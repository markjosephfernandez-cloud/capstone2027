<?php
session_start();

// Set the base directory path
$base_directory = '/Volumes/creative/greyhoundhub';

// Retrieve the folder name and current directory from the POST request
$folderName = isset($_POST['folderName']) ? trim($_POST['folderName']) : '';
$currentDir = isset($_POST['currentDir']) ? $_POST['currentDir'] : $base_directory;

// Validate the folder name and ensure the directory is within the base path
if (!empty($folderName) && strpos(realpath($currentDir), realpath($base_directory)) === 0) {
    // Sanitize folder name to prevent invalid characters
    $folderName = preg_replace('/[^A-Za-z0-9 _-]/', '', $folderName);
    $newFolderPath = $currentDir . '/' . $folderName;

    // Attempt to create the folder if it doesn't already exist
    if (!is_dir($newFolderPath)) {
        if (mkdir($newFolderPath, 0777, true)) {
            $_SESSION['alert'] = "Folder '$folderName' created successfully!";

            // Database connection parameters
            $servername = "localhost";
            $username = "root";
            $password = "capstone2425";
            $dbname = "greyhoundhub";

            // Create a new database connection
            $conn = new mysqli($servername, $username, $password, $dbname);
            if ($conn->connect_error) {
                die("Connection failed: " . $conn->connect_error);
            }

            // Insert the folder details into the 'folders' table
            $size = 0; // Size is 0 for folders
            $dateCreated = date('Y-m-d H:i:s'); // Current date and time

            $stmt = $conn->prepare("INSERT INTO folders (filename, filepath, size, datecreated, description) VALUES (?, ?, ?, ?, ?)");
            if ($stmt === false) {
                die("Error preparing statement: " . $conn->error);
            }

            $description = ''; // You can modify this to add a description if provided
            $stmt->bind_param("ssiss", $folderName, $newFolderPath, $size, $dateCreated, $description);

            if ($stmt->execute()) {
                $_SESSION['alert'] = "Folder '$folderName' created and database entry added successfully!";
            } else {
                $_SESSION['alert'] = "Error adding folder to database: " . $stmt->error;
            }

            // Close the statement and connection
            $stmt->close();
       

        } else {
            $_SESSION['alert'] = "Error: Could not create folder.";
        }
    } else {
        $_SESSION['alert'] = "Error: Folder '$folderName' already exists.";
    }
} else {
    $_SESSION['alert'] = "Error: Invalid folder name or directory.";
}

// Redirect to the directory listing page
header("Location: directory-listing.php?dir=" . urlencode($currentDir));
exit;
?>
