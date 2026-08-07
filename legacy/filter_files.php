<?php
require 'config.php';

$fileType = $_POST['fileType'] ?? '';
$extensions = [];

if ($fileType === "Photos & Images") {
    $extensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'];
} elseif ($fileType === "Audio") {
    $extensions = ['mp3', 'wav', 'aac', 'flac', 'ogg'];
} elseif ($fileType === "Video") {
    $extensions = ['mp4', 'avi', 'mov', 'mkv', 'flv', 'wmv'];
}

$placeholders = implode(',', array_fill(0, count($extensions), '?'));
$stmt = $conn->prepare("SELECT * FROM files WHERE filepath LIKE CONCAT('%', ?, '%')");
$stmt->bind_param(str_repeat('s', count($extensions)), ...$extensions);
$stmt->execute();
$result = $stmt->get_result();

$files = $result->fetch_all(MYSQLI_ASSOC);
echo json_encode($files);
?>
