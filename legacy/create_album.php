<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

include('config.php'); // Ensure your config file is set up correctly

// Check if the data from the AJAX call is available
if (isset($_POST['name']) && isset($_POST['description'])) {
    $name = $_POST['name'];
    $description = $_POST['description'];
    $created_at = date('Y-m-d H:i:s');  // Current date and time

    // Check if an album with the same name already exists
    $checkQuery = "SELECT * FROM albums WHERE name = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("s", $name);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    if ($result->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Album already exists.']);
    } else {
        // SQL query to insert the album
        $sql = "INSERT INTO albums (name, description, created_at) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $name, $description, $created_at);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Album created successfully.', 'album_id' => $conn->insert_id]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to create album: ' . $stmt->error]);
        }
        $stmt->close();
    }
    $checkStmt->close();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Incomplete data received.']);
}

$conn->close();
?>
