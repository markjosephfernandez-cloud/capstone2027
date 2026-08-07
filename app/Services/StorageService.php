<?php

namespace App\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Storage;
use RuntimeException;

/**
 * Keeps all client-controlled file operations inside configured storage roots.
 */
class StorageService
{
    public function __construct(private readonly Storage $config = new Storage())
    {
    }

    public function root(): string
    {
        $root = $this->config->root();
        if (! is_dir($root) && ! mkdir($root, 0775, true) && ! is_dir($root)) {
            throw new RuntimeException('Unable to initialise the media storage directory.');
        }

        $resolved = realpath($root);
        if ($resolved === false) {
            throw new RuntimeException('Unable to resolve the media storage directory.');
        }

        return $resolved;
    }

    public function normalizeRelativePath(?string $path, bool $allowEmpty = true): string
    {
        $path = str_replace('\\', '/', trim((string) $path));
        $path = trim($path, '/');

        if ($path === '') {
            if ($allowEmpty) {
                return '';
            }

            throw new RuntimeException('A folder path is required.');
        }

        if (str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
            throw new RuntimeException('The folder path is invalid.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || ! preg_match('/^[\\pL\\pN][\\pL\\pN ._()\\-]{0,119}$/u', $segment)) {
                throw new RuntimeException('The folder path contains an invalid name.');
            }
        }

        return $path;
    }

    public function directory(string $relativePath = '', bool $create = false): string
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        $root         = $this->root();
        $target       = $root . ($relativePath === '' ? '' : DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        if ($create && ! is_dir($target) && ! mkdir($target, 0775, true) && ! is_dir($target)) {
            throw new RuntimeException('Unable to create the requested folder.');
        }

        $resolved = realpath($target);
        if ($resolved === false || ! is_dir($resolved) || ! $this->isWithin($resolved, $root)) {
            throw new RuntimeException('The requested folder is outside the managed storage.');
        }

        return $resolved;
    }

    /** @return array{filename: string, filepath: string, mimeType: string, size: int} */
    public function storeUpload(UploadedFile $file, string $relativeDirectory = ''): array
    {
        if (! $file->isValid() || $file->hasMoved()) {
            $reason = trim($file->getErrorString());
            throw new RuntimeException($reason === ''
                ? 'The uploaded file is invalid.'
                : 'The upload was rejected: ' . $reason);
        }

        if ($file->getSize() > $this->config->maxUploadBytes()) {
            throw new RuntimeException('The uploaded file exceeds the permitted size.');
        }

        $mimeType = (string) $file->getMimeType();
        if (! in_array($mimeType, $this->config->allowedMimeTypes(), true)) {
            throw new RuntimeException('This file type is not allowed.');
        }

        $directory = $this->directory($relativeDirectory, true);
        $filename  = $this->safeFilename($file->getClientName());
        $filename  = $this->availableFilename($directory, $filename);

        $file->move($directory, $filename);
        $filepath = realpath($directory . DIRECTORY_SEPARATOR . $filename);
        if ($filepath === false || ! $this->isWithin($filepath, $this->root())) {
            throw new RuntimeException('The uploaded file could not be stored safely.');
        }

        return [
            'filename' => $filename,
            'filepath' => $filepath,
            'mimeType' => $mimeType,
            'size'     => (int) filesize($filepath),
        ];
    }

    public function resolveDatabaseFile(string $storedPath): string
    {
        if ($storedPath === '' || str_contains($storedPath, "\0")) {
            throw new RuntimeException('The stored file path is invalid.');
        }

        $resolved = realpath($storedPath);
        if ($resolved === false || ! is_file($resolved)) {
            throw new RuntimeException('The file is unavailable on the configured storage.');
        }

        foreach ($this->allowedRoots() as $root) {
            if ($this->isWithin($resolved, $root)) {
                return $resolved;
            }
        }

        throw new RuntimeException('The stored file is outside the managed storage.');
    }

    public function renameDatabaseFile(string $storedPath, string $newFilename): string
    {
        $source      = $this->resolveDatabaseFile($storedPath);
        $newFilename = $this->safeFilename($newFilename);
        $destination = dirname($source) . DIRECTORY_SEPARATOR . $newFilename;

        if (file_exists($destination)) {
            throw new RuntimeException('A file with that name already exists.');
        }
        if (! rename($source, $destination)) {
            throw new RuntimeException('The file could not be renamed.');
        }

        return $destination;
    }

    public function moveDatabaseFile(string $storedPath, string $relativeDirectory): string
    {
        $source      = $this->resolveDatabaseFile($storedPath);
        $destination = $this->directory($relativeDirectory, true) . DIRECTORY_SEPARATOR . basename($source);

        if (file_exists($destination)) {
            throw new RuntimeException('A file with the same name already exists in that folder.');
        }
        if (! rename($source, $destination)) {
            throw new RuntimeException('The file could not be moved.');
        }

        return $destination;
    }

    public function relativePath(string $absolutePath): ?string
    {
        $resolved = realpath($absolutePath);
        $root     = $this->root();
        if ($resolved === false || ! $this->isWithin($resolved, $root)) {
            return null;
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($resolved, strlen($root)), "\\/"));
    }

    /** @return list<string> */
    private function allowedRoots(): array
    {
        $roots = [$this->root()];
        foreach ($this->config->legacyRoots() as $legacyRoot) {
            $resolved = realpath($legacyRoot);
            if ($resolved !== false && is_dir($resolved)) {
                $roots[] = $resolved;
            }
        }

        return array_values(array_unique($roots));
    }

    private function safeFilename(string $filename): string
    {
        $filename = trim(str_replace(['/', '\\', "\0"], '_', $filename));
        $filename = preg_replace('/[^\\pL\\pN ._()\\-]/u', '_', $filename) ?? '';
        $filename = trim($filename, '. ');

        if ($filename === '' || mb_strlen($filename) > 180) {
            throw new RuntimeException('The filename is invalid.');
        }

        return $filename;
    }

    private function availableFilename(string $directory, string $filename): string
    {
        $candidate = $filename;
        $base      = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $counter   = 1;

        while (file_exists($directory . DIRECTORY_SEPARATOR . $candidate)) {
            $candidate = $base . ' (' . $counter++ . ')' . ($extension === '' ? '' : '.' . $extension);
        }

        return $candidate;
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = rtrim(strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path)), DIRECTORY_SEPARATOR);
        $root = rtrim(strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root)), DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
