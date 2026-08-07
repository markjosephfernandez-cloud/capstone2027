<?php
require 'config.php';

$published_id = $_GET['published_id'];
$query = "SELECT id, comment FROM comments WHERE published_file_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $published_id);
$stmt->execute();
$result = $stmt->get_result();
$comments = $result->fetch_all(MYSQLI_ASSOC);

echo json_encode($comments);
?>

