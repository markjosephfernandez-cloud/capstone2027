<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * File-system boundaries for the Greyhound API.
 *
 * The API never accepts an absolute file path from a client. Configure the
 * root to the mounted media directory in each environment.
 */
class Storage extends BaseConfig
{
    public function centralized(): bool
    {
        return filter_var(env('greyhound.centralLibrary', false), FILTER_VALIDATE_BOOL);
    }

    public function root(): string
    {
        if ($this->centralized()) return $this->scanSourceRoot();
        return $this->configuredProjectStorageRoot('greyhound.storageRoot', 'writable/uploads');
    }

    public function departmentRoot(): string
    {
        if ($this->centralized()) return $this->scanSourceRoot() . '/DEPARTMENT';
        return $this->configuredProjectStorageRoot('greyhound.departmentStorageRoot', 'writable/Department');
    }

    /** @return list<string> */
    public function legacyRoots(): array
    {
        $value = (string) env('greyhound.legacyStorageRoots', '');

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    public function maxUploadBytes(): int
    {
        // Zero means no application-level per-file size limit.
        return max(0, (int) env('greyhound.maxUploadBytes', 0));
    }

    /**
     * Server-side inbox inspected by the iFound scanner. Keeping this outside
     * the managed library prevents a scan from discovering its own copies.
     */
    public function scanSourceRoot(): string
    {
        return rtrim((string) env('greyhound.scanSourceRoot', WRITEPATH . 'scan-inbox'), "\\/");
    }

    public function scannerEndpoint(): string
    {
        return rtrim((string) env('greyhound.scannerEndpoint', ''), "\\/");
    }

    public function scanDestination(): string
    {
        return trim((string) env('greyhound.scanDestination', 'iFound Scanned Files'), "\\/");
    }

    public function maxScanFiles(): int
    {
        return max(1, min(50000, (int) env('greyhound.maxScanFiles', 10000)));
    }

    public function scanBatchSize(): int
    {
        return max(1, min(1000, (int) env('greyhound.scanBatchSize', 10)));
    }

    /** @return list<string> */
    public function allowedMimeTypes(): array
    {
        return [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska',
            'video/webm', 'video/x-m4v',
            'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /**
     * Relative storage paths in .env are project-root relative. Resolving them
     * explicitly prevents web requests (whose working directory is public/)
     * and CLI maintenance commands from using different physical folders.
     */
    private function configuredProjectStorageRoot(string $environmentKey, string $default): string
    {
        $path = trim((string) env($environmentKey, $default));
        if ($path === '') {
            $path = $default;
        }

        if ($this->isAbsolutePath($path)) {
            return rtrim($path, "\\/");
        }

        return rtrim(ROOTPATH, "\\/") . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, ltrim(str_replace('\\', '/', $path), '/'));
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            || str_starts_with($path, '/')
            || str_starts_with($path, '\\\\');
    }
}
