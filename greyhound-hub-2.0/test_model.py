import tensorflow as tf
import numpy as np
import cv2
import os

# ======================================
# SETTINGS
# ======================================

MODEL_PATH = "greyhound_events_model.keras"

CLASS_NAMES = [
    "dal-uy",
    "graduation",
    "kasadyaan"
]

IMG_SIZE = (224, 224)

# Confidence threshold
THRESHOLD = 80.0

# ======================================
# LOAD MODEL
# ======================================

try:
    model = tf.keras.models.load_model(MODEL_PATH)
    print("✅ Model loaded successfully.\n")
except Exception as e:
    print("❌ Error loading model:", e)
    exit()

# ======================================
# COUNTERS
# ======================================

correct = 0
total = 0
unknown_count = 0

predicted_counts = {name: 0 for name in CLASS_NAMES}

print("=" * 70)
print("      GREYHOUND HUB - AI EVENT CLASSIFIER")
print("=" * 70)

# ======================================
# TEST ALL IMAGES
# ======================================

for actual_class in CLASS_NAMES:

    folder = os.path.join("dataset", "test", actual_class)

    if not os.path.exists(folder):
        print(f"⚠ Folder not found: {folder}")
        continue

    for filename in os.listdir(folder):

        if not filename.lower().endswith((".jpg", ".jpeg", ".png", ".bmp", ".webp")):
            continue

        image_path = os.path.join(folder, filename)

        img = cv2.imread(image_path)

        if img is None:
            print(f"Cannot read image: {filename}")
            continue

        img = cv2.cvtColor(img, cv2.COLOR_BGR2RGB)
        img = cv2.resize(img, IMG_SIZE)
        img = np.expand_dims(img, axis=0)

        prediction = model.predict(img, verbose=0)[0]

        predicted_index = np.argmax(prediction)
        confidence = float(prediction[predicted_index]) * 100

        if confidence < THRESHOLD:
            predicted_class = "UNKNOWN"
            unknown_count += 1
        else:
            predicted_class = CLASS_NAMES[predicted_index]
            predicted_counts[predicted_class] += 1

        print("-" * 70)
        print(f"Image      : {filename}")
        print(f"Actual     : {actual_class}")
        print(f"Predicted  : {predicted_class}")
        print(f"Confidence : {confidence:.2f}%")

        print("\nClass Probabilities:")

        for i, cls in enumerate(CLASS_NAMES):
            print(f"   {cls:<12}: {prediction[i]*100:.2f}%")

        total += 1

        if predicted_class == actual_class:
            correct += 1

# ======================================
# SUMMARY
# ======================================

accuracy = (correct / total * 100) if total > 0 else 0

print("\n")
print("=" * 70)
print("BATCH TEST SUMMARY")
print("=" * 70)

print(f"Total Images Tested : {total}")
print(f"Correct Predictions : {correct}")
print(f"Unknown Images      : {unknown_count}")
print(f"Overall Accuracy    : {accuracy:.2f}%")

print("\nPredicted Events:")

for event, count in predicted_counts.items():
    print(f"  {event:<15}: {count}")

print("=" * 70)