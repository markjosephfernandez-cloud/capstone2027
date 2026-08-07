<?php
require '../config.php';
require __DIR__ . '/helpers.php';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $filepath = $_POST['filepath'] ?? null;
    $newPath = $_POST['newPath'] ?? null;

    if (!$filepath || !$newPath) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input.']);
        exit();
    }

    $destinationPath = $newPath . '/' . basename($filepath);

    if (rename($filepath, $destinationPath)) {
        $conn = getDatabaseConnection();
        $stmt = $conn->prepare("UPDATE files SET filepath = ? WHERE filepath = ?");
        $stmt->bind_param("ss", $destinationPath, $filepath);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'success', 'message' => 'File/folder moved successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to move file/folder.']);
    }
}

?>