<?php
require 'head.php';
require "config.php";
require 'login-check.php';
require_once 'audit_helper.php';

$employee_id = $_SESSION['employee_id'] ?? null;
$stmt = $conn->prepare("SELECT employee_id, name, role, department FROM user WHERE employee_id = ?");
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

$filterUser = trim($_GET['user'] ?? '');
$filterDept = trim($_GET['department'] ?? '');
$filterModule = trim($_GET['module'] ?? '');
$filterFrom = trim($_GET['from'] ?? '');
$filterTo = trim($_GET['to'] ?? '');
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

$where = ['1=1'];
$params = [];
$types = '';

if ($filterUser !== '') {
    $where[] = '(user_name LIKE ? OR user_id LIKE ?)';
    $like = "%$filterUser%";
    $params[] = $like; $params[] = $like; $types .= 'ss';
}
if ($filterDept !== '') {
    $where[] = 'department = ?';
    $params[] = $filterDept; $types .= 's';
}
if ($filterModule !== '') {
    $where[] = 'module = ?';
    $params[] = $filterModule; $types .= 's';
}
if ($filterFrom !== '') {
    $where[] = 'created_at >= ?';
    $params[] = $filterFrom . ' 00:00:00'; $types .= 's';
}
if ($filterTo !== '') {
    $where[] = 'created_at <= ?';
    $params[] = $filterTo . ' 23:59:59'; $types .= 's';
}

$sql = "SELECT * FROM audit_logs WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT " . ($export ? 5000 : 200);
$stmt = $conn->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp', 'User', 'Department', 'Module', 'Action', 'Target Type', 'Target ID', 'Description', 'IP Address']);
    foreach ($logs as $l) {
        fputcsv($out, [$l['created_at'], $l['user_name'], $l['department'], $l['module'], $l['action'], $l['target_type'], $l['target_id'], $l['description'], $l['ip_address']]);
    }
    fclose($out);
    exit;
}

$deptRes = $conn->query("SELECT DISTINCT department FROM user WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments = $deptRes ? $deptRes->fetch_all(MYSQLI_ASSOC) : [];

$moduleRes = $conn->query("SELECT DISTINCT module FROM audit_logs WHERE module IS NOT NULL ORDER BY module");
$modules = $moduleRes ? $moduleRes->fetch_all(MYSQLI_ASSOC) : [];

$moduleBadge = [
    'upload' => 'bg-success', 'download' => 'bg-dark', 'delete' => 'bg-danger',
    'watermark' => 'bg-info text-dark',
    'metadata' => 'bg-secondary', 'auth' => 'bg-warning text-dark', 'general' => 'bg-light text-dark',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Audit Logs - Greyhound Hub</title>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Audit Logs</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item active">Audit Logs</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Filters</h5>
                <form class="row g-2 mb-3" method="get">
                    <div class="col-md-2">
                        <input type="text" name="user" class="form-control" placeholder="User name/ID" value="<?= htmlspecialchars($filterUser) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="department" class="form-select">
                            <option value="">All departments</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= htmlspecialchars($d['department']) ?>" <?= $filterDept === $d['department'] ? 'selected' : '' ?>><?= htmlspecialchars($d['department']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="module" class="form-select">
                            <option value="">All activity types</option>
                            <?php foreach ($modules as $m): ?>
                                <option value="<?= htmlspecialchars($m['module']) ?>" <?= $filterModule === $m['module'] ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($m['module'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($filterFrom) ?>">
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($filterTo) ?>">
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-funnel"></i> Filter</button>
                    </div>
                </form>
                <a class="btn btn-outline-success btn-sm mb-3" href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>"><i class="bi bi-download"></i> Export CSV</a>

                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle">
                        <thead class="table-light">
                        <tr><th>Timestamp</th><th>User</th><th>Department</th><th>Activity</th><th>Description</th><th>IP</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td class="text-nowrap"><?= date('M d, Y h:i A', strtotime($l['created_at'])) ?></td>
                                <td><?= htmlspecialchars($l['user_name'] ?: $l['user_id'] ?: 'System') ?></td>
                                <td><?= htmlspecialchars($l['department'] ?? '—') ?></td>
                                <td>
                                    <span class="badge <?= $moduleBadge[$l['module']] ?? 'bg-light text-dark' ?>"><?= htmlspecialchars(strtoupper($l['action'])) ?></span>
                                </td>
                                <td><?= htmlspecialchars($l['description']) ?></td>
                                <td class="text-muted small"><?= htmlspecialchars($l['ip_address'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No audit log entries match your filters.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted small">Showing up to 200 most recent entries. Use filters or export CSV for the full range.</p>
            </div>
        </div>
    </section>
</main>

<?php require 'footer.php'; ?>
<script src="assets/js/main.js"></script>
<a href="#" class="back-to-top"><i class="bi bi-arrow-up-short"></i></a>
</body>
</html>
