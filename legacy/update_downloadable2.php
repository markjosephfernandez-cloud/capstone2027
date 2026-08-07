<?php
require 'config.php'; // Your DB config
header('Content-Type: application/json');

// 1) Get JSON input & decode
$json_str = file_get_contents('php://input');
$request  = json_decode($json_str, true);

// 2) Validate input
if (!isset($request['files']) || !isset($request['status'])) {
    echo json_encode(["status" => "error", "message" => "Invalid input: 'files' or 'status' missing."]);
    exit;
}

// "status" can be one of: "restrict", "downloadable", "view", "hide"
$status = $request['status'];
$files  = $request['files'];

if (empty($files)) {
    echo json_encode(["status" => "error", "message" => "No files selected."]);
    exit;
}

$updatedFiles = 0;

// 3) Loop all selected files and apply partial updates
foreach ($files as $file) {
    // Ensure we have a filename
    if (!isset($file['filename'])) {
        continue; // Skip invalid entries
    }

    $filename = $file['filename'];

    // Decide which column(s) to update based on $status
    switch ($status) {
        case 'restrict':
            // Only set downloadable=0
            $stmt = $conn->prepare("UPDATE album_files SET downloadable=0 WHERE filename=?");
            $stmt->bind_param("s", $filename);
            break;

        case 'downloadable':
            // Only set downloadable=1
            $stmt = $conn->prepare("UPDATE album_files SET downloadable=1 WHERE filename=?");
            $stmt->bind_param("s", $filename);
            break;

        case 'view':
            // Only set featured=1
            $stmt = $conn->prepare("UPDATE album_files SET featured=1 WHERE filename=?");
            $stmt->bind_param("s", $filename);
            break;

        case 'hide':
            // Only set featured=0
            $stmt = $conn->prepare("UPDATE album_files SET featured=0 WHERE filename=?");
            $stmt->bind_param("s", $filename);
            break;

        default:
            // Unrecognized status; skip or handle error
            continue 2; // skip this iteration
    }

    // Execute the prepared update
    if ($stmt->execute()) {
        $updatedFiles++;
    }
    $stmt->close();
}

// 4) Respond with success if at least 1 file was updated
if ($updatedFiles > 0) {
    echo json_encode(["status" => "success", "message" => "$updatedFiles file(s) updated."]);
} else {
    echo json_encode(["status" => "error", "message" => "Database update failed or no matching records."]);
}

$conn->close();
