<?php
// Database connection parameters
$servername = "127.0.0.1"; // or your server name
$username = "root"; // your database username
$password = ""; // your database password
$dbname = "last_fm"; // your database name

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
    