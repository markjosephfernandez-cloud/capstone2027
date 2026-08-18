<?php

namespace App\Controllers\Api;

use App\Libraries\MediaTitle;
use App\Models\FileModel;
use App\Models\FolderModel;
use App\Models\RecentModel;
use App\Models\TagModel;
use RuntimeException;

class FileController extends ApiController
{
    public function index()
    {
        $files  = new FileModel();
        $search = trim((string) $this->request->getGet('search'));
        $type   = strtolower(trim((string) $this->request->getGet('type')));
        $folder = $this->request->getGet('folder_id');
        $trash  = filter_var($this->request->getGet('trashed') ?? false, FILTER_VALIDATE_BOOLEAN);
        $query  = $files->where('is_deleted', $trash ? 1 : 0);
        $actor = $this->actor();
        if ((string) ($actor['role'] ?? '') === 'department') {
            $assignedIds = array_map('intval', array_column(db_connect()->table('media_assignments')->select('media_id')->where('department', (string) ($actor['department'] ?? ''))->groupBy('media_id')->get()->getResultArray(), 'media_id'));
            if ($assignedIds === []) {
                return $this->success([], 200, ['page' => $this->page(), 'per_page' => $this->perPage(), 'total' => 0]);
            }
            $query->whereIn('id', $assignedIds);
        }

        if ($search !== '') {
            if (mb_strlen($search) > 150) {
                return $this->error('validation_error', 'Search text may not exceed 150 characters.', 422);
            }
            $folderIds = array_map('intval', array_column((new FolderModel())->where('is_deleted', 0)->select('id')->like('name', $search)->findAll(), 'id'));
            $query->groupStart()
                ->like('display_title', $search)
                ->orLike('tags', $search)
                ->orLike('classification', $search)
                ->orLike('detected_objects', $search);
            if ($folderIds !== []) {
                $query->orWhereIn('folder_id', $folderIds);
            }
            $query->groupEnd();
        }
        if ($type !== '') {
            $query->where('filetype', $type);
        }
        if ($folder !== null && $folder !== '') {
            if (filter_var($folder, FILTER_VALIDATE_INT) === false) {
                return $this->error('validation_error', 'folder_id must be an integer.', 422);
            }
            $query->where('folder_id', (int) $folder);
        }

        $total = $query->countAllResults(false);
        $rows  = $query->orderBy('dateupload', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll($this->perPage(), ($this->page() - 1) * $this->perPage());

        return $this->success(array_map(fn (array $file): array => $this->payload($file), $rows), 200, [
            'page'     => $this->page(),
            'per_page' => $this->perPage(),
            'total'    => $total,
        ]);
    }

    public function show(int $id)
    {
        $file = $this->findFile($id);
        if ($file === null) {
            return $this->error('not_found', 'The requested file does not exist.', 404);
        }

        $data = $this->payload($file);
        $data['tags'] = array_column((new TagModel())->where('file_id', $id)->findAll(), 'tag');
        $this->recordRecent($file);

        return $this->success($data);
    }

    public function upload()
    {
        if (($response = $this->requireRole(['super_admin', 'admin', 'staff'])) !== null) {
            return $response;
        }

        $uploaded = $this->request->getFile('file');
        if ($uploaded === null) {
            return $this->error('validation_error', 'A file upload is required.', 422);
        }

        try {
            $displayTitle = MediaTitle::validate($this->request->getPost('display_title'));
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        }
        $description = trim((string) $this->request->getPost('description'));
        if (mb_strlen($description) > 5000) {
            return $this->error('validation_error', 'Description may not exceed 5,000 characters.', 422);
        }
        $folderId = $this->request->getPost('folder_id');
        if ($folderId !== null && $folderId !== '' && (filter_var($folderId, FILTER_VALIDATE_INT) === false || (new FolderModel())->where('is_deleted', 0)->find((int) $folderId) === null)) {
            return $this->error('validation_error', 'Choose a valid destination folder.', 422);
        }
        $folderId = $folderId === null || $folderId === '' ? null : (int) $folderId;
        $contentHash = hash_file('sha256', $uploaded->getTempName()) ?: null;
        $original = $contentHash === null ? null : (new FileModel())->where('is_deleted', 0)->groupStart()->where('content_hash', $contentHash)->orWhere('filehash', $contentHash)->groupEnd()->first();

        try {
            $stored = service('storage')->storeUpload($uploaded, (string) $this->request->getPost('directory'));
        } catch (RuntimeException $exception) {
            return $this->error('upload_rejected', $exception->getMessage(), 422);
        }

        $db     = db_connect();
        $files  = new FileModel();
        $actor  = $this->actor();
        $fileId = null;
        if ($original !== null) {
            $db->transStart();
            $db->table('duplicate_media')->insert(['original_file_id' => (int) $original['id'], 'filename' => $stored['filename'], 'display_title' => $displayTitle, 'filepath' => $stored['filepath'], 'mime_type' => $stored['mimeType'], 'size' => $stored['size'], 'content_hash' => $contentHash, 'uploaded_by' => (int) $actor['employee_id'], 'status' => 'pending', 'created_at' => gmdate('Y-m-d H:i:s')]);
            $db->table('files')->where('id', (int) $original['id'])->set('duplicate_count', 'duplicate_count + 1', false)->update();
            $db->transComplete();
            if (! $db->transStatus()) {
                @unlink($stored['filepath']);
                return $this->error('persistence_error', 'The duplicate could not be saved.', 500);
            }
            service('audit')->record($actor, $this->request, 'upload', 'DUPLICATE_DETECTED', 'file', (int) $original['id'], 'Saved duplicate upload "' . $stored['filename'] . '" without changing the original.');
            return $this->error('duplicate_detected', 'This media already exists. The duplicate was retained in the duplicate queue.', 409, ['display_title' => $displayTitle, 'original_display_title' => MediaTitle::forFile($original)]);
        }
        $db->transStart();
        try {
            $fileId = $files->insert([
                'filename'       => $stored['filename'],
                'display_title'  => $displayTitle,
                'filepath'       => $stored['filepath'],
                'filetype'       => strtolower((string) pathinfo($stored['filename'], PATHINFO_EXTENSION)),
                'mime_type'      => $stored['mimeType'],
                'media_kind'     => $this->mediaKindForMimeType($stored['mimeType']),
                'size'           => $stored['size'],
                'dateupload'     => gmdate('Y-m-d H:i:s'),
                'description'    => $description,
                'employee_id'    => (int) $actor['employee_id'],
                'folder_id'      => $folderId,
                'is_deleted'     => 0,
                'downloadable'   => 1,
                'featured'       => 1,
                'release_status' => 'published',
                'storage_stage'  => 'raw',
                'content_hash'   => $contentHash,
                'last_action'    => 'uploaded',
                'last_action_at' => gmdate('Y-m-d H:i:s'),
                'updated_at'     => gmdate('Y-m-d H:i:s'),
            ], true);
            if ($fileId === false) {
                throw new RuntimeException('The media record could not be saved.');
            }
            $file = $files->find((int) $fileId);
            if ($file === null) {
                throw new RuntimeException('The saved media could not be loaded for AI processing.');
            }
            $detection = service('eventDetection')->classifyAndStore($file);
            service('passports')->generate($file, $actor, $detection);
            service('watermarking')->applyForCreativeUpload($file, $actor);
            $publishedAt = gmdate('Y-m-d H:i:s');
            $files->update((int) $fileId, ['storage_stage' => 'processed', 'processed_at' => $publishedAt, 'release_status' => 'published', 'featured' => 1, 'published_at' => $publishedAt, 'publication_channel' => trim((string) $this->request->getPost('channel')) ?: 'General Events', 'last_action' => 'published', 'last_action_at' => $publishedAt, 'updated_at' => $publishedAt]);
        } catch (\Throwable $exception) {
            $db->transRollback();
            @unlink($stored['filepath']);
            log_message('error', 'API media processing failed: {message}', ['message' => $exception->getMessage()]);
            return $this->error('processing_error', 'The media could not complete automatic processing and was not published.', 500);
        }
        $db->transComplete();

        if (! $db->transStatus() || $fileId === false) {
            @unlink($stored['filepath']);

            return $this->error('persistence_error', 'The file could not be saved to the database.', 500);
        }

        $file = $files->find((int) $fileId);
        service('audit')->record($actor, $this->request, 'upload', 'UPLOAD', 'file', (int) $fileId, 'Uploaded "' . $stored['filename'] . '".');

        return $this->success($this->payload($file), 201);
    }

    public function update(int $id)
    {
        if (($response = $this->requireRole(['super_admin', 'admin', 'staff'])) !== null) {
            return $response;
        }
        $file = $this->findFile($id);
        if ($file === null) {
            return $this->error('not_found', 'The requested file does not exist.', 404);
        }

        $input   = $this->input();
        $changes = [];
        if (array_key_exists('display_title', $input)) {
            try {
                $changes['display_title'] = MediaTitle::validate($input['display_title']);
            } catch (\InvalidArgumentException $exception) {
                return $this->error('validation_error', $exception->getMessage(), 422);
            }
        }
        if (array_key_exists('description', $input)) {
            if (! is_string($input['description']) || mb_strlen($input['description']) > 5000) {
                return $this->error('validation_error', 'description must be a string of 5,000 characters or fewer.', 422);
            }
            $changes['description'] = trim($input['description']);
        }
        foreach (['featured', 'downloadable'] as $field) {
            if (array_key_exists($field, $input)) {
                $value = filter_var($input[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($value === null) {
                    return $this->error('validation_error', $field . ' must be boolean.', 422);
                }
                $changes[$field] = $value ? 1 : 0;
            }
        }
        if (array_key_exists('folder_id', $input)) {
            if ($input['folder_id'] !== null && filter_var($input['folder_id'], FILTER_VALIDATE_INT) === false) {
                return $this->error('validation_error', 'folder_id must be an integer or null.', 422);
            }
            if ($input['folder_id'] !== null && (new FolderModel())->where('is_deleted', 0)->find((int) $input['folder_id']) === null) {
                return $this->error('validation_error', 'Choose an active destination folder.', 422);
            }
            $changes['folder_id'] = $input['folder_id'] === null ? null : (int) $input['folder_id'];
        }
        if ($changes === []) {
            return $this->error('validation_error', 'No editable fields were supplied.', 422);
        }

        $changes['last_action']    = 'updated';
        $changes['last_action_at'] = gmdate('Y-m-d H:i:s');
        (new FileModel())->update($id, $changes);
        service('audit')->record($this->actor(), $this->request, 'metadata', 'METADATA_EDIT', 'file', $id, 'Updated metadata for "' . $file['filename'] . '".');

        return $this->success($this->payload((new FileModel())->find($id)));
    }

    public function rename(int $id)
    {
        if (($response = $this->requireRole(['admin', 'staff'])) !== null) {
            return $response;
        }
        $file = $this->findFile($id);
        if ($file === null) {
            return $this->error('not_found', 'The requested file does not exist.', 404);
        }
        $filename = $this->input()['filename'] ?? null;
        if (! is_string($filename)) {
            return $this->error('validation_error', 'filename is required.', 422);
        }

        try {
            $newPath = service('storage')->renameDatabaseFile((string) $file['filepath'], $filename);
        } catch (RuntimeException $exception) {
            return $this->error('rename_rejected', $exception->getMessage(), 422);
        }

        $newName = basename($newPath);
        try {
            (new FileModel())->update($id, [
                'filename'       => $newName,
                'filepath'       => $newPath,
                'filetype'       => strtolower((string) pathinfo($newName, PATHINFO_EXTENSION)),
                'last_action'    => 'renamed',
                'last_action_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            @rename($newPath, (string) $file['filepath']);

            return $this->error('persistence_error', 'The filename changed on disk but could not be saved; it was reverted.', 500);
        }

        service('audit')->record($this->actor(), $this->request, 'metadata', 'RENAME', 'file', $id, 'Renamed file to "' . $newName . '".');

        return $this->success($this->payload((new FileModel())->find($id)));
    }

    public function move(int $id)
    {
        if (($response = $this->requireRole(['super_admin', 'admin', 'staff'])) !== null) {
            return $response;
        }
        $file = $this->findFile($id);
        if ($file === null) {
            return $this->error('not_found', 'The requested file does not exist.', 404);
        }
        $directory = $this->input()['directory'] ?? null;
        if (! is_string($directory)) {
            return $this->error('validation_error', 'directory is required.', 422);
        }

        try {
            $newPath = service('storage')->moveDatabaseFile((string) $file['filepath'], $directory);
        } catch (RuntimeException $exception) {
            return $this->error('move_rejected', $exception->getMessage(), 422);
        }
        try {
            (new FileModel())->update($id, ['filepath' => $newPath, 'last_action' => 'updated', 'last_action_at' => gmdate('Y-m-d H:i:s')]);
        } catch (\Throwable) {
            @rename($newPath, (string) $file['filepath']);

            return $this->error('persistence_error', 'The file move was reverted because its database record could not be saved.', 500);
        }

        service('audit')->record($this->actor(), $this->request, 'metadata', 'MOVE', 'file', $id, 'Moved "' . $file['filename'] . '".');

        return $this->success($this->payload((new FileModel())->find($id)));
    }

    public function trash(int $id)
    {
        if (($response = $this->requireRole(['super_admin', 'admin', 'staff'])) !== null) {
            return $response;
        }
        $file = $this->findFile($id, true);
        if ($file === null) {
            return $this->error('not_found', 'The requested file does not exist.', 404);
        }
        if ((int) $file['is_deleted'] === 1) {
            return $this->error('already_trashed', 'The file is already in trash.', 409);
        }

        $actor = $this->actor();
        $db    = db_connect();
        $db->transStart();
        (new FileModel())->update($id, ['is_deleted' => 1, 'last_action' => 'moved_to_trash', 'last_action_at' => gmdate('Y-m-d H:i:s')]);
        $trash = $db->table('trashfiles');
        if ($trash->where('file_id', $id)->countAllResults() === 0) {
            $trash->insert(['file_id' => $id, 'deleted_by' => (int) $actor['employee_id'], 'reason' => 'Deleted through API']);
        }
        $db->transComplete();
        if (! $db->transStatus()) {
            return $this->error('persistence_error', 'The file could not be moved to trash.', 500);
        }

        service('audit')->record($actor, $this->request, 'delete', 'DELETE', 'file', $id, 'Moved "' . $file['filename'] . '" to trash.');

        return $this->success(['id' => $id, 'trashed' => true]);
    }

    public function restore(int $id)
    {
        if (($response = $this->requireRole(['super_admin', 'admin', 'staff'])) !== null) {
            return $response;
        }
        $file = $this->findFile($id, true);
        if ($file === null) {
            return $this->error('not_found', 'The requested file does not exist.', 404);
        }
        if ((int) $file['is_deleted'] === 0) {
            return $this->error('not_trashed', 'The file is not in trash.', 409);
        }

        $db = db_connect();
        $db->transStart();
        (new FileModel())->update($id, ['is_deleted' => 0, 'last_action' => 'restored', 'last_action_at' => gmdate('Y-m-d H:i:s')]);
        $db->table('trashfiles')->where('file_id', $id)->delete();
        $db->transComplete();
        if (! $db->transStatus()) {
            return $this->error('persistence_error', 'The file could not be restored.', 500);
        }

        service('audit')->record($this->actor(), $this->request, 'delete', 'RESTORE', 'file', $id, 'Restored "' . $file['filename'] . '" from trash.');

        return $this->success($this->payload((new FileModel())->find($id)));
    }

    public function download(int $id)
    {
        $file = $this->findFile($id);
        if ($file === null) {
            return $this->error('not_found', 'The requested file does not exist.', 404);
        }
        try {
            $path = service('storage')->resolveDatabaseFile((string) $file['filepath']);
        } catch (RuntimeException $exception) {
            return $this->error('file_unavailable', $exception->getMessage(), 409);
        }

        $this->recordRecent($file);
        service('audit')->record($this->actor(), $this->request, 'download', 'DOWNLOAD', 'file', $id, 'Downloaded "' . $file['filename'] . '".');

        return $this->response->download($path, null)->setFileName((string) $file['filename']);
    }

    /** @return array<string, mixed>|null */
    private function findFile(int $id, bool $includeTrashed = false): ?array
    {
        $query = new FileModel();
        if (! $includeTrashed) {
            $query->where('is_deleted', 0);
        }

        $file = $query->find($id);
        $actor = $this->actor();
        if ($file !== null && (string) ($actor['role'] ?? '') === 'department') {
            $assigned = db_connect()->table('media_assignments')->where('media_id', $id)->where('department', (string) ($actor['department'] ?? ''))->countAllResults() > 0;
            return $assigned ? $file : null;
        }
        return $file;
    }

    /** @param array<string, mixed> $file */
    private function payload(array $file): array
    {
        return [
            'id'            => (int) $file['id'],
            'display_title' => MediaTitle::forFile($file),
            'title'         => MediaTitle::forFile($file),
            'filetype'      => $file['filetype'],
            'mime_type'     => $file['mime_type'] ?? null,
            'media_kind'    => $file['media_kind'] ?? null,
            'size'          => (int) $file['size'],
            'date_uploaded' => $file['dateupload'],
            'description'   => $file['description'],
            'tags'          => $file['tags'] === null || $file['tags'] === '' ? [] : array_map('trim', explode(',', $file['tags'])),
            'featured'      => (bool) $file['featured'],
            'downloadable'  => (bool) ($file['downloadable'] ?? false),
            'folder_id'     => $file['folder_id'] === null ? null : (int) $file['folder_id'],
            'is_deleted'    => (bool) $file['is_deleted'],
            'release_status'=> $file['release_status'] ?? 'published',
            'last_action'   => $file['last_action'],
            'last_action_at'=> $file['last_action_at'],
            'storage_path'  => service('storage')->relativePath((string) $file['filepath']),
            'download_url'  => site_url('api/v1/files/' . $file['id'] . '/download'),
        ];
    }

    /** @param array<string, mixed> $file */
    private function recordRecent(array $file): void
    {
        $actor = $this->actor();
        (new RecentModel())->insert([
            'employee_id' => (int) $actor['employee_id'],
            'item_type'   => 'file',
            'item_name'   => MediaTitle::forFile($file),
            'filepath'    => $file['filepath'],
            'timestamp'   => gmdate('Y-m-d H:i:s'),
        ]);
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
}
