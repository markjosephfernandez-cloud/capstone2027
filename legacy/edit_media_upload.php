<?php
require "config.php"; // Include your database configuration

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Retrieve POST data
    $id = $_POST['id'] ?? null;
    $title = $_POST['title'] ?? null;
    $description = $_POST['description'] ?? null;
    $playlist = $_POST['playlist'] ?? null;
    $audience = $_POST['audience'] ?? null;

    // Check if the ID is provided
    if ($id === null) {
        echo json_encode(['status' => 'error', 'message' => 'Media ID is required.']);
        exit();
    }

    // Optional: Prepare to update thumbnail and video file if provided
    $thumbnailFilePath = null;
    $videoFilePath = null;

    if (!empty($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] == 0) {
        $targetDir = "uploads/thumbnails/";
        $targetFile = $targetDir . basename($_FILES["thumbnail"]["name"]);
        if (move_uploaded_file($_FILES["thumbnail"]["tmp_name"], $targetFile)) {
            $thumbnailFilePath = $targetFile; // Path where thumbnail is saved
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to upload thumbnail.']);
            exit();
        }
    }

    if (!empty($_FILES['file']) && $_FILES['file']['error'] == 0) {
        $targetDir = "uploads/videos/";
        $targetFile = $targetDir . basename($_FILES["file"]["name"]);
        if (move_uploaded_file($_FILES["file"]["tmp_name"], $targetFile)) {
            $videoFilePath = $targetFile; // Path where video file is saved
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to upload video file.']);
            exit();
        }
    }

    // Build the update query with parameters
    $sql = "UPDATE files SET title = ?, description = ?, playlist = ?, audience = ?";
    $types = "ssss";
    $params = [$title, $description, $playlist, $audience];

    // Append thumbnail file path to the update query if provided
    if ($thumbnailFilePath) {
        $sql .= ", thumbnail = ?";
        $types .= "s";
        $params[] = $thumbnailFilePath;
    }

    // Append video file path to the update query if provided
    if ($videoFilePath) {
        $sql .= ", filepath = ?";
        $types .= "s";
        $params[] = $videoFilePath;
    }

    $sql .= " WHERE id = ?";
    $types .= "i";
    $params[] = $id;

    // Prepare and execute the statement
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Media updated successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update media.']);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to prepare statement.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}


?>
