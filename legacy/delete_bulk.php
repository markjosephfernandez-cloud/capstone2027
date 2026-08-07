<?php
require 'config.php';
header('Content-Type: text/plain');
ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['files']) && is_array($_POST['files'])) {
    $files = $_POST['files'];
    $success = true;

    foreach ($files as $filepath) {
        $filepath = trim($filepath);

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
            error_log("❌ Trash record not found for $filepath");
            $success = false;
            continue;
        }

        $file_id = $row['file_id'];

        // Step 2: Delete from disk
        if (file_exists($filepath)) {
            if (!unlink($filepath)) {
                error_log("❌ Failed to delete: $filepath");
                $success = false;
                continue;
            }
        }

        // Step 3: Delete from trashfiles
        $delTrash = $conn->prepare("DELETE FROM trashfiles WHERE file_id = ?");
        $delTrash->bind_param("i", $file_id);
        if (!$delTrash->execute()) {
            error_log("❌ Failed to remove from trashfiles: " . $delTrash->error);
            $delTrash->close();
            $success = false;
            continue;
        }
        $delTrash->close();

        // Step 4: Mark as deleted in files
        $update = $conn->prepare("UPDATE files SET is_deleted = 1, last_action = 'deleted', last_action_at = NOW() WHERE id = ?");
        $update->bind_param("i", $file_id);
        if (!$update->execute()) {
            error_log("❌ Failed to update files table: " . $update->error);
            $update->close();
            $success = false;
            continue;
        }
        $update->close();
    }

    echo $success ? "success" : "error";
} else {
    echo "error";
}
?>
