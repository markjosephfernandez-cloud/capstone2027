<?php
session_start();
header('Content-Type: application/json');

// Path to the lock file
$lockFile = __DIR__ . '/scan_running.lock';

// ✅ Check if the lock file exists
if (file_exists($lockFile)) {
    $processId = trim(file_get_contents($lockFile)); // ✅ Read the stored PID

    if ($processId && is_numeric($processId)) {
        // ✅ Kill the process
        shell_exec("kill -9 $processId");

        // ✅ Remove the lock file
        unlink($lockFile);
        $_SESSION['scan_running'] = false;

        echo json_encode(['status' => 'success', 'message' => 'Sync stopped successfully.']);
        exit;
    } else {
        // ✅ If no valid PID found, remove the lock file
        unlink($lockFile);
        $_SESSION['scan_running'] = false;

        echo json_encode(['status' => 'error', 'message' => 'No valid process found.']);
        exit;
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'No sync is currently running.']);
    exit;
}
?>
