<?php
require '../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filepath = $_GET['filepath'] ?? null;

    // Check if the file path is provided
    if (!$filepath) {
        http_response_code(400);
        echo "File path is required.";
        exit();
    }

    // Normalize and verify the file path
    $realBasePath = realpath('/Volumes/creative/categorizesample'); // Base directory for your files
    $realFilePath = realpath($filepath);

    // Ensure the file is within the allowed base directory
    if (strpos($realFilePath, $realBasePath) !== 0 || !file_exists($realFilePath)) {
        http_response_code(404);
        echo "File not found or access denied.";
        exit();
    }

    // Validate allowed file extensions
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'mov', 'pdf', 'doc', 'docx'];
    $fileExtension = strtolower(pathinfo($realFilePath, PATHINFO_EXTENSION));

    if (!in_array($fileExtension, $allowedExtensions)) {
        http_response_code(403);
        echo "Invalid file type.";
        exit();
    }

    // Secure the filename and serve the file
    $filename = basename($realFilePath);
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . filesize($realFilePath));

    // Output the file contents
    readfile($realFilePath);
    exit();
}
?>
