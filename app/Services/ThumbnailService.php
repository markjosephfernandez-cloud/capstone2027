<?php

namespace App\Services;

use RuntimeException;

/** Builds private, reusable browsing images without modifying uploaded originals. */
class ThumbnailService
{
    public function __construct(private readonly string $cacheDirectory = WRITEPATH . 'cache/media-thumbnails')
    {
    }

    public function forFile(string $source): string
    {
        $previousExecutionLimit = (int) ini_get('max_execution_time');
        // Reading image headers (and camera conversion) can be slow on the
        // server share too. Adjust the budget before any source access, not
        // only before GD decoding, and restore it on every return/error path.
        if ($previousExecutionLimit > 0
            && (! function_exists('set_time_limit') || ! set_time_limit(0))) {
            throw new RuntimeException('The thumbnail processing time limit could not be adjusted.');
        }
        try {
            return $this->buildThumbnail($source);
        } finally {
            if ($previousExecutionLimit > 0) set_time_limit($previousExecutionLimit);
        }
    }

    protected function buildThumbnail(string $source): string
    {
        if (CameraImagePreviewService::supports($source)) {
            return (new CameraImagePreviewService($this->cacheDirectory . '/camera'))->forFile($source, 400);
        }
        clearstatcache(true, $source);
        $stat = @stat($source);
        if ($stat === false || ! is_file($source)) {
            throw new RuntimeException('The source image is unavailable.');
        }
        $key = hash('sha256', 'v1-400-75|' . $source . '|' . $stat['mtime'] . '|' . $stat['ctime'] . '|' . $stat['size']);
        $target = $this->cacheDirectory . '/' . $key . '.jpg';
        if (is_file($target)) {
            return $target;
        }
        if (! extension_loaded('gd')) {
            throw new RuntimeException('Image resizing is unavailable.');
        }
        $info = @getimagesize($source);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new RuntimeException('The source is not a supported image.');
        }
        // Camera photos need more decoding memory than their compressed file size.
        $pixels = (float) $info[0] * $info[1];
        if ($pixels > 40000000) {
            throw new RuntimeException('The source image is too large to resize.');
        }
        $decoder = match ($info['mime'] ?? '') {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/gif' => 'imagecreatefromgif',
            'image/webp' => 'imagecreatefromwebp',
            'image/bmp' => 'imagecreatefrombmp',
            default => '',
        };
        if ($decoder === '' || ! function_exists($decoder)) {
            throw new RuntimeException('The source is not a supported image.');
        }
        $original = null;
        $thumbnail = null;
        $temporary = null;
        $previousLimit = (string) ini_get('memory_limit');
        $limit = ini_parse_quantity($previousLimit);
        $memoryAdjusted = false;
        try {
            $requiredBytes = memory_get_usage(true) + $pixels * 8 + 16777216;
            if ($limit > 0 && $requiredBytes > $limit) {
                // Increase only this operation's budget, in 32 MB steps, up to 384 MB.
                $budgetMb = (int) (ceil($requiredBytes / 33554432) * 32);
                if ($budgetMb > 384 || ini_set('memory_limit', $budgetMb . 'M') === false) {
                    throw new RuntimeException('There is not enough memory to resize this image.');
                }
                $memoryAdjusted = true;
            }
            $original = @$decoder($source);
            if ($original === false) {
                throw new RuntimeException('The source image could not be decoded.');
            }
            $scale = min(1, 400 / max($info[0], $info[1]));
            $width = max(1, (int) round($info[0] * $scale));
            $height = max(1, (int) round($info[1] * $scale));
            $thumbnail = imagecreatetruecolor($width, $height);
            imagefill($thumbnail, 0, 0, imagecolorallocate($thumbnail, 255, 255, 255));
            imagecopyresampled($thumbnail, $original, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
            if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data($source, 'IFD0');
                $orientation = (int) ($exif['Orientation'] ?? 1);
                if (in_array($orientation, [2, 4, 5, 7], true)) {
                    imageflip($thumbnail, IMG_FLIP_HORIZONTAL);
                }
                $angle = match ($orientation) { 3, 4 => 180, 5, 8 => 90, 6, 7 => -90, default => 0 };
                if ($angle !== 0) {
                    $rotated = imagerotate($thumbnail, $angle, 0);
                    if ($rotated !== false) {
                        imagedestroy($thumbnail);
                        $thumbnail = $rotated;
                    }
                }
            }
            if (! is_dir($this->cacheDirectory) && ! @mkdir($this->cacheDirectory, 0775, true) && ! is_dir($this->cacheDirectory)) {
                throw new RuntimeException('Thumbnail storage is unavailable.');
            }
            $temporary = tempnam($this->cacheDirectory, 'thumb-');
            if ($temporary === false || ! imagejpeg($thumbnail, $temporary, 75) || ! @rename($temporary, $target)) {
                throw new RuntimeException('The thumbnail could not be saved.');
            }
            return $target;
        } finally {
            if ($original instanceof \GdImage) imagedestroy($original);
            if ($thumbnail !== null) imagedestroy($thumbnail);
            // PHP 8 releases GD memory when its objects are no longer referenced.
            unset($original, $thumbnail, $rotated);
            if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
            if ($memoryAdjusted) ini_set('memory_limit', $previousLimit);
        }
    }
}
