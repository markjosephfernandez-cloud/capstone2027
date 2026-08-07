<?php
require_once 'config.php'; // Include database configuration

// Ensure database connection
if (!isset($conn) || $conn->connect_error) {
    die(json_encode(['status' => 'error', 'message' => 'Database connection failed.']));
}

// Decode JSON input
$data = json_decode(file_get_contents('php://input'), true);

// Debugging: Log incoming request
error_log("Received JSON: " . json_encode($data));

if (!isset($data['filepath']) || empty($data['filepath'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request. Missing file path.']);
    exit;
}

$filePath = trim($data['filepath']);

// Function to recursively delete directories
function deleteDirectory($dir) {
    if (!is_dir($dir)) return is_file($dir) ? unlink($dir) : false;

    foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
        $filePath = "$dir/$file";
        is_dir($filePath) ? deleteDirectory($filePath) : unlink($filePath);
    }
    return rmdir($dir);
}

// Attempt to delete the file or directory
if (is_dir($filePath)) {
    $deleted = deleteDirectory($filePath);
} else if (file_exists($filePath)) {
    $deleted = unlink($filePath);
} else {
    echo json_encode(['status' => 'error', 'message' => 'File or directory not found.']);
    $conn->close();
    exit;
}

// If deletion was successful, remove from the database
if ($deleted) {
    $stmtFiles = $conn->prepare("DELETE FROM files WHERE filepath = ?");
    if ($stmtFiles) {
        $stmtFiles->bind_param("s", $filePath);
        $stmtFiles->execute();
        $affectedFiles = $stmtFiles->affected_rows;
        $stmtFiles->close();
    } else {
        error_log("Database error: " . $conn->error);
    }

    $stmtAlbumFiles = $conn->prepare("DELETE FROM album_files WHERE filepath = ?");
    if ($stmtAlbumFiles) {
        $stmtAlbumFiles->bind_param("s", $filePath);
        $stmtAlbumFiles->execute();
        $affectedAlbums = $stmtAlbumFiles->affected_rows;
        $stmtAlbumFiles->close();
    } else {
        error_log("Database error: " . $conn->error);
    }

    // Response
    if ($affectedFiles > 0 || $affectedAlbums > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Item deleted successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Item deleted from filesystem, but no database records found.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to delete the item from the filesystem.']);
}

$conn->close();
?>
