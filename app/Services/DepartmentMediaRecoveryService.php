<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Repairs metadata for copies already present in designated department storage. */
class DepartmentMediaRecoveryService
{
    public function __construct(private BaseConnection $db, private StorageService $storage, private string $root)
    {
    }

    public function plan(): array
    {
        $departments = $this->db->table('user')->select('department')->where('role', 'department')->groupBy('department')->get()->getResultArray();
        $owners = [];
        foreach ($departments as $row) {
            $department = trim((string) $row['department']);
            if ($department !== '') {
                $owners[$this->storage->departmentFolderName($department)][] = $department;
            }
        }
        $files = $this->db->table('files')->select('id, filepath, content_hash, is_deleted')->get()->getResultArray();
        $byPath = $byHash = [];
        foreach ($files as $file) {
            $byPath[$file['filepath']][] = $file;
            if ($file['content_hash']) {
                $byHash[$file['content_hash']][] = $file;
            }
        }
        $plan = [];
        foreach ($owners as $directory => $names) {
            if (count($names) !== 1) {
                throw new RuntimeException('Multiple departments map to storage directory ' . $directory);
            }
            $department = $names[0];
            $base = $this->root . '/' . $directory;
            if (! is_dir($base) || is_link($base)) {
                continue;
            }
            $base = realpath($base);
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $item) {
                if (! $item->isFile() || $item->isLink()) {
                    continue;
                }
                $physical = $item->getRealPath();
                if (! str_starts_with($physical, $base . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($physical);
                $types = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/gif'=>'gif', 'image/webp'=>'webp'];
                if (! isset($types[$mime])) {
                    continue;
                }
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($physical, strlen($base) + 1));
                $path = $this->storage->databasePath($physical);
                $hash = hash_file('sha256', $physical);
                $candidates = $byPath[$path] ?? $byPath['writable/uploads/' . $relative] ?? $byHash[$hash] ?? [];
                $matching = [];
                $deleted = false;
                foreach ($candidates as $candidate) {
                    if ((int) $candidate['is_deleted'] !== 0) {
                        $deleted = true;
                        continue;
                    }
                    try {
                        if (hash_file('sha256', $this->storage->resolveDatabaseFile($candidate['filepath'])) === $hash) {
                            $matching[] = $candidate;
                        }
                    } catch (RuntimeException) { /* An unavailable original is not a verified match. */ }
                }
                $fileId = count($matching) === 1 ? (int) $matching[0]['id'] : null;
                $action = $deleted || count($matching) > 1 ? 'skip' : ($fileId ? 'assign' : 'register');
                if ($fileId && $this->db->table('media_assignments')->where('media_id', $fileId)->where('department', $department)->where('status', 'completed')->where('delivery_filepath', $path)->countAllResults() > 0) {
                    $action = 'existing';
                }
                $plan[] = ['department'=>$department, 'directory'=>$directory, 'relative'=>$relative, 'path'=>$path, 'hash'=>$hash, 'file_id'=>$fileId, 'type'=>$types[$mime], 'mime'=>$mime, 'size'=>filesize($physical), 'action'=>$action];
            }
        }
        return $plan;
    }

    /** All inserts are transactional; the manifest lists exactly what was created. */
    public function apply(array $plan, string $manifest): array
    {
        $created = ['file_ids'=>[], 'folder_ids'=>[], 'assignment_ids'=>[]];
        $now = gmdate('Y-m-d H:i:s');
        $this->db->transBegin();
        try {
            foreach ($plan as $entry) {
                if (! in_array($entry['action'], ['register', 'assign'], true)) {
                    continue;
                }
                $physical = $this->storage->resolveDatabaseFile($entry['path']);
                if (hash_file('sha256', $physical) !== $entry['hash']) {
                    throw new RuntimeException('A recovery image changed; generate a new plan.');
                }
                $fileId = $entry['file_id'];
                if ($fileId) {
                    $file = $this->db->table('files')->where('id', $fileId)->where('is_deleted', 0)->get()->getRowArray();
                    if (! $file || hash_file('sha256', $this->storage->resolveDatabaseFile($file['filepath'])) !== $entry['hash']) {
                        throw new RuntimeException('A matched original changed; generate a new plan.');
                    }
                } else {
                    if ($this->db->table('files')->where('filepath', $entry['path'])->countAllResults() > 0) {
                        throw new RuntimeException('A recovery record already exists; generate a new plan.');
                    }
                    $folderId = $this->recoveryFolder($entry, $created, $now);
                    $this->insert('files', [
                        'filename'=>basename($entry['relative']), 'filepath'=>$entry['path'], 'filetype'=>$entry['type'],
                        'mime_type'=>$entry['mime'], 'size'=>$entry['size'], 'content_hash'=>$entry['hash'],
                        'folder_id'=>$folderId, 'is_deleted'=>0, 'dateupload'=>$now, 'downloadable'=>1,
                        'description'=>'Recovered metadata for an existing department copy; original upload date unavailable.',
                    ]);
                    $fileId = (int) $this->db->insertID();
                    $created['file_ids'][] = $fileId;
                }
                if ($this->db->table('media_assignments')->where('media_id', $fileId)->where('department', $entry['department'])->where('status', 'completed')->where('delivery_filepath', $entry['path'])->countAllResults() > 0) {
                    continue;
                }
                $this->insert('media_assignments', [
                    'media_id'=>$fileId, 'department'=>$entry['department'], 'delivery_filepath'=>$entry['path'],
                    'request_id'=>null, 'method'=>'recovery', 'status'=>'completed', 'sent_by'=>0,
                    'sent_at'=>$now, 'completed_at'=>$now,
                ]);
                $created['assignment_ids'][] = (int) $this->db->insertID();
            }
            if (! $this->db->transStatus()) {
                throw new RuntimeException('Recovery transaction failed.');
            }
            // Persist provenance before committing; a failed write rolls back every insert.
            $json = json_encode(['created_at'=>$now, 'created'=>$created, 'plan'=>$plan], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (file_put_contents($manifest, $json, LOCK_EX) === false) {
                throw new RuntimeException('Could not save the recovery manifest.');
            }
            if (! $this->db->transCommit()) {
                throw new RuntimeException('Could not commit recovery.');
            }
        } catch (\Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
        return $created;
    }

    private function insert(string $table, array $data): void
    {
        if (! $this->db->table($table)->insert($data)) {
            throw new RuntimeException('Could not insert a recovery record into ' . $table);
        }
    }

    private function recoveryFolder(array $entry, array &$created, string $now): int
    {
        $parts = ['Recovered department images', $entry['directory']];
        $relative = dirname($entry['relative']);
        if ($relative !== '.') {
            $parts = array_merge($parts, explode('/', $relative));
        }
        $parent = null;
        $path = '';
        foreach ($parts as $part) {
            $path = $path === '' ? $part : $path . '/' . $part;
            $folder = $this->db->table('folder')->where('path', $path)->where('is_deleted', 0)->get()->getRowArray();
            if ($folder) {
                $parent = (int) $folder['id'];
                continue;
            }
            $this->insert('folder', ['name'=>$part, 'path'=>$path, 'parent_id'=>$parent, 'is_deleted'=>0, 'created_at'=>$now, 'description'=>'Metadata recovered from existing department storage.']);
            $parent = (int) $this->db->insertID();
            $created['folder_ids'][] = $parent;
        }
        return $parent;
    }
}
