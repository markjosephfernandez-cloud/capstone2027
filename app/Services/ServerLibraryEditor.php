<?php

namespace App\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Storage;
use RuntimeException;

/** Direct share edits. Private metadata and recoverable Trash stay on the share. */
class ServerLibraryEditor
{
    private const PRIVATE_DIRECTORY = '.greyhound-library';

    public function __construct(protected readonly ServerLibraryService $library, protected readonly Storage $config = new Storage())
    {
    }

    public function state(): array
    {
        if ($this->config->centralized()) {
            $record = db_connect()->table('server_metadata')->where('source_key',$this->library->catalogSourceKey())->get()->getRowArray();
            if ($record) {
                return json_decode($record['payload'], true, 512, JSON_THROW_ON_ERROR);
            }
        }
        $directory = $this->privateDirectory(false);
        $path = $directory . '/metadata.json';
        $this->rejectLink($path);
        if (! file_exists($path)) {
            return ['tags' => [], 'trash' => [], 'media' => [], 'duplicates' => []];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! is_array($data['tags'] ?? null) || ! is_array($data['trash'] ?? null)) {
            throw new RuntimeException('Server Library metadata cannot be read. Restore its metadata backup before editing.');
        }
        foreach (['media', 'duplicates'] as $map) {
            if (isset($data[$map]) && ! is_array($data[$map])) {
                throw new RuntimeException('Server Library metadata cannot be read.');
            }
        }
        return $data + ['media'=>[], 'duplicates'=>[]];
    }

    public function createFolder(string $folder, string $name): void
    {
        $this->edit(function (array &$state, array &$undo) use ($folder, $name): void {
            $target = $this->destination($folder, $name);
            if (! @mkdir($target, 0775)) {
                throw new RuntimeException('The server folder could not be created. Check write access.');
            }
            $undo[] = static fn () => rmdir($target);
        });
        $this->library->invalidateCounts($folder);
        $this->refreshSavedCatalog([trim($folder . '/' . $name, '/')]);
    }

    protected function refreshSavedCatalog(array $paths): void
    {
        try { (new ServerLibraryCatalogWriter($this->library))->sync($paths, $this->state()); }
        catch (\Throwable $error) {
            // The originals are already committed. Never retry the upload as if it failed.
            log_message('warning', 'Saved server items need catalog refresh: {message}', ['message'=>$error->getMessage()]);
        }
    }

    public function rename(string $path, string $name): void
    {
        $this->move([$path], $this->parent($path), $name);
    }

    public function move(array $paths, string $folder, ?string $newName = null): void
    {
        $paths = $this->selection($paths);
        $this->edit(function (array &$state, array &$undo) use ($paths, $folder, $newName): void {
            $targets = [];
            foreach ($paths as $path) {
                $source = $this->item($path);
                $name = $newName ?? basename($path);
                $target = $this->destination($folder, $name);
                if (is_dir($source) && str_starts_with($target, $source . '/')) {
                    throw new RuntimeException('A folder cannot be moved into itself.');
                }
                if (isset($targets[$target])) {
                    throw new RuntimeException('Selected items have the same name. Move them separately.');
                }
                $targets[$target] = [$path, $source, ($folder === '' ? '' : $folder . '/') . $name];
            }
            foreach ($targets as $target => [$path, $source, $next]) {
                $this->relocate($source, $target, $undo);
                if ($this->config->centralized()) {
                    (new CentralMediaService($this->library))->move($path, $next);
                }
                foreach (['tags', 'media'] as $map) {
                    foreach (array_keys($state[$map]) as $key) {
                        if ($key === $path || str_starts_with($key, $path . '/')) {
                            $state[$map][$next . substr($key, strlen($path))] = $state[$map][$key];
                            unset($state[$map][$key]);
                        }
                    }
                }
                foreach ($state['duplicates'] as &$duplicate) {
                    foreach (['original','path'] as $field) {
                        $value = $duplicate[$field];
                        if ($value === $path || str_starts_with($value, $path . '/')) {
                            $duplicate[$field] = $next . substr($value, strlen($path));
                        }
                    }
                }
                unset($duplicate);
            }
        });
        foreach ($paths as $path) {
            $next = ($folder === '' ? '' : $folder.'/').($newName ?? basename($path));
            try { (new ServerLibraryCatalogWriter($this->library))->move($path,$next); }
            catch (\Throwable $error) { log_message('warning','Moved server items need catalog refresh: {message}',['message'=>$error->getMessage()]); }
        }
        $this->refreshSavedCatalog(array_map(static fn ($path) => ($folder === '' ? '' : $folder.'/').($newName ?? basename($path)), $paths));
        foreach (array_unique(array_merge([$folder], array_map($this->parent(...), $paths))) as $parent) {
            $this->library->invalidateCounts($parent);
        }
    }

    public function addTags(array $paths, array $tags): void
    {
        $paths = $this->selection($paths);
        $normalized = [];
        foreach ($tags as $tag) {
            if (! is_string($tag) || mb_strlen(trim($tag)) > 50 || preg_match('/[\x00-\x1f]/', $tag)) {
                throw new RuntimeException('Tags must be text of up to 50 characters.');
            }
            $tag = trim($tag);
            if ($tag !== '') {
                $normalized[mb_strtolower($tag)] = $tag;
            }
        }
        if ($normalized === [] || count($normalized) > 25) {
            throw new RuntimeException('Enter between 1 and 25 tags.');
        }
        $this->edit(function (array &$state) use ($paths, $normalized): void {
            foreach ($paths as $path) {
                $this->library->resolve($path);
                $merged = [];
                foreach ($state['tags'][$path] ?? [] as $tag) {
                    $merged[mb_strtolower($tag)] = $tag;
                }
                $merged = array_replace($merged, $normalized);
                if (count($merged) > 25) {
                    throw new RuntimeException('A file can have up to 25 tags.');
                }
                $state['tags'][$path] = array_values($merged);
                if ($this->config->centralized()) {
                    $identity = (new CentralMediaService($this->library))->identity($path);
                    if ($identity['file_id']) {
                        db_connect()->table('files')->where('id', $identity['file_id'])->update(['tags' => implode(', ', $state['tags'][$path])]);
                    }
                }
            }
        });
        $this->refreshSavedCatalog($paths);
    }

    public function trash(array $paths, int $actorId): void
    {
        $paths = $this->selection($paths);
        $filePaths = [];
        $this->edit(function (array &$state, array &$undo) use ($paths, $actorId, &$filePaths): void {
            foreach ($paths as $path) {
                $this->item($path);
            }
            foreach ($paths as $path) {
                $source = $this->item($path);
                $id = bin2hex(random_bytes(16));
                $storedName = $id . '--' . mb_strcut(basename($path), 0, 200, 'UTF-8');
                $target = $this->trashDirectory(true) . '/' . $storedName;
                $directory = is_dir($source);
                if (! $directory) {
                    $filePaths[] = $path;
                }
                $central = $this->config->centralized() ? new CentralMediaService($this->library) : null;
                $identity = $central?->identity($path, $directory);
                $this->relocate($source, $target, $undo);
                // A file cannot have descendants. Restrict the update to its
                // indexed identity instead of scanning every path in the source.
                $identities = $central?->markDeleted($path, true, $directory ? [] : [$identity['id']]) ?? [];
                $state['trash'][$id] = ['stored_name' => $storedName, 'path' => $path, 'deleted_at' => gmdate('Y-m-d H:i:s'), 'deleted_by' => $actorId, 'tags' => [], 'media'=>[]];
                if ($identities) {
                    $state['trash'][$id]['identities'] = $identities;
                }
                foreach (['tags', 'media'] as $map) {
                    foreach (array_keys($state[$map]) as $key) {
                        if ($key === $path || str_starts_with($key, $path . '/')) {
                            $state['trash'][$id][$map][$key] = $state[$map][$key];
                            unset($state[$map][$key]);
                        }
                    }
                }
            }
        });
        foreach (array_unique(array_map($this->parent(...), $paths)) as $parent) {
            $this->library->invalidateCounts($parent);
        }
        $this->refreshTrashCatalog($paths, false, $filePaths);
    }

    public function restore(string $id, ?string $destinationFolder = null): void
    {
        $parent = '';
        $restoredPath = '';
        $this->edit(function (array &$state, array &$undo) use ($id, &$parent, &$restoredPath, $destinationFolder): void {
            $record = $this->trashRecord($state, $id);
            if (! empty($record['deleting'])) {
                throw new RuntimeException('Permanent deletion was interrupted. Retry Delete selected to finish removing this item.');
            }
            if (!empty($record['original_unknown'])) {
                if ($destinationFolder === null) {
                    throw new RuntimeException('Choose a destination for this item; its original location was not recorded.');
                }
                $path = ($destinationFolder === '' ? '' : $this->library->relative($destinationFolder) . '/') . $record['path'];
            } else $path = $this->library->relative($record['path']);
            $restoredPath = $path;
            $parent = $this->parent($path);
            $target = $this->destination($parent, basename($path));
            $source = $this->storedTrashPath($id, $record);
            $this->rejectLink($source);
            if (! file_exists($source)) {
                throw new RuntimeException('The trashed item is unavailable on the server.');
            }
            $this->relocate($source, $target, $undo);
            if ($this->config->centralized()) {
                (new CentralMediaService($this->library))->markDeleted($path, false, $record['identities'] ?? []);
            }
            foreach (['tags', 'media'] as $map) {
                $state[$map] = array_replace($state[$map], $record[$map] ?? []);
            }
            unset($state['trash'][$id]);
        });
        $this->library->invalidateCounts($parent);
        $this->refreshTrashCatalog([$restoredPath], true);
        $this->refreshSavedCatalog([$restoredPath]);
    }

    /** Include existing Trash manifests without copying server originals locally. */
    public function trashItems(): array
    {
        $items = [];
        $records = $this->state()['trash'];
        $directory = $this->trashDirectory(false);
        $tracked = array_filter(array_column($records, 'stored_name'));
        if (is_dir($directory)) {
            $entries = @scandir($directory);
            if ($entries === false) {
                throw new RuntimeException('Server TRASH cannot be read.');
            }
            foreach ($entries as $name) {
                if (str_starts_with($name, '.') || in_array($name, $tracked, true) || is_link($directory . '/' . $name)) {
                    continue;
                }
                $id = 'external-' . bin2hex($name);
                $records[$id] ??= $this->trashRecord(['trash'=>$records], $id);
            }
        }
        // Unknown deletion dates get a persisted grace period, never the file's mtime.
        foreach ($records as $id => &$record) {
            if ($this->retentionTimestamp($record) !== null) {
                continue;
            }
            $this->edit(function (array &$state) use ($id, &$record): void {
                $current = $this->trashRecord($state, (string) $id);
                if ($this->retentionTimestamp($current) === null) {
                    $current['retention_started_at'] = gmdate('Y-m-d H:i:s');
                }
                $state['trash'][$id] = $record = $current;
            });
        }
        unset($record);
        foreach ($records as $id => $record) {
            if (! preg_match('/^(?:[a-f0-9]{32}|external-[a-f0-9]+)$/D', (string) $id)) {
                continue;
            }
            $original = !empty($record['original_unknown']) ? (string)$record['path'] : $this->library->relative((string)$record['path']);
            $path = $this->storedTrashPath($id, $record);
            $this->rejectLink($path);
            $isDirectory = is_dir($path);
            $items[] = array_merge($record, [
                'id'=>$id, 'name'=>basename($original), 'directory'=>$isDirectory,
                'filetype'=>$isDirectory ? '' : strtolower(pathinfo($original, PATHINFO_EXTENSION)),
                'size'=>is_file($path) ? (int)filesize($path) : 0,
                'trash_days_remaining'=>max(0, (int) ceil((($this->retentionTimestamp($record) + TrashService::RETENTION_DAYS * 86400) - time()) / 86400)),
                'trash_expires_at'=>gmdate('M d, Y H:i', $this->retentionTimestamp($record) + TrashService::RETENTION_DAYS * 86400) . ' UTC',
            ]);
        }
        usort($items, static fn ($a, $b) => strcmp($b['deleted_at'], $a['deleted_at']) ?: strcmp($a['id'], $b['id']));
        return $items;
    }

    /** Resolve only a recorded Trash item; never accept a physical path from a client. */
    public function previewPath(string $id, string $child = ''): string
    {
        return $this->resolveTrashEntry($id, $child, false);
    }

    /** Read one folder level without changing manifests or running retention cleanup. */
    public function previewFolder(string $id, string $child = ''): array
    {
        $record = $this->trashRecord($this->state(), $id);
        $directory = $this->resolveTrashEntry($id, $child, true, $record);
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Trash folder unavailable.');
        }
        $items = [];
        foreach ($entries as $name) {
            if (ServerLibraryService::isMetadataName($name) || is_link($directory . '/' . $name)) {
                continue;
            }
            $relative = $child === '' ? $name : $child . '/' . $name;
            try {
                $isDirectory = is_dir($directory . '/' . $name);
                $path = $this->resolveTrashEntry($id, $relative, $isDirectory, $record);
                $items[] = ['name'=>$name, 'child'=>$relative, 'directory'=>$isDirectory,
                    'filetype'=>strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                    'size'=>$isDirectory ? 0 : (int) filesize($path)];
            } catch (RuntimeException) {
                continue;
            }
        }
        usort($items, static fn ($a, $b) => ($b['directory'] <=> $a['directory']) ?: strnatcasecmp($a['name'], $b['name']));
        return ['record'=>$record, 'items'=>$items];
    }

    private function resolveTrashEntry(string $id, string $child, bool $directory, ?array $record = null): string
    {
        if (!preg_match('/^(?:[a-f0-9]{32}|external-[a-f0-9]+)$/D', $id)) {
            throw new RuntimeException('Invalid Trash item.');
        }
        $record ??= $this->trashRecord($this->state(), $id);
        if (! empty($record['deleting'])) {
            throw new RuntimeException('This item is being deleted.');
        }
        $base = $this->storedTrashPath($id, $record);
        $this->rejectLink($base);
        $resolvedBase = realpath($base);
        if ($resolvedBase === false) {
            throw new RuntimeException('Trash media is unavailable.');
        }
        $path = $base;
        if ($child !== '') {
            $this->library->relative($child);
            if (! is_dir($base)) {
                throw new RuntimeException('Invalid Trash folder.');
            }
            foreach (explode('/', $child) as $part) {
                $path .= '/' . $part;
                $this->rejectLink($path);
            }
        }
        $resolved = realpath($path);
        if ($resolved === false || ($resolved !== $resolvedBase && !str_starts_with($resolved, $resolvedBase . DIRECTORY_SEPARATOR))
            || ($directory ? !is_dir($resolved) : !is_file($resolved)) || !is_readable($resolved)) {
            throw new RuntimeException('Trash media is unavailable.');
        }
        return $resolved;
    }

    private function retentionTimestamp(array $record): ?int
    {
        $value = (string) ($record['retention_started_at'] ?? $record['deleted_at'] ?? '');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d H:i:s') === $value ? $date->getTimestamp() : null;
    }

    public function purgeExpiredTrash(int $limit = 500): int
    {
        $cutoff = time() - TrashService::RETENTION_DAYS * 86400;
        $purged = 0;
        $attempted = 0;
        foreach ($this->trashItems() as $item) {
            $timestamp = $this->retentionTimestamp($item);
            if ($timestamp === null || $timestamp > $cutoff) {
                continue;
            }
            if ($attempted++ >= max(1, min(5000, $limit))) {
                break;
            }
            try {
                if (! $this->deleteTrashItem($item['id'], $cutoff)) {
                    continue;
                }
                $purged++;
                service('audit')->record(['employee_id'=>0, 'name'=>'Trash retention cleanup', 'department'=>'System', 'role'=>'system'], null, 'delete', 'AUTO_SERVER_TRASH_PURGE', 'server_library', null, 'Permanently deleted server Trash item ' . $item['id']);
            } catch (\Throwable $exception) {
                log_message('error', 'Server Trash cleanup failed for {id}: {message}', ['id'=>$item['id'], 'message'=>$exception->getMessage()]);
            }
        }
        return $purged;
    }

    /** Capture the readable path before restore/delete removes the Trash record. */
    public function trashAuditLabel(string $id): string
    {
        $record = $this->trashRecord($this->state(), $id);
        $path = (string) $record['path'];
        return ! empty($record['original_unknown']) ? $path . ' (original path unknown)' : $path;
    }

    public function permanentlyDelete(string $id): void
    {
        $this->deleteTrashItem($id);
    }

    private function deleteTrashItem(string $id, ?int $cutoff = null): bool
    {
        $deleted = false;
        $this->edit(function (array &$state) use ($id, $cutoff, &$deleted): void {
            // Recheck under the edit lock so a restored item cannot be purged.
            if ($cutoff !== null && !isset($state['trash'][$id])) return;
            $record = $this->trashRecord($state, $id);
            if ($cutoff !== null) {
                $timestamp = $this->retentionTimestamp($record);
                if ($timestamp === null || $timestamp > $cutoff) {
                    return;
                }
            }
            $directory = $this->privateDirectory(true);
            $path = $this->storedTrashPath($id, $record);
            $this->rejectLink($path);
            // Preserve the type before removal so retries keep exact file lookups.
            if (! array_key_exists('directory', $record)) {
                $record['directory'] = ! is_file($path);
            }
            $state['trash'][$id] = $record;
            // Persist intent first: a failed or interrupted recursive removal is
            // retryable and must not offer restoration of an incomplete folder.
            $state['trash'][$id]['deleting'] = true;
            $this->saveState($state, $directory);
            $this->removeTrashedPath($path);
            if ($this->config->centralized()) {
                (new ServerTrashReconciler($this->library))->reconcile($record);
            }
            unset($state['trash'][$id]);
            $deleted = true;
        });
        return $deleted;
    }

    private function refreshTrashCatalog(array $paths, bool $restored, array $filePaths = []): void
    {
        try {
            $db = db_connect();
            if (! $db->tableExists('server_library_index')) {
                return;
            }
            $source = $this->library->catalogSourceKey();
            foreach ($paths as $path) {
                foreach (['server_library_index', 'server_library_scan_queue'] as $table) {
                    $query = $db->table($table)->where('source_key', $source);
                    if (in_array($path, $filePaths, true)) {
                        $query->where('path_hash', hash('sha256', $path));
                    } else {
                        $query->groupStart()->where('path', $path)
                            ->orLike('path', $path . '/', 'after')->groupEnd();
                    }
                    $query->delete();
                }
                if ($restored) {
                    $parent = $this->parent($path);
                    $db->table('server_library_scan_queue')->ignore(true)->insert([
                        'source_key'=>$source, 'path'=>$parent, 'path_hash'=>hash('sha256', $parent), 'entry_offset'=>0, 'priority'=>1,
                    ]);
                    $db->table('server_library_scan_queue')->where('source_key', $source)->where('path_hash', hash('sha256', $parent))->update(['entry_offset'=>0, 'priority'=>1]);
                }
            }
        } catch (\Throwable $error) {
            // The NAS operation succeeded. A later scan repairs catalog state.
            log_message('warning', 'Trash catalog refresh failed: {message}', ['message'=>$error->getMessage()]);
        }
    }

    private function trashDirectory(bool $create): string
    {
        $path = $this->library->root() . '/TRASH';
        $this->rejectLink($path);
        if ($create && ! is_dir($path) && ! @mkdir($path, 0770) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create server TRASH. Check write access.');
        }
        if (file_exists($path) && ! is_dir($path)) {
            throw new RuntimeException('Server TRASH must be a directory.');
        }
        return $path;
    }

    private function storedTrashPath(string $id, array $record): string
    {
        if (isset($record['stored_name'])) {
            $name = $record['stored_name'];
            if (! is_string($name) || $name === '' || basename($name) !== $name || str_starts_with($name, '.') || str_contains($name, "\x00") || str_contains($name, '\\')) {
                throw new RuntimeException('Invalid Trash metadata.');
            }
            $path = $this->trashDirectory(false) . '/' . $name;
        } else {
            if (! preg_match('/^[a-f0-9]{32}$/D', $id)) {
                throw new RuntimeException('Invalid Trash item.');
            }
            // Keep older deletions restorable in their original private storage.
            $path = $this->privateDirectory(false) . '/trash-' . $id;
        }
        $this->rejectLink($path);
        return $path;
    }

    private function trashRecord(array $state, string $id): array
    {
        if (isset($state['trash'][$id])) {
            return $state['trash'][$id];
        }
        if (! preg_match('/^external-([a-f0-9]{2,510})$/D', $id, $match) || strlen($match[1]) % 2 !== 0) {
            throw new RuntimeException('This Trash item is unavailable.');
        }
        $name = hex2bin($match[1]);
        $record = ['stored_name'=>$name, 'path'=>$name, 'original_unknown'=>true, 'deleted_at'=>'Unknown', 'deleted_by'=>0, 'tags'=>[], 'media'=>[]];
        $path = $this->storedTrashPath($id, $record);
        if (! file_exists($path)) {
            throw new RuntimeException('This Trash item is unavailable.');
        }
        return $record;
    }

    private function removeTrashedPath(string $path): void
    {
        // Unlink a child link itself; never traverse its target.
        if (is_link($path) || is_file($path)) {
            if (! @unlink($path)) {
                throw new RuntimeException('Permanent deletion could not finish. Check server write access and retry.');
            }
        } elseif (is_dir($path)) {
            $children = @scandir($path);
            if ($children === false) {
                throw new RuntimeException('The deleted folder cannot be read. Check the server connection and retry.');
            }
            foreach ($children as $name) {
                if ($name !== '.' && $name !== '..') {
                    $this->removeTrashedPath($path . '/' . $name);
                }
            }
            if (! @rmdir($path)) {
                throw new RuntimeException('The deleted folder could not be removed. Check server write access and retry.');
            }
        }
    }

    /** Validate the entire batch before copying. Never overwrite an existing item. */
    public function upload(string $folder, array $uploads): void
    {
        if ($uploads === [] || count($uploads) > 50) {
            throw new RuntimeException('Choose between 1 and 50 files.');
        }
        $this->edit(function (array &$state, array &$undo) use ($folder, $uploads): void {
            $targets = [];
            foreach ($uploads as $upload) {
                if (! $upload instanceof UploadedFile || ! $upload->isValid() || $upload->hasMoved()) {
                    throw new RuntimeException('An upload is incomplete. Check the server upload size limits.');
                }
                $name = $upload->getClientName();
                $target = $this->destination($folder, $name);
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (! in_array($extension, ['jpg','jpeg','png','gif','webp','mp4','mov','avi','mkv','webm','m4v','mp3','wav','ogg','pdf','doc','docx','xls','xlsx'], true)
                    || ! in_array($upload->getMimeType(), $this->config->allowedMimeTypes(), true)) throw new RuntimeException('This file type is not supported: ' . $name);
                if ($this->config->maxUploadBytes() > 0 && $upload->getSize() > $this->config->maxUploadBytes()) {
                    throw new RuntimeException('The file exceeds the configured upload limit: ' . $name);
                }
                if (isset($targets[$target])) {
                    throw new RuntimeException('The upload contains duplicate filenames.');
                }
                $targets[$target] = $upload;
            }
            $staged = [];
            foreach ($targets as $target => $upload) {
                $temporary = $this->privateDirectory(true) . '/upload-' . bin2hex(random_bytes(16));
                $output = @fopen($temporary, 'xb');
                if ($output === false) {
                    throw new RuntimeException('The file could not be created. Check server write access.');
                }
                $undo[] = static fn () => !file_exists($temporary) || unlink($temporary);
                $input = null;
                try {
                    $input = @fopen($upload->getTempName(), 'rb');
                    if ($input === false || stream_copy_to_stream($input, $output) !== $upload->getSize() || ! fflush($output)) {
                        throw new RuntimeException('The upload could not be saved completely. Try again.');
                    }
                } finally {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    fclose($output);
                }
                $staged[$target] = $temporary;
            }
            foreach ($staged as $target => $temporary) {
                $this->relocate($temporary, $target, $undo);
            }
        });
        $this->library->invalidateCounts($folder);
        $this->refreshSavedCatalog(array_map(static fn ($upload) => trim($folder . '/' . $upload->getClientName(), '/'), $uploads));
    }

    private function selection(array $paths): array
    {
        if ($paths === [] || count($paths) > 50 || count(array_filter($paths, 'is_string')) !== count($paths)) {
            throw new RuntimeException('Select between 1 and 50 items.');
        }
        $paths = array_values(array_unique($paths));
        foreach ($paths as $path) {
            if ($path === '') {
                throw new RuntimeException('The server root cannot be changed.');
            }
            $this->library->relative($path);
            foreach ($paths as $other) {
                if ($other !== $path && str_starts_with($path, $other . '/')) {
                    throw new RuntimeException('Select the parent folder without also selecting its contents.');
                }
            }
        }
        return $paths;
    }

    private function item(string $path): string
    {
        if ($path === '') {
            throw new RuntimeException('The server root cannot be changed.');
        }
        try { return $this->library->resolve($path); }
        catch (RuntimeException) { return $this->library->resolve($path, true); }
    }

    protected function destination(string $folder, string $name): string
    {
        if ($name !== trim($name) || strlen($name) > 240 || preg_match('/[\x00-\x1f\x7f\/\\\\:*?"<>|]/', $name) || str_ends_with($name, '.')) {
            throw new RuntimeException('Enter a valid file or folder name.');
        }
        $this->library->relative(($folder === '' ? '' : $folder . '/') . $name);
        if ($name === '') {
            throw new RuntimeException('Enter a name.');
        }
        $directory = $this->library->resolve($folder, true);
        if (! is_writable($directory)) {
            throw new RuntimeException('This server folder is read-only. Ask the server administrator for write access.');
        }
        $target = $directory . '/' . $name;
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('An item with that name already exists. Choose another name.');
        }
        return $target;
    }

    protected function parent(string $path): string
    {
        return dirname($path) === '.' ? '' : dirname($path);
    }

    protected function relocate(string $source, string $target, array &$undo): void
    {
        if (file_exists($target) || is_link($target) || ! @rename($source, $target)) {
            throw new RuntimeException('The item could not be moved. Check server access and destination.');
        }
        $undo[] = static fn () => ! file_exists($source) && ! is_link($source) && rename($target, $source);
    }

    protected function rejectLink(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Linked server metadata paths are unavailable.');
        }
    }

    protected function privateDirectory(bool $create): string
    {
        $path = $this->library->root() . '/' . self::PRIVATE_DIRECTORY;
        $this->rejectLink($path);
        if ($create && ! is_dir($path) && ! @mkdir($path, 0770) && ! is_dir($path)) {
            throw new RuntimeException('The server share is read-only. Ask the server administrator for write access.');
        }
        return $path;
    }

    protected function edit(callable $operation): void
    {
        $directory = $this->privateDirectory(true);
        $lockPath = $directory . '/edit.lock';
        $this->rejectLink($lockPath);
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Server editing is unavailable. Check write access.');
        }
        $undo = [];
        $db = $this->config->centralized() ? db_connect() : null;
        $transaction = false;
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another server edit is in progress. Try again shortly.');
            }
            if ($db) {
                $db->transBegin();
                $transaction = true;
            }
            $state = $this->state();
            $operation($state, $undo);
            $this->saveState($state, $directory);
            if ($db && (! $db->transStatus() || ! $db->transCommit())) {
                throw new RuntimeException('Server metadata transaction failed.');
            }
            $transaction = false;
        } catch (\Throwable $exception) {
            if ($transaction) {
                $db->transRollback();
            }
            foreach (array_reverse($undo) as $rollback) {
                if (! $rollback()) {
                    log_message('critical', 'A Server Library edit could not be rolled back. Inspect the server share before retrying.');
                }
            }
            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function saveState(array $state, string $directory): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($this->config->centralized()) {
            if (! db_connect()->table('server_metadata')->upsert(['source_key' => $this->library->catalogSourceKey(), 'payload' => $json, 'updated_at' => gmdate('Y-m-d H:i:s')])) {
                throw new RuntimeException('Server metadata could not be saved.');
            }
            return;
        }
        $temp = $directory . '/metadata-' . bin2hex(random_bytes(12)) . '.tmp';
        try {
            if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || ! @rename($temp, $directory . '/metadata.json')) {
                throw new RuntimeException('Server metadata could not be saved.');
            }
        } finally { if (is_file($temp)) unlink($temp); }
    }

    public function saveAnalysis(string $path, array $analysis): void
    {
        $this->edit(function (array &$state) use ($path,$analysis): void {
            $state['media'][$path]['passport'] = array_merge($state['media'][$path]['passport'] ?? [],$analysis);
            unset($state['media'][$path]['passport']['digital_signature']);
            $state['media'][$path]['passport']['digital_signature'] = hash('sha256',json_encode($state['media'][$path]['passport'],JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $state['tags'][$path] = $analysis['tags'] ?? array_values(array_unique(array_merge($state['tags'][$path] ?? [], array_filter([$analysis['event_name'] ?? null]))));
        });
        $this->refreshSavedCatalog([$path]);
    }
}
