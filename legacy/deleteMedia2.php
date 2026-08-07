<?php
require_once 'config.php'; // Include database configuration

$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['filepath'])) {
    $filePath = trim($data['filepath']);

    // Log for debugging
    error_log("Delete request for: $filePath");

    // Function to recursively delete directories
    function deleteDirectory($dir) {
        if (!file_exists($dir)) {
            return true;
        }
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') continue;
            if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        return rmdir($dir);
    }

    if (is_dir($filePath)) {
        // It's a directory; attempt to delete recursively
        $deleted = deleteDirectory($filePath);
    } else if (file_exists($filePath)) {
        // It's a file; attempt to delete
        $deleted = unlink($filePath);
    } else {
        // File or directory does not exist
        echo json_encode(['status' => 'error', 'message' => 'File or directory not found.']);
        $conn->close();
        exit;
    }

    if ($deleted) {
        // Attempt to delete the record from the database
        $stmt = $conn->prepare("DELETE FROM files WHERE filepath = ?");
        $stmt->bind_param("s", $filePath);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Item deleted successfully from filesystem and database.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Item deleted from filesystem, but failed to delete record from database: ' . $conn->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete the item from the filesystem.']);
    }
} else {
    // If required parameters are missing
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters.']);
}

// Close database connection
$conn->close();

// Log raw POST data for debugging
error_log("Raw POST data: " . file_get_contents('php://input'));
?>
