<?php
require "config.php";
require 'login-check.php';
require_once 'passport_helper.php';

$mediaId = isset($_GET['media_id']) ? (int) $_GET['media_id'] : 0;
$format = $_GET['format'] ?? 'html';

$stmt = $conn->prepare("SELECT * FROM files WHERE id = ?");
$stmt->bind_param("i", $mediaId);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

$passport = get_passport_by_media_id($conn, $mediaId);

if (!$file || !$passport) {
    http_response_code(404);
    echo "Passport not found.";
    exit;
}

record_audit($conn, [
    'module'      => 'metadata',
    'action'      => 'DOWNLOAD',
    'target_type' => 'passport',
    'target_id'   => $passport['id'],
    'description' => "Downloaded passport {$passport['passport_uid']} ({$format})",
]);

if ($format === 'json') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $passport['passport_uid'] . '.json"');
    echo json_encode([
        'passport'  => $passport,
        'media'     => [
            'filename' => $file['filename'],
            'filetype' => $file['filetype'],
            'size'     => $file['size'],
        ],
    ], JSON_PRETTY_PRINT);
    exit;
}

$qrData = urlencode($passport['qr_payload'] ?? $passport['passport_uid']);
$qrImageUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={$qrData}";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Passport <?= htmlspecialchars($passport['passport_uid']) ?></title>
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; color: #222; margin: 40px; }
    .doc { max-width: 800px; margin: 0 auto; border: 2px solid #6f42c1; border-radius: 8px; padding: 30px; }
    .doc-header { display:flex; justify-content:space-between; align-items:center; border-bottom: 2px solid #6f42c1; padding-bottom: 16px; margin-bottom: 20px; }
    .doc-header h1 { font-size: 20px; margin: 0; color: #6f42c1; }
    .doc-header p { margin: 2px 0 0; font-size: 13px; color: #666; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    table td, table th { padding: 8px 6px; border-bottom: 1px solid #eee; font-size: 13px; text-align: left; vertical-align: top; }
    table th { width: 220px; color: #555; }
    .sig { font-family: monospace; font-size: 11px; word-break: break-all; }
    .footer-note { margin-top: 24px; font-size: 11px; color: #888; }
    .print-btn { margin-bottom: 16px; }
    @media print { .print-btn { display: none; } }
</style>
</head>
<body>
    <button class="btn print-btn" onclick="window.print()" style="padding:8px 16px;">Print / Save as PDF</button>
    <div class="doc">
        <div class="doc-header">
            <div>
                <h1>Foundation University — Media Release Metadata Passport</h1>
                <p><?= htmlspecialchars($file['filename']) ?></p>
            </div>
            <img src="<?= htmlspecialchars($qrImageUrl) ?>" alt="QR Code" width="90" height="90">
        </div>

        <table>
            <tr><th>Passport ID</th><td><?= htmlspecialchars($passport['passport_uid']) ?></td></tr>
            <tr><th>Media ID</th><td><?= (int)$passport['media_id'] ?></td></tr>
            <tr><th>Event Name</th><td><?= htmlspecialchars($passport['event_name'] ?? '—') ?></td></tr>
            <tr><th>Department</th><td><?= htmlspecialchars($passport['department'] ?? '—') ?></td></tr>
            <tr><th>Photographer</th><td><?= htmlspecialchars($passport['photographer'] ?? '—') ?></td></tr>
            <tr><th>Uploaded By</th><td><?= htmlspecialchars($passport['uploaded_by'] ?? '—') ?></td></tr>
            <tr><th>Date Taken</th><td><?= $passport['date_taken'] ? date('M d, Y h:i A', strtotime($passport['date_taken'])) : '—' ?></td></tr>
            <tr><th>Upload Date</th><td><?= $passport['upload_date'] ? date('M d, Y h:i A', strtotime($passport['upload_date'])) : '—' ?></td></tr>
            <tr><th>GPS Location</th><td><?= htmlspecialchars($passport['gps_location'] ?? 'Not available') ?></td></tr>
            <tr><th>Device Information</th><td><?= htmlspecialchars($passport['device_info'] ?? 'Not available') ?></td></tr>
            <tr><th>AI Classification</th><td><?= htmlspecialchars($passport['ai_classification'] ?? '—') ?></td></tr>
            <tr><th>Confidence Score</th><td><?= number_format((float)$passport['confidence_score'], 1) ?>%</td></tr>
            <tr><th>AI Version</th><td><?= htmlspecialchars($passport['ai_version']) ?></td></tr>
            <tr><th>Copyright Owner</th><td><?= htmlspecialchars($passport['copyright_owner']) ?></td></tr>
            <tr><th>Distribution Rights</th><td><?= htmlspecialchars($passport['distribution_rights']) ?></td></tr>
            <tr><th>Usage Restrictions</th><td><?= htmlspecialchars($passport['usage_restrictions'] ?? 'Standard internal-use terms apply') ?></td></tr>
            <tr><th>C2PA Compliance</th><td><?= $passport['c2pa_compliant'] ? 'Compliant' : 'Not marked' ?></td></tr>
            <tr><th>Digital Signature</th><td class="sig"><?= htmlspecialchars($passport['digital_signature']) ?></td></tr>
            <tr><th>Issued</th><td><?= date('M d, Y h:i A', strtotime($passport['created_at'])) ?></td></tr>
        </table>

        <p class="footer-note">
            This passport is a system-generated record of media provenance and usage rights for Foundation University Creatives.
            Verify authenticity by matching the digital signature above against the passport registry, or by scanning the QR code.
        </p>
    </div>
</body>
</html>
