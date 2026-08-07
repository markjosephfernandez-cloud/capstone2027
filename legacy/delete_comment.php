<?php
require 'config.php';

$comment_id = $_POST['comment_id'];
$query = "DELETE FROM comments WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $comment_id);

if ($stmt->execute()) {
    echo json_encode(['message' => 'Comment deleted successfully.']);
} else {
    echo json_encode(['message' => 'Failed to delete comment.']);
}
?>
