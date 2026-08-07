<?php
require 'config.php'; // Ensure MySQLi connection

header('Content-Type: application/json');

try {
    // Query to fetch detected objects
    $query = "SELECT filename, filetype, filepath, datecreated, detected_objects FROM files WHERE detected_objects IS NOT NULL   AND (is_deleted IS NULL OR is_deleted = 0) ";
    $result = $conn->query($query);

    if (!$result) {
        throw new Exception("Query failed: " . $conn->error);
    }

    $files = [];
    while ($row = $result->fetch_assoc()) {
        // Process detected objects
        $objectsArray = array_map('trim', explode(',', $row['detected_objects']));
        $objectCounts = array_count_values($objectsArray);
        $formattedObjects = [];

        foreach ($objectCounts as $object => $count) {
            if (!empty($object) && strtolower($object) !== 'none' && strtolower($object) !== 'null') {
                $formattedObjects[] = "$object x$count";
            }
        }

        $countedDetectedObjects = !empty($formattedObjects) ? implode(', ', $formattedObjects) : 'No objects detected';

        $files[] = [
            "filename" => $row['filename'],
            "filetype" => $row['filetype'],
            "filepath" => $row['filepath'],
            "datecreated" => $row['datecreated'] ?? 'N/A',
            "detected_objects" => $countedDetectedObjects
        ];
    }

    echo json_encode(["success" => true, "files" => $files]);

} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>