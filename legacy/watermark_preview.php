<?php
require "config.php";
require 'login-check.php';
require_once 'watermark_helper.php';

$mediaId = isset($_GET['media_id']) ? (int) $_GET['media_id'] : 0;

$stmt = $conn->prepare("SELECT * FROM files WHERE id = ?");
$stmt->bind_param("i", $mediaId);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    http_response_code(404);
    echo "File not found.";
    exit;
}

$watermark = get_file_watermark($conn, $mediaId);

function to_web_path(string $path): string {
    // Reuses the app's existing file-streaming endpoint (see download_file.php)
    // so previews respect the same access checks as everything else.
    return 'download_file.php?file=' . urlencode($path);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Watermark Preview - <?= htmlspecialchars($file['filename']) ?></title>
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<style>body{padding:30px;background:#f6f6f9;} .preview-box{background:#fff;border-radius:10px;padding:16px;text-align:center;} img{max-width:100%;border-radius:6px;}</style>
</head>
<body>
<div class="container">
    <h4 class="mb-4">Watermark Preview — <?= htmlspecialchars($file['filename']) ?></h4>
    <div class="row g-3">
        <div class="col-md-6">
            <div class="preview-box">
                <h6>Original</h6>
                <img src="<?= htmlspecialchars(to_web_path($file['filepath'])) ?>" alt="Original">
            </div>
        </div>
        <div class="col-md-6">
            <div class="preview-box">
                <h6>Watermarked</h6>
                <?php if ($watermark): ?>
                    <img src="<?= htmlspecialchars(to_web_path($watermark['watermarked_path'])) ?>" alt="Watermarked">
                    <p class="text-muted small mt-2">Applied <?= date('M d, Y h:i A', strtotime($watermark['applied_at'])) ?></p>
                <?php else: ?>
                    <p class="text-muted py-5">Not watermarked yet. Apply a watermark from the Watermark Settings page first.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($watermark): ?>
        <a href="<?= htmlspecialchars(to_web_path($watermark['watermarked_path'])) ?>" download class="btn btn-primary mt-3">Download Watermarked Version</a>
    <?php endif; ?>
    <a href="watermark_settings.php" class="btn btn-outline-secondary mt-3">Back to Settings</a>
</div>
</body>
</html>
