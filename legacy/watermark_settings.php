<?php
require 'head.php';
require "config.php";
require 'login-check.php';
require_once 'watermark_helper.php';

$employee_id = $_SESSION['employee_id'] ?? null;
$stmt = $conn->prepare("SELECT employee_id, name, role, department FROM user WHERE employee_id = ?");
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

$isAdmin = ($currentUser['role'] ?? '') === 'admin';

$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!$isAdmin) {
        $message = ['type' => 'danger', 'text' => 'Only administrators can change watermark settings.'];
    } else {
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $text = trim($_POST['watermark_text'] ?? 'Foundation University | FU Creatives');
        $position = $_POST['position'] ?? 'bottom_right';
        $opacity = max(0, min(100, (int) ($_POST['opacity'] ?? 55)));
        $resize = max(50, min(300, (int) ($_POST['resize_percent'] ?? 100)));

        $stmt = $conn->prepare(
            "UPDATE watermark_settings SET enabled=?, watermark_text=?, position=?, opacity=?, resize_percent=?, updated_by=? WHERE id = 1"
        );
        $stmt->bind_param("issiis", $enabled, $text, $position, $opacity, $resize, $employee_id);
        $stmt->execute();
        $stmt->close();

        record_audit($conn, [
            'module'      => 'watermark',
            'action'      => 'WATERMARK_TOGGLE',
            'target_type' => 'settings',
            'target_id'   => 1,
            'description' => "Updated watermark settings — enabled: " . ($enabled ? 'yes' : 'no') . ", position: {$position}, opacity: {$opacity}%",
        ]);

        $message = ['type' => 'success', 'text' => 'Watermark settings updated.'];
    }
}

$settings = get_watermark_settings($conn);

// Recent images available for manual watermarking
$stmt = $conn->prepare(
    "SELECT f.id, f.filename, f.filepath, f.filetype, fw.watermarked_path
     FROM files f
     LEFT JOIN file_watermarks fw ON fw.media_id = f.id
     WHERE f.is_deleted = 0 AND (f.filetype LIKE 'image%' OR f.filename LIKE '%.jpg' OR f.filename LIKE '%.jpeg' OR f.filename LIKE '%.png')
     ORDER BY f.dateupload DESC LIMIT 30"
);
$stmt->execute();
$images = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Watermark Settings - Greyhound Hub</title>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Automatic Watermarking</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item active">Watermark Settings</li>
            </ol>
        </nav>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $message['type'] ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endif; ?>
    <?php if (!extension_loaded('gd')): ?>
        <div class="alert alert-warning">The PHP GD extension is not enabled on this server. Watermarking will not run until GD is enabled.</div>
    <?php endif; ?>

    <section class="section">
        <div class="row">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Settings</h5>
                        <form method="post">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="enabled" name="enabled" <?= $settings['enabled'] ? 'checked' : '' ?> <?= !$isAdmin ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="enabled">Enable automatic watermarking on upload</label>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Watermark Text</label>
                                <input type="text" class="form-control" name="watermark_text" value="<?= htmlspecialchars($settings['watermark_text']) ?>" <?= !$isAdmin ? 'disabled' : '' ?>>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Position</label>
                                <select class="form-select" name="position" <?= !$isAdmin ? 'disabled' : '' ?>>
                                    <?php foreach (['bottom_right' => 'Bottom Right', 'bottom_left' => 'Bottom Left', 'top_right' => 'Top Right', 'top_left' => 'Top Left', 'center' => 'Center'] as $val => $label): ?>
                                        <option value="<?= $val ?>" <?= $settings['position'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Opacity: <span id="opacityVal"><?= (int)$settings['opacity'] ?></span>%</label>
                                <input type="range" class="form-range" min="0" max="100" name="opacity" id="opacity" value="<?= (int)$settings['opacity'] ?>" <?= !$isAdmin ? 'disabled' : '' ?>>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Resize: <span id="resizeVal"><?= (int)$settings['resize_percent'] ?></span>%</label>
                                <input type="range" class="form-range" min="50" max="300" name="resize_percent" id="resize" value="<?= (int)$settings['resize_percent'] ?>" <?= !$isAdmin ? 'disabled' : '' ?>>
                            </div>

                            <?php if ($isAdmin): ?>
                                <button type="submit" name="save_settings" class="btn btn-primary">Save Settings</button>
                            <?php else: ?>
                                <small class="text-muted">Only administrators can edit these settings.</small>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Recent Images</h5>
                        <p class="text-muted small">Apply the current watermark settings to a file and preview it before it's made downloadable.</p>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle">
                                <thead>
                                <tr><th>File</th><th>Status</th><th class="text-end">Actions</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($images as $img): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($img['filename']) ?></td>
                                        <td>
                                            <?php if ($img['watermarked_path']): ?>
                                                <span class="badge bg-success">Watermarked</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Original only</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary apply-wm" data-media-id="<?= (int)$img['id'] ?>"><i class="bi bi-droplet"></i> Apply</button>
                                            <a class="btn btn-sm btn-outline-secondary" target="_blank" href="watermark_preview.php?media_id=<?= (int)$img['id'] ?>"><i class="bi bi-eye"></i> Preview</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($images)): ?>
                                    <tr><td colspan="3" class="text-center text-muted py-3">No images found.</td></tr>
                                <?php endif; ?>
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
document.getElementById('opacity')?.addEventListener('input', e => document.getElementById('opacityVal').textContent = e.target.value);
document.getElementById('resize')?.addEventListener('input', e => document.getElementById('resizeVal').textContent = e.target.value);

document.querySelectorAll('.apply-wm').forEach(btn => {
    btn.addEventListener('click', function () {
        const mediaId = this.dataset.mediaId;
        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        fetch('watermark_apply.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'media_id=' + encodeURIComponent(mediaId)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert('Error: ' + (data.error || 'Could not apply watermark.'));
                this.disabled = false;
            }
        });
    });
});
</script>
<a href="#" class="back-to-top"><i class="bi bi-arrow-up-short"></i></a>
</body>
</html>
