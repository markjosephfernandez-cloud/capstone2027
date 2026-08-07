<?php
require "config.php"; // Include database configuration

header('Content-Type: application/json');

try {
    // Check if ID is provided
    if (isset($_GET['id'])) {
        $id = intval($_GET['id']);

        // Prepare and execute the query to fetch media details
        $sql = "SELECT * FROM files WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $data = $result->fetch_assoc();
                echo json_encode($data); // Correctly encode and echo the data
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Media not found']);
            }

            $stmt->close();
        } else {
            throw new Exception('Failed to prepare statement');
        }
    } else {
        throw new Exception('No ID provided');
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}


?>
