<?php
require 'config.php';
header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['filepath'])) {
    $filepath = trim($_POST['filepath']);
    $success = true;
    $errors = [];

    // Delete file from disk
    if (file_exists($filepath)) {
        if (!unlink($filepath)) {
            $errors[] = "Failed to delete file from disk.";
            error_log("❌ Failed to unlink: $filepath");
            $success = false;
        }
    } else {
        $errors[] = "File not found on disk.";
        error_log("⚠️ File not found on disk: $filepath");
    }

    // Get file ID
    $stmt = $conn->prepare("SELECT id FROM files WHERE filepath = ? LIMIT 1");
    $stmt->bind_param("s", $filepath);
    $stmt->execute();
    $result = $stmt->get_result();
    $file = $result->fetch_assoc();
    $stmt->close();

    if ($file) {
        $file_id = $file['id'];

        // Delete from trashfiles
        $delTrash = $conn->prepare("DELETE FROM trashfiles WHERE file_id = ?");
        $delTrash->bind_param("i", $file_id);
        $delTrash->execute();
        $delTrash->close();

        // Update file record
        $update = $conn->prepare("UPDATE files SET is_deleted = 1, last_action = 'deleted', last_action_at = NOW() WHERE id = ?");
        $update->bind_param("i", $file_id);
        $update->execute();
        $update->close();
    } else {
        $errors[] = "File record not found.";
        $success = false;
    }

    echo json_encode([
        "status" => $success ? "success" : "error",
        "message" => $success ? "File permanently deleted." : "Failed to delete file.",
        "errors" => $errors
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid request."
    ]);
}
?>
