<?php
require 'config.php'; // Include your database configuration

if (isset($_POST['filename']) && isset($_POST['downloadable'])) {
    $filename = $_POST['filename'];
    $downloadable = intval($_POST['downloadable']); // Ensure it's 0 or 1

    // Update query using prepared statements
    $stmt = $conn->prepare("UPDATE album_files SET downloadable = ? WHERE filename = ?");
    $stmt->bind_param("is", $downloadable, $filename);
    
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Downloadable status updated."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Database update failed."]);
    }

    $stmt->close();
} else {
    echo json_encode(["status" => "error", "message" => "Invalid input."]);
}

$conn->close();
?>
