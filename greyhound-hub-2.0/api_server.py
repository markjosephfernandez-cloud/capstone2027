"""Local HTTP inference service for the Greyhound Hub event classifier.

It intentionally uses Python's standard-library HTTP server so the existing
virtual environment can run it without adding Flask or another web framework.
"""

from __future__ import annotations

import json
import os
from email import policy
from email.parser import BytesParser
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "2")

import numpy as np
import tensorflow as tf
from tensorflow.keras.applications import ResNet50
from tensorflow.keras.applications.resnet50 import preprocess_input
from tensorflow.keras.layers import (
    Dense,
    Dropout,
    GlobalAveragePooling2D,
    Input,
    RandomFlip,
    RandomRotation,
    RandomZoom,
)
from tensorflow.keras.models import Model


ROOT = os.path.dirname(os.path.abspath(__file__))
MODEL_PATH = os.path.join(ROOT, "greyhound_events_model.keras")
HOST = os.environ.get("GREYHOUND_AI_HOST", "127.0.0.1")
PORT = int(os.environ.get("GREYHOUND_AI_PORT", "5001"))
MIN_CONFIDENCE = float(os.environ.get("GREYHOUND_AI_MIN_CONFIDENCE", "80"))

# These labels must remain in the same alphabetical order used by
# image_dataset_from_directory() during model training.
CLASS_NAMES = ("dal-uy", "graduation", "kasadyaan")
EVENT_NAMES = {
    "dal-uy": "Dal-uy Events",
    "graduation": "Graduation Activities",
    "kasadyaan": "Kasadyaan Celebrations",
}

def load_model() -> Model:
    """Rebuild the training architecture, then load the saved Keras weights.

    The model was saved by an earlier Keras release whose serialized layer
    configuration is not directly readable by the installed Keras version.
    Loading its weights into the identical architecture keeps inference output
    unchanged while avoiding a version downgrade.
    """
    inputs = Input(shape=(224, 224, 3))
    augmentation = tf.keras.Sequential([
        RandomFlip("horizontal"),
        RandomRotation(0.15),
        RandomZoom(0.15),
    ])
    x = augmentation(inputs)
    x = preprocess_input(x)
    backbone = ResNet50(weights=None, include_top=False, input_tensor=x)
    x = GlobalAveragePooling2D()(backbone.output)
    x = Dense(512, activation="relu")(x)
    x = Dropout(0.5)(x)
    x = Dense(256, activation="relu")(x)
    x = Dropout(0.3)(x)
    outputs = Dense(3, activation="softmax")(x)
    model = Model(inputs, outputs)
    model.load_weights(MODEL_PATH)
    return model


MODEL = load_model()


def classify(image_bytes: bytes) -> dict:
    image = tf.io.decode_image(image_bytes, channels=3, expand_animations=False)
    image.set_shape([None, None, 3])
    image = tf.image.resize(image, (224, 224))
    probabilities = MODEL(tf.expand_dims(image, axis=0), training=False).numpy()[0]
    index = int(np.argmax(probabilities))
    confidence = round(float(probabilities[index]) * 100, 2)
    predicted_class = CLASS_NAMES[index]
    event_name = EVENT_NAMES[predicted_class] if confidence >= MIN_CONFIDENCE else None

    return {
        "event_name": event_name,
        "confidence_score": confidence,
        "model_version": "greyhound-hub-2.0-resnet50",
        "evidence": [
            {"type": "image_classifier", "value": predicted_class},
            {
                "type": "class_probabilities",
                "value": {
                    CLASS_NAMES[position]: round(float(probability) * 100, 2)
                    for position, probability in enumerate(probabilities)
                },
            },
        ],
    }


class RequestHandler(BaseHTTPRequestHandler):
    server_version = "GreyhoundHubAI/2.0"

    def _json(self, status: int, payload: dict) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:
        if self.path.rstrip("/") != "/health":
            self._json(HTTPStatus.NOT_FOUND, {"error": "Not found"})
            return

        self._json(HTTPStatus.OK, {
            "status": "ok",
            "model_version": "greyhound-hub-2.0-resnet50",
            "classes": list(EVENT_NAMES.values()),
            "minimum_confidence": MIN_CONFIDENCE,
        })

    def do_POST(self) -> None:
        if self.path.rstrip("/") != "/predict":
            self._json(HTTPStatus.NOT_FOUND, {"error": "Not found"})
            return

        content_type = self.headers.get("Content-Type", "")
        if not content_type.startswith("multipart/form-data"):
            self._json(HTTPStatus.UNSUPPORTED_MEDIA_TYPE, {"error": "Use multipart/form-data."})
            return

        try:
            content_length = int(self.headers.get("Content-Length", "0"))
            body = self.rfile.read(content_length)
            message = BytesParser(policy=policy.default).parsebytes(
                b"Content-Type: " + content_type.encode("ascii") + b"\r\n"
                b"MIME-Version: 1.0\r\n\r\n" + body
            )
            media_bytes = None
            for part in message.iter_parts():
                if part.get_param("name", header="content-disposition") == "media":
                    media_bytes = part.get_payload(decode=True)
                    break
            if not media_bytes:
                self._json(HTTPStatus.BAD_REQUEST, {"error": "The media file is required."})
                return

            self._json(HTTPStatus.OK, classify(media_bytes))
        except (tf.errors.InvalidArgumentError, ValueError) as error:
            self._json(HTTPStatus.UNPROCESSABLE_ENTITY, {"error": "The uploaded file is not a supported image.", "details": str(error)})
        except Exception:
            self._json(HTTPStatus.INTERNAL_SERVER_ERROR, {"error": "AI inference failed."})

    def log_message(self, format: str, *args: object) -> None:
        print("%s - %s" % (self.address_string(), format % args))


if __name__ == "__main__":
    print("Loading Greyhound Hub 2.0 AI model...")
    print(f"AI service available at http://{HOST}:{PORT}/health")
    ThreadingHTTPServer((HOST, PORT), RequestHandler).serve_forever()
