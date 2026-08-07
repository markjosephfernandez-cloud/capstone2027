<?php
require_once 'config.php';

// Enable detailed error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
$response = ['status' => 'success', 'message' => '', 'errors' => []];

// Decode JSON input
$data = json_decode(file_get_contents('php://input'), true);
error_log(print_r($data, true)); // Log input for debugging

if (!isset($data['selectedItems']) || !is_array($data['selectedItems'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid data received.']);
    exit;
}

// Process each selected item
foreach ($data['selectedItems'] as $item) {
    $filename = $item['filename'] ?? null;
    $filetype = $item['filetype'] ?? null;
    $filepath = $item['filepath'] ?? null;

    // Validate required fields
    if (empty($filename) || empty($filetype) || empty($filepath)) {
        $response['errors'][] = "Missing data for item: " . print_r($item, true);
        continue;
    }

    // Insert into the database
    $stmt = $conn->prepare("INSERT INTO files (filename, filetype, filepath) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sss", $filename, $filetype, $filepath);
        if (!$stmt->execute()) {
            $response['errors'][] = "Failed to insert item: $filename. SQL Error: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $response['errors'][] = "Failed to prepare statement for item: $filename. SQL Error: " . $conn->error;
    }
}

// Finalize response
if (!empty($response['errors'])) {
    $response['status'] = 'error';
    $response['message'] = 'Some items failed to upload. Check errors for details.';
} else {
    $response['message'] = 'All selected files and folders were uploaded successfully.';
}

echo json_encode($response);
exit;
?>
