<?php

namespace App\Libraries;

use RuntimeException;

final class MediaMimeType
{
    public static function forFile(string $path): string
    {
        // Bound reads from mounted shares; never infer trust from the extension.
        $header = @file_get_contents($path, false, null, 0, 65536);
        if ($header === false || $header === '') throw new RuntimeException('Server file unavailable.');
        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($header) ?: 'application/octet-stream';
    }
}
