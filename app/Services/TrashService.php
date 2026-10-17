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

    /** Move originals and database records together, including folder deletions. */
    public function moveToTrash(array $files, array $actor, string $reason, ?int $folderRootId = null, array $folderIds = []): void
    {
        if ((new \Config\Storage())->centralized()) {
            $paths = [];
            if ($folderRootId !== null) {
                $folder = $this->db->table('folder')->where('id',$folderRootId)->where('is_deleted',0)->get()->getRowArray();
                if (!$folder) throw new RuntimeException('The folder is unavailable.');
                $paths[] = str_starts_with($folder['path'],'server-library/') ? substr($folder['path'],15) : $folder['path'];
            } else {
                foreach ($files as $file) {
                    if (!str_starts_with($file['filepath'],'server-library/')) throw new RuntimeException('Migrate this media before deleting it.');
                    $paths[] = substr($file['filepath'],15);
                }
            }
            (new ServerLibraryEditor(new ServerLibraryService()))->trash($paths,(int)$actor['employee_id']);
            return;
        }
        $this->withFileMoves(function (array &$moves) use ($files, $actor, $reason, $folderRootId, $folderIds): void {
            $now = gmdate('Y-m-d H:i:s');
            foreach ($files as $file) {
                $id = (int) $file['id'];
                $current = $this->db->table('files')->where('id', $id)->get()->getRowArray();
                if ($current === null || (int) $current['is_deleted'] !== 0) {
                    throw new RuntimeException('The file is unavailable or already in Trash.');
                }
                $original = service('storage')->databasePath(service('storage')->resolveDatabaseFile($current['filepath']));
                $destination = $this->availableTrashPath($original, $id);
                $this->moveTracked($original, $destination, $moves);
                $this->db->table('files')->where('id', $id)->update([
                    'filepath' => $destination, 'is_deleted' => 1,
                    'last_action' => 'moved_to_trash', 'last_action_at' => $now,
                ]);
                $metadata = [
                    'original_filepath' => $original, 'folder_root_id' => $folderRootId,
                    'deleted_at' => $now, 'deleted_by' => (int) $actor['employee_id'], 'reason' => $reason,
                ];
                if ($this->db->table('trashfiles')->where('file_id', $id)->countAllResults() > 0) {
                    $this->db->table('trashfiles')->where('file_id', $id)->update($metadata);
                } else {
                    $this->db->table('trashfiles')->insert(['file_id' => $id] + $metadata);
                }
            }
            if ($folderIds !== []) {
                $this->db->table('folder')->whereIn('id', $folderIds)->update([
                    'is_deleted' => 1, 'deleted_at' => $now, 'deleted_by' => (int) $actor['employee_id'],
                    'deletion_reason' => $reason, 'trash_root_id' => $folderRootId,
                ]);
            }
        });
    }

    public function restoreMedia(array $file): void
    {
        if ((new \Config\Storage())->centralized() && str_starts_with($file['filepath'],'server-library/')) {
            $editor = new ServerLibraryEditor(new ServerLibraryService());
            foreach ($editor->state()['trash'] as $id=>$record) {
                if ($record['path'] === substr($file['filepath'],15)) { $editor->restore($id); return; }
            }
            throw new RuntimeException('Restore this item from its folder on the Trash page.');
        }
        $metadata = $this->db->table('trashfiles')->where('file_id', (int) $file['id'])->get()->getRowArray();
        if (! empty($metadata['folder_root_id'])) {
            throw new RuntimeException('This file belongs to a deleted folder. Restore the folder instead.');
        }
        if (! empty($file['folder_id']) && $this->db->table('folder')->where('id', (int) $file['folder_id'])->where('is_deleted', 0)->countAllResults() === 0) {
            throw new RuntimeException('Restore the original parent folder before restoring this file.');
        }
        $this->withFileMoves(function (array &$moves) use ($file): void {
            $this->restoreMediaWithinTransaction($file, $moves);
        });
    }

    /** Flatten existing Trash storage without changing deletion dates or restore paths. */
    public function relocateLegacyTrash(array $file): void
    {
        $this->withFileMoves(function (array &$moves) use ($file): void {
            $id = (int) $file['id'];
            $current = $this->db->table('files')->where('id', $id)->where('is_deleted', 1)->get()->getRowArray();
            $metadata = $this->db->table('trashfiles')->where('file_id', $id)->get()->getRowArray();
            if ($current === null || $metadata === null) {
                throw new RuntimeException('The file is not in Trash.');
            }
            $source = service('storage')->databasePath(service('storage')->resolveDatabaseFile($current['filepath']));
            $original = trim((string) ($metadata['original_filepath'] ?? ''));
            if ($original !== '' && dirname($source) === 'writable/uploads/Trashed') {
                return;
            }
            $original = $original !== '' ? $original : $source;
            $destination = $this->availableTrashPath($original, $id);
            $this->moveTracked($source, $destination, $moves);
            $this->db->table('trashfiles')->where('file_id', $id)->update(['original_filepath' => $original]);
            $this->db->table('files')->where('id', $id)->update(['filepath' => $destination]);
        });
    }

    /** Keep files directly in Trashed; add a suffix only when a name is occupied. */
    private function availableTrashPath(string $original, int $id): string
    {
        $filename = basename(str_replace('\\', '/', $original));
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        for ($attempt = 0; $attempt < 10000; ++$attempt) {
            $candidate = $attempt === 0 ? $filename : $stem . ' (' . $id . ($attempt === 1 ? '' : '-' . $attempt) . ')' . ($extension === '' ? '' : '.' . $extension);
            $path = 'writable/uploads/Trashed/' . $candidate;
            $physical = service('storage')->root() . '/Trashed/' . $candidate;
            if (! file_exists($physical) && ! is_link($physical)
                && $this->db->table('files')->where('filepath', $path)->countAllResults() === 0) {
                return $path;
            }
        }
        throw new RuntimeException('An available filename in Trash could not be generated.');
    }

    private function restoreMediaWithinTransaction(array $file, array &$moves, ?string $destination = null): void
    {
        $id = (int) $file['id'];
        $metadata = $this->db->table('trashfiles')->where('file_id', $id)->get()->getRowArray();
        $original = trim((string) ($metadata['original_filepath'] ?? ''));
        if ($original !== '') {
            $restoredPath = $destination ?? $original;
            $this->moveTracked($file['filepath'], $restoredPath, $moves);
        } else {
            // Older Trash records still have their physical file at its original path.
            $restoredPath = service('storage')->databasePath(service('storage')->resolveDatabaseFile($file['filepath']));
            if ($destination !== null && $destination !== $restoredPath) {
                $this->moveTracked($restoredPath, $destination, $moves);
                $restoredPath = $destination;
            }
        }
        $this->db->table('files')->where('id', $id)->update([
            'filepath' => $restoredPath, 'is_deleted' => 0,
            'last_action' => 'restored', 'last_action_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->db->table('trashfiles')->where('file_id', $id)->delete();
    }

    private function moveTracked(string $source, string $destination, array &$moves): void
    {
        service('storage')->relocateDatabaseFile($source, $destination);
        $moves[] = [$source, $destination];
    }

    /** Compensate physical moves if any move or database write fails. */
    private function withFileMoves(callable $operation): void
    {
        $moves = [];
        $this->db->transBegin();
        try {
            $operation($moves);
            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('The Trash changes could not be saved.');
            }
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            foreach (array_reverse($moves) as [$source, $destination]) {
                try {
                    service('storage')->relocateDatabaseFile($destination, $source);
                } catch (\Throwable $rollbackException) {
                    log_message('critical', 'Trash rollback failed: {message}', ['message' => $rollbackException->getMessage()]);
                    throw new RuntimeException('A storage rollback failed. Keep the files in Trashed and contact the administrator.', 0, $exception);
                }
            }
            if (method_exists($this->db, 'resetTransStatus')) {
                $this->db->resetTransStatus();
            }
            throw $exception;
        }
        foreach ($moves as [$source]) {
            $this->removeEmptyTrashDirectory($source);
        }
    }

    private function removeEmptyTrashDirectory(string $path): void
    {
        if (preg_match('#^writable/uploads/Trashed/[0-9]+/[^/]+$#', $path)) {
            $directory = service('storage')->root() . '/' . dirname(substr($path, strlen('writable/uploads/')));
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
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
            $deletedTimestamp = $deletedAt === '' ? false : strtotime($deletedAt . ' UTC');
            if ($deletedTimestamp === false) {
                $file['trash_expires_at'] = '-';
                $file['trash_days_remaining'] = self::RETENTION_DAYS;
                continue;
            }

            $expiresTimestamp = $deletedTimestamp + (self::RETENTION_DAYS * 86400);
            $remainingSeconds = $expiresTimestamp - $now;
            $file['trash_expires_at'] = gmdate('Y-m-d H:i:s', $expiresTimestamp);
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
        if ((int) ($file['is_deleted'] ?? 0) !== 1) {
            throw new RuntimeException('Only files in Trash can be permanently deleted.');
        }
        $artifactPaths = $this->managedArtifactPaths($file);

        $this->deletePhysicalArtifacts($artifactPaths);
        $this->deleteMediaDatabaseRows($file);
        $this->removeEmptyTrashDirectory((string) $file['filepath']);

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

        $files = $this->db->table('files f')->select('f.*')
            ->join('trashfiles tf', 'tf.file_id = f.id', 'inner')
            ->where('tf.folder_root_id', $rootId)->where('f.is_deleted', 1)->get()->getResultArray();
        $fileIds = array_map('intval', array_column($files, 'id'));

        try {
            $this->withFileMoves(function (array &$moves) use ($files, $renamed, $pathUpdates, $rootId, $restoredName, $folderIds): void {
                foreach ($files as $file) {
                    $destination = null;
                    if ($renamed && isset($pathUpdates[(int) $file['folder_id']])) {
                        $metadata = $this->db->table('trashfiles')->where('file_id', (int) $file['id'])->get()->getRowArray();
                        $originalPath = (string) ($metadata['original_filepath'] ?? $file['filepath']);
                        $destination = 'writable/uploads/' . $pathUpdates[(int) $file['folder_id']] . '/' . basename($originalPath);
                    }
                    $this->restoreMediaWithinTransaction($file, $moves, $destination);
                }
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
                    'is_deleted' => 0, 'deleted_at' => null, 'deleted_by' => null,
                    'deletion_reason' => null, 'trash_root_id' => null,
                ]);
            });
        } catch (\Throwable $exception) {
            $this->removeEmptyDirectories($createdDirectories);
            throw $exception;
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

        $paths = array_values(array_filter(array_map(static fn (array $item): string => trim((string) ($item['path'] ?? '')), $folders)));
        usort($paths, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ($paths as $path) {
            $this->removeTrashedFolderDirectory($path);
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

        $this->recordAudit($actor, $request, 'folder', $auditAction, 'folder', $rootId, 'Permanently deleted folder "' . $folder['name'] . '" and ' . count($files) . ' file(s) from Trash.');

        return count($files);
    }

    /** Remove Finder metadata without deleting untracked media or following links. */
    private function removeTrashedFolderDirectory(string $path): void
    {
        $storage = service('storage');
        $path = $storage->normalizeRelativePath($path, false);
        $candidate = $storage->root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_link($candidate)) {
            throw new RuntimeException('The storage folder "' . basename($path) . '" is a symbolic link and cannot be removed automatically.');
        }
        if (! is_dir($candidate)) {
            return;
        }

        $directory = $storage->directory($path);
        $metadataFiles = [];
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->getFilename() === '.DS_Store' && $entry->isFile() && ! $entry->isLink()) {
                $metadataFiles[] = $entry->getPathname();
                continue;
            }
            throw new RuntimeException('The folder "' . basename($path) . '" still contains untracked files or subfolders. Review its storage contents before permanently deleting it.');
        }
        foreach ($metadataFiles as $metadataFile) {
            if (! @unlink($metadataFile)) {
                throw new RuntimeException('The hidden folder metadata in "' . basename($path) . '" could not be removed. Check storage permissions.');
            }
        }
        if (! @rmdir($directory) && is_dir($directory)) {
            throw new RuntimeException('The physical folder "' . basename($path) . '" could not be removed from storage. Check its contents and storage permissions.');
        }
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

        // Department deliveries have their own records and deletion lifecycle.
        // Their paths must never be collected as artifacts of an admin original.

        // Pending duplicate uploads are physical queue copies associated with
        // this original. Restored duplicates are excluded because their path
        // is owned by a separate files record.
        if ($this->db->tableExists('duplicate_media')) {
            foreach ($this->db->table('duplicate_media')->select('filepath')->where('original_file_id', (int) $file['id'])->where('status', 'pending')->get()->getResultArray() as $duplicate) {
                $duplicatePath = trim((string) ($duplicate['filepath'] ?? ''));
                if ($duplicatePath !== '') {
                    $paths[] = $duplicatePath;
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
            $storage->deleteDatabaseFile($path);
        }
    }

    /** @param array<string, mixed> $file */
    private function deleteMediaDatabaseRows(array $file): void
    {
        $fileId = (int) $file['id'];
        $metadata = $this->db->table('trashfiles')->where('file_id', $fileId)->get()->getRowArray();
        $filepath = (string) ($metadata['original_filepath'] ?? $file['filepath'] ?? '');

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
        if ($this->db->tableExists('media_assignments')) {
            $this->db->table('media_assignments')->where('media_id', $fileId)->where('status !=', 'completed')->delete();
        }
        if ($this->db->tableExists('duplicate_media')) {
            $this->db->table('duplicate_media')->where('original_file_id', $fileId)->where('status', 'pending')->delete();
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
            'ip_address'  => null,
            'created_at'  => Time::now('UTC')->toDateTimeString(),
        ]);
    }
}
