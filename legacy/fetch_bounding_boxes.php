<?php
require 'config.php';

header('Content-Type: application/json');

// Validate input
if (!isset($_POST['file_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing file ID.']);
    exit;
}

$file_id = intval($_POST['file_id']);

try {
    $stmt = $pdo->prepare("SELECT bounding_boxes FROM files WHERE id = ?");
    $stmt->execute([$file_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result || empty($result['bounding_boxes'])) {
        echo json_encode(['status' => 'error', 'message' => 'No bounding boxes found.']);
        exit;
    }

    // Decode the JSON from the database
    $bounding_boxes = json_decode($result['bounding_boxes'], true);

    // If JSON is a string, decode it again (fixes double-encoding)
    if (is_string($bounding_boxes)) {
        $bounding_boxes = json_decode($bounding_boxes, true);
    }

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON format in database.']);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'bounding_boxes' => $bounding_boxes
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
