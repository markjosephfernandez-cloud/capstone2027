<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Storage;
use RuntimeException;

/** CLI-only scan with durable checkpoints and deletion only after a complete pass. */
class ServerLibraryIndexer
{
    public function __construct(private BaseConnection $db, private Storage $config) {}

    public function run(int $seconds = 0, bool $restart = false, ?callable $continue = null): array
    {
        $source = hash('sha256', rtrim($this->config->scanSourceRoot(), '/\\'));
        $lock = fopen(WRITEPATH . 'cache/server-index-' . $source . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            throw new RuntimeException('Another indexer is already running for this share.');
        }
        $library = new ServerLibraryService($this->config);
        $state = null;
        $handle = null;
        $handleCursor = null;
        try {
            $library->root();
            $metadata = (new ServerLibraryEditor($library, $this->config))->state();
            $state = $this->db->table('server_library_sources')->where('source_key',$source)->get()->getRowArray();
            if ($restart || !$state || in_array($state['status'], ['complete','partial'], true)) {
                $run = bin2hex(random_bytes(16));
                $this->db->transStart();
                $this->db->table('server_library_scan_queue')->where('source_key',$source)->delete();
                $this->db->table('server_library_scan_queue')->insert(['source_key'=>$source,'path'=>'','path_hash'=>hash('sha256',''),'entry_offset'=>0]);
                $this->db->table('server_library_sources')->upsert(['source_key'=>$source,'run_id'=>$run,'status'=>'running','updated_at'=>gmdate('Y-m-d H:i:s'),'completed_at'=>$state['completed_at'] ?? null,'last_error'=>null]);
                $this->db->transComplete();
                if (!$this->db->transStatus()) throw new RuntimeException('Could not start the catalog scan.');
                $state = ['run_id'=>$run];
            }
            // Drop obsolete catalog/queue records for the reserved server Trash tree.
            foreach (['server_library_index', 'server_library_scan_queue'] as $table) {
                $this->db->table($table)->where('source_key', $source)->groupStart()
                    ->where('LOWER(path)', 'trash')->orLike('path', 'TRASH/', 'after')->groupEnd()->delete();
            }
            if ($this->config->centralized()) {
                foreach (['server_library_index','server_library_scan_queue'] as $table) {
                    $this->db->table($table)->where('source_key',$source)->groupStart()
                        ->where('LOWER(path)','department')->orLike('LOWER(path)','department/','after')->groupEnd()->delete();
                }
            }
            $run = $state['run_id'];
            $this->db->table('server_library_sources')->where('source_key',$source)->update(['status'=>'running','last_error'=>null]);
            $deadline = $seconds > 0 ? microtime(true) + $seconds : INF;
            $canRead = static fn () => microtime(true) < $deadline && ($continue === null || $continue());
            $processed = 0;
            $deferred = [];
            while ($canRead()) {
                $queue = $this->db->table('server_library_scan_queue')->where('source_key',$source);
                if ($deferred) $queue->whereNotIn('id', array_keys($deferred));
                $cursor = $queue->orderBy('priority', 'DESC')->orderBy('id')->get(1)->getRowArray();
                if (!$cursor) {
                    $library->root();
                    $this->reconnectAfterRead();
                    if ($deferred) {
                        $this->db->table('server_library_sources')->where('source_key',$source)->update(['status'=>'partial','last_error'=>count($deferred) . ' folders could not be read; they remain queued for retry.','updated_at'=>gmdate('Y-m-d H:i:s')]);
                        return ['status'=>'partial','processed'=>$processed,'deferred'=>count($deferred)];
                    }
                    $this->db->transStart();
                    $this->db->table('server_library_index')->where('source_key',$source)->where('run_id !=',$run)->delete();
                    $this->db->table('server_library_sources')->where('source_key',$source)->update(['status'=>'complete','completed_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>null]);
                    $this->db->transComplete();
                    if (!$this->db->transStatus()) throw new RuntimeException('Could not complete the catalog scan.');
                    return ['status'=>'complete','processed'=>$processed];
                }
                // Retain an unreadable directory for retry, but continue other folders.
                try {
                    $directory = $library->resolve($cursor['path'], true);
                    $rows = []; $children = []; $finished = false;
                    $offset = (int)$cursor['entry_offset'];
                    // Reuse the stream between checkpoints. Replaying the saved offset
                    // is only necessary on resume or when queue priority changes folders.
                    if ($handleCursor !== $cursor['id'] || !is_resource($handle)) {
                        if (is_resource($handle)) closedir($handle);
                        $handle = $this->openDirectory($directory);
                        if (!$handle) throw new RuntimeException('Cannot read directory: ' . $cursor['path']);
                        $handleCursor = $cursor['id'];
                        for ($skip = 0; $skip < $offset; $skip++) {
                            if ($this->readDirectory($handle) === false) { $finished = true; break; }
                        }
                    }
                    while (!$finished && count($rows) < 100 && $canRead()) {
                        $name = $this->readDirectory($handle);
                        if ($name === false) { $finished = true; break; }
                        $offset++;
                        if (str_starts_with($name,'.') || in_array(strtolower($name),['__macosx','thumbs.db','desktop.ini'],true)) continue;
                        $relative = ($cursor['path'] === '' ? '' : $cursor['path'] . '/') . $name;
                        try { $library->relative($relative); } catch (RuntimeException) { continue; }
                        $absolute = $directory . DIRECTORY_SEPARATOR . $name;
                        if (is_link($absolute)) continue;
                        $stat = @stat($absolute);
                        if (!$stat || !is_readable($absolute)) throw new RuntimeException('Cannot read item: ' . $relative);
                        $isDir = is_dir($absolute);
                        if (!$isDir && !is_file($absolute)) continue;
                        $hash = hash('sha256',$relative);
                        if ($isDir) $children[] = ['source_key'=>$source,'path'=>$relative,'path_hash'=>$hash,'entry_offset'=>0];
                        $passport = $metadata['media'][$relative]['passport'] ?? [];
                        $rows[] = ['source_key'=>$source,'path_hash'=>$hash,'parent_hash'=>hash('sha256',$cursor['path']),
                            'path'=>$relative,'name'=>$name,'is_dir'=>$isDir ? 1 : 0,'size'=>$isDir ? null : $stat['size'],
                            'modified_at'=>$stat['mtime'],'extension'=>mb_substr(strtolower(pathinfo($name,PATHINFO_EXTENSION)),0,32),
                            'tags'=>json_encode($metadata['tags'][$relative] ?? [], JSON_THROW_ON_ERROR),
                            'classification'=>$passport['classification'] ?? $passport['event_name'] ?? '',
                            'date_taken'=>mb_substr((string)($passport['date_taken'] ?? ''),0,100),
                            'uploaded_at'=>$metadata['media'][$relative]['uploaded_at'] ?? null,
                            'run_id'=>$run,'indexed_at'=>gmdate('Y-m-d H:i:s')];
                    }
                    if ($finished) { closedir($handle); $handle = null; $handleCursor = null; }
                } catch (RuntimeException $error) {
                    if (is_resource($handle)) closedir($handle);
                    $handle = null; $handleCursor = null;
                    $library->root(); // A disconnected source still stops the whole pass.
                    $this->reconnectAfterRead();
                    $deferred[$cursor['id']] = true;
                    log_message('warning', 'Catalog deferred {path}: {message}', ['path'=>$cursor['path'],'message'=>$error->getMessage()]);
                    continue;
                }
                $library->root();
                $this->reconnectAfterRead();
                $this->db->transStart();
                if ($rows) $this->db->table('server_library_index')->onConstraint(['source_key','path_hash'])->upsertBatch($rows);
                if ($rows && $this->config->centralized()) {
                    $identities = array_map(static fn ($row) => ['source_key'=>$source,'path_hash'=>$row['path_hash'],'path'=>$row['path'],'is_dir'=>$row['is_dir'],'is_deleted'=>0,'updated_at'=>gmdate('Y-m-d H:i:s')], $rows);
                    $this->db->table('server_media')->ignore(true)->insertBatch($identities);
                }
                if ($children) $this->db->table('server_library_scan_queue')->ignore(true)->insertBatch($children);
                if ($finished) $this->db->table('server_library_scan_queue')->where('id',$cursor['id'])->delete();
                else $this->db->table('server_library_scan_queue')->where('id',$cursor['id'])->update(['entry_offset'=>$offset]);
                $this->db->table('server_library_sources')->where('source_key',$source)->update(['updated_at'=>gmdate('Y-m-d H:i:s')]);
                $this->db->transComplete();
                if (!$this->db->transStatus()) throw new RuntimeException('Could not save catalog progress.');
                $processed += count($rows);
            }
            $this->db->table('server_library_sources')->where('source_key',$source)->update(['status'=>'paused']);
            return ['status'=>'paused','processed'=>$processed];
        } catch (\Throwable $error) {
            try {
                while ($this->db->transDepth > 0) { if (!$this->db->transRollback()) break; }
                $this->reconnectAfterRead();
                if ($state !== null) $this->db->table('server_library_sources')->where('source_key',$source)->update(['status'=>'failed','last_error'=>mb_substr($error->getMessage(),0,2000)]);
            } catch (\Throwable $reportError) {
                log_message('error', 'Catalog could not record failure: {message}', ['message'=>$reportError->getMessage()]);
            }
            throw $error;
        } finally {
            if (is_resource($handle)) closedir($handle);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    /** @return resource|false */
    protected function openDirectory(string $directory)
    {
        return @opendir($directory);
    }

    /** @param resource $handle */
    protected function readDirectory($handle): string|false
    {
        return readdir($handle);
    }

    private function reconnectAfterRead(): void
    {
        // No transaction spans the NAS read. Reopen MySQL before writing a batch
        // so a slow/disconnected share cannot leave us using an expired session.
        if ($this->db->DBDriver === 'MySQLi' && $this->db->transDepth === 0) {
            $this->db->close();
            $this->db->initialize();
        }
    }
}
