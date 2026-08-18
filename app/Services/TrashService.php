<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\I18n\Time;
use RuntimeException;

class TrashService
{
    public const RETENTION_DAYS = 30;

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function purgeExpiredTrash(array $actor, ?IncomingRequest $request = null, int $limit = 500): int
    {
        $limit = max(1, min(5000, $limit));
        $expiredFolders = $this->db->table('folder')
            ->where('is_deleted', 1)
            ->where('trash_root_id = id', null, false)
            ->where('deleted_at <= DATE_SUB(NOW(), INTERVAL ' . self::RETENTION_DAYS . ' DAY)', null, false)
            ->limit($limit)
            ->get()
            ->getResultArray();
        $expiredFiles = $this->db->table('files f')
            ->select('f.*')
            ->join('trashfiles tf', 'tf.file_id = f.id', 'left')
            ->where('f.is_deleted', 1)
            ->where('tf.folder_root_id IS NULL', null, false)
            ->where('COALESCE(tf.deleted_at, f.last_action_at, f.dateupload) <= DATE_SUB(NOW(), INTERVAL ' . self::RETENTION_DAYS . ' DAY)', null, false)
            ->limit($limit)
            ->get()
            ->getResultArray();

        $cleanupActor = [
            'employee_id' => 0,
            'name'        => 'Trash retention cleanup',
            'department'  => 'System',
            'role'        => 'system',
        ];
        $purged = 0;
        foreach ($expiredFolders as $folder) {
            try {
                $this->permanentlyRemoveFolder($folder, $cleanupActor, 'AUTO_FOLDER_TRASH_PURGE', $request);
                $purged++;
            } catch (\Throwable) {
                $this->recordAudit($actor, $request, 'delete', 'AUTO_FOLDER_TRASH_PURGE_FAILED', 'folder', (int) $folder['id'], 'Trash retention cleanup could not purge folder "' . $folder['name'] . '".');
            }
        }
        foreach ($expiredFiles as $file) {
            try {
                $this->permanentlyRemoveMedia($file, $cleanupActor, 'AUTO_TRASH_PURGE', $request);
                $purged++;
            } catch (\Throwable) {
                $this->recordAudit($actor, $request, 'delete', 'AUTO_TRASH_PURGE_FAILED', 'file', (int) $file['id'], 'Trash retention cleanup could not purge "' . $file['filename'] . '".');
            }
        }

        return $purged;
    }

    /**
     * @param list<array<string, mixed>> $files
     * @return list<array<string, mixed>>
     */
    public function withRetention(array $files): array
    {
        $now = time();
        foreach ($files as &$file) {
            $deletedAt = (string) ($file['deleted_at'] ?? $file['last_action_at'] ?? $file['dateupload'] ?? '');
            $deletedTimestamp = $deletedAt === '' ? false : strtotime($deletedAt);
            if ($deletedTimestamp === false) {
                $file['trash_expires_at'] = '-';
                $file['trash_days_remaining'] = self::RETENTION_DAYS;
                continue;
            }

            $expiresTimestamp = $deletedTimestamp + (self::RETENTION_DAYS * 86400);
            $remainingSeconds = $expiresTimestamp - $now;
            $file['trash_expires_at'] = date('M d, Y h:i A', $expiresTimestamp);
            $file['trash_days_remaining'] = max(0, (int) ceil($remainingSeconds / 86400));
        }
        unset($file);

        return $files;
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed> $actor
     */
    public function permanentlyRemoveMedia(array $file, array $actor, string $auditAction, ?IncomingRequest $request = null): void
    {
        $fileId = (int) $file['id'];
        $filename = (string) ($file['filename'] ?? 'media');
        $artifactPaths = $this->managedArtifactPaths($file);

        $this->deletePhysicalArtifacts($artifactPaths);
        $this->deleteMediaDatabaseRows($file);

        $this->recordAudit($actor, $request, 'delete', $auditAction, 'file', $fileId, 'Permanently deleted "' . $filename . '" from Trash.');
    }

    /**
     * @param array<string, mixed> $folder
     * @param array<string, mixed> $actor
     */
    public function restoreFolderTree(array $folder, array $actor, string $auditAction = 'FOLDER_RESTORE', ?IncomingRequest $request = null): int
    {
        $rootId = (int) ($folder['id'] ?? 0);
        $folders = $this->deletedFolderTree($rootId);
        if ($rootId < 1 || $folders === []) {
            throw new RuntimeException('The requested folder is not in Trash.');
        }

        $folderIds = array_map('intval', array_column($folders, 'id'));
        $rootFolder = null;
        foreach ($folders as $treeFolder) {
            if ((int) $treeFolder['id'] === $rootId) {
                $rootFolder = $treeFolder;
                break;
            }
        }
        if ($rootFolder === null) {
            throw new RuntimeException('The deleted folder root is unavailable.');
        }

        $originalName = (string) $rootFolder['name'];
        $restoredName = $this->availableRestoredFolderName($originalName, empty($rootFolder['parent_id']) ? null : (int) $rootFolder['parent_id'], $rootId);
        $renamed = $restoredName !== $originalName;
        $pathUpdates = [];
        $createdDirectories = [];
        if ($renamed) {
            $pathUpdates = $this->restoredFolderPaths($folders, $rootId, $restoredName);
            try {
                foreach ($pathUpdates as $path) {
                    $absolutePath = service('storage')->root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
                    $existed = is_dir($absolutePath);
                    service('storage')->directory($path, true);
                    if (! $existed) {
                        $createdDirectories[] = $absolutePath;
                    }
                }
            } catch (\Throwable $exception) {
                $this->removeEmptyDirectories($createdDirectories);
                throw new RuntimeException('A separate storage location for the restored folder could not be created.', 0, $exception);
            }
        }

        $fileIds = array_map('intval', array_column(
            $this->db->table('trashfiles tf')
                ->select('tf.file_id')
                ->join('files f', 'f.id = tf.file_id', 'inner')
                ->where('tf.folder_root_id', $rootId)
                ->where('f.is_deleted', 1)
                ->get()
                ->getResultArray(),
            'file_id'
        ));

        $now = gmdate('Y-m-d H:i:s');
        $this->db->transStart();
        if ($renamed) {
            foreach ($pathUpdates as $folderId => $path) {
                $changes = ['path' => $path];
                if ($folderId === $rootId) {
                    $changes['name'] = $restoredName;
                }
                $this->db->table('folder')->where('id', $folderId)->update($changes);
            }
        }
        $this->db->table('folder')->whereIn('id', $folderIds)->update([
            'is_deleted' => 0,
            'deleted_at' => null,
            'deleted_by' => null,
            'deletion_reason' => null,
            'trash_root_id' => null,
        ]);
        if ($fileIds !== []) {
            $this->db->table('files')->whereIn('id', $fileIds)->update([
                'is_deleted' => 0,
                'last_action' => 'restored',
                'last_action_at' => $now,
            ]);
        }
        $this->db->table('trashfiles')->where('folder_root_id', $rootId)->delete();
        $this->db->transComplete();
        if (! $this->db->transStatus()) {
            $this->removeEmptyDirectories($createdDirectories);
            throw new RuntimeException('The folder could not be restored.');
        }

        $renameNote = $renamed ? ' as "' . $restoredName . '" because the original name was already in use' : '';
        $this->recordAudit($actor, $request, 'folder', $auditAction, 'folder', $rootId, 'Restored folder "' . $originalName . '"' . $renameNote . ' with ' . count($fileIds) . ' file(s) from Trash.');

        return count($fileIds);
    }

    private function availableRestoredFolderName(string $name, ?int $parentId, int $restoringId): string
    {
        $baseName = mb_substr($name, 0, 100);
        $parentPath = '';
        if ($parentId !== null) {
            $parent = $this->db->table('folder')->select('name, path')->where('id', $parentId)->where('is_deleted', 0)->get()->getRowArray();
            if ($parent === null) {
                throw new RuntimeException('The original parent folder is unavailable.');
            }
            $parentPath = trim((string) ($parent['path'] ?: $parent['name']), '/');
        }
        for ($suffix = 0; $suffix < 1000; ++$suffix) {
            $candidate = $suffix === 0 ? $name : $baseName . ' (restored' . ($suffix === 1 ? '' : ' ' . $suffix) . ')';
            $duplicate = $this->db->table('folder')
                ->where('is_deleted', 0)
                ->where('name', $candidate)
                ->where('id !=', $restoringId);
            $parentId === null
                ? $duplicate->where('parent_id IS NULL', null, false)
                : $duplicate->where('parent_id', $parentId);
            if ($duplicate->countAllResults() !== 0) {
                continue;
            }
            if ($suffix > 0) {
                $relativePath = service('storage')->normalizeRelativePath(trim($parentPath . '/' . $candidate, '/'), false);
                $absolutePath = service('storage')->root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                if (file_exists($absolutePath)) {
                    continue;
                }
            }
            return $candidate;
        }

        throw new RuntimeException('An available name for the restored folder could not be generated.');
    }

    /**
     * @param list<array<string, mixed>> $folders
     * @return array<int, string>
     */
    private function restoredFolderPaths(array $folders, int $rootId, string $restoredName): array
    {
        $byId = [];
        foreach ($folders as $folder) {
            $byId[(int) $folder['id']] = $folder;
        }

        $root = $byId[$rootId];
        $parentPath = '';
        if (! empty($root['parent_id'])) {
            $parent = $this->db->table('folder')->where('id', (int) $root['parent_id'])->where('is_deleted', 0)->get()->getRowArray();
            if ($parent === null) {
                throw new RuntimeException('The original parent folder is unavailable.');
            }
            $parentPath = trim((string) ($parent['path'] ?: $parent['name']), '/');
        }

        $paths = [$rootId => service('storage')->normalizeRelativePath(trim($parentPath . '/' . $restoredName, '/'), false)];
        while (count($paths) < count($byId)) {
            $progress = false;
            foreach ($byId as $id => $folder) {
                if (isset($paths[$id])) {
                    continue;
                }
                $parentId = (int) ($folder['parent_id'] ?? 0);
                if (! isset($paths[$parentId])) {
                    continue;
                }
                $paths[$id] = service('storage')->normalizeRelativePath($paths[$parentId] . '/' . (string) $folder['name'], false);
                $progress = true;
            }
            if (! $progress) {
                throw new RuntimeException('The deleted folder hierarchy is incomplete.');
            }
        }

        return $paths;
    }

    /** @param list<string> $directories */
    private function removeEmptyDirectories(array $directories): void
    {
        usort($directories, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ($directories as $directory) {
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    /**
     * @param array<string, mixed> $folder
     * @param array<string, mixed> $actor
     */
    public function permanentlyRemoveFolder(array $folder, array $actor, string $auditAction = 'FOLDER_PERMANENT_DELETE', ?IncomingRequest $request = null): int
    {
        $rootId = (int) ($folder['id'] ?? 0);
        $folders = $this->deletedFolderTree($rootId);
        if ($rootId < 1 || $folders === []) {
            throw new RuntimeException('The requested folder is not in Trash.');
        }

        $folderIds = array_map('intval', array_column($folders, 'id'));
        $files = $this->db->table('files f')
            ->select('f.*')
            ->join('trashfiles tf', 'tf.file_id = f.id', 'inner')
            ->where('tf.folder_root_id', $rootId)
            ->where('f.is_deleted', 1)
            ->get()
            ->getResultArray();
        foreach ($files as $file) {
            $this->permanentlyRemoveMedia($file, $actor, $auditAction . '_FILE', $request);
        }

        $this->db->transStart();
        $this->db->table('files')->whereIn('folder_id', $folderIds)->update(['folder_id' => null]);
        if ($this->db->tableExists('media_assignment_folders')) {
            $this->db->table('media_assignment_folders')->whereIn('folder_id', $folderIds)->delete();
        }
        $this->db->table('folder')->whereIn('id', $folderIds)->delete();
        $this->db->transComplete();
        if (! $this->db->transStatus()) {
            throw new RuntimeException('The folder records could not be permanently deleted.');
        }

        $paths = array_values(array_filter(array_map(static fn (array $item): string => trim((string) ($item['path'] ?? '')), $folders)));
        usort($paths, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ($paths as $path) {
            try {
                $directory = service('storage')->directory($path);
                @rmdir($directory);
            } catch (RuntimeException) {
                // Missing or non-empty directories are safe to leave in managed storage.
            }
        }

        $this->recordAudit($actor, $request, 'folder', $auditAction, 'folder', $rootId, 'Permanently deleted folder "' . $folder['name'] . '" and ' . count($files) . ' file(s) from Trash.');

        return count($files);
    }

    /** @return list<array<string, mixed>> */
    private function deletedFolderTree(int $rootId): array
    {
        if ($rootId < 1) {
            return [];
        }

        return $this->db->table('folder')
            ->where('is_deleted', 1)
            ->where('trash_root_id', $rootId)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * @param array<string, mixed> $file
     * @return list<string>
     */
    private function managedArtifactPaths(array $file): array
    {
        $paths = [];
        $filepath = trim((string) ($file['filepath'] ?? ''));
        if ($filepath !== '') {
            $paths[] = $filepath;
        }

        if ($this->db->tableExists('file_watermarks')) {
            foreach ($this->db->table('file_watermarks')->select('watermarked_path')->where('media_id', (int) $file['id'])->get()->getResultArray() as $watermark) {
                $watermarkedPath = trim((string) ($watermark['watermarked_path'] ?? ''));
                if ($watermarkedPath !== '') {
                    $paths[] = $watermarkedPath;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /** @param list<string> $paths */
    private function deletePhysicalArtifacts(array $paths): void
    {
        $storage = service('storage');
        foreach ($paths as $path) {
            try {
                $resolved = $storage->resolveDatabaseFile($path);
            } catch (RuntimeException) {
                continue;
            }

            if (is_file($resolved) && ! @unlink($resolved)) {
                throw new RuntimeException('A media file could not be removed from storage.');
            }
        }
    }

    /** @param array<string, mixed> $file */
    private function deleteMediaDatabaseRows(array $file): void
    {
        $fileId = (int) $file['id'];
        $filepath = (string) ($file['filepath'] ?? '');

        $passportIds = [];
        if ($this->db->tableExists('passports')) {
            $passportIds = array_map('intval', array_column($this->db->table('passports')->select('id')->where('media_id', $fileId)->get()->getResultArray(), 'id'));
        }
        $this->db->transStart();
        if ($passportIds !== [] && $this->db->tableExists('passport_versions')) {
            $this->db->table('passport_versions')->whereIn('passport_id', $passportIds)->delete();
        }
        if ($this->db->tableExists('passports')) {
            $this->db->table('passports')->where('media_id', $fileId)->delete();
        }
        if ($this->db->tableExists('file_watermarks')) {
            $this->db->table('file_watermarks')->where('media_id', $fileId)->delete();
        }
        if ($this->db->tableExists('ai_event_detections')) {
            $this->db->table('ai_event_detections')->where('media_id', $fileId)->delete();
        }
        if ($this->db->tableExists('recent') && $filepath !== '') {
            $this->db->table('recent')->where('filepath', $filepath)->delete();
        }
        if ($this->db->tableExists('tag')) {
            $this->db->table('tag')->where('file_id', $fileId)->delete();
            if ($filepath !== '') {
                $this->db->table('tag')->where('filepath', $filepath)->delete();
            }
        }
        if ($this->db->tableExists('album_files') && $filepath !== '') {
            $this->db->table('album_files')->where('filepath', $filepath)->delete();
        }
        $this->db->table('trashfiles')->where('file_id', $fileId)->delete();
        $this->db->table('files')->where('id', $fileId)->delete();
        $this->db->transComplete();
        if (! $this->db->transStatus()) {
            throw new RuntimeException('The media database record could not be permanently deleted.');
        }
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function recordAudit(array $actor, ?IncomingRequest $request, string $module, string $action, string $targetType, ?int $targetId, string $description): void
    {
        if ($request !== null) {
            service('audit')->record($actor, $request, $module, $action, $targetType, $targetId, $description);
            return;
        }

        if (! $this->db->tableExists('audit_logs')) {
            return;
        }

        $this->db->table('audit_logs')->insert([
            'user_id'     => (string) ($actor['employee_id'] ?? 0),
            'user_name'   => $actor['name'] ?? null,
            'department'  => $actor['department'] ?? null,
            'module'      => $module,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'description' => $description,
            'ip_address'  => 'CLI',
            'created_at'  => Time::now('UTC')->toDateTimeString(),
        ]);
    }
}
