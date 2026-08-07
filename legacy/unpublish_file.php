<?php
require 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = [];
    $publishId = $_POST['publish_id'] ?? null;

    if (!$publishId) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input: missing publish_id.']);
        exit();
    }

    try {
        $stmt = $conn->prepare("DELETE FROM publish WHERE p_id = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare SQL statement: " . $conn->error);
        }

        $stmt->bind_param("i", $publishId);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            echo json_encode(['status' => 'success', 'message' => 'File unpublished successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to unpublish the file. File not found.']);
        }

        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Unexpected error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}

$conn->close();
?>
