<?php
require 'config.php'; // Include database connection
require 'login-check.php'; // Include authentication logic

// Capture POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!empty($data['filepath']) && !empty($data['item_name']) && !empty($data['item_type'])) {
    $filepath = $data['filepath'];
    $item_name = $data['item_name'];
    $item_type = $data['item_type'];
    $employee_id = $_SESSION['employee_id'] ?? 'guest'; // Default to 'guest' if no session is found
    $timestamp = date("Y-m-d H:i:s");

    // Insert into the recent table or update the timestamp if it already exists
    $query = "
        INSERT INTO recent (employee_id, item_type, item_name, filepath, timestamp)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE timestamp = VALUES(timestamp)
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sssss", $employee_id, $item_type, $item_name, $filepath, $timestamp);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }

    $stmt->close();
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}
?>
