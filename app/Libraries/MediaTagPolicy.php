<?php

namespace App\Libraries;

final class MediaTagPolicy
{
    public static function isVideo(array $file): bool
    {
        $type = strtolower(trim((string) ($file['filetype'] ?? $file['type'] ?? '')));
        $mime = strtolower((string) ($file['mime_type'] ?? ''));
        $extension = strtolower(pathinfo((string) ($file['filename'] ?? ''), PATHINFO_EXTENSION));
        return strtolower((string) ($file['media_kind'] ?? '')) === 'video'
            || $type === 'video' || str_starts_with($type, 'video/') || str_starts_with($mime, 'video/')
            || in_array($type, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'], true)
            || in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'], true);
    }

    public static function remove(string $tag, array $file): bool
    {
        $tag = trim($tag);
        return preg_match('/^(?:Media|Format)\s*:/i', $tag) === 1
            || strcasecmp($tag, 'AI Classified') === 0
            || strcasecmp($tag, 'AI Review Needed') === 0;
    }

    public static function normalize(string $tag, array $file = []): string
    {
        $tag = trim(preg_replace('/^(?:Year|Folder|Expression)\s*:\s*/i', '', trim($tag)) ?? '');
        return self::remove($tag, $file) ? '' : $tag;
    }

    public static function clean(string $tags, array $file): string
    {
        $clean = [];
        foreach (explode(',', $tags) as $tag) {
            $tag = self::normalize($tag, $file);
            if ($tag !== '' && ! isset($clean[mb_strtolower($tag)])) {
                $clean[mb_strtolower($tag)] = $tag;
            }
        }
        return implode(', ', array_values($clean));
    }
}
