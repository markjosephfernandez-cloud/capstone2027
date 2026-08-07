<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$logFile = __DIR__ . '/scan_progress.log';
$lockFile = __DIR__ . '/scan_running.lock';

// 🔴 **Stop polling if no scan is running (lock file missing)**
if (!file_exists($lockFile)) {
    echo json_encode(['status' => 'not_running', 'progress' => 100]); // Ensure bar is filled
    exit;
}

// ✅ **Ensure We Get the Latest Log Content**
clearstatcache();
$progress = 0;

// 🔴 **Handle Empty or Missing `scan_progress.log`**
if (!file_exists($logFile) || filesize($logFile) === 0) {
    echo json_encode(['status' => 'waiting', 'progress' => 0]); // Return waiting status
    exit;
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if ($lines) {
    foreach (array_reverse($lines) as $line) { // Read in reverse order
        if (preg_match('/Progress: (\d+(\.\d+)?)%/', $line, $matches)) {
            $progress = floatval($matches[1]);
            break;
        }
    }
}

// 🔴 **Ensure Immediate Completion**
if ($progress >= 100 || strpos(end($lines), 'Database connection closed.') !== false) {
    $_SESSION['scan_running'] = false;
    
    // ✅ **Check for database connection and close if open**
    if (isset($db) && $db instanceof mysqli) {
        $db->close();
    }

    // ✅ **Ensure log contains completion status**
    file_put_contents($logFile, "Scan completed successfully.\n", FILE_APPEND);

    unlink($lockFile); // ✅ Remove lock file to stop looping
    echo json_encode(['status' => 'completed', 'progress' => 100]); // ✅ Ensure progress bar fills to 100%
} else {
    echo json_encode(['status' => 'running', 'progress' => $progress]);
}
exit;
?>
