<?php
session_start();
require 'config.php';
header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

$employee_id = $_SESSION['employee_id'] ?? null;
if (!$employee_id) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$selectedFiles = $data['selectedFiles'] ?? [];

$trashDirectory = '/Applications/XAMPP/xamppfiles/htdocs/TRASH';

if (!is_array($selectedFiles) || empty($action)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
    exit;
}

$errors = [];

// 🔴 BULK DELETE
if ($action === 'delete') {
    $deleted = 0;

    foreach ($selectedFiles as $file) {
        $filepath = $file['filepath'] ?? '';
        $filename = $file['filename'] ?? basename($filepath) ?: 'Unknown';

        // Check if file exists on disk
        if (file_exists($filepath)) {
            if (!unlink($filepath)) {
                $errors[] = "$filename: Could not delete from disk.";
                continue;
            }
        } else {
            $errors[] = "$filename: File missing from disk.";
            // You can still proceed to mark it deleted in the DB
        }

        // Mark as soft-deleted in DB
        $stmt = $conn->prepare("
            UPDATE files 
            SET is_deleted = 1,
                last_action = 'deleted',
                last_action_at = NOW(),
                employee_id = ?
            WHERE filepath = ?
        ");
        $stmt->bind_param("is", $employee_id, $filepath);
        $stmt->execute();
        $stmt->close();

        $deleted++;
    }

    echo json_encode([
        'status' => 'success',
        'message' => "$deleted file(s) deleted successfully.",
        'successCount' => $deleted,
        'errors' => $errors
    ]);
    exit;
}


// 🔴 BULK MOVE TO TRASH
if ($action === 'move_to_trash') {
    $moved = 0;

    foreach ($selectedFiles as $file) {
        $filepath = $file['filepath'] ?? '';
        $filename = $file['filename'] ?? basename($filepath) ?: 'Unknown';

        if (!file_exists($filepath)) {
            $errors[] = "$filename: File does not exist.";
            continue;
        }

        $info = pathinfo($filename);
        $newPath = $trashDirectory . '/' . $info['basename'];

        if (file_exists($newPath)) {
            $newPath = $trashDirectory . '/' . $info['filename'] . '_' . time() . '.' . ($info['extension'] ?? '');
        }

        if (!rename($filepath, $newPath)) {
            $errors[] = "$filename: Failed to move to trash.";
            continue;
        }

        $stmt = $conn->prepare("UPDATE files SET filepath = ?, is_deleted = 1, last_action = 'moved_to_trash', employee_id = ?, last_action_at = NOW() WHERE filepath = ?");
        $stmt->bind_param("sis", $newPath, $employee_id, $filepath);
        $stmt->execute();
        $stmt->close();

        $fetch = $conn->prepare("SELECT id FROM files WHERE filepath = ?");
        $fetch->bind_param("s", $newPath);
        $fetch->execute();
        $result = $fetch->get_result();
        $fileData = $result->fetch_assoc();
        $fetch->close();

        if ($fileData) {
            $insert = $conn->prepare("INSERT INTO trashfiles (file_id, deleted_at, deleted_by, reason) VALUES (?, NOW(), ?, 'Bulk move to trash')");
            $insert->bind_param("ii", $fileData['id'], $employee_id);
            $insert->execute();
            $insert->close();
            $moved++;
        } else {
            $errors[] = "$filename: Trash record insert failed.";
        }
    }

    echo json_encode([
        'status' => 'success',
        'message' => "$moved file(s) moved to trash successfully.",
        'successCount' => $moved,
        'errors' => $errors
    ]);
    exit;
}

// 🔴 BULK DOWNLOAD
if ($action === 'download') {
    $added = 0;
    $validFiles = [];
    $zip = new ZipArchive();
    $zipName = 'bulk_download_' . time() . '.zip';
    $zipPath = __DIR__ . '/downloads/' . $zipName;

    if (!file_exists(__DIR__ . '/downloads')) {
        mkdir(__DIR__ . '/downloads', 0777, true);
    }

    foreach ($selectedFiles as $file) {
        $filepath = $file['filepath'] ?? '';
        $filename = $file['filename'] ?? basename($filepath) ?: 'Unknown';

        if (!file_exists($filepath)) {
            $errors[] = "$filename: File missing from disk.";
            continue;
        }

        // Check if the file is marked as deleted
        $stmt = $conn->prepare("SELECT is_deleted FROM files WHERE filepath = ? LIMIT 1");
        $stmt->bind_param("s", $filepath);
        $stmt->execute();
        $stmt->bind_result($isDeleted);
        $stmt->fetch();
        $stmt->close();

        if ($isDeleted === 1) {
            $errors[] = "$filename: File is marked as deleted.";
            continue;
        }

        $validFiles[] = [
            'path' => $filepath,
            'name' => basename($filepath)
        ];
    }

    if (count($validFiles) === 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'No valid files found for download.',
            'errors' => $errors
        ]);
        exit;
    }

    if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
        foreach ($validFiles as $file) {
            $zip->addFile($file['path'], $file['name']);

            // Log the download
            $stmt = $conn->prepare("UPDATE files SET last_action = 'downloaded', last_action_at = NOW(), employee_id = ? WHERE filepath = ?");
            $stmt->bind_param("is", $employee_id, $file['path']);
            $stmt->execute();
            $stmt->close();

            $added++;
        }
        $zip->close();
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to create ZIP archive.'
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'message' => "$added file(s) downloaded successfully.",
        'successCount' => $added,
        'downloadUrl' => 'downloads/' . $zipName,
        'errors' => $errors
    ]);
    exit;
}

// 🔴 BULK RESTORE
if ($action === 'restore') {
    $restored = 0;

    foreach ($selectedFiles as $file) {
        $trashPath = $file['filepath'] ?? '';
        $filename = basename($trashPath) ?: 'Unknown';

        $stmt = $conn->prepare("
            SELECT tf.*, f.*
            FROM trashfiles tf
            LEFT JOIN files f ON tf.file_id = f.id
            WHERE f.filename = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $filename);
        $stmt->execute();
        $result = $stmt->get_result();
        $trash = $result->fetch_assoc();
        $stmt->close();

        if (!$trash) {
            $errors[] = "$filename: No metadata found.";
            continue;
        }

        $originalPath = $trash['filepath'];

        if (!file_exists($trashPath)) {
            $errors[] = "$filename: File not found in trash.";
            continue;
        }

        if (!is_dir(dirname($originalPath))) {
            mkdir(dirname($originalPath), 0777, true);
        }

        if (!rename($trashPath, $originalPath)) {
            $errors[] = "$filename: Restore failed.";
            continue;
        }

        $insert = $conn->prepare("
            INSERT INTO files (
                filename, filepath, filetype, size, dateupload, datecreated,
                filehash, content_hash, is_deleted, employee_id, last_action, last_action_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'restored', NOW())
        ");
        $insert->bind_param(
            "ssssssssi",
            $trash['filename'],
            $trash['filepath'],
            $trash['filetype'],
            $trash['size'],
            $trash['dateupload'],
            $trash['datecreated'],
            $trash['filehash'],
            $trash['content_hash'],
            $employee_id
        );
        $insert->execute();
        $insert->close();

        $delete = $conn->prepare("DELETE FROM trashfiles WHERE file_id = ?");
        $delete->bind_param("i", $trash['file_id']);
        $delete->execute();
        $delete->close();

        $restored++;
    }

    echo json_encode([
        'status' => $restored > 0 ? 'success' : 'error',
        'message' => "$restored file(s) restored successfully.",
        'successCount' => $restored,
        'errors' => $errors
    ]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unhandled action.']);
?>
