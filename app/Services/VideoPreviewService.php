<?php

namespace App\Services;

use RuntimeException;

/** Private browser-compatible copies; uploaded originals are never rewritten. */
class VideoPreviewService
{
    public function __construct(private readonly string $cacheDirectory = WRITEPATH . 'cache/video-previews')
    {
    }

    private function cacheKey(string $source): string
    {
        clearstatcache(true, $source);
        $stat = @stat($source);
        if ($stat === false || ! is_file($source)) throw new RuntimeException('Video not found.');
        $key = hash('sha256', 'v1|' . $source . '|' . $stat['mtime'] . '|' . $stat['ctime'] . '|' . $stat['size']);
        if (! is_dir($this->cacheDirectory) && ! @mkdir($this->cacheDirectory, 0770, true) && ! is_dir($this->cacheDirectory)) {
            throw new RuntimeException('Video preview storage is unavailable.');
        }
        return $key;
    }

    /** Extract a small JPEG directly, without downloading/transcoding the video. */
    public function thumbnailForFile(string $source): string
    {
        $key = $this->cacheKey($source);
        $target = $this->cacheDirectory . '/' . $key . '-poster.jpg';
        if (is_file($target)) return $target;
        $lock = fopen($target . '.lock', 'c');
        if ($lock === false) throw new RuntimeException('Video thumbnail is unavailable.');
        $temporary = null;
        $previousTimeLimit = $this->allowPreparationTime(100); // Lock + two 30-second frame attempts.
        try {
            $deadline = microtime(true) + 30;
            while (! flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) > $deadline) throw new RuntimeException('Video thumbnail is still being prepared.');
                usleep(100000);
            }
            if (is_file($target)) return $target;
            $temporary = $target . '.' . bin2hex(random_bytes(6)) . '.tmp.jpg';
            foreach (['1', '0'] as $seek) {
                try {
                $this->run([
                    $this->binary('ffmpeg'), '-nostdin', '-v', 'error', '-y', '-threads', '2',
                    '-ss', $seek, '-i', $source, '-map', '0:v:0', '-an', '-sn', '-dn',
                    '-vf', 'scale=w=min(640\,iw):h=min(360\,ih):force_original_aspect_ratio=decrease',
                    '-frames:v', '1', '-threads', '1', '-q:v', '3', '-update', '1', $temporary,
                ], 30);
                } catch (RuntimeException $exception) {
                    // Some decoders fail when seeking beyond a very short clip.
                    if ($seek === '0') throw $exception;
                    continue;
                }
                clearstatcache(true, $temporary);
                if (is_file($temporary) && filesize($temporary) > 0) break;
            }
            if (! is_file($temporary) || filesize($temporary) === 0 || ! rename($temporary, $target)) {
                throw new RuntimeException('Video thumbnail could not be created.');
            }
            return $target;
        } finally {
            if ($temporary !== null && is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
            $this->restoreTimeLimit($previousTimeLimit);
        }
    }

    public function forFile(string $source): string
    {
        $key = $this->cacheKey($source);
        $target = $this->cacheDirectory . '/' . $key . '.mp4';
        $compatible = $this->cacheDirectory . '/' . $key . '.original';
        if (is_file($target)) return $target;
        if (is_file($compatible)) return $source;

        // Concurrent metadata/range requests must not encode the same video twice.
        $lock = fopen($this->cacheDirectory . '/' . $key . '.lock', 'c');
        if ($lock === false) throw new RuntimeException('Video preview is unavailable.');
        $temporary = null;
        $previousTimeLimit = $this->allowPreparationTime(390); // Lock + probe + conversion, with cleanup time.
        try {
            $deadline = microtime(true) + 180;
            while (! flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) > $deadline) throw new RuntimeException('Video preview is still being prepared. Try again shortly.');
                usleep(100000);
            }
            if (is_file($target)) return $target;
            if (is_file($compatible)) return $source;
            $metadata = json_decode($this->run([
                $this->binary('ffprobe'), '-v', 'error', '-show_entries',
                'stream=codec_type,codec_name,pix_fmt:format=format_name', '-of', 'json', $source,
            ], 15), true);
            $videos = array_values(array_filter($metadata['streams'] ?? [], static fn ($stream) => ($stream['codec_type'] ?? '') === 'video'));
            $audios = array_values(array_filter($metadata['streams'] ?? [], static fn ($stream) => ($stream['codec_type'] ?? '') === 'audio'));
            if ($videos === []) throw new RuntimeException('This file does not contain a video track.');
            $video = $videos[0];
            $format = $metadata['format']['format_name'] ?? '';
            $mp4 = str_contains($format, 'mp4') && ($video['codec_name'] ?? '') === 'h264' && ($video['pix_fmt'] ?? '') === 'yuv420p';
            $webm = str_contains($format, 'webm') && in_array($video['codec_name'] ?? '', ['vp8', 'vp9'], true);
            $audioCompatible = $audios === [] || in_array($audios[0]['codec_name'] ?? '', $mp4 ? ['aac', 'mp3'] : ['opus', 'vorbis'], true);
            if (($mp4 || $webm) && $audioCompatible) {
                file_put_contents($compatible, '1');
                return $source;
            }

            $temporary = $target . '.' . bin2hex(random_bytes(6)) . '.tmp.mp4';
            $this->run([
                $this->binary('ffmpeg'), '-nostdin', '-v', 'error', '-y', '-threads', '2', '-i', $source,
                '-map', '0:v:0', '-map', '0:a:0?', '-sn', '-dn',
                '-vf', 'scale=w=min(1280\,iw):h=min(720\,ih):force_original_aspect_ratio=decrease:force_divisible_by=2,setsar=1',
                '-c:v', 'libx264', '-threads', '2', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p',
                '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $temporary,
            ], 180);
            if (! is_file($temporary) || filesize($temporary) === 0 || ! rename($temporary, $target)) {
                throw new RuntimeException('Video preview could not be saved.');
            }
            return $target;
        } finally {
            if ($temporary !== null && is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
            $this->restoreTimeLimit($previousTimeLimit);
        }
    }

    private function allowPreparationTime(int $seconds): int
    {
        $previous = (int) ini_get('max_execution_time');
        // Keep CLI/unlimited callers unchanged. The process and lock deadlines
        // still bound preparation; PHP must leave time to terminate and clean up.
        if ($previous > 0 && $previous < $seconds) set_time_limit($seconds);
        return $previous;
    }

    private function restoreTimeLimit(int $seconds): void
    {
        if ((int) ini_get('max_execution_time') !== $seconds) set_time_limit($seconds);
    }

    private function binary(string $name): string
    {
        $configured = (string) env('greyhound.' . $name . 'Binary', '');
        if ($configured !== '') return $configured;
        foreach (array_merge(explode(PATH_SEPARATOR, (string) getenv('PATH')), ['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin']) as $directory) {
            $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            if (is_file($path) && is_executable($path)) return $path;
        }
        throw new RuntimeException('Video preview conversion is unavailable.');
    }

    private function run(array $command, int $timeout): string
    {
        if (! function_exists('proc_open')) throw new RuntimeException('Video preview conversion is unavailable.');
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) throw new RuntimeException('Video preview conversion could not start.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + $timeout;
        try {
            do {
                $output .= stream_get_contents($pipes[1], 65536);
                // Drain stderr without collecting a potentially large decoder log.
                stream_get_contents($pipes[2], 65536);
                if (strlen($output) > 1048576 || microtime(true) > $deadline) {
                    proc_terminate($process, 9);
                    throw new RuntimeException('Video preview preparation timed out.');
                }
                $status = proc_get_status($process);
                if ($status['running']) usleep(10000);
            } while ($status['running']);
            $output .= stream_get_contents($pipes[1]);
            if ($status['exitcode'] !== 0) throw new RuntimeException('Video preview could not be prepared.');
            return $output;
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
}
