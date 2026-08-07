<?php
include 'config.php';

$response = ['status' => 'error', 'message' => 'Something went wrong'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $albumId = isset($_POST['albumId']) ? intval($_POST['albumId']) : null;
    $files = isset($_POST['files']) ? json_decode($_POST['files'], true) : [];

    if (!$albumId || empty($files)) {
        $response['message'] = "Please select an album and at least one file.";
    } else {
        $stmt = $conn->prepare("INSERT INTO album_files (album_id, filename, filepath, filetype, size, dateupload) 
                                SELECT ?, filename, filepath, filetype, size, NOW() FROM files WHERE filename = ?");
        
        foreach ($files as $file) {
            $stmt->bind_param("is", $albumId, $file);
            $stmt->execute();
        }
        $stmt->close();

        $response['status'] = 'success';
        $response['message'] = "Files added to album successfully.";
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>
