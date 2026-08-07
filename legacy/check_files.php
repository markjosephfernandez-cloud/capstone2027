<?php
require "config.php";
header('Content-Type: application/json');

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8", $username, $password);
    $query = $pdo->query("SELECT COUNT(*) AS count FROM files");
    $result = $query->fetch(PDO::FETCH_ASSOC);

    echo json_encode(["files_exist" => ($result['count'] > 0)]);
} catch (PDOException $e) {
    echo json_encode(["error" => "Database error: " . $e->getMessage()]);
}
?>
