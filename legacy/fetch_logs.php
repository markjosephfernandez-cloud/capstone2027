<?php
session_start();
header('Content-Type: application/json');

// Path to the log file
$logFile = __DIR__ . '/scan_progress.log';

// Check if log file exists
if (file_exists($logFile)) {
    try {
        $logs = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        echo json_encode(['status' => 'success', 'logs' => $logs]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error reading logs']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'No log file found']);
}
exit;
?>