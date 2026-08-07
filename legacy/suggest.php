<?php
// suggest.php
include 'db.php'; // Your DB connection file

$search = $_GET['search'] ?? '';

if (!empty($search)) {
    $query = "SELECT DISTINCT tag FROM your_table WHERE tag LIKE ?";
    $stmt = $conn->prepare($query);
    $searchParam = "%" . $search . "%";
    $stmt->bind_param("s", $searchParam);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        echo "<div class='suggested-item'>" . htmlspecialchars($row['tag']) . "</div>";
    }
}
?>
