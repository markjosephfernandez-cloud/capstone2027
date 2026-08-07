<?php
require 'config.php';
header('Content-Type: application/json');

try {
    // ✅ Only mark as scanned after processing is fully done
    $stmt = $conn->query("
        UPDATE files 
        SET last_scanned = NOW() 
        WHERE last_scanned IS NULL
    ");
    echo json_encode(["status" => "success", "message" => "New files marked as scanned"]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
}
?>
