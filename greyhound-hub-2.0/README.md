# Greyhound Hub ConvNeXt event classification

The active inference service loads `convnext_event_classifier.pth` with PyTorch.
The old Keras/ResNet models are retained as historical training assets and are
not loaded by `api_server.py`.

## Start the service

macOS (existing native Python 3.11 environment):

```bash
cd greyhound-hub-2.0
./.venv_ai_mac/bin/python -m pip install -r requirements-inference.txt
./start-ai-server.sh
```

For a new environment, run `python3.11 -m venv .venv_ai_mac` first.
Linux can use the same commands or set `GREYHOUND_AI_PYTHON` to its virtualenv's Python.

Windows:

```powershell
cd greyhound-hub-2.0
.\.venv_ai\Scripts\python.exe -m pip install -r requirements-inference.txt
.\start-ai-server.bat
```

Stop the previous AI process before starting the replacement on the same port.
The launcher rejects a running service that reports the old model version.
The PHP `.env` setting remains:

```ini
greyhound.eventModelEndpoint = 'http://127.0.0.1:5001/predict'
```

Check `http://127.0.0.1:5001/health`: it must report
`greyhound-hub-2.0-convnext` and eight classes. `POST /predict` accepts a multipart
`media` image and returns the existing event/confidence/evidence contract.

## Checkpoint and labels

The checkpoint stores a torchvision ConvNeXt Tiny state dict, `classes`, and
`class_to_idx`. Inference strictly loads all weights and validates the saved
class order; it never downloads pretrained weights or loads the old model.

| Checkpoint class | Application classification |
| --- | --- |
| Digital EXPO | EXPO Activities |
| Graduation | Graduation Activities |
| Kasadyaan | Kasadyaan Celebrations |
| Kasadyaan:Hara | Kasadyaan: Hara |
| Kasadyaan:Parada | Kasadyaan: Parada |
| Kasadyaan:Pasundayag | Kasadyaan: Pasundayag |
| dal-uy | Dal-uy Events |
| intramurals | Intramurals |

The checkpoint does **not** include its training/validation preprocessing.
Until the original transform code is provided, inference uses the standard
[torchvision ConvNeXt Tiny transform](https://docs.pytorch.org/vision/main/models/generated/torchvision.models.convnext_tiny.html):
RGB, bilinear resize of the shorter edge to 236, center crop to 224, and ImageNet
normalization. Confirm these settings against training before assessing accuracy.
`GREYHOUND_AI_RESIZE_SIZE` and `GREYHOUND_AI_CROP_SIZE` can override the sizes.

`GREYHOUND_AI_HOST` (default `127.0.0.1`), `GREYHOUND_AI_PORT` (5001),
`GREYHOUND_AI_MIN_CONFIDENCE` (80), and `GREYHOUND_AI_THREADS` (2) are optional.
Below the service threshold, results retain probabilities but have no event and
require review. The PHP review threshold is 80%. A service outage still allows
uploads using the separately identified `greyhound-keyword-baseline` with zero
AI confidence and mandatory review; it does
not load the old ResNet model. Existing classifications are not rewritten.

## Historical ResNet training documentation

The material below describes the old training assets. `train_model.py` and
`test_model.py` are legacy TensorFlow utilities; they do not train or test the
active ConvNeXt checkpoint.

An AI-powered Event Classification System developed for the Greyhound Hub Digital Asset Management System. This project uses ResNet-50 Transfer Learning to automatically classify uploaded media into Foundation University event categories.

Current Event Categories:

Dal-uy
Graduation
Kasadyaan

The trained model is integrated with the Digital Asset Management System to automatically tag uploaded images with their corresponding event.

Requirements
Software
Python 3.10 or 3.11 (Recommended)
TensorFlow 2.20+
OpenCV
NumPy
Matplotlib
Scikit-learn
Pandas
Pillow
Install Dependencies

Create a virtual environment.

python -m venv venv

Activate it.

Windows
venv\Scripts\activate
Install libraries
pip install tensorflow
pip install opencv-python
pip install numpy
pip install matplotlib
pip install scikit-learn
pip install pandas
pip install pillow

or

pip install tensorflow opencv-python numpy matplotlib scikit-learn pandas pillow
Dataset Structure

The dataset must follow this exact directory structure.

dataset/

    train/

        dal-uy/
        graduation/
        expo/
        kasadyaan/

    valid/

        dal-uy/
        expo/
        graduation/
        kasadyaan/

    test/

        dal-uy/
        expo/
        graduation/
        kasadyaan/

Each folder contains only images belonging to that event.

Example

dataset/

    train/

        dal-uy/
            img001.jpg
            img002.jpg

        graduation/
            img001.jpg

        kasadyaan/
            img001.jpg
Dataset Split

Recommended dataset distribution

Training      70-80%
Validation    10-20%
Testing        10-20%

Example

Dal-uy

71%
18%
11%

Graduation

78%
11%
11%

Kasadyaan

72%
14%
14%
Image Annotation

Annotation Tool

Roboflow

The dataset should be exported as

Multi-Label Classification

or

Image Classification

The project uses ResNet-50 image classification.

Bounding boxes are not required.

Training the Model

Run

python train_model.py

Example Output

Found 263 files belonging to 3 classes.

Epoch 1/30

Accuracy

Validation Accuracy

Validation Loss

...

The trained model will automatically be saved as

greyhound_events_model.keras
Testing the Model

Run

python test_model.py

Example

Actual

Graduation

Predicted

Graduation

Confidence

98.65%

Images with confidence lower than the threshold (default 80%) are automatically classified as

UNKNOWN

This prevents incorrect event tagging.

Recommended Hyperparameters
Image Size

224 x 224

Batch Size

16

Optimizer

Adam

Learning Rate

0.001

Epochs

30

Fine-tuning Epochs

10

Loss Function

Sparse Categorical Crossentropy
Understanding the Training Results

During training, TensorFlow prints several metrics.

Example

Epoch 6

Training Accuracy

95.82%

Validation Accuracy

93.75%

Validation Loss

0.104
Training Accuracy

Shows how well the model learns from the training dataset.

Higher is better.

Validation Accuracy

Shows how well the model predicts unseen validation images.

This is the most important metric.

Validation Loss

Measures prediction error.

Lower is better.

Training Fluctuations

Training accuracy and validation accuracy may fluctuate during training.

This is normal.

Example

Epoch 6

Validation Accuracy

93%

Epoch 7

Validation Accuracy

97%

Epoch 8

Validation Accuracy

95%

Epoch 9

Validation Accuracy

96%

Small fluctuations are expected.

If Accuracy Suddenly Drops

Example

95%

↓

70%

Possible causes

Dataset contains incorrect labels.
Images are blurry or duplicated.
Learning rate is too high.
Overfitting.
Insufficient training images.
If Validation Accuracy Stops Improving

The model has likely converged.

No additional epochs are necessary.

The best checkpoint will already have been saved automatically.

If Training Accuracy is High but Validation Accuracy is Low

Example

Training Accuracy

99%

Validation Accuracy

65%

The model is overfitting.

Solutions

Add more training images.
Increase data augmentation.
Add Dropout.
Reduce learning rate.
Use EarlyStopping.
Improving the Dataset

Adding more images is usually more beneficial than increasing the number of epochs.

Priority

More Images

>

More Epochs
Adding a New Dataset

Suppose a new university event needs to be recognized.

Example

EXPO

Create new folders.

dataset/

train/

expo/

valid/

expo/

test/

expo/

Add labeled EXPO images to each folder.

Then retrain the model.

No changes to the code are required because TensorFlow automatically detects new class folders.

Example

Current

Dal-uy

Graduation

Kasadyaan

After adding

Dal-uy

Graduation

Kasadyaan

EXPO

The model will automatically become a 4-class classifier after retraining.

Replacing an Existing Dataset

If existing images are poor quality or incorrectly labeled

Simply replace the images inside the corresponding class folders.

Example

dataset/

train/

graduation/

Delete incorrect images.

Copy new correctly labeled images.

Retrain the model.

Updating Existing Classes

If more Graduation images become available

Simply add them into

dataset/train/graduation/

Do the same for

valid/graduation

test/graduation

Maintain approximately

70%

15%

15%

distribution.

When Should You Retrain?

Retraining is recommended when:

A new event category is added.
More images are collected for an existing event.
Incorrect labels are corrected.
Image quality has significantly improved.
The model performs poorly on new uploaded images.
Best Practices

✔ Use at least 100 images per class (300–500+ is preferable).

✔ Ensure each class has a similar number of images to avoid imbalance.

✔ Avoid duplicate images across training, validation, and test sets.

✔ Use high-resolution images with clear event content.

✔ Verify labels carefully before training.

✔ Keep the train/validation/test splits consistent.

Model Output

The trained model predicts:

Event Name

Confidence Score

Example

Prediction

Graduation

Confidence

98.74%

or

Prediction

UNKNOWN

Confidence

42.33%
Deployment Workflow
User Uploads Image
          │
          ▼
Greyhound Hub
          │
          ▼
ResNet-50 AI Model
          │
          ▼
Event Prediction
          │
          ▼
Confidence Check
          │
     ≥ 80% ?
      /    \
    Yes     No
     │       │
     ▼       ▼
 Save Event  Save as
 Metadata    UNKNOWN
          │
          ▼
Digital Asset Management Database

## iFound object and expression analysis

The AI service also exposes `/analyze` for local YOLO object detection and DeepFace facial-expression estimates. See [setup, iFound usage, duplicate matching, and custom checkpoints](../docs/ifound-visual-analysis.md).
