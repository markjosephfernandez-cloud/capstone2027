<?php
session_start();

// Check if the user is logged in
if (!isset($_SESSION["logged"]) || $_SESSION["logged"] !== true) {
    // Redirect to login.php
    header("Location: login.php");
    exit();
}
?>
