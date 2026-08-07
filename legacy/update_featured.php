<?php
// update_featured.php
require 'config.php'; // Make sure your DB connection is set up

// Get POST data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $filename = $_POST['filename'] ?? '';
    $featured = isset($_POST['featured']) ? intval($_POST['featured']) : 0;

    if ($filename !== '') {
        // Update the featured status in the database
        $stmt = $conn->prepare("UPDATE album_files SET featured = ? WHERE filename = ?");
        if ($stmt) {
            $stmt->bind_param("is", $featured, $filename);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Featured status updated']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Database update failed']);
            }
            $stmt->close();
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Statement preparation failed']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Filename not provided']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
?>
