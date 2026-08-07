<?php
require '../config.php';
require __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $filepath = $_POST['filepath'] ?? null;
    $newName = $_POST['newName'] ?? null;
    $description = $_POST['description'] ?? '';
    $tags = $_POST['tags'] ?? '';

    if (!$filepath || !$newName) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input: Filepath and new name are required.']);
        exit();
    }

    $realFilePath = realpath($filepath);
    if (!$realFilePath || !file_exists($realFilePath)) {
        error_log("DEBUG: Received filepath: $filepath");
        error_log("DEBUG: Resolved realpath: $realFilePath");
        echo json_encode(['status' => 'error', 'message' => 'File not found.']);
        exit();
    }

    $newFilePath = dirname($realFilePath) . '/' . $newName;

    // Check if a file with the new name already exists
    if (file_exists($newFilePath)) {
        echo json_encode(['status' => 'error', 'message' => 'A file or folder with the new name already exists.']);
        exit();
    }

    // Rename the file on the AFP server
    if (rename($realFilePath, $newFilePath)) {
        // Get the updated file type and extension
        $newFileType = pathinfo($newFilePath, PATHINFO_EXTENSION); // File extension
        $newMimeType = mime_content_type($newFilePath); // MIME type

        // Update the database with the new name, path, description, and tags
        $conn = getDatabaseConnection();
        $stmt = $conn->prepare(
            "UPDATE files SET filename = ?, filepath = ?, filetype = ?, description = ?, tags = ? WHERE filepath = ?"
        );

        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: Unable to prepare statement.']);
            exit();
        }

        $stmt->bind_param("ssssss", $newName, $newFilePath, $newFileType, $description, $tags, $filepath);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            echo json_encode([
                'status' => 'success',
                'message' => 'File updated successfully.',
                'data' => [
                    'newFilePath' => $newFilePath,
                    'newFileType' => $newFileType,
                    'newName' => $newName,
                    'description' => $description,
                    'tags' => $tags,
                ]
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'No changes were made, but the file is valid.'
            ]);
        }

        $stmt->close();
        $conn->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to rename file on server.']);
    }
}
?>
