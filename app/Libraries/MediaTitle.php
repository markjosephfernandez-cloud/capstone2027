<?php

namespace App\Libraries;

use InvalidArgumentException;

final class MediaTitle
{
    public static function validate(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Display Title is required.');
        }
        $title = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($title === '') {
            throw new InvalidArgumentException('Display Title is required.');
        }
        if (mb_strlen($title) > 150) {
            throw new InvalidArgumentException('Display Title may not exceed 150 characters.');
        }
        if (preg_match('/^[\pL\pN .,\'"!?():;&+\-\/#@\[\]\x{2018}\x{2019}\x{201C}\x{201D}\x{2013}\x{2014}]+$/u', $title) !== 1) {
            throw new InvalidArgumentException('Display Title may contain letters, numbers, spaces, and common punctuation only.');
        }
        return $title;
    }

    /** @param array<string, mixed> $file */
    public static function forFile(array $file): string
    {
        return trim((string) ($file['display_title'] ?? '')) ?: 'Untitled media';
    }
}
