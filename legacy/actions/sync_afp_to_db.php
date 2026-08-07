<?php
require '../config.php';

function syncWithDatabase($baseDirectory) {
    $conn = getDatabaseConnection();
    $filesInServer = [];

    // Scan AFP server
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDirectory));
    foreach ($iterator as $file) {
        if (!$file->isDir()) {
            $filesInServer[] = $file->getPathname();
        }
    }

    // Fetch database entries
    $dbFilePaths = [];
    $result = $conn->query("SELECT filepath FROM files");
    while ($row = $result->fetch_assoc()) {
        $dbFilePaths[] = $row['filepath'];
    }

    // Add missing files
    foreach (array_diff($filesInServer, $dbFilePaths) as $newFile) {
        $filename = basename($newFile);
        $filesize = filesize($newFile);
        $filetype = mime_content_type($newFile);
        $stmt = $conn->prepare("INSERT INTO files (filename, filepath, filetype, size, dateupload) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssi", $filename, $newFile, $filetype, $filesize);
        $stmt->execute();
    }

    // Remove deleted files
    foreach (array_diff($dbFilePaths, $filesInServer) as $oldFile) {
        $stmt = $conn->prepare("DELETE FROM files WHERE filepath = ?");
        $stmt->bind_param("s", $oldFile);
        $stmt->execute();
    }

    $conn->close();
}

syncWithDatabase('/Volumes/creative/categorizesample');
?>
