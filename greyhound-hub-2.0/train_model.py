import tensorflow as tf
from tensorflow.keras.applications import ResNet50
from tensorflow.keras.applications.resnet50 import preprocess_input
from tensorflow.keras.layers import (
    Input,
    Dense,
    Dropout,
    GlobalAveragePooling2D,
    RandomFlip,
    RandomRotation,
    RandomZoom,
)
from tensorflow.keras.models import Model
from tensorflow.keras.callbacks import (
    EarlyStopping,
    ModelCheckpoint,
    ReduceLROnPlateau,
)

# ==========================
# SETTINGS
# ==========================
IMG_SIZE = (224, 224)
BATCH_SIZE = 16
EPOCHS = 15

# ==========================
# LOAD DATASET
# ==========================
train = tf.keras.preprocessing.image_dataset_from_directory(
    "dataset/train",
    image_size=IMG_SIZE,
    batch_size=BATCH_SIZE,
    shuffle=True
)

valid = tf.keras.preprocessing.image_dataset_from_directory(
    "dataset/valid",
    image_size=IMG_SIZE,
    batch_size=BATCH_SIZE,
    shuffle=False
)

class_names = train.class_names
print("Classes:", class_names)

AUTOTUNE = tf.data.AUTOTUNE

train = train.prefetch(AUTOTUNE)
valid = valid.prefetch(AUTOTUNE)

# ==========================
# DATA AUGMENTATION
# ==========================
augmentation = tf.keras.Sequential([
    RandomFlip("horizontal"),
    RandomRotation(0.15),
    RandomZoom(0.15),
])

# ==========================
# BUILD MODEL
# ==========================
inputs = Input(shape=(224,224,3))

x = augmentation(inputs)
x = preprocess_input(x)

base_model = ResNet50(
    weights="imagenet",
    include_top=False,
    input_tensor=x
)

base_model.trainable = False

x = GlobalAveragePooling2D()(base_model.output)

x = Dense(512, activation="relu")(x)
x = Dropout(0.5)(x)

x = Dense(256, activation="relu")(x)
x = Dropout(0.3)(x)

outputs = Dense(
    len(class_names),
    activation="softmax"
)(x)

model = Model(inputs, outputs)

# ==========================
# COMPILE
# ==========================
model.compile(
    optimizer=tf.keras.optimizers.Adam(learning_rate=1e-3),
    loss="sparse_categorical_crossentropy",
    metrics=["accuracy"]
)

# ==========================
# CALLBACKS
# ==========================
early_stop = EarlyStopping(
    monitor="val_loss",
    patience=5,
    restore_best_weights=True
)

reduce_lr = ReduceLROnPlateau(
    monitor="val_loss",
    factor=0.2,
    patience=2,
    min_lr=1e-6
)

checkpoint = ModelCheckpoint(
    "greyhound_events_model.keras",
    monitor="val_accuracy",
    save_best_only=True,
    verbose=1
)

# ==========================
# TRAIN STAGE 1
# ==========================
history = model.fit(
    train,
    validation_data=valid,
    epochs=EPOCHS,
    callbacks=[
        early_stop,
        reduce_lr,
        checkpoint
    ]
)

# ==========================
# FINE-TUNING
# ==========================

base_model.trainable = True

for layer in base_model.layers[:-30]:
    layer.trainable = False

model.compile(
    optimizer=tf.keras.optimizers.Adam(1e-5),
    loss="sparse_categorical_crossentropy",
    metrics=["accuracy"]
)

history_finetune = model.fit(
    train,
    validation_data=valid,
    epochs=10,
    callbacks=[
        early_stop,
        reduce_lr,
        checkpoint
    ]
)

model.save("greyhound_events_model.keras")

print("Training Complete!")