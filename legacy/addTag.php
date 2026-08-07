<?php
// Include the database configuration file
require_once 'config.php';

// Function to normalize filepaths (remove double slashes)
function normalizePath($path) {
    return preg_replace('#/+#', '/', $path);
}

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the raw input data
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (!isset($input['filepath'], $input['fileName'], $input['tag']) || empty($input['filepath']) || empty($input['tag']) || empty($input['fileName'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid input.']);
        exit();
    }

    // Sanitize and normalize inputs
    $filepath = $conn->real_escape_string(normalizePath($input['filepath']));
    $fileName = $conn->real_escape_string($input['fileName']);
    $tag = $conn->real_escape_string($input['tag']);

    // Check if the file already exists in the 'files' table
    $checkSql = "SELECT * FROM files WHERE filename = '$fileName'";
    $checkResult = $conn->query($checkSql);

    if ($checkResult->num_rows > 0) {
        // File exists, update the tag
        $updateSql = "UPDATE files SET tag = '$tag' WHERE filename = '$fileName'";
        if ($conn->query($updateSql) === TRUE) {
            echo json_encode(['status' => 'success', 'message' => 'Tag updated successfully.']);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to update tag: ' . $conn->error]);
        }
    } else {
        // File does not exist, insert a new file entry with tag
        $insertSql = "INSERT INTO files (filename, filepath, tag) VALUES ('$fileName', '$filepath', '$tag')";
        if ($conn->query($insertSql) === TRUE) {
            echo json_encode(['status' => 'success', 'message' => 'File and tag added successfully.']);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to add file and tag: ' . $conn->error]);
        }
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
?>
