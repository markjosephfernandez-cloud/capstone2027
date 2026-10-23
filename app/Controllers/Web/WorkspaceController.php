<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\MediaStreamResponse;
use App\Libraries\MediaTitle;
use App\Libraries\RolePolicy;
use App\Models\FileModel;
use App\Models\FolderModel;
use App\Models\RecentModel;
use App\Models\TagModel;
use App\Services\TrashService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\GreyhoundAi;
use RuntimeException;

class WorkspaceController extends BaseController
{
    private const TRASH_RETENTION_DAYS = TrashService::RETENTION_DAYS;

    public function mediaSuggestions()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $response = $this->response->setHeader('Cache-Control', 'no-store, private');
        $query = trim(mb_substr((string) $this->request->getGet('q'), 0, 120));
        if (mb_strlen($query) < 2) {
            return $response->setJSON(['suggestions' => []]);
        }

        $db = db_connect();
        $actor = session()->get();
        $departmentUser = RolePolicy::role($actor) === 'department';
        $fileTable = ($departmentUser ? 'department_files' : 'files') . ' f';
        $files = $db->table($fileTable)->where('f.is_deleted', 0);
        $folders = $db->table($departmentUser ? 'department_folders' : 'folder')->where('is_deleted', 0);
        if ($departmentUser) {
            $files->where('f.department', (string) ($actor['department'] ?? ''));
            $folders->where('department', (string) ($actor['department'] ?? ''));
        }
        $type = trim(mb_substr((string) $this->request->getGet('type'), 0, 100));
        $stage = trim((string) $this->request->getGet('stage'));
        $distribution = trim((string) $this->request->getGet('distribution'));
        if ($type !== '') {
            $files->where('f.filetype', $type);
        }
        if (in_array($stage, ['raw', 'processed', 'published', 'department'], true)) {
            $stage === 'published' ? $files->where('f.release_status', 'published') : ($stage === 'department' ? $files->where('f.distribution_type', 'department') : $files->where('f.storage_stage', $stage));
        }
        if (in_array($distribution, ['mobile_app', 'department'], true)) {
            $files->where('f.distribution_type', $distribution);
        }

        // Search the whole accessible library, just like submitting a search.
        // Fetch distinct matching values, not the first page of matching files.
        $suggestions = [];
        $add = static function (string $value, string $kind) use (&$suggestions, $query): void {
            $value = trim($value);
            if ($value === '' || mb_strlen($value) > 120 || mb_stripos($value, $query) === false) {
                return;
            }
            $key = mb_strtolower($value);
            if (! isset($suggestions[$key])) {
                $suggestions[$key] = ['value' => $value, 'kind' => $kind];
            }
        };
        foreach (['name', 'path'] as $field) {
            foreach ((clone $folders)->select($field)->distinct()->like($field, $query)->orderBy($field)->limit(8)->get()->getResultArray() as $row) {
                $add((string) $row[$field], 'Folder');
            }
        }
        $fields = ['f.filename' => 'File', 'f.tags' => 'Tag', 'f.display_title' => 'Title'];
        if ($departmentUser) {
            $fields['f.description'] = 'Description';
        } else {
            $fields['f.classification'] = 'Classification';
            $fields['f.detected_objects'] = 'Detected label';
        }
        foreach ($fields as $field => $kind) {
            foreach ((clone $files)->from($fileTable, true)->select($field . ' AS value')->distinct()->like($field, $query)->orderBy($field)->limit($kind === 'Tag' ? 100 : 8)->get()->getResultArray() as $row) {
                $values = $kind === 'Tag' ? explode(',', (string) $row['value']) : [(string) $row['value']];
                foreach ($values as $value) {
                    $add($value, $kind);
                }
            }
        }
        if (! $departmentUser && $db->tableExists('passports')) {
            foreach (['p.event_name' => 'Event', 'p.date_taken' => 'Date taken'] as $field => $kind) {
                $values = (clone $files)->from($fileTable, true)->join('passports p', 'p.media_id = f.id')->select($field . ' AS value')->distinct()->like($field, $query);
                // A reviewed classification supersedes the historical passport event.
                if ($kind === 'Event') $values->groupStart()->where('f.classification', null)->orWhere('f.classification', '')->groupEnd();
                foreach ($values->orderBy($field)->limit(8)->get()->getResultArray() as $row) {
                    $add((string) $row['value'], $kind);
                }
            }
        }

        return $response->setJSON(['suggestions' => array_slice(array_values($suggestions), 0, 8)]);
    }

    public function media()
    {
        if ((new \Config\Storage())->centralized() && \App\Libraries\RolePolicy::allows(session()->get(), 'files.view_all')) {
            $folder = (int)$this->request->getGet('folder');
            $row = $folder ? db_connect()->table('server_media')->where('folder_id',$folder)->where('is_deleted',0)->get()->getRowArray() : null;
            return redirect()->to(site_url('workspace/server-library') . '?' . http_build_query(['folder'=>$row['path'] ?? '', 'q'=>(string)$this->request->getGet('q'), 'type'=>(string)$this->request->getGet('type')]));
        }
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $actor    = session()->get();
        $db       = db_connect();
        $locatedFileId = max(0, (int) $this->request->getGet('locate'));
        $query    = trim(mb_substr((string) $this->request->getGet('q'), 0, 120));
        $type     = trim(mb_substr((string) $this->request->getGet('type'), 0, 100));
        $stage    = trim((string) $this->request->getGet('stage'));
        $distribution = trim((string) $this->request->getGet('distribution'));
        if (! in_array($stage, ['', 'raw', 'processed', 'published', 'department'], true)) {
            $stage = '';
        }
        if (! in_array($distribution, ['', 'mobile_app', 'department'], true)) {
            $distribution = '';
        }
        $departmentUser = RolePolicy::role($actor) === 'department';
        $fileTable = $departmentUser ? 'department_files' : 'files';
        $folderTable = $departmentUser ? 'department_folders' : 'folder';
        $folderModel = (new FolderModel())->setTable($folderTable)->where('is_deleted', 0);
        if ($departmentUser) {
            $folderModel->where('department', (string) ($actor['department'] ?? ''));
        }
        $allActiveFolders = $folderModel->orderBy('name', 'ASC')->findAll();
        $allActiveFolderById = [];
        $activeChildCounts = [];
        foreach ($allActiveFolders as $folder) {
            $allActiveFolderById[(int) $folder['id']] = $folder;
            $parentId = (int) ($folder['parent_id'] ?? 0);
            $activeChildCounts[$parentId] = ($activeChildCounts[$parentId] ?? 0) + 1;
        }
        $departmentAccessibleFolderIds = array_map('intval', array_column($allActiveFolders, 'id'));
        $folderId = filter_var($this->request->getGet('folder'), FILTER_VALIDATE_INT);
        $folderId = $folderId !== false && $folderId > 0 ? $folderId : null;
        if ($query !== '') {
            $folderId = null;
        }
        $selectedFolder = $folderId === null ? null : ($allActiveFolderById[$folderId] ?? null);
        if ($folderId !== null && ($selectedFolder === null || ($departmentUser && ! in_array($folderId, $departmentAccessibleFolderIds, true)))) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'The requested folder is unavailable.');
        }

        $folderQuery = $db->table($folderTable . ' fo')
            ->select('fo.id, fo.name, fo.description, fo.path, fo.created_at, COUNT(f.id) AS file_count', false)
            ->join($fileTable . ' f', 'f.folder_id = fo.id AND f.is_deleted = 0', 'left')
            ->where('fo.is_deleted', 0);
        if ($query !== '') {
            $folderQuery->groupStart()->like('fo.name', $query)->orLike('fo.path', $query)->groupEnd();
        } elseif ($folderId === null) {
            $folderQuery->where('fo.parent_id IS NULL', null, false);
        } else {
            $folderQuery->where('fo.parent_id', $folderId);
        }
        if ($departmentUser) {
            $folderQuery->where('fo.department', (string) ($actor['department'] ?? ''));
        }
        $folders = $folderQuery
            ->groupBy('fo.id, fo.name, fo.description, fo.path, fo.created_at')
            ->orderBy('fo.name', 'ASC')
            ->get()
            ->getResultArray();
        foreach ($folders as &$listedFolder) {
            $listedFolder['subfolder_count'] = $activeChildCounts[(int) $listedFolder['id']] ?? 0;
        }
        unset($listedFolder);
        $allFolders = $allActiveFolders;
        $folderById = $allActiveFolderById;
        $folderOptions = [];
        foreach ($allFolders as $folder) {
            $folder['display_name'] = $this->folderDisplayPath($folder, $folderById);
            $folderOptions[] = $folder;
        }
        usort($folderOptions, static fn (array $left, array $right): int => strcasecmp((string) $left['display_name'], (string) $right['display_name']));
        $parentFolder = $selectedFolder === null || empty($selectedFolder['parent_id']) ? null : ($folderById[(int) $selectedFolder['parent_id']] ?? null);
        $selectedFolderPath = $selectedFolder === null ? 'All media' : $this->folderDisplayPath($selectedFolder, $folderById);
        $folderBreadcrumbs = [];
        $breadcrumbFolder = $selectedFolder;
        $visitedFolderIds = [];
        while ($breadcrumbFolder !== null) {
            $breadcrumbId = (int) $breadcrumbFolder['id'];
            if (isset($visitedFolderIds[$breadcrumbId])) {
                break;
            }
            $visitedFolderIds[$breadcrumbId] = true;
            array_unshift($folderBreadcrumbs, $breadcrumbFolder);
            $breadcrumbParentId = empty($breadcrumbFolder['parent_id']) ? null : (int) $breadcrumbFolder['parent_id'];
            $breadcrumbFolder = $breadcrumbParentId === null ? null : ($folderById[$breadcrumbParentId] ?? null);
        }
        $matchingFolderIds = $query === '' ? [] : $this->matchingFolderIds($allActiveFolders, $folderById, $query);

        $files = $db->table($fileTable . ' f')->select('f.*')->where('f.is_deleted', 0);
        if ($departmentUser) {
            $files->where('f.department', (string) ($actor['department'] ?? ''));
        }
        if ($query !== '') {
            if ($departmentUser) {
                $files->groupStart()->like('f.filename', $query)->orLike('f.display_title', $query)->orLike('f.tags', $query)->orLike('f.description', $query);
                if ($matchingFolderIds !== []) {
                    $files->orWhereIn('f.folder_id', $matchingFolderIds);
                }
                $files->groupEnd();
            } else $files = $this->applyFileManagementSearch($files, $query, $matchingFolderIds, $db->tableExists('passports'));
        } elseif ($folderId === null) {
            if (! $departmentUser) {
                $files->where('f.folder_id IS NULL', null, false);
            } elseif ($departmentAccessibleFolderIds !== []) {
                $files->groupStart()
                    ->where('f.folder_id IS NULL', null, false)
                    ->orWhereNotIn('f.folder_id', $departmentAccessibleFolderIds)
                    ->groupEnd();
            }
        } else {
            $files->where('f.folder_id', $folderId);
        }
        if ($type !== '') {
            $files->where('f.filetype', $type);
        }
        if ($stage !== '') {
            $stage === 'published' ? $files->where('f.release_status', 'published') : ($stage === 'department' ? $files->where('f.distribution_type', 'department') : $files->where('f.storage_stage', $stage));
        }
        if ($distribution !== '') {
            $files->where('f.distribution_type', $distribution);
        }

        $fileTypes = $db->table($fileTable)->select('filetype')->where('is_deleted', 0)->where('filetype IS NOT NULL', null, false);
        if ($departmentUser) {
            $fileTypes->where('department', (string) ($actor['department'] ?? ''));
        }
        if ($query === '') {
            if ($folderId === null) {
                $fileTypes->where('folder_id IS NULL', null, false);
            } else {
                $fileTypes->where('folder_id', $folderId);
            }
        }

        $perPage = 20;
        $matchingTotal = (int) $files->countAllResults(false);
        $totalPages = max(1, (int) ceil($matchingTotal / $perPage));
        $currentPage = min(max(1, (int) $this->request->getGet('page')), $totalPages);
        $offset = ($currentPage - 1) * $perPage;

        // Keep the linked original at the start of a located result set.
        if ($locatedFileId > 0) {
            $files->orderBy('CASE WHEN f.id = ' . $locatedFileId . ' THEN 0 ELSE 1 END', 'ASC', false);
        }
        $files->orderBy('f.dateupload', 'DESC')->orderBy('f.id', 'DESC');
        $files->limit($perPage, $offset);
        $listedFiles = $files->get()->getResultArray();
        foreach ($listedFiles as &$listedFile) {
            $containingFolderId = empty($listedFile['folder_id']) ? null : (int) $listedFile['folder_id'];
            $containingFolder = $containingFolderId === null ? null : ($folderById[$containingFolderId] ?? null);
            $listedFile['folder_display_path'] = $containingFolder === null
                ? 'Root Library'
                : $this->folderDisplayPath($containingFolder, $folderById);
            $listedFile['containing_folder_id'] = $containingFolderId;
        }
        unset($listedFile);

        return view('workspace/media', $this->pageData('file-management', 'File Management', [
            'files'          => $listedFiles,
            'pagination' => [
                'current_page' => $currentPage, 'total_pages' => $totalPages,
                'total_results' => $matchingTotal, 'per_page' => $perPage,
                'from' => $matchingTotal === 0 ? 0 : $offset + 1,
                'to' => min($offset + $perPage, $matchingTotal),
            ],
            'locatedFileId'  => $locatedFileId,
            'fileTypes'      => $fileTypes->groupBy('filetype')->orderBy('filetype')->get()->getResultArray(),
            'folders'        => $folders,
            'folderOptions'  => $folderOptions,
            'selectedFolder' => $selectedFolder,
            'parentFolder'   => $parentFolder,
            'folderBreadcrumbs' => $folderBreadcrumbs,
            'selectedFolderPath' => $selectedFolderPath,
            'folderId'       => $folderId,
            'query'          => $query,
            'type'           => $type,
            'stage'          => $stage,
            'distribution'   => $distribution,
            'canManage'      => RolePolicy::allows($actor, 'files.manage'),
            'canUpload'      => RolePolicy::allows($actor, 'files.upload'),
            'uploadMaxBytes' => $this->effectiveUploadMaxBytes(),
            'isDepartment'   => $departmentUser,
            'departments'    => $db->table('user')->select('department')->where('department !=', '')->groupBy('department')->orderBy('department')->get()->getResultArray(),
            'openRequests'   => $db->tableExists('media_requests') ? $db->table('media_requests')->where('status', 'submitted')->orderBy('submitted_at', 'DESC')->get()->getResultArray() : [],
            'success'        => session()->getFlashdata('success'),
            'error'          => session()->getFlashdata('error'),
        ]));
    }

    public function upload()
    {
        if ($this->request->getPost('cancel_upload') === '1') {
            return $this->cancelUpload();
        }
        if ($this->request->getPost('chunk_upload') === '1') {
            return $this->uploadChunk();
        }
        return $this->processUpload();
    }

    private function cancelUpload()
    {
        if (session()->get('isLoggedIn') !== true || ! RolePolicy::allows(session()->get(), 'files.upload')) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'message' => 'Please sign in with permission to upload files.']);
        }
        $id = $this->request->getPost('chunk_id');
        if (! is_string($id)) {
            return $this->uploadResult([], ['The upload identifier is invalid.']);
        }
        try {
            $result = (new \App\Services\ChunkUploadService())->cancel((int) session()->get('employee_id'), $id);
            return $this->response->setJSON(array_merge(['ok' => true, 'csrfToken' => csrf_hash()], $result));
        } catch (RuntimeException $exception) {
            return $this->uploadResult([], [$exception->getMessage()]);
        }
    }

    private function uploadChunk()
    {
        if (session()->get('isLoggedIn') !== true || ! RolePolicy::allows(session()->get(), 'files.upload')) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false, 'message' => 'Please sign in with permission to upload files.']);
        }
        try {
            $file = $this->request->getFile('file');
            if ($file === null) {
                throw new RuntimeException('The upload part is missing.');
            }
            $this->assertUploadReady($file);
            $total = filter_var($this->request->getPost('file_size'), FILTER_VALIDATE_INT);
            $offset = filter_var($this->request->getPost('chunk_offset'), FILTER_VALIDATE_INT);
            $chunkId = $this->request->getPost('chunk_id');
            $maximum = (new \Config\Storage())->maxUploadBytes();
            if (! is_string($chunkId) || $total === false || $offset === false || ($maximum > 0 && $total > $maximum)) {
                throw new RuntimeException('The file size is invalid or exceeds the configured limit.');
            }
            $metadata = [];
            foreach (['file_name', 'folder_id', 'relative_path', 'description', 'upload_batch_id'] as $field) {
                $value = $this->request->getPost($field);
                if (is_array($value) || mb_strlen((string) $value) > 5000) {
                    throw new RuntimeException('Upload details are invalid.');
                }
                $metadata[$field === 'file_name' ? 'name' : $field] = (string) $value;
            }
            $result = (new \App\Services\ChunkUploadService())->accept(
                (int) session()->get('employee_id'),
                $chunkId,
                $total,
                $offset,
                $file->getTempName(),
                $metadata,
                function (\App\Libraries\AssembledUpload $assembled) use ($metadata): array {
                    $response = $this->processUpload([['file' => $assembled, 'relativePath' => $metadata['relative_path']]]);
                    $payload = json_decode($response->getBody(), true);
                    return ['status' => $response->getStatusCode(), 'payload' => is_array($payload) ? $payload : ['ok' => false, 'message' => 'The file could not be processed.']];
                }
            );
            $result['payload']['csrfToken'] = csrf_hash();
            return $this->response->setStatusCode($result['status'])->setJSON($result['payload']);
        } catch (RuntimeException $exception) {
            return $this->uploadResult([], [$exception->getMessage()]);
        }
    }

    /** @param list<array{file:UploadedFile,relativePath:string}>|null $assembledUploads */
    private function processUpload(?array $assembledUploads = null)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'files.upload')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Your role is not allowed to upload media.');
        }

        $requestedFolderId = $this->request->getPost('folder_id');
        $folderId    = $requestedFolderId === null || $requestedFolderId === ''
            ? null
            : filter_var($requestedFolderId, FILTER_VALIDATE_INT);
        $description = trim((string) $this->request->getPost('description'));
        if (mb_strlen($description) > 5000) {
            return $this->uploadResult([], ['Description may not exceed 5,000 characters.']);
        }
        if ($folderId === false || ($folderId !== null && $folderId < 1)) {
            return $this->uploadResult([], ['Choose a valid destination folder.']);
        }
        $folder = $folderId === null ? null : (new FolderModel())->where('is_deleted', 0)->find((int) $folderId);
        if ($folderId !== null && $folder === null) {
            return $this->uploadResult([], ['The chosen destination folder is unavailable.']);
        }
        try {
            $directory = $folder === null ? '' : $this->folderStoragePath($folder);
        } catch (RuntimeException $exception) {
            return $this->uploadResult([], [$exception->getMessage()]);
        }

        $uploads = $assembledUploads ?? $this->uploadsFromRequest();
        if ($uploads === []) {
            return $this->uploadResult([], ['Choose one or more files, or select a folder to upload.']);
        }
        $uploadBatchId = trim((string) $this->request->getPost('upload_batch_id'));
        if ($uploadBatchId === '') {
            $uploadBatchId = bin2hex(random_bytes(16));
        }
        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $uploadBatchId) !== 1) {
            return $this->uploadResult([], ['The upload batch identifier is invalid.']);
        }

        if ((new \Config\Storage())->centralized()) {
            $service = new \App\Services\ServerLibraryUploadService(new \App\Services\ServerLibraryService());
            $successful = []; $errors = [];
            foreach ($uploads as $item) {
                try {
                    $service->receive($item['file'],['folder_id'=>$directory,'relative_path'=>$item['relativePath'],'upload_batch_id'=>$uploadBatchId],$actor);
                    $successful[] = $item['file']->getClientName();
                } catch (\Throwable $error) { $errors[] = $error->getMessage(); }
            }
            return $this->uploadResult($successful,$errors);
        }
        $successful = [];
        $errors     = [];
        // Large media are hashed and stored as streams; do not impose PHP's CPU timer.
        @set_time_limit(0);
        foreach ($uploads as $uploadItem) {
            $uploaded  = $uploadItem['file'];
            $stored    = null;
            $persisted = false;
            $transactionOpen = false;
            $displayTitle = mb_substr(pathinfo($uploaded->getClientName(), PATHINFO_FILENAME), 0, 150);
            if (trim($displayTitle) === '') {
                $displayTitle = 'Uploaded File';
            }
            $name      = $displayTitle;

            try {
                // PHP may create an UploadedFile object even when it rejected
                // the request (for example, because upload_max_filesize was
                // exceeded). Validate before reading the temporary path so an
                // empty path becomes a useful upload error instead of a
                // hash_file() ValueError.
                $this->assertUploadReady($uploaded);
                $targetDirectory = $this->uploadDirectory($directory, $uploadItem['relativePath']);
                $fileFolderId = $this->ensureUploadFolderHierarchy($folder, $uploadItem['relativePath']);
                $contentHash = hash_file('sha256', $uploaded->getTempName());
                $imageFingerprint = null;
                // `storage_stage=raw` is the authoritative Raw Files location;
                // retain legacy folder paths so existing rename/restore behavior stays compatible.
                $stored          = service('storage')->storeUpload($uploaded, $targetDirectory);
                $db              = db_connect();
                $db->transStart();
                $transactionOpen = true;
                $fileId = (new FileModel())->insert([
                    'filename'       => $stored['filename'],
                    'display_title'  => $displayTitle,
                    'filepath'       => service('storage')->databasePath($stored['filepath']),
                    'filetype'       => strtolower((string) pathinfo($stored['filename'], PATHINFO_EXTENSION)),
                    'mime_type'      => $stored['mimeType'],
                    'media_kind'     => $this->mediaKindForMimeType($stored['mimeType']),
                    'size'           => $stored['size'],
                    'dateupload'     => gmdate('Y-m-d H:i:s'),
                    'description'    => $description,
                    'employee_id'    => (int) $actor['employee_id'],
                    'folder_id'      => $fileFolderId,
                    'is_deleted'     => 0,
                    'downloadable'   => 1,
                    'release_status' => 'draft',
                    'featured'       => 0,
                    'storage_stage' => 'raw',
                    'distribution_type' => null,
                    'distribution_target' => null,
                    'content_hash'   => $contentHash ?: null,
                    'image_fingerprint' => $imageFingerprint,
                    'upload_batch_id' => $uploadBatchId,
                    'last_action'    => 'uploaded',
                    'last_action_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at'     => gmdate('Y-m-d H:i:s'),
                ], true);
                if ($fileId === false) {
                    throw new RuntimeException('The upload could not be saved to the database.');
                }

                service('audit')->record($actor, $this->request, 'upload', 'UPLOAD', 'file', (int) $fileId, 'Uploaded "' . $stored['filename'] . '" through the web interface.');
                $this->runPostUploadAutomation((int) $fileId, $actor);
                $db->transComplete();
                $transactionOpen = false;
                if (! $db->transStatus()) {
                    throw new RuntimeException('The upload workflow could not be committed.');
                }
                $persisted = true;
                $successful[] = $displayTitle;
            } catch (RuntimeException $exception) {
                if ($transactionOpen) {
                    $db->transRollback();
                    $transactionOpen = false;
                }
                if (! $persisted && $stored !== null && is_file($stored['filepath'])) {
                    @unlink($stored['filepath']);
                }
                $errors[] = $name . ': ' . $exception->getMessage();
            } catch (\Throwable $exception) {
                if ($transactionOpen) {
                    $db->transRollback();
                    $transactionOpen = false;
                }
                if (! $persisted && $stored !== null && is_file($stored['filepath'])) {
                    @unlink($stored['filepath']);
                }
                log_message('error', 'Upload failed for {filename}: {message}', [
                    'filename' => $name,
                    'message'  => $exception->getMessage(),
                ]);
                $errors[] = $name . ': The upload could not be completed.';
            }
        }

        return $this->uploadResult($successful, $errors);
    }

    public function uploadToken()
    {
        if (session()->get('isLoggedIn') !== true) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'message' => 'Your session has expired. Sign in again.',
            ]);
        }
        if (! RolePolicy::allows(session()->get(), 'files.upload')) {
            return $this->response->setStatusCode(403)->setJSON([
                'ok' => false,
                'message' => 'Your role is not allowed to upload media.',
            ]);
        }

        (new \App\Services\ChunkUploadService())->pruneExpired();
        return $this->response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->setJSON([
                'ok' => true,
                'csrfTokenName' => csrf_token(),
                'csrfToken' => csrf_hash(),
                'maxUploadBytes' => $this->effectiveUploadMaxBytes(),
                'chunkBytes' => $this->uploadChunkBytes(),
            ]);
    }

    private function effectiveUploadMaxBytes(): int
    {
        return (new \Config\Storage())->maxUploadBytes();
    }

    private function uploadChunkBytes(): int
    {
        $limits = [\App\Services\ChunkUploadService::MAX_CHUNK_BYTES];
        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $bytes = $this->phpIniBytes((string) ini_get($directive));
            if ($bytes > 0) {
                // Leave room for multipart headers and upload metadata.
                $limits[] = max(1, (int) floor($bytes * 0.75));
            }
        }

        return min($limits);
    }

    private function phpIniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        $multiplier = match ($unit) {
            'g' => 1073741824,
            'm' => 1048576,
            'k' => 1024,
            default => 1,
        };

        return max(0, (int) floor($number * $multiplier));
    }

    public function downloads()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $actor = session()->get();
        if (RolePolicy::role($actor) !== 'department') {
            return redirect()->to(site_url('workspace/file-management'));
        }

        $files = $this->actorFileModel()->where('is_deleted', 0)->where('downloadable', 1)
            ->orderBy('assigned_at', 'DESC')->findAll(100);

        return view('workspace/downloads', $this->pageData('downloads', 'Downloads', [
            'files' => $files,
        ]));
    }

    public function addTags()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'files.manage')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Your role is not allowed to edit metadata.');
        }
        $ids = $this->selectedFileIds();
        $rawTags = $this->request->getPost('tags');
        $rawTags = is_array($rawTags) ? $rawTags : preg_split('/[,\r\n]+/u', (string) $rawTags);
        $tags = [];
        foreach ($rawTags ?: [] as $tag) {
            $tag = trim(preg_replace('/\s+/u', ' ', (string) $tag) ?? '');
            if ($tag === '') {
                continue;
            }
            if (mb_strlen($tag) > 50) {
                return redirect()->to(site_url('workspace/file-management'))->with('error', 'Each tag may contain no more than 50 characters.');
            }
            $tags[mb_strtolower($tag)] = $tag;
        }
        if ($ids === [] || $tags === []) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Select at least one image and enter at least one tag.');
        }
        $files = (new FileModel())->where('is_deleted', 0)->whereIn('id', array_slice($ids, 0, 100))->findAll();
        $db = db_connect();
        $db->transStart();
        foreach ($files as $file) {
            $merged = [];
            foreach (preg_split('/\s*,\s*/u', (string) ($file['tags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tag) {
                $merged[mb_strtolower($tag)] = $tag;
            }
            foreach ($tags as $key => $tag) {
                $merged[$key] = $tag;
            }
            foreach ($tags as $tag) {
                if ((new TagModel())->where('file_id', (int) $file['id'])->where('tag', $tag)->first() === null) {
                    $tagType = match (strtolower((string) ($file['filetype'] ?? ''))) {
                        'mp4', 'mov', 'avi', 'mkv', 'webm' => 'video',
                        'mp3', 'wav', 'ogg', 'm4a', 'flac' => 'audio',
                        default => 'image',
                    };
                    (new TagModel())->insert(['tag' => $tag, 'type' => $tagType, 'filepath' => $file['filepath'], 'file_id' => (int) $file['id']]);
                }
            }
            (new FileModel())->update((int) $file['id'], ['tags' => implode(', ', array_values($merged)), 'last_action' => 'updated', 'last_action_at' => gmdate('Y-m-d H:i:s')]);
        }
        $db->transComplete();
        if (! $db->transStatus()) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Tags could not be saved.');
        }
        service('audit')->record($actor, $this->request, 'metadata', 'MANUAL_TAGS_ADDED', 'file', 0, 'Added manual tags to ' . count($files) . ' selected image(s).');
        return redirect()->to(site_url('workspace/file-management'))->with('success', 'Tags successfully added to selected images.');
    }

    public function createFolder()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'files.manage')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Your role is not allowed to create folders.');
        }

        $name        = trim((string) $this->request->getPost('name'));
        $description = trim((string) $this->request->getPost('description'));
        $requestedParentId = $this->request->getPost('parent_id');
        $parentId = $requestedParentId === null || $requestedParentId === '' ? null : filter_var($requestedParentId, FILTER_VALIDATE_INT);
        if ($name === '' || mb_strlen($description) > 5000) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', $name === '' ? 'Enter a folder name.' : 'Folder description may not exceed 5,000 characters.');
        }

        try {
            $storage = service('storage');
            if ($parentId === false || ($parentId !== null && $parentId < 1)) {
                throw new RuntimeException('Choose a valid parent folder.');
            }
            $parentFolder = $parentId === null ? null : (new FolderModel())->where('is_deleted', 0)->find((int) $parentId);
            if ($parentId !== null && $parentFolder === null) {
                throw new RuntimeException('The parent folder is unavailable.');
            }
            $name    = $storage->normalizeRelativePath($name, false);
            if (str_contains($name, '/')) {
                throw new RuntimeException('A folder name cannot contain subfolders.');
            }
            $duplicateQuery = (new FolderModel())->where('is_deleted', 0)->where('name', $name);
            $parentId === null ? $duplicateQuery->where('parent_id IS NULL', null, false) : $duplicateQuery->where('parent_id', $parentId);
            if ($duplicateQuery->first() !== null) {
                throw new RuntimeException('A folder with that name already exists.');
            }
            $root      = $storage->directory();
            $parentPath = $parentFolder === null ? '' : $this->folderStoragePath($parentFolder);
            $relativePath = (new FolderModel())->availableStoragePath(trim($parentPath . '/' . $name, '/'));
            $target    = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $createdDir = false;
            if (is_dir($target)) {
                throw new RuntimeException('A folder with that name already exists.');
            }
            $storage->directory($relativePath, true);
            $createdDir = true;
            $folderId = (new FolderModel())->insert([
                'name'        => $name,
                'parent_id'   => $parentId,
                'description' => $description,
                'path'        => $relativePath,
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ], true);
            if ($folderId === false) {
                throw new RuntimeException('The folder could not be saved.');
            }
        } catch (RuntimeException $exception) {
            if (isset($createdDir) && $createdDir && isset($target) && is_dir($target)) {
                @rmdir($target);
            }

            return redirect()->to(site_url('workspace/file-management'))->with('error', $exception->getMessage());
        } catch (\Throwable) {
            if (isset($createdDir) && $createdDir && isset($target) && is_dir($target)) {
                @rmdir($target);
            }

            return redirect()->to(site_url('workspace/file-management'))->with('error', 'The folder could not be created.');
        }

        service('audit')->record($actor, $this->request, 'folder', 'CREATE', 'folder', (int) $folderId, 'Created folder "' . $name . '" through the web interface.');

        $returnPath = 'workspace/file-management' . ($parentId === null ? '' : '?folder=' . (int) $parentId);

        return redirect()->to(site_url($returnPath))->with('success', 'Folder "' . $name . '" created.');
    }

    public function renameFolder(int $id)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'files.manage')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Your role is not allowed to rename folders.');
        }

        $folderModel = new FolderModel();
        $folder = $folderModel->where('is_deleted', 0)->find($id);
        if ($folder === null) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'The requested folder is unavailable.');
        }

        $storage = service('storage');
        $newName = trim((string) $this->request->getPost('name'));
        $oldName = (string) $folder['name'];
        $movedDirectory = false;
        $createdDirectory = false;
        $oldDirectory = null;
        $newDirectory = null;
        try {
            $newName = $storage->normalizeRelativePath($newName, false);
            if (str_contains($newName, '/')) {
                throw new RuntimeException('A folder name cannot contain subfolders.');
            }
            if ($newName === $oldName) {
                return redirect()->to(site_url('workspace/file-management?folder=' . $id))->with('success', 'The folder name is unchanged.');
            }
            $duplicateQuery = $folderModel->where('is_deleted', 0)->where('name', $newName)->where('id !=', $id);
            empty($folder['parent_id']) ? $duplicateQuery->where('parent_id IS NULL', null, false) : $duplicateQuery->where('parent_id', (int) $folder['parent_id']);
            $duplicate = $duplicateQuery->first();
            if ($duplicate !== null) {
                throw new RuntimeException('A folder with that name already exists.');
            }

            $oldRelative = $this->folderStoragePath($folder);
            $parentFolder = empty($folder['parent_id']) ? null : $folderModel->where('is_deleted', 0)->find((int) $folder['parent_id']);
            $parentPath = $parentFolder === null ? '' : $this->folderStoragePath($parentFolder);
            $newRelative = (new FolderModel())->availableStoragePath(trim($parentPath . '/' . $newName, '/'));
            try {
                $oldDirectory = $storage->directory($oldRelative);
            } catch (RuntimeException) {
                $oldDirectory = null;
            }
            $newDirectory = $storage->root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newRelative);
            $oldDatabasePrefix = $oldDirectory === null ? null : rtrim($storage->databasePath($oldDirectory), '/') . '/';
            if ($oldDirectory !== null) {
                if (file_exists($newDirectory)) {
                    throw new RuntimeException('A storage folder with that name already exists.');
                }
                if (! rename($oldDirectory, $newDirectory)) {
                    throw new RuntimeException('The storage folder could not be renamed.');
                }
                $movedDirectory = true;
            } else {
                if (file_exists($newDirectory)) {
                    throw new RuntimeException('A storage folder with that name already exists.');
                }
                $newDirectory = $storage->directory($newRelative, true);
                $createdDirectory = true;
            }

            $newDatabasePrefix = rtrim($storage->databasePath($newDirectory), '/') . '/';

            $db = db_connect();
            $files = (new FileModel())->findAll();
            $descendantFolders = $folderModel->where('is_deleted', 0)->like('path', $oldRelative . '/', 'after')->findAll();
            $db->transStart();
            $folderModel->update($id, ['name' => $newName, 'path' => $newRelative]);
            foreach ($descendantFolders as $descendant) {
                $descendantPath = (string) ($descendant['path'] ?? '');
                if (str_starts_with($descendantPath, $oldRelative . '/')) {
                    $folderModel->update((int) $descendant['id'], ['path' => $newRelative . substr($descendantPath, strlen($oldRelative))]);
                }
            }
            if ($oldDatabasePrefix !== null) {
                foreach ($files as $file) {
                    $oldFilePath = str_replace('\\', '/', (string) ($file['filepath'] ?? ''));
                    if (str_starts_with(mb_strtolower($oldFilePath), mb_strtolower($oldDatabasePrefix))) {
                        $relativeFilePath = substr($oldFilePath, strlen($oldDatabasePrefix));
                        (new FileModel())->update((int) $file['id'], ['filepath' => $newDatabasePrefix . $relativeFilePath]);
                    }
                }
            }
            $db->transComplete();
            if (! $db->transStatus()) {
                throw new RuntimeException('The renamed folder could not be saved.');
            }
        } catch (RuntimeException $exception) {
            if ($movedDirectory && $oldDirectory !== null && $newDirectory !== null && is_dir($newDirectory) && ! is_dir($oldDirectory)) {
                @rename($newDirectory, $oldDirectory);
            } elseif ($createdDirectory && $newDirectory !== null && is_dir($newDirectory)) {
                @rmdir($newDirectory);
            }
            return redirect()->to(site_url('workspace/file-management?folder=' . $id))->with('error', $exception->getMessage());
        } catch (\Throwable) {
            if ($movedDirectory && $oldDirectory !== null && $newDirectory !== null && is_dir($newDirectory) && ! is_dir($oldDirectory)) {
                @rename($newDirectory, $oldDirectory);
            }
            return redirect()->to(site_url('workspace/file-management?folder=' . $id))->with('error', 'The folder could not be renamed.');
        }

        service('audit')->record($actor, $this->request, 'folder', 'RENAME', 'folder', $id, 'Renamed folder "' . $oldName . '" to "' . $newName . '".');
        return redirect()->to(site_url('workspace/file-management?folder=' . $id))->with('success', 'Folder renamed to "' . $newName . '".');
    }

    public function deleteFolder(int $id)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'files.manage')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Your role is not allowed to delete folders.');
        }

        $folderModel = new FolderModel();
        $folder = $folderModel->where('is_deleted', 0)->find($id);
        if ($folder === null) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'The requested folder is unavailable.');
        }
        $folderIds = $this->descendantFolderIds($id);
        $files = (new FileModel())->whereIn('folder_id', $folderIds)->where('is_deleted', 0)->findAll();
        try {
            $this->trashService()->moveToTrash($files, $actor, 'Moved to Trash when folder "' . $folder['name'] . '" was deleted', $id, $folderIds);
        } catch (\Throwable $exception) {
            return redirect()->to(site_url('workspace/file-management?folder=' . $id))->with('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'The folder could not be deleted.');
        }

        foreach ($files as $file) {
            service('audit')->record($actor, $this->request, 'delete', 'FOLDER_FILE_TRASH', 'file', (int) $file['id'], 'Moved "' . $file['filename'] . '" to Trash when folder "' . $folder['name'] . '" was deleted.');
        }
        service('audit')->record($actor, $this->request, 'folder', 'DELETE', 'folder', $id, 'Deleted folder tree "' . $folder['name'] . '" and moved ' . count($files) . ' active file(s) to Trash.');

        return redirect()->to(site_url('workspace/file-management'))->with('success', 'Folder "' . $folder['name'] . '" moved to Trash with ' . count($files) . ' file(s).');
    }

    public function download(int $id)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $file = $this->actorFileModel()->where('is_deleted', 0)->find($id);
        if ($file === null) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'The requested media file is unavailable.');
        }
        $actor = session()->get();
        if (! $this->canAccessMedia($file, $actor)) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'This file is not assigned to your department.');
        }

        try {
            $path = $this->resolveMediaPathForActor($file, $actor);
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', $exception->getMessage());
        }

        $this->recordRecent($file);
        $this->actorFileModel()->update($id, [
            'last_action'    => 'downloaded',
            'last_action_at' => gmdate('Y-m-d H:i:s'),
        ]);
        service('audit')->record($actor, $this->request, 'download', 'DOWNLOAD', 'file', $id, 'Downloaded "' . $file['filename'] . '" through the web interface.');

        session()->close();
        return $this->response->download($path, null)->setFileName((string) $file['filename']);
    }

    /**
     * Streams a safely stored raster image for the protected library preview.
     *
     * Previews deliberately do not update the download history or audit log: one
     * library page can load many thumbnails at once.
     */
    public function preview(int $id)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $file = $this->actorFileModel()->where('is_deleted', 0)->find($id);
        if ($file === null) {
            return $this->response->setStatusCode(404)->setBody('Image not found.');
        }
        $actor = session()->get();
        if (! $this->canAccessMedia($file, $actor)) {
            return $this->response->setStatusCode(403)->setBody('This file is not assigned to your department.');
        }

        session()->close();
        try {
            $path = $this->resolveMediaPathForActor($file, $actor);
        } catch (RuntimeException) {
            return $this->response->setStatusCode(404)->setBody('Image not found.');
        }

        if ($this->request->getGet('thumbnail') === '1') {
            return $this->thumbnailResponse($path);
        }

        $mimeType = $this->previewMimeType($path);
        $allowedImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (! is_string($mimeType) || ! in_array($mimeType, $allowedImageTypes, true)) {
            return $this->response->setStatusCode(404)->setBody('Image preview is unavailable for this file type.');
        }

        return $this->inlineMediaResponse($path, $mimeType);
    }

    /** Streams a safely stored video for an authorized workspace viewer. */
    public function videoPreview(int $id)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $file = $this->actorFileModel()->where('is_deleted', 0)->find($id);
        if ($file === null) {
            return $this->response->setStatusCode(404)->setBody('Video not found.');
        }
        if (! $this->canAccessMedia($file, session()->get())) {
            return $this->response->setStatusCode(403)->setBody('This file is not assigned to your department.');
        }

        return $this->streamMediaPreview($file, session()->get());
    }

    /** Streams deleted image or video media without restoring it from Trash. */
    public function trashPreview(int $id)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        if (! RolePolicy::allows(session()->get(), 'files.manage')) {
            return $this->response->setStatusCode(403)->setBody('Trash previews are restricted to Creative Staff.');
        }
        $file = (new FileModel())->where('is_deleted', 1)->find($id);
        return $this->streamMediaPreview($file);
    }

    public function serverTrashFolder(string $id)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        $actor = session()->get();
        if (!RolePolicy::allows($actor, 'files.manage')) {
            return $this->response->setStatusCode(403)->setBody('Trash is restricted to Creative Staff.');
        }
        $data = $this->pageData('trash', 'Deleted server folder', []);
        session()->close();
        try {
            $child = $this->request->getGet('child') ?? '';
            if (! is_string($child)) {
                throw new RuntimeException('Invalid Trash path.');
            }
            $contents = $this->serverTrashEditor()->previewFolder($id, $child);
        } catch (RuntimeException) {
            return $this->response->setStatusCode(404)->setBody('Trash folder unavailable.');
        }
        return view('workspace/trash_server_folder', $data + $contents + ['trashId'=>$id, 'child'=>$child]);
    }

    public function serverTrashPreview(string $id)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        if (!RolePolicy::allows(session()->get(), 'files.manage')) {
            return $this->response->setStatusCode(403)->setBody('Trash previews are restricted to Creative Staff.');
        }
        session()->close();
        try {
            $child = $this->request->getGet('child') ?? '';
            if (! is_string($child)) {
                throw new RuntimeException('Invalid Trash path.');
            }
            $path = $this->serverTrashEditor()->previewPath($id, $child);
        } catch (RuntimeException) {
            return $this->response->noCache()->setStatusCode(404)->setBody('Trash preview unavailable.');
        }
        return $this->resolvedMediaPreview($path);
    }

    public function moveToTrash(int $id)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'files.manage')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Your role is not allowed to move media to Trash.');
        }

        $file = (new FileModel())->where('is_deleted', 0)->find($id);
        if ($file === null) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'The requested media file is unavailable or already in Trash.');
        }

        try {
            $this->trashService()->moveToTrash([$file], $actor, 'Moved to Trash through the web interface');
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', $exception->getMessage());
        } catch (\Throwable) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'The media file could not be moved to Trash.');
        }

        service('audit')->record($actor, $this->request, 'delete', 'DELETE', 'file', $id, 'Moved "' . $file['filename'] . '" to Trash through the web interface.');

        return redirect()->to(site_url('workspace/file-management'))->with('success', 'Moved "' . MediaTitle::forFile($file) . '" to Trash.');
    }

    public function restoreFromTrash(int $id)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'files.manage')) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'Your role is not allowed to restore media.');
        }

        $file = (new FileModel())->where('is_deleted', 1)->find($id);
        if ($file === null) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'The requested media file is not in Trash.');
        }
        if (db_connect()->table('trashfiles')->where('file_id', $id)->where('folder_root_id IS NOT NULL', null, false)->countAllResults() > 0) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'This file belongs to a deleted folder. Select and restore the folder instead.');
        }

        try {
            $this->trashService()->restoreMedia($file);
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('workspace/trash'))->with('error', $exception->getMessage());
        } catch (\Throwable) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'The media file could not be restored.');
        }

        service('audit')->record($actor, $this->request, 'delete', 'RESTORE', 'file', $id, 'Restored "' . $file['filename'] . '" from Trash through the web interface.');

        return redirect()->to(site_url('workspace/trash'))->with('success', 'Restored "' . MediaTitle::forFile($file) . '".');
    }

    public function permanentlyDeleteFromTrash(int $id)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $actor = session()->get();
        if (! $this->canPermanentlyDeleteTrash($actor)) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'Your role is not allowed to permanently delete media.');
        }

        $file = (new FileModel())->where('is_deleted', 1)->find($id);
        if ($file === null) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'The requested media file is not in Trash.');
        }
        if (db_connect()->table('trashfiles')->where('file_id', $id)->where('folder_root_id IS NOT NULL', null, false)->countAllResults() > 0) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'This file belongs to a deleted folder. Select and delete the folder instead.');
        }

        try {
            $this->trashService()->permanentlyRemoveMedia($file, $actor, 'PERMANENT_DELETE', $this->request);
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('workspace/trash'))->with('error', $exception->getMessage());
        } catch (\Throwable) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'The media file could not be permanently deleted.');
        }

        return redirect()->to(site_url('workspace/trash'))->with('success', 'Permanently deleted "' . MediaTitle::forFile($file) . '".');
    }

    public function bulkRestoreTrash()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $actor = session()->get();
        if (! $this->canRestoreTrash($actor)) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'Your role is not allowed to restore media.');
        }

        $ids = $this->selectedFileIds();
        $folderIds = $this->selectedFolderIds();
        $serverIds = $this->selectedServerTrashIds();
        if ($ids === [] && $folderIds === [] && $serverIds === []) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'Select one or more Trash items first.');
        }
        if (count($ids) + count($folderIds) + count($serverIds) > 500) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'Select no more than 500 Trash items at one time.');
        }

        $filesById = [];
        if ($ids !== []) {
            foreach (db_connect()->table('files f')->select('f.*')->join('trashfiles tf', 'tf.file_id = f.id', 'inner')->where('f.is_deleted', 1)->where('tf.folder_root_id IS NULL', null, false)->whereIn('f.id', $ids)->get()->getResultArray() as $file) {
                $filesById[(int) $file['id']] = $file;
            }
        }
        $foldersById = [];
        if ($folderIds !== []) {
            foreach (db_connect()->table('folder')->where('is_deleted', 1)->where('trash_root_id = id', null, false)->whereIn('id', $folderIds)->get()->getResultArray() as $folder) {
                $foldersById[(int) $folder['id']] = $folder;
            }
        }

        $restored = 0;
        $errors   = [];
        foreach ($ids as $id) {
            $file = $filesById[$id] ?? null;
            if ($file === null) {
                $errors[] = 'A selected file is no longer available in Trash.';
                continue;
            }

            try {
                $this->restoreTrashedMedia($file, $actor, 'BULK_RESTORE');
                $restored++;
            } catch (\Throwable $exception) {
                $errors[] = MediaTitle::forFile($file) . ': ' . ($exception->getMessage() ?: 'The file could not be restored.');
            }
        }
        foreach ($folderIds as $folderId) {
            $folder = $foldersById[$folderId] ?? null;
            if ($folder === null) {
                $errors[] = 'A selected folder is no longer available in Trash.';
                continue;
            }
            try {
                $this->trashService()->restoreFolderTree($folder, $actor, 'BULK_FOLDER_RESTORE', $this->request);
                $restored++;
            } catch (\Throwable $exception) {
                $errors[] = (string) $folder['name'] . ': ' . ($exception->getMessage() ?: 'The folder could not be restored.');
            }
        }

        [$serverRestored, $serverErrors] = $this->processServerTrashSelection($serverIds, false, $actor);
        $restored += $serverRestored;
        $errors = array_merge($errors, $serverErrors);
        $message = $restored . ' Trash item' . ($restored === 1 ? '' : 's') . ' restored.';
        if ($errors !== []) {
            $message .= ' ' . count($errors) . ' selected item' . (count($errors) === 1 ? '' : 's') . ' could not be restored. ' . implode(' ', array_slice($errors, 0, 2));
        }

        return redirect()->to(site_url('workspace/trash'))->with($restored > 0 ? 'success' : 'error', $message);
    }

    public function bulkDeleteTrash()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $actor = session()->get();
        if (! $this->canPermanentlyDeleteTrash($actor)) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'Your role is not allowed to permanently delete media.');
        }

        $ids = $this->selectedFileIds();
        $folderIds = $this->selectedFolderIds();
        $serverIds = $this->selectedServerTrashIds();
        if ($ids === [] && $folderIds === [] && $serverIds === []) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'Select one or more Trash items first.');
        }
        if (count($ids) + count($folderIds) + count($serverIds) > 500) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'Select no more than 500 Trash items at one time.');
        }

        $filesById = [];
        if ($ids !== []) {
            foreach (db_connect()->table('files f')->select('f.*')->join('trashfiles tf', 'tf.file_id = f.id', 'inner')->where('f.is_deleted', 1)->where('tf.folder_root_id IS NULL', null, false)->whereIn('f.id', $ids)->get()->getResultArray() as $file) {
                $filesById[(int) $file['id']] = $file;
            }
        }
        $foldersById = [];
        if ($folderIds !== []) {
            foreach (db_connect()->table('folder')->where('is_deleted', 1)->where('trash_root_id = id', null, false)->whereIn('id', $folderIds)->get()->getResultArray() as $folder) {
                $foldersById[(int) $folder['id']] = $folder;
            }
        }

        $deleted = 0;
        $errors  = [];
        foreach ($ids as $id) {
            $file = $filesById[$id] ?? null;
            if ($file === null) {
                $errors[] = 'A selected file is no longer available in Trash.';
                continue;
            }

            try {
                $this->trashService()->permanentlyRemoveMedia($file, $actor, 'BULK_PERMANENT_DELETE', $this->request);
                $deleted++;
            } catch (\Throwable $exception) {
                $errors[] = MediaTitle::forFile($file) . ': ' . ($exception->getMessage() ?: 'The file could not be permanently deleted.');
            }
        }
        foreach ($folderIds as $folderId) {
            $folder = $foldersById[$folderId] ?? null;
            if ($folder === null) {
                $errors[] = 'A selected folder is no longer available in Trash.';
                continue;
            }
            try {
                $this->trashService()->permanentlyRemoveFolder($folder, $actor, 'BULK_FOLDER_PERMANENT_DELETE', $this->request);
                $deleted++;
            } catch (\Throwable $exception) {
                $errors[] = (string) $folder['name'] . ': ' . ($exception->getMessage() ?: 'The folder could not be permanently deleted.');
            }
        }

        [$serverDeleted, $serverErrors] = $this->processServerTrashSelection($serverIds, true, $actor);
        $deleted += $serverDeleted;
        $errors = array_merge($errors, $serverErrors);
        $message = $deleted . ' Trash item' . ($deleted === 1 ? '' : 's') . ' permanently deleted.';
        if ($errors !== []) {
            $message .= ' ' . count($errors) . ' selected item' . (count($errors) === 1 ? '' : 's') . ' could not be deleted. ' . implode(' ', array_slice($errors, 0, 2));
        }

        return redirect()->to(site_url('workspace/trash'))->with($deleted > 0 ? 'success' : 'error', $message);
    }

    public function bulkTrash()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'files.manage')) {
            return redirect()->to($this->mediaListingUrl())->with('error', 'Your role is not allowed to move media to Trash.');
        }

        $ids = $this->selectedFileIds();
        if ($ids === []) {
            return redirect()->to($this->mediaListingUrl())->with('error', 'Select one or more media files first.');
        }
        if (count($ids) > 500) {
            return redirect()->to($this->mediaListingUrl())->with('error', 'Select no more than 500 media files at one time.');
        }

        $filesById = [];
        foreach ((new FileModel())->where('is_deleted', 0)->whereIn('id', $ids)->findAll() as $file) {
            $filesById[(int) $file['id']] = $file;
        }

        $moved  = 0;
        $errors = [];
        foreach ($ids as $id) {
            $file = $filesById[$id] ?? null;
            if ($file === null) {
                $errors[] = 'A selected file is unavailable.';
                continue;
            }

            try {
                $this->trashService()->moveToTrash([$file], $actor, 'Bulk move to Trash through the web interface');

                service('audit')->record($actor, $this->request, 'delete', 'BULK_DELETE', 'file', $id, 'Moved "' . $file['filename'] . '" to Trash as part of a bulk action.');
                $moved++;
            } catch (\Throwable) {
                $errors[] = MediaTitle::forFile($file) . ' could not be moved to Trash.';
            }
        }

        $message = $moved . ' file' . ($moved === 1 ? '' : 's') . ' moved to Trash.';
        if ($errors !== []) {
            $message .= ' ' . count($errors) . ' file' . (count($errors) === 1 ? '' : 's') . ' could not be moved.';
        }

        return redirect()->to($this->mediaListingUrl())->with($moved > 0 ? 'success' : 'error', $message);
    }

    public function bulkDownload()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $ids = $this->selectedFileIds();
        if ($ids === []) {
            return redirect()->to($this->mediaListingUrl())->with('error', 'Select one or more media files first.');
        }
        if (count($ids) > 500) {
            return redirect()->to($this->mediaListingUrl())->with('error', 'Select no more than 500 media files at one time.');
        }
        if (! class_exists(\ZipArchive::class)) {
            return redirect()->to($this->mediaListingUrl())->with('error', 'ZIP downloads are not available on this server.');
        }

        $filesById = [];
        foreach ($this->actorFileModel()->where('is_deleted', 0)->whereIn('id', $ids)->findAll() as $file) {
            $filesById[(int) $file['id']] = $file;
        }

        $zipPath = tempnam(WRITEPATH . 'cache', 'greyhound-bulk-');
        if ($zipPath === false) {
            return redirect()->to($this->mediaListingUrl())->with('error', 'The selected files could not be prepared for download.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            return redirect()->to($this->mediaListingUrl())->with('error', 'The selected files could not be prepared for download.');
        }

        $actor      = session()->get();
        $added      = 0;
        $usedNames  = [];
        try {
            foreach ($ids as $id) {
                $file = $filesById[$id] ?? null;
                if ($file === null) {
                    continue;
                }
                if (! $this->canAccessMedia($file, $actor)) {
                    continue;
                }
                try {
                    $path = $this->resolveMediaPathForActor($file, $actor);
                } catch (RuntimeException) {
                    continue;
                }

                $archiveName = $this->uniqueArchiveName((string) $file['filename'], $usedNames);
                if (! $zip->addFile($path, $archiveName)) {
                    continue;
                }
                $this->actorFileModel()->update($id, [
                    'last_action'    => 'downloaded',
                    'last_action_at' => gmdate('Y-m-d H:i:s'),
                ]);
                service('audit')->record($actor, $this->request, 'download', 'BULK_DOWNLOAD', 'file', $id, 'Added "' . $file['filename'] . '" to a bulk download archive.');
                $added++;
            }
        } catch (\Throwable) {
            $zip->close();
            @unlink($zipPath);

            return redirect()->to($this->mediaListingUrl())->with('error', 'The selected files could not be prepared for download.');
        }
        $zip->close();

        if ($added === 0) {
            @unlink($zipPath);
            return redirect()->to($this->mediaListingUrl())->with('error', 'None of the selected files are available for download.');
        }

        register_shutdown_function(static function () use ($zipPath): void {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        });

        return $this->response
            ->download($zipPath, null, true)
            ->setFileName('greyhound-media-' . gmdate('Ymd-His') . '.zip');
    }

    public function viewed(int $id)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        if (! (new \Config\Storage())->centralized()) {
            return $this->response->setStatusCode(204);
        }
        $actor = session()->get();
        $file = $this->actorFileModel()->where('is_deleted',0)->find($id);
        if (! $file || ! $this->canAccessMedia($file, $actor)) {
            return $this->response->setStatusCode(404);
        }
        if (RolePolicy::role($actor) === 'department') {
            db_connect()->table('department_recent')->onConstraint(['employee_id','department_file_id'])->upsert([
                'employee_id'=>(int)$actor['employee_id'],'department_file_id'=>$id,'viewed_at'=>gmdate('Y-m-d H:i:s')]);
        } elseif (str_starts_with($file['filepath'],'server-library/')) {
            (new \App\Services\CentralMediaService())->viewed(substr($file['filepath'],15),(int)$actor['employee_id']);
        }
        return $this->response->noCache()->setJSON(['csrfToken'=>csrf_hash(),'csrfTokenName'=>csrf_token()]);
    }

    public function recent()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        if ((new \Config\Storage())->centralized() && RolePolicy::role(session()->get()) === 'department') {
            session()->close();
            $rows = db_connect()->table('department_recent r')->select('f.id, f.filepath AS path, f.filename, f.folder_id, r.viewed_at')
                ->join('department_files f','f.id = r.department_file_id')->where('r.employee_id',(int)session()->get('employee_id'))
                ->where('f.department',(string)session()->get('department'))->where('f.is_deleted',0)
                ->orderBy('r.viewed_at','DESC')->limit(100)->get()->getResultArray();
            return view('workspace/server_recent',$this->pageData('recent','Recently viewed',['items'=>$rows,'departmentRecent'=>true]));
        }
        if ((new \Config\Storage())->centralized() && \App\Libraries\RolePolicy::allows(session()->get(), 'files.view_all')) {
            $controller = new ServerLibraryController();
            $controller->initController($this->request,$this->response,service('logger'));
            return $controller->recent();
        }
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        if (! RolePolicy::allows(session()->get(), 'files.manage')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Recent repository activity is restricted to Creative Staff.');
        }
        session()->close();

        $perPage = 20;
        $page = max(1, (int) $this->request->getGet('page'));
        $department = RolePolicy::role(session()->get()) === 'department';
        $recentQuery = db_connect()->table(($department ? 'department_files' : 'files') . ' f')
            ->select('f.id, f.display_title, f.filetype, f.size, f.dateupload, f.last_action, f.last_action_at, f.tags, u.name AS uploader_name, COALESCE(f.last_action_at, f.dateupload) AS activity_at', false)
            ->join('user u', 'u.employee_id = f.employee_id', 'left')
            ->where('f.is_deleted', 0);
        if ($department) {
            $recentQuery->where('f.department', (string) session()->get('department'));
        }
        $total = $recentQuery->countAllResults(false);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $recent = $recentQuery
            ->orderBy('COALESCE(f.last_action_at, f.dateupload)', 'DESC', false)
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return view('workspace/recent', $this->pageData('recent', 'Recent Files', [
            'files' => $recent,
            'pagination' => $this->paginationData($page, $total, $perPage),
        ]));
    }

    public function ifound()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        if (! RolePolicy::allows(session()->get(), 'files.manage')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Repository discovery is restricted to Creative Staff.');
        }
        // This page only reads data; release navigation requests from the same session.
        $reviewSuccess = session()->getFlashdata('success');
        $reviewError = session()->getFlashdata('error');
        session()->close();

        $term  = trim(mb_substr((string) $this->request->getGet('q'), 0, 120));
        $aiResult = (string) $this->request->getGet('ai_result');
        if (! in_array($aiResult, ['needs_review', 'different', 'dominant', 'reviewed', 'not_applicable'], true)) {
            $aiResult = '';
        }
        $perPage = 20;
        $page = max(1, (int) $this->request->getGet('page'));
        $files = db_connect()->table('files f')
            ->join('ai_event_detections ed', 'ed.media_id = f.id', 'left')
            ->join('folder fo', 'fo.id = f.folder_id', 'left')
            ->where('f.is_deleted', 0);
        if ($term !== '') {
            $files->groupStart()
                ->like('f.display_title', $term)
                ->orLike('f.filename', $term)
                ->orLike('f.detected_objects', $term)
                ->orLike('f.classification', $term)
                ->orLike('ed.event_name', $term)
                ->orLike('f.emotion', $term)
                ->orLike('f.gesture', $term)
                ->orLike('f.tags', $term)
                ->groupEnd();
        }

        // Rank all matching images before slicing a page, using only the fields
        // needed for classification. Load full media metadata for this page only.
        $candidates = (clone $files)
            ->select('f.id, f.folder_id, f.filetype, f.mime_type, ed.event_name, ed.confidence_score, ed.detector_version, ed.evidence_json, ed.reviewed_at, ed.needs_review')
            ->orderBy('f.dateupload', 'DESC')->orderBy('f.id', 'DESC')
            ->get()->getResultArray();
        $ranked = (new \App\Services\IFoundClassificationService())->rankedResults($candidates, $aiResult);
        $total = count($ranked);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $rankedPage = array_slice($ranked, ($page - 1) * $perPage, $perPage);
        $results = [];
        if ($rankedPage !== []) {
            $details = $files
                ->select('f.*, ed.event_name, ed.confidence_score, ed.needs_review, ed.detector_version, ed.evidence_json, ed.reviewed_at, fo.name AS folder_name')
                ->whereIn('f.id', array_column($rankedPage, 'id'))->get()->getResultArray();
            $detailsById = array_column($details, null, 'id');
            foreach ($rankedPage as $file) {
                if (isset($detailsById[$file['id']])) {
                    $results[] = array_merge($detailsById[$file['id']], $file);
                }
            }
        }

        return view('workspace/ifound', $this->pageData('ifound', 'iFound AI', [
            'term'    => $term,
            'aiResult' => $aiResult,
            'reviewSuccess' => $reviewSuccess, 'reviewError' => $reviewError,
            'results' => $results,
            'visualResults' => (new \App\Services\VisualMediaAnalysisService())->resultsFor(array_column($results, 'id')),
            'reviewThreshold' => (new GreyhoundAi())->reviewThreshold,
            'pagination' => $this->paginationData($page, $total, $perPage),
            'scanSource'      => service('serverScan')->sourceLabel(),
            'scannerEndpoint' => service('serverScan')->endpointLabel(),
            'scanDestination' => service('serverScan')->destinationLabel(),
            'scanBatchSize'   => service('serverScan')->batchSize(),
        ]));
    }

    public function startIFoundScan()
    {
        if (session()->get('isLoggedIn') !== true) {
            return $this->iFoundScanResponse(['ok' => false, 'message' => 'Your session has expired. Sign in again.'], 401);
        }
        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'files.manage')) {
            return $this->iFoundScanResponse(['ok' => false, 'message' => 'Repository scanning is restricted to Creative Staff.'], 403);
        }

        try {
            $paths = $this->request->getPost('file_paths');
            if (! is_array($paths) || ! $paths) {
                throw new RuntimeException('Select files to scan first.');
            }
            $result = service('serverScan')->begin((int) ($actor['employee_id'] ?? 0), $paths, \App\Services\SelectedImageScan::TASKS, (string) ($this->request->getPost('previous_job_id') ?? ''), (int) $this->request->getPost('selection_total'));
            return $this->iFoundScanResponse($result);
        } catch (RuntimeException $exception) {
            return $this->iFoundScanResponse(['ok' => false, 'message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            log_message('error', 'Unable to start iFound server scan: {message}', ['message' => $exception->getMessage()]);
            return $this->iFoundScanResponse(['ok' => false, 'message' => 'The server scan could not be started.'], 500);
        }
    }

    public function browseIFoundScan()
    {
        if (session()->get('isLoggedIn') !== true) {
            return $this->iFoundScanResponse(['ok' => false, 'message' => 'Sign in again to browse files.'], 401);
        }
        if (! RolePolicy::allows(session()->get(), 'files.manage')) {
            return $this->iFoundScanResponse(['ok' => false, 'message' => 'Your account cannot scan files.'], 403);
        }
        session()->close();
        try {
            return $this->iFoundScanResponse(service('serverScan')->browse((string) $this->request->getGet('folder'), mb_substr((string) $this->request->getGet('q'), 0, 120), (int) $this->request->getGet('page')));
        } catch (\Throwable $error) {
            return $this->iFoundScanResponse(['ok'=>false, 'message'=>$error instanceof RuntimeException ? $error->getMessage() : 'The scan source is unavailable. Please retry.'], 422);
        }
    }

    public function iFoundScanToken()
    {
        if (session()->get('isLoggedIn') !== true) {
            return $this->iFoundScanResponse(['ok' => false, 'message' => 'Your session has expired. Sign in again.'], 401);
        }
        if (! RolePolicy::allows(session()->get(), 'files.manage')) {
            return $this->iFoundScanResponse(['ok' => false, 'message' => 'Repository scanning is restricted to Creative Staff.'], 403);
        }

        return $this->iFoundScanResponse(['ok' => true, 'csrfTokenName' => csrf_token()]);
    }

    public function stepIFoundScan()
    {
        if (session()->get('isLoggedIn') !== true) {
            return $this->iFoundScanResponse(['ok' => false, 'message' => 'Your session has expired. Sign in again.'], 401);
        }
        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'files.manage')) {
            return $this->iFoundScanResponse(['ok' => false, 'message' => 'Repository scanning is restricted to Creative Staff.'], 403);
        }

        try {
            $jobId = trim((string) $this->request->getPost('job_id'));
            session()->close();
            @set_time_limit(150);
            $result = service('serverScan')->processNext($jobId, (int) ($actor['employee_id'] ?? 0), $actor);
            return $this->iFoundScanResponse($result);
        } catch (RuntimeException $exception) {
            return $this->iFoundScanResponse(['ok' => false, 'message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            log_message('error', 'Unable to continue iFound server scan: {message}', ['message' => $exception->getMessage()]);
            return $this->iFoundScanResponse(['ok' => false, 'message' => 'The server scan could not continue.'], 500);
        }
    }

    /** @param array<string, mixed> $payload */
    private function iFoundScanResponse(array $payload, int $status = 200)
    {
        $payload['csrfToken'] = csrf_hash();
        return $this->response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->setStatusCode($status)
            ->setJSON($payload);
    }

    public function analyzeSelectedImages()
    {
        return $this->selectedAnalysisResponse(false);
    }

    public function selectedAnalysisStatus()
    {
        return $this->selectedAnalysisResponse(true);
    }

    private function selectedAnalysisResponse(bool $status)
    {
        $actor = session()->get();
        if (($actor['isLoggedIn'] ?? false) !== true) {
            return $this->response->setStatusCode(401)->setJSON(['message' => 'Sign in again to analyze images.']);
        }
        if (! RolePolicy::allows($actor, 'files.manage')) {
            return $this->response->setStatusCode(403)->setJSON(['message' => 'Image analysis requires Creative Staff access.']);
        }
        $input = $status ? $this->request->getGet('file_ids') : $this->request->getPost('file_ids');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $input), static fn ($id) => $id > 0)));
        if (! $ids || count($ids) > 100) {
            return $this->response->setStatusCode(422)->setJSON(['message' => 'Select between 1 and 100 images.']);
        }
        session()->close();
        try {
            $queue = new \App\Services\IFoundAnalysisQueue();
            $data = $status ? ['results'=>$queue->status($ids)] : $queue->enqueue($ids);
            return $this->response->noCache()->setJSON($data);
        } catch (\Throwable $error) {
            log_message('error', 'Selected image analysis: {error}', ['error'=>$error->getMessage()]);
            return $this->response->setStatusCode(503)->setJSON(['message'=>'Image analysis is temporarily unavailable. Please try again.']);
        }
    }

    public function reviewEvent(int $id)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'files.manage')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Your role is not allowed to review AI classifications.');
        }

        $file = (new FileModel())->where('is_deleted', 0)->find($id);
        if ($file === null) {
            return redirect()->to(site_url('workspace/ifound'))->with('error', 'The requested media file is unavailable.');
        }

        $eventName = trim(mb_substr((string) $this->request->getPost('event_name'), 0, 150));
        $allowedEvents = array_keys((new GreyhoundAi())->eventKeywords);
        if ($eventName !== '' && ! in_array($eventName, $allowedEvents, true)) {
            return $this->reviewResponse('error', 'Choose a supported university event classification.');
        }

        $reviewNote = trim(mb_substr((string) $this->request->getPost('review_note'), 0, 500));
        $db = db_connect();
        if (! $db->tableExists('ai_event_detections')) {
            return $this->reviewResponse('error', 'AI review records are not available yet.');
        }
        $detection = $db->table('ai_event_detections')->where('media_id', $id)->get()->getRowArray();
        if ($detection === null) {
            return $this->reviewResponse('error', 'This media file has not been processed by iFound yet.');
        }

        try {
            $additionalTags = $this->reviewAdditionalTags($this->request->getPost('review_tags'), [$id]);
        } catch (RuntimeException $exception) {
            return $this->reviewResponse('error', $exception->getMessage());
        }
        $tags = $this->reviewedEventTags($file, $eventName === '' ? null : $eventName, $additionalTags[$id] ?? []);
        $db->transStart();
        (new FileModel())->update($id, [
            'classification'   => $eventName !== '' ? $eventName : 'Unclassified university media',
            'tags'             => implode(', ', $tags),
            'last_action'      => 'classification reviewed',
            'last_action_at'   => gmdate('Y-m-d H:i:s'),
        ]);
        $db->table('ai_event_detections')->where('id', (int) $detection['id'])->update([
            'event_name'   => $eventName !== '' ? $eventName : null,
            'needs_review' => 0,
            'reviewed_by'  => (int) ($actor['employee_id'] ?? 0),
            'reviewed_at'  => gmdate('Y-m-d H:i:s'),
            'review_note'  => $reviewNote !== '' ? $reviewNote : null,
        ]);
        (new TagModel())->where('file_id', $id)->delete();
        foreach ($tags as $tag) {
            (new TagModel())->insert([
                'tag'      => $tag,
                'type'     => $this->reviewTagType((string) ($file['filetype'] ?? '')),
                'filepath' => (string) ($file['filepath'] ?? ''),
                'file_id'  => $id,
            ]);
        }
        $db->transComplete();
        if (! $db->transStatus()) {
            return $this->reviewResponse('error', 'The AI classification review could not be saved.');
        }

        $classification = $eventName !== '' ? $eventName : 'Unclassified university media';
        if ((new \Config\Storage())->centralized()) {
            (new \App\Services\CentralMediaService())->syncReview($id);
        }
        service('audit')->record($actor, $this->request, 'metadata', 'AI_CLASSIFICATION_REVIEWED', 'file', $id, 'Reviewed iFound classification for "' . $file['filename'] . '" as ' . $classification . '.');

        return $this->reviewResponse('success', 'Review saved. Your final classification and tags have been updated.');
    }

    public function reviewEvents()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'files.manage')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Your role is not allowed to review AI classifications.');
        }

        $fileIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $this->request->getPost('file_ids')),
            static fn (int $id): bool => $id > 0
        )));
        if ($fileIds === [] || count($fileIds) > 100) {
            return $this->reviewResponse('error', 'Select between 1 and 100 images to review.');
        }

        $eventName = trim(mb_substr((string) $this->request->getPost('event_name'), 0, 150));
        $allowedEvents = array_keys((new GreyhoundAi())->eventKeywords);
        if ($eventName !== '' && ! in_array($eventName, $allowedEvents, true)) {
            return $this->reviewResponse('error', 'Choose a supported university event classification.');
        }

        $reviewNote = trim(mb_substr((string) $this->request->getPost('review_note'), 0, 500));
        $db = db_connect();
        if (! $db->tableExists('ai_event_detections')) {
            return $this->reviewResponse('error', 'AI review records are not available yet.');
        }

        $files = (new FileModel())->where('is_deleted', 0)->whereIn('id', $fileIds)->findAll();
        $filesById = [];
        foreach ($files as $file) {
            $filesById[(int) $file['id']] = $file;
        }
        $detections = $db->table('ai_event_detections')->whereIn('media_id', $fileIds)->get()->getResultArray();
        $detectionsByFile = [];
        foreach ($detections as $detection) {
            $detectionsByFile[(int) $detection['media_id']] = $detection;
        }
        if (count($filesById) !== count($fileIds) || count($detectionsByFile) !== count($fileIds)) {
            return $this->reviewResponse('error', 'One or more selected images are unavailable or have not been processed by iFound.');
        }

        try {
            $additionalTags = $this->reviewAdditionalTags($this->request->getPost('review_tags'), $fileIds);
        } catch (RuntimeException $exception) {
            return $this->reviewResponse('error', $exception->getMessage());
        }

        $classification = $eventName !== '' ? $eventName : 'Unclassified university media';
        $reviewedAt = gmdate('Y-m-d H:i:s');
        $db->transStart();
        foreach ($fileIds as $id) {
            $file = $filesById[$id];
            $detection = $detectionsByFile[$id];
            $tags = $this->reviewedEventTags($file, $eventName === '' ? null : $eventName, $additionalTags[$id] ?? []);
            (new FileModel())->update($id, [
                'classification' => $classification,
                    'tags' => implode(', ', $tags),
                'last_action' => 'classification reviewed',
                'last_action_at' => $reviewedAt,
            ]);
            $db->table('ai_event_detections')->where('id', (int) $detection['id'])->update([
                'event_name' => $eventName !== '' ? $eventName : null,
                'needs_review' => 0,
                'reviewed_by' => (int) ($actor['employee_id'] ?? 0),
                'reviewed_at' => $reviewedAt,
                'review_note' => $reviewNote !== '' ? $reviewNote : null,
            ]);
            (new TagModel())->where('file_id', $id)->delete();
            foreach ($tags as $tag) {
                (new TagModel())->insert([
                    'tag' => $tag,
                    'type' => $this->reviewTagType((string) ($file['filetype'] ?? '')),
                    'filepath' => (string) ($file['filepath'] ?? ''),
                    'file_id' => $id,
                ]);
            }
        }
        $db->transComplete();
        if (! $db->transStatus()) {
            return $this->reviewResponse('error', 'The AI classification reviews could not be saved.');
        }

        foreach ($fileIds as $id) {
            if ((new \Config\Storage())->centralized()) {
                (new \App\Services\CentralMediaService())->syncReview($id);
            }
        service('audit')->record($actor, $this->request, 'metadata', 'AI_CLASSIFICATION_REVIEWED', 'file', $id, 'Reviewed iFound classification for "' . $filesById[$id]['filename'] . '" as ' . $classification . '.');
        }

        return $this->reviewResponse('success', count($fileIds) . ' classification review' . (count($fileIds) === 1 ? '' : 's') . ' saved.');
    }

    private function reviewResponse(string $kind, string $message)
    {
        if ($this->request->isAJAX() && str_contains($this->request->getHeaderLine('Accept'), 'application/json')) {
            if ($kind === 'success') {
                session()->setFlashdata('success', $message);
            }
            return $this->response->noCache()->setStatusCode($kind === 'success' ? 200 : 422)
                ->setJSON(['ok'=>$kind === 'success', 'message'=>$message, 'csrfToken'=>csrf_hash()]);
        }
        return $this->redirectToIFound()->with($kind, $message);
    }

    private function redirectToIFound()
    {
        $term = trim(mb_substr((string) $this->request->getPost('return_q'), 0, 120));
        $url = site_url('workspace/ifound');
        if ($term !== '') {
            $url .= '?q=' . rawurlencode($term);
        }

        return redirect()->to($url);
    }

    /**
     * @param list<int> $fileIds
     * @return array<int, list<string>>
     */
    private function reviewAdditionalTags(mixed $input, array $fileIds): array
    {
        if ($input === null) {
            return [];
        }
        if (! is_array($input) || count($input) > count($fileIds)) {
            throw new RuntimeException('Add tags only to images selected for review.');
        }
        $result = [];
        foreach ($input as $fileId => $rawTags) {
            if (! in_array($fileId, $fileIds, true) || ! is_string($rawTags)) {
                throw new RuntimeException('Add tags only to images selected for review.');
            }
            if (mb_strlen($rawTags) > 2000) {
                throw new RuntimeException('Enter no more than 25 new tags per image, with up to 50 characters per tag.');
            }
            $tags = [];
            foreach (explode(',', $rawTags) as $tag) {
                $tag = trim(preg_replace('/\s+/u', ' ', $tag) ?? '');
                if (mb_strlen($tag) > 50) {
                    throw new RuntimeException('Each tag may contain no more than 50 characters.');
                }
                $tag = \App\Libraries\MediaTagPolicy::normalize($tag);
                if ($tag !== '') {
                    $tags[mb_strtolower($tag)] = $tag;
                }
            }
            if (count($tags) > 25) {
                throw new RuntimeException('Add no more than 25 new tags per image.');
            }
            $result[$fileId] = array_values($tags);
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $file
     * @param list<string> $additionalTags
     * @return list<string>
     */
    private function reviewedEventTags(array $file, ?string $eventName, array $additionalTags = []): array
    {
        $eventTags = array_keys((new GreyhoundAi())->eventKeywords);
        $excluded = array_fill_keys(array_map('mb_strtolower', array_merge($eventTags, ['AI Review Needed'])), true);
        $tags = [];
        foreach (preg_split('/\s*,\s*/u', (string) ($file['tags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tag) {
            $tag = trim($tag);
            if ($tag !== '' && ! isset($excluded[mb_strtolower($tag)]) && ! \App\Libraries\MediaTagPolicy::remove($tag, $file)) {
                $tags[mb_strtolower($tag)] = $tag;
            }
        }
        if ($eventName !== null) {
            foreach ([$eventName, 'University Event'] as $tag) {
                $tags[mb_strtolower($tag)] = $tag;
            }
        }

        foreach ($additionalTags as $tag) {
            $tags[mb_strtolower($tag)] = $tag;
        }

        return array_values($tags);
    }

    private function reviewTagType(string $extension): string
    {
        return match (strtolower($extension)) {
            'mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v' => 'video',
            'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac' => 'audio',
            default => 'image',
        };
    }

    public function trash()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        if (! RolePolicy::allows(session()->get(), 'files.manage')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Trash is restricted to Creative Staff.');
        }

        $actor = session()->get();
        $purgedCount = $this->trashService()->purgeExpiredTrash($actor, $this->request);
        $db = db_connect();
        $query = trim(mb_substr((string) $this->request->getGet('q'), 0, 120));
        $type = trim(mb_substr((string) $this->request->getGet('type'), 0, 30));
        $perPage = 20;
        $page = max(1, (int) $this->request->getGet('page'));
        $serverItems = [];
        $serverTrashError = null;
        try {
            $serverEditor = $this->serverTrashEditor();
            $purgedCount += $serverEditor->purgeExpiredTrash();
            $serverItems = $serverEditor->trashItems();
            $actorIds = array_values(array_unique(array_filter(array_column($serverItems, 'deleted_by'))));
            $names = $actorIds === [] ? [] : array_column($db->table('user')->select('employee_id, name')->whereIn('employee_id', $actorIds)->get()->getResultArray(), 'name', 'employee_id');
            foreach ($serverItems as &$serverItem) {
                $serverItem['deleted_by_name'] = $names[$serverItem['deleted_by']] ?? 'Unknown';
            }
            unset($serverItem);
        } catch (RuntimeException $exception) {
            $serverTrashError = 'Server items are unavailable. Connect the server share to view or restore them. Local Trash is still available.';
        }
        $fileTypes = $db->table('files f')->select('f.filetype')->join('trashfiles tf', 'tf.file_id = f.id', 'inner')->where('f.is_deleted', 1)->where('tf.folder_root_id IS NULL', null, false)->where('f.filetype !=', '')->distinct()->orderBy('f.filetype', 'ASC')->get()->getResultArray();
        foreach ($serverItems as $serverItem) {
            if ($serverItem['filetype'] !== '') {
                $fileTypes[] = ['filetype' => $serverItem['filetype']];
            }
        }
        $fileTypes = array_values(array_column($fileTypes, null, 'filetype'));
        usort($fileTypes, static fn ($a, $b) => strcasecmp($a['filetype'], $b['filetype']));
        $validTypes = array_map(static fn (array $row): string => (string) $row['filetype'], $fileTypes);
        if ($type !== '' && ! in_array($type, $validTypes, true)) {
            $type = '';
        }
        $serverItems = array_values(array_filter($serverItems, static fn ($item) =>
            ($type === '' || $item['filetype'] === $type) && ($query === '' || mb_stripos($item['path'] . ' ' . $item['deleted_by_name'], $query) !== false)));

        $folders = [];
        $folderCount = 0;
        $folderQuery = null;
        if ($type === '') {
            $folderQuery = $db->table('folder fo')
                ->select('fo.id, fo.name, fo.description, fo.path, fo.deleted_at, fo.deletion_reason AS reason, u.name AS deleted_by_name, (SELECT COUNT(*) FROM folder child WHERE child.is_deleted = 1 AND child.trash_root_id = fo.id AND child.id != fo.id) AS subfolder_count, (SELECT COUNT(*) FROM trashfiles tf2 INNER JOIN files f2 ON f2.id = tf2.file_id AND f2.is_deleted = 1 WHERE tf2.folder_root_id = fo.id) AS file_count, (SELECT COALESCE(SUM(f3.size), 0) FROM trashfiles tf3 INNER JOIN files f3 ON f3.id = tf3.file_id AND f3.is_deleted = 1 WHERE tf3.folder_root_id = fo.id) AS size', false)
                ->join('user u', 'u.employee_id = fo.deleted_by', 'left')
                ->where('fo.is_deleted', 1)
                ->where('fo.trash_root_id = fo.id', null, false);
            if ($query !== '') {
                $folderQuery->groupStart()->like('fo.name', $query)->orLike('fo.deletion_reason', $query)->orLike('u.name', $query)->groupEnd();
            }
            $folderCount = (clone $folderQuery)->countAllResults();
        }

        $trashQuery = $db->table('files f')
            ->select('f.id, f.display_title, f.filename, f.filetype, f.size, f.dateupload, f.last_action_at, tf.original_filepath, tf.deleted_at, tf.reason, u.name AS deleted_by_name')
            ->join('trashfiles tf', 'tf.file_id = f.id', 'inner')
            ->join('user u', 'u.employee_id = tf.deleted_by', 'left')
            ->where('f.is_deleted', 1)
            ->where('tf.folder_root_id IS NULL', null, false);
        if ($query !== '') {
            $trashQuery->groupStart()->like('f.display_title', $query)->orLike('f.filename', $query)->orLike('tf.original_filepath', $query)->orLike('tf.reason', $query)->orLike('u.name', $query)->groupEnd();
        }
        if ($type !== '') {
            $trashQuery->where('f.filetype', $type);
        }
        $fileCount = (clone $trashQuery)->countAllResults();
        $total = $folderCount + $fileCount + count($serverItems);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        if ($folderQuery !== null && $offset < $folderCount) {
            $folderLimit = min($perPage, $folderCount - $offset);
            $folders = $folderQuery->orderBy('fo.deleted_at', 'DESC')->limit($folderLimit, $offset)->get()->getResultArray();
            $folders = $this->trashService()->withRetention($folders);
        }
        $fileLimit = $perPage - count($folders);
        $fileOffset = max(0, $offset - $folderCount);
        $files = $fileLimit > 0
            ? $trashQuery->orderBy('tf.deleted_at', 'DESC')->orderBy('f.last_action_at', 'DESC')->limit($fileLimit, $fileOffset)->get()->getResultArray()
            : [];
        $files = $this->trashService()->withRetention($files);
        $serverItems = array_slice($serverItems, max(0, $offset - $folderCount - $fileCount), max(0, $perPage - count($folders) - count($files)));

        return view('workspace/trash', $this->pageData('trash', 'Trash', [
            'files'         => $files,
            'folders'       => $folders,
            'serverItems'   => $serverItems,
            'serverTrashError' => $serverTrashError,
            'retentionDays' => self::TRASH_RETENTION_DAYS,
            'purgedCount'   => $purgedCount,
            'query'         => $query,
            'type'          => $type,
            'fileTypes'     => $fileTypes,
            'pagination'    => $this->paginationData($page, $total, $perPage),
            'success'       => session()->getFlashdata('success'),
            'error'         => session()->getFlashdata('error'),
        ]));
    }

    /** @return array{current_page:int,total_pages:int,total_results:int,per_page:int,from:int,to:int} */
    private function paginationData(int $page, int $total, int $perPage): array
    {
        return [
            'current_page' => $page,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
            'total_results' => $total,
            'per_page' => $perPage,
            'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'to' => min($total, $page * $perPage),
        ];
    }

    public function trashFolder(int $id)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'files.manage')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Trash is restricted to Creative Staff.');
        }

        $db = db_connect();
        $folder = $db->table('folder')->where('id', $id)->where('is_deleted', 1)->get()->getRowArray();
        if ($folder === null || empty($folder['trash_root_id'])) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'The requested deleted folder is unavailable.');
        }
        $rootId = (int) $folder['trash_root_id'];
        $tree = $db->table('folder')->where('is_deleted', 1)->where('trash_root_id', $rootId)->orderBy('id', 'ASC')->get()->getResultArray();
        $folderById = [];
        foreach ($tree as $treeFolder) {
            $folderById[(int) $treeFolder['id']] = $treeFolder;
        }
        $rootFolder = $folderById[$rootId] ?? null;
        if ($rootFolder === null) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'The deleted folder root is unavailable.');
        }

        $breadcrumbs = [];
        $cursor = $folder;
        $visited = [];
        while ($cursor !== null && ! isset($visited[(int) $cursor['id']])) {
            $visited[(int) $cursor['id']] = true;
            array_unshift($breadcrumbs, $cursor);
            if ((int) $cursor['id'] === $rootId) {
                break;
            }
            $cursor = empty($cursor['parent_id']) ? null : ($folderById[(int) $cursor['parent_id']] ?? null);
        }

        $children = array_values(array_filter($tree, static fn (array $item): bool => (int) ($item['parent_id'] ?? 0) === $id));
        usort($children, static fn (array $left, array $right): int => strcasecmp((string) $left['name'], (string) $right['name']));
        $files = $db->table('files f')
            ->select('f.id, f.display_title, f.filename, f.filetype, f.mime_type, f.size, f.dateupload, f.last_action_at, tf.deleted_at, tf.reason')
            ->join('trashfiles tf', 'tf.file_id = f.id', 'inner')
            ->where('f.is_deleted', 1)
            ->where('f.folder_id', $id)
            ->where('tf.folder_root_id', $rootId)
            ->orderBy('f.display_title', 'ASC')
            ->get()
            ->getResultArray();
        $summary = $db->table('trashfiles tf')
            ->select('COUNT(*) AS file_count, COALESCE(SUM(f.size), 0) AS size', false)
            ->join('files f', 'f.id = tf.file_id AND f.is_deleted = 1', 'inner')
            ->where('tf.folder_root_id', $rootId)
            ->get()
            ->getRowArray() ?? ['file_count' => 0, 'size' => 0];
        $rootFolder = $this->trashService()->withRetention([$rootFolder])[0];

        return view('workspace/trash_folder', $this->pageData('trash', 'Trash - ' . (string) $folder['name'], [
            'folder' => $folder,
            'rootFolder' => $rootFolder,
            'breadcrumbs' => $breadcrumbs,
            'children' => $children,
            'files' => $files,
            'summary' => $summary,
            'canRestore' => $this->canRestoreTrash($actor),
            'canPermanentDelete' => $this->canPermanentlyDeleteTrash($actor),
            'success' => session()->getFlashdata('success'),
            'error' => session()->getFlashdata('error'),
        ]));
    }

    /** @param array<string, mixed> $actor */
    private function canRestoreTrash(array $actor): bool
    {
        return RolePolicy::allows($actor, 'files.manage');
    }

    /**
     * @param array<string, mixed>|null $file
     * @param array<string, mixed>|null $actor
     */
    private function streamMediaPreview(?array $file, ?array $actor = null)
    {
        if ($file === null) {
            return $this->response->setStatusCode(404)->setBody('Media preview not found.');
        }
        // Authorization is complete; NAS reads must not hold the browser session lock.
        session()->close();
        try {
            $path = $actor === null
                ? service('storage')->resolveDatabaseFile((string) $file['filepath'])
                : $this->resolveMediaPathForActor($file, $actor);
        } catch (RuntimeException) {
            return $this->response->setStatusCode(404)->setBody('Media preview not found.');
        }
        return $this->resolvedMediaPreview($path);
    }

    private function resolvedMediaPreview(string $path)
    {
        $mimeType = $this->previewMimeType($path);
        $allowed = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
            'video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo',
            'video/x-matroska', 'video/x-m4v',
        ];
        if (! is_string($mimeType) || ! in_array($mimeType, $allowed, true)) {
            return $this->response->setStatusCode(415)->setBody('Preview is unavailable for this file type.');
        }
        if ($this->request->getGet('thumbnail') === '1') {
            return $this->thumbnailResponse($path, true);
        }
        if (str_starts_with($mimeType, 'video/')) {
            try {
                $preview = (new \App\Services\VideoPreviewService())->forFile($path);
                if ($preview !== $path) {
                    $mimeType = 'video/mp4';
                }
                $path = $preview;
            } catch (RuntimeException $exception) {
                log_message('warning', 'Video preview unavailable: {message}', ['message' => $exception->getMessage()]);
                return $this->response->setStatusCode(503)->setBody('Video preview could not be prepared. Download the original or try again later.');
            }
        }
        return $this->inlineMediaResponse($path, $mimeType);
    }

    /** Inspect a bounded header, never an entire large remote media file. */
    private function previewMimeType(string $path): string|false
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return false;
        }
        try {
            $header = @stream_get_contents($stream, 65536);
            return $header === false || $header === '' ? false : (new \finfo(FILEINFO_MIME_TYPE))->buffer($header);
        } finally {
            fclose($stream);
        }
    }

    /** Called only after authentication, authorization and storage path validation. */
    private function thumbnailResponse(string $path, bool $reportUnavailable = false)
    {
        // Image processing must not block navigation requests from the same session.
        session()->close();
        try {
            $thumbnail = str_starts_with((string) $this->previewMimeType($path), 'video/')
                ? (new \App\Services\VideoPreviewService())->thumbnailForFile($path)
                : (new \App\Services\ThumbnailService())->forFile($path);
            $etag = '"' . pathinfo($thumbnail, PATHINFO_FILENAME) . '"';
            // Revalidate access on each request, while allowing reuse of cached bytes.
            $response = $this->response
                ->removeHeader('Cache-Control')
                ->removeHeader('Pragma')
                ->removeHeader('Expires')
                ->setHeader('Cache-Control', 'private, no-cache, must-revalidate')
                ->setHeader('Vary', 'Cookie')
                ->setHeader('ETag', $etag)
                ->setHeader('X-Content-Type-Options', 'nosniff');
            $validators = array_map('trim', explode(',', $this->request->getHeaderLine('If-None-Match')));
            if (in_array($etag, $validators, true) || in_array('W/' . $etag, $validators, true) || in_array('*', $validators, true)) {
                return $response->setStatusCode(304)->setBody('');
            }
            $body = file_get_contents($thumbnail);
            if ($body === false) {
                throw new RuntimeException('Thumbnail unavailable.');
            }
            return $response->setContentType('image/jpeg')->setHeader('Content-Length', (string) strlen($body))->setBody($body);
        } catch (RuntimeException $exception) {
            log_message('warning', 'Thumbnail unavailable: {message}', ['message' => $exception->getMessage()]);
            if ($reportUnavailable) {
                return $this->response->noCache()->setStatusCode(503)->setBody('Thumbnail unavailable.');
            }
            // Keep cards lightweight even when GD cannot process a particular image.
            return $this->response->noCache()->setContentType('image/svg+xml')->setBody(
                '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="260" viewBox="0 0 400 260"><rect width="400" height="260" fill="#f0f4f9"/><path d="M160 90h80v70h-80z M160 150l25-25 20 20 15-15 20 25" fill="none" stroke="#73809a" stroke-width="5"/><text x="200" y="195" text-anchor="middle" font-family="sans-serif" font-size="14" fill="#5f6b7d">Preview unavailable</text></svg>'
            );
        }
    }

    /**
     * Return a browser-native image or video response for media cards.
     * Video ranges let the browser fetch metadata and a poster frame without
     * downloading the whole file before rendering a thumbnail.
     */
    private function inlineMediaResponse(string $path, string $mimeType)
    {
        session()->close();
        $size = filesize($path);
        if ($size === false || $size < 1) {
            return $this->response->setStatusCode(404)->setBody('Media preview not found.');
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;
        $headOnly = strtoupper($this->request->getMethod()) === 'HEAD';
        $range = $headOnly ? '' : trim($this->request->getHeaderLine('Range'));
        // Ignore malformed or multipart ranges and send the full representation.
        if (preg_match('/^bytes=(\d*)-(\d*)$/iD', $range, $matches) === 1 && ($matches[1] !== '' || $matches[2] !== '')) {
            if ($matches[1] === '' && $matches[2] !== '') {
                $suffixLength = min((int) $matches[2], $size);
                $start = $size - $suffixLength;
            } elseif ($matches[1] !== '') {
                $start = (int) $matches[1];
                $end = $matches[2] === '' ? $end : min((int) $matches[2], $end);
            }

            if ($start > $end || $start >= $size) {
                return $this->response
                    ->setStatusCode(416)
                    ->setHeader('Content-Range', 'bytes */' . $size)
                    ->setBody('Requested media range is unavailable.');
            }
            $status = 206;
        }

        $length = $end - $start + 1;
        try {
            $response = new MediaStreamResponse($path, $mimeType, $start, $length, $status === 206, $headOnly);
        } catch (RuntimeException $exception) {
            return $this->response->setStatusCode(404)->setBody('Media preview not found.');
        }

        return $status === 206
            ? $response->setHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size)
            : $response;
    }

    /** @param array<string, mixed> $actor */
    private function canPermanentlyDeleteTrash(array $actor): bool
    {
        return RolePolicy::allows($actor, 'files.delete_permanently');
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed> $actor
     */
    private function canAccessMedia(array $file, array $actor): bool
    {
        if (RolePolicy::role($actor) === 'department') {
            return isset($file['department']) && $file['department'] === (string)($actor['department'] ?? '') && (int)$file['is_deleted'] === 0;
        }
        return RolePolicy::allows($actor, 'files.view_all');
    }

    private function actorFileModel(): FileModel
    {
        $actor = session()->get();
        $model = new FileModel();
        if (($actor['role'] ?? '') === 'department') {
            $model->setTable('department_files')->where('department', (string) ($actor['department'] ?? ''));
        }
        return $model;
    }

    private function resolveMediaPathForActor(array $file, array $actor): string
    {
        if (! $this->canAccessMedia($file, $actor)) {
            throw new RuntimeException('This media is not available to your account.');
        }
        return service('storage')->resolveDatabaseFile((string)($file['filepath'] ?? ''));
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed> $actor
     */
    private function restoreTrashedMedia(array $file, array $actor, string $auditAction = 'RESTORE'): void
    {
        $id = (int) $file['id'];
        $this->trashService()->restoreMedia($file);

        service('audit')->record($actor, $this->request, 'delete', $auditAction, 'file', $id, 'Restored "' . $file['filename'] . '" from Trash.');
    }

    protected function serverTrashEditor(): \App\Services\ServerLibraryEditor
    {
        return new \App\Services\ServerLibraryEditor(new \App\Services\ServerLibraryService());
    }

    private function selectedServerTrashIds(): array
    {
        $ids = $this->request->getPost('server_ids');
        return is_array($ids) ? array_values(array_unique(array_filter($ids, static fn ($id) => is_string($id) && preg_match('/^(?:[a-f0-9]{32}|external-(?:[a-f0-9]{2}){1,255})$/D', $id)))) : [];
    }

    private function processServerTrashSelection(array $ids, bool $delete, array $actor): array
    {
        if ($ids === []) {
            return [0, []];
        }
        $done = 0; $errors = [];
        $editor = $this->serverTrashEditor();
        if (!$delete) {
            try {
                $records = $editor->state()['trash'];
                usort($ids, static fn ($a, $b) => substr_count($records[$a]['path'] ?? '', '/') <=> substr_count($records[$b]['path'] ?? '', '/'));
            } catch (RuntimeException) { /* Individual actions report the connection failure below. */ }
        }
        foreach ($ids as $id) {
            try {
                $auditLabel = $editor->trashAuditLabel($id);
                $delete ? $editor->permanentlyDelete($id) : $editor->restore($id, is_string($this->request->getPost('server_restore_folder')) ? trim($this->request->getPost('server_restore_folder')) : null);
                $done++;
            } catch (\Throwable $exception) {
                $errors[] = 'Server item: ' . ($exception->getMessage() ?: 'The action could not be completed.');
                continue;
            }
            try { service('audit')->record($actor, $this->request, 'delete', $delete ? 'SERVER_TRASH_DELETE' : 'SERVER_TRASH_RESTORE', 'server_library', null, ($delete ? 'Permanently deleted from server Trash: ' : 'Restored from server Trash: ') . $auditLabel); }
            catch (\Throwable $exception) { log_message('error', 'Server Trash audit failed: {message}', ['message'=>$exception->getMessage()]); }
        }
        return [$done, $errors];
    }

    protected function trashService(): TrashService
    {
        return new TrashService();
    }

    /** @return array{groups:int,files:int} */
    private function selectedFileIds(): array
    {
        return $this->selectedIds('file_ids');
    }

    /** @return list<int> */
    private function selectedFolderIds(): array
    {
        return $this->selectedIds('folder_ids');
    }

    /** @return list<int> */
    private function selectedIds(string $field): array
    {
        $input = $this->request->getPost($field);
        if (! is_array($input)) {
            return [];
        }

        $ids = [];
        foreach ($input as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id !== false && $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param array<string, bool> $usedNames */
    private function uniqueArchiveName(string $filename, array &$usedNames): string
    {
        $filename = trim(basename($filename));
        if ($filename === '') {
            $filename = 'media';
        }
        $base      = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $candidate = $filename;
        $counter   = 2;
        while (isset($usedNames[mb_strtolower($candidate)])) {
            $candidate = $base . ' (' . $counter++ . ')' . ($extension === '' ? '' : '.' . $extension);
        }
        $usedNames[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    private function mediaListingUrl(): string
    {
        $folderId = filter_var($this->request->getGet('folder'), FILTER_VALIDATE_INT);

        return $folderId !== false && $folderId > 0
            ? site_url('workspace/file-management?folder=' . $folderId)
            : site_url('workspace/file-management');
    }

    /** @return list<array{file: UploadedFile, relativePath: string}> */
    private function uploadsFromRequest(): array
    {
        $uploads = [];
        $single  = $this->request->getFile('file');
        if ($single instanceof UploadedFile) {
            $uploads[] = ['file' => $single, 'relativePath' => (string) $this->request->getPost('relative_path')];
        }

        foreach ($this->request->getFileMultiple('files') ?? [] as $uploaded) {
            if ($uploaded instanceof UploadedFile) {
                $uploads[] = ['file' => $uploaded, 'relativePath' => ''];
            }
        }
        foreach ($this->request->getFileMultiple('folder_files') ?? [] as $uploaded) {
            if ($uploaded instanceof UploadedFile) {
                $uploads[] = ['file' => $uploaded, 'relativePath' => (string) $uploaded->getClientPath()];
            }
        }

        return $uploads;
    }

    private function assertUploadReady(UploadedFile $file): void
    {
        if ($file->isValid() && ! $file->hasMoved() && $file->getTempName() !== '') {
            return;
        }

        $reason = trim($file->getErrorString());
        throw new RuntimeException($reason === ''
            ? 'The uploaded file was not received by the server.'
            : 'The upload was rejected: ' . $reason);
    }

    private function uploadDirectory(string $baseDirectory, string $relativePath): string
    {
        $storage = service('storage');
        $base    = $storage->normalizeRelativePath($baseDirectory);
        $folderPath = $this->uploadFolderPath($relativePath);
        if ($folderPath === '') {
            return $base;
        }

        return $storage->normalizeRelativePath(trim($base . '/' . $folderPath, '/'));
    }

    private function uploadFolderPath(string $relativePath): string
    {
        if (trim($relativePath) === '') {
            return '';
        }

        $clientPath = str_replace('\\', '/', trim($relativePath));
        if ($clientPath === '' || str_contains($clientPath, "\0") || str_starts_with($clientPath, '/')) {
            throw new RuntimeException('The folder path is invalid.');
        }

        // The final segment is a filename and is validated separately by
        // StorageService::storeUpload(). Only folder segments belong here.
        $folderPath = dirname($clientPath);
        if ($folderPath === '.') {
            return '';
        }

        return service('storage')->normalizeRelativePath($folderPath, false);
    }

    /** @param array<string, mixed>|null $baseFolder */
    private function ensureUploadFolderHierarchy(?array $baseFolder, string $relativePath): ?int
    {
        $folderPath = $this->uploadFolderPath($relativePath);
        $parentId = $baseFolder === null ? null : (int) $baseFolder['id'];
        if ($folderPath === '') {
            return $parentId;
        }

        $parentPath = $baseFolder === null ? '' : $this->folderStoragePath($baseFolder);
        foreach (explode('/', $folderPath) as $name) {
            $path = service('storage')->normalizeRelativePath(trim($parentPath . '/' . $name, '/'), false);
            $folderQuery = (new FolderModel())
                ->where('is_deleted', 0)
                ->where('name', $name);
            $parentId === null
                ? $folderQuery->where('parent_id IS NULL', null, false)
                : $folderQuery->where('parent_id', $parentId);
            $existing = $folderQuery->first();

            if ($existing === null) {
                $createdId = (new FolderModel())->insert([
                    'parent_id'   => $parentId,
                    'name'        => $name,
                    'description' => '',
                    'path'        => $path,
                    'created_at'  => gmdate('Y-m-d H:i:s'),
                    'is_deleted'  => 0,
                ], true);
                if ($createdId === false) {
                    throw new RuntimeException('The uploaded folder structure could not be saved.');
                }
                $parentId = (int) $createdId;
            } else {
                $parentId = (int) $existing['id'];
            }
            $parentPath = $path;
        }

        return $parentId;
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

    /** @param array<string, mixed> $file */
    private function previewName(array $file): string
    {
        $title = preg_replace('/[^\pL\pN _.-]+/u', '', MediaTitle::forFile($file)) ?: 'media';
        $extension = trim((string) ($file['filetype'] ?? ''));
        return $title . ($extension === '' ? '' : '.' . $extension);
    }

    /** @param array<string, mixed> $folder */
    private function folderStoragePath(array $folder): string
    {
        $path = trim((string) ($folder['path'] ?? ''));
        if ($path === '') {
            $path = (string) ($folder['name'] ?? '');
        }

        if ((new \Config\Storage())->centralized() && str_starts_with($path, 'server-library/')) {
            $path = substr($path, 15);
        }
        return service('storage')->normalizeRelativePath($path, false);
    }

    /** @param array<int, array<string, mixed>> $folderById */
    private function folderDisplayPath(array $folder, array $folderById): string
    {
        $parts = [(string) ($folder['name'] ?? '')];
        $parentId = ! empty($folder['parent_id']) ? (int) $folder['parent_id'] : null;
        $visited = [(int) ($folder['id'] ?? 0) => true];
        while ($parentId !== null && isset($folderById[$parentId]) && ! isset($visited[$parentId])) {
            $visited[$parentId] = true;
            $parent = $folderById[$parentId];
            array_unshift($parts, (string) ($parent['name'] ?? ''));
            $parentId = ! empty($parent['parent_id']) ? (int) $parent['parent_id'] : null;
        }
        return implode(' / ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param list<int> $folderIds
     * @param array<int, array<string, mixed>> $folderById
     * @return list<int>
     */
    private function folderIdsWithAncestors(array $folderIds, array $folderById): array
    {
        $accessible = [];
        foreach ($folderIds as $folderId) {
            $currentId = (int) $folderId;
            $visited = [];
            while ($currentId > 0 && isset($folderById[$currentId]) && ! isset($visited[$currentId])) {
                $visited[$currentId] = true;
                $accessible[$currentId] = true;
                $currentId = empty($folderById[$currentId]['parent_id'])
                    ? 0
                    : (int) $folderById[$currentId]['parent_id'];
            }
        }

        return array_map('intval', array_keys($accessible));
    }

    /**
     * @param list<array<string,mixed>> $folders
     * @param array<int,array<string,mixed>> $folderById
     * @return list<int>
     */
    private function matchingFolderIds(array $folders, array $folderById, string $query): array
    {
        $ids = [];
        foreach ($folders as $folder) {
            $searchablePath = implode(' ', [
                (string) ($folder['name'] ?? ''),
                (string) ($folder['path'] ?? ''),
                $this->folderDisplayPath($folder, $folderById),
            ]);
            if (mb_stripos($searchablePath, $query) !== false) {
                $ids[] = (int) $folder['id'];
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /** @param list<int> $matchingFolderIds */
    private function applyFileManagementSearch(BaseBuilder $files, string $query, array $matchingFolderIds, bool $searchPassports): BaseBuilder
    {
        if ($searchPassports) {
            $files->join('passports p', 'p.media_id = f.id', 'left');
        }

        $files->groupStart()
            ->like('f.filename', $query)
            ->orLike('f.display_title', $query)
            ->orLike('f.tags', $query)
            ->orLike('f.classification', $query)
            ->orLike('f.detected_objects', $query);
        if ($searchPassports) {
            $files->orLike('p.date_taken', $query)
                // Passports retain the event recorded when they were generated.
                // Use that legacy value only if the file has no current classification.
                ->orGroupStart()
                    ->groupStart()->where('f.classification', null)->orWhere('f.classification', '')->groupEnd()
                    ->like('p.event_name', $query)
                ->groupEnd();
        }
        if ($matchingFolderIds !== []) {
            $files->orWhereIn('f.folder_id', $matchingFolderIds);
        }
        $files->groupEnd();

        return $files;
    }

    /** @return list<int> */
    private function descendantFolderIds(int $rootId): array
    {
        $rows = (new FolderModel())->where('is_deleted', 0)->select('id, parent_id')->findAll();
        $ids = [$rootId];
        for ($index = 0; $index < count($ids); ++$index) {
            $parentId = $ids[$index];
            foreach ($rows as $row) {
                if ((int) ($row['parent_id'] ?? 0) === $parentId && ! in_array((int) $row['id'], $ids, true)) {
                    $ids[] = (int) $row['id'];
                }
            }
        }
        return $ids;
    }

    /**
     * @param list<string> $successful
     * @param list<string> $errors
     */
    private function uploadResult(array $successful, array $errors)
    {
        $uploadedCount = count($successful);
        $failedCount   = count($errors);
        $message       = $uploadedCount === 1 ? '1 file uploaded successfully.' : $uploadedCount . ' files uploaded successfully.';
        if ($uploadedCount === 0) {
            $message = 'No files were uploaded.';
        }
        if ($failedCount > 0) {
            $message .= ' ' . $failedCount . ' file' . ($failedCount === 1 ? '' : 's') . ' skipped.';
        }

        if ($this->request->isAJAX()) {
            return $this->response
                ->setStatusCode($uploadedCount > 0 ? 200 : 422)
                ->setJSON([
                    'ok'        => $uploadedCount > 0,
                    'message'   => $message,
                    'uploaded'  => $uploadedCount,
                    'failed'    => $failedCount,
                    'errors'    => array_slice($errors, 0, 3),
                    'csrfToken' => csrf_hash(),
                ]);
        }

        $details = $failedCount > 0 ? ' ' . implode(' ', array_slice($errors, 0, 3)) : '';
        $key     = $uploadedCount > 0 ? 'success' : 'error';

        return redirect()->to(site_url('workspace/file-management'))->with($key, trim($message . $details));
    }

    /** @return \CodeIgniter\HTTP\RedirectResponse|null */
    private function requireLogin(): ?\CodeIgniter\HTTP\RedirectResponse
    {
        return session()->get('isLoggedIn') === true ? null : redirect()->to(site_url('login'));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function pageData(string $activePage, string $title, array $data): array
    {
        helper('dashboard');

        return array_merge($data, ['user' => session()->get(), 'activePage' => $activePage, 'title' => $title]);
    }

    /** @param array<string, mixed> $file */
    private function recordRecent(array $file): void
    {
        $actor = session()->get();
        if (empty($actor['employee_id'])) {
            return;
        }

        (new RecentModel())->insert([
            'employee_id' => (int) $actor['employee_id'],
            'item_type'   => 'file',
            'item_name'   => MediaTitle::forFile($file),
            'filepath'    => (string) $file['filepath'],
            'timestamp'   => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $actor
     * @return list<string>
     */
    private function runPostUploadAutomation(int $fileId, array $actor): array
    {
        $file = (new FileModel())->find($fileId);
        if ($file === null) {
            return ['The uploaded file is available, but its automatic processing could not start.'];
        }

        $messages = [];
        try {
            $detection = service('eventDetection')->classifyAndStore($file);
            service('audit')->record($actor, $this->request, 'metadata', 'AI_CLASSIFIED', 'file', $fileId, 'Event classifier processed "' . $file['filename'] . '" as ' . ($detection['event_name'] ?? 'unclassified media') . '.');
            $passport = service('passports')->generate($file, $actor, $detection);
            if ($passport !== null) {
                $messages[] = 'Metadata passport generated.';
                service('audit')->record($actor, $this->request, 'metadata', 'PASSPORT_GENERATED', 'passport', (int) $passport['id'], 'Generated passport ' . $passport['passport_uid'] . ' for "' . $file['filename'] . '".');
            }
        } catch (\Throwable) {
            $messages[] = 'Metadata processing completed with fallback values.';
        }

        return $messages;
    }

    public function postMedia()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        $actor = session()->get();
        if (! \App\Libraries\RolePolicy::allows($actor, 'files.manage')) {
            return redirect()->back()->with('error', 'You do not have permission to post media.');
        }

        $postType = $this->request->getPost('post_type');
        $displayTitle = trim((string) $this->request->getPost('display_title'));
        $description = trim((string) $this->request->getPost('description'));
        $publishedAt = gmdate('Y-m-d H:i:s');
        $employeeId = (int) $actor['employee_id'];

        if ($displayTitle === '') {
            return redirect()->back()->with('error', 'Display title is required.');
        }

        $fileModel = new \App\Models\FileModel();
        $folder = null;
        if ($postType === 'folder') {
            $folderId = (int)$this->request->getPost('folder_id');
            $folder = (new \App\Models\FolderModel())->where('is_deleted', 0)->find($folderId);
            if (! $folder) {
                return redirect()->back()->with('error', 'Folder not found.');
            }
            $folderIds = [$folderId];
            $folders = (new \App\Models\FolderModel())->where('is_deleted', 0)->findAll();
            do {
                $before = count($folderIds);
                foreach ($folders as $child) {
                    if (in_array((int) $child['parent_id'], $folderIds, true) && ! in_array((int) $child['id'], $folderIds, true)) {
                        $folderIds[] = (int) $child['id'];
                    }
                }
            } while (count($folderIds) > $before);
            $fileIds = array_column($fileModel->whereIn('folder_id', $folderIds)->where('is_deleted', 0)->whereIn('media_kind', ['image', 'video'])->findAll(), 'id');
        } elseif ($postType === 'files') {
            $fileIds = $this->request->getPost('file_ids') ?? [];
            if (! is_array($fileIds)) {
                return redirect()->back()->with('error', 'Choose files to post.');
            }
        } else {
            return redirect()->back()->with('error', 'Invalid post type.');
        }
        if (! $fileIds) {
            return redirect()->back()->with('error', 'Select at least one photo or video.');
        }
        if (mb_strlen($displayTitle) > 150 || mb_strlen($description) > 5000) {
            return redirect()->back()->with('error', 'Title may contain at most 150 characters and description 5,000.');
        }
        $db = db_connect();
        $db->transStart();
        try {
            (new \App\Services\AppPublishingService())->receive($fileIds, $displayTitle, $description);
            $db->table('files')->whereIn('id', array_map('intval', $fileIds))->update(['published_by' => $employeeId]);
            if ($folder) (new \App\Models\FolderModel())->update($folderId, [
                'published_at' => $publishedAt, 'published_by' => $employeeId,
                'description' => $description, 'name' => $displayTitle,
            ]);
            service('audit')->record($actor, $this->request, 'publish', 'APP_POST_CREATED', $folder ? 'folder' : 'file', $folder ? $folderId : (int)$fileIds[0], 'Posted "' . $displayTitle . '" for GreyhoundHub admins to edit and publish.');
            $db->transComplete();
        } catch (\Throwable $error) {
            $db->transRollback();
            log_message('error', 'App post failed: {message}', ['message' => $error->getMessage()]);
            return redirect()->back()->with('error', 'The media could not be posted. Please try again.');
        }
        if (! $db->transStatus()) {
            return redirect()->back()->with('error', 'Failed to post media.');
        }
        return redirect()->to(site_url('workspace/published-files'))->with('message', 'Posted to GreyhoundHub. App admins can now edit and publish it for regular users.');
    }

    public function publishedFiles($folderId = null)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        $actor = session()->get();
        if (! \App\Libraries\RolePolicy::allows($actor, 'files.manage')) {
            return redirect()->to(site_url('dashboard'))->with('error', 'Access denied.');
        }

        $folderModel = new \App\Models\FolderModel();
        $fileModel = new \App\Models\FileModel();
        $userModel = new \App\Models\UserModel();
        $perPage = 24;
        $page = max(1, (int) $this->request->getGet('page'));

        $users = [];
        foreach ($userModel->findAll() as $u) {
            $users[$u['employee_id']] = $u;
        }

        $parentFolder = null;
        if ($folderId) {
            $parentFolder = $folderModel->where('is_deleted', 0)->find((int)$folderId);
            if (!$parentFolder) {
                return redirect()->to(site_url('workspace/published-files'))->with('error', 'Folder not found.');
            }
            $folders = [];
            if (empty($parentFolder['published_at'])) {
                return redirect()->to(site_url('workspace/published-files'))->with('error', 'This folder has not been published yet.');
            }
            $filesQuery = $fileModel
                ->where('folder_id', (int) $folderId)
                ->where('is_deleted', 0);
            $total = $filesQuery->countAllResults(false);
            $page = min($page, max(1, (int) ceil($total / $perPage)));
            $files = $filesQuery
                ->orderBy('dateupload', 'DESC')
                ->orderBy('id', 'DESC')
                ->findAll($perPage, ($page - 1) * $perPage);

            // Inherit publisher info from folder for the files
            foreach ($files as &$f) {
                $f['published_by'] = $parentFolder['published_by'] ?? $f['employee_id'];
                $f['published_at'] = $parentFolder['published_at'] ?? $f['published_at'] ?? $f['dateupload'];
            }
        } else {
            // Keep every published folder ID so files in other pages' folders
            // are still excluded from the standalone file cards.
            $publishedFolderIds = array_map('intval', array_column($folderModel
                ->select('id')
                ->where('is_deleted', 0)
                ->where('published_at IS NOT NULL')
                ->findAll(), 'id'));
            $folderCount = count($publishedFolderIds);
            $fileCount = $this->publishedStandaloneQuery($fileModel, $publishedFolderIds)->countAllResults();
            $total = $folderCount + $fileCount;
            $page = min($page, max(1, (int) ceil($total / $perPage)));
            $offset = ($page - 1) * $perPage;

            $folders = [];
            if ($offset < $folderCount) {
                $folders = $folderModel
                    ->where('is_deleted', 0)
                    ->where('published_at IS NOT NULL')
                    ->orderBy('published_at', 'DESC')
                    ->orderBy('id', 'DESC')
                    ->findAll(min($perPage, $folderCount - $offset), $offset);
            }
            $fileLimit = $perPage - count($folders);
            $files = $fileLimit > 0
                ? $this->publishedStandaloneQuery($fileModel, $publishedFolderIds)
                    ->orderBy('published_at', 'DESC')
                    ->orderBy('dateupload', 'DESC')
                    ->orderBy('id', 'DESC')
                    ->findAll($fileLimit, max(0, $offset - $folderCount))
                : [];
        }

        foreach ($folders as &$f) {
            $publisher = $users[$f['published_by']] ?? null;
            $f['publisher_name'] = $publisher ? $publisher['name'] : 'Unknown';
            $f['file_count'] = (int) $fileModel
                ->where('folder_id', (int) $f['id'])
                ->where('is_deleted', 0)
                ->countAllResults();
        }
        foreach ($files as &$f) {
            $publisherId = $f['published_by'] ?? $f['employee_id'];
            $publisher = $users[$publisherId] ?? null;
            $f['publisher_name'] = $publisher ? $publisher['name'] : 'Unknown';
        }

        unset($f);
        return view('workspace/published_files', [
            'activePage' => 'published-files',
            'folders' => $folders,
            'files' => $files,
            'pagination' => $this->paginationData($page, $total, $perPage),
            'parentFolder' => $parentFolder,
            'user' => $actor,
            'role' => $actor['role'],
            'canManage' => true,
        ]);
    }

    /** @param list<int> $publishedFolderIds */
    private function publishedStandaloneQuery(FileModel $fileModel, array $publishedFolderIds): FileModel
    {
        $filesQuery = $fileModel
            ->where('is_deleted', 0)
            ->where('release_status', 'published');

        // Files represented by a published folder card are hidden from the
        // standalone grid. With no folder cards, every published file must be
        // shown, including files stored inside ordinary folders.
        if ($publishedFolderIds !== []) {
            $filesQuery
                ->groupStart()
                ->where('folder_id IS NULL', null, false)
                ->orWhereNotIn('folder_id', $publishedFolderIds)
                ->groupEnd();
        }

        return $filesQuery;
    }
}
