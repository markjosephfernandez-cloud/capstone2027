<?php

namespace App\Services;

/** Adapts confined server paths to the Local Library's presentation contract. */
final class ServerLibraryPresentation
{
    public static function token(string $path): string
    {
        if ((new \Config\Storage())->centralized()) {
            $key = (new ServerLibraryService())->catalogSourceKey();
            $row = db_connect()->table('server_media')->where('source_key',$key)->where('path_hash',hash('sha256',$path))->where('is_deleted',0)->get()->getRowArray();
            if ($row) return 'm' . $row['id'];
        }
        return bin2hex($path);
    }

    public static function path(string $token): string
    {
        if ((new \Config\Storage())->centralized() && preg_match('/^m([1-9][0-9]*)$/D',$token,$match)) {
            $row = db_connect()->table('server_media')->where('id',$match[1])->where('source_key',(new ServerLibraryService())->catalogSourceKey())->where('is_deleted',0)->get()->getRowArray();
            if (!$row) throw new \RuntimeException('This server item is unavailable.');
            return $row['path'];
        }
        if ($token === '' || strlen($token) > 8192 || strlen($token) % 2 !== 0 || !ctype_xdigit($token)) {
            throw new \RuntimeException('Choose a valid server item.');
        }
        return hex2bin($token);
    }

    public static function data(array $data): array
    {
        $folder = (string) ($data['folder'] ?? '');
        $folders = []; $files = []; $options = []; $crumbs = []; $ancestor = '';
        foreach ($folder === '' ? [] : explode('/', $folder) as $part) {
            $ancestor .= ($ancestor === '' ? '' : '/') . $part;
            $crumbs[] = ['id'=>$ancestor, 'name'=>$part];
            $options[$ancestor] = ['id'=>$ancestor, 'display_name'=>$ancestor];
        }
        foreach ($data['items'] ?? [] as $item) {
            if ($item['directory']) {
                $folders[] = ['id'=>$item['path'], 'name'=>$item['name'], 'file_count'=>0, 'subfolder_count'=>0, 'created_at'=>empty($item['modified_at']) ? null : gmdate('Y-m-d H:i:s', (int) $item['modified_at'])];
                $options[$item['path']] = ['id'=>$item['path'], 'display_name'=>$item['path']];
            } else {
                $parent = dirname($item['path']);
                $files[] = ['id'=>self::token($item['path']), 'filename'=>$item['name'], 'display_title'=>$item['name'],
                    'filetype'=>$item['extension'], 'size'=>$item['size'], 'dateupload'=>$item['uploaded_at'] ?? (empty($item['modified_at']) ? null : gmdate('Y-m-d H:i:s', (int) $item['modified_at'])),
                    'tags'=>implode(', ', $item['tags']), 'classification'=>$item['classification'],
                    'containing_folder_id'=>$parent === '.' ? null : $parent, 'folder_display_path'=>$parent === '.' ? 'Root Library' : 'Root Library / ' . $parent];
            }
        }
        $groups = array_unique(array_column($data['duplicates'] ?? [], 'original'));
        return ['files'=>$files, 'folders'=>$folders, 'folderOptions'=>array_values($options),
            'folderId'=>$folder === '' ? null : $folder, 'selectedFolder'=>$folder === '' ? null : ['id'=>$folder, 'name'=>basename($folder)],
            'selectedFolderPath'=>$folder === '' ? 'All media' : $folder, 'folderBreadcrumbs'=>$crumbs,
            'stage'=>'', 'distribution'=>'', 'locatedFileId'=>$data['locatedFileId'] ?? '',
            'fileTypes'=>array_map(static fn ($type) => ['filetype'=>$type], array_keys($data['fileTypes'] ?? [])),
            'duplicateCount'=>count($groups)];
    }
}
