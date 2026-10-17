<?php

namespace App\Libraries;

/** Virtual channel folders for the published library. */
final class PublishedChannelGroups
{
    public static function group(array $folders, array $files): array
    {
        $groups = [];
        foreach (['folders' => $folders, 'files' => $files] as $kind => $items) {
            foreach ($items as $item) {
                $channel = trim((string) ($item['publication_channel'] ?? ''));
                $groups[$channel] ??= [
                    'channel' => $channel,
                    'label' => $channel === '' ? 'Unassigned channel' : $channel,
                    'folders' => [], 'files' => [], 'file_count' => 0, 'folder_count' => 0,
                ];
                $groups[$channel][$kind][] = $item;
                $groups[$channel]['file_count'] += $kind === 'folders' ? (int) ($item['file_count'] ?? 0) : 1;
                $groups[$channel]['folder_count'] += $kind === 'folders' ? 1 : 0;
            }
        }
        uasort($groups, static fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label']));
        return $groups;
    }
}
