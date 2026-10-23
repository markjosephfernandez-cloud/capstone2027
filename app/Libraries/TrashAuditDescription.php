<?php

namespace App\Libraries;

final class TrashAuditDescription
{
    /** Older external IDs encode the stored Trash name, not the original path. */
    public static function format(string $action, string $description): string
    {
        if (! in_array($action, ['SERVER_TRASH_DELETE', 'SERVER_TRASH_RESTORE'], true)
            || ! preg_match('/^Trash item external-((?:[a-f0-9]{2}){1,255})$/D', $description, $match)) {
            return $description;
        }
        $name = hex2bin($match[1]);
        if (! mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            return $description;
        }

        return ($action === 'SERVER_TRASH_DELETE' ? 'Permanently deleted from server Trash: ' : 'Restored from server Trash: ')
            . $name . ' (stored Trash name; original path unknown)';
    }
}
