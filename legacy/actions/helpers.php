<?php

// Get database connection
function getDatabaseConnection() {
    require '../config.php';
    return $conn;
}

// Recursively list directories
function listDirectories($base_directory) {
    $directories = [];
    $items = array_diff(scandir($base_directory), ['.', '..']);
    foreach ($items as $item) {
        $path = $base_directory . '/' . $item;
        if (is_dir($path)) {
            $directories[] = ['name' => $item, 'path' => realpath($path)];
            $directories = array_merge($directories, listDirectories($path)); // Recursively list subdirectories
        }
    }
    return $directories;
}

// Recursive function to delete directories
function deleteDirectory($dir) {
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = "$dir/$item";
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    return rmdir($dir);
}
?>