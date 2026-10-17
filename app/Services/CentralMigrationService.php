<?php
namespace App\Services;

use Config\Storage;
use RuntimeException;

/** Non-destructive, resumable migration. Source files are never removed. */
class CentralMigrationService
{
    private StorageService $legacy;
    private ServerLibraryService $server;
    public function __construct(?Storage $config = null, ?StorageService $legacy = null)
    {
        $config ??= new Storage();
        $this->server = new ServerLibraryService($config);
        $old = new class extends Storage { public function centralized(): bool { return false; } };
        $this->legacy = $legacy ?? new StorageService($old);
    }

    public function run(bool $apply = false, ?callable $progress = null, bool $excludeMissingDuplicates = false): array
    {
        if ($apply) {
            $preflight = $this->run(false, null, $excludeMissingDuplicates);
            if ($preflight['missing'] !== [] || $preflight['conflicts'] !== []) {
                throw new RuntimeException('Migration stopped before copying: resolve missing originals and destination conflicts reported by the dry run.');
            }
        }
        $db = db_connect(); $root = $this->server->root();
        $savedMetadata = $apply ? $db->table('server_metadata')->where('source_key',$this->server->catalogSourceKey())->get()->getRowArray() : null;
        $metadata = $apply ? ($savedMetadata ? json_decode($savedMetadata['payload'],true,512,JSON_THROW_ON_ERROR) : (new ServerLibraryEditor($this->server))->state()) : [];
        $tables = ['files'=>'filepath','department_files'=>'filepath','duplicate_media'=>'filepath','media_assignments'=>'delivery_filepath',
            'folder'=>'path','department_folders'=>'path','media_assignment_folders'=>'delivery_path','trashfiles'=>'original_filepath','tags'=>'filepath'];
        $untracked = $this->untracked();
        $tables['@untracked'] = 'filepath';
        $report = ['checked'=>0,'verified'=>0,'missing'=>[],'excluded_missing_duplicates'=>[],'conflicts'=>[],'cleanup_pending'=>[],'directories'=>0,'bytes'=>0];
        foreach ($tables as $table=>$column) {
            $synthetic = $table === '@untracked';
            if (!$synthetic && (!$db->tableExists($table) || !$db->fieldExists($column,$table))) continue;
            $offset = 0;
            do {
                $rows = $synthetic ? array_slice($untracked,$offset,200) : $db->table($table)->orderBy('id')->limit(200,$offset)->get()->getResultArray();
                $offset += count($rows);
                foreach ($rows as $row) {
                    $stored = (string)$row[$column];
                    if ($stored === '') continue;
                    $report['checked']++;
                    if (str_starts_with($stored,'server-library/')) {
                        $absolute = $root . '/' . substr($stored,15);
                        if (is_file($absolute) || is_dir($absolute) || $table === 'trashfiles') $report['verified']++;
                        else $report['missing'][] = [$table,$row['id'],$stored];
                        continue;
                    }
                    $isFolder = in_array($table,['folder','department_folders','media_assignment_folders'],true);
                    $lookup = $stored;
                    if ($table === 'folder' && !str_starts_with($stored,'/') && !str_starts_with($stored,'writable/')) $lookup = 'writable/uploads/'.$stored;
                    if ($table === 'department_folders' && !str_starts_with($stored,'/') && !str_starts_with($stored,'writable/')) {
                        $lookup = 'writable/Department/'.$this->legacy->departmentFolderName($row['department']).'/'.$stored;
                    }
                    try { $source = $isFolder ? $this->legacy->resolveDatabaseDirectory($lookup) : $this->legacy->resolveDatabaseFile($lookup); }
                    catch (RuntimeException) {
                        if ($table === 'duplicate_media' && $excludeMissingDuplicates) { $report['excluded_missing_duplicates'][] = (int)$row['id']; continue; }
                        // Deleted original locations are historical references, not missing data.
                        if ($table === 'trashfiles' || $table === 'tags') continue;
                        $report['missing'][] = [$table,$row['id'],$stored]; continue;
                    }
                    if (is_link($source)) { $report['conflicts'][] = [$table,$row['id'],'linked source']; continue; }
                    $department = $this->legacy->departmentRoot(); $local = $this->legacy->root();
                    if (str_starts_with($source,$department.'/')) $relative = 'DEPARTMENT/' . substr($source,strlen($department)+1);
                    elseif ($source === $local || str_starts_with($source,$local.'/')) $relative = 'Imported media' . substr($source,strlen($local));
                    else { $report['conflicts'][] = [$table,$row['id'],'unmapped storage root']; continue; }
                    $target = $root . '/' . $relative;
                    $hash = $isFolder ? '' : hash_file('sha256',$source);
                    if (!$isFolder && $hash === false) throw new RuntimeException('Cannot hash migration source.');
                    if (is_link($target) || (file_exists($target) && ($isFolder ? !is_dir($target) : !is_file($target) || hash_file('sha256',$target) !== $hash))) {
                        $report['conflicts'][] = [$table,$row['id'],$relative]; continue;
                    }
                    if ($isFolder) $report['directories']++; else $report['bytes'] += filesize($source);
                    if (!$apply) continue;
                    $cursor = $root;
                    foreach (explode('/', $isFolder ? $relative : dirname($relative)) as $part) {
                        if ($part === '' || $part === '.' || $part === '..') throw new RuntimeException('Invalid migration destination.');
                        $cursor .= '/'.$part;
                        if (is_link($cursor)) throw new RuntimeException('Linked destination rejected.');
                        if (!is_dir($cursor) && !mkdir($cursor,0770) && !is_dir($cursor)) throw new RuntimeException('Cannot create migration directory.');
                    }
                    if (!$isFolder && !is_file($target)) {
                        $temporary = dirname($target).'/.migration-'.bin2hex(random_bytes(12));
                        try {
                            if (!copy($source,$temporary) || hash_file('sha256',$temporary) !== $hash) throw new RuntimeException('Migration verification failed.');
                            // Hard-link publication refuses to overwrite a racing destination.
                            if (!@link($temporary,$target)) {
                                $output = @fopen($target,'xb');
                                if (!$output) throw new RuntimeException('Destination appeared during migration.');
                                $input = fopen($temporary,'rb');
                                try {
                                    $copied = stream_copy_to_stream($input,$output);
                                    fflush($output);
                                } finally { fclose($input); fclose($output); }
                                if ($copied !== filesize($temporary) || hash_file('sha256',$target) !== $hash) {
                                    unlink($target);
                                    throw new RuntimeException('Migration copy verification failed.');
                                }
                            }
                        } finally {
                            if (is_file($temporary) && !@unlink($temporary)) $report['cleanup_pending'][] = $temporary;
                        }
                    }
                    $newPath = 'server-library/'.$relative;
                    $db->transStart();
                    $db->table('central_storage_migration')->onConstraint(['table_name','record_id'])->upsert(['table_name'=>$table,'record_id'=>$row['id'],'old_path'=>$stored,'new_path'=>$newPath,'sha256'=>$hash,'status'=>'verified','updated_at'=>gmdate('Y-m-d H:i:s')]);
                    if (!$synthetic) $db->table($table)->where('id',$row['id'])->update([$column=>$newPath]);
                    if ($table === 'department_files') $db->table($table)->where('id',$row['id'])->update(['delivery_key'=>hash('sha256',$newPath)]);
                    if (($table === 'files' || $table === 'folder') && !str_starts_with($relative,'DEPARTMENT/')) {
                        $identity = (new CentralMediaService($this->server))->identity($relative,$isFolder);
                        $db->table('server_media')->where('id',$identity['id'])->update([$isFolder ? 'folder_id' : 'file_id'=>$row['id']]);
                    }
                    if ($table === 'files' && !str_starts_with($relative,'DEPARTMENT/')) {
                        $metadata['tags'][$relative] = array_values(array_filter(array_map('trim',explode(',',(string)($row['tags'] ?? '')))));
                        $metadata['media'][$relative] = ['uploaded_at'=>$row['dateupload'] ?? null,'content_hash'=>$hash,
                            'passport'=>['filename'=>$row['filename'],'classification'=>$row['classification'] ?? '',
                                'uploaded_by'=>$row['employee_id'] ?? null,'upload_date'=>$row['dateupload'] ?? null]];
                    }
                    $db->transComplete();
                    if (!$db->transStatus()) throw new RuntimeException('Could not save migration checkpoint.');
                    $report['verified']++;
                    if ($progress) $progress($table,(int)$row['id']);
                }
            } while (count($rows) === 200);
        }
        if ($apply) {
            $db->table('server_metadata')->upsert(['source_key'=>$this->server->catalogSourceKey(),
                'payload'=>json_encode($metadata,JSON_THROW_ON_ERROR),'updated_at'=>gmdate('Y-m-d H:i:s')]);
            // Backfill reference-only paths after their physical owners have moved.
            foreach ($db->table('central_storage_migration')->get()->getResultArray() as $entry) {
                foreach (['tags'=>'filepath','trashfiles'=>'original_filepath'] as $table=>$column) {
                    if ($db->tableExists($table) && $db->fieldExists($column,$table)) $db->table($table)->where($column,$entry['old_path'])->update([$column=>$entry['new_path']]);
                }
            }
            $paths = [];
            foreach ($db->table('central_storage_migration')->whereIn('table_name',['files','folder','@untracked'])->get()->getResultArray() as $entry) {
                $path = substr($entry['new_path'],15);
                if (!str_starts_with($path,'DEPARTMENT/')) $paths[] = $path;
            }
            foreach (array_chunk(array_unique($paths),100) as $batch) (new ServerLibraryCatalogWriter($this->server))->sync($batch,$metadata);
        }
        $report['cleanup_pending'] = array_values(array_filter($report['cleanup_pending'], static fn ($path) => is_file($path) && !@unlink($path)));
        return $report;
    }

    /** Retain orphaned originals as browseable imports; logos are application assets. */
    private function untracked(): array
    {
        $db = db_connect(); $seen = [];
        foreach (['files','department_files','duplicate_media'] as $table) {
            if (!$db->tableExists($table)) continue;
            foreach ($db->table($table)->select('filepath')->get()->getResultArray() as $row) {
                if (str_starts_with($row['filepath'],'server-library/')) continue;
                try { $seen[$this->legacy->resolveDatabaseFile($row['filepath'])] = true; } catch (RuntimeException) {}
            }
        }
        if ($db->tableExists('central_storage_migration')) {
            foreach ($db->table('central_storage_migration')->select('old_path')->get()->getResultArray() as $row) {
                try { $seen[$this->legacy->resolveDatabaseFile($row['old_path'])] = true; } catch (RuntimeException) {}
            }
        }
        $rows = [];
        foreach ([$this->legacy->root(),$this->legacy->departmentRoot()] as $root) {
            $filter = new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),
                static fn ($entry) => !$entry->isLink() && !str_starts_with($entry->getFilename(),'.') && !in_array($entry->getFilename(),['watermark-logos','index.html'],true));
            foreach (new \RecursiveIteratorIterator($filter) as $entry) {
                if (!$entry->isFile() || isset($seen[$entry->getRealPath()])) continue;
                $path = $entry->getRealPath();
                $rows[] = ['id'=>hexdec(substr(hash('sha256',$path),0,12)),'filepath'=>$path];
            }
        }
        return $rows;
    }
}
