<?php
require '../includes/helpers.php';

$base_directory = '/Volumes/creative/categorizesample';

header('Content-Type: application/json');
echo json_encode(listDirectories($base_directory));


?>