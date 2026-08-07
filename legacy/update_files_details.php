<?php
require 'config.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!$data || !isset($data['filepath']) || !isset($data['featured'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

$filepath = trim($data['filepath']);
$featured = (int)$data['featured'];

// First, check if the filepath actually exists
$checkStmt = $conn->prepare("SELECT id FROM files WHERE filepath = ?");
$checkStmt->bind_param("s", $filepath);
$checkStmt->execute();
$checkStmt->store_result();

if ($checkStmt->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'File not found']);
    $checkStmt->close();
    $conn->close();
    exit;
}

$checkStmt->close();

// Update 'featured' status explicitly
$stmt = $conn->prepare("UPDATE files SET featured = ? WHERE filepath = ?");
$stmt->bind_param("is", $featured, $filepath);
$stmt->execute();

// Check if update was successful
if ($stmt->affected_rows > 0) {
    $message = $featured ? 'Featured status set to Yes.' : 'Featured status set to No.';
    echo json_encode(['status' => 'success', 'message' => $message]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'No changes were made.']);
}

$stmt->close();
$conn->close();
