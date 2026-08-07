import os
import mysql.connector
from difflib import SequenceMatcher

# Database Configuration
DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "capstone2425",
    "database": "greyhound_creative"
}

def similar(a, b):
    """Calculate similarity ratio between two strings."""
    return SequenceMatcher(None, a, b).ratio()

def find_duplicate_files(file_id, filename, threshold=0.9):
    """
    Find duplicates for a specific file based on filename similarity.
    :param file_id: ID of the file to check.
    :param filename: Name of the file to check.
    :param threshold: Similarity ratio threshold for duplicates.
    :return: List of duplicate file IDs.
    """
    try:
        # Connect to the database
        db = mysql.connector.connect(**DB_CONFIG)
        cursor = db.cursor(dictionary=True)

        # Fetch all filenames except the current file
        cursor.execute("SELECT id, filename FROM files WHERE id != %s", (file_id,))
        files = cursor.fetchall()

        duplicates = []
        for file in files:
            similarity = similar(filename.lower(), file['filename'].lower())
            if similarity >= threshold:
                duplicates.append({"id": file['id'], "filename": file['filename'], "similarity": similarity})

        db.close()
        return duplicates

    except Exception as e:
        print(f"Error while finding duplicates: {e}")
        return []

if __name__ == "__main__":
    import sys
    if len(sys.argv) != 3:
        print("Usage: python duplicate_scan.py <file_id> <filename>")
        sys.exit(1)

    file_id = int(sys.argv[1])
    filename = sys.argv[2]

    duplicates = find_duplicate_files(file_id, filename)
    if duplicates:
        print(f"Duplicates found for '{filename}':")
        for dup in duplicates:
            print(f"  ID: {dup['id']}, Filename: {dup['filename']}, Similarity: {dup['similarity']:.2f}")
    else:
        print(f"No duplicates found for '{filename}'.")
