<?php

namespace App\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Storage;
use RuntimeException;

/**
 * Keeps all client-controlled file operations inside configured storage roots.
 */
class StorageService
{
    public function __construct(private readonly Storage $config = new Storage())
    {
    }

    public function root(): string
    {
        if ($this->config->centralized()) return (new ServerLibraryService($this->config))->root();
        $root = $this->config->root();
        if (! is_dir($root) && ! mkdir($root, 0775, true) && ! is_dir($root)) {
            throw new RuntimeException('Unable to initialise the media storage directory.');
        }

        $resolved = realpath($root);
        if ($resolved === false) {
            throw new RuntimeException('Unable to resolve the media storage directory.');
        }

        return $resolved;
    }

    /** Validate transfer destinations without creating any directories. */
    public function assertSeparateFromSource(string $sourceRoot): void
    {
        foreach ([$this->config->root(), $this->config->departmentRoot()] as $configured) {
            $ancestor = $configured;
            $suffix = [];
            while (!file_exists($ancestor) && !is_link($ancestor)) {
                $parent = dirname($ancestor);
                if ($parent === $ancestor) throw new RuntimeException('Local storage is unavailable.');
                array_unshift($suffix, basename($ancestor));
                $ancestor = $parent;
            }
            $resolved = realpath($ancestor);
            if ($resolved === false) throw new RuntimeException('Local storage is unavailable.');
            $destination = rtrim($resolved, DIRECTORY_SEPARATOR) . ($suffix ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR,$suffix) : '');
            if ($destination === $sourceRoot || str_starts_with($destination,$sourceRoot.DIRECTORY_SEPARATOR)
                || str_starts_with($sourceRoot,$destination.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Local and department storage must be separate from the server library.');
            }
        }
    }

    public function departmentRoot(): string
    {
        if ($this->config->centralized()) {
            $root = (new ServerLibraryService($this->config))->root() . '/DEPARTMENT';
            if (is_link($root)) throw new RuntimeException('Linked department storage is unavailable.');
            if (!is_dir($root) && !mkdir($root, 0770) && !is_dir($root)) throw new RuntimeException('Department storage is unavailable.');
            return $root;
        }
        $root = $this->config->departmentRoot();
        if (! is_dir($root) && ! mkdir($root, 0775, true) && ! is_dir($root)) {
            throw new RuntimeException('Unable to initialise the department storage directory.');
        }

        $resolved = realpath($root);
        if ($resolved === false) {
            throw new RuntimeException('Unable to resolve the department storage directory.');
        }

        return $resolved;
    }

    public function normalizeRelativePath(?string $path, bool $allowEmpty = true): string
    {
        if ($this->config->centralized()) {
            $path = (string)$path;
            if ($path === '' && !$allowEmpty) throw new RuntimeException('A folder path is required.');
            if ($path === '') return '';
            if (str_starts_with($path,'/') || str_contains($path,'\\') || preg_match('/[\x00-\x1f]/',$path) || preg_match('/^[A-Za-z]:/',$path)) throw new RuntimeException('Invalid folder path.');
            foreach (explode('/',$path) as $part) if ($part === '' || str_starts_with($part,'.')) throw new RuntimeException('Invalid folder path.');
            return $path;
        }
        $path = str_replace('\\', '/', trim((string) $path));
        $path = trim($path, '/');

        if ($path === '') {
            if ($allowEmpty) {
                return '';
            }

            throw new RuntimeException('A folder path is required.');
        }

        if (str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
            throw new RuntimeException('The folder path is invalid.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || ! preg_match('/^[\\pL\\pN][\\pL\\pN ,._()\\-]{0,119}$/u', $segment)) {
                throw new RuntimeException('The folder path contains an invalid name.');
            }
        }

        return $path;
    }

    public function directory(string $relativePath = '', bool $create = false): string
    {
        if ($this->config->centralized()) {
            $library = new ServerLibraryService($this->config);
            $relativePath = $library->relative($relativePath);
            if ($create) {
                $cursor = $library->root();
                foreach ($relativePath === '' ? [] : explode('/',$relativePath) as $part) {
                    $cursor .= '/'.$part;
                    if (is_link($cursor)) throw new RuntimeException('Linked server paths are unavailable.');
                    if (!is_dir($cursor) && !mkdir($cursor,0770) && !is_dir($cursor)) throw new RuntimeException('Cannot create server folder.');
                }
            }
            return $library->resolve($relativePath,true);
        }
        $relativePath = $this->normalizeRelativePath($relativePath);
        $root         = $this->root();
        $target       = $root . ($relativePath === '' ? '' : DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        if ($create && ! is_dir($target) && ! mkdir($target, 0775, true) && ! is_dir($target)) {
            throw new RuntimeException('Unable to create the requested folder.');
        }

        $resolved = realpath($target);
        if ($resolved === false || ! is_dir($resolved) || ! $this->isWithin($resolved, $root)) {
            throw new RuntimeException('The requested folder is outside the managed storage.');
        }

        return $resolved;
    }

    public function departmentRelativeDirectory(string $department, string $folderPath = ''): string
    {
        $department = $this->departmentFolderName($department);
        $folderPath = $this->normalizeRelativePath($folderPath);

        return $this->normalizeRelativePath($department . ($folderPath === '' ? '' : '/' . $folderPath), false);
    }

    public function ensureDepartmentDirectory(string $department, string $folderPath = ''): string
    {
        $root = $this->departmentRoot();
        $relativePath = $this->departmentRelativeDirectory($department, $folderPath);
        $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if ($this->config->centralized()) {
            $cursor = $root;
            foreach (explode('/',$relativePath) as $part) {
                $cursor .= '/'.$part;
                if (is_link($cursor)) throw new RuntimeException('Linked department paths are unavailable.');
            }
        }
        if (! is_dir($target) && ! mkdir($target, 0775, true) && ! is_dir($target)) {
            throw new RuntimeException('Unable to create the designated department folder.');
        }

        $resolved = realpath($target);
        if ($resolved === false || ! is_dir($resolved) || ! $this->isWithin($resolved, $root)) {
            throw new RuntimeException('The department folder is outside the managed department storage.');
        }

        return $resolved;
    }

    public function departmentFolderName(string $department): string
    {
        $department = trim($department);
        $shortName = preg_replace('/\s+Department$/iu', '', $department) ?? $department;

        return $this->safeDirectorySegment($shortName === '' ? $department : $shortName);
    }

    public function isDepartmentStoredFile(string $path): bool
    {
        try {
            $resolved = $this->resolveDatabaseFile($path);
        } catch (RuntimeException) {
            return false;
        }

        return $this->isWithin($resolved, $this->departmentRoot());
    }

    public function isDepartmentStoredDirectory(string $path): bool
    {
        try {
            $resolved = $this->resolveDatabaseDirectory($path);
        } catch (RuntimeException) {
            return false;
        }

        return $this->isWithin($resolved, $this->departmentRoot());
    }

    /** @return array{filename: string, filepath: string, mimeType: string, size: int} */
    public function storeUpload(UploadedFile $file, string $relativeDirectory = ''): array
    {
        if (! $file->isValid() || $file->hasMoved()) {
            $reason = trim($file->getErrorString());
            throw new RuntimeException($reason === ''
                ? 'The uploaded file is invalid.'
                : 'The upload was rejected: ' . $reason);
        }

        if ($this->config->maxUploadBytes() > 0 && $file->getSize() > $this->config->maxUploadBytes()) {
            throw new RuntimeException('The uploaded file exceeds the permitted size.');
        }

        $mimeType = (string) $file->getMimeType();
        if (! in_array($mimeType, $this->config->allowedMimeTypes(), true)) {
            throw new RuntimeException('This file type is not allowed.');
        }

        $directory = $this->directory($relativeDirectory, true);
        $filename  = $this->safeFilename($file->getClientName());
        $filename  = $this->availableFilename($directory, $filename);

        $file->move($directory, $filename);
        $filepath = realpath($directory . DIRECTORY_SEPARATOR . $filename);
        if ($filepath === false || ! $this->isWithin($filepath, $this->root())) {
            throw new RuntimeException('The uploaded file could not be stored safely.');
        }
        $relativeStoragePath = str_replace('\\', 'writable/uploads', trim(str_replace(rtrim(realpath(FCPATH), '\\/'), '', $filepath), '\\/'));

        return [
            'filename' => $filename,
            'filepath' => $filepath,
            'mimeType' => $mimeType,
            'size'     => (int) filesize($filepath),
        ];
    }

    /** @return array{filename: string, filepath: string, mimeType: string, size: int} */
    public function storeServerCopy(string $sourcePath, string $relativeDirectory = ''): array
    {
        return $this->storeCopyInDirectory($sourcePath, $this->directory($relativeDirectory, true), $this->root());
    }

    /** Copy a posting batch in parallel; either return all copies or remove all of them. */
    public function storeServerCopies(array $sources, string $relativeDirectory): array
    {
        $directory = $this->directory($relativeDirectory, true);
        $copies = [];
        $jobs = [];
        try {
            foreach ($sources as $sourcePath) {
                $source = realpath($sourcePath);
                if ($source === false || !is_file($source) || !is_readable($source)) throw new RuntimeException('The server file is unavailable.');
                $size = filesize($source);
                if ($size === false || ($this->config->maxUploadBytes() > 0 && $size > $this->config->maxUploadBytes())) throw new RuntimeException('The server file exceeds the permitted size.');
                $mime = \App\Libraries\MediaMimeType::forFile($source);
                if (!in_array($mime, $this->config->allowedMimeTypes(), true)) throw new RuntimeException('This server file type is not allowed.');
                $filename = $this->availableFilename($directory, $this->safeFilename(basename($source)));
                $target = $directory . DIRECTORY_SEPARATOR . $filename;
                // Reserve names before starting workers, including duplicate basenames.
                $reservation = @fopen($target, 'x');
                if ($reservation === false) throw new RuntimeException('The local copy could not be reserved.');
                fclose($reservation);
                $copies[] = ['filename'=>$filename, 'filepath'=>$target, 'mimeType'=>$mime, 'size'=>(int)$size];
                $jobs[] = [$source, $target];
            }
            (new \App\Libraries\ParallelFileCopier())->copy($jobs);
            foreach ($copies as $copy) {
                clearstatcache(true, $copy['filepath']);
                if (filesize($copy['filepath']) !== $copy['size']) throw new RuntimeException('A server file changed or was only partly copied.');
            }
            return $copies;
        } catch (\Throwable $error) {
            foreach ($copies as $copy) $this->deleteDatabaseFile($this->databasePath($copy['filepath']));
            throw $error;
        }
    }

    /** @return array{filename: string, filepath: string, mimeType: string, size: int} */
    public function storeDepartmentCopy(string $sourcePath, string $department, string $folderPath = ''): array
    {
        return $this->storeCopyInDirectory(
            $sourcePath,
            $this->ensureDepartmentDirectory($department, $folderPath),
            $this->departmentRoot()
        );
    }

    /** @return array{filename: string, filepath: string, mimeType: string, size: int} */
    private function storeCopyInDirectory(string $sourcePath, string $directory, string $allowedRoot): array
    {
        $source = realpath($sourcePath);
        if ($source === false || ! is_file($source) || ! is_readable($source)) {
            throw new RuntimeException('The server file is unavailable.');
        }

        $size = filesize($source);
        if ($size === false || ($this->config->maxUploadBytes() > 0 && $size > $this->config->maxUploadBytes())) {
            throw new RuntimeException('The server file exceeds the permitted size.');
        }

        $mimeType = \App\Libraries\MediaMimeType::forFile($source);
        if (! in_array($mimeType, $this->config->allowedMimeTypes(), true)) {
            throw new RuntimeException('This server file type is not allowed.');
        }

        $filename  = $this->availableFilename($directory, $this->safeFilename(basename($source)));
        $target    = $directory . DIRECTORY_SEPARATOR . $filename;
        if (! copy($source, $target)) {
            // A disconnected share can leave a partial destination behind.
            if (is_file($target) && $this->isWithin((string) realpath($target), $allowedRoot)) @unlink($target);
            throw new RuntimeException('The server file could not be copied into managed storage.');
        }

        $filepath = realpath($target);
        if ($filepath === false || ! $this->isWithin($filepath, $allowedRoot)) {
            @unlink($target);
            throw new RuntimeException('The server file could not be stored safely.');
        }
          $relativeStoragePath = str_replace('\\', 'writable/...', trim(str_replace(rtrim(realpath(FCPATH), '\\/'), '', $filepath), '\\/'));
        return [
            'filename' => $filename,
            'filepath' => $filepath,
            'mimeType' => $mimeType,
            'size'     => (int) filesize($filepath),
        ];
    }
    public function resolveDatabaseFile(string $storedPath): string
    {
        if ($storedPath === '' || str_contains($storedPath, "\0")) {
            throw new RuntimeException('The stored file path is invalid.');
        }

        $outsideManagedStorage = false;
        foreach ($this->databasePathCandidates($storedPath) as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved === false || ! is_file($resolved)) {
                continue;
            }
            foreach ($this->allowedRoots($storedPath) as $root) {
                if ($this->isWithin($resolved, $root)) {
                    return $resolved;
                }
            }
            $outsideManagedStorage = true;
        }

        throw new RuntimeException($outsideManagedStorage
            ? 'The stored file is outside the managed storage.'
            : 'The file is unavailable on the configured storage.');
    }

    public function resolveDatabaseDirectory(string $storedPath): string
    {
        if ($storedPath === '' || str_contains($storedPath, "\0")) {
            throw new RuntimeException('The stored directory path is invalid.');
        }

        foreach ($this->databasePathCandidates($storedPath) as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_dir($resolved)) {
                foreach ($this->allowedRoots($storedPath) as $root) {
                    if ($this->isWithin($resolved, $root)) {
                        return $resolved;
                    }
                }
            }
        }

        throw new RuntimeException('The directory is unavailable on the configured storage.');
    }

    /**
     * Converts a managed physical file or directory into a portable database
     * path. Forward slashes keep the value usable on Windows, macOS, and Linux.
     */
    public function databasePath(string $physicalPath): string
    {
        $resolved = realpath($physicalPath);
        if ($resolved === false) {
            throw new RuntimeException('The managed storage path is unavailable.');
        }

        if ($this->config->centralized() && $this->isWithin($resolved, $this->root())) {
            return 'server-library/' . str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($resolved, strlen($this->root())), '/\\'));
        }
        $roots = [
            ['root' => $this->root(), 'prefix' => 'writable/uploads'],
            ['root' => $this->departmentRoot(), 'prefix' => 'writable/Department'],
        ];
        foreach ($roots as $mapping) {
            if ($this->isWithin($resolved, $mapping['root'])) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($resolved, strlen($mapping['root'])), "\\/"));

                return $mapping['prefix'] . ($relative === '' ? '' : '/' . $relative);
            }
        }

        throw new RuntimeException('The path is outside the managed storage.');
    }

    /**
     * Deletes one database-referenced file after proving that it is inside a
     * configured storage root. A missing file is already physically deleted
     * and is therefore treated as an idempotent no-op.
     */
    public function deleteDatabaseFile(string $storedPath): bool
    {
        try {
            $resolved = $this->resolveDatabaseFile($storedPath);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'The file is unavailable on the configured storage.') {
                return false;
            }

            throw $exception;
        }

        if (! unlink($resolved)) {
            throw new RuntimeException('A media file could not be removed from storage.');
        }

        clearstatcache(true, $resolved);
        if (file_exists($resolved)) {
            throw new RuntimeException('A media file remains on storage after deletion.');
        }

        return true;
    }

    public function renameDatabaseFile(string $storedPath, string $newFilename): string
    {
        $source      = $this->resolveDatabaseFile($storedPath);
        $newFilename = $this->safeFilename($newFilename);
        $destination = dirname($source) . DIRECTORY_SEPARATOR . $newFilename;

        if (file_exists($destination)) {
            throw new RuntimeException('A file with that name already exists.');
        }
        if (! rename($source, $destination)) {
            throw new RuntimeException('The file could not be renamed.');
        }

        return $destination;
    }

    public function moveDatabaseFile(string $storedPath, string $relativeDirectory): string
    {
        $source      = $this->resolveDatabaseFile($storedPath);
        $destination = $this->directory($relativeDirectory, true) . DIRECTORY_SEPARATOR . basename($source);

        if (file_exists($destination)) {
            throw new RuntimeException('A file with the same name already exists in that folder.');
        }
        if (! rename($source, $destination)) {
            throw new RuntimeException('The file could not be moved.');
        }

        return $destination;
    }

    public function relativePath(string $absolutePath): ?string
    {
        $resolved = realpath($absolutePath);
        if ($resolved === false) {
            return null;
        }

        foreach ($this->allowedRoots() as $root) {
            if ($this->isWithin($resolved, $root)) {
                return str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($resolved, strlen($root)), "\\/"));
            }
        }

        return null;
    }

    /** Move to an exact portable path without overwriting another file. */
    public function relocateDatabaseFile(string $storedPath, string $destinationPath): string
    {
        $source = $this->resolveDatabaseFile($storedPath);
        $destinationPath = str_replace('\\', '/', $destinationPath);
        $root = null;
        $mappings = $this->config->centralized()
            ? ['server-library/' => $this->root()]
            : ['writable/uploads/' => $this->root(), 'writable/Department/' => $this->departmentRoot()];
        foreach ($mappings as $prefix => $candidate) {
            if (str_starts_with($destinationPath, $prefix)) {
                $root = $candidate;
                $relative = substr($destinationPath, strlen($prefix));
                break;
            }
        }
        if ($root === null || empty($relative) || str_contains($relative, "\0") || preg_match('#(^|/)\.\.?(/|$)#', $relative)) {
            throw new RuntimeException('The destination is outside the managed storage.');
        }
        $segments = explode('/', $relative);
        $filename = array_pop($segments);
        $directory = $root;
        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new RuntimeException('The destination path is invalid.');
            }
            $directory .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($directory)) {
                throw new RuntimeException('The destination contains a symbolic link.');
            }
            if (! is_dir($directory) && ! mkdir($directory, 0775) && ! is_dir($directory)) {
                throw new RuntimeException('The original folder could not be recreated.');
            }
            if (! $this->isWithin(realpath($directory) ?: '', $root)) {
                throw new RuntimeException('The destination is outside the managed storage.');
            }
        }
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        if ($filename === '' || file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('A file already exists at the original location. Move or rename it before restoring.');
        }
        if (! rename($source, $destination)) {
            throw new RuntimeException('The media file could not be moved on storage.');
        }
        return $destinationPath;
    }

    /** @return list<string> */
    private function allowedRoots(?string $storedPath = null): array
    {
        $roots = [$this->root(), $this->departmentRoot()];
        if ($storedPath !== null && str_starts_with($storedPath,'server-library/')) {
            $roots[] = (new ServerLibraryService($this->config))->root();
        }
        foreach ($this->config->legacyRoots() as $legacyRoot) {
            $resolved = realpath($legacyRoot);
            if ($resolved !== false && is_dir($resolved)) {
                $roots[] = $resolved;
            }
        }

        return array_values(array_unique($roots));
    }

    /** @return list<string> */
    private function databasePathCandidates(string $storedPath): array
    {
        if (str_starts_with($storedPath, 'server-library/')) {
            $relative = substr($storedPath, 15);
            if (preg_match('#(^|/)(\.|\.\.)(/|$)#', $relative) || str_contains($relative, '\\') || str_contains($relative, "\0")) throw new RuntimeException('Invalid server path.');
            $root = (new ServerLibraryService($this->config))->root();
            $candidate = $root;
            foreach (explode('/', $relative) as $part) {
                $candidate .= '/' . $part;
                if (is_link($candidate)) throw new RuntimeException('Linked server paths are unavailable.');
            }
            return [$candidate];
        }
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($storedPath));
        $relative = ltrim($normalized, DIRECTORY_SEPARATOR);
        $lowerRelative = strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $relative));
        $candidates = [$storedPath];

        foreach ([
            ['prefix' => 'writable/uploads', 'root' => $this->root()],
            ['prefix' => 'writable/department', 'root' => $this->departmentRoot()],
        ] as $mapping) {
            if ($lowerRelative === $mapping['prefix'] || str_starts_with($lowerRelative, $mapping['prefix'] . '/')) {
                $suffix = substr($relative, strlen(str_replace('/', DIRECTORY_SEPARATOR, $mapping['prefix'])));
                $candidates[] = $mapping['root'] . ($suffix === '' ? '' : DIRECTORY_SEPARATOR . ltrim($suffix, DIRECTORY_SEPARATOR));
            }
        }

        $candidates[] = rtrim(ROOTPATH, '\\/') . DIRECTORY_SEPARATOR . $relative;

        return array_values(array_unique($candidates));
    }

    private function safeFilename(string $filename): string
    {
        $filename = trim(str_replace(['/', '\\', "\0"], '_', $filename));
        $filename = preg_replace('/[^\\pL\\pN ._()\\-]/u', '_', $filename) ?? '';
        $filename = trim($filename, '. ');

        if ($filename === '' || mb_strlen($filename) > 180) {
            throw new RuntimeException('The filename is invalid.');
        }

        return $filename;
    }

    private function safeDirectorySegment(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[^\pL\pN ,._()\-]+/u', '_', $name) ?? '';
        $name = trim($name, " .\t\n\r\0\x0B");
        if ($name === '') {
            throw new RuntimeException('A valid department name is required for department storage.');
        }
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $name) === 1) {
            $name = '_' . $name;
        }

        return mb_substr($name, 0, 120);
    }

    private function availableFilename(string $directory, string $filename): string
    {
        $candidate = $filename;
        $base      = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $counter   = 1;

        while (file_exists($directory . DIRECTORY_SEPARATOR . $candidate)) {
            $candidate = $base . ' (' . $counter++ . ')' . ($extension === '' ? '' : '.' . $extension);
        }

        return $candidate;
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = rtrim(strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path)), DIRECTORY_SEPARATOR);
        $root = rtrim(strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root)), DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
