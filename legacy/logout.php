<?php
session_start();
require 'config.php';

$employee_id = $_SESSION['employee_id'] ?? null;

if ($employee_id) {
    // ✅ Insert logout audit log
    $action = 'logout'; // must match enum values
    $details = "User $employee_id logged out.";
    $timestamp = date("Y-m-d H:i:s");

    $stmt = $conn->prepare("INSERT INTO activity (user_id, action, details, timestamp) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $employee_id, $action, $details, $timestamp);
    $stmt->execute();
   
}

// ✅ Clear session
$_SESSION = [];
session_destroy();

// ✅ Redirect to login
header("Location: login.php");
exit();
?>
