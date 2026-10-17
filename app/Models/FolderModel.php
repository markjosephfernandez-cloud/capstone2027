<?php

namespace App\Models;

use CodeIgniter\Model;

class FolderModel extends Model
{
    protected $table         = 'folder';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['parent_id', 'name', 'description', 'path', 'created_at', 'publication_channel', 'published_at', 'published_by', 'is_deleted', 'deleted_at', 'deleted_by', 'deletion_reason', 'trash_root_id'];

    /** Keep a new folder separate from any older folder still in Trash. */
    public function availableStoragePath(string $relativePath): string
    {
        $storage = service('storage');
        $relativePath = $storage->normalizeRelativePath($relativePath, false);
        $records = $this->where('path', $relativePath)->findAll();
        $hasDeletedFolder = false;
        foreach ($records as $folder) {
            if ((int) $folder['is_deleted'] === 0) {
                throw new \RuntimeException('A folder with that name already exists.');
            }
            $hasDeletedFolder = true;
        }

        $root = $storage->root() . DIRECTORY_SEPARATOR;
        $target = $root . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (! $hasDeletedFolder) {
            if (file_exists($target) || is_link($target)) {
                throw new \RuntimeException('A folder with that name already exists.');
            }
            return $relativePath;
        }

        $parent = dirname($relativePath);
        $prefix = $parent === '.' ? '' : $parent . '/';
        $baseName = mb_substr(basename($relativePath), 0, 100);
        for ($suffix = 2; $suffix < 10000; ++$suffix) {
            $candidate = $prefix . $baseName . ' (' . $suffix . ')';
            $physical = $root . str_replace('/', DIRECTORY_SEPARATOR, $candidate);
            if (! file_exists($physical) && ! is_link($physical)
                && $this->where('path', $candidate)->countAllResults() === 0) {
                return $candidate;
            }
        }

        throw new \RuntimeException('An available storage location for the folder could not be generated.');
    }
}
