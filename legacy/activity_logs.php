<?php
require "config.php";
require 'login-check.php';

$employee_id = $_SESSION['employee_id'] ?? null;
if (!$employee_id) {
    header("Location: login.php");
    exit;
}

// Fetch current user info
$sql = "SELECT employee_id, name, role FROM user WHERE employee_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Fetch login/logout activity logs from `activity` table
$loginLogs = [];
$stmt = $conn->prepare("
    SELECT 
        a.user_id,
        u.name AS user_name,
        u.role,
        a.action,
        a.details,
        a.timestamp
    FROM activity a
    JOIN user u ON a.user_id = u.employee_id
    ORDER BY a.timestamp DESC
    LIMIT 50
");
$stmt->execute();
$result = $stmt->get_result();
$loginLogs = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch file activity logs from `files` table
$fileLogs = [];
$stmt = $conn->prepare("
    SELECT 
        f.last_action AS action,
        f.employee_id AS admin_id,
        f.last_action_at AS timestamp,
        f.filename,
        f.filepath,
        u.name AS user_name,
        u.role
    FROM files f
    JOIN user u ON f.employee_id = u.employee_id
    WHERE f.last_action IS NOT NULL
    ORDER BY f.last_action_at DESC
    LIMIT 50
");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $filename = basename($row['filename']) ?? 'Unknown File';
    $action = strtolower($row['action']);

    $row['details'] = match ($action) {
        'restored'       => "Restored file '{$filename}' from trash.",
        'deleted'        => "Permanently deleted file '{$filename}'.",
        'renamed'        => "Renamed file '{$filename}'.",
        'moved_to_trash' => "Moved file '{$filename}' to trash.",
        'duplicated'     => "Duplicated file '{$filename}'.",
        'downloaded'     => "Downloaded file '{$filename}'.",
        'update'         => "Updated file '{$filename}'.",
        'created'        => "Created file '{$filename}'.",
        default          => ucfirst($row['role']) . " user '{$row['user_name']}' performed {$action} on '{$filename}'"
    };

    $fileLogs[] = $row;
}
$stmt->close();

// Merge and sort both logs by timestamp (DESC)
$logs = array_merge($loginLogs, $fileLogs);
usort($logs, fn($a, $b) => strtotime($b['timestamp']) <=> strtotime($a['timestamp']));

// Format timestamps and roles
foreach ($logs as &$log) {
    $log['timestamp'] = date('Y-m-d h:i:s A', strtotime($log['timestamp']));
    $log['role'] = ucfirst($log['role']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Activity Logs</title>
    <?php require 'head.php'; ?>
    <style>
        .badge-action {
            font-size: 0.8rem;
            padding: 0.45em 0.75em;
            border-radius: 0.35rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<main id="main" class="main">
    <div class="activity-logs-container">
        <div class="card activity-logs-card">
            <div class="card-header text-center">
                <h2>Activity Logs</h2>
                <p>Recent actions performed in the system</p>
            </div>

            <div class="card-body">
                <?php if (!empty($logs)): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>Timestamp</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($logs as $index => $log): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($log['user_name']) ?></td>
                                    <td><?= htmlspecialchars($log['role']) ?></td>
                                    <td>
                                        <?php
                                        $action = strtolower(trim($log['action']));
                                        $badgeClass = match ($action) {
                                            'login'          => 'badge bg-success',
                                            'logout'         => 'badge bg-secondary',
                                            'renamed'        => 'badge bg-primary',
                                            'duplicated'     => 'badge bg-info text-dark',
                                            'moved_to_trash' => 'badge bg-warning text-dark',
                                            'restored'       => 'badge bg-success',
                                            'deleted'        => 'badge bg-danger',
                                            'update'         => 'badge bg-primary',
                                            'downloaded'     => 'badge bg-dark',
                                            'created'        => 'badge bg-success',
                                            default          => 'badge bg-light text-dark'
                                        };
                                        ?>
                                        <span class="<?= $badgeClass ?> badge-action"><?= strtoupper($log['action']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($log['details']) ?></td>
                                    <td><?= htmlspecialchars($log['timestamp']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center">No activity logs found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php require 'footer.php'; ?>
<script src="assets/js/main.js"></script>
<a href="#" class="back-to-top"><i class="bi bi-arrow-up-short"></i></a>
</body>
</html>
