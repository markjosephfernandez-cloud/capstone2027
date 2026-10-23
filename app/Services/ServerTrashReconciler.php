<?php

namespace App\Services;

use RuntimeException;

/** Remove stale listing references after successful physical Trash deletion. */
final class ServerTrashReconciler
{
    public function __construct(private readonly ServerLibraryService $library)
    {
    }

    /** @return list<string> Paths whose missing originals were confirmed. */
    public function reconcile(array $record): array
    {
        $db = db_connect();
        $source = $this->library->catalogSourceKey();
        $path = (string) ($record['path'] ?? '');
        if (! empty($record['original_unknown'])) {
            // Only our exact generated prefix may be removed; never guess a path
            // from a partial filename or select arbitrarily among duplicates.
            $name = (string) ($record['stored_name'] ?? $path);
            $name = preg_replace('/^[a-f0-9]{32}--/', '', $name);
            $candidates = [];
            if ($db->tableExists('server_library_index')) {
                foreach ($db->table('server_library_index')->select('path')
                    ->where('source_key', $source)->where('name', $name)->limit(2)->get()->getResultArray() as $row) {
                    $candidates[$row['path']] = true;
                }
            }
            if ($db->tableExists('files')) {
                foreach ($db->table('files')->select('filepath')->where('filename', $name)
                    ->where('is_deleted', 0)->like('filepath', 'server-library/', 'after')
                    ->limit(2)->get()->getResultArray() as $row) {
                    $candidates[substr($row['filepath'], strlen('server-library/'))] = true;
                }
            }
            if (count($candidates) !== 1) {
                return [];
            }
            $path = (string) array_key_first($candidates);
        }
        $path = $this->library->relative($path);
        if ($path === '') {
            return [];
        }
        // A missing mount or unreadable directory is not proof of deletion.
        // Enumerate the reachable parent rather than relying on file_exists().
        $parent = dirname($path) === '.' ? '' : dirname($path);
        try {
            $directory = $this->library->resolve($parent, true);
        } catch (RuntimeException) {
            return [];
        }
        $entries = @scandir($directory);
        if ($entries === false || in_array(basename($path), $entries, true)) {
            return [];
        }

        $recursive = (bool) ($record['directory'] ?? true);
        $db->transBegin();
        try {
            (new CentralMediaService($this->library))->markDeleted($path, true, [], $recursive);
            if ($db->tableExists('files')) {
                $portable = 'server-library/' . $path;
                $query = $db->table('files')->where('is_deleted', 0);
                if ($recursive) {
                    $query->groupStart()->where('filepath', $portable)->orLike('filepath', $portable . '/', 'after')->groupEnd();
                } else {
                    $query->where('filepath', $portable);
                }
                $query->update(['is_deleted' => 1]);
            }
            foreach (['server_library_index', 'server_library_scan_queue'] as $table) {
                if ($db->tableExists($table)) {
                    $query = $db->table($table)->where('source_key', $source);
                    if ($recursive) {
                        $query->groupStart()->where('path', $path)->orLike('path', $path . '/', 'after')->groupEnd();
                    } else {
                        $query->where('path_hash', hash('sha256', $path));
                    }
                    $query->delete();
                }
            }
            if (! $db->transStatus()) {
                throw new RuntimeException('Deleted server item could not be removed from the media listings.');
            }
            $db->transCommit();
        } catch (\Throwable $error) {
            $db->transRollback();
            throw $error;
        }
        $this->library->invalidateCounts($parent);
        return [$path];
    }
}
