<?php
require_once "config.php";

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $query = $conn->prepare("
        SELECT DISTINCT id, filename, filepath, filetype, datecreated, emotion
        FROM files
        WHERE 
        emotion IS NOT NULL 
        AND emotion != ''
        AND duplicate_of IS NULL
        AND (is_deleted IS NULL OR is_deleted = 0)
        ORDER BY datecreated DESC
    ");
    
    $query->execute();
    $files = $query->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode(["success" => true, "files" => $files]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
