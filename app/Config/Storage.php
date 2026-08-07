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
    public function root(): string
    {
        return rtrim((string) env('greyhound.storageRoot', WRITEPATH . 'uploads'), "\\/");
    }

    /** @return list<string> */
    public function legacyRoots(): array
    {
        $value = (string) env('greyhound.legacyStorageRoots', '');

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    public function maxUploadBytes(): int
    {
        return max(1, (int) env('greyhound.maxUploadBytes', 104857600));
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
}
