<?php
require '../config.php';

header('Content-Type: application/json');

$base_directory = '/Volumes/creative/categorizesample';

$query = "SELECT filename, filepath, filetype, size, owner FROM files ORDER BY dateupload DESC";
$result = $conn->query($query);

$files = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $files[] = [
            'filename' => $row['filename'],
            'filepath' => $row['filepath'],
            'filetype' => $row['filetype'],
            'owner' => $row['owner'] ?? 'Unknown',
            'size' => $row['size']
        ];
    }
}

echo json_encode(['files' => $files]);
?>
