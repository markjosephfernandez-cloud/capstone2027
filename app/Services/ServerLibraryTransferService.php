<?php

namespace App\Services;

use App\Libraries\RolePolicy;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Stages explicit selections for department review and mobile-app publishing. */
class ServerLibraryTransferService
{
    public function __construct(private BaseConnection $db, private ServerLibraryService $server, private StorageService $storage)
    {
    }

    public function send(array $paths, string $department, array $actor, ?callable $assign = null): array
    {
        if ((new \Config\Storage())->centralized()) {
            return (new CentralDistributionService($this->server, $this->storage))->deliver($paths, [], $department, $actor);
        }
        if (! RolePolicy::allows($actor, 'files.assign')) {
            throw new RuntimeException('Your account cannot send files.');
        }
        if ($paths === [] || count($paths) > 20 || count(array_filter($paths, 'is_string')) !== count($paths)) {
            throw new RuntimeException('Select between 1 and 20 files.');
        }
        if (!$this->db->table('user')->where('role','department')->where('department',$department)->where('is_active',1)->countAllResults()) {
            throw new RuntimeException('Choose an active department.');
        }
        $assign ??= static fn ($ids, $department, $actor) => service('distribution')->assign($ids, [], $department, null, 'direct', $actor);
        return $this->stage($paths, $actor, static fn ($ids) => $assign($ids, $department, $actor), 20, []);
    }

    public function direct(array $paths, array $folders, string $department, array $actor, ?callable $assign = null): array
    {
        if ((new \Config\Storage())->centralized()) {
            return (new CentralDistributionService($this->server, $this->storage))->deliver($paths, $folders, $department, $actor);
        }
        if (! RolePolicy::allows($actor, 'files.assign') || ! RolePolicy::allows($actor, 'files.view_all')) {
            throw new RuntimeException('Your account cannot send server files.');
        }
        if (! $this->db->table('user')->where('role', 'department')->where('department', $department)->where('is_active', 1)->countAllResults()) {
            throw new RuntimeException('Choose an active department.');
        }
        foreach ($folders as $folder) {
            if (! is_string($folder)) {
                throw new RuntimeException('Choose valid server folders.');
            }
            $this->server->resolve($folder, true);
        }
        $assign ??= static fn ($ids, $folderIds, $department, $actor) => service('distribution')->assign($ids, $folderIds, $department, null, 'direct', $actor);
        return $this->stage($paths, $actor, static function ($ids, $folderMap) use ($folders, $department, $actor, $assign) {
            $folderIds = [];
            foreach ($folders as $folder) {
                if (isset($folderMap[$folder])) {
                    $folderIds[] = $folderMap[$folder];
                }
            }
            return $assign($ids, $folderIds, $department, $actor);
        }, ServerDistributionPicker::MAX_FILES, $folders);
    }

    public function post(array $paths, string $title, string $description, array $actor): array
    {
        if (! RolePolicy::allows($actor, 'files.manage')) {
            throw new RuntimeException('Your account cannot post files.');
        }
        if ($paths === [] || count($paths) > 20) {
            throw new RuntimeException('Select between 1 and 20 files.');
        }
        if (mb_strlen($title) > 150 || mb_strlen($description) > 5000) {
            throw new RuntimeException('Enter a title of up to 150 characters and description of up to 5,000 characters.');
        }
        // Incoming drafts may be untitled. Validate explicit titles before copying.
        $title = trim($title) === '' ? '' : \App\Libraries\MediaTitle::validate($title);
        foreach ($paths as $path) {
            if (! is_string($path)) {
                throw new RuntimeException('Choose photos or videos to post.');
            }
            $mime = \App\Libraries\MediaMimeType::forFile($this->server->resolve($path));
            if (! is_string($mime) || ! str_starts_with($mime, 'image/') && ! str_starts_with($mime, 'video/')) {
                throw new RuntimeException('Only photos and videos can be posted.');
            }
        }
        return $this->stage($paths, $actor, function ($ids) use ($title, $description, $actor) {
            $posts = (new AppPublishingService())->receive($ids, trim($title), trim($description));
            if (! $this->db->table('files')->whereIn('id', $ids)->update(['published_by' => (int) $actor['employee_id']])) {
                throw new RuntimeException('The post could not be saved.');
            }
            return $posts;
        });
    }

    public function transmit(array $paths, array $folders, int $requestId, array $actor, ?callable $assign = null): array
    {
        if ((new \Config\Storage())->centralized()) {
            $request = $this->db->table('media_requests')->where('id',$requestId)->get()->getRowArray();
            if (! $request) {
                throw new RuntimeException('Choose an open transaction.');
            }
            return (new CentralDistributionService($this->server,$this->storage))->deliver($paths,$folders,$request['department'],$actor,$requestId);
        }
        if (! RolePolicy::allows($actor, 'files.assign') || ! RolePolicy::allows($actor, 'files.view_all')) {
            throw new RuntimeException('Your account cannot transmit server files.');
        }
        $request = $this->db->table('media_requests')->where('id', $requestId)->whereIn('status', ['submitted','pending_incomplete'])->get()->getRowArray();
        if ($request === null) {
            throw new RuntimeException('This request has already been processed or is unavailable.');
        }
        foreach ($folders as $folder) {
            if (! is_string($folder)) {
                throw new RuntimeException('Choose valid server folders.');
            }
            $this->server->resolve($folder, true);
        }
        $assign ??= static fn ($ids, $folderIds, $department, $id, $actor) => service('distribution')->assign($ids, $folderIds, $department, $id, 'request', $actor);
        return $this->stage($paths, $actor, function ($ids, $folderMap) use ($folders, $requestId, $request, $actor, $assign) {
            $folderIds = [];
            foreach ($folders as $folder) {
                if (isset($folderMap[$folder])) {
                    $folderIds[] = $folderMap[$folder];
                }
            }
            return $assign($ids, $folderIds, (string)$request['department'], $requestId, $actor);
        }, ServerDistributionPicker::MAX_FILES, $folders, $requestId);
    }

    private function stage(array $paths, array $actor, callable $complete, int $limit = 20, ?array $selectedFolders = null, ?int $requestId = null): array
    {
        if ($paths === [] || count($paths) > $limit || count(array_filter($paths, 'is_string')) !== count($paths)) {
            throw new RuntimeException('Select between 1 and ' . $limit . ' files.');
        }
        $paths = array_values(array_unique($paths));
        $sourceRoot = $this->server->root();
        if ((new \Config\Storage())->centralized()) {
            $identities = new CentralMediaService($this->server);
            $ids = array_map(fn ($path) => $identities->register($path,$actor),$paths);
            if ($this->db->table('files')->whereIn('id',$ids)->where('app_post_id IS NOT NULL',null,false)->countAllResults()) {
                throw new RuntimeException('Some selected media already belongs to a post. Open Published Files to edit that post.');
            }
            return $complete($ids,[]);
        }
        $this->storage->assertSeparateFromSource($sourceRoot);
        // Validate the complete selection before creating any local copy.
        foreach ($paths as $path) $this->server->resolve($path);
        $tags = (new ServerLibraryEditor($this->server))->state()['tags'];
        $created = [];
        $ids = [];
        $batch = 'Server transfers ' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $now = gmdate('Y-m-d H:i:s');
        // Own the outer transaction so failed nested assignment work can be rolled back fully.
        if ($this->db->transDepth !== 0 || !$this->db->transBegin()) {
            throw new RuntimeException('Could not start the department submission.');
        }
        try {
            $folderId = null;
            $folderMap = [];
            $postingCopies = [];
            // Publishing keeps its existing staging layout. Deliveries expose only selected folders.
            if ($selectedFolders === null) {
                if (! $this->db->table('folder')->insert(['name' => $batch, 'path' => $batch, 'parent_id' => null, 'is_deleted' => 0, 'created_at' => $now])) {
                    throw new RuntimeException('Could not create the local transfer folder.');
                }
                $folderId = (int)$this->db->insertID();
                $started = microtime(true);
                $postingCopies = $this->storage->storeServerCopies(
                    array_map(fn ($path) => $this->server->resolve($path), $paths), $batch
                );
                $created = array_column($postingCopies, 'filepath');
                log_message('info', 'Server post copied {count} files ({bytes} bytes) in {seconds} seconds.', [
                    'count'=>count($postingCopies), 'bytes'=>array_sum(array_column($postingCopies, 'size')),
                    'seconds'=>round(microtime(true) - $started, 3),
                ]);
            }
            $roots = $selectedFolders ?? [];
            usort($roots, static fn ($a, $b) => strlen($a) <=> strlen($b));
            foreach ($paths as $pathIndex => $path) {
                $destination = $batch;
                $fileFolderId = $folderId;
                foreach ($roots as $root) {
                    if (! str_starts_with($path, $root . '/')) {
                        continue;
                    }
                    // Drop ancestors above the selected root; overlapping selections share one tree.
                    $relative = substr($path, strlen($root) + 1);
                    $parts = [basename($root)];
                    if (dirname($relative) !== '.') {
                        $parts = array_merge($parts, explode('/', dirname($relative)));
                    }
                    $sourceFolder = $root;
                    $parentId = null;
                    $physical = ($requestId === null ? $batch : 'Server request ' . $requestId) . '/' . hash('sha256', $root);
                    foreach ($parts as $index => $part) {
                        if ($index > 0) {
                            $sourceFolder .= '/' . $part;
                        }
                        $physical .= '/' . $part;
                        if (!isset($folderMap[$sourceFolder])) {
                            // Reuse the same selected folder across correction cycles of this request.
                            $existing = $this->db->table('folder')->where('path',$physical)->where('is_deleted',0)->get()->getRowArray();
                            if ($existing) $folderMap[$sourceFolder] = (int)$existing['id'];
                            else {
                                if (! $this->db->table('folder')->insert(['name' => $part, 'path' => $physical, 'parent_id' => $parentId, 'is_deleted' => 0, 'created_at' => $now])) {
                                    throw new RuntimeException('Could not preserve the selected folder.');
                                }
                                $folderMap[$sourceFolder] = (int)$this->db->insertID();
                            }
                        }
                        $parentId = $folderMap[$sourceFolder];
                    }
                    $destination = $physical;
                    $fileFolderId = $parentId;
                    break;
                }
                if ($selectedFolders === null) {
                    $stored = $postingCopies[$pathIndex];
                } else {
                    $source = $this->server->resolve($path);
                    $stored = $this->storage->storeServerCopy($source, $destination);
                    $created[] = $stored['filepath'];
                }
                if (!$this->db->table('files')->insert([
                    'filename'=>$stored['filename'], 'display_title'=>mb_substr(pathinfo(basename($path),PATHINFO_FILENAME),0,150),
                    'filepath'=>$this->storage->databasePath($stored['filepath']), 'filetype'=>strtolower(pathinfo($stored['filename'],PATHINFO_EXTENSION)),
                    'mime_type'=>$stored['mimeType'], 'size'=>$stored['size'], 'folder_id'=>$fileFolderId,
                    'media_kind'=>str_starts_with($stored['mimeType'], 'image/') ? 'image' : (str_starts_with($stored['mimeType'], 'video/') ? 'video' : (str_starts_with($stored['mimeType'], 'audio/') ? 'audio' : 'document')),
                    'tags'=>implode(', ', $tags[$path] ?? []),
                    'employee_id'=>(int)$actor['employee_id'], 'dateupload'=>$now,'is_deleted'=>0,
                    'description'=>'Copied from Server Library for review or publishing.', 'last_action'=>'uploaded','last_action_at'=>$now,
                ])) throw new RuntimeException('Could not save the local transfer copy.');
                $ids[] = (int)$this->db->insertID();
            }
            $result = $complete($ids, $folderMap);
            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('Could not save the department submission.');
            }
            return $result;
        } catch (\Throwable $error) {
            while ($this->db->transDepth > 0) {
                if (! $this->db->transRollback()) {
                    break;
                }
            }
            // Only local paths returned by storeServerCopy are eligible for cleanup.
            foreach ($created as $path) $this->storage->deleteDatabaseFile($this->storage->databasePath($path));
            throw $error;
        }
    }
}
