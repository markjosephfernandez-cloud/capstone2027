<?php

namespace App\Services;

use Config\Storage;
use CodeIgniter\Cache\CacheInterface;
use RuntimeException;

/** Confined reads of the configured share; writes are handled by ServerLibraryEditor. */
class ServerLibraryService
{
    public const FILE_BATCH_SIZE = 30;
    public const COUNT_CACHE_SECONDS = 180;
    public const FINAL_CUT_CACHE_FOLDERS = ['render files', 'analysis files'];
    protected bool $taggedImageSearch = false;
    protected int $folderSearchPage = 1;

    public function searchTaggedImagesOnly(bool $enabled, int $folderPage = 1): static
    {
        $this->taggedImageSearch = $enabled;
        $this->folderSearchPage = max(1, $folderPage);
        return $this;
    }

    public function __construct(private readonly Storage $config = new Storage(), private readonly ?CacheInterface $countCache = null) {}

    public static function isMetadataName(string $name): bool
    {
        return $name === "Icon\r" || str_starts_with($name, '.')
            || in_array(strtolower($name), ['__macosx', 'thumbs.db', 'desktop.ini'], true);
    }

    public function catalogSourceKey(): string
    {
        return hash('sha256', rtrim($this->config->scanSourceRoot(), '/\\'));
    }

    public function root(): string
    {
        $configured = $this->config->scanSourceRoot();
        clearstatcache();
        $root = realpath($configured);
        if ($root === false || !is_dir($root) || !is_readable($root)) {
            throw new RuntimeException('Server disconnected. Connect the server share and refresh.');
        }
        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    public function relative(string $path): string
    {
        if ($this->config->centralized() && preg_match('#^DEPARTMENT(?:/|$)#i', $path)) throw new RuntimeException('Department storage is separate from source media.');
        if (strlen($path) > 4000 || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new RuntimeException('Invalid server path.');
        }
        if ($path === '') return '';
        // Keep Original Media accessible, but do not browse or index editor caches.
        foreach (self::FINAL_CUT_CACHE_FOLDERS as $cacheFolder) {
            if (preg_match('#\.fcpbundle/(?:.*/)?' . preg_quote($cacheFolder, '#') . '(?:/|$)#i', $path)) {
                throw new RuntimeException('Editing cache files are not library media.');
            }
        }
        if (strcasecmp(explode('/', $path)[0], 'TRASH') === 0) throw new RuntimeException('Open the Trash page to manage server Trash.');
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.') || $part === '__MACOSX' || $part === "Icon\r") {
                throw new RuntimeException('Invalid server path.');
            }
        }
        return $path;
    }

    public function writable(string $folder = ''): bool
    {
        try { return is_writable($this->resolve($folder, true)); }
        catch (RuntimeException) { return false; }
    }

    public function invalidateCounts(string $folder): void
    {
        try { ($this->countCache ?? cache())->delete(ServerLibrarySearchIndex::key($this->root())); }
        catch (\Throwable) {}
        try { ($this->countCache ?? cache())->delete('server_folder_counts_' . hash('sha256', $this->resolve($folder, true))); }
        catch (\Throwable $exception) { log_message('warning', 'Server folder count refresh failed: {message}', ['message'=>$exception->getMessage()]); }
    }

    public function resolve(string $relative, bool $directory = false): string
    {
        $relative = $this->relative($relative);
        $root = $this->root();
        $candidate = $root;
        foreach ($relative === '' ? [] : explode('/', $relative) as $part) {
            $candidate .= DIRECTORY_SEPARATOR . $part;
            if (is_link($candidate)) throw new RuntimeException('Linked server paths are unavailable.');
        }
        $path = realpath($candidate);
        if ($path === false || ($path !== $root && !str_starts_with($path, $root . DIRECTORY_SEPARATOR))
            || !is_readable($path) || ($directory ? !is_dir($path) : !is_file($path))) {
            throw new RuntimeException('This server item is unavailable.');
        }
        return $path;
    }

    /** Folder pickers must not inherit the file grid's pagination limit. */
    public function listFolders(string $folder = '', ?callable $continueReading = null): array
    {
        return array_values(array_filter($this->browse($folder, '', 0, $continueReading)['items'], static fn (array $item): bool => $item['directory']));
    }

    public function suggestions(string $folder, string $query, string $type = '', ?callable $continueReading = null): array
    {
        if (mb_strlen(trim($query)) < 2) return [];
        $listing = $this->browse($folder, $query, 0, $continueReading, $type, 1);
        return $this->suggestionsFromItems($listing['items'], $query);
    }

    protected function suggestionsFromItems(array $items, string $query): array
    {
        $suggestions = []; $seen = [];
        foreach ($items as $item) {
            $values = $this->taggedImageSearch
                ? ($item['directory'] ? [$item['name']] : $item['tags'])
                : array_merge([$item['name']], $item['tags'], [$item['classification'], $item['date_taken']]);
            foreach ($values as $value) {
                if (mb_stripos($value, $query) === false || isset($seen[mb_strtolower($value)])) continue;
                $seen[mb_strtolower($value)] = true;
                $suggestions[] = ['value'=>$value, 'kind'=>$this->taggedImageSearch ? ($item['directory'] ? 'Folder' : 'Tag') : ($value === $item['name'] ? ($item['directory'] ? 'Folder' : 'File') : 'Tag')];
                if (count($suggestions) >= 8) break 2;
            }
        }
        return $suggestions;
    }

    public function browse(string $folder = '', string $search = '', int $offset = 0, ?callable $continueReading = null, string $type = '', ?int $page = null, bool $imagesOnly = false): array
    {
        $started = hrtime(true);
        $this->checkRead($continueReading);
        $directory = $this->resolve($folder, true);
        $this->checkRead($continueReading);
        $state = (new ServerLibraryEditor($this))->state();
        $tags = $state['tags'];
        $fileTypes = [];
        $items = [];
        $recursive = $page !== null && $search !== '';
        $index = $recursive ? (new ServerLibrarySearchIndex($this, $this->countCache ?? cache()))->read($continueReading) : null;
        $entries = $recursive ? $index['entries'] : $this->entries($folder, false, $continueReading);
        foreach ($entries as $relative => $path) {
            try { $this->relative($relative); } catch (RuntimeException) { continue; }
            $name = basename($relative);
            $itemTags = $tags[$relative] ?? [];
            
            if (!$recursive && is_link($path)) continue;
            $isDirectory = $recursive ? $path['directory'] : is_dir($path);
            if (!$recursive && !$isDirectory && !is_file($path)) continue;
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!$isDirectory && $imagesOnly && !in_array($extension, ['jpg','jpeg','png','webp'], true)) continue;
            if (!$isDirectory && $extension !== '') $fileTypes[$extension] = strtoupper($extension);
            if (!$isDirectory && $type !== '' && $extension !== strtolower($type)) continue;
            $passport = $state['media'][$relative]['passport'] ?? [];
            $searchText = implode(' ', [$relative, implode(', ', $itemTags), $passport['classification'] ?? $passport['event_name'] ?? '', $passport['date_taken'] ?? '']);
            if ($this->taggedImageSearch && $search !== '') {
                if (!$isDirectory && (!in_array($extension, CameraImagePreviewService::IMAGE_EXTENSIONS, true) || $itemTags === [])) continue;
                $searchText = $isDirectory ? $name : implode(', ', $itemTags);
            }
            if ($search !== '' && mb_stripos($searchText, $search) === false) continue;
            $kind = $isDirectory ? 'folder' : $this->fileKind($name);
            $items[] = ['name'=>$name, 'path'=>$relative, 'directory'=>$isDirectory, 'kind'=>$kind, 'tags'=>$itemTags, 'extension'=>$extension, 'size'=>$recursive ? $path['size'] : ($isDirectory ? null : @filesize($path)), 'modified_at'=>$recursive ? $path['modified_at'] : @filemtime($path), 'uploaded_at'=>$state['media'][$relative]['uploaded_at'] ?? null, 'has_passport'=>!empty($passport), 'classification'=>$passport['classification'] ?? $passport['event_name'] ?? '', 'date_taken'=>$passport['date_taken'] ?? ''];
        }
        if (!$recursive) $this->checkRead($continueReading);
        usort($items, static fn ($a, $b) => ($b['directory'] <=> $a['directory']) ?: strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['name'], $b['name']));
        $folders = array_values(array_filter($items, static fn ($item) => $item['directory']));
        $files = array_values(array_filter($items, static fn ($item) => !$item['directory']));
        if ($page !== null) usort($files, static fn ($a, $b) => (strtotime($b['uploaded_at'] ?? '') ?: $b['modified_at']) <=> (strtotime($a['uploaded_at'] ?? '') ?: $a['modified_at']) ?: strcmp($b['path'], $a['path']));
        $total = count($files);
        $offset = max(0, $offset);
        $pageSize = $page === null ? self::FILE_BATCH_SIZE : 20;
        $totalPages = max(1, (int) ceil($total / $pageSize));
        $currentPage = $page === null ? 1 : min(max(1, $page), $totalPages);
        if ($page !== null) $offset = ($currentPage - 1) * $pageSize;
        $batch = array_slice($files, $offset, $pageSize);
        $items = array_merge($offset === 0 || $page !== null ? $folders : [], $batch);
        foreach ($items as &$item) {
            if ($recursive) continue;
            $this->checkRead($continueReading);
            if ($item['directory']) {
                $modified = @filemtime($this->resolve($item['path'], true));
                $item['modified_at'] = $modified === false ? null : $modified;
            }
        }
        unset($item);
        // Do not display a stale listing if the share disappeared during enumeration.
        $this->root();
        if (!$recursive) $this->checkRead($continueReading);
        $next = $offset + count($batch);
        ksort($fileTypes);
        return ['pagination'=>['current_page'=>$currentPage, 'total_pages'=>$totalPages, 'per_page'=>$pageSize, 'total_results'=>$total, 'from'=>$total === 0 ? 0 : $offset + 1, 'to'=>min($offset + $pageSize, $total)], 'fileTypes'=>$fileTypes, 'items'=>$items, 'file_total'=>$total, 'next_offset'=>$next < $total ? $next : null,
            'search_incomplete'=>$recursive && !$index['complete'], 'listing_ms'=>(hrtime(true) - $started) / 1e6];
    }

    /** Read visible entries without following links or entering private metadata. */
    private function entries(string $folder, bool $recursive, ?callable $continueReading): \Generator
    {
        $directory = $this->resolve($folder, true);
        $handle = @opendir($directory);
        if ($handle === false) throw new RuntimeException('This server folder cannot be read.');
        try {
            while (true) {
                $this->checkRead($continueReading);
                $name = readdir($handle);
                if ($name === false) break;
                if (self::isMetadataName($name)) continue;
                $relative = ($folder === '' ? '' : $folder . '/') . $name;
                $path = $directory . DIRECTORY_SEPARATOR . $name;
                if (!$this->isBrowsableEntry($relative, $path)) continue;
                if (!is_dir($path) && !is_file($path)) continue;
                yield $relative => $path;
                if ($recursive && is_dir($path)) yield from $this->entries($relative, true, $continueReading);
            }
        } finally { closedir($handle); }
    }

    /** Enumerate visible files without following symlinks or private application folders. */
    public function walkFiles(string $folder = ''): \Generator
    {
        $directory = $this->resolve($folder, true);
        $handle = @opendir($directory);
        if ($handle === false) throw new RuntimeException('This server folder cannot be read.');
        try {
            while (($name = readdir($handle)) !== false) {
                if (self::isMetadataName($name)) continue;
                $path = $directory . '/' . $name;
                $relative = ($folder === '' ? '' : $folder . '/') . $name;
                if (!$this->isBrowsableEntry($relative, $path)) continue;
                if (is_dir($path)) yield from $this->walkFiles($relative);
                elseif (is_file($path)) yield $relative => $this->resolve($relative);
            }
        } finally { closedir($handle); }
    }

    private function fileKind(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($extension, CameraImagePreviewService::IMAGE_EXTENSIONS, true) ? 'image'
            : (in_array($extension, ['mp4','mov','m4v','webm','avi','mkv'], true) ? 'video' : 'file');
    }

    /** Unsupported share names must not abort an otherwise readable directory. */
    private function isBrowsableEntry(string $relative, string $path): bool
    {
        try { $this->relative($relative); }
        catch (RuntimeException) { return false; }
        return !is_link($path) && is_readable($path);
    }

    /** Count immediate visible children only, for the displayed folders. */
    public function folderCounts(string $relative, ?callable $continueReading = null): array
    {
        $this->checkRead($continueReading);
        // Resolve before consulting the cache so offline, linked and forbidden paths stay unavailable.
        $directory = $this->resolve($relative, true);
        $this->checkRead($continueReading);
        $cache = $this->countCache ?? cache();
        $key = 'server_folder_counts_' . hash('sha256', $directory);
        $cached = $cache->get($key);
        if (is_array($cached)) return $cached;
        $handle = @opendir($directory);
        if ($handle === false) throw new RuntimeException('This server folder cannot be read.');
        $counts = ['files'=>0, 'folders'=>0];
        try {
            while (true) {
                $this->checkRead($continueReading);
                $name = readdir($handle);
                if ($name === false) break;
                if (self::isMetadataName($name)) continue;
                $path = $directory . DIRECTORY_SEPARATOR . $name;
                $child = ($relative === '' ? '' : $relative . '/') . $name;
                if (!$this->isBrowsableEntry($child, $path)) continue;
                if (is_dir($path)) $counts['folders']++;
                elseif (is_file($path)) $counts['files']++;
            }
        } finally { closedir($handle); }
        $this->resolve($relative, true);
        // An interrupted enumeration must never be stored as a complete count.
        $this->checkRead($continueReading);
        $cache->save($key, $counts, self::COUNT_CACHE_SECONDS);
        return $counts;
    }

    private function checkRead(?callable $continueReading): void
    {
        if ($continueReading !== null && !$continueReading()) {
            throw new RuntimeException('Server reading stopped or took too long. Refresh to try again.');
        }
    }
}
