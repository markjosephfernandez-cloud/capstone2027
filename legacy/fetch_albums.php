<?php
// Include database connection
require 'config.php';

$albumQuery = "SELECT id, name FROM albums ORDER BY name ASC";
$albumResult = $conn->query($albumQuery);

$albums = [];

if ($albumResult->num_rows > 0) {
    while ($album = $albumResult->fetch_assoc()) {
        $albums[] = $album;
    }
}

// Debugging: Log fetched albums to check if IDs are retrieved correctly
error_log("Fetched albums: " . json_encode($albums));

// Return JSON response
header('Content-Type: application/json');
echo json_encode($albums);
?>
