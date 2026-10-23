<?php

namespace App\Services;

use App\Models\FileModel;
use App\Models\FolderModel;
use Config\Storage;
use FilesystemIterator;
use RuntimeException;
use Throwable;

/**
 * Imports server-side media into managed storage in small, resumable steps.
 * Job files contain server-generated relative paths only and are bound to the
 * employee who started the scan.
 */
class ServerScanService
{
    public function __construct(
        private readonly Storage $config = new Storage(),
        private readonly ?StorageService $storageService = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function begin(int $employeeId, ?array $selectedPaths = null, ?array $scanModes = null, string $previousJobId = '', int $selectionTotal = 0): array
    {
        if ($employeeId < 1) {
            throw new RuntimeException('A valid user is required to start a server scan.');
        }

        $previous = [];
        if ($scanModes !== null) {
            $scanModes = SelectedImageScan::validateModes($scanModes);
        }
        if ($previousJobId !== '') {
            $previous = $this->loadState($previousJobId);
            if ((int) $previous['employee_id'] !== $employeeId || empty($previous['complete']) || ($previous['scan_modes'] ?? null) !== $scanModes) {
                throw new RuntimeException('Invalid scan continuation.');
            }
        }
        $sourceRoot = $this->sourceRoot(true);
        $files = $selectedPaths === null ? $this->discoverPendingBatch($sourceRoot) : $this->validateSelection($sourceRoot, $selectedPaths);
        $destinationFolderId = $this->config->centralized() ? null : $this->ensureFolderHierarchy('');
        $jobId = bin2hex(random_bytes(16));
        $state = [
            'job_id'                => $jobId,
            'scan_modes'            => $scanModes,
            'selection_entries'     => $previous['selection_entries'] ?? [],
            'audit_id'              => $previous['audit_id'] ?? null,
            'audit_results'         => $previous['audit_results'] ?? [],
            'audit_total'           => max(count($files), (int) ($previous['audit_total'] ?? $selectionTotal)),
            'employee_id'           => $employeeId,
            'source_root'           => $sourceRoot,
            'files'                 => $files,
            'total'                 => count($files),
            'processed'             => 0,
            'imported'              => 0,
            'skipped'               => 0,
            'failed'                => 0,
            'errors'                => [],
            'destination_folder_id' => $destinationFolderId,
            'complete'              => $files === [],
            'created_at'            => gmdate('c'),
        ];
        $this->saveState($state);

        return $this->publicState($state, $files === [] ? 'No files are waiting in the Server Scan Inbox.' : 'Server files discovered.');
    }

    /** Browse one directory only; selecting a folder navigates rather than scanning it. */
    public function browse(string $folder = '', string $query = '', int $page = 1): array
    {
        $root = $this->sourceRoot(true);
        $directory = $folder === '' ? $root : $this->resolveSelectionPath($root, $folder);
        if (! is_dir($directory)) {
            throw new RuntimeException('This folder is unavailable.');
        }
        $items = [];
        $deadline = microtime(true) + 8;
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
            if (microtime(true) > $deadline || count($items) >= $this->config->maxScanFiles()) {
                throw new RuntimeException('This folder is too large to browse. Narrow the configured scan source.');
            }
            $path = ltrim($folder . '/' . $item->getFilename(), '/');
            if ($item->isLink() || $this->shouldIgnoreSourcePath($path) || ! $item->isReadable()) {
                continue;
            }
            if ($query !== '' && mb_stripos($item->getFilename(), $query) === false) {
                continue;
            }
            if (! $item->isDir() && ! in_array(strtolower($item->getExtension()), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                continue;
            }
            $items[] = ['path'=>$path, 'name'=>$item->getFilename(), 'directory'=>$item->isDir()];
        }
        usort($items, static fn ($a, $b) => ($b['directory'] <=> $a['directory']) ?: strnatcasecmp($a['name'], $b['name']));
        $pages = max(1, (int) ceil(count($items) / 40));
        $page = max(1, min($page, $pages));
        $items = array_slice($items, ($page - 1) * 40, 40);
        foreach ($items as &$entry) {
            if (!$entry['directory'] && $this->config->centralized()) {
                $entry['preview_url'] = site_url('workspace/server-library/media') . '?' . http_build_query(['path'=>$entry['path'], 'thumbnail'=>1]);
            }
        }
        unset($entry);
        return ['ok'=>true, 'items'=>$items, 'page'=>$page, 'pages'=>$pages];
    }

    private function resolveSelectionPath(string $root, string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || in_array('..', explode('/', $path), true) || $this->shouldIgnoreSourcePath($path)) {
            throw new RuntimeException('Invalid scan selection.');
        }
        $cursor = $root;
        foreach (explode('/', $path) as $part) {
            $cursor .= DIRECTORY_SEPARATOR . $part;
            if (is_link($cursor)) {
                throw new RuntimeException('Linked files cannot be scanned.');
            }
        }
        $resolved = realpath($cursor);
        if ($resolved === false || ! $this->isWithin($resolved, $root) || ! is_readable($resolved)) {
            throw new RuntimeException('A selected file or folder is unavailable.');
        }
        $trash = $this->storage()->root() . DIRECTORY_SEPARATOR . 'Trashed';
        if ($this->isWithin($resolved, $trash)) {
            throw new RuntimeException('Trashed files cannot be scanned.');
        }
        return $resolved;
    }

    private function validateSelection(string $root, array $paths): array
    {
        if (!$paths || count($paths) > min(100, $this->batchSize()) || count(array_filter($paths, 'is_string')) !== count($paths)) {
            throw new RuntimeException('Select between 1 and ' . min(100, $this->batchSize()) . ' images.');
        }
        $paths = array_values(array_unique($paths));
        foreach ($paths as $path) {
            $source = $this->resolveSelectionPath($root, $path);
            if (! is_file($source) || ! in_array(mime_content_type($source), ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                throw new RuntimeException('Select supported image files for AI scanning.');
            }
            if ($this->config->maxUploadBytes() > 0 && filesize($source) > $this->config->maxUploadBytes()) {
                throw new RuntimeException('A selected image exceeds the file size limit.');
            }
        }
        return $paths;
    }

    /** Resolve/register a selected server image for metadata extraction without running AI. */
    public function passportFile(string $path, int $employeeId): int
    {
        if ($employeeId < 1) {
            throw new RuntimeException('A valid administrator is required.');
        }
        $root = $this->sourceRoot(false);
        $this->validateSelection($root, [$path]);
        $result = $this->importFile($root, $path, bin2hex(random_bytes(16)), $employeeId, false);
        return (int) $result['file_id'];
    }

    /** @return array<string, mixed> */
    public function processNext(string $jobId, int $employeeId, ?array $actor = null): array
    {
        $lock = fopen($this->jobPath($jobId) . '.lock', 'c');
        if (! $lock) {
            throw new RuntimeException('Scan lock unavailable.');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('This scan is already processing an image.');
            }
            return $this->processLocked($jobId, $employeeId, $actor);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function processLocked(string $jobId, int $employeeId, ?array $actor): array
    {
        $state = $this->loadState($jobId);
        if ((int) ($state['employee_id'] ?? 0) !== $employeeId) {
            throw new RuntimeException('This scan belongs to another user.');
        }
        if (! empty($state['complete'])) {
            return $this->publicState($state, 'The server scan is complete.');
        }

        $index = (int) ($state['processed'] ?? 0);
        $relativePath = (string) ($state['files'][$index] ?? '');
        if ($relativePath === '') {
            $state['complete'] = true;
            $this->saveState($state);
            return $this->publicState($state, 'The server scan is complete.');
        }

        $message = '';
        $fileId = null;
        try {
            if (isset($state['scan_modes'])) {
                $this->resolveSelectionPath((string) $state['source_root'], $relativePath);
                $result = (new SelectedImageScan(new ServerLibraryService($this->config)))->process($relativePath, $employeeId, $state['scan_modes'], $state['selection_entries']);
            } else {
                $result = $this->importFile((string) $state['source_root'], $relativePath, $jobId, $employeeId);
            }
            $status = (string) $result['status'];
            $state[$status] = (int) ($state[$status] ?? 0) + 1;
            $fileId = isset($result['file_id']) ? (int) $result['file_id'] : null;
            $message = (string) $result['message'];
        } catch (Throwable $exception) {
            ++$state['failed'];
            $message = basename($relativePath) . ': ' . $exception->getMessage();
            if (count($state['errors']) < 10) {
                $state['errors'][] = $message;
            }
        }

        $state['audit_results'][] = ['path' => $relativePath, 'status' => $status ?? 'failed', 'message' => $message];
        if ($actor !== null) {
            $state['audit_id'] = service('audit')->recordServerScan($actor, $state['audit_id'] ?? null, $state['audit_results'], (int) ($state['audit_total'] ?? $state['total']));
        }

        ++$state['processed'];
        $state['complete'] = $state['processed'] >= $state['total'];
        $this->saveState($state);
        $payload = $this->publicState($state, $message);
        $payload['fileId'] = $fileId;
        $payload['filePath'] = $relativePath;
        $payload['fileStatus'] = $status ?? 'failed';
        $payload['analysisQueued'] = !isset($state['scan_modes']) && $this->config->centralized() && $fileId !== null && ($status ?? '') === 'imported';

        return $payload;
    }

    public function sourceLabel(): string
    {
        return $this->config->scanSourceRoot();
    }

    public function destinationLabel(): string
    {
        return $this->config->scanDestination();
    }

    public function endpointLabel(): string
    {
        return $this->config->scannerEndpoint();
    }

    public function batchSize(): int
    {
        return $this->config->scanBatchSize();
    }

    /**
     * Finds only the next batch instead of recursively cataloguing the entire
     * source first. Breadth-first traversal prioritizes files near the share
     * root and prevents large AFP trees from exhausting the request timeout.
     *
     * @return list<string>
     */
    private function discoverPendingBatch(string $sourceRoot): array
    {
        $knownHashes = $this->knownContentHashes();
        $batch = [];
        $batchSize = $this->config->scanBatchSize();
        $inspectionLimit = $this->config->maxScanFiles();
        $inspected = 0;
        $storageRoot = $this->storage()->root();
        $trashRoot = $storageRoot . DIRECTORY_SEPARATOR . 'Trashed';
        $destination = $this->storage()->normalizeRelativePath($this->config->scanDestination(), false);
        $directories = [$sourceRoot];

        for ($directoryIndex = 0; $directoryIndex < count($directories); ++$directoryIndex) {
            $directory = $directories[$directoryIndex];
            try {
                $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
            } catch (\UnexpectedValueException) {
                continue;
            }

            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    continue;
                }
                $resolved = $item->getRealPath();
                if ($resolved === false || ! $this->isWithin($resolved, $sourceRoot)) {
                    continue;
                }
                // Trash is managed storage, never a source of new library media.
                if ($this->isWithin($resolved, $trashRoot)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($resolved, strlen($sourceRoot) + 1));
                if ($this->shouldIgnoreSourcePath($relative)) {
                    continue;
                }
                if ($item->isDir()) {
                    $directories[] = $resolved;
                    continue;
                }
                if (! $item->isFile() || ! $item->isReadable()) {
                    continue;
                }
                ++$inspected;
                if ($inspected > $inspectionLimit) {
                    throw new RuntimeException('No new scan batch was found within the first ' . number_format($inspectionLimit) . ' source files. Increase greyhound.maxScanFiles or narrow the scan source.');
                }
                $mimeType = mime_content_type($resolved) ?: 'application/octet-stream';
                if (! in_array($mimeType, $this->config->allowedMimeTypes(), true)) {
                    continue;
                }
                $size = filesize($resolved);
                if ($size === false || ($this->config->maxUploadBytes() > 0 && $size > $this->config->maxUploadBytes())) {
                    continue;
                }
                if ($this->samePath($sourceRoot, $storageRoot)
                    && ($relative === $destination || str_starts_with($relative, $destination . '/'))) {
                    continue;
                }
                $hash = hash_file('sha256', $resolved);
                if (is_string($hash) && isset($knownHashes[strtolower($hash)])) {
                    continue;
                }
                $batch[] = $relative;
                if (is_string($hash)) {
                    $knownHashes[strtolower($hash)] = true;
                }
                if (count($batch) >= $batchSize) {
                    return $batch;
                }
            }
        }

        return $batch;
    }

    private function shouldIgnoreSourcePath(string $relativePath): bool
    {
        if ($this->config->centralized() && preg_match('#^(DEPARTMENT|TRASH)(/|$)#i', $relativePath)) {
            return true;
        }
        $segments = explode('/', str_replace('\\', '/', $relativePath));
        foreach ($segments as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return true;
            }
            if (in_array(strtolower($segment), ['__macosx', 'thumbs.db', 'desktop.ini'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private function knownContentHashes(): array
    {
        $knownHashes = [];
        foreach ((new FileModel())->where('is_deleted', 0)->select('content_hash, filehash')->findAll() as $file) {
            foreach (['content_hash', 'filehash'] as $column) {
                $hash = strtolower(trim((string) ($file[$column] ?? '')));
                if (preg_match('/^[a-f0-9]{64}$/', $hash) === 1) {
                    $knownHashes[$hash] = true;
                }
            }
        }

        return $knownHashes;
    }

    /** @return array{status: string, message: string, file_id?: int} */
    private function importFile(string $sourceRoot, string $relativePath, string $jobId, int $employeeId, bool $analyze = true): array
    {
        $this->resolveSelectionPath($sourceRoot, $relativePath);
        if ($this->config->centralized()) {
            $library = new ServerLibraryService($this->config);
            $source = $library->resolve($relativePath);
            $central = new CentralMediaService($library);
            $id = $central->register($relativePath, ['employee_id'=>$employeeId], ['server_path'=>$relativePath,'size'=>filesize($source)]);
            if (! $analyze) {
                return ['status' => 'imported', 'file_id' => $id, 'message' => 'Image registered for metadata scanning.'];
            }
            db_connect()->table('files')->where('id',$id)->update(['content_hash'=>hash_file('sha256',$source),'scan_id'=>$jobId]);
            $queued = (new IFoundAnalysisQueue())->enqueue([$id]);
            if (! $queued['queued']) {
                return ['status' => 'skipped', 'file_id' => $id, 'message' => basename($relativePath) . ' already analyzed; existing results were preserved.'];
            }
            return ['status'=>'imported','file_id'=>$id,'message'=>basename($relativePath).' queued for analysis.'];
        }
        $source = realpath($sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($source === false || ! is_file($source) || ! is_readable($source) || ! $this->isWithin($source, $sourceRoot)) {
            throw new RuntimeException('The source file is no longer available.');
        }

        $contentHash = hash_file('sha256', $source);
        if ($contentHash === false) {
            throw new RuntimeException('The source file could not be verified.');
        }
        $existing = (new FileModel())
            ->where('is_deleted', 0)
            ->groupStart()
            ->where('content_hash', $contentHash)
            ->orWhere('filehash', $contentHash)
            ->groupEnd()
            ->first();
        if ($existing !== null) {
            if ($analyze && !db_connect()->table('ai_event_detections')->where('media_id', $existing['id'])->countAllResults()) {
                service('eventDetection')->classifyAndStore($existing);
                return ['status'=>'imported', 'file_id'=>(int) $existing['id'], 'message'=>basename($relativePath).' was analyzed in File Management.'];
            }
            return [
                'status'  => 'skipped',
                'file_id' => (int) $existing['id'],
                'message' => basename($relativePath) . ' already exists in File Management and was skipped.',
            ];
        }

        $relativeFolder = dirname(str_replace('\\', '/', $relativePath));
        $relativeFolder = $relativeFolder === '.' ? '' : $relativeFolder;
        $folderId = $this->ensureFolderHierarchy($relativeFolder);
        $destination = trim($this->config->scanDestination() . '/' . $relativeFolder, '/');

        $stored = $this->storage()->storeServerCopy($source, $destination);

        // Standardize relative filepath for database storage (writable/uploads/...)
        $relativeStoredPath = trim('writable/uploads/' . trim($destination, '/') . '/' . $stored['filename'], '/');

        $db = db_connect();
        $db->transBegin();

        try {
            $displayTitle = trim(mb_substr(pathinfo($stored['filename'], PATHINFO_FILENAME), 0, 150));
            $displayTitle = $displayTitle === '' ? 'Scanned File' : $displayTitle;
            $now = gmdate('Y-m-d H:i:s');
            $fileId = (new FileModel())->insert([
                'filename'          => $stored['filename'],
                'display_title'     => $displayTitle,
                'filepath'          => $relativeStoredPath,
                'filetype'          => strtolower((string) pathinfo($stored['filename'], PATHINFO_EXTENSION)),
                'mime_type'         => $stored['mimeType'],
                'media_kind'        => $this->mediaKindForMimeType($stored['mimeType']),
                'size'              => $stored['size'],
                'dateupload'        => $now,
                'datecreated'       => gmdate('Y-m-d H:i:s', filemtime($source) ?: time()),
                'description'       => $analyze ? 'Copied from the Server Scan Inbox by iFound.' : 'Copied from the Server Scan Inbox for metadata passport generation.',
                'employee_id'       => $employeeId,
                'folder_id'         => $folderId,
                'is_deleted'        => 0,
                'downloadable'      => 1,
                'release_status'    => 'draft',
                'featured'          => 0,
                'storage_stage'     => 'raw',
                'content_hash'      => $contentHash,
                'filehash'          => $contentHash,
                'image_fingerprint' => service('imageSimilarity')->fingerprint($stored['filepath'], $stored['mimeType']),
                'scan_id'           => $jobId,
                'new_scanned'       => 1,
                'last_action'       => 'uploaded',
                'last_action_at'    => $now,
                'updated_at'        => $now,
            ], true);

            if ($fileId === false) {
                throw new RuntimeException('The scanned file could not be registered in File Management.');
            }

            $file = (new FileModel())->find((int) $fileId);
            if ($file === null) {
                throw new RuntimeException('The scanned file could not be loaded for iFound processing.');
            }
            if ($analyze) {
                service('eventDetection')->classifyAndStore($file);
            }

            $db->transCommit();
        } catch (Throwable $exception) {
            $db->transRollback();
            @unlink($stored['filepath']);
            throw new RuntimeException('iFound could not import this file: ' . $exception->getMessage(), 0, $exception);
        }

        return [
            'status'  => 'imported',
            'file_id' => (int) $fileId,
            'message' => basename($relativePath) . ' was scanned and added to File Management.',
        ];
    }

    private function ensureFolderHierarchy(string $relativeFolder): int
    {
        $path = $this->storage()->normalizeRelativePath(trim($this->config->scanDestination() . '/' . $relativeFolder, '/'), false);
        $parentId = null;
        $currentPath = '';

        foreach (explode('/', $path) as $name) {
            if ($name === '') {
                continue;
            }
            $currentPath = trim($currentPath . '/' . $name, '/');
            $query = (new FolderModel())->where('is_deleted', 0)->where('name', $name);
            $parentId === null
                ? $query->where('parent_id IS NULL', null, false)
                : $query->where('parent_id', $parentId);
            $folder = $query->first();
            if ($folder === null) {
                $createdId = (new FolderModel())->insert([
                    'parent_id'   => $parentId,
                    'name'        => $name,
                    'description' => $parentId === null ? 'Files copied and classified by the iFound server scanner.' : '',
                    'path'        => $currentPath,
                    'created_at'  => gmdate('Y-m-d H:i:s'),
                    'is_deleted'  => 0,
                ], true);
                if ($createdId === false) {
                    throw new RuntimeException('The iFound destination folder could not be created.');
                }
                $parentId = (int) $createdId;
            } else {
                $parentId = (int) $folder['id'];
            }
            $this->storage()->directory($currentPath, true);
        }

        if ($parentId === null) {
            throw new RuntimeException('The iFound destination folder is invalid.');
        }
        return $parentId;
    }

    private function sourceRoot(bool $create): string
    {
        $path = $this->config->scanSourceRoot();
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('The Server Scan Inbox is not configured.');
        }
        // A disconnected share must never be replaced by an empty local directory.
        $externalInbox = $this->config->scannerEndpoint() !== ''
            || str_starts_with(str_replace('\\', '/', $path), '/Volumes/')
            || str_starts_with($path, '\\\\');
        if (! is_dir($path) && $externalInbox) {
            throw new RuntimeException('The Server Scan Inbox is not mounted or unavailable. Reconnect the configured network share before scanning.');
        }
        if ($create && ! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException('The Server Scan Inbox could not be created.');
        }
        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException('The Server Scan Inbox is not mounted or unavailable.');
        }
        if (! is_readable($resolved)) {
            $endpoint = $this->config->scannerEndpoint();
            $source = $endpoint === '' ? $resolved : $endpoint . ' mounted at ' . $resolved;
            throw new RuntimeException('The Server Scan Inbox cannot be read (' . $source . '). Allow the PHP/Terminal process to access network volumes in macOS Privacy & Security.');
        }
        return $resolved;
    }

    /** @param array<string, mixed> $state */
    private function saveState(array $state): void
    {
        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->jobPath((string) $state['job_id']), $encoded, LOCK_EX) === false) {
            throw new RuntimeException('The scan progress could not be saved.');
        }
    }

    /** @return array<string, mixed> */
    private function loadState(string $jobId): array
    {
        $contents = @file_get_contents($this->jobPath($jobId));
        if ($contents === false) {
            throw new RuntimeException('The scan job was not found. Start a new scan.');
        }
        try {
            $state = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('The scan job is invalid. Start a new scan.', 0, $exception);
        }
        if (! is_array($state)) {
            throw new RuntimeException('The scan job is invalid. Start a new scan.');
        }
        return $state;
    }

    private function jobPath(string $jobId): string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $jobId) !== 1) {
            throw new RuntimeException('The scan job identifier is invalid.');
        }
        $directory = WRITEPATH . 'cache' . DIRECTORY_SEPARATOR . 'ifound-scans';
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('The scan progress directory could not be created.');
        }
        return $directory . DIRECTORY_SEPARATOR . $jobId . '.json';
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function publicState(array $state, string $message): array
    {
        $total = (int) ($state['total'] ?? 0);
        $processed = (int) ($state['processed'] ?? 0);
        return [
            'ok'                  => true,
            'jobId'               => (string) $state['job_id'],
            'total'               => $total,
            'processed'           => $processed,
            'imported'            => (int) ($state['imported'] ?? 0),
            'skipped'             => (int) ($state['skipped'] ?? 0),
            'failed'              => (int) ($state['failed'] ?? 0),
            'percent'             => $total === 0 ? 100 : (int) floor(($processed / $total) * 100),
            'complete'            => ! empty($state['complete']),
            'message'             => $message,
            'errors'              => array_values((array) ($state['errors'] ?? [])),
            'destinationFolderId' => (int) ($state['destination_folder_id'] ?? 0),
        ];
    }

    private function storage(): StorageService
    {
        return $this->storageService ?? service('storage');
    }

    private function mediaKindForMimeType(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            default                              => 'document',
        };
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private function samePath(string $left, string $right): bool
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $left), DIRECTORY_SEPARATOR)
            === rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $right), DIRECTORY_SEPARATOR);
    }
}
