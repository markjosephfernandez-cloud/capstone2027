<?php
// Include the database configuration file
require_once 'config.php';

header('Content-Type: application/json');

// Check if the request is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve the input data
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate that filepath exists and is not empty
    if (isset($input['filepath']) && !empty($input['filepath'])) {
        $rawFilepath = $input['filepath'];
        $decodedFilepath = urldecode($rawFilepath); // Decode URL encoding first
        $escapedFilepath = $conn->real_escape_string($decodedFilepath); // Escape SQL characters

        $filename = basename($escapedFilepath);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Determine the file type based on the extension
        $type = '';
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
            $type = 'image';
        } elseif (in_array($extension, ['mp3', 'wav', 'aac'])) {
            $type = 'audio';
        } elseif (in_array($extension, ['mp4', 'avi', 'mkv', 'mov'])) {
            $type = 'video';
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Unsupported file type']);
            exit();
        }

        // Debugging Logs
        error_log("Raw File Path: " . $rawFilepath);
        error_log("Decoded File Path: " . $decodedFilepath);
        error_log("Escaped File Path: " . $escapedFilepath);

        // Insert into the 'publish' table
        $stmt = $conn->prepare("INSERT INTO publish (filename, type, filepath) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $filename, $type, $escapedFilepath);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'File published successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to publish file: ' . $stmt->error]);
        }

        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}

// Close database connection
$conn->close();
?>
