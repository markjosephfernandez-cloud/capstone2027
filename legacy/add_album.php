<?php
include 'config.php';

$response = ['status' => 'error', 'message' => 'Something went wrong'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['albumName'])) {
    $albumName = trim($_POST['albumName']);

    if (!empty($albumName)) {
        // Insert into album_files table
        $stmt = $conn->prepare("INSERT INTO album_files (filename, filepath, filetype, size, dateupload, description, tag_id, album_id) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?)");
        $emptyValue = NULL; // Placeholder for nullable fields
        $stmt->bind_param("sssissi", $albumName, $emptyValue, $emptyValue, $emptyValue, $emptyValue, $emptyValue, $emptyValue);

        if ($stmt->execute()) {
            $response['status'] = 'success';
            $response['message'] = "Album '$albumName' added successfully.";
        } else {
            $response['message'] = "Failed to insert album.";
        }
        $stmt->close();
    } else {
        $response['message'] = "Album name cannot be empty.";
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>
