import os
import json
import hashlib
import mysql.connector
from ultralytics import YOLO
import cv2  # For video processing
from datetime import datetime
import numpy as np
import sys
from watchdog.observers import Observer
from watchdog.events import FileSystemEventHandler
import time
from deepface import DeepFace  # Added for emotion detection


# Load YOLO models for detection, classification, and pose
models = {
    'detection': YOLO('yolo11n.pt'),         # Object Detection
    'classification': YOLO('yolo11n-cls.pt'), # Image Classification
    'pose': YOLO('yolo11n-pose.pt')          # Pose Estimation
}

# Confidence threshold for storing detections
CONFIDENCE_THRESHOLD = 0.8  # or higher depending on your results


# Local folder path
LOCAL_FOLDER_PATH = '/Applications/XAMPP/xamppfiles/htdocs/testcreative/random'
# ✅ Define TRASH folder path to exclude from scanning
TRASH_FOLDER_PATH = os.path.join(LOCAL_FOLDER_PATH, 'TRASH')





# Set Matplotlib writable directory
os.environ["MPLCONFIGDIR"] = "/tmp/matplotlib_cache"

# Set YOLO Ultralytics config writable directory
os.environ["YOLO_CONFIG_DIR"] = "/tmp/ultralytics_config"




# MySQL Database Connection
def get_db_connection():
    try:
        connection = mysql.connector.connect(
            host="localhost",
            user="root",
            password="",
            database="last_fm",
            autocommit=True  # Ensure changes are saved permanently
        )
        return connection
    except mysql.connector.Error as err:
        print(f"Error: {err}")
        sys.exit(1)

# Initialize global database connection
db = get_db_connection()


# Supported file types
image_extensions = {'jpg', 'jpeg', 'png', 'gif'}
video_extensions = {'mp4', 'avi', 'mov'}




def construct_web_url(file_path):
    """Ensure the stored file path is absolute and correctly formatted."""
    base_path = "/Applications/XAMPP/xamppfiles/htdocs/testcreative/random"

    if not file_path.startswith(base_path):
        # Ensure correct absolute path formatting
        absolute_path = os.path.join(base_path, file_path.lstrip(os.sep))
        absolute_path = absolute_path.replace("\\", "/")  # Ensure forward slashes
        return absolute_path  # ✅ Store the absolute path
    return file_path  # Already absolute, return as is




# Check if local folder exists
def check_local_folder():
    if not os.path.exists(LOCAL_FOLDER_PATH):
        print(f"Error: Target folder '{LOCAL_FOLDER_PATH}' does not exist.")
        sys.exit(1)
    print(f"Local folder '{LOCAL_FOLDER_PATH}' is accessible.")





def get_file_creation_date(filepath):
    """Extract the file creation date."""
    try:
        creation_time = os.path.getctime(filepath)
        return datetime.fromtimestamp(creation_time).strftime('%Y-%m-%d %H:%M:%S')
    except Exception as e:
        print(f"Error retrieving creation date for {filepath}: {e}")
        return None

def populate_missing_columns(filepath):
    """Ensure file exists in the database and populate missing columns, including metadata and AI results."""
    try:
        with db.cursor(dictionary=True) as cursor:
            # ✅ Fetch file ID first
            cursor.execute("SELECT id FROM files WHERE filepath = %s", (filepath,))
            result = cursor.fetchone()

            if not result:
                print(f"⚠️ File {filepath} not found in database. Inserting...")
                insert_new_file(filepath)
                return  # ✅ After inserting, no need to continue

            file_id = result["id"]

            # ✅ Clear unread results before executing new queries
            cursor.fetchall()

        # ✅ Fetch missing metadata
        with db.cursor(dictionary=True) as cursor:
            cursor.execute("""
                SELECT datecreated, filehash, size, detected_objects, classification, pose, gesture, emotion, bounding_boxes
                FROM files WHERE id = %s
            """, (file_id,))
            result = cursor.fetchone()

            # ✅ Clear unread results before executing new queries
            cursor.fetchall()

            # ✅ Check missing metadata
            missing_data = []
            if not result["datecreated"]:
                missing_data.append("datecreated")
            if not result["filehash"]:
                missing_data.append("filehash")
            if not result["size"]:
                missing_data.append("size")
            if not result["detected_objects"]:
                missing_data.append("detected_objects")
            if not result["classification"]:
                missing_data.append("classification")
            if not result["pose"]:
                missing_data.append("pose")
            if not result["gesture"]:
                missing_data.append("gesture")
            if not result["emotion"]:
                missing_data.append("emotion")
            if not result["bounding_boxes"]:
                missing_data.append("bounding_boxes")

            if not missing_data:
                print(f"✅ All metadata is present for file ID {file_id}. Skipping update.")
                return

            print(f"🛠️ Populating missing metadata for file ID {file_id}: {missing_data}")

            # ✅ Update missing fields
            with db.cursor() as cursor:
                if "datecreated" in missing_data:
                    datecreated = get_file_creation_date(filepath)
                    cursor.execute("UPDATE files SET datecreated = %s WHERE id = %s", (datecreated, file_id))

                if "filehash" in missing_data:
                    filehash = calculate_filehash(filepath)
                    cursor.execute("UPDATE files SET filehash = %s WHERE id = %s", (filehash, file_id))

                if "size" in missing_data:
                    size = get_file_size(filepath)
                    cursor.execute("UPDATE files SET size = %s WHERE id = %s", (size, file_id))

                if "detected_objects" in missing_data or "classification" in missing_data or "pose" in missing_data:
                    process_file(file_id, filepath, filepath.split('.')[-1].lower())

            db.commit()
            print(f"✅ Finished populating metadata for file ID {file_id}")

    except mysql.connector.Error as e:
        print(f"❌ Database error while populating metadata for {filepath}: {e}")
    except Exception as e:
        print(f"❌ Error in `populate_missing_columns()` for file: {filepath} - {e}")




def insert_new_file(filepath):
    """Insert new file into database if not found."""
    try:
        with db.cursor() as cursor:
            filename = os.path.basename(filepath)
            filetype = filepath.split('.')[-1].lower()
            size = get_file_size(filepath)
            datecreated = get_file_creation_date(filepath)
            filehash = calculate_filehash(filepath)

            cursor.execute("""
                INSERT INTO files (filename, filepath, filetype, size, datecreated, filehash)
                VALUES (%s, %s, %s, %s, %s, %s)
            """, (filename, filepath, filetype, size, datecreated, filehash))
            db.commit()

        print(f"✅ Inserted new file into database: {filepath}")

    except Exception as e:
        print(f"❌ Error inserting new file {filepath}: {e}")



# Ensure required columns in the database
def ensure_required_columns():
    required_columns = ['classification', 'pose', 'gesture', 'detected_objects', 'filehash', 'content_hash', 'emotion']
    try:
        with db.cursor() as cursor:
            for column in required_columns:
                cursor.execute(f"""
                    SELECT COUNT(*) AS column_exists
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_NAME = 'files' AND COLUMN_NAME = '{column}'
                """)
                column_exists = cursor.fetchone()[0]
                if column_exists == 0:
                    cursor.execute(f"ALTER TABLE files ADD COLUMN {column} TEXT")
                    print(f"Added `{column}` column to the `files` table.")
            db.commit()
    except Exception as e:
        print(f"Error ensuring required columns: {e}")


# Compute file size
def get_file_size(filepath):
    try:
        return os.path.getsize(filepath)
    except Exception as e:
        print(f"Error getting file size for {filepath}: {e}")
        return None

# Calculate file hash
def calculate_filehash(filepath):
    """Calculate the hash of a file using SHA-256."""
    try:
        hasher = hashlib.sha256()
        with open(filepath, 'rb') as f:
            while chunk := f.read(8192):
                hasher.update(chunk)
        return hasher.hexdigest()
    except Exception as e:
        print(f"Error hashing file {filepath}: {e}")




        return None

# Check if file already exists in the database based on filehash




# Improved Map keypoints to gestures with added accuracy
def interpret_gesture(keypoints):
    """Interpret gestures based on pose keypoints."""
    try:
        if len(keypoints) < 17:
            return "No clear gesture detected"

        # Extract body keypoints
        left_wrist = keypoints[9]
        right_wrist = keypoints[10]
        left_shoulder = keypoints[5]
        right_shoulder = keypoints[6]
        left_knee = keypoints[13]
        right_knee = keypoints[14]
        left_ankle = keypoints[15]
        right_ankle = keypoints[16]

        # Helper to calculate distance
        def distance(p1, p2):
            return ((p1[0] - p2[0]) ** 2 + (p1[1] - p2[1]) ** 2) ** 0.5

        # Movement Detection Logic
        if left_wrist[1] < left_shoulder[1] or right_wrist[1] < right_shoulder[1]:
            return "Raising Hands"
        if distance(left_wrist, right_wrist) < 30:
            return "Clapping"
        if abs(left_ankle[1] - right_ankle[1]) > 20:
            return "Walking"
        return "No significant movement detected"
    except Exception as e:
        print(f"Error interpreting gesture: {e}")
        return "Error during gesture interpretation"




# YOLO functions
def scan_image(file_path):
    try:
        detection_results = models['detection'](file_path)

        detected_objects = []
        bounding_boxes = []

        for box in detection_results[0].boxes:
            if box.conf >= CONFIDENCE_THRESHOLD:
                label = models['detection'].names[int(box.cls)]  # Object label
                x, y, w, h = box.xywh[0].tolist()  # Convert tensor to list (x, y, width, height)

                detected_objects.append(label)
                bounding_boxes.append({
                    "label": label,
                    "x": x, "y": y, "width": w, "height": h,
                    "confidence": float(box.conf)  # Store confidence score
                })

        return detected_objects, bounding_boxes

    except Exception as e:
        print(f"Error processing image {file_path}: {e}")
        return [], []



def classify_image(file_path):
    try:
        results = models['classification'](file_path)
        top_class = results[0].probs.top1  # Most probable class index
        top_class_conf = results[0].probs.top1conf  # Confidence of the top class
        return {
            "class": models['classification'].names[top_class],
            "confidence": top_class_conf.item()
        }
    except Exception as e:
        print(f"Error during classification for {file_path}: {e}")
        return {}

def estimate_pose(file_path):
    try:
        results = models['pose'](file_path)
        keypoints = results[0].keypoints.xy.tolist() if results[0].keypoints is not None else []
        gesture = interpret_gesture(keypoints) if keypoints else "No gesture detected"
        return {"keypoints": keypoints, "gesture": gesture}
    except Exception as e:
        print(f"Error during pose estimation for {file_path}: {e}")
        return {"keypoints": [], "gesture": "Error during pose estimation"}


# ✅ Ensure the 'duplicate_of' column exists in the database
def ensure_duplicate_column():
    """Ensure that the `duplicate_of` column exists in the `files` table."""
    try:
        with db.cursor() as cursor:
            cursor.execute("""
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_NAME = 'files' AND COLUMN_NAME = 'duplicate_of'
            """)
            column_exists = cursor.fetchone()[0]

            if column_exists == 0:
                cursor.execute("ALTER TABLE files ADD COLUMN duplicate_of INT NULL")
                print("Added `duplicate_of` column to the `files` table.")
        db.commit()
    except Exception as e:
        print(f"Error ensuring `duplicate_of` column: {e}")



# ✅ Check if file already exists in the database based on filehash or content_hash
def file_exists(filehash, content_hash):
    """Check if a file with the same hash or AI content already exists and store duplicates."""
    try:
        with db.cursor(dictionary=True) as cursor:
            query = "SELECT id FROM files WHERE filehash = %s OR content_hash = %s"
            cursor.execute(query, (filehash, content_hash))

            duplicate = cursor.fetchone()  # ✅ Fetch only one result

            if duplicate:
                duplicate_id = duplicate['id']
                print(f"Duplicate detected! This file matches file ID {duplicate_id}.")

                # ✅ Clear unread results
                cursor.fetchall()  # Clears remaining results in case of multiple matches

                # ✅ Store the duplicate reference
                cursor.execute("""
                    UPDATE files
                    SET duplicate_of = %s
                    WHERE filehash = %s OR content_hash = %s
                """, (duplicate_id, filehash, content_hash))

                db.commit()
                return duplicate_id  # ✅ Return duplicate file ID

        return None  # No duplicate found
    except Exception as e:
        print(f"Error checking and storing duplicate: {e}")
        return None






# ✅ Alert and handle duplicate detection properly
def alert_and_handle_duplicates(filehash, content_hash, filepath):
    """Mark the duplicate file in the database and store its reference."""
    try:
        duplicate_id = file_exists(filehash, content_hash)

        if duplicate_id:
            print(f"Marking {filepath} as duplicate of file ID {duplicate_id}")

            file_size = os.path.getsize(filepath)  # ✅ Get the file size

            with db.cursor() as cursor:
                cursor.execute("""
                    INSERT INTO files (filename, filepath, filehash, content_hash, filetype, size, duplicate_of)
                    VALUES (%s, %s, %s, %s, %s, %s, %s)
                """, (
                    os.path.basename(filepath),
                    construct_web_url(filepath),  # Store web-accessible path
                    filehash,
                    content_hash,
                    filepath.split('.')[-1].lower(),
                    file_size,  # ✅ Include the file size
                    duplicate_id  # ✅ Reference to the original file
                ))
            db.commit()
            return True

        return False  # No duplicate found
    except Exception as e:
        print(f"Error handling duplicate file {filepath}: {e}")
        return False




def scan_and_process_directory(directory):
    """Scan the entire directory if the database is empty. Otherwise, scan only new files."""
    print("🔍 Checking if full directory scan is needed...")

    try:
        with db.cursor() as cursor:
            cursor.execute("SELECT COUNT(*) FROM files WHERE is_deleted = 0")
            total_files_in_db = cursor.fetchone()[0]

        if total_files_in_db > 0:
            print("✅ Skipping full scan. Scanning only new files.")
            scan_new_files()  # ✅ Call the function to scan only new files
            return

        print("🚀 Database is empty. Scanning entire directory...")

        total_files = 0
        processed_files = 0

        # ✅ First Pass: Count total files (excluding TRASH)
        for root, _, files in os.walk(directory):
            if TRASH_FOLDER_PATH in root:
                print(f"Skipping TRASH folder: {root}")
                continue
            total_files += len(files)

        # ✅ Second Pass: Process files
        for root, _, files in os.walk(directory):
            if TRASH_FOLDER_PATH in root:
                print(f"Skipping TRASH folder: {root}")
                continue

            for file in files:
                filepath = os.path.join(root, file)
                filetype = file.split('.')[-1].lower()

                # ✅ Skip unsupported file types
                if filetype not in image_extensions.union(video_extensions):
                    print(f"Skipping unsupported file: {file}")
                    continue

                # ✅ Calculate file hash
                filehash = calculate_filehash(filepath)
                content_hash = None  # Initialize content_hash

                if not filehash:
                    print(f"Error: Unable to calculate filehash for {filepath}")
                    continue

                # ✅ Check for duplicates and store them properly
                if alert_and_handle_duplicates(filehash, content_hash, filepath):
                    continue  # Skip further processing for duplicates

                try:
                    # ✅ Construct web URL for filepath
                    web_url = construct_web_url(filepath)

                    # ✅ Insert file metadata into the database
                    size = get_file_size(filepath)
                    creation_date = get_file_creation_date(filepath)
                    sql = """
                        INSERT INTO files (filename, filepath, filehash, filetype, size, dateupload, datecreated)
                        VALUES (%s, %s, %s, %s, %s, %s, %s)
                        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
                    """
                    with db.cursor() as cursor:
                        cursor.execute(sql, (
                            os.path.basename(filepath),
                            web_url,  # Store web-accessible file path
                            filehash,
                            filetype,
                            size,
                            datetime.now(),
                            creation_date
                        ))
                        file_id = cursor.lastrowid
                    db.commit()

                    # ✅ Process the file and update AI results
                    process_file(file_id, filepath, filetype)

                    processed_files += 1
                    progress_percentage = (processed_files / total_files) * 100
                    print(f"Progress: {progress_percentage:.2f}%")
                    sys.stdout.flush()

                except Exception as e:
                    print(f"Error processing file '{file}': {e}")

        print("✅ Full directory scan complete.")

    except mysql.connector.Error as err:
        print(f"❌ Database error: {err}")





def scan_video(file_path):
    """Perform object detection on video frames and store bounding boxes."""
    try:
        cap = cv2.VideoCapture(file_path)
        detected_objects = set()  # Use a set to avoid duplicate labels
        bounding_boxes = []
        frame_count = 0
        frame_interval = 10  # Process every 10th frame
        max_frames = 500  # Limit to avoid infinite processing

        while cap.isOpened() and frame_count < max_frames:
            ret, frame = cap.read()
            if not ret:
                break

            if frame_count % frame_interval == 0:
                try:
                    print(f"Processing frame {frame_count} of video: {file_path}")
                    # Perform detection on the frame
                    results = models['detection'](frame)

                    for box in results[0].boxes:
                        if box.conf >= CONFIDENCE_THRESHOLD:
                            label = models['detection'].names[int(box.cls)]
                            x, y, w, h = box.xywh[0].tolist()

                            detected_objects.add(label)
                            bounding_boxes.append({
                                "label": label,
                                "x": x, "y": y, "width": w, "height": h,
                                "confidence": float(box.conf)
                            })

                    print(f"Detected objects in frame {frame_count}: {list(detected_objects)}")

                except Exception as e:
                    print(f"Error detecting objects in video frame {frame_count}: {e}")

            frame_count += 1

        cap.release()
        return list(detected_objects), bounding_boxes

    except Exception as e:
        print(f"Error processing video {file_path}: {e}")
        return [], []






def calculate_content_hash(detected_objects, pose_gesture, emotions):
    """Calculate a hash combining objects, pose gestures, and emotions."""
    try:
        content_string = json.dumps({
            "objects": detected_objects,
            "gesture": pose_gesture,
            "emotions": emotions
        }, sort_keys=True)
        return hashlib.sha256(content_string.encode()).hexdigest()
    except Exception as e:
        print(f"Error calculating content hash: {e}")
        return None

# Process individual files
def process_file(file_id, file_path, file_type):
    """Perform AI detection and update the database with human-readable metadata while keeping keypoints and bounding boxes intact."""
    # Initialize variables with default values so they're always available
    detected_objects = []
    bounding_boxes = []
    detected_objects_str = "None"  # Default string if no objects detected
    bounding_boxes_json = "[]"     # Default empty JSON array
    classification = "Unknown"
    pose_keypoints = []
    pose_gesture = "No gesture detected"
    emotion = "Unknown"
    content_hash = None

    try:
        print(f"🔍 Processing AI for File ID {file_id}: {file_path}")

        if file_type in image_extensions:
            # Process image files
            # Initialize pose_data with a default value
            pose_data = {"keypoints": [], "gesture": "No gesture detected"}

            # Call AI functions for images
            detected_objects, bounding_boxes = scan_image(file_path) or ([], [])
            classification_result = classify_image(file_path) or {}
            pose_data = estimate_pose(file_path) or pose_data
            emotion = detect_emotion(file_path) or "Unknown"

            # Extract classification and pose data
            classification = classification_result.get("class", "Unknown")
            pose_gesture = pose_data.get("gesture", "No gesture detected")
            pose_keypoints = pose_data.get("keypoints", [])

            # Create human-readable strings for objects and bounding boxes
            detected_objects_str = ", ".join(detected_objects) if detected_objects else "None"
            bounding_boxes_json = json.dumps(bounding_boxes) if bounding_boxes else "[]"

            # Build the content hash based on detected objects, gesture, and emotion
            content_hash = calculate_content_hash(detected_objects, pose_gesture, emotion)

            print(">> [IMAGE] Detected objects:", detected_objects_str)
            print(">> [IMAGE] Classification:", classification)
            print(">> [IMAGE] Pose gesture:", pose_gesture)
            print(">> [IMAGE] Emotion:", emotion)

        elif file_type in video_extensions:
            # Process video files
            detected_objects, bounding_boxes = scan_video(file_path) or ([], [])
            classification = "Video"
            detected_objects_str = ", ".join(detected_objects) if detected_objects else "None"
            bounding_boxes_json = json.dumps(bounding_boxes) if bounding_boxes else "[]"

            # For videos, we use video-specific emotion analysis (no pose data)
            emotion = analyze_emotion_in_video(file_path) or "Neutral"
            content_hash = calculate_content_hash(detected_objects, classification, emotion)

            print(">> [VIDEO] Detected objects:", detected_objects_str)
            print(">> [VIDEO] Emotion:", emotion)

        else:
            print(f"File type '{file_type}' not supported for AI processing.")
            return

        # Debug log: Print values before updating the database
        print(">> Updating database with the following values:")
        print("   Detected Objects:", detected_objects_str)
        print("   Classification:", classification)
        print("   Pose Keypoints:", pose_keypoints)
        print("   Pose Gesture:", pose_gesture)
        print("   Emotion:", emotion)
        print("   Content Hash:", content_hash)
        print("   Bounding Boxes:", bounding_boxes_json)

        # Update the file record in the database with the computed AI results
        sql = """
            UPDATE files
            SET detected_objects = %s, classification = %s, pose = %s,
                gesture = %s, emotion = %s, content_hash = %s, bounding_boxes = %s, last_scanned = %s
            WHERE id = %s
        """
        with db.cursor() as cursor:
            cursor.execute(sql, (
                detected_objects_str,
                classification,
                json.dumps(pose_keypoints),
                pose_gesture,
                emotion,
                content_hash,
                bounding_boxes_json,
                datetime.now(),
                file_id
            ))
        db.commit()
        print(f"✅ Updated AI results for File ID {file_id}")

    except Exception as e:
        print(f"❌ Error processing AI for file ID {file_id}: {e}")











def detect_emotion(file_path):
    """Detect emotions in an image using DeepFace, with expanded emotion categories."""
    try:
        img = cv2.imread(file_path)
        if img is None:
            return "Unknown"

        # ✅ DeepFace emotion detection
        result = DeepFace.analyze(img, actions=['emotion'], enforce_detection=False)
        detected_emotion = result[0]['dominant_emotion']

        # ✅ Mapping detected emotions to expanded set
        emotion_mapping = {
            "angry": "angry",
            "disgust": "disgusted",
            "fear": "fearful",
            "happy": "happy",
            "sad": "sad",
            "surprise": "surprised",
            "neutral": "neutral",
            "excited": "excited",  # New
            "confused": "confused",  # New
            "tired": "tired",  # New
            "bored": "bored",  # New
            "nervous": "nervous",  # New
            "relieved": "relieved",  # New
            "proud": "proud",  # New
            "determined": "determined"  # New
        }

        # ✅ Return mapped emotion or default to detected one
        return emotion_mapping.get(detected_emotion, detected_emotion)

    except Exception as e:
        print(f"Error detecting emotion in image: {e}")
        return "Unknown"


def analyze_emotion_in_video(video_path):
    """Analyze dominant emotion from video frames, using expanded emotion categories."""
    try:
        cap = cv2.VideoCapture(video_path)
        emotions_detected = []
        frame_count = 0
        frame_interval = 30  # Analyze every 30 frames
        max_frames = 300  # Limit the number of frames analyzed

        while cap.isOpened() and frame_count < max_frames:
            ret, frame = cap.read()
            if not ret:
                break

            if frame_count % frame_interval == 0:
                try:
                    print(f"Analyzing frame {frame_count} for emotion.")
                    result = DeepFace.analyze(frame, actions=['emotion'], enforce_detection=False)
                    detected_emotion = result[0]['dominant_emotion']
                    emotions_detected.append(detected_emotion)
                    print(f"Emotion detected in frame {frame_count}: {detected_emotion}")
                except Exception as e:
                    print(f"Error analyzing emotion in frame {frame_count}: {e}")
            frame_count += 1

        cap.release()

        # ✅ Return most frequent emotion detected
        if emotions_detected:
            dominant_emotion = max(set(emotions_detected), key=emotions_detected.count)
            return dominant_emotion

        return "Neutral"

    except Exception as e:
        print(f"Error processing video emotion: {e}")
        return "Neutral"



def scan_new_files():
    """Scan ONLY files with missing metadata & AI data, ensuring AI metadata is correctly processed."""
    try:
        with db.cursor(dictionary=True) as cursor:
            cursor.execute("""
                SELECT id, filepath, filetype
                  FROM files
                 WHERE (is_deleted IS NULL OR is_deleted = 0)
                   AND (
                        last_scanned IS NULL  -- ✅ Scan new files
                     OR filename       IS NULL
                     OR filepath       IS NULL
                     OR filetype       IS NULL
                     OR size           IS NULL
                     OR datecreated    IS NULL
                     OR filehash       IS NULL
                     OR detected_objects IS NULL
                     OR classification IS NULL
                     OR pose           IS NULL
                     OR gesture        IS NULL
                     OR emotion        IS NULL
                     OR bounding_boxes IS NULL
                   )
            """)
            new_files = cursor.fetchall()

        if not new_files:
            print("✅ No new or incomplete files to scan.")
            with open("scan_progress.log", "a") as log_file:
                log_file.write("Progress: 100%\n")  # ✅ Ensure log finishes at 100%
            return

        total_files = len(new_files)
        processed_files = 0

        print(f"🚀 Scanning {total_files} files (New + Missing Metadata)...")

        for file in new_files:
            file_id   = file["id"]
            file_path = file["filepath"]
            file_type = file["filetype"]

            if not os.path.exists(file_path):
                print(f"❌ File not found: {file_path}, skipping...")
                continue

            print(f"🔍 Processing file: {file_path}")

            # ✅ Populate all missing metadata first
            populate_missing_columns(file_path)

            # ✅ Process file using AI functions (Ensures AI metadata is fully populated)
            process_file(file_id, file_path, file_type)

            # ✅ Ensure AI metadata is not empty
            with db.cursor(dictionary=True) as cursor:
                cursor.execute("""
                    SELECT detected_objects, classification, pose, gesture, emotion, bounding_boxes
                      FROM files
                     WHERE id = %s
                """, (file_id,))
                ai_metadata = cursor.fetchone()

            # ✅ If AI metadata is still empty, retry processing the file
            if (not ai_metadata["detected_objects"] or ai_metadata["detected_objects"] == "None"
                or not ai_metadata["classification"]
                or not ai_metadata["pose"]
                or not ai_metadata["gesture"]
                or not ai_metadata["emotion"]
                or not ai_metadata["bounding_boxes"]):

                print(f"⚠️ AI metadata incomplete for File ID {file_id}, retrying scan...")
                process_file(file_id, file_path, file_type)  # ✅ Retry to ensure AI metadata is filled

            # ✅ Update progress after each file
            processed_files += 1
            progress = (processed_files / total_files) * 100
            log_entry = f"Progress: {progress:.2f}%\n"

            with open("scan_progress.log", "a") as log_file:
                log_file.write(log_entry)

            print(log_entry)

            # ✅ Mark the file as scanned (update `last_scanned`)
            with db.cursor() as cursor:
                cursor.execute(
                    "UPDATE files SET last_scanned = %s WHERE id = %s",
                    (datetime.now(), file_id)
                )
            db.commit()

        # ✅ Ensure progress reaches 100% at the end
        with open("scan_progress.log", "a") as log_file:
            log_file.write("Progress: 100%\n")

        print("✅ Finished scanning new & incomplete files.")

    except mysql.connector.Error as err:
        print(f"❌ Database error: {err}")
    except Exception as e:
        print(f"❌ Error scanning new files: {e}")











# ✅ Main execution
if __name__ == "__main__":
    try:
        check_local_folder()
        ensure_required_columns()

        # ✅ Only scan all files if the database is empty
        scan_and_process_directory(LOCAL_FOLDER_PATH)

    finally:
        db.close()
        print("✅ Database connection closed.")



