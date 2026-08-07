<?php
require_once 'config.php';
session_start();

// ✅ Clean output buffer to prevent corruption
if (ob_get_level()) {
    ob_end_clean();
}
ini_set('display_errors', 0); // Avoid corrupting binary stream with PHP warnings

// ✅ Check for required file param
if (!isset($_GET['file'])) {
    http_response_code(400);
    echo "No file specified.";
    exit;
}

$adminId = $_SESSION['employee_id'] ?? null;
if (!$adminId) {
    http_response_code(403);
    echo "Unauthorized access.";
    exit;
}

// ✅ Adjust file path from URL to real server path
function adjustFilePath($filePath) {
    if (strpos($filePath, "http://localhost/testcreative/") !== false) {
        return str_replace("http://localhost/testcreative/", "/Applications/XAMPP/xamppfiles/htdocs/testcreative/", $filePath);
    }
    return $filePath;
}

$filePath = adjustFilePath(urldecode($_GET['file']));

// ✅ Ensure file exists
if (!file_exists($filePath)) {
    http_response_code(404);
    echo "File not found.";
    exit;
}

// ✅ Block deleted files
$stmt = $conn->prepare("SELECT is_deleted FROM files WHERE filepath = ? LIMIT 1");
$stmt->bind_param("s", $filePath);
$stmt->execute();
$stmt->bind_result($is_deleted);
$stmt->fetch();
$stmt->close();

if ($is_deleted === 1) {
    http_response_code(403);
    echo "Access denied. File is marked as deleted.";
    exit;
}

// ✅ Log download to DB
$stmt = $conn->prepare("
    UPDATE files 
    SET last_action = 'downloaded', 
        last_action_at = NOW(), 
        employee_id = ?
    WHERE filepath = ?
");
$stmt->bind_param("is", $adminId, $filePath);
$stmt->execute();
$stmt->close();

// ✅ Serve the file correctly
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));
flush(); // ✅ Flush buffer to prevent header/output overlap
readfile($filePath);
exit;
?>
