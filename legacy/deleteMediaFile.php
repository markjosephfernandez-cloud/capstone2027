<?php
require_once 'config.php'; // Include database configuration
header('Content-Type: application/json'); // Ensure JSON response

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['filepath']) || empty($data['filepath']) || !isset($data['itemType'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request. Missing necessary data.']);
    exit;
}

$filePath = trim($data['filepath']);
$itemType = $data['itemType'];

function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        error_log("Not a directory: $dir");
        return false;
    }
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $fullPath = "$dir/$file";
        if (is_dir($fullPath)) {
            if (!deleteDirectory($fullPath)) {
                error_log("Failed to delete directory: $fullPath");
                return false;
            }
        } else {
            if (!unlink($fullPath)) {
                error_log("Failed to delete file: $fullPath");
                return false;
            }
        }
    }
    if (!rmdir($dir)) {
        error_log("Failed to remove directory: $dir");
        return false;
    }
    return true;
}

if ($itemType === 'folder') {
    // Directly delete directories without database queries
    $deleted = deleteDirectory($filePath);
    if ($deleted) {
        echo json_encode(['status' => 'success', 'message' => 'Folder deleted successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete the folder from the filesystem. Ensure permissions and that the directory is not in use.']);
    }
} else {
    // Handle file deletion with database entry removal
    if (file_exists($filePath)) {
        $deleted = unlink($filePath);
        if ($deleted) {
            // Proceed with database deletion for files
            if ($stmt = $conn->prepare("DELETE FROM files WHERE filepath = ?")) {
                $stmt->bind_param("s", $filePath);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                if ($affected > 0) {
                    echo json_encode(['status' => 'success', 'message' => "File deleted successfully, database updated. Affected rows: $affected"]);
                } else {
                    echo json_encode(['status' => 'warning', 'message' => "File deleted from filesystem, but no database records were found to update."]);
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Database error during deletion.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete the file from the filesystem.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'File not found.']);
    }
}

$conn->close();
?>
