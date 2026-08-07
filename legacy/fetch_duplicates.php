<?php
require_once "config.php";

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // ✅ Fetch ALL files that are duplicates (have duplicate_of populated) as individual records
    // ✅ Optionally include originals that have duplicates (if needed, else fetch only duplicates)

    $query = $conn->prepare("
SELECT 
    d.id,
    d.filename,
    d.filepath,
    d.filetype,
    d.datecreated,
    d.filehash,
    d.content_hash,
    d.duplicate_of,
    o.filename AS original_filename
FROM files d
LEFT JOIN files o ON d.duplicate_of = o.id
WHERE 
    d.duplicate_of IS NOT NULL
    AND d.filepath IS NOT NULL
    AND d.filepath != ''
    AND (d.is_deleted IS NULL OR d.is_deleted = 0)
ORDER BY d.datecreated DESC;



    ");

    $query->execute();
    $result = $query->get_result();

    $files = [];
    while ($row = $result->fetch_assoc()) {
        $files[] = $row;
    }

    echo json_encode([
        "success" => true,
        "duplicates" => $files
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>
