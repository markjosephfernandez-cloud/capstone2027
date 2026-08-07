<?php
require 'head.php';
require "config.php";
require 'login-check.php';
require_once 'passport_helper.php';

$employee_id = $_SESSION['employee_id'] ?? null;
$stmt = $conn->prepare("SELECT employee_id, name, role, department FROM user WHERE employee_id = ?");
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$sql = "SELECT f.id AS media_id, f.filename, f.filepath, f.filetype, f.dateupload,
               p.id AS passport_id, p.passport_uid, p.event_name, p.confidence_score,
               p.needs_review, p.created_at AS passport_created
        FROM files f
        LEFT JOIN passports p ON p.media_id = f.id
        WHERE f.is_deleted = 0";
$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (f.filename LIKE ? OR p.event_name LIKE ? OR p.passport_uid LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
if ($statusFilter === 'no_passport') {
    $sql .= " AND p.id IS NULL";
} elseif ($statusFilter === 'needs_review') {
    $sql .= " AND p.needs_review = 1";
} elseif ($statusFilter === 'ready') {
    $sql .= " AND p.id IS NOT NULL AND p.needs_review = 0";
}

$sql .= " ORDER BY f.dateupload DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Metadata Passports - Greyhound Hub</title>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>AI Media Release Metadata Passports</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item active">Metadata Passports</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Passport Registry</h5>

                        <form class="row g-2 mb-3" method="get">
                            <div class="col-md-5">
                                <input type="text" class="form-control" name="q" placeholder="Search filename, event, or passport ID" value="<?= htmlspecialchars($search) ?>">
                            </div>
                            <div class="col-md-3">
                                <select name="status" class="form-select">
                                    <option value="">All statuses</option>
                                    <option value="no_passport" <?= $statusFilter === 'no_passport' ? 'selected' : '' ?>>No passport yet</option>
                                    <option value="needs_review" <?= $statusFilter === 'needs_review' ? 'selected' : '' ?>>Low AI confidence</option>
                                    <option value="ready" <?= $statusFilter === 'ready' ? 'selected' : '' ?>>Ready</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-primary w-100" type="submit"><i class="bi bi-search"></i> Filter</button>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-striped table-hover align-middle">
                                <thead class="table-light">
                                <tr>
                                    <th>File</th>
                                    <th>Passport ID</th>
                                    <th>AI Event</th>
                                    <th>Confidence</th>
                                    <th>Status</th>
                                    <th>Uploaded</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">No media found.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><i class="bi bi-file-earmark-image me-1"></i><?= htmlspecialchars($r['filename']) ?></td>
                                        <td><?= $r['passport_uid'] ? htmlspecialchars($r['passport_uid']) : '<span class="text-muted">—</span>' ?></td>
                                        <td><?= htmlspecialchars($r['event_name'] ?? '—') ?></td>
                                        <td>
                                            <?php if ($r['confidence_score'] !== null): ?>
                                                <span class="badge <?= $r['confidence_score'] >= 90 ? 'bg-success' : ($r['confidence_score'] >= 80 ? 'bg-primary' : 'bg-warning text-dark') ?>">
                                                    <?= number_format((float)$r['confidence_score'], 1) ?>%
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!$r['passport_id']): ?>
                                                <span class="badge bg-secondary">No passport</span>
                                            <?php elseif ($r['needs_review']): ?>
                                                <span class="badge bg-warning text-dark">Low AI confidence</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Ready</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $r['dateupload'] ? date('M d, Y', strtotime($r['dateupload'])) : '—' ?></td>
                                        <td class="text-end">
                                            <?php if ($r['passport_id']): ?>
                                                <a href="passport_view.php?media_id=<?= (int)$r['media_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> View</a>
                                                <button class="btn btn-sm btn-outline-secondary regen-btn" data-media-id="<?= (int)$r['media_id'] ?>"><i class="bi bi-arrow-repeat"></i> Regenerate</button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-primary gen-btn" data-media-id="<?= (int)$r['media_id'] ?>"><i class="bi bi-magic"></i> Generate Passport</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require 'footer.php'; ?>
<script src="assets/js/main.js"></script>
<script>
document.querySelectorAll('.gen-btn, .regen-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        const mediaId = this.dataset.mediaId;
        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Working...';
        fetch('generate_passport.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'media_id=' + encodeURIComponent(mediaId)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = 'passport_view.php?media_id=' + mediaId;
            } else {
                alert('Error: ' + (data.error || 'Could not generate passport.'));
                this.disabled = false;
            }
        })
        .catch(() => {
            alert('Network error while generating the passport.');
            this.disabled = false;
        });
    });
});
</script>
<a href="#" class="back-to-top"><i class="bi bi-arrow-up-short"></i></a>
</body>
</html>
