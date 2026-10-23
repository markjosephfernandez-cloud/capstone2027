<?php

namespace App\Libraries;

/** Presentation only: stored audit descriptions remain unchanged. */
final class UploadAuditDescription
{
    public static function format(string $description): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $description);
        $summary = array_shift($lines);
        if (! str_starts_with($summary, 'Server Library upload batch:')) {
            return $description;
        }

        $groups = [];
        $notes = [];
        foreach ($lines as $line) {
            if (! preg_match('/^(Uploaded|Failed): (.+)$/u', $line, $match)) {
                $notes[] = $line;
                continue;
            }
            $path = $match[2];
            $separator = strrpos($path, '/');
            $directory = $separator === false ? './' : substr($path, 0, $separator + 1);
            $filename = $separator === false ? $path : substr($path, $separator + 1);
            $groups[$directory][] = '  ' . ($match[1] === 'Failed' ? 'Failed: ' : '') . $filename;
        }
        if ($groups === []) {
            return $description;
        }

        $sections = [$summary];
        foreach ($groups as $directory => $filenames) {
            $sections[] = $directory . "\n" . implode("\n", $filenames);
        }
        if ($notes !== []) {
            $sections[] = implode("\n", $notes);
        }

        return implode("\n\n", $sections);
    }
}
