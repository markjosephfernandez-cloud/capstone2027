<?php
namespace App\Services;

use RuntimeException;

/** Read-only data for Distribution's server selection and confirmation steps. */
class ServerDistributionPicker
{
    public const MAX_FILES = 500;
    public function __construct(private ServerLibraryService $library)
    {
    }

    public function file(string $path): array
    {
        $source = $this->library->resolve($path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return ['id'=>bin2hex($path), 'name'=>basename($path), 'path'=>$path, 'size'=>filesize($source),
            'preview_url'=>in_array($extension, ['jpg','jpeg','png','gif','webp','mp4','mov','avi','mkv','webm','m4v'], true)
                ? site_url('workspace/server-library/media') . '?' . http_build_query(['path'=>$path,'thumbnail'=>1]) : null];
    }

    public function browse(string $token, string $query, int $page): array
    {
        $path = $token === '' ? '' : ServerLibraryPresentation::path($token);
        $deadline = microtime(true) + 10;
        $listing = $this->library->browse($path, mb_substr(trim($query),0,120), 0, static fn () => microtime(true) < $deadline, '', $page);
        $folders = []; $files = []; $crumbs = [['id'=>'','name'=>'Server Library']]; $ancestor = '';
        foreach ($path === '' ? [] : explode('/', $path) as $part) {
            $ancestor .= ($ancestor === '' ? '' : '/') . $part;
            $crumbs[] = ['id'=>bin2hex($ancestor),'name'=>$part];
        }
        foreach ($listing['items'] as $item) {
            if ($item['directory']) $folders[] = ['id'=>bin2hex($item['path']),'name'=>$item['name'],'path'=>$item['path']];
            else $files[] = $this->file($item['path']);
        }
        return ['folders'=>$folders,'files'=>$files,'breadcrumbs'=>$crumbs,'pagination'=>$listing['pagination']];
    }

    public function paths(mixed $tokens): array
    {
        if (! is_array($tokens) || count($tokens) > self::MAX_FILES || count(array_filter($tokens, 'is_string')) !== count($tokens)) {
            throw new RuntimeException('Select up to 500 server files per transmission.');
        }
        return array_map([ServerLibraryPresentation::class,'path'], array_values(array_unique($tokens)));
    }

    public function selection(mixed $fileTokens, mixed $folderTokens): array
    {
        $paths = $this->paths($fileTokens); $folders = $this->paths($folderTokens);
        $chosen = array_fill_keys($paths, true);
        $deadline = microtime(true) + 20;
        foreach ($folders as $folder) {
            foreach ($this->library->walkFiles($folder) as $path => $source) {
                $chosen[$path] = true;
                if (count($chosen) > self::MAX_FILES) {
                    throw new RuntimeException('Select up to 500 server files per transmission.');
                }
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('This folder takes too long to read. Select a smaller subfolder.');
                }
            }
        }
        if (! $chosen) {
            throw new RuntimeException('Select at least one server file or a folder containing files.');
        }
        return ['files'=>array_map($this->file(...), array_keys($chosen)), 'folders'=>array_map(static fn ($path) => ['id'=>bin2hex($path),'name'=>basename($path)], $folders)];
    }
}
