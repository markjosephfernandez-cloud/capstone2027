<?php
include 'config.php';  // Ensure your database configuration is correct

$response = ['status' => 'error', 'message' => ''];

// Handle file upload when the form is submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file']['name']) && !empty($_FILES['file']['name']) && isset($_POST['albumId'])) {
    $albumId = intval($_POST['albumId']);  // Convert album ID to an integer
    $description = $_POST['description'] ?? '';  // Optional description

    // Validate album ID
    $stmt = $conn->prepare("SELECT name FROM albums WHERE id = ?");
    $stmt->bind_param("i", $albumId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $album = $result->fetch_assoc();
        $albumTableName = "album_" . preg_replace('/[^A-Za-z0-9_]/', '', $album['name']); // Sanitize and format table name

        // Process each uploaded file
        $fileCount = count($_FILES['file']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            $fileName = $_FILES['file']['name'][$i];
            $fileTmpPath = $_FILES['file']['tmp_name'][$i];
            $fileType = $_FILES['file']['type'][$i];
            $fileSize = $_FILES['file']['size'][$i];
            $filePath = "uploads/" . basename($fileName); // Ensure your upload path is correct

            // Check if file was uploaded correctly
            if (is_uploaded_file($fileTmpPath)) {
                if (move_uploaded_file($fileTmpPath, $filePath)) {
                    // Insert into the general `files` table
                    $insertFile = $conn->prepare("INSERT INTO files (filename, filepath, filetype, size, description, album_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $insertFile->bind_param("sssisi", $fileName, $filePath, $fileType, $fileSize, $description, $albumId);
                    
                    if ($insertFile->execute()) {
                        // Insert file into the specific album table
                        $insertToAlbum = $conn->prepare("INSERT INTO `$albumTableName` (filename, filepath, filetype, album_id, upload_date) VALUES (?, ?, ?, ?, NOW())");
                        $insertToAlbum->bind_param("sssi", $fileName, $filePath, $fileType, $albumId);
                        
                        if (!$insertToAlbum->execute()) {
                            $response['message'] = 'Error inserting into album table: ' . $insertToAlbum->error;
                        } else {
                            $response = ['status' => 'success', 'message' => 'File uploaded and inserted successfully.'];
                        }
                        $insertToAlbum->close();
                    } else {
                        $response['message'] = 'Error inserting into files table: ' . $insertFile->error;
                    }
                    $insertFile->close();
                } else {
                    $response['message'] = 'Failed to move the uploaded file.';
                }
            } else {
                $response['message'] = 'File upload error. Code: ' . $_FILES['file']['error'][$i];
            }
        }
    } else {
        $response['message'] = 'Album not found.';
    }
    $stmt->close();
}

// Output JSON response for AJAX or debugging
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Files</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>

<div class="container mt-5">
    <h2>Upload a File</h2>

    <!-- File Upload Form -->
    <form action="" method="POST" enctype="multipart/form-data">
        
        <!-- Album Selection Dropdown -->
        <div class="mb-3">
            <label for="albumId" class="form-label">Select Album</label>
            <select class="form-control" id="albumId" name="albumId" required>
                <option value="">Select Album</option>
                <?php
                // Fetch all albums from database
                $albumQuery = "SELECT id, name FROM albums ORDER BY name ASC";
                $albumResult = $conn->query($albumQuery);
                if ($albumResult->num_rows > 0) {
                    while ($album = $albumResult->fetch_assoc()) {
                        echo '<option value="' . htmlspecialchars($album['id']) . '">' . htmlspecialchars($album['name']) . '</option>';
                    }
                } else {
                    echo '<option value="">No albums available</option>';
                }
                ?>
            </select>
        </div>

        <!-- File Upload Input -->
        <div class="mb-3">
            <label for="file" class="form-label">Choose File</label>
            <input type="file" class="form-control" id="file" name="file[]" multiple required>
        </div>

        <!-- Optional Description -->
        <div class="mb-3">
            <label for="description" class="form-label">Description</label>
            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="btn btn-primary">Upload</button>
    </form>

    <!-- Response Message -->
    <div id="uploadResponse" class="mt-3"></div>

</div>

<!-- JavaScript for Handling AJAX Response -->
<script>
    document.querySelector("form").addEventListener("submit", function(event) {
        event.preventDefault();
        
        let formData = new FormData(this);
        
        fetch("", {
            method: "POST",
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            let messageBox = document.getElementById("uploadResponse");
            if (data.status === "success") {
                messageBox.innerHTML = `<div class="alert alert-success">${data.message}</div>`;
            } else {
                messageBox.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
            }
        })
        .catch(error => console.error("Error:", error));
    });
</script>

</body>
</html>
