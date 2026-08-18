<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
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

    public function media()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $actor    = session()->get();
        $db       = db_connect();
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
        $hasFolderAssignments = $db->tableExists('media_assignment_folders');
        $departmentFolderIds = [];
        if ($departmentUser && $hasFolderAssignments) {
            $departmentFolderIds = array_values(array_unique(array_map('intval', array_column(
                $db->table('media_assignment_folders')
                    ->select('folder_id')
                    ->where('department', (string) ($actor['department'] ?? ''))
                    ->get()
                    ->getResultArray(),
                'folder_id'
            ))));
        }
        $allActiveFolders = (new FolderModel())->where('is_deleted', 0)->orderBy('name', 'ASC')->findAll();
        $allActiveFolderById = [];
        foreach ($allActiveFolders as $folder) {
            $allActiveFolderById[(int) $folder['id']] = $folder;
        }
        $departmentAccessibleFolderIds = $departmentFolderIds;
        if ($departmentUser) {
            $assignedFileFolders = $db->table('files f')
                ->select('f.folder_id')
                ->join('media_assignments ma', 'ma.media_id = f.id', 'inner')
                ->where('ma.department', (string) ($actor['department'] ?? ''))
                ->where('f.is_deleted', 0)
                ->where('f.folder_id IS NOT NULL', null, false)
                ->groupBy('f.folder_id')
                ->get()
                ->getResultArray();
            $departmentAccessibleFolderIds = $this->folderIdsWithAncestors(
                array_merge($departmentAccessibleFolderIds, array_map('intval', array_column($assignedFileFolders, 'folder_id'))),
                $allActiveFolderById
            );
        }
        $folderId = filter_var($this->request->getGet('folder'), FILTER_VALIDATE_INT);
        $folderId = $folderId !== false && $folderId > 0 ? $folderId : null;
        if ($query !== '') {
            $folderId = null;
        }
        $selectedFolder = $folderId === null ? null : ($allActiveFolderById[$folderId] ?? null);
        if ($folderId !== null && ($selectedFolder === null || ($departmentUser && ! in_array($folderId, $departmentAccessibleFolderIds, true)))) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'The requested folder is unavailable.');
        }

        $folderQuery = $db->table('folder fo')
            ->select('fo.id, fo.name, fo.description, fo.path, fo.created_at, COUNT(DISTINCT f.id) AS file_count, (SELECT COUNT(*) FROM folder child WHERE child.parent_id = fo.id AND child.is_deleted = 0) AS subfolder_count', false)
            ->join('files f', 'f.folder_id = fo.id AND f.is_deleted = 0', 'left')
            ->where('fo.is_deleted', 0);
        if ($query !== '') {
            $folderQuery->groupStart()->like('fo.name', $query)->orLike('fo.path', $query)->groupEnd();
        } elseif ($folderId === null) {
            $folderQuery->where('fo.parent_id IS NULL', null, false);
        } else {
            $folderQuery->where('fo.parent_id', $folderId);
        }
        if ($departmentUser) {
            $receivedFolders = [];
            if ($departmentAccessibleFolderIds !== []) {
                $receivedFolders = $db->table('folder fo')
                    ->select('fo.id, fo.parent_id, fo.name, fo.description, fo.path, fo.created_at, COUNT(DISTINCT ma.media_id) AS file_count', false)
                    ->join('files f', 'f.folder_id = fo.id AND f.is_deleted = 0', 'left')
                    ->join('media_assignments ma', 'ma.media_id = f.id AND ma.department = ' . $db->escape((string) ($actor['department'] ?? '')), 'left')
                    ->where('fo.is_deleted', 0)
                    ->whereIn('fo.id', $departmentAccessibleFolderIds)
                    ->groupBy('fo.id, fo.parent_id, fo.name, fo.description, fo.path, fo.created_at')
                    ->get()
                    ->getResultArray();
            }
            $receivedFolderIds = array_values(array_unique(array_map('intval', array_column($receivedFolders, 'id'))));
            $receivedFolderSet = array_fill_keys($receivedFolderIds, true);
            foreach ($receivedFolders as &$receivedFolder) {
                $receivedFolder['display_path'] = $this->folderDisplayPath($receivedFolder, $allActiveFolderById);
                $receivedFolder['subfolder_count'] = count(array_filter(
                    $receivedFolders,
                    static fn (array $candidate): bool => (int) ($candidate['parent_id'] ?? 0) === (int) $receivedFolder['id']
                ));
            }
            unset($receivedFolder);
            $folders = array_values(array_filter($receivedFolders, static function (array $folder) use ($folderId, $query, $receivedFolderSet): bool {
                if ($query !== '') {
                    return stripos((string) $folder['name'], $query) !== false
                        || stripos((string) ($folder['path'] ?? ''), $query) !== false
                        || stripos((string) ($folder['display_path'] ?? ''), $query) !== false;
                }
                if ($folderId !== null) {
                    return (int) ($folder['parent_id'] ?? 0) === $folderId;
                }
                $parentId = empty($folder['parent_id']) ? 0 : (int) $folder['parent_id'];
                return $parentId === 0 || ! isset($receivedFolderSet[$parentId]);
            }));
            $allFolders = $receivedFolders;
        } else {
            $folders = $folderQuery
                ->groupBy('fo.id, fo.name, fo.description, fo.path, fo.created_at')
                ->orderBy('fo.name', 'ASC')
                ->get()
                ->getResultArray();
            $allFolders = $allActiveFolders;
        }
        $folderById = $allActiveFolderById;
        $folderOptions = [];
        foreach ($allFolders as $folder) {
            $folder['display_name'] = $this->folderDisplayPath($folder, $folderById);
            $folderOptions[] = $folder;
        }
        usort($folderOptions, static fn (array $left, array $right): int => strcasecmp((string) $left['display_name'], (string) $right['display_name']));
        $parentFolder = $selectedFolder === null || empty($selectedFolder['parent_id']) ? null : ($folderById[(int) $selectedFolder['parent_id']] ?? null);
        $selectedFolderPath = $selectedFolder === null ? 'All media' : $this->folderDisplayPath($selectedFolder, $folderById);
        $matchingFolderIds = $query === '' ? [] : $this->matchingFolderIds($allActiveFolders, $folderById, $query);

        $files = $db->table('files f')->select('f.*')->where('f.is_deleted', 0);
        if ($departmentUser) {
            $files->join('media_assignments ma', 'ma.media_id = f.id', 'inner')
                ->where('ma.department', (string) ($actor['department'] ?? ''))
                ->groupBy('f.id');
        }
        if ($query !== '') {
            $files = $this->applyFileManagementSearch($files, $query, $matchingFolderIds, $db->tableExists('passports'));
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

        $fileTypes = $db->table('files')->select('filetype')->where('is_deleted', 0)->where('filetype IS NOT NULL', null, false);
        if ($query === '') {
            if ($folderId === null) {
                $fileTypes->where('folder_id IS NULL', null, false);
            } else {
                $fileTypes->where('folder_id', $folderId);
            }
        }

        $files->orderBy('f.dateupload', 'DESC');
        if ($query === '') {
            $files->limit(100);
        }
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
        $duplicateGroups = RolePolicy::allows($actor, 'duplicates.view') ? $this->duplicateGroups() : [];
        $duplicateFileCount = array_sum(array_map(static fn (array $group): int => count($group['duplicates']), $duplicateGroups));
        $watermarkSettings = $db->tableExists('watermark_settings')
            ? ($db->table('watermark_settings')->orderBy('id', 'ASC')->get(1)->getRowArray() ?? [])
            : [];
        return view('workspace/media', $this->pageData('file-management', 'File Management', [
            'files'          => $listedFiles,
            'fileTypes'      => $fileTypes->groupBy('filetype')->orderBy('filetype')->get()->getResultArray(),
            'folders'        => $folders,
            'folderOptions'  => $folderOptions,
            'selectedFolder' => $selectedFolder,
            'parentFolder'   => $parentFolder,
            'selectedFolderPath' => $selectedFolderPath,
            'folderId'       => $folderId,
            'query'          => $query,
            'type'           => $type,
            'stage'          => $stage,
            'distribution'   => $distribution,
            'canManage'      => RolePolicy::allows($actor, 'files.manage'),
            'canUpload'      => RolePolicy::allows($actor, 'files.upload'),
            'canToggleWatermark' => RolePolicy::isCreativeAdmin($actor) && $db->tableExists('watermark_settings'),
            'watermarkEnabled' => ! empty($watermarkSettings['enabled']),
            'isDepartment'   => $departmentUser,
            'duplicateCount' => count($duplicateGroups),
            'duplicateFileCount' => $duplicateFileCount,
            'duplicateGroups' => $duplicateGroups,
            'departments'    => $db->table('user')->select('department')->where('department !=', '')->groupBy('department')->orderBy('department')->get()->getResultArray(),
            'openRequests'   => $db->tableExists('media_requests') ? $db->table('media_requests')->where('status', 'submitted')->orderBy('submitted_at', 'DESC')->get()->getResultArray() : [],
            'success'        => session()->getFlashdata('success'),
            'error'          => session()->getFlashdata('error'),
        ]));
    }

    public function upload()
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

        $uploads = $this->uploadsFromRequest();
        if ($uploads === []) {
            return $this->uploadResult([], ['Choose one or more files, or select a folder to upload.']);
        }
        $duplicateScope = trim((string) $this->request->getPost('duplicate_mode'));
        $duplicateScope = $duplicateScope === '' ? 'media_library' : $duplicateScope;
        if (! in_array($duplicateScope, ['upload_batch', 'media_library'], true)) {
            return $this->uploadResult([], ['Choose a valid duplicate scan mode.']);
        }
        $uploadBatchId = trim((string) $this->request->getPost('upload_batch_id'));
        if ($uploadBatchId === '') {
            $uploadBatchId = bin2hex(random_bytes(16));
        }
        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $uploadBatchId) !== 1) {
            return $this->uploadResult([], ['The upload batch identifier is invalid.']);
        }

        $successful = [];
        $errors     = [];
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
                $targetDirectory = $this->uploadDirectory($directory, $uploadItem['relativePath']);
                $fileFolderId = $this->ensureUploadFolderHierarchy($folder, $uploadItem['relativePath']);
                $contentHash = hash_file('sha256', $uploaded->getTempName());
                $imageFingerprint = service('imageSimilarity')->fingerprint($uploaded->getTempName(), (string) $uploaded->getMimeType());
                $similarityScore = 100.0;
                $matchType = 'exact';
                $original = $contentHash === false ? null : $this->duplicateCandidates($uploadBatchId, $duplicateScope)->groupStart()->where('content_hash', $contentHash)->orWhere('filehash', $contentHash)->groupEnd()->first();
                if ($original === null && $contentHash !== false) {
                    foreach ($this->duplicateCandidates($uploadBatchId, $duplicateScope)->where('size', (int) $uploaded->getSize())->findAll(50) as $candidate) {
                        try {
                            $candidateHash = hash_file('sha256', service('storage')->resolveDatabaseFile((string) $candidate['filepath']));
                        } catch (RuntimeException) {
                            continue;
                        }
                        if (is_string($candidateHash)) {
                            (new FileModel())->update((int) $candidate['id'], ['content_hash' => $candidateHash]);
                        }
                        if (hash_equals($contentHash, (string) $candidateHash)) {
                            $original = $candidate;
                            break;
                        }
                    }
                }
                if ($original === null && $imageFingerprint !== null) {
                    $visualMatch = service('imageSimilarity')->findBestMatch($imageFingerprint, 1000, $uploadBatchId, $duplicateScope);
                    if ($visualMatch !== null) {
                        $original = $visualMatch['file'];
                        $similarityScore = (float) $visualMatch['similarity'];
                        $matchType = 'visual';
                    }
                }
                if ($original !== null) {
                    $stored = service('storage')->storeUpload($uploaded, 'Duplicate Queue');
                    $db = db_connect();
                    $db->transStart();
                    $transactionOpen = true;
                    $db->table('duplicate_media')->insert(['original_file_id' => (int) $original['id'], 'filename' => $stored['filename'], 'display_title' => $displayTitle, 'filepath' => $stored['filepath'], 'mime_type' => $stored['mimeType'], 'size' => $stored['size'], 'content_hash' => $contentHash, 'image_fingerprint' => $imageFingerprint, 'match_type' => $matchType, 'similarity_score' => $similarityScore, 'upload_batch_id' => $uploadBatchId, 'detection_scope' => $duplicateScope, 'uploaded_by' => (int) $actor['employee_id'], 'status' => 'pending', 'created_at' => gmdate('Y-m-d H:i:s')]);
                    $db->table('files')->where('id', (int) $original['id'])->set('duplicate_count', 'duplicate_count + 1', false)->update();
                    $db->transComplete();
                    $transactionOpen = false;
                    if (! $db->transStatus()) {
                        @unlink($stored['filepath']);
                        throw new RuntimeException('The duplicate could not be saved to Duplicate Queue.');
                    }
                    service('audit')->record($actor, $this->request, 'upload', 'DUPLICATE_DETECTED', 'file', (int) $original['id'], 'Saved ' . ($matchType === 'exact' ? 'exact duplicate' : number_format($similarityScore, 2) . '% visually similar') . ' upload "' . $stored['filename'] . '" to Duplicate Queue; the original was not changed.');
                    $successful[] = $displayTitle . ' (duplicate queued)';
                    continue;
                }
                // `storage_stage=raw` is the authoritative Raw Files location;
                // retain legacy folder paths so existing rename/restore behavior stays compatible.
                $stored          = service('storage')->storeUpload($uploaded, $targetDirectory);
                $db              = db_connect();
                $db->transStart();
                $transactionOpen = true;
                $fileId = (new FileModel())->insert([
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

    public function updateAutomaticWatermark()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }

        $actor = session()->get();
        if (! RolePolicy::isCreativeAdmin($actor)) {
            return $this->automaticWatermarkResult(false, 'Only Creative Administrators can change automatic watermarking.', 403);
        }

        $db = db_connect();
        if (! $db->tableExists('watermark_settings')) {
            return $this->automaticWatermarkResult(false, 'Watermark settings are not installed yet.', 404);
        }

        $enabled = $this->request->getPost('enabled') === '1' ? 1 : 0;
        $existing = $db->table('watermark_settings')->orderBy('id', 'ASC')->get(1)->getRowArray();
        if ($existing === null) {
            $db->table('watermark_settings')->insert([
                'id' => 1,
                'enabled' => $enabled,
                'logo_path' => 'FU',
                'position' => 'bottom_right',
                'opacity' => 65,
                'resize_percent' => 100,
                'updated_by' => (string) ($actor['employee_id'] ?? ''),
            ]);
            $settingsId = (int) $db->insertID();
        } else {
            $settingsId = (int) $existing['id'];
            $db->table('watermark_settings')->where('id', $settingsId)->update([
                'enabled' => $enabled,
                'updated_by' => (string) ($actor['employee_id'] ?? ''),
            ]);
        }

        $state = $enabled === 1 ? 'enabled' : 'disabled';
        service('audit')->record($actor, $this->request, 'watermark', 'WATERMARK_AUTO_TOGGLE', 'settings', $settingsId, 'Automatic watermarking ' . $state . ' from File Management.');

        return $this->automaticWatermarkResult(true, 'Automatic watermarking is now ' . $state . '.', 200, $enabled === 1);
    }

    private function automaticWatermarkResult(bool $ok, string $message, int $status, ?bool $enabled = null)
    {
        if ($this->request->isAJAX()) {
            return $this->response->setStatusCode($status)->setJSON([
                'ok' => $ok,
                'message' => $message,
                'enabled' => $enabled,
                'csrfToken' => csrf_hash(),
            ]);
        }

        return redirect()->to(site_url('workspace/file-management'))->with($ok ? 'success' : 'error', $message);
    }

    private function duplicateCandidates(string $uploadBatchId, string $duplicateScope): FileModel
    {
        $files = (new FileModel())->where('is_deleted', 0);
        if ($duplicateScope === 'upload_batch') {
            return $files->where('upload_batch_id', $uploadBatchId);
        }

        return $files->groupStart()
            ->where('upload_batch_id !=', $uploadBatchId)
            ->orWhere('upload_batch_id IS NULL', null, false)
            ->groupEnd();
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

        $files = db_connect()->table('files f')
            ->select('f.*, MAX(ma.sent_at) AS assigned_at')
            ->join('media_assignments ma', 'ma.media_id = f.id', 'inner')
            ->where('ma.department', (string) ($actor['department'] ?? ''))
            ->where('f.is_deleted', 0)
            ->where('f.downloadable', 1)
            ->groupBy('f.id')
            ->orderBy('assigned_at', 'DESC')
            ->limit(100)
            ->get()
            ->getResultArray();

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

    public function duplicateQueue()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'duplicates.view')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Duplicate Queue is restricted to Creative Staff.');
        }
        $db = db_connect();
        $duplicates = $db->table('duplicate_media d')->select('d.*, f.display_title AS original_display_title, u.name AS uploader_name')->join('files f', 'f.id = d.original_file_id', 'left')->join('user u', 'u.employee_id = d.uploaded_by', 'left')->orderBy('d.created_at', 'DESC')->limit(200)->get()->getResultArray();
        return view('workspace/duplicates', $this->pageData('duplicates', 'Duplicate Queue', ['duplicates' => $duplicates, 'success' => session()->getFlashdata('success'), 'error' => session()->getFlashdata('error')]));
    }

    public function duplicatePreview(int $id)
    {
        if (($redirect = $this->requireLogin()) !== null) return $redirect;
        if (! RolePolicy::allows(session()->get(), 'duplicates.view')) {
            return $this->response->setStatusCode(403)->setBody('Duplicate previews are restricted to Creative Staff.');
        }
        $duplicate = $this->duplicateFile($id);
        if ($duplicate === null) return $this->response->setStatusCode(404)->setBody('Duplicate media not found.');
        try {
            $path = service('storage')->resolveDatabaseFile((string) $duplicate['filepath']);
        } catch (RuntimeException) {
            return $this->response->setStatusCode(404)->setBody('Duplicate media not found.');
        }
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mimeType) || ! in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'], true)) {
            return $this->response->setStatusCode(415)->setBody('Preview is unavailable for this file type.');
        }

        return $this->inlineMediaResponse($path, $mimeType);
    }

    public function duplicateDownload(int $id)
    {
        if (($redirect = $this->requireLogin()) !== null) return $redirect;
        if (! RolePolicy::allows(session()->get(), 'duplicates.view')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Duplicate downloads are restricted to Creative Staff.');
        }
        $duplicate = $this->duplicateFile($id);
        if ($duplicate === null) return redirect()->to(site_url('workspace/file-management'))->with('error', 'The duplicate file is unavailable.');
        try {
            $path = service('storage')->resolveDatabaseFile((string) $duplicate['filepath']);
        } catch (RuntimeException) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'The duplicate file is unavailable.');
        }

        return $this->response->download($path, null)->setFileName((string) $duplicate['filename']);
    }

    public function restoreDuplicates()
    {
        if (($redirect = $this->requireLogin()) !== null) return $redirect;
        $actor = session()->get();
        if (! RolePolicy::allows($actor, 'duplicates.view') || ! RolePolicy::allows($actor, 'files.manage')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Restoring duplicate copies is restricted to Creative Staff.');
        }

        $duplicateIds = $this->selectedIds('duplicate_ids');
        if ($duplicateIds === []) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Select at least one duplicate copy to restore.');
        }

        $db = db_connect();
        $duplicates = $db->table('duplicate_media')
            ->whereIn('id', $duplicateIds)
            ->where('status', 'pending')
            ->get()
            ->getResultArray();
        $restored = 0;
        $failed = count($duplicateIds) - count($duplicates);
        $restoredFileIds = [];

        foreach ($duplicates as $duplicate) {
            $original = (new FileModel())->where('is_deleted', 0)->find((int) $duplicate['original_file_id']);
            try {
                service('storage')->resolveDatabaseFile((string) $duplicate['filepath']);
            } catch (RuntimeException) {
                $original = null;
            }
            if ($original === null) {
                ++$failed;
                continue;
            }

            $now = gmdate('Y-m-d H:i:s');
            $db->transStart();
            $restoredId = (new FileModel())->insert($this->duplicateRestorePayload($duplicate, $original, $actor, $now), true);
            if ($restoredId === false) {
                $db->transRollback();
                $db->resetTransStatus();
                ++$failed;
                continue;
            }
            $db->table('duplicate_media')->where('id', (int) $duplicate['id'])->where('status', 'pending')->update(['status' => 'restored']);
            (new FileModel())->update((int) $original['id'], ['duplicate_count' => max(0, (int) ($original['duplicate_count'] ?? 0) - 1)]);
            $db->transComplete();
            if (! $db->transStatus()) {
                $db->resetTransStatus();
                ++$failed;
                continue;
            }

            $restoredFileIds[] = (int) $restoredId;
            ++$restored;
            service('audit')->record($actor, $this->request, 'upload', 'DUPLICATE_RESTORED', 'file', (int) $restoredId, 'Restored duplicate copy "' . (string) $duplicate['filename'] . '" to the active media library.');
        }

        foreach ($restoredFileIds as $restoredFileId) {
            $this->runPostUploadAutomation($restoredFileId, $actor);
        }

        if ($restored === 0) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'The selected duplicate copies could not be restored.');
        }
        $message = $restored . ' duplicate cop' . ($restored === 1 ? 'y was' : 'ies were') . ' restored to File Management.';
        if ($failed > 0) {
            $message .= ' ' . $failed . ' selected cop' . ($failed === 1 ? 'y was' : 'ies were') . ' unavailable.';
        }

        return redirect()->to(site_url('workspace/file-management'))->with('success', $message);
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
            $relativePath = trim($parentPath . '/' . $name, '/');
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

        return redirect()->to(site_url('workspace/file-management?folder=' . (int) $folderId))->with('success', 'Folder "' . $name . '" created. Upload files into it below.');
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
            $newRelative = trim($parentPath . '/' . $newName, '/');
            try {
                $oldDirectory = $storage->directory($oldRelative);
            } catch (RuntimeException) {
                $oldDirectory = null;
            }
            $newDirectory = $storage->root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newRelative);
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
            if ($oldDirectory !== null) {
                $oldPrefix = rtrim($oldDirectory, '\\/') . DIRECTORY_SEPARATOR;
                foreach ($files as $file) {
                    $oldFilePath = (string) ($file['filepath'] ?? '');
                    if (str_starts_with(mb_strtolower($oldFilePath), mb_strtolower($oldPrefix))) {
                        $relativeFilePath = substr($oldFilePath, strlen($oldPrefix));
                        (new FileModel())->update((int) $file['id'], ['filepath' => rtrim((string) $newDirectory, '\\/') . DIRECTORY_SEPARATOR . $relativeFilePath]);
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
            $db = db_connect();
            $now = gmdate('Y-m-d H:i:s');
            $db->transStart();
            foreach ($files as $file) {
                $fileId = (int) $file['id'];
                (new FileModel())->update($fileId, ['is_deleted' => 1, 'last_action' => 'moved_to_trash', 'last_action_at' => $now]);
                if ($db->table('trashfiles')->where('file_id', $fileId)->countAllResults() === 0) {
                    $db->table('trashfiles')->insert(['file_id' => $fileId, 'folder_root_id' => $id, 'deleted_at' => $now, 'deleted_by' => (int) $actor['employee_id'], 'reason' => 'Moved to Trash when folder "' . $folder['name'] . '" was deleted']);
                } else {
                    $db->table('trashfiles')->where('file_id', $fileId)->update(['folder_root_id' => $id, 'deleted_at' => $now, 'deleted_by' => (int) $actor['employee_id'], 'reason' => 'Moved to Trash when folder "' . $folder['name'] . '" was deleted']);
                }
            }
            $db->table('folder')->whereIn('id', $folderIds)->update([
                'is_deleted' => 1,
                'deleted_at' => $now,
                'deleted_by' => (int) $actor['employee_id'],
                'deletion_reason' => 'Moved to Trash through File Management',
                'trash_root_id' => $id,
            ]);
            $db->transComplete();
            if (! $db->transStatus()) {
                throw new RuntimeException('The folder could not be deleted.');
            }
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

        $file = (new FileModel())->where('is_deleted', 0)->find($id);
        if ($file === null) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'The requested media file is unavailable.');
        }
        if (! $this->canAccessMedia($file, session()->get())) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'This file is not assigned to your department.');
        }

        try {
            $path = service('storage')->resolveDatabaseFile((string) $file['filepath']);
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', $exception->getMessage());
        }

        $actor = session()->get();
        $this->recordRecent($file);
        (new FileModel())->update($id, [
            'last_action'    => 'downloaded',
            'last_action_at' => gmdate('Y-m-d H:i:s'),
        ]);
        service('audit')->record($actor, $this->request, 'download', 'DOWNLOAD', 'file', $id, 'Downloaded "' . $file['filename'] . '" through the web interface.');

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

        $file = (new FileModel())->where('is_deleted', 0)->find($id);
        if ($file === null) {
            return $this->response->setStatusCode(404)->setBody('Image not found.');
        }
        if (! $this->canAccessMedia($file, session()->get())) {
            return $this->response->setStatusCode(403)->setBody('This file is not assigned to your department.');
        }

        try {
            $path = service('storage')->resolveDatabaseFile((string) $file['filepath']);
        } catch (RuntimeException) {
            return $this->response->setStatusCode(404)->setBody('Image not found.');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
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

        $file = (new FileModel())->where('is_deleted', 0)->find($id);
        if ($file === null) {
            return $this->response->setStatusCode(404)->setBody('Video not found.');
        }
        if (! $this->canAccessMedia($file, session()->get())) {
            return $this->response->setStatusCode(403)->setBody('This file is not assigned to your department.');
        }

        return $this->streamMediaPreview($file);
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

    /**
     * Builds an on-demand preview with the currently selected default logo.
     * The generated copy is temporary; the protected original is never changed.
     */
    public function watermarkedPreview(int $id)
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        if (! RolePolicy::allows(session()->get(), 'files.manage')) {
            return $this->response->setStatusCode(403)->setBody('Watermarked previews are restricted to Creative Staff.');
        }

        $file = (new FileModel())->where('is_deleted', 0)->find($id);
        if ($file === null) {
            return $this->response->setStatusCode(404)->setBody('Image not found.');
        }

        $db = db_connect();
        $settings = $db->tableExists('watermark_settings')
            ? ($db->table('watermark_settings')->orderBy('id', 'ASC')->get(1)->getRowArray() ?? [])
            : [];
        $watermarking = service('watermarking');
        $result = $watermarking->createForDownload($file, session()->get(), $watermarking->settingsLogoCode($settings));
        if (! ($result['applied'] ?? false) || empty($result['path']) || ! is_file((string) $result['path'])) {
            return $this->response->setStatusCode(404)->setBody((string) ($result['reason'] ?? 'Watermarked preview is unavailable.'));
        }

        $path = (string) $result['path'];
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mimeType) || ! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            @unlink($path);
            return $this->response->setStatusCode(404)->setBody('Watermarked preview is unavailable for this file type.');
        }

        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return $this->response
            ->download($path, null, true)
            ->setFileName('watermarked_' . $this->previewName($file))
            ->setContentType($mimeType)
            ->inline()
            ->noCache();
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
            $db = db_connect();
            $db->transStart();
            (new FileModel())->update($id, [
                'is_deleted'     => 1,
                'last_action'    => 'moved_to_trash',
                'last_action_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $trash = $db->table('trashfiles');
            if ($trash->where('file_id', $id)->countAllResults() === 0) {
                $trash->insert([
                    'file_id'    => $id,
                    'deleted_by' => (int) $actor['employee_id'],
                    'reason'     => 'Moved to Trash through the web interface',
                ]);
            }
            $db->transComplete();
            if (! $db->transStatus()) {
                throw new RuntimeException('The media file could not be moved to Trash.');
            }
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
            $db = db_connect();
            $db->transStart();
            (new FileModel())->update($id, [
                'is_deleted'     => 0,
                'last_action'    => 'restored',
                'last_action_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $db->table('trashfiles')->where('file_id', $id)->delete();
            $db->transComplete();
            if (! $db->transStatus()) {
                throw new RuntimeException('The media file could not be restored.');
            }
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
        if ($ids === [] && $folderIds === []) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'Select one or more Trash items first.');
        }
        if (count($ids) + count($folderIds) > 500) {
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
        if ($ids === [] && $folderIds === []) {
            return redirect()->to(site_url('workspace/trash'))->with('error', 'Select one or more Trash items first.');
        }
        if (count($ids) + count($folderIds) > 500) {
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
                $db = db_connect();
                $db->transStart();
                (new FileModel())->update($id, [
                    'is_deleted'     => 1,
                    'last_action'    => 'moved_to_trash',
                    'last_action_at' => gmdate('Y-m-d H:i:s'),
                ]);
                if ($db->table('trashfiles')->where('file_id', $id)->countAllResults() === 0) {
                    $db->table('trashfiles')->insert([
                        'file_id'    => $id,
                        'deleted_by' => (int) $actor['employee_id'],
                        'reason'     => 'Bulk move to Trash through the web interface',
                    ]);
                }
                $db->transComplete();
                if (! $db->transStatus()) {
                    throw new RuntimeException('The media file could not be moved to Trash.');
                }

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
        foreach ((new FileModel())->where('is_deleted', 0)->whereIn('id', $ids)->findAll() as $file) {
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
                try {
                    $path = service('storage')->resolveDatabaseFile((string) $file['filepath']);
                } catch (RuntimeException) {
                    continue;
                }

                $archiveName = $this->uniqueArchiveName((string) $file['filename'], $usedNames);
                if (! $zip->addFile($path, $archiveName)) {
                    continue;
                }
                (new FileModel())->update($id, [
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

    public function recent()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        if (! RolePolicy::allows(session()->get(), 'files.manage')) return redirect()->to(site_url('workspace/file-management'))->with('error', 'Recent repository activity is restricted to Creative Staff.');

        $recent = db_connect()->table('files f')
            ->select('f.id, f.display_title, f.filetype, f.size, f.dateupload, f.last_action, f.last_action_at, f.tags, u.name AS uploader_name, COALESCE(f.last_action_at, f.dateupload) AS activity_at', false)
            ->join('user u', 'u.employee_id = f.employee_id', 'left')
            ->where('f.is_deleted', 0)
            ->orderBy('COALESCE(f.last_action_at, f.dateupload)', 'DESC', false)
            ->limit(100)
            ->get()
            ->getResultArray();

        return view('workspace/recent', $this->pageData('recent', 'Recent Files', ['files' => $recent]));
    }

    public function ifound()
    {
        if (($redirect = $this->requireLogin()) !== null) {
            return $redirect;
        }
        if (! RolePolicy::allows(session()->get(), 'files.manage')) return redirect()->to(site_url('workspace/file-management'))->with('error', 'Repository discovery is restricted to Creative Staff.');

        $term  = trim(mb_substr((string) $this->request->getGet('q'), 0, 120));
        $files = db_connect()->table('files f')
            ->select('f.*, ed.event_name, ed.confidence_score, ed.needs_review')
            ->join('ai_event_detections ed', 'ed.media_id = f.id', 'left')
            ->where('f.is_deleted', 0);
        if ($term !== '') {
            $files->groupStart()
                ->like('f.display_title', $term)
                ->orLike('f.detected_objects', $term)
                ->orLike('f.classification', $term)
                ->orLike('ed.event_name', $term)
                ->orLike('f.emotion', $term)
                ->orLike('f.gesture', $term)
                ->orLike('f.tags', $term)
                ->groupEnd();
        }

        return view('workspace/ifound', $this->pageData('ifound', 'iFound AI', [
            'term'    => $term,
            'results' => $files->orderBy('f.dateupload', 'DESC')->limit(100)->get()->getResultArray(),
        ]));
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
            return $this->redirectToIFound()->with('error', 'Choose a supported university event classification.');
        }

        $reviewNote = trim(mb_substr((string) $this->request->getPost('review_note'), 0, 500));
        $db = db_connect();
        if (! $db->tableExists('ai_event_detections')) {
            return $this->redirectToIFound()->with('error', 'AI review records are not available yet.');
        }
        $detection = $db->table('ai_event_detections')->where('media_id', $id)->get()->getRowArray();
        if ($detection === null) {
            return $this->redirectToIFound()->with('error', 'This media file has not been processed by iFound yet.');
        }

        $tags = $this->reviewedEventTags($file, $eventName === '' ? null : $eventName);
        $db->transStart();
        (new FileModel())->update($id, [
            'classification'   => $eventName !== '' ? $eventName : 'Unclassified university media',
            'detected_objects' => $eventName !== '' ? 'event: ' . $eventName : null,
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
            return $this->redirectToIFound()->with('error', 'The AI classification review could not be saved.');
        }

        $classification = $eventName !== '' ? $eventName : 'Unclassified university media';
        service('audit')->record($actor, $this->request, 'metadata', 'AI_CLASSIFICATION_REVIEWED', 'file', $id, 'Reviewed iFound classification for "' . $file['filename'] . '" as ' . $classification . '.');

        return $this->redirectToIFound()->with('success', 'Review saved. The file is now marked Ready.');
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

    /** @param array<string, mixed> $file @return list<string> */
    private function reviewedEventTags(array $file, ?string $eventName): array
    {
        $eventTags = array_keys((new GreyhoundAi())->eventKeywords);
        $excluded = array_fill_keys(array_map('mb_strtolower', array_merge($eventTags, ['AI Review Needed'])), true);
        $tags = [];
        foreach (preg_split('/\s*,\s*/u', (string) ($file['tags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tag) {
            $tag = trim($tag);
            if ($tag !== '' && ! isset($excluded[mb_strtolower($tag)])) {
                $tags[mb_strtolower($tag)] = $tag;
            }
        }
        if ($eventName !== null) {
            foreach ([$eventName, 'AI Classified', 'University Event'] as $tag) {
                $tags[mb_strtolower($tag)] = $tag;
            }
        }

        return array_slice(array_values($tags), 0, 30);
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
        if (! RolePolicy::allows(session()->get(), 'files.manage')) return redirect()->to(site_url('workspace/file-management'))->with('error', 'Trash is restricted to Creative Staff.');

        $actor = session()->get();
        $purgedCount = $this->trashService()->purgeExpiredTrash($actor, $this->request);
        $db = db_connect();
        $query = trim(mb_substr((string) $this->request->getGet('q'), 0, 120));
        $type = trim(mb_substr((string) $this->request->getGet('type'), 0, 30));
        $fileTypes = $db->table('files f')->select('f.filetype')->join('trashfiles tf', 'tf.file_id = f.id', 'inner')->where('f.is_deleted', 1)->where('tf.folder_root_id IS NULL', null, false)->where('f.filetype !=', '')->distinct()->orderBy('f.filetype', 'ASC')->get()->getResultArray();
        $validTypes = array_map(static fn (array $row): string => (string) $row['filetype'], $fileTypes);
        if ($type !== '' && ! in_array($type, $validTypes, true)) {
            $type = '';
        }

        $folders = [];
        if ($type === '') {
            $folderQuery = $db->table('folder fo')
                ->select('fo.id, fo.name, fo.description, fo.path, fo.deleted_at, fo.deletion_reason AS reason, u.name AS deleted_by_name, (SELECT COUNT(*) FROM folder child WHERE child.is_deleted = 1 AND child.trash_root_id = fo.id AND child.id != fo.id) AS subfolder_count, (SELECT COUNT(*) FROM trashfiles tf2 INNER JOIN files f2 ON f2.id = tf2.file_id AND f2.is_deleted = 1 WHERE tf2.folder_root_id = fo.id) AS file_count, (SELECT COALESCE(SUM(f3.size), 0) FROM trashfiles tf3 INNER JOIN files f3 ON f3.id = tf3.file_id AND f3.is_deleted = 1 WHERE tf3.folder_root_id = fo.id) AS size', false)
                ->join('user u', 'u.employee_id = fo.deleted_by', 'left')
                ->where('fo.is_deleted', 1)
                ->where('fo.trash_root_id = fo.id', null, false);
            if ($query !== '') {
                $folderQuery->groupStart()->like('fo.name', $query)->orLike('fo.deletion_reason', $query)->orLike('u.name', $query)->groupEnd();
            }
            $folders = $folderQuery->orderBy('fo.deleted_at', 'DESC')->limit(100)->get()->getResultArray();
            $folders = $this->trashService()->withRetention($folders);
        }

        $trashQuery = $db->table('files f')
            ->select('f.id, f.display_title, f.filename, f.filetype, f.size, f.dateupload, f.last_action_at, tf.deleted_at, tf.reason, u.name AS deleted_by_name')
            ->join('trashfiles tf', 'tf.file_id = f.id', 'inner')
            ->join('user u', 'u.employee_id = tf.deleted_by', 'left')
            ->where('f.is_deleted', 1)
            ->where('tf.folder_root_id IS NULL', null, false);
        if ($query !== '') {
            $trashQuery->groupStart()->like('f.display_title', $query)->orLike('tf.reason', $query)->orLike('u.name', $query)->groupEnd();
        }
        if ($type !== '') {
            $trashQuery->where('f.filetype', $type);
        }
        $files = $trashQuery
            ->orderBy('tf.deleted_at', 'DESC')
            ->orderBy('f.last_action_at', 'DESC')
            ->limit(100)
            ->get()
            ->getResultArray();
        $files = $this->trashService()->withRetention($files);

        return view('workspace/trash', $this->pageData('trash', 'Trash', [
            'files'         => $files,
            'folders'       => $folders,
            'retentionDays' => self::TRASH_RETENTION_DAYS,
            'purgedCount'   => $purgedCount,
            'query'         => $query,
            'type'          => $type,
            'fileTypes'     => $fileTypes,
            'success'       => session()->getFlashdata('success'),
            'error'         => session()->getFlashdata('error'),
        ]));
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

    /** @param array<string, mixed>|null $file */
    private function streamMediaPreview(?array $file)
    {
        if ($file === null) {
            return $this->response->setStatusCode(404)->setBody('Media preview not found.');
        }
        try {
            $path = service('storage')->resolveDatabaseFile((string) $file['filepath']);
        } catch (RuntimeException) {
            return $this->response->setStatusCode(404)->setBody('Media preview not found.');
        }
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowed = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
            'video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo',
            'video/x-matroska', 'video/x-m4v',
        ];
        if (! is_string($mimeType) || ! in_array($mimeType, $allowed, true)) {
            return $this->response->setStatusCode(415)->setBody('Preview is unavailable for this file type.');
        }
        return $this->inlineMediaResponse($path, $mimeType);
    }

    /**
     * Return a browser-native image or video response for media cards.
     * Video ranges let the browser fetch metadata and a poster frame without
     * downloading the whole file before rendering a thumbnail.
     */
    private function inlineMediaResponse(string $path, string $mimeType)
    {
        $size = filesize($path);
        if ($size === false || $size < 1) {
            return $this->response->setStatusCode(404)->setBody('Media preview not found.');
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;
        $range = $this->request->getHeaderLine('Range');
        if (preg_match('/bytes=(\d*)-(\d*)/i', $range, $matches) === 1) {
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
        $body = file_get_contents($path, false, null, $start, $length);
        if ($body === false) {
            return $this->response->setStatusCode(404)->setBody('Media preview not found.');
        }

        $response = $this->response
            ->setStatusCode($status)
            ->setHeader('Accept-Ranges', 'bytes')
            ->setHeader('Content-Type', $mimeType)
            ->setHeader('Content-Length', (string) $length)
            ->setBody($body)
            ->noCache();

        return $status === 206
            ? $response->setHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size)
            : $response;
    }

    /** @param array<string, mixed> $actor */
    private function canPermanentlyDeleteTrash(array $actor): bool
    {
        return RolePolicy::allows($actor, 'files.delete_permanently');
    }

    /** @param array<string, mixed> $file @param array<string, mixed> $actor */
    private function canAccessMedia(array $file, array $actor): bool
    {
        // Global media access must take precedence over department-only access.
        if (RolePolicy::allows($actor, 'files.view_all')) {
            return true;
        }
        if (! RolePolicy::allows($actor, 'files.view_assigned')) {
            return false;
        }
        return db_connect()->table('media_assignments')
            ->where('media_id', (int) $file['id'])
            ->where('department', (string) ($actor['department'] ?? ''))
            ->countAllResults() > 0;
    }

    /** @param array<string, mixed> $file @param array<string, mixed> $actor */
    private function restoreTrashedMedia(array $file, array $actor, string $auditAction = 'RESTORE'): void
    {
        $id = (int) $file['id'];
        $db = db_connect();
        $db->transStart();
        (new FileModel())->update($id, [
            'is_deleted'     => 0,
            'last_action'    => 'restored',
            'last_action_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $db->table('trashfiles')->where('file_id', $id)->delete();
        $db->transComplete();
        if (! $db->transStatus()) {
            throw new RuntimeException('The media file could not be restored.');
        }

        service('audit')->record($actor, $this->request, 'delete', $auditAction, 'file', $id, 'Restored "' . $file['filename'] . '" from Trash.');
    }

    private function trashService(): TrashService
    {
        return new TrashService();
    }

    /** @return list<array{original:array<string,mixed>,duplicates:list<array<string,mixed>>,similarity:float}> */
    private function duplicateGroups(): array
    {
        $db = db_connect();
        if (! $db->tableExists('duplicate_media')) return [];

        $duplicateFields = $db->getFieldNames('duplicate_media');
        $similaritySelect = in_array('similarity_score', $duplicateFields, true) ? 'd.similarity_score' : '100 AS similarity_score';
        $matchTypeSelect = in_array('match_type', $duplicateFields, true) ? 'd.match_type' : "'exact' AS match_type";
        $detectionScopeSelect = in_array('detection_scope', $duplicateFields, true) ? 'd.detection_scope' : "'media_library' AS detection_scope";
        $rows = $db->table('duplicate_media d')
            ->select('d.id, d.original_file_id, d.filename, d.display_title, d.filepath, d.mime_type, d.size, d.status, d.created_at, ' . $similaritySelect . ', ' . $matchTypeSelect . ', ' . $detectionScopeSelect . ', f.display_title AS original_display_title, f.filename AS original_filename, f.filetype AS original_filetype, f.mime_type AS original_mime_type, f.size AS original_size, f.dateupload AS original_dateupload, u.name AS uploader_name', false)
            ->join('files f', 'f.id = d.original_file_id', 'inner')
            ->join('user u', 'u.employee_id = d.uploaded_by', 'left')
            ->where('d.status', 'pending')
            ->where('f.is_deleted', 0)
            ->orderBy('d.original_file_id', 'ASC')
            ->orderBy('d.created_at', 'DESC')
            ->limit(500)
            ->get()
            ->getResultArray();

        $watermarkedIds = [];
        if ($db->tableExists('file_watermarks')) {
            $originalIds = array_values(array_unique(array_map('intval', array_column($rows, 'original_file_id'))));
            if ($originalIds !== []) {
                $watermarkedIds = array_fill_keys(array_map('intval', array_column($db->table('file_watermarks')->select('media_id')->whereIn('media_id', $originalIds)->get()->getResultArray(), 'media_id')), true);
            }
        }

        $groups = [];
        foreach ($rows as $row) {
            $originalId = (int) $row['original_file_id'];
            if (! isset($groups[$originalId])) {
                $groups[$originalId] = [
                    'original' => [
                        'id' => $originalId,
                        'display_title' => (string) ($row['original_display_title'] ?: pathinfo((string) $row['original_filename'], PATHINFO_FILENAME)),
                        'filename' => (string) $row['original_filename'],
                        'filetype' => (string) $row['original_filetype'],
                        'mime_type' => (string) $row['original_mime_type'],
                        'size' => (int) $row['original_size'],
                        'dateupload' => (string) $row['original_dateupload'],
                        'watermarked' => isset($watermarkedIds[$originalId]),
                    ],
                    'duplicates' => [],
                    'similarity' => 100.0,
                ];
            }
            $duplicate = [
                'id' => (int) $row['id'],
                'display_title' => (string) ($row['display_title'] ?: pathinfo((string) $row['filename'], PATHINFO_FILENAME)),
                'filename' => (string) $row['filename'],
                'mime_type' => (string) $row['mime_type'],
                'size' => (int) $row['size'],
                'status' => (string) $row['status'],
                'created_at' => (string) $row['created_at'],
                'uploader_name' => (string) ($row['uploader_name'] ?? ''),
                'match_type' => (string) $row['match_type'],
                'similarity_score' => (float) $row['similarity_score'],
                'detection_scope' => (string) $row['detection_scope'],
            ];
            $groups[$originalId]['duplicates'][] = $duplicate;
            $groups[$originalId]['similarity'] = min((float) $groups[$originalId]['similarity'], (float) $duplicate['similarity_score']);
        }

        return array_values($groups);
    }

    /** @return array<string,mixed>|null */
    private function duplicateFile(int $id): ?array
    {
        if ($id < 1 || ! db_connect()->tableExists('duplicate_media')) return null;

        return db_connect()->table('duplicate_media')->where('id', $id)->where('status', 'pending')->get()->getRowArray();
    }

    /** @param array<string,mixed> $duplicate @param array<string,mixed> $original @param array<string,mixed> $actor @return array<string,mixed> */
    private function duplicateRestorePayload(array $duplicate, array $original, array $actor, string $now): array
    {
        return [
            'filename' => (string) $duplicate['filename'],
            'display_title' => trim((string) ($duplicate['display_title'] ?? '')) ?: pathinfo((string) $duplicate['filename'], PATHINFO_FILENAME),
            'filepath' => (string) $duplicate['filepath'],
            'filetype' => strtolower((string) pathinfo((string) $duplicate['filename'], PATHINFO_EXTENSION)),
            'mime_type' => (string) ($duplicate['mime_type'] ?? ''),
            'media_kind' => $this->mediaKindForMimeType((string) ($duplicate['mime_type'] ?? '')),
            'size' => (int) ($duplicate['size'] ?? 0),
            'dateupload' => (string) ($duplicate['created_at'] ?? $now),
            'description' => 'Restored from the Duplicate Queue.',
            'employee_id' => (int) ($duplicate['uploaded_by'] ?? $actor['employee_id']),
            'folder_id' => empty($original['folder_id']) ? null : (int) $original['folder_id'],
            'is_deleted' => 0,
            'downloadable' => 1,
            'release_status' => 'draft',
            'featured' => 0,
            'storage_stage' => 'raw',
            'distribution_type' => null,
            'distribution_target' => null,
            'content_hash' => $duplicate['content_hash'] ?? null,
            'image_fingerprint' => $duplicate['image_fingerprint'] ?? null,
            'upload_batch_id' => $duplicate['upload_batch_id'] ?? null,
            'duplicate_of' => (int) $duplicate['original_file_id'],
            'duplicate_count' => 0,
            'last_action' => 'restored',
            'last_action_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return list<int> */
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

    /** @param list<int> $folderIds @param array<int, array<string, mixed>> $folderById @return list<int> */
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

    /** @param list<array<string,mixed>> $folders @param array<int,array<string,mixed>> $folderById @return list<int> */
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
            $files->orLike('p.date_taken', $query)->orLike('p.event_name', $query);
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

    /** @param array<string, mixed> $data @return array<string, mixed> */
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

    /** @param array<string, mixed> $actor @return list<string> */
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

        try {
            $watermark = service('watermarking')->applyForCreativeUpload($file, $actor);
            if (! empty($watermark['applied'])) {
                $messages[] = 'Watermark derivative created.';
                service('audit')->record($actor, $this->request, 'watermark', 'WATERMARK_APPLIED', 'file', $fileId, 'Created a watermarked derivative for "' . $file['filename'] . '".');
            }
        } catch (\Throwable) {
            $messages[] = 'Watermark processing could not be completed.';
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
        $channel = trim((string) $this->request->getPost('channel'));
        $description = trim((string) $this->request->getPost('description'));
        $publishedAt = gmdate('Y-m-d H:i:s');
        $employeeId = (int) $actor['employee_id'];

        if ($displayTitle === '' || $channel === '') {
            return redirect()->back()->with('error', 'Display title and channel are required.');
        }

        $db = db_connect();
        $db->transStart();

        if ($postType === 'folder') {
            $folderId = (int) $this->request->getPost('folder_id');
            $folderModel = new \App\Models\FolderModel();
            $folder = $folderModel->where('is_deleted', 0)->find($folderId);
            if (! $folder) {
                return redirect()->back()->with('error', 'Folder not found.');
            }
            $folderModel->update($folderId, [
                'publication_channel' => $channel,
                'published_at' => $publishedAt,
                'published_by' => $employeeId,
                'description' => $description !== '' ? $description : $folder['description'],
                'name' => $displayTitle,
            ]);
            service('audit')->record($actor, $this->request, 'publish', 'FOLDER_PUBLISHED', 'folder', $folderId, 'Published folder "' . $displayTitle . '" to Mobile App. Channel: ' . $channel . '.');
        } else if ($postType === 'files') {
            $fileIds = $this->request->getPost('file_ids') ?? [];
            if (empty($fileIds)) {
                return redirect()->back()->with('error', 'No files selected.');
            }
            $fileModel = new \App\Models\FileModel();
            foreach ($fileIds as $fileId) {
                $file = $fileModel->find((int) $fileId);
                if ($file) {
                    $publishedTitle = $displayTitle;
                    $fileModel->update((int) $fileId, [
                        'publication_channel' => $channel,
                        'published_at' => $publishedAt,
                        'published_by' => $employeeId,
                        'description' => $description !== '' ? $description : $file['description'],
                        'display_title' => $displayTitle,
                        'release_status' => 'published',
                    ]);
                    service('audit')->record($actor, $this->request, 'publish', 'FILE_PUBLISHED', 'file', (int) $fileId, 'Published file "' . $publishedTitle . '" to Mobile App. Channel: ' . $channel . '.');
                }
            }
        } else {
            return redirect()->back()->with('error', 'Invalid post type.');
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return redirect()->back()->with('error', 'Failed to post media.');
        }

        return redirect()->to(site_url('workspace/published-files'))->with('message', 'Media successfully posted.');
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
            if (empty($parentFolder['publication_channel'])) {
                return redirect()->to(site_url('workspace/published-files'))->with('error', 'This folder has not been published yet.');
            }
            $files = $fileModel
                ->where('folder_id', (int) $folderId)
                ->where('is_deleted', 0)
                ->orderBy('dateupload', 'DESC')
                ->findAll();

            // Inherit publisher info from folder for the files
            foreach ($files as &$f) {
                $f['published_by'] = $parentFolder['published_by'] ?? $f['employee_id'];
                $f['published_at'] = $parentFolder['published_at'] ?? $f['published_at'] ?? $f['dateupload'];
                $f['publication_channel'] = $parentFolder['publication_channel'];
            }
        } else {
            $folders = $folderModel
                ->where('is_deleted', 0)
                ->where('publication_channel IS NOT NULL')
                ->orderBy('published_at', 'DESC')
                ->findAll();
            $publishedFolderIds = array_map('intval', array_column($folders, 'id'));
            $files = $this->publishedStandaloneQuery($fileModel, $publishedFolderIds)
                ->orderBy('published_at', 'DESC')
                ->orderBy('dateupload', 'DESC')
                ->findAll();
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

        return view('workspace/published_files', [
            'activePage' => 'published-files',
            'folders' => $folders,
            'files' => $files,
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
