<?php
session_start();
require '../config.php';
require __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve the input
    $filePath = $_POST['filepath'] ?? null; // Full file path
    $fileName = $_POST['filename'] ?? null; // File name

    // Retrieve admin ID from session
    $publishedBy = $_SESSION['employee_id'] ?? null;

    // Input validation
    if (!$filePath || !$fileName) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input: missing file path or file name.']);
        exit();
    }

    if (!$publishedBy) {
        echo json_encode(['status' => 'error', 'message' => 'Admin user not logged in.']);
        exit();
    }

    try {
        $conn = getDatabaseConnection();

        // Debugging logs
        error_log("DEBUG: Full file path: '$filePath'");
        error_log("DEBUG: File name: '$fileName'");
        error_log("DEBUG: Published by: $publishedBy");

        // Fetch the file ID from the `files` table using the file path
        $stmt = $conn->prepare("SELECT id FROM files WHERE TRIM(filepath) = TRIM(?)");
        $stmt->bind_param("s", $filePath);
        $stmt->execute();
        $stmt->bind_result($fileId);
        $stmt->fetch();
        $stmt->close();

        if (!$fileId) {
            error_log("DEBUG: No file found for file path: '$filePath'");
            echo json_encode(['status' => 'error', 'message' => 'File not found in database.']);
            exit();
        }

        // Verify the admin user exists
        $stmt = $conn->prepare("SELECT employee_id FROM admin_users WHERE employee_id = ?");
        $stmt->bind_param("i", $publishedBy);
        $stmt->execute();
        $stmt->bind_result($adminId);
        $stmt->fetch();
        $stmt->close();

        if (!$adminId) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid admin user.']);
            exit();
        }

        // Insert into the `published_files` table
        $status = 'published';
        $stmt = $conn->prepare(
            "INSERT INTO published_files (file_id, published_at, published_by, status) VALUES (?, NOW(), ?, ?)"
        );
        $stmt->bind_param("iis", $fileId, $publishedBy, $status);
        $stmt->execute();

        // Check if the insert was successful
        if ($stmt->affected_rows > 0) {
            echo json_encode(['status' => 'success', 'message' => 'File published successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to publish the file.']);
        }

        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        error_log("ERROR: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Unexpected error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}

?>