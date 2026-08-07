<?php
require_once 'config.php';

// Set the base directory to scan

//$base_directory = '/Volumes/creative/Hara All About/HARA sa FU 2025/Photoshoot';
//$base_directory = '/Users/capstone2024-2025/Downloads/testscan';
//$base_directory = '/Applications/XAMPP/xamppfiles/htdocs/testcreative/categorizesample';

$base_directory = '/Applications/XAMPP/xamppfiles/htdocs/testcreative/ALBUMS';



// Function to recursively scan the directory
function scanDirectory($directory, $conn) {
    $items = scandir($directory);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue; // Skip current and parent directory
        }

        $item_path = rtrim($directory, '/') . '/' . $item;
        $is_dir = is_dir($item_path); // Check if it's a folder
        $item_type = $is_dir ? 'folder' : mime_content_type($item_path); // Filetype for files
        $item_size = !$is_dir ? filesize($item_path) : 0; // Size for files, 0 for folders

        // Insert into the database
        insertIntoDatabase($item, $item_type, $item_path, $item_size, $conn);

        // If it's a folder, recursively scan it
        if ($is_dir) {
            scanDirectory($item_path, $conn);
        }
    }
}

// Function to insert file/folder details into the database
function insertIntoDatabase($filename, $filetype, $filepath, $size, $conn) {
    $dateupload = date('Y-m-d H:i:s'); // Current timestamp

    $stmt = $conn->prepare("INSERT INTO files (filename, filetype, filepath, size, dateupload) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sssis", $filename, $filetype, $filepath, $size, $dateupload);
        if (!$stmt->execute()) {
            echo "Failed to insert: $filename. Error: " . $stmt->error . "\n";
        } else {
            echo "Inserted: $filename ($filetype, Size: $size bytes, Uploaded: $dateupload) - $filepath\n";
        }
        $stmt->close();
    } else {
        echo "Failed to prepare statement for: $filename. SQL Error: " . $conn->error . "\n";
    }
}

// Start scanning the base directory
if (is_dir($base_directory)) {
    echo "Scanning directory: $base_directory\n";
    scanDirectory($base_directory, $conn);
    echo "Scan completed!\n";
} else {
    echo "The specified directory does not exist: $base_directory\n";
}
?>
