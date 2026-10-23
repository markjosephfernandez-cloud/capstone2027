<?php

namespace App\Services;

use RuntimeException;

/** Browser-safe derivatives; camera originals are never changed. */
class CameraImagePreviewService
{
    public const EXTENSIONS = ['cr2', 'cr3', 'crw', 'nef', 'nrw', 'arw', 'sr2', 'dng', 'orf', 'rw2', 'raf', 'pef', 'tif', 'tiff', 'heic', 'heif', 'avif', 'bmp'];
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', ...self::EXTENSIONS];

    public function __construct(private readonly string $cacheDirectory = WRITEPATH . 'cache/camera-previews')
    {
    }

    public static function supports(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    public function forFile(string $source, int $size = 1600): string
    {
        if (! self::supports($source) || ! in_array($size, [400, 1600], true)) {
            throw new RuntimeException('Unsupported image preview.');
        }
        clearstatcache(true, $source);
        $stat = @stat($source);
        if ($stat === false || ! is_file($source)) {
            throw new RuntimeException('The source image is unavailable.');
        }
        $key = hash('sha256', 'v1|' . $source . '|' . $stat['mtime'] . '|' . $stat['ctime'] . '|' . $stat['size'] . '|' . $size);
        $target = $this->cacheDirectory . '/' . $key . '.jpg';
        if (is_file($target)) {
            return $target;
        }
        if (PHP_OS_FAMILY !== 'Darwin' || !is_executable('/usr/bin/sips') || !function_exists('proc_open')) {
            throw new RuntimeException('Camera image conversion is unavailable on this server.');
        }
        if (!is_dir($this->cacheDirectory) && !@mkdir($this->cacheDirectory, 0770, true) && !is_dir($this->cacheDirectory)) {
            throw new RuntimeException('Image preview storage is unavailable.');
        }
        $lock = @fopen($target . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Image preview storage is unavailable.');
        }
        $temporary = null;
        try {
            // Never occupy another HTTP worker waiting for the same conversion.
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Image preview is being prepared. Try again shortly.');
            if (is_file($target)) {
                return $target;
            }
            $temporary = $target . '.' . bin2hex(random_bytes(6)) . '.tmp.jpg';
            $process = proc_open(['/usr/bin/sips', '-s', 'format', 'jpeg', '-Z', (string)$size, $source, '--out', $temporary],
                [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Image conversion could not start.');
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + 15;
            try {
                do {
                    stream_get_contents($pipes[1], 65536);
                    stream_get_contents($pipes[2], 65536);
                    $status = proc_get_status($process);
                    if (! $status['running']) {
                        break;
                    }
                    if (microtime(true) >= $deadline) {
                        proc_terminate($process, 9);
                        throw new RuntimeException('Image preview preparation timed out.');
                    }
                    usleep(10000);
                } while (true);
                if ($status['exitcode'] !== 0) {
                    throw new RuntimeException('This camera image could not be decoded.');
                }
            } finally {
                fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
            }
            $info = @getimagesize($temporary);
            if (!$info || $info['mime'] !== 'image/jpeg' || max($info[0], $info[1]) > $size || !@rename($temporary, $target)) {
                throw new RuntimeException('Image preview could not be saved.');
            }
            return $target;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
}
