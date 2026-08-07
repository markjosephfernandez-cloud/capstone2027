<?php
require 'config.php'; // ✅ Ensure database connection works

// ✅ Function to convert stored file paths to local URLs
function convertFilePathToURL($filePath) {
    $baseDirectory = '/Applications/XAMPP/xamppfiles/htdocs/testcreative'; // ✅ Adjusted for XAMPP
    $baseURL = 'http://localhost/testcreative'; // ✅ Local Apache server

    // ✅ Fix: Check if file path is already a URL, if so, return as is
    if (filter_var($filePath, FILTER_VALIDATE_URL)) {
        return $filePath;
    }

    // ✅ Fix: Ensure the file path matches the base directory
    if (strpos($filePath, $baseDirectory) === 0) {
        $relativePath = substr($filePath, strlen($baseDirectory));
        return $baseURL . '/' . ltrim($relativePath, '/');
    }

    return $filePath; // ✅ Return as-is if outside base directory
}

// ✅ Set JSON response headers
header('Content-Type: application/json');

try {
    // ✅ Fix: Ensure we fetch only recently scanned files with correct paths
    $query = "
        SELECT id, filename, filetype, filepath, dateupload 
        FROM files 
        WHERE last_scanned IS NOT NULL 
          AND (is_deleted IS NULL OR is_deleted = 0)
        ORDER BY last_scanned DESC, dateupload DESC 
        LIMIT 20
    ";


    $result = $conn->query($query);

    if (!$result) {
        throw new Exception("Database query failed: " . $conn->error);
    }

    $files = $result->fetch_all(MYSQLI_ASSOC); // ✅ Fetch as associative array

    $results = [];
    foreach ($files as $file) {
        // ✅ Fix: Convert stored paths to correct URLs
        $fileUrl = convertFilePathToURL($file['filepath']);

        // ✅ Fix: Ensure the file actually exists before returning it
        if (!file_exists($file['filepath'])) {
            continue; // Skip files that do not exist
        }

        $results[] = [
            'id' => $file['id'],
            'filename' => htmlspecialchars($file['filename']),
            'filetype' => htmlspecialchars($file['filetype']),
            'filepath' => htmlspecialchars($file['filepath']), // ✅ Keep original local path
            'fileurl' => $fileUrl, // ✅ Corrected URL path
            'dateupload' => htmlspecialchars($file['dateupload'])
        ];
    }

    echo json_encode(['status' => 'success', 'files' => $results], JSON_PRETTY_PRINT); // ✅ Return JSON
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); // ✅ Error handling
}
?>
