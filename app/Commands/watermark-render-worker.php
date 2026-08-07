<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use App\Services\WatermarkImageRenderer;

$payload = json_decode(stream_get_contents(STDIN), true);
if (! is_array($payload)) {
    fwrite(STDOUT, json_encode(['written' => false, 'reason' => 'Invalid watermark render request.']));
    exit(1);
}

$source = (string) ($payload['source'] ?? '');
$logo = (string) ($payload['logo'] ?? '');
$destination = (string) ($payload['destination'] ?? '');
$extension = (string) ($payload['extension'] ?? '');
$settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];

if (! is_file($source) || ! is_readable($source) || ! is_file($logo) || ! is_readable($logo) || ! is_dir(dirname($destination))) {
    fwrite(STDOUT, json_encode(['written' => false, 'reason' => 'The watermark render paths are unavailable.']));
    exit(1);
}

$result = (new WatermarkImageRenderer())->render($source, $logo, $destination, $extension, $settings);
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES));
exit(($result['written'] ?? false) ? 0 : 1);
