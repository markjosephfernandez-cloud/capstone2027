<?php
require 'config.php';
header('Content-Type: application/json');

// Get input data
$data = json_decode(file_get_contents('php://input'), true);
$filepath = $data['filepath'] ?? '';

$response = ['status' => 'error', 'message' => 'File not found'];

// Query database for file details
$stmt = $conn->prepare("SELECT tag, description, featured FROM files WHERE filepath = ?");
$stmt->bind_param("s", $filepath);
$stmt->execute();
$stmt->bind_result($tag, $description, $featured);

if ($stmt->fetch()) {
    $response = [
        'status' => 'success',
        'tag' => $tag,
        'description' => $description,
        'featured' => $featured
    ];
}

$stmt->close();
$conn->close();

echo json_encode($response);
exit;
