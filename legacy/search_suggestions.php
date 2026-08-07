<?php
require "config.php"; // Include your database configuration

if (isset($_GET['query'])) {
    $searchQuery = trim($_GET['query']);
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8", $username, $password);

    try {
        $stmt = $pdo->prepare("
            SELECT search_term 
            FROM search_logs 
            WHERE search_term LIKE :query 
            GROUP BY search_term 
            ORDER BY MAX(last_searched) DESC, SUM(search_count) DESC 
            LIMIT 10
        ");
        $stmt->bindValue(':query', '%' . $searchQuery . '%', PDO::PARAM_STR);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($results);
    } catch (PDOException $e) {
        echo json_encode([]);
    }
}
?>
