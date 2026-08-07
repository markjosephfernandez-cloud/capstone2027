<?php
require 'config.php';
header('Content-Type: application/json');

// Optional debugging log file
$logFile = '/Applications/XAMPP/xamppfiles/htdocs/debug_restore.txt';

// Paths for trash and restore folder
$trashDirectory     = realpath('/Applications/XAMPP/xamppfiles/htdocs/TRASH/');
$testcreativeFolder = realpath('/Applications/XAMPP/xamppfiles/htdocs/testcreative/');

// 1) Decode JSON from request
$data = json_decode(file_get_contents("php://input"), true);
file_put_contents($logFile, "Restore request:\n" . print_r($data, true) . "\n", FILE_APPEND);

// 2) Ensure we have a trash_id or array of them
//    This code supports both single or multiple IDs.
if (!isset($data['trash_id']) || empty($data['trash_id'])) {
    echo json_encode([
        'success' => false,
        'error'   => 'Missing trash_id in request. Either pass a single ID or an array of IDs.'
    ]);
    exit;
}

// Convert single ID to array for uniform handling
$trashIds = (array) $data['trash_id'];

// We'll accumulate any errors or successes
$restoredFiles = [];
$errors        = [];

foreach ($trashIds as $id) {
    $trashId = (int) $id;

    // 3) Look up the record in the `trash` table
    $stmt = $conn->prepare("SELECT id, filename, filepath FROM trash WHERE id = ?");
    $stmt->bind_param("i", $trashId);
    $stmt->execute();
    $result   = $stmt->get_result();
    $trashRow = $result->fetch_assoc();
    $stmt->close();

    if (!$trashRow) {
        $errors[] = "No matching record in trash table for trash_id: {$trashId}";
        continue;
    }

    // 4) Check if file exists in trash folder
    $trashFilePath = $trashRow['filepath'];
    if (!file_exists($trashFilePath)) {
        $errors[] = "File not found in TRASH folder: {$trashFilePath}";
        continue;
    }

    // 5) Construct restore path in /testcreative/
    $filename    = basename($trashRow['filename']);  // e.g. "09 CBA.jpg"
    $restorePath = $testcreativeFolder . '/' . $filename;

    // 6) Check for name conflict
    if (file_exists($restorePath)) {
        $errors[] = "A file with the same name already exists: {$restorePath}";
        continue;
    }

    // 7) Attempt to move the file
    if (!@rename($trashFilePath, $restorePath)) {
        $errors[] = "Failed to move file from trash to: {$restorePath}";
        continue;
    }

    // 8) Remove from `trash` table
    $delStmt = $conn->prepare("DELETE FROM trash WHERE id = ?");
    $delStmt->bind_param("i", $trashId);
    $delStmt->execute();
    $delStmt->close();

    // 9) Record success
    $restoredFiles[] = [
        'trash_id'    => $trashId,
        'restoredTo'  => $restorePath
    ];
}

// Prepare final response
if (!empty($errors)) {
    echo json_encode([
        'success'       => false,
        'restoredFiles' => $restoredFiles,
        'errors'        => $errors
    ]);
} else {
    echo json_encode([
        'success'       => true,
        'restoredFiles' => $restoredFiles
    ]);
}
?>
