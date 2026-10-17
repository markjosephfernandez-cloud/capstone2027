<?php

namespace App\Services;

use RuntimeException;

final class VideoMimeType
{
    public const HEADER_BYTES = 65536;

    /** Inspect a bounded header, never let libmagic scan a large video file. */
    public static function detect(string $path): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('The video file is unavailable.');
        try {
            $header = fread($handle, self::HEADER_BYTES);
        } finally {
            fclose($handle);
        }
        if ($header === false || $header === '') throw new RuntimeException('The video file is empty or unreadable.');
        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($header) ?: 'application/octet-stream';
    }
}
