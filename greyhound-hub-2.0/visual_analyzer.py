"""Local object and facial-expression inference; failures are explicit per task."""
from io import BytesIO
from pathlib import Path
from threading import Lock
import logging
import math
import os

from PIL import Image, ImageOps

ROOT = Path(__file__).resolve().parent
# Keep downloaded assets/config inside the project rather than the user's home.
os.environ.setdefault("DEEPFACE_HOME", str(ROOT / "models"))
os.environ.setdefault("YOLO_CONFIG_DIR", str(ROOT / ".ultralytics"))
os.environ.setdefault("TF_USE_LEGACY_KERAS", "1")
Path(os.environ["YOLO_CONFIG_DIR"]).mkdir(parents=True, exist_ok=True)
Path(os.environ["DEEPFACE_HOME"]).mkdir(parents=True, exist_ok=True)
LOCK = Lock()
_detector = None


def get_detector():
    global _detector
    if _detector is None:
        from ultralytics import YOLO
        weights = Path(os.environ.get("GREYHOUND_OBJECT_MODEL", str(ROOT / "models" / "yolo11n.pt")))
        if not weights.is_file():
            raise RuntimeError("Object model missing. Run prepare_visual_models.py first.")
        _detector = YOLO(str(weights), task="detect")
    return _detector


def analyze_objects(image):
    detector = get_detector()
    result = detector.predict(image, conf=0.5, max_det=100, device="cpu", verbose=False)[0]
    objects = []
    for box in result.boxes:
        confidence = float(box.conf.item()) * 100
        coordinates = [round(float(v), 6) for v in box.xyxyn[0].tolist()]
        if not math.isfinite(confidence) or not all(math.isfinite(v) for v in coordinates):
            continue
        objects.append({"label": str(result.names[int(box.cls.item())]),
                        "confidence": round(confidence, 2), "box": coordinates})
    return {"status": "complete", "model": Path(detector.ckpt_path).name, "items": objects}


def analyze_expressions(image):
    if os.environ.get("GREYHOUND_EXPRESSION_ANALYSIS", "on").lower() in ("off", "false", "0"):
        return {"status": "disabled", "model": "DeepFace-Emotion", "items": []}
    import numpy as np
    from deepface import DeepFace
    try:
        # enforce_detection avoids assigning an expression to an image with no face.
        faces = DeepFace.analyze(np.asarray(image)[:, :, ::-1].copy(), actions=["emotion"],
                                 detector_backend="opencv", enforce_detection=True, silent=True)
    except ValueError as error:
        if "face could not be detected" in str(error).lower():
            return {"status": "no_face", "model": "DeepFace-Emotion", "items": []}
        raise
    items = []
    for face in faces:
        label = face["dominant_emotion"]
        score = float(face["emotion"][label])
        region = face["region"]
        if not math.isfinite(score):
            continue
        items.append({"label": label, "confidence": round(score, 2), "box": [
            max(0, region["x"] / image.width), max(0, region["y"] / image.height),
            min(1, (region["x"] + region["w"]) / image.width),
            min(1, (region["y"] + region["h"]) / image.height)]})
    return {"status": "complete", "model": "DeepFace-Emotion", "items": items[:100]}


def analyze(image_bytes, tasks=None):
    tasks = ["objects", "expressions"] if tasks is None else tasks
    if not isinstance(tasks, list) or not tasks or any(task not in ("objects", "expressions") for task in tasks):
        raise ValueError("Choose objects and/or expressions.")
    with Image.open(BytesIO(image_bytes)) as source:
        if source.width * source.height > 40_000_000:
            raise ValueError("Image exceeds the 40 megapixel analysis limit.")
        image = ImageOps.exif_transpose(source).convert("RGB")
        image.thumbnail((1600, 1600))
    result = {"schema_version": 1}
    with LOCK:
        for name, operation in (("objects", analyze_objects), ("expressions", analyze_expressions)):
            if name not in tasks:
                result[name] = {"status": "not_requested", "items": []}
                continue
            try:
                result[name] = operation(image)
            except Exception:
                logging.exception("%s inference unavailable", name)
                result[name] = {"status": "unavailable", "items": [],
                                "message": "Model unavailable. Check the AI service setup and retry."}
    return result
