<?php

namespace App\Libraries;

/** Convert stored UTC dates and filesystem timestamps only at display time. */
final class DisplayTime
{
    public static function format(string|int|null $value, string $format = 'M d, Y g:i A', string $fallback = '—'): string
    {
        if (is_string($value)) $value = trim($value);
        if ($value === null || $value === '' || $value === 'Unknown' || $value === '0000-00-00 00:00:00') return $fallback;
        try {
            $date = is_int($value)
                ? new \DateTimeImmutable('@' . $value)
                : new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return $date->setTimezone(new \DateTimeZone('Asia/Manila'))->format($format);
        } catch (\Exception) {
            return $fallback;
        }
    }
}
