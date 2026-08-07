<?php
require 'config.php';

$response = ['status' => 'error', 'message' => 'Upload failed.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $uploadDir = '/path/to/upload/directory/';
    
    foreach ($_FILES['file']['name'] as $index => $name) {
        $tmpFilePath = $_FILES['file']['tmp_name'][$index];
        $newFilePath = $uploadDir . basename($name);

        if (move_uploaded_file($tmpFilePath, $newFilePath)) {
            $response['status'] = 'success';
            $response['message'] = 'File uploaded successfully!';
        } else {
            $response['message'] = 'Failed to upload ' . $name;
        }
    }
}

echo json_encode($response);
?>
