<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require 'config.php';
session_start();

$trash_directory = '/Applications/XAMPP/xamppfiles/htdocs/TRASH/';

function adjustFilePath($filePath) {
    if (str_starts_with($filePath, '/Applications/XAMPP/xamppfiles/htdocs/')) {
        return $filePath;
    }
    if (str_starts_with($filePath, 'http://localhost/')) {
        return str_replace("http://localhost/", "/Applications/XAMPP/xamppfiles/htdocs/", $filePath);
    }
    return $filePath; // fallback: return as-is
}

$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['filepath']) && isset($data['fileName'])) {
    $originalFilePath = adjustFilePath(trim($data['filepath']));
    $fileName = trim($data['fileName']);
    $employee_id = $_SESSION['employee_id'] ?? null;

    if (!$employee_id) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized user.']);
        exit;
    }

    // Look up file ID in database
    $stmt = $conn->prepare("SELECT id FROM files WHERE filepath = ?");
    $stmt->bind_param("s", $originalFilePath);
    $stmt->execute();
    $stmt->bind_result($file_id);
    $stmt->fetch();
    $stmt->close();

    if (!$file_id) {
        echo json_encode(['status' => 'error', 'message' => 'File not found in database.']);
        exit;
    }

    $fileExists = file_exists($originalFilePath);
    $finalTrashPath = null;

    if ($fileExists) {
        if (!is_dir($trash_directory)) {
            mkdir($trash_directory, 0777, true);
        }

        $safeFileName = basename($fileName);
        $newPath = rtrim($trash_directory, '/') . '/' . $safeFileName;

        if (file_exists($newPath)) {
            $fileInfo = pathinfo($safeFileName);
            $newPath = rtrim($trash_directory, '/') . '/' . $fileInfo['filename'] . '_' . time() . '.' . $fileInfo['extension'];
        }

        if (!rename($originalFilePath, $newPath)) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to move file to trash directory.']);
            exit;
        }

        $finalTrashPath = $newPath;
        $reason = "Moved to trash";
    } else {
        $reason = "Missing from disk";
    }

    // Insert trash record (removed description column)
    $stmtInsert = $conn->prepare("
        INSERT INTO trashfiles (file_id, deleted_by, reason)
        VALUES (?, ?, ?)
    ");
    $stmtInsert->bind_param("iis", $file_id, $employee_id, $reason);
    $stmtInsert->execute();
    $stmtInsert->close();

    // Soft-delete and update path in `files` table
    $stmtUpdate = $conn->prepare("
    UPDATE files 
    SET filepath = ?,
        is_deleted = 1,
        last_action = 'moved_to_trash',
        last_action_at = NOW(),
        employee_id = ? 
    WHERE id = ?
");
    $stmtUpdate->bind_param("sii", $finalTrashPath, $employee_id, $file_id);

    $stmtUpdate->execute();
    $stmtUpdate->close();

    echo json_encode([
        'status' => 'success',
        'message' => $fileExists ? 'File moved to trash.' : 'File missing, but deletion recorded.',
        'trashPath' => $finalTrashPath
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters.']);
}
?>
