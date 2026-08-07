<?php
include 'config.php';  // Ensure correct database connection

header('Content-Type: application/json');

// Function to log errors
function logError($message) {
    error_log("[moveFile.php] " . $message); // Logs errors to PHP/Apache logs
}

// Function to copy the file and insert metadata into the database
function copyFile($filePath, $albumName, $albumId, $filename) {
    global $conn;  // Ensure database connection is available

    $baseDir = '/Applications/XAMPP/xamppfiles/htdocs/testcreative/Featured/';
    $targetDir = $baseDir . $albumName;
    $newPath = $targetDir . '/' . basename($filePath);

    // Ensure the target directory exists
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true)) {
        logError("Failed to create directory: $targetDir");
        return ['status' => 'error', 'message' => 'Failed to create target directory.'];
    }

    // Copy the file instead of moving it
    if (!copy($filePath, $newPath)) {
        logError("Failed to copy file from $filePath to $newPath");
        return ['status' => 'error', 'message' => 'Failed to copy file. Error: ' . error_get_last()['message']];
    }

    // Ensure album ID is valid
    if (!isset($albumId) || empty($albumId) || !is_numeric($albumId)) {
        logError("Invalid album ID provided: $albumId");
        return ['status' => 'error', 'message' => "Invalid album ID provided."];
    }

    // Fetch file metadata
    if (!file_exists($newPath)) {
        logError("File does not exist after copying: $newPath");
        return ['status' => 'error', 'message' => 'File copy operation failed, file not found.'];
    }

    $fileSize = filesize($newPath); // Get file size
    $fileType = mime_content_type($newPath); // Get file type
    $dateUpload = date('Y-m-d H:i:s');

    // Log the file details before inserting into the database
    logError(
        "Preparing to insert into database: " .
        "Filename: $filename, Filepath: $newPath, Filetype: $fileType, " .
        "Size: $fileSize, Album ID: $albumId"
    );

    // Insert file metadata into album_files table
    $stmt = $conn->prepare("INSERT INTO album_files (filename, filepath, filetype, size, dateupload, album_id) VALUES (?, ?, ?, ?, ?, ?)");
    
    if (!$stmt) {
        logError("Database prepare failed: " . $conn->error);
        return ['status' => 'error', 'message' => 'Database prepare failed: ' . $conn->error];
    }

    $stmt->bind_param("sssisi", $filename, $newPath, $fileType, $fileSize, $dateUpload, $albumId);
    
    if (!$stmt->execute()) {
        logError("Database insert failed: " . $stmt->error);
        return ['status' => 'error', 'message' => 'Failed to insert file data: ' . $stmt->error];
    }

    return ['status' => 'success', 'message' => 'File copied and data inserted successfully'];
}

// Receive and decode JSON input
$json_str = file_get_contents('php://input');
$request = json_decode($json_str, true);

// Log received data
logError("Received Data: " . json_encode($request));

if (isset($request['filepath'], $request['albumName'], $request['albumId'], $request['filename'])) {
    $albumId = intval($request['albumId']); // Ensure album ID is an integer
    $response = copyFile($request['filepath'], $request['albumName'], $albumId, $request['filename']);
} else {
    logError("Invalid request data: " . json_encode($request));
    $response = ['status' => 'error', 'message' => 'Invalid request data'];
}

echo json_encode($response);
?>
