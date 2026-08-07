<?php
session_start();

require 'config.php';
require 'login-check.php';

header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"), true);

if (
    empty($data['filepath']) ||
    empty($data['item_name']) ||
    empty($data['item_type'])
) {
    echo json_encode([
        "success" => false,
        "error" => "Invalid data"
    ]);
    exit;
}

$employee_id = $_SESSION['employee_id'];

$filepath = $data['filepath'];
$item_name = $data['item_name'];
$item_type = $data['item_type'];

$sql = "INSERT INTO recent
(employee_id,item_type,item_name,filepath,timestamp)
VALUES (?,?,?,?,NOW())";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "isss",
    $employee_id,
    $item_type,
    $item_name,
    $filepath
);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode([
        "success" => false,
        "error" => $stmt->error
    ]);
}