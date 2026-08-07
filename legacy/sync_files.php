<?php
session_start();
// ✅ Sync starts
$_SESSION["scanCompleted"] = false;
header('Content-Type: application/json');

// ←–– you should already have your DB connection in here…
require_once 'config.php';  // ← adjust to your real include

set_time_limit(0); // Allow PHP to run indefinitely


$lockFile = __DIR__ . '/scan_running.lock';
$logFile  = __DIR__ . '/scan_progress.log';

// Cleanup stale lock file
if (file_exists($lockFile)) {
    $pid = file_get_contents($lockFile);
    if (!is_process_running($pid)) unlink($lockFile);
}

try {
    // Clear the previous progress log
    if (file_exists($logFile)) unlink($logFile);

    // 1) Start the background scan process
    $pid = start_background_process($logFile);
    file_put_contents($lockFile, $pid);

    // 2) **LOG** this action into your activity table
    //    (assumes you store the current user in $_SESSION['employee_id'])
    $details = "User {$_SESSION['employee_id']} initiated a scan.";
    $logStmt = $conn->prepare("
      INSERT INTO activity (user_id, action, details)
      VALUES (?, 'SCAN', ?)
    ");
    $logStmt->bind_param("is", $_SESSION['employee_id'], $details);
    $logStmt->execute();
    $logStmt->close();

    // 3) Tell the front-end we’re off to the races
    echo json_encode([
      'status'  => 'success',
      'message' => 'Scan started successfully.'
    ]);
    exit;

} catch (Exception $e) {
    if (file_exists($lockFile)) unlink($lockFile);
    echo json_encode([
      'status'  => 'error',
      'message' => 'Failed to start scan: ' . $e->getMessage()
    ]);
    exit;
}

/**
 * Launches the Python scanner in the background, writing to $logFile.
 * Returns the PID.
 */
function start_background_process($logFile) {
    $pythonPath = "/opt/homebrew/bin/python3.10"; 
    $cmd = sprintf(
      "%s %s > %s 2>&1 & echo $!",
      escapeshellarg($pythonPath),
      escapeshellarg(__DIR__ . "/yolo_ai.py"),
      escapeshellarg($logFile)
    );
    exec($cmd, $output);
    return (int) $output[0];
}

/** Checks whether $pid is still running on this box */
function is_process_running($pid) {
    exec("ps -p $pid", $out);
    return count($out) > 1;
}

// We never actually reach here, but…
$_SESSION["scanCompleted"] = true;
echo json_encode(["status" => "success"]);
?>
