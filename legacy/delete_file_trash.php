<?php
require 'config.php';
header('Content-Type: text/plain');
ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['filepath'])) {
    $filepath = trim($_POST['filepath']);
    $success = true;

    // Step 1: Get file ID
    $stmt = $conn->prepare("
        SELECT tf.file_id
        FROM trashfiles tf
        LEFT JOIN files f ON tf.file_id = f.id
        WHERE f.filepath = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $filepath);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        error_log("❌ No trash record for file: $filepath");
        echo "error";
        exit;
    }

    $file_id = $row['file_id'];

    // Step 2: Delete from disk
    if (file_exists($filepath)) {
        if (!unlink($filepath)) {
            error_log("❌ Failed to unlink: $filepath");
            echo "error";
            exit;
        }
    }

    // Step 3: Delete from trashfiles
    $delTrash = $conn->prepare("DELETE FROM trashfiles WHERE file_id = ?");
    $delTrash->bind_param("i", $file_id);
    if (!$delTrash->execute()) {
        error_log("❌ Trash delete failed: " . $delTrash->error);
        echo "error";
        exit;
    }
    $delTrash->close();

    // Step 4: Mark as deleted in files
    $update = $conn->prepare("UPDATE files SET is_deleted = 1, last_action = 'deleted', last_action_at = NOW() WHERE id = ?");
    $update->bind_param("i", $file_id);
    if (!$update->execute()) {
        error_log("❌ File update failed: " . $update->error);
        echo "error";
        exit;
    }
    $update->close();

    echo "success";
} else {
    echo "error";
}
?>
