<?php
$base_directory = '/Volumes/creative/categorizesample';
$current_directory = isset($_GET['dir']) ? urldecode($_GET['dir']) : $base_directory;

function convertFilePathToURL($filePath) {
    $baseDirectory = '/Volumes';
    $baseURL = 'http://172.16.152.45:8000';
    $relative_path = str_replace($baseDirectory, $baseURL, $filePath);
    return str_replace(' ', '%20', $relative_path);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postData = json_decode(file_get_contents('php://input'), true);
    $selectedTypes = $postData['types'] ?? [];
    $items = scandir($current_directory);
    $filteredItems = [];

    foreach ($items as $item) {
        $item_path = $current_directory . '/' . $item;
        if (is_file($item_path)) {
            $mimeType = mime_content_type($item_path);
            if (in_array($mimeType, $selectedTypes)) {
                $filteredItems[] = [
                    'filename' => $item,
                    'filetype' => $mimeType,
                    'filepath' => convertFilePathToURL($item_path),
                ];
            }
        }
    }

    echo json_encode(['status' => 'success', 'files' => $filteredItems]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
exit;
?>
