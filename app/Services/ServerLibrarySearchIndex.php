<?php

namespace App\Services;

use CodeIgniter\Cache\CacheInterface;
use RuntimeException;

/** A short-lived local inventory, built in resumable slices of share reads. */
final class ServerLibrarySearchIndex
{
    public static function key(string $root): string
    {
        return 'server_search_v1_' . hash('sha256', $root);
    }

    public function __construct(private ServerLibraryService $library, private CacheInterface $cache) {}

    public function read(?callable $continueReading): array
    {
        $root = $this->library->root();
        $key = self::key($root);
        $state = $this->cache->get($key);
        if (!is_array($state)) $state = ['entries'=>[], 'queue'=>[['folder'=>'', 'offset'=>0]], 'complete'=>false];
        if ($state['complete']) return $state;
        $deadline = microtime(true) + 3;
        $canRead = static fn () => microtime(true) < $deadline && ($continueReading === null || $continueReading());
        while ($state['queue'] !== [] && $canRead()) {
            $cursor = &$state['queue'][0];
            try { $directory = $this->library->resolve($cursor['folder'], true); }
            catch (RuntimeException) {
                // A removed or linked child must not block the remaining inventory.
                $this->library->root();
                array_shift($state['queue']);
                unset($cursor);
                continue;
            }
            $handle = @opendir($directory);
            if ($handle === false) throw new RuntimeException('This server folder cannot be read.');
            $finished = false;
            try {
                for ($skip = 0; $skip < $cursor['offset']; $skip++) {
                    if (readdir($handle) === false) { $finished = true; break; }
                }
                while (!$finished && $canRead()) {
                    $name = readdir($handle);
                    if ($name === false) { $finished = true; break; }
                    $cursor['offset']++;
                    if ($name === "Icon\r" || str_starts_with($name, '.') || in_array(strtolower($name), ['__macosx','thumbs.db','desktop.ini'], true)) continue;
                    $relative = ($cursor['folder'] === '' ? '' : $cursor['folder'] . '/') . $name;
                    try { $this->library->relative($relative); } catch (RuntimeException) { continue; }
                    $path = $directory . DIRECTORY_SEPARATOR . $name;
                    if (is_link($path) || !is_readable($path)) continue;
                    $isDirectory = is_dir($path);
                    if (!$isDirectory && !is_file($path)) continue;
                    $state['entries'][$relative] = ['directory'=>$isDirectory, 'size'=>$isDirectory ? null : @filesize($path), 'modified_at'=>@filemtime($path)];
                    if ($isDirectory) $state['queue'][] = ['folder'=>$relative, 'offset'=>0];
                }
            } finally { closedir($handle); }
            unset($cursor);
            if ($finished) array_shift($state['queue']);
        }
        $state['complete'] = $state['queue'] === [];
        // Recheck connectivity before serving or saving even partially built results.
        $this->library->root();
        if (!$this->cache->save($key, $state, 300)) throw new RuntimeException('Search progress could not be saved. Please try again.');
        return $state;
    }
}
