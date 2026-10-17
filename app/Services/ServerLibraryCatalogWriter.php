<?php
namespace App\Services;

/** Publish successful app writes without waiting for a full NAS scan. */
class ServerLibraryCatalogWriter
{
    public function __construct(private readonly ServerLibraryService $library) {}

    /** Relocate cached descendants without re-reading a large NAS directory tree. */
    public function move(string $old, string $new): void
    {
        $old = $this->library->relative($old);
        $new = $this->library->relative($new);
        if ($old === '' || $new === '' || $new === $old || str_starts_with($new, $old . '/')) {
            throw new \RuntimeException('Invalid catalog move destination.');
        }
        $db = db_connect();
        if ($db->DBDriver === 'MySQLi' && $db->transDepth === 0) { $db->close(); $db->initialize(); }
        if (!$db->tableExists('server_library_index')) return;
        $source = $this->library->catalogSourceKey();
        $db->transStart();
        try {
            // Walk indexed parent hashes instead of scanning every path in the catalog.
            // Include the old parent even when the folder row itself is not indexed yet.
            $parents = [$old];
            $root = $db->table('server_library_index')->where('source_key', $source)
                ->where('path_hash', hash('sha256', $old))->get()->getResultArray();
            $relocate = function (array $rows) use ($db, $old, $new): void {
                if (!$rows) return;
                $db->table('server_library_index')->whereIn('id', array_column($rows, 'id'))->delete();
                foreach ($rows as &$row) {
                    unset($row['id']);
                    $row['path'] = $new . substr($row['path'], strlen($old));
                    $row['path_hash'] = hash('sha256', $row['path']);
                    $parent = dirname($row['path']);
                    $row['parent_hash'] = hash('sha256', $parent === '.' ? '' : $parent);
                    $row['name'] = basename($row['path']);
                    $row['indexed_at'] = gmdate('Y-m-d H:i:s');
                }
                unset($row);
                $db->table('server_library_index')->onConstraint(['source_key', 'path_hash'])->upsertBatch($rows);
            };
            $relocate($root);
            while ($parents) {
                $batch = array_splice($parents, 0, 50);
                do {
                    $rows = $db->table('server_library_index')->where('source_key', $source)
                        ->whereIn('parent_hash', array_map(static fn ($path) => hash('sha256', $path), $batch))
                        ->limit(200)->get()->getResultArray();
                    foreach ($rows as $row) if ($row['is_dir']) $parents[] = $row['path'];
                    $relocate($rows);
                } while (count($rows) === 200);
            }
            $db->table('server_library_scan_queue')->where('source_key',$source)->groupStart()
                ->where('path',$old)->orLike('path',$old.'/','after')->groupEnd()->delete();
            $db->transComplete();
            if (!$db->transStatus()) throw new \RuntimeException('Could not relocate catalog entries.');
        } catch (\Throwable $error) { $db->transRollback(); throw $error; }
    }

    public function sync(array $paths, array $metadata = []): void
    {
        $rows = [];
        $source = $this->library->catalogSourceKey();
        foreach ($paths as $path) {
            $path = $this->library->relative($path);
            // Include parents created by nested folder uploads.
            while ($path !== '') {
                if (isset($rows[$path])) break;
                try { $absolute = $this->library->resolve($path); }
                catch (\RuntimeException) { $absolute = $this->library->resolve($path, true); }
                clearstatcache(true, $absolute);
                $stat = @stat($absolute);
                if (!$stat) throw new \RuntimeException('Cannot read the saved server item.');
                $directory = is_dir($absolute);
                $parent = dirname($path) === '.' ? '' : dirname($path);
                $passport = $metadata['media'][$path]['passport'] ?? [];
                $rows[$path] = ['source_key'=>$source, 'path_hash'=>hash('sha256', $path), 'parent_hash'=>hash('sha256', $parent),
                    'path'=>$path, 'name'=>basename($path), 'is_dir'=>$directory ? 1 : 0, 'size'=>$directory ? null : $stat['size'],
                    'modified_at'=>$stat['mtime'], 'extension'=>mb_substr(strtolower(pathinfo($path, PATHINFO_EXTENSION)),0,32),
                    'tags'=>json_encode($metadata['tags'][$path] ?? [], JSON_THROW_ON_ERROR),
                    'classification'=>$passport['classification'] ?? $passport['event_name'] ?? '',
                    'date_taken'=>mb_substr((string)($passport['date_taken'] ?? ''),0,100),
                    'uploaded_at'=>$metadata['media'][$path]['uploaded_at'] ?? null, 'indexed_at'=>gmdate('Y-m-d H:i:s')];
                $path = $parent;
            }
        }
        if (!$rows) return;
        $db = db_connect();
        // A NAS operation may outlive an idle MySQL connection.
        if ($db->DBDriver === 'MySQLi' && $db->transDepth === 0) { $db->close(); $db->initialize(); }
        if (!$db->tableExists('server_library_index')) return;
        $db->transStart();
        try {
            $state = $db->table('server_library_sources')->where('source_key', $source)->get()->getRowArray();
            foreach ($rows as &$row) $row['run_id'] = $state['run_id'] ?? '';
            unset($row);
            $db->table('server_library_index')->onConstraint(['source_key','path_hash'])->upsertBatch(array_values($rows));
            $db->transComplete();
            if (!$db->transStatus()) throw new \RuntimeException('Could not update the server catalog.');
            if ((new \Config\Storage())->centralized()) {
                $identities = new CentralMediaService($this->library);
                foreach ($rows as $row) $identities->identity($row['path'],(bool)$row['is_dir']);
            }
        } catch (\Throwable $error) {
            if ($db->transDepth > 0) $db->transRollback();
            throw $error;
        }
    }
}
