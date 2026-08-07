<?php
require_once 'config.php';
session_start();
header('Content-Type: application/json');

$employee_id = $_SESSION['employee_id'] ?? null;
if (!$employee_id) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['filePath']) || !isset($data['newName'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters.']);
    exit;
}

$filePath = trim($data['filePath']);
$newName = trim($data['newName']);

if (!file_exists($filePath)) {
    echo json_encode(['success' => false, 'error' => 'Original file not found.']);
    exit;
}

$ext = pathinfo($filePath, PATHINFO_EXTENSION);
if (pathinfo($newName, PATHINFO_EXTENSION) === '') {
    $newName .= '.' . $ext;
}
$newPath = dirname($filePath) . '/' . $newName;

if (!rename($filePath, $newPath)) {
    echo json_encode(['success' => false, 'error' => 'Rename failed.']);
    exit;
}

// ✅ Update database
$stmt = $conn->prepare("
    UPDATE files 
    SET filepath = ?, filename = ?, 
        last_action = 'renamed',
        last_action_at = NOW(),
        employee_id = ? 
    WHERE filepath = ?
");
$stmt->bind_param("ssis", $newPath, $newName, $employee_id, $filePath);
$stmt->execute();
$stmt->close();

echo json_encode([
    'success' => true,
    'message' => 'File renamed.',
    'newFilePath' => $newPath,
    'newFileName' => $newName
]);
?>
