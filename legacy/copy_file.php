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
if (!isset($data['filePath']) || empty(trim($data['filePath']))) {
    echo json_encode(['success' => false, 'error' => 'Invalid file path.']);
    exit;
}

$filePath = trim($data['filePath']);
if (!file_exists($filePath)) {
    echo json_encode(['success' => false, 'error' => 'File not found.']);
    exit;
}

$directory = dirname($filePath);
$originalName = basename($filePath);
$ext = pathinfo($filePath, PATHINFO_EXTENSION);
$baseName = pathinfo($filePath, PATHINFO_FILENAME);

$counter = 1;
$newName = "Copy_of_$baseName.$ext";
$newPath = "$directory/$newName";
while (file_exists($newPath)) {
    $newName = "Copy_of_{$baseName}_$counter.$ext";
    $newPath = "$directory/$newName";
    $counter++;
}

if (!copy($filePath, $newPath)) {
    echo json_encode(['success' => false, 'error' => 'Copy failed.']);
    exit;
}

$size = filesize($newPath);
$dateupload = date('Y-m-d H:i:s');
$datecreated = filectime($newPath) ? date('Y-m-d H:i:s', filectime($newPath)) : $dateupload;
$filehash = hash_file('sha256', $newPath);
$content_hash = md5_file($newPath);

$stmt = $conn->prepare("
    INSERT INTO files (
        filename, filepath, filetype, size, dateupload, datecreated,
        filehash, content_hash, is_deleted, employee_id, last_action, last_action_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'duplicated', NOW())
");
$stmt->bind_param(
    "ssssssssi",
    $newName, $newPath, $ext, $size, $dateupload, $datecreated, $filehash, $content_hash, $employee_id
);
$stmt->execute();
$inserted_id = $conn->insert_id;
$stmt->close();

$stmt = $conn->prepare("SELECT * FROM files WHERE id = ?");
$stmt->bind_param("i", $inserted_id);
$stmt->execute();
$result = $stmt->get_result();
$fileData = $result->fetch_assoc();
$stmt->close();

echo json_encode([
    'success' => true,
    'message' => 'File duplicated successfully.',
    'file' => $fileData,
    'newPath' => $newPath // ✅ required for frontend display
]);

$conn->close();
?>
