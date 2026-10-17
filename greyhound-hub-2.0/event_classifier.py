"""ConvNeXt inference using the class order saved with the trained checkpoint."""

from functools import lru_cache
from io import BytesIO
import math
import os
from pathlib import Path

from PIL import Image, ImageOps
import torch
from torchvision.models import ConvNeXt_Tiny_Weights, convnext_tiny

MODEL_VERSION = "greyhound-hub-2.0-convnext"
MODEL_PATH = Path(__file__).with_name("convnext_event_classifier.pth")
EVENT_NAMES = {
    "Digital EXPO": "EXPO Activities",
    "Graduation": "Graduation Activities",
    "Kasadyaan": "Kasadyaan Celebrations",
    "Kasadyaan:Hara": "Kasadyaan: Hara",
    "Kasadyaan:Parada": "Kasadyaan: Parada",
    "Kasadyaan:Pasundayag": "Kasadyaan: Pasundayag",
    "dal-uy": "Dal-uy Events",
    "intramurals": "Intramurals",
}


class EventClassifier:
    def __init__(self, model_path=MODEL_PATH):
        torch.set_num_threads(max(1, int(os.environ.get("GREYHOUND_AI_THREADS", "2"))))
        checkpoint = torch.load(model_path, map_location="cpu", weights_only=True)
        self.classes = tuple(checkpoint["classes"])
        if not self.classes or len(set(self.classes)) != len(self.classes) or any(label not in EVENT_NAMES for label in self.classes):
            raise ValueError("Checkpoint contains missing, duplicate, or unsupported event labels.")
        if checkpoint.get("class_to_idx") != {label: index for index, label in enumerate(self.classes)}:
            raise ValueError("Checkpoint class_to_idx does not match its saved class order.")
        self.model = convnext_tiny(weights=None, num_classes=len(self.classes))
        self.model.load_state_dict(checkpoint["model_state_dict"], strict=True)
        self.model.eval()
        self.minimum_confidence = float(os.environ.get("GREYHOUND_AI_MIN_CONFIDENCE", "80"))
        if not math.isfinite(self.minimum_confidence) or not 0 <= self.minimum_confidence <= 100:
            raise ValueError("GREYHOUND_AI_MIN_CONFIDENCE must be between 0 and 100.")
        # The checkpoint omits transforms. Use torchvision's standard ConvNeXt
        # ImageNet normalization and validation resize/crop until training settings
        # are supplied. No pretrained weights are downloaded.
        self.resize_size = int(os.environ.get("GREYHOUND_AI_RESIZE_SIZE", "236"))
        self.crop_size = int(os.environ.get("GREYHOUND_AI_CROP_SIZE", "224"))
        if self.resize_size < self.crop_size or self.crop_size < 32:
            raise ValueError("Resize must be at least crop size, and crop size at least 32.")
        self.transform = ConvNeXt_Tiny_Weights.IMAGENET1K_V1.transforms(
            resize_size=self.resize_size, crop_size=self.crop_size
        )

    def health(self):
        return {
            "model_version": MODEL_VERSION,
            "model_file": MODEL_PATH.name,
            "classes": [EVENT_NAMES[label] for label in self.classes],
            "checkpoint_classes": list(self.classes),
            "minimum_confidence": self.minimum_confidence,
            "preprocessing": {"resize_size": self.resize_size, "crop_size": self.crop_size, "normalization": "ImageNet"},
        }

    def classify(self, image_bytes):
        with Image.open(BytesIO(image_bytes)) as image:
            tensor = self.transform(ImageOps.exif_transpose(image).convert("RGB")).unsqueeze(0)
        with torch.inference_mode():
            probabilities = torch.softmax(self.model(tensor), dim=1)[0]
        if not torch.isfinite(probabilities).all():
            raise RuntimeError("Model produced non-finite probabilities.")
        index = int(probabilities.argmax().item())
        confidence = float(probabilities[index].item()) * 100
        predicted_class = self.classes[index]
        return {
            "event_name": EVENT_NAMES[predicted_class] if confidence >= self.minimum_confidence else None,
            "confidence_score": round(confidence, 2),
            "model_version": MODEL_VERSION,
            "evidence": [
                {"type": "image_classifier", "value": predicted_class},
                {"type": "class_probabilities", "value": {
                    label: round(float(probabilities[position].item()) * 100, 2)
                    for position, label in enumerate(self.classes)
                }},
            ],
        }


@lru_cache(maxsize=1)
def get_classifier():
    return EventClassifier()
