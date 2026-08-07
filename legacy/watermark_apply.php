<?php
require "config.php";
require 'login-check.php';
require_once 'watermark_helper.php';

header('Content-Type: application/json');

$mediaId = isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0;
if ($mediaId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid media_id']);
    exit;
}

$actorId = $_SESSION['employee_id'] ?? null;
$path = watermark_media($conn, $mediaId, $actorId);

if ($path) {
    echo json_encode(['success' => true, 'watermarked_path' => $path]);
} else {
    echo json_encode(['success' => false, 'error' => 'Watermarking is disabled, the file type is unsupported, or GD is unavailable.']);
}
