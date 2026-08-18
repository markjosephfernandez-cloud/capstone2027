<?php

namespace App\Controllers\Api;

use App\Models\FolderModel;
use RuntimeException;

class FolderController extends ApiController
{
    public function index()
    {
        if ((string) ($this->actor()['role'] ?? '') === 'department') {
            return $this->error('forbidden', 'Department accounts cannot browse the central repository.', 403);
        }
        $path = (string) ($this->request->getGet('path') ?? '');
        try {
            $relative  = service('storage')->normalizeRelativePath($path);
            $directory = service('storage')->directory($relative);
        } catch (RuntimeException $exception) {
            return $this->error('invalid_path', $exception->getMessage(), 422);
        }

        $metadata = [];
        $deletedPaths = [];
        foreach ((new FolderModel())->findAll() as $folder) {
            if (! empty($folder['path'])) {
                if ((int) ($folder['is_deleted'] ?? 0) === 1) {
                    $deletedPaths[(string) $folder['path']] = true;
                } else {
                    $metadata[(string) $folder['path']] = $folder;
                }
            }
        }
        foreach (array_keys($deletedPaths) as $deletedPath) {
            if ($relative === $deletedPath || str_starts_with($relative, $deletedPath . '/')) {
                return $this->error('not_found', 'The requested folder is in Trash.', 404);
            }
        }

        $items = [];
        foreach (new \DirectoryIterator($directory) as $item) {
            if ($item->isDot() || ! $item->isDir()) {
                continue;
            }
            $childPath = ltrim(($relative === '' ? '' : $relative . '/') . $item->getFilename(), '/');
            if (isset($deletedPaths[$childPath])) {
                continue;
            }
            $folder    = $metadata[$childPath] ?? null;
            $items[]   = [
                'id'          => $folder === null ? null : (int) $folder['id'],
                'name'        => $item->getFilename(),
                'path'        => $childPath,
                'description' => $folder['description'] ?? null,
                'created_at'  => $folder['created_at'] ?? gmdate('Y-m-d H:i:s', $item->getCTime()),
            ];
        }
        usort($items, fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return $this->success($items, 200, ['path' => $relative]);
    }

    public function create()
    {
        if (($response = $this->requireRole(['super_admin', 'admin', 'staff'])) !== null) {
            return $response;
        }

        $input       = $this->input();
        $name        = $input['name'] ?? null;
        $parent      = $input['parent_path'] ?? '';
        $description = $input['description'] ?? '';
        if (! is_string($name) || ! is_string($parent) || ! is_string($description)) {
            return $this->error('validation_error', 'name, parent_path, and description must be strings.', 422);
        }
        if (mb_strlen($description) > 5000) {
            return $this->error('validation_error', 'description may not exceed 5,000 characters.', 422);
        }

        try {
            $parentPath = service('storage')->normalizeRelativePath($parent);
            $name       = service('storage')->normalizeRelativePath($name, false);
            if (str_contains($name, '/')) {
                return $this->error('validation_error', 'name must be a single folder name.', 422);
            }
            $path       = service('storage')->normalizeRelativePath(($parentPath === '' ? '' : $parentPath . '/') . $name, false);
            if ($parentPath !== '' && (new FolderModel())->where('path', $parentPath)->where('is_deleted', 1)->first() !== null) {
                return $this->error('invalid_path', 'The parent folder is in Trash.', 422);
            }
            $parentDir  = service('storage')->directory($parentPath);
        } catch (RuntimeException $exception) {
            return $this->error('invalid_path', $exception->getMessage(), 422);
        }
        if (is_dir($parentDir . DIRECTORY_SEPARATOR . $name)) {
            return $this->error('already_exists', 'A folder with that name already exists.', 409);
        }

        try {
            service('storage')->directory($path, true);
            $id = (new FolderModel())->insert([
                'name'        => $name,
                'description' => trim($description),
                'path'        => $path,
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ], true);
        } catch (\Throwable) {
            @rmdir($parentDir . DIRECTORY_SEPARATOR . $name);

            return $this->error('persistence_error', 'The folder could not be created.', 500);
        }

        service('audit')->record($this->actor(), $this->request, 'folder', 'CREATE', 'folder', (int) $id, 'Created folder "' . $name . '".');

        return $this->success((new FolderModel())->find((int) $id), 201);
    }
}
