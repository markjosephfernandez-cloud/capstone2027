<?php
require 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedTags = json_decode(file_get_contents('php://input'), true)['tags'] ?? [];

    if (!empty($selectedTags)) {
        // Prepare the SQL query to filter files by selected tags
        $placeholders = implode(',', array_fill(0, count($selectedTags), '?'));
        $query = "SELECT filename, filepath, filetype, tag FROM files WHERE tag IN ($placeholders)";
        $stmt = $conn->prepare($query);

        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($selectedTags)), ...$selectedTags);
            $stmt->execute();
            $result = $stmt->get_result();

            $files = [];
            while ($row = $result->fetch_assoc()) {
                $files[] = $row;
            }

            echo json_encode([
                'status' => 'success',
                'files' => $files,
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Failed to prepare database query.',
            ]);
        }
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'No tags selected.',
        ]);
    }
}
?>
