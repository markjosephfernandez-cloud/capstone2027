<?php
require "config.php";  // Assuming config.php handles the DB connection

// Check if a file is uploaded
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '/path/to/upload/directory/'; // Replace with actual path
        $fileTmpPath = $_FILES['file']['tmp_name'];
        $fileName = $_FILES['file']['name'];
        $fileSize = $_FILES['file']['size'];
        $fileType = $_FILES['file']['type'];

        // Sanitize file name and set destination
        $newFileName = time() . "_" . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $fileName);
        $destPath = $uploadDir . $newFileName;

        // Move the uploaded file
        if (move_uploaded_file($fileTmpPath, $destPath)) {
            // Collect metadata
            $title = htmlspecialchars($_POST['title']);
            $description = htmlspecialchars($_POST['description']);
            $playlist = htmlspecialchars($_POST['playlist']);

            // Insert metadata into the database
            $sql = "INSERT INTO media_files (filename, filepath, filetype, size, title, description, playlist) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssss", $newFileName, $destPath, $fileType, $fileSize, $title, $description, $playlist);

            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'File uploaded successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to insert file info into database']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'File upload failed']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or there was an upload error']);
    }
}
?>
