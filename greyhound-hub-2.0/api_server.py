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

from PIL import UnidentifiedImageError
from event_classifier import MODEL_VERSION, get_classifier
from visual_analyzer import analyze as analyze_visual

HOST = os.environ.get("GREYHOUND_AI_HOST", "127.0.0.1")
PORT = int(os.environ.get("GREYHOUND_AI_PORT", "5001"))


def classify(image_bytes: bytes) -> dict:
    return get_classifier().classify(image_bytes)


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
            "visual_analysis": {"endpoint": "/analyze", "schema_version": 1},
            **get_classifier().health(),
        })

    def do_POST(self) -> None:
        if self.path.rstrip("/") not in ("/predict", "/analyze"):
            self._json(HTTPStatus.NOT_FOUND, {"error": "Not found"})
            return

        content_type = self.headers.get("Content-Type", "")
        if not content_type.startswith("multipart/form-data"):
            self._json(HTTPStatus.UNSUPPORTED_MEDIA_TYPE, {"error": "Use multipart/form-data."})
            return

        try:
            content_length = int(self.headers.get("Content-Length", "0"))
            if content_length <= 0 or content_length > 30 * 1024 * 1024:
                self._json(HTTPStatus.REQUEST_ENTITY_TOO_LARGE, {"error": "Maximum analysis upload is 30 MB."})
                return
            self.connection.settimeout(120)
            body = self.rfile.read(content_length)
            message = BytesParser(policy=policy.default).parsebytes(
                b"Content-Type: " + content_type.encode("ascii") + b"\r\n"
                b"MIME-Version: 1.0\r\n\r\n" + body
            )
            media_bytes = None
            tasks = None
            for part in message.iter_parts():
                if part.get_param("name", header="content-disposition") == "media":
                    media_bytes = part.get_payload(decode=True)
                elif part.get_param("name", header="content-disposition") == "tasks":
                    tasks = json.loads(part.get_payload(decode=True))
            if not media_bytes:
                self._json(HTTPStatus.BAD_REQUEST, {"error": "The media file is required."})
                return

            self._json(HTTPStatus.OK, analyze_visual(media_bytes, tasks) if self.path.rstrip("/") == "/analyze" else classify(media_bytes))
        except (UnidentifiedImageError, OSError, ValueError) as error:
            self._json(HTTPStatus.UNPROCESSABLE_ENTITY, {"error": "The uploaded file is not a supported image.", "details": str(error)})
        except Exception:
            self._json(HTTPStatus.INTERNAL_SERVER_ERROR, {"error": "AI inference failed."})

    def log_message(self, format: str, *args: object) -> None:
        print("%s - %s" % (self.address_string(), format % args))


if __name__ == "__main__":
    print(f"Loading {MODEL_VERSION}...", flush=True)
    get_classifier()
    print(f"AI service available at http://{HOST}:{PORT}/health", flush=True)
    ThreadingHTTPServer((HOST, PORT), RequestHandler).serve_forever()
