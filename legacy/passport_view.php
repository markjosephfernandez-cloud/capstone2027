<?php
require 'head.php';
require "config.php";
require 'login-check.php';
require_once 'passport_helper.php';

$mediaId = isset($_GET['media_id']) ? (int) $_GET['media_id'] : 0;

$stmt = $conn->prepare("SELECT * FROM files WHERE id = ?");
$stmt->bind_param("i", $mediaId);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    header("Location: passports.php");
    exit;
}

$passport = get_passport_by_media_id($conn, $mediaId);
if (!$passport) {
    header("Location: passports.php");
    exit;
}

$verStmt = $conn->prepare("SELECT * FROM passport_versions WHERE passport_id = ? ORDER BY version_no DESC");
$verStmt->bind_param("i", $passport['id']);
$verStmt->execute();
$versions = $verStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$verStmt->close();

$qrData = urlencode($passport['qr_payload'] ?? $passport['passport_uid']);
$qrImageUrl = "https://api.qrserver.com/v1/create-qr-code/?size=180x180&data={$qrData}";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Passport <?= htmlspecialchars($passport['passport_uid']) ?> - Greyhound Hub</title>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Metadata Passport</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item"><a href="passports.php">Metadata Passports</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($passport['passport_uid']) ?></li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="card-title mb-0"><?= htmlspecialchars($file['filename']) ?></h5>
                                <small class="text-muted">Passport ID: <?= htmlspecialchars($passport['passport_uid']) ?></small>
                            </div>
                            <?php if ($passport['needs_review']): ?>
                                <span class="badge bg-warning text-dark">Low AI confidence</span>
                            <?php else: ?>
                                <span class="badge bg-success">AI classification accepted</span>
                            <?php endif; ?>
                        </div>
                        <hr>
                        <table class="table table-sm">
                            <tbody>
                                <tr><th style="width:220px">Media ID</th><td><?= (int)$passport['media_id'] ?></td></tr>
                                <tr><th>Event Name</th><td><?= htmlspecialchars($passport['event_name'] ?? '—') ?></td></tr>
                                <tr><th>Department</th><td><?= htmlspecialchars($passport['department'] ?? '—') ?></td></tr>
                                <tr><th>Photographer</th><td><?= htmlspecialchars($passport['photographer'] ?? '—') ?></td></tr>
                                <tr><th>Uploaded By</th><td><?= htmlspecialchars($passport['uploaded_by'] ?? '—') ?></td></tr>
                                <tr><th>Date Taken</th><td><?= $passport['date_taken'] ? date('M d, Y h:i A', strtotime($passport['date_taken'])) : '—' ?></td></tr>
                                <tr><th>Upload Date</th><td><?= $passport['upload_date'] ? date('M d, Y h:i A', strtotime($passport['upload_date'])) : '—' ?></td></tr>
                                <tr><th>GPS Location</th><td><?= htmlspecialchars($passport['gps_location'] ?? 'Not available') ?></td></tr>
                                <tr><th>Device Information</th><td><?= htmlspecialchars($passport['device_info'] ?? 'Not available') ?></td></tr>
                                <tr><th>AI Classification</th><td><?= htmlspecialchars($passport['ai_classification'] ?? '—') ?> <span class="text-muted">(<?= htmlspecialchars($passport['ai_version']) ?>, <?= (int)$passport['processing_time_ms'] ?> ms)</span></td></tr>
                                <tr><th>Confidence Score</th><td><?= number_format((float)$passport['confidence_score'], 1) ?>%</td></tr>
                                <tr><th>Copyright Owner</th><td><?= htmlspecialchars($passport['copyright_owner']) ?></td></tr>
                                <tr><th>Distribution Rights</th><td><?= htmlspecialchars($passport['distribution_rights']) ?></td></tr>
                                <tr><th>Usage Restrictions</th><td><?= htmlspecialchars($passport['usage_restrictions'] ?? 'Standard internal-use terms apply') ?></td></tr>
                                <tr><th>Digital Signature</th><td><code style="font-size:0.75rem"><?= htmlspecialchars($passport['digital_signature']) ?></code></td></tr>
                                <tr><th>C2PA Compliance</th><td><?= $passport['c2pa_compliant'] ? '<span class="badge bg-success">Compliant</span>' : '<span class="badge bg-secondary">Not marked</span>' ?></td></tr>
                            </tbody>
                        </table>

                        <div class="d-flex gap-2">
                            <a href="passport_download.php?media_id=<?= $mediaId ?>&format=json" class="btn btn-outline-secondary btn-sm"><i class="bi bi-filetype-json"></i> Download JSON</a>
                            <a href="passport_download.php?media_id=<?= $mediaId ?>" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Downloadable Passport (Print/PDF)</a>
                            <button class="btn btn-outline-secondary btn-sm regen-btn" data-media-id="<?= $mediaId ?>"><i class="bi bi-arrow-repeat"></i> Regenerate</button>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Version History</h5>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($versions as $v): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>v<?= (int)$v['version_no'] ?> — <?= htmlspecialchars($v['change_note']) ?></span>
                                    <span class="text-muted small"><?= date('M d, Y h:i A', strtotime($v['changed_at'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                            <?php if (empty($versions)): ?>
                                <li class="list-group-item text-muted">No version history yet.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h5 class="card-title">Passport QR Code</h5>
                        <img src="<?= htmlspecialchars($qrImageUrl) ?>" alt="Passport QR" class="img-fluid mb-2">
                        <p class="text-muted small mb-0">Scan to verify this passport's authenticity and signature.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require 'footer.php'; ?>
<script src="assets/js/main.js"></script>
<script>
document.querySelectorAll('.regen-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        const mediaId = this.dataset.mediaId;
        this.disabled = true;
        fetch('generate_passport.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'media_id=' + encodeURIComponent(mediaId)
        }).then(() => window.location.reload());
    });
});
</script>
<a href="#" class="back-to-top"><i class="bi bi-arrow-up-short"></i></a>
</body>
</html>
