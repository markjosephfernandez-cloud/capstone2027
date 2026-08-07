<?php
require "config.php";
header("Content-Type: application/json");

// Debug: Log incoming request
file_put_contents("debug.log", "Request received: " . json_encode($_GET) . "\n", FILE_APPEND);

// Ensure database connection
if (!isset($conn) || $conn->connect_error) {
    echo json_encode(["success" => false, "message" => "Database connection failed."]);
    exit;
}

// Get the filter type from URL
$type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : '';

if ($type === 'objects') {
    // only from non-deleted files
    $query = "
      SELECT DISTINCT detected_objects
        FROM files f
       WHERE (f.is_deleted IS NULL OR f.is_deleted = 0)
         AND detected_objects IS NOT NULL
         AND detected_objects != ''
    ";
}
elseif ($type === 'emotions') {
    // only from non-deleted files
    $query = "
      SELECT DISTINCT emotion
        FROM files f
       WHERE (f.is_deleted IS NULL OR f.is_deleted = 0)
         AND emotion IS NOT NULL
         AND emotion != ''
    ";
}
else {
    echo json_encode([
      "success"       => false,
      "message"       => "Invalid request.",
      "received_type" => $type
    ]);
    exit;
}

// Run the query
$result = $conn->query($query);

if (!$result) {
    echo json_encode([
      "success" => false,
      "message" => "Query failed: " . $conn->error
    ]);
    exit;
}

// Process results
$uniqueFilters = [];
while ($row = $result->fetch_assoc()) {
    if ($type === 'objects' && isset($row['detected_objects'])) {
        $parts = explode(',', $row['detected_objects']);
        foreach ($parts as $object) {
            $object = trim($object);
            if ($object !== '' && strtolower($object) !== 'null' && !in_array($object, $uniqueFilters, true)) {
                $uniqueFilters[] = $object;
            }
        }
    }
    elseif ($type === 'emotions' && isset($row['emotion'])) {
        $emotion = trim($row['emotion']);
        if ($emotion !== '' && strtolower($emotion) !== 'null' && !in_array($emotion, $uniqueFilters, true)) {
            $uniqueFilters[] = $emotion;
        }
    }
}

// Close connection
$conn->close();

echo json_encode([
  "success" => true,
  "data"    => $uniqueFilters
]);
?>
