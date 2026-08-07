<?php
require_once 'config.php'; // Include database connection

header('Content-Type: application/json');

// Decode incoming JSON data
$data = json_decode(file_get_contents('php://input'), true);

// Check if file paths are provided
if (isset($data['filepaths']) && is_array($data['filepaths'])) {
    $errors = [];

    foreach ($data['filepaths'] as $filePath) {
        // Use a prepared statement to delete the file record from the database
        $stmt = $conn->prepare("DELETE FROM publish WHERE filepath = ?");
        $stmt->bind_param("s", $filePath);

        if (!$stmt->execute()) {
            $errors[] = "Failed to delete file record: " . $filePath;
        }
    }

    if (empty($errors)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => implode(", ", $errors)]);
    }

    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid file paths.']);
}

$conn->close();
?>
