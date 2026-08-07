<?php
require "config.php";
require 'login-check.php';
require_once 'passport_helper.php';

header('Content-Type: application/json');

$mediaId = isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0;
if ($mediaId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid media_id']);
    exit;
}

$actorId = $_SESSION['employee_id'] ?? null;

$passport = generate_passport($conn, $mediaId, $actorId);

if ($passport) {
    echo json_encode(['success' => true, 'passport' => $passport]);
} else {
    echo json_encode(['success' => false, 'error' => 'Media not found or passport could not be generated']);
}
