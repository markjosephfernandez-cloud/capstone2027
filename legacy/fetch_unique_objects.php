<?php
require "config.php";

header("Content-Type: application/json");

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ✅ Fetch unique emotions
    $stmt = $pdo->query("SELECT DISTINCT emotion FROM files WHERE emotion IS NOT NULL AND emotion <> ''");
    $emotions = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // ✅ Fetch unique detected objects
    $stmt = $pdo->query("SELECT DISTINCT detected_objects FROM files WHERE detected_objects IS NOT NULL AND detected_objects <> ''");
    $objects = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        "success" => true,
        "emotions" => $emotions,
        "objects" => $objects
    ]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
