<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseBuilder;
use Config\Storage;
use RuntimeException;

/** Browsing never touches the NAS. Actual file operations use ServerLibraryService. */
class ServerLibraryCatalog extends ServerLibraryService
{
    private BaseConnection $db;
    private bool $centralized;
    public readonly string $sourceKey;
    public function __construct(?Storage $config = null, ?BaseConnection $db = null)
    {
        $config ??= new Storage();
        parent::__construct($config);
        $this->centralized = $config->centralized();
        $this->db = $db ?? db_connect();
        $this->sourceKey = hash('sha256', rtrim($config->scanSourceRoot(), '/\\'));
    }

    public function statusInfo(): array
    {
        if (! $this->db->tableExists('server_library_sources')) {
            throw new RuntimeException('The server catalog has not been installed.');
        }
        return $this->db->table('server_library_sources')->where('source_key', $this->sourceKey)->get()->getRowArray()
            ?? ['status'=>'not_started', 'completed_at'=>null, 'updated_at'=>null, 'last_error'=>null];
    }

    public function browse(string $folder = '', string $search = '', int $offset = 0, ?callable $continueReading = null, string $type = '', ?int $page = null, bool $imagesOnly = false): array
    {
        $start = microtime(true);
        $this->relative($folder);
        $status = $this->statusInfo();
        $pending = $this->db->table('server_library_scan_queue')->where('source_key',$this->sourceKey)->where('path_hash',hash('sha256',$folder))->countAllResults() > 0;
        // Only update the local queue; never scan the NAS in a page request.
        if ($pending && $search === '') {
            $this->db->table('server_library_scan_queue')->where('source_key', $this->sourceKey)
                ->where('path_hash', hash('sha256', $folder))->where('priority', 0)->update(['priority' => 1]);
        }
        $search = $this->literalLike($search);
        $builder = $this->visibleEntries($search !== '');
        if ($search === '') $builder->where('parent_hash', hash('sha256', $folder));
        elseif ($this->taggedImageSearch) $this->applyTaggedImageSearch($builder, $search);
        else $builder->groupStart()->like('path', $search, 'both', true)->orLike('tags', $search, 'both', true)->orLike('classification', $search, 'both', true)->orLike('date_taken', $search, 'both', true)->groupEnd();
        if ($type !== '') {
            $builder->groupStart()->where('is_dir', 1)->orWhere('extension', strtolower($type))->groupEnd();
        }
        if ($imagesOnly) {
            $builder->groupStart()->where('is_dir', 1)->orWhereIn('extension', ['jpg', 'jpeg', 'png', 'webp'])->groupEnd();
        }
        $counts = (clone $builder)->select('COUNT(*) AS total, COALESCE(SUM(CASE WHEN is_dir = 0 THEN 1 ELSE 0 END), 0) AS file_total', false)->get()->getRowArray();
        $total = (int) $counts['total'];
        $fileTotal = (int) $counts['file_total'];
        $perPage = $page === null ? 30 : 40;
        $folderPagination = null;
        $folderRows = [];
        if ($this->taggedImageSearch && $search !== '') {
            $folderTotal = $total - $fileTotal;
            $folderPages = max(1, (int) ceil($folderTotal / $perPage));
            $folderPage = min($this->folderSearchPage, $folderPages);
            $folderOffset = ($folderPage - 1) * $perPage;
            $folderRows = (clone $builder)->where('is_dir', 1)->orderBy('name')->orderBy('id')->get($perPage, $folderOffset)->getResultArray();
            $folderPagination = ['current_page'=>$folderPage, 'total_pages'=>$folderPages, 'per_page'=>$perPage,
                'total_results'=>$folderTotal, 'from'=>$folderTotal ? $folderOffset + 1 : 0, 'to'=>min($folderTotal, $folderOffset + count($folderRows))];
            // Images get their own limit and offset; folders never consume image slots.
            $builder->where('is_dir', 0);
            $total = $fileTotal;
        }
        $pages = max(1, (int)ceil($total / $perPage));
        $current = $page === null ? 1 : min(max(1, $page), $pages);
        $offset = $page === null ? max(0, $offset) : ($current - 1) * $perPage;
        $builder->orderBy('is_dir','DESC');
        // Surface containing folders before the hundreds of descendants whose
        // paths also match the same query.
        if ($search !== '') $builder->orderBy('LENGTH(path)', 'ASC', false);
        $rows = $builder->orderBy('name')->orderBy('id')->get($perPage, $offset)->getResultArray();
        $pageItemCount = count($rows);
        $rows = array_merge($folderRows, $rows);
        $items = array_map(static function ($row) {
            return ['path'=>$row['path'], 'name'=>$row['name'], 'directory'=>(bool)$row['is_dir'],
                'kind'=>$row['is_dir'] ? 'folder' : (in_array($row['extension'], CameraImagePreviewService::IMAGE_EXTENSIONS) ? 'image' : (in_array($row['extension'], ['mp4','mov','webm','avi','mkv','m4v']) ? 'video' : 'file')),
                'size'=>$row['size'], 'modified_at'=>$row['modified_at'], 'extension'=>$row['extension'],
                'tags'=>json_decode($row['tags'], true) ?: [], 'classification'=>$row['classification'], 'date_taken'=>$row['date_taken'],
                'uploaded_at'=>$row['uploaded_at'], 'has_passport'=>$row['classification'] !== '' || $row['date_taken'] !== ''];
        }, $rows);
        return ['items'=>$items, 'file_total'=>$fileTotal, 'next_offset'=>$offset + $pageItemCount < $total ? $offset + $pageItemCount : null,
            'pagination'=>['current_page'=>$current,'total_pages'=>$pages,'per_page'=>$perPage,'total_results'=>$total,'from'=>$total ? $offset + 1 : 0,'to'=>min($total,$offset + $pageItemCount)],
            'folder_pagination'=>$folderPagination,
            'fileTypes'=>array_combine($extensions = [...CameraImagePreviewService::IMAGE_EXTENSIONS, 'mp4','mov','webm','avi','mkv'], array_map('strtoupper', $extensions)),
            'catalog_mode'=>true, 'catalog_status'=>$status, 'folder_index_pending'=>$pending,
            'search_incomplete'=>$status['completed_at'] === null || $pending || in_array($status['status'], ['partial','failed'], true),
            'listing_ms'=>(microtime(true) - $start) * 1000];
    }

    private function applyTaggedImageSearch(BaseBuilder $builder, string $query): void
    {
        $builder->groupStart()
            ->groupStart()->where('is_dir', 1)->like('name', $query)->groupEnd()
            ->orGroupStart()->where('is_dir', 0)
            ->whereIn('extension', CameraImagePreviewService::IMAGE_EXTENSIONS)
            ->where('tags !=', '[]')->like('tags', $query)->groupEnd()
            ->groupEnd();
    }

    private function literalLike(string $value): string
    {
        // This framework version escapes SQL bindings, but leaves LIKE
        // metacharacters to the caller.
        $escape = $this->db->likeEscapeChar;
        return str_replace([$escape, '%', '_'], [$escape . $escape, $escape . '%', $escape . '_'], $value);
    }

    private function visibleEntries(bool $search): BaseBuilder
    {
        $table = $this->db->escapeIdentifiers($this->db->prefixTable('server_library_index'));
        // Contains searches cannot use a B-tree to find matching text. On the
        // million-row catalog, MariaDB otherwise walks source_key/path_hash
        // and fetches each row in random order (14s versus 1.5s sequentially).
        // Keep ordinary folder browsing on its selective parent_hash index.
        if ($search && $this->db->DBDriver === 'MySQLi') $table .= ' FORCE INDEX (PRIMARY)';
        $builder = $this->db->table($table)->where('source_key', $this->sourceKey)
            ->where('LOWER(path) !=', 'trash')->notLike('path', 'TRASH/', 'after')
            // Match the exact Finder metadata filename, including its hidden CR.
            // HEX avoids collation rules hiding a real file named Icon.
            ->where('HEX(name) !=', '49636F6E0D');
        if ($this->centralized) {
            $builder->where('LOWER(path) !=', 'department')->notLike('LOWER(path)', 'department/', 'after');
        }
        // Also hide caches already indexed before this policy, before pagination
        // and counts. Originals inside the same bundle remain searchable.
        foreach (self::FINAL_CUT_CACHE_FOLDERS as $cacheFolder) {
            foreach (['%.fcpbundle/', '%.fcpbundle/%/'] as $prefix) {
                $builder->where('LOWER(path) NOT LIKE', $prefix . $cacheFolder)
                    ->where('LOWER(path) NOT LIKE', $prefix . $cacheFolder . '/%');
            }
        }
        return $builder;
    }

    /** Aggregate the entire visible catalog, including files in nested folders. */
    public function storageAnalytics(): array
    {
        $categories = $this->visibleEntries(true)->where('is_dir', 0)
            ->select('extension AS filetype, COUNT(*) AS file_count, COALESCE(SUM(size), 0) AS total_size')
            ->groupBy('extension')->orderBy('total_size', 'DESC')->orderBy('extension')
            ->get()->getResultArray();

        return [
            'active_files' => (int) array_sum(array_column($categories, 'file_count')),
            'total_bytes' => (int) array_sum(array_column($categories, 'total_size')),
            'categories' => $categories,
        ];
    }

    public function suggestions(string $folder, string $query, string $type = '', ?callable $continueReading = null): array
    {
        $this->relative($folder);
        if (mb_strlen(trim($query)) < 2) {
            return [];
        }
        // Match values that can actually be suggested, not every descendant
        // whose parent path matches. No totals or global sort while typing.
        $match = $this->literalLike($query);
        $builder = $this->visibleEntries(true)->select('name, is_dir, tags, classification, date_taken');
        if ($this->taggedImageSearch) $this->applyTaggedImageSearch($builder, $match);
        else $builder->groupStart()->like('name', $match)->orLike('tags', $match)
            ->orLike('classification', $match)->orLike('date_taken', $match)->groupEnd();
        if ($type !== '') {
            $builder->groupStart()->where('is_dir', 1)->orWhere('extension', strtolower($type))->groupEnd();
        }
        $items = array_map(static fn ($row) => [
            'name'=>$row['name'], 'directory'=>(bool)$row['is_dir'],
            'tags'=>json_decode($row['tags'], true) ?: [],
            'classification'=>$row['classification'], 'date_taken'=>$row['date_taken'],
        ], $builder->get(40)->getResultArray());
        return $this->suggestionsFromItems($items, $query);
    }

    public function listFolders(string $folder = '', ?callable $continueReading = null): array
    {
        $this->relative($folder);
        $this->statusInfo();
        $rows = $this->visibleEntries(false)->select('path, name')->where('parent_hash', hash('sha256', $folder))
            ->where('is_dir', 1)->orderBy('name')->orderBy('id')->get()->getResultArray();
        return array_map(static fn (array $row): array => $row + ['directory'=>true], $rows);
    }

    public function folderCounts(string $relative, ?callable $continueReading = null): array
    {
        $this->relative($relative);
        $counts = ['files'=>0,'folders'=>0];
        foreach ($this->visibleEntries(false)->select('is_dir')->selectCount('id','total')->where('parent_hash',hash('sha256',$relative))->groupBy('is_dir')->get()->getResultArray() as $row) {
            $counts[$row['is_dir'] ? 'folders' : 'files'] = (int)$row['total'];
        }
        return $counts;
    }
}
