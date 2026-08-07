<?php
/**
 * watermark_helper.php
 * Feature 4: Automatic Watermarking (images, posters, marketing graphics).
 * Uses PHP GD (bundled with virtually every PHP install) so no extra
 * extensions need to be compiled in. Falls back gracefully if GD is missing.
 */

require_once __DIR__ . '/audit_helper.php';

if (!function_exists('get_watermark_settings')) {
    function get_watermark_settings(mysqli $conn): array
    {
        $res = $conn->query("SELECT * FROM watermark_settings WHERE id = 1");
        $row = $res ? $res->fetch_assoc() : null;
        return $row ?: [
            'enabled' => 1,
            'watermark_text' => 'Foundation University | FU Creatives',
            'position' => 'bottom_right',
            'opacity' => 55,
            'resize_percent' => 100,
            'logo_path' => null,
        ];
    }
}

if (!function_exists('is_watermarkable_image')) {
    function is_watermarkable_image(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) && file_exists($path);
    }
}

/**
 * Apply the configured watermark to $sourcePath and write the result to $destPath.
 * Returns true on success, false otherwise (e.g. GD missing, unsupported type).
 */
if (!function_exists('apply_watermark_to_image')) {
    function apply_watermark_to_image(string $sourcePath, string $destPath, array $settings): bool
    {
        if (!extension_loaded('gd') || !is_watermarkable_image($sourcePath)) {
            return false;
        }

        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                $src = @imagecreatefromjpeg($sourcePath);
                break;
            case 'png':
                $src = @imagecreatefrompng($sourcePath);
                break;
            case 'gif':
                $src = @imagecreatefromgif($sourcePath);
                break;
            case 'webp':
                $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false;
                break;
            default:
                $src = false;
        }
        if (!$src) {
            return false;
        }

        imagesavealpha($src, true);
        $width = imagesx($src);
        $height = imagesy($src);

        $text = $settings['watermark_text'] ?? 'Foundation University | FU Creatives';
        $opacityPct = (int) ($settings['opacity'] ?? 55);
        $opacityPct = max(0, min(100, $opacityPct));
        $resizePercent = max(50, min(300, (int) ($settings['resize_percent'] ?? 100)));

        // Scale font size relative to image width so the watermark stays proportional
        $fontSize = max(1, (int) round(($width / 60) * ($resizePercent / 100)));
        // GD built-in font 5 is the largest fixed font; imagestring only accepts sizes 1-5.
        $gdFont = 5;
        $charWidth = imagefontwidth($gdFont);
        $charHeight = imagefontheight($gdFont);
        $textWidth = $charWidth * strlen($text);
        $textHeight = $charHeight;

        $margin = max(10, (int) round($width * 0.02));
        switch ($settings['position'] ?? 'bottom_right') {
            case 'bottom_left':
                $x = $margin; $y = $height - $textHeight - $margin;
                break;
            case 'top_right':
                $x = $width - $textWidth - $margin; $y = $margin;
                break;
            case 'top_left':
                $x = $margin; $y = $margin;
                break;
            case 'center':
                $x = (int) (($width - $textWidth) / 2); $y = (int) (($height - $textHeight) / 2);
                break;
            case 'bottom_right':
            default:
                $x = $width - $textWidth - $margin; $y = $height - $textHeight - $margin;
        }

        // Draw watermark onto a transparent overlay first, then merge for true opacity control
        $overlay = imagecreatetruecolor($width, $height);
        imagesavealpha($overlay, true);
        $transparent = imagecolorallocatealpha($overlay, 0, 0, 0, 127);
        imagefill($overlay, 0, 0, $transparent);

        $shadow = imagecolorallocatealpha($overlay, 0, 0, 0, 40);
        $white = imagecolorallocatealpha($overlay, 255, 255, 255, 20);
        imagestring($overlay, $gdFont, $x + 2, $y + 2, $text, $shadow);
        imagestring($overlay, $gdFont, $x, $y, $text, $white);

        // Merge overlay onto source at the configured opacity
        imagecopymerge($src, $overlay, 0, 0, 0, 0, $width, $height, $opacityPct);
        imagedestroy($overlay);

        // Ensure destination directory exists
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }

        $ok = false;
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                $ok = imagejpeg($src, $destPath, 90);
                break;
            case 'png':
                $ok = imagepng($src, $destPath);
                break;
            case 'gif':
                $ok = imagegif($src, $destPath);
                break;
            case 'webp':
                $ok = function_exists('imagewebp') ? imagewebp($src, $destPath, 90) : false;
                break;
        }
        imagedestroy($src);

        return $ok;
    }
}

/**
 * Apply watermark for a given media id using current admin settings and
 * record the result in file_watermarks. Returns the watermarked path or null.
 */
if (!function_exists('watermark_media')) {
    function watermark_media(mysqli $conn, int $mediaId, ?string $actorId = null): ?string
    {
        $settings = get_watermark_settings($conn);
        if (!$settings['enabled']) {
            return null;
        }

        $stmt = $conn->prepare("SELECT * FROM files WHERE id = ?");
        $stmt->bind_param("i", $mediaId);
        $stmt->execute();
        $file = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$file || !is_watermarkable_image($file['filepath'])) {
            return null;
        }

        $watermarkDir = dirname($file['filepath']) . '/watermarked';
        $destPath = $watermarkDir . '/wm_' . basename($file['filepath']);

        $success = apply_watermark_to_image($file['filepath'], $destPath, $settings);
        if (!$success) {
            return null;
        }

        $snapshot = json_encode($settings);
        $stmt = $conn->prepare(
            "INSERT INTO file_watermarks (media_id, watermarked_path, applied_by, settings_snapshot)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE watermarked_path = VALUES(watermarked_path),
                                     applied_by = VALUES(applied_by),
                                     settings_snapshot = VALUES(settings_snapshot),
                                     applied_at = CURRENT_TIMESTAMP"
        );
        $stmt->bind_param("isss", $mediaId, $destPath, $actorId, $snapshot);
        $stmt->execute();
        $stmt->close();

        record_audit($conn, [
            'module'      => 'watermark',
            'action'      => 'WATERMARK_APPLIED',
            'target_type' => 'file',
            'target_id'   => $mediaId,
            'description' => "Applied watermark to \"{$file['filename']}\" (position: {$settings['position']}, opacity: {$settings['opacity']}%)",
        ]);

        notify_users($conn, [
            'recipient_id' => $actorId,
            'type'         => 'watermark_done',
            'title'        => 'Watermark completed',
            'message'      => "\"{$file['filename']}\" has been watermarked and is ready for download.",
            'link'         => "watermark_preview.php?media_id={$mediaId}",
        ]);

        return $destPath;
    }
}

if (!function_exists('get_file_watermark')) {
    function get_file_watermark(mysqli $conn, int $mediaId): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM file_watermarks WHERE media_id = ?");
        $stmt->bind_param("i", $mediaId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}
