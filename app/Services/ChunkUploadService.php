<?php

namespace App\Services;

use App\Libraries\AssembledUpload;
use RuntimeException;

/** Streams small HTTP uploads into a private file; receipts make retries idempotent. */
final class ChunkUploadService
{
    public const MAX_CHUNK_BYTES = 4194304;
    private const RETENTION_SECONDS = 86400;

    public function __construct(private readonly string $root = WRITEPATH . 'upload-chunks')
    {
    }

    /**
     * @param array<string,string> $metadata
     * @param callable(AssembledUpload):array $complete
     */
    public function accept(int $owner, string $id, int $total, int $offset, string $chunkPath, array $metadata, callable $complete): array
    {
        if ($owner < 1 || ! preg_match('/^[a-f0-9]{32}$/', $id) || $total < 1 || $offset < 0 || $offset >= $total) {
            throw new RuntimeException('The upload identifier or size is invalid.');
        }
        $length = filesize($chunkPath);
        if ($length === false || $length < 1 || $length > self::MAX_CHUNK_BYTES || $length > $total - $offset) {
            throw new RuntimeException('The upload part has an invalid size.');
        }
        $name = $metadata['name'] ?? '';
        if ($name === '' || strlen($name) > 255 || strpbrk($name, "/\\\0") !== false) {
            throw new RuntimeException('The uploaded filename is invalid.');
        }
        if (! is_dir($this->root) && ! @mkdir($this->root, 0770, true) && ! is_dir($this->root)) {
            throw new RuntimeException('Temporary upload storage is unavailable.');
        }
        $key = hash('sha256', $owner . ':' . $id);
        $base = $this->root . '/' . $key;
        $lock = fopen($base . '.lock', 'c+b');
        if ($lock === false) {
            throw new RuntimeException('The upload could not be locked.');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('The upload is busy. Try again.');
            }
            $exists = is_file($base . '.json');
            $state = $exists ? json_decode(file_get_contents($base . '.json'), true) : null;
            if ($exists && ! is_array($state)) {
                throw new RuntimeException('Upload progress is unavailable. Please restart this file.');
            }
            if (! empty($state['cancelled'])) {
                throw new RuntimeException('This upload was cancelled. Please start a new upload.');
            }
            if ($state === null) {
                if ($offset !== 0) {
                    throw new RuntimeException('The upload expired. Please restart this file.');
                }
                $state = ['total' => $total, 'metadata' => $metadata, 'offset' => 0];
            }
            if ($state['total'] !== $total || $state['metadata'] !== $metadata) {
                throw new RuntimeException('Upload details changed. Please restart this file.');
            }
            if (isset($state['result'])) {
                return $state['result'];
            }
            if (! empty($state['processing'])) {
                throw new RuntimeException('This upload was already submitted for processing. Check File Management before retrying.');
            }
            $hash = hash_file('sha256', $chunkPath);
            if ($offset !== $state['offset']) {
                if (($state['last_offset'] ?? -1) === $offset && ($state['last_hash'] ?? '') === $hash && $state['offset'] === $offset + $length) {
                    return ['status' => 200, 'payload' => ['ok' => true, 'complete' => false, 'nextOffset' => $state['offset']]];
                }
                throw new RuntimeException('Upload parts arrived out of order. Please restart this file.');
            }
            $output = fopen($base . '.part', 'c+b');
            $input = fopen($chunkPath, 'rb');
            if ($output === false || $input === false) {
                if (is_resource($output)) {
                    fclose($output);
                }
                if (is_resource($input)) {
                    fclose($input);
                }
                throw new RuntimeException('The upload part could not be opened.');
            }
            try {
                // Discard a partial write from a failed request before retrying it.
                if (fstat($output)['size'] < $offset || ! ftruncate($output, $offset) || fseek($output, $offset) !== 0) {
                    throw new RuntimeException('The temporary upload is incomplete. Please restart this file.');
                }
                if (stream_copy_to_stream($input, $output, $length) !== $length || ! fflush($output)) {
                    ftruncate($output, $offset);
                    throw new RuntimeException('The upload could not be written. Check available disk space.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            $state['last_offset'] = $offset;
            $state['last_hash'] = $hash;
            $state['offset'] = $offset + $length;
            $state['processing'] = $state['offset'] === $total;
            $this->saveState($base, $state);
            if (! $state['processing']) {
                return ['status' => 200, 'payload' => ['ok' => true, 'complete' => false, 'nextOffset' => $state['offset']]];
            }
            clearstatcache(true, $base . '.part');
            try {
                $state['result'] = $complete(new AssembledUpload($base . '.part', $name));
                $state['result']['payload']['complete'] = true;
                $this->saveState($base, $state);
                return $state['result'];
            } finally {
                if (is_file($base . '.part')) {
                    @unlink($base . '.part');
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Cancel only this owner's unfinished upload, retaining a marker for late requests. */
    public function cancel(int $owner, string $id): array
    {
        if ($owner < 1 || ! preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new RuntimeException('The upload identifier is invalid.');
        }
        if (! is_dir($this->root) && ! @mkdir($this->root, 0770, true) && ! is_dir($this->root)) {
            throw new RuntimeException('Temporary upload storage is unavailable.');
        }
        $base = $this->root . '/' . hash('sha256', $owner . ':' . $id);
        $lock = fopen($base . '.lock', 'c+b');
        if ($lock === false) {
            throw new RuntimeException('The upload could not be locked.');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('The upload is busy. Try again.');
            }
            $state = is_file($base . '.json') ? json_decode(file_get_contents($base . '.json'), true) : null;
            if (isset($state['result'])) {
                return ['cancelled' => false, 'complete' => true, 'result' => $state['result']['payload']];
            }
            if (! empty($state['processing'])) {
                return ['cancelled' => false, 'processing' => true];
            }
            $this->saveState($base, ['cancelled' => true]);
            if (is_file($base . '.part') && ! @unlink($base . '.part')) {
                throw new RuntimeException('The upload stopped, but temporary data could not be removed.');
            }
            return ['cancelled' => true, 'complete' => false];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function saveState(string $base, array $state): void
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        if (file_put_contents($base . '.json.tmp', $json) !== strlen($json) || ! @rename($base . '.json.tmp', $base . '.json')) {
            throw new RuntimeException('Upload progress could not be saved. Check available disk space.');
        }
    }

    /** Remove stale partial files and receipts without interfering with an active upload. */
    public function pruneExpired(): void
    {
        if (! is_dir($this->root)) {
            return;
        }
        $removed = 0;
        foreach (new \DirectoryIterator($this->root) as $entry) {
            if (! $entry->isFile() || ! preg_match('/^[a-f0-9]{64}\.json$/', $entry->getFilename()) || $entry->getMTime() >= time() - self::RETENTION_SECONDS) {
                continue;
            }
            $base = substr($entry->getPathname(), 0, -5);
            $lock = fopen($base . '.lock', 'c+b');
            if ($lock === false) {
                continue;
            }
            try {
                if (! flock($lock, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                clearstatcache(true, $base . '.json');
                if (is_file($base . '.json') && filemtime($base . '.json') < time() - self::RETENTION_SECONDS) {
                    if (is_file($base . '.part')) {
                        @unlink($base . '.part');
                    }
                    @unlink($base . '.json');
                    $removed++;
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            // Lock files stay in place so waiting requests always share the same lock.
            if ($removed >= 20) break;
        }
    }
}
