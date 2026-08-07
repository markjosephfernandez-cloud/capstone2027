<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require 'config.php';
session_start();
date_default_timezone_set('Asia/Manila');

function ensureDirectoryExists($path) {
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
}

header('Content-Type: application/json');

$employee_id = $_SESSION['employee_id'] ?? null;
if (!$employee_id) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['filepath']) || empty($data['filepath'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing parameter: filepath']);
    exit;
}

$paths = is_array($data['filepath']) ? $data['filepath'] : [$data['filepath']];
$restored = 0;
$errors = [];
$debug = [];

foreach ($paths as $trashPath) {
    $filename = basename($trashPath);

    // Look up trashfile + original file data
    $query = "
        SELECT tf.*, f.*
        FROM trashfiles tf
        LEFT JOIN files f ON tf.file_id = f.id
        WHERE f.filename = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $filename);
    $stmt->execute();
    $result = $stmt->get_result();
    $trash = $result->fetch_assoc();
    $stmt->close();

    if (!$trash) {
        $errors[] = "No metadata found for: $filename";
        $debug[] = ['missing_metadata' => $filename];
        continue;
    }

    $originalPath = $trash['filepath'];

    if (!file_exists($trashPath)) {
        $errors[] = "Missing file in trash: $trashPath";
        $debug[] = ['missing_file' => $trashPath];
        continue;
    }

    ensureDirectoryExists($originalPath);

    if (!rename($trashPath, $originalPath)) {
        $errors[] = "Failed to restore: $filename";
        $debug[] = ['rename_failed' => ['from' => $trashPath, 'to' => $originalPath]];
        continue;
    }

    // Re-insert into `files` table
// ✅ Just update the file record to restore
    $stmt = $conn->prepare("
    UPDATE files 
    SET is_deleted = 0, 
        last_action = 'restored', 
        last_action_at = NOW(), 
        employee_id = ? 
    WHERE id = ?
");
    $stmt->bind_param("ii", $employee_id, $trash['file_id']);
    $stmt->execute();
    $stmt->close();


    // Remove from `trashfiles`
    $delete = $conn->prepare("DELETE FROM trashfiles WHERE file_id = ?");
    $delete->bind_param("i", $trash['file_id']);
    $delete->execute();
    $delete->close();

    $restored++;
}

if ($restored > 0) {
    echo json_encode([
        'status' => 'success',
        'message' => "$restored file(s) restored.",
        'errors' => $errors,
        'debug' => $debug
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'No files restored.',
        'errors' => $errors,
        'debug' => $debug
    ]);
}
?>
