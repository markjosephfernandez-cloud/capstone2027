<?php
session_start();
$logFile = __DIR__ . '/scan_progress.log'; // Log file in the same directory
$totalSteps = 10; // Total steps for the simulated task

try {
    // Ensure the log file is writable
    if (!is_writable(__DIR__)) {
        throw new Exception("Directory is not writable: " . __DIR__);
    }

    // Initialize the log file
    file_put_contents($logFile, "Scan started.\n");

    for ($i = 1; $i <= $totalSteps; $i++) {
        // Check if the scan has been manually stopped
        if (!isset($_SESSION['scan_running']) || $_SESSION['scan_running'] === false) {
            file_put_contents($logFile, "Scan stopped by user.\n", FILE_APPEND);
            $_SESSION['scan_running'] = false; // Ensure session status is consistent
            exit; // Stop execution
        }

        sleep(2); // Simulate a delay for the task

        // Update progress in the log file
        $progress = round(($i / $totalSteps) * 100, 2);
        file_put_contents($logFile, "Progress: $progress%\n", FILE_APPEND);
    }

    // Mark the scan as complete in the log file and reset session status
    file_put_contents($logFile, "Progress: 100%\nScan completed.\n", FILE_APPEND);
    $_SESSION['scan_running'] = false;

} catch (Exception $e) {
    // Log any errors
    file_put_contents($logFile, "Error: " . $e->getMessage() . "\n", FILE_APPEND);
    $_SESSION['scan_running'] = false; // Reset session status to avoid blocking future scans
    exit;
}
?>
