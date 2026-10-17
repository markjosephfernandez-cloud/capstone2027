"""Download the public pretrained weights once, before starting the AI server."""
from pathlib import Path
import os
from visual_analyzer import ROOT

if __name__ == "__main__":
    models = ROOT / "models"
    models.mkdir(exist_ok=True)
    os.chdir(models)
    from ultralytics import YOLO
    YOLO("yolo11n.pt")
    from deepface import DeepFace
    DeepFace.build_model(task="facial_attribute", model_name="Emotion")
    DeepFace.build_model(task="face_detector", model_name="opencv")
    print("Object and facial-expression models are ready.")
