<?php

namespace App\Services;

use RuntimeException;

/** Transactional request, assignment, history, and notification operations. */
class DistributionService
{
    /** @param array<string, mixed> $actor */
    public function createRequest(array $actor, string $title, string $description): int
    {
        $department = trim((string) ($actor['department'] ?? ''));
        if ($department === '') {
            throw new RuntimeException('Your account must have a department before requesting media.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $db = db_connect();
        $db->transStart();
        $db->table('media_requests')->insert(['department' => $department, 'title' => $title, 'description' => $description, 'status' => 'submitted', 'requested_by' => (int) $actor['employee_id'], 'submitted_at' => $now, 'updated_at' => $now]);
        $id = (int) $db->insertID();
        $this->action($db, $actor, 'submitted', $id, null, 0, $department, $now);
        $this->notifyCreativeAdmins(
            $db,
            'department_request',
            'New Department Media Request',
            "Department:\n" . $department . "\n\nRequested Media:\n" . $title . "\n\nRequested By:\n" . (string) ($actor['name'] ?? 'Department user') . "\n\nTime:\n" . date('M j, Y g:i A', strtotime($now)),
            site_url('workspace/distribution?tab=requests'),
            $now
        );
        $db->transComplete();
        if (! $db->transStatus()) {
            throw new RuntimeException('The media request could not be submitted.');
        }
        return $id;
    }

    /**
     * Registers a submission for department review. Physical department copies are
     * created only by authorize(), after required Department confirmation.
     *
     * @param list<int> $mediaIds
     * @param list<int> $folderIds
     * @param array<string, mixed> $actor
     * @return array{file_count:int,folder_count:int,request_id:int,cycle:int}
     */
    public function assign(array $mediaIds, array $folderIds, string $department, ?int $requestId, string $method, array $actor): array
    {
        $mediaIds = array_values(array_unique(array_filter(array_map('intval', $mediaIds), static fn (int $id): bool => $id > 0)));
        $folderIds = array_values(array_unique(array_filter(array_map('intval', $folderIds), static fn (int $id): bool => $id > 0)));
        if (($mediaIds === [] && $folderIds === []) || $department === '' || ! in_array($method, ['request', 'direct'], true)) {
            throw new RuntimeException('Choose media, a department, and a valid assignment method.');
        }

        $db = db_connect();
        $now = gmdate('Y-m-d H:i:s');
        $db->transStart();

        if ($requestId === null) {
            $db->table('media_requests')->insert([
                'transaction_type' => 'direct', 'department' => $department,
                'title' => 'Direct Send - ' . date('M j, Y g:i A', strtotime($now)),
                'description' => 'Media sent directly by Creative Staff for Department review.',
                'status' => 'pending', 'authorization_status' => 'not_authorized',
                'current_cycle' => 1, 'requested_by' => (int) ($actor['employee_id'] ?? 0),
                'original_sender' => (int) ($actor['employee_id'] ?? 0), 'submitted_at' => $now,
                'transmitted_by' => (int) ($actor['employee_id'] ?? 0), 'transmitted_at' => $now, 'updated_at' => $now,
            ]);
            $requestId = (int) $db->insertID();
            $db->table('media_requests')->where('id', $requestId)->update(['transaction_code' => $this->transactionCode($requestId, $now)]);
            $cycle = 1;
        } else {
            $request = $db->table('media_requests')->where('id', $requestId)->where('department', $department)->get()->getRowArray();
            if ($request === null || (string) $request['status'] === 'complete') {
                throw new RuntimeException('Choose an open transaction belonging to the selected department.');
            }
            $cycle = max(1, (int) ($request['current_cycle'] ?? 0) + 1);
            $requestUpdate = [
                'status' => 'pending', 'authorization_status' => 'not_authorized', 'current_cycle' => $cycle,
                'transmitted_by' => (int) ($actor['employee_id'] ?? 0), 'transmitted_at' => $now, 'updated_at' => $now,
            ];
            if (empty($request['transaction_code'])) {
                $requestUpdate['transaction_code'] = $this->transactionCode($requestId, (string) $request['submitted_at']);
            }
            if ((int) ($request['current_cycle'] ?? 0) === 0) {
                $requestUpdate['original_sender'] = (int) ($actor['employee_id'] ?? 0);
            }
            $db->table('media_requests')->where('id', $requestId)->update($requestUpdate);
        }
        $submissionType = $cycle === 1 ? 'original' : 'resubmission';
        $fileCount = 0;
        $folderCount = 0;
        $items = [];

        foreach ($folderIds as $folderId) {
            $folder = $db->table('folder')->select('id, name, path')->where('id', $folderId)->where('is_deleted', 0)->get()->getRowArray();
            if ($folder === null) {
                continue;
            }
            $exists = $db->table('media_assignment_folders')->where('request_id', $requestId)->where('folder_id', $folderId)->where((new \Config\Storage())->centralized() ? 'cycle_number' : 'cycle_number <=', (new \Config\Storage())->centralized() ? $cycle : PHP_INT_MAX)->countAllResults() > 0;
            if (! $exists) {
                $db->table('media_assignment_folders')->insert([
                    'folder_id' => $folderId, 'department' => $department, 'request_id' => $requestId,
                    'method' => $method, 'status' => 'pending', 'cycle_number' => $cycle,
                    'submission_type' => $submissionType, 'sent_by' => (int) ($actor['employee_id'] ?? 0), 'sent_at' => $now,
                ]);
                ++$folderCount;
            }
            $items[] = ['type' => 'folder', 'id' => $folderId, 'name' => (string) $folder['name'], 'source_path'=>(string)$folder['path']];
        }
        foreach ($mediaIds as $mediaId) {
            $file = $db->table('files')->select('id, filename, filepath')->where('id', $mediaId)->where('is_deleted', 0)->get()->getRowArray();
            if ($file === null) {
                continue;
            }
            $exists = $db->table('media_assignments')->where('request_id', $requestId)->where('media_id', $mediaId)->where((new \Config\Storage())->centralized() ? 'cycle_number' : 'cycle_number <=', (new \Config\Storage())->centralized() ? $cycle : PHP_INT_MAX)->countAllResults() > 0;
            if (! $exists) {
                $db->table('media_assignments')->insert([
                    'media_id' => $mediaId, 'department' => $department, 'request_id' => $requestId,
                    'method' => $method, 'status' => 'pending', 'cycle_number' => $cycle,
                    'submission_type' => $submissionType, 'sent_by' => (int) ($actor['employee_id'] ?? 0), 'sent_at' => $now,
                ]);
                ++$fileCount;
            }
            $items[] = ['type' => 'file', 'id' => $mediaId, 'name' => (string) $file['filename'], 'source_path'=>(string)$file['filepath']];
        }
        if ($fileCount + $folderCount === 0) {
            throw new RuntimeException('No new file or folder assignments were created.');
        }
        $this->action($db, $actor, $submissionType, $requestId, null, $fileCount, $department, $now, $cycle, 'pending', 'not_authorized', null, $items);
        $this->notifyDepartment($db, $department, 'Transaction ' . $this->transactionCode($requestId, $now) . ' is ready for review. The media is not yet authorized for File Management.', site_url('workspace/distribution?request=' . $requestId), $now, $cycle === 1 ? 'Media awaiting review' : 'Media resubmission awaiting review');
        $db->transComplete();
        if (! $db->transStatus()) {
            throw new RuntimeException('The media transaction could not be saved.');
        }

        return ['file_count' => $fileCount, 'folder_count' => $folderCount, 'request_id' => $requestId, 'cycle' => $cycle];
    }

    /** @param array<string,mixed> $actor */
    public function markIncomplete(int $requestId, array $actor, string $feedback): void
    {
        $feedback = trim(mb_substr($feedback, 0, 4000));
        if ($feedback === '') {
            throw new RuntimeException('Feedback is required when marking a transaction as incomplete.');
        }
        $db = db_connect();
        $request = $this->reviewableRequest($db, $requestId, $actor);
        $now = gmdate('Y-m-d H:i:s');
        $db->transStart();
        $db->table('media_requests')->where('id', $requestId)->update(['status' => 'pending_incomplete', 'authorization_status' => 'not_authorized', 'updated_at' => $now]);
        $this->action($db, $actor, 'marked_incomplete', $requestId, null, 0, (string) $request['department'], $now, (int) $request['current_cycle'], 'pending_incomplete', 'not_authorized', $feedback);
        $this->notifyCreativeAdmins($db, 'distribution_incomplete', 'Transaction requires corrections', $this->transactionCode($requestId, (string) $request['submitted_at']) . " was marked incomplete by " . (string) $request['department'] . ".\n\nFeedback:\n" . $feedback, site_url('workspace/distribution?request=' . $requestId), $now);
        $db->transComplete();
        if (! $db->transStatus()) {
            throw new RuntimeException('The incomplete review could not be saved.');
        }
    }

    /** @param array<string,mixed> $actor */
    public function authorize(int $requestId, array $actor, string $feedback): void
    {
        $feedback = trim(mb_substr($feedback, 0, 4000));
        if ($feedback === '') {
            throw new RuntimeException('Completion feedback is required before confirming this transaction as complete.');
        }
        $db = db_connect();
        $request = $this->reviewableRequest($db, $requestId, $actor);
        $assignments = $db->table('media_assignments ma')->select('ma.id, ma.media_id, ma.delivery_filepath, f.filepath, f.folder_id')->join('files f', 'f.id = ma.media_id', 'inner')->where('ma.request_id', $requestId)->where('ma.cycle_number <=', (int)$request['current_cycle'])->where('f.is_deleted', 0)->orderBy('ma.cycle_number', 'DESC')->orderBy('ma.id', 'DESC')->get()->getResultArray();
        $folderAssignments = $db->table('media_assignment_folders')->where('request_id',$requestId)->where('cycle_number <=',(int)$request['current_cycle'])->orderBy('cycle_number', 'DESC')->orderBy('id', 'DESC')->get()->getResultArray();
        [$assignments, $folderAssignments] = $this->latestDeliveredItems($assignments, $folderAssignments);
        if ($assignments === [] && $folderAssignments === []) {
            throw new RuntimeException('This transaction has no available files to authorize.');
        }
        $storage = service('storage');
        $created = [];
        $now = gmdate('Y-m-d H:i:s');
        try {
            $storage->ensureDepartmentDirectory((string) $request['department']);
            $db->transStart();
            foreach ($assignments as $assignment) {
                if ((new \Config\Storage())->centralized()) {
                    if (empty($assignment['delivery_filepath'])) {
                        throw new RuntimeException('This delivery must be migrated before completion.');
                    }
                    $stored = ['filepath'=>$storage->resolveDatabaseFile($assignment['delivery_filepath'])];
                } else {
                    $stored = $storage->storeDepartmentCopy($storage->resolveDatabaseFile((string) $assignment['filepath']), (string) $request['department'], $this->fileFolderPath($db, empty($assignment['folder_id']) ? null : (int) $assignment['folder_id']));
                    $created[] = $stored['filepath'];
                }
                $db->table('media_assignments')->where('id', (int) $assignment['id'])->update(['delivery_filepath' => $storage->databasePath($stored['filepath']), 'status' => 'completed', 'completed_by' => (int) $actor['employee_id'], 'completed_at' => $now]);
                (new DepartmentLibraryService($db, $storage))->capture(
                    $db->table('media_assignments')->where('id', $assignment['id'])->get()->getRowArray(),
                    $db->table('files')->where('id', $assignment['media_id'])->get()->getRowArray(), $created
                );
            }
            if ((new \Config\Storage())->centralized()) {
                foreach ($folderAssignments as $folder) {
                    $path = $storage->resolveDatabaseDirectory((string)$folder['delivery_path']);
                    (new DepartmentLibraryService($db,$storage))->captureFolderPath((string)$request['department'],(string)$folder['delivery_path']);
                    $db->table('media_assignment_folders')->where('id',$folder['id'])->update(['status'=>'completed','completed_by'=>(int)$actor['employee_id'],'completed_at'=>$now]);
                }
            } elseif ($db->tableExists('media_assignment_folders')) {
                foreach ($db->table('media_assignment_folders maf')->select('maf.id, f.name, f.path')->join('folder f', 'f.id = maf.folder_id', 'inner')->where('maf.request_id', $requestId)->get()->getResultArray() as $folder) {
                    $path = $storage->ensureDepartmentDirectory((string) $request['department'], $this->folderPath($folder));
                    $db->table('media_assignment_folders')->where('id', (int) $folder['id'])->update(['delivery_path' => $storage->databasePath($path), 'status' => 'completed', 'completed_by' => (int) $actor['employee_id'], 'completed_at' => $now]);
                }
            }
            $db->table('media_requests')->where('id', $requestId)->update(['status' => 'complete', 'authorization_status' => 'authorized', 'completion_feedback' => $feedback, 'completed_by' => (int) $actor['employee_id'], 'completed_at' => $now, 'updated_at' => $now]);
            $this->action($db, $actor, 'confirmed_complete', $requestId, null, count($assignments), (string) $request['department'], $now, (int) $request['current_cycle'], 'complete', 'authorized', $feedback);
            $this->notifyCreativeAdmins($db, 'distribution_complete', 'Transaction completed', $this->transactionCode($requestId, (string) $request['submitted_at']) . ' was confirmed complete and authorized by ' . (string) $request['department'] . '.', site_url('workspace/distribution?request=' . $requestId), $now);
            $db->transComplete();
            if (! $db->transStatus()) {
                throw new RuntimeException('The transaction could not be authorized.');
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            foreach (array_reverse($created) as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('The transaction could not be authorized.', 0, $e);
        }
    }

    /** Rows arrive newest-first; earlier distinct submissions remain part of the transaction. */
    private function latestDeliveredItems(array $files, array $folders): array
    {
        $latestFolders = []; $seenFolders = []; $seenFolderPaths = []; $supersededPaths = [];
        foreach ($folders as $folder) {
            $path = (string) ($folder['delivery_path'] ?? '');
            $id = (int) $folder['folder_id'];
            if (isset($seenFolders[$id]) || ($path !== '' && isset($seenFolderPaths[$path]))) {
                // A replacement folder archives its old tree, including files omitted in the new cycle.
                if ($path !== '' && !isset($seenFolderPaths[$path])) $supersededPaths[] = rtrim($path, '/') . '/';
                continue;
            }
            $seenFolders[$id] = true;
            if ($path !== '') {
                $seenFolderPaths[$path] = true;
            }
            $latestFolders[] = $folder;
        }
        $latestFiles = []; $seenFiles = []; $seenFilePaths = [];
        foreach ($files as $file) {
            $path = (string) ($file['delivery_filepath'] ?? '');
            foreach ($supersededPaths as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    continue 2;
                }
            }
            $id = (int) $file['media_id'];
            if (isset($seenFiles[$id]) || $path !== '' && isset($seenFilePaths[$path])) {
                continue;
            }
            $seenFiles[$id] = true;
            if ($path !== '') {
                $seenFilePaths[$path] = true;
            }
            $latestFiles[] = $file;
        }
        return [$latestFiles, $latestFolders];
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private function reviewableRequest($db, int $requestId, array $actor): array
    {
        $request = $db->table('media_requests')->where('id', $requestId)->where('department', trim((string) ($actor['department'] ?? '')))->get()->getRowArray();
        if ($request === null || (string) $request['status'] !== 'pending' || (string) $request['authorization_status'] !== 'not_authorized') {
            throw new RuntimeException('This transaction is not awaiting Department review.');
        }
        return $request;
    }

    private function transactionCode(int $requestId, string $date): string
    {
        return 'TRX-' . date('Y', strtotime($date)) . '-' . str_pad((string) $requestId, 6, '0', STR_PAD_LEFT);
    }

    /** @return array{departments:int,files_copied:int,assignments_updated:int,folders_updated:int,skipped:int,errors:list<string>} */
    public function backfillDepartmentStorage(): array
    {
        $db = db_connect();
        $storage = service('storage');
        $departmentNames = [];
        foreach ($db->table('user')->select('department')->where('role', 'department')->where('department !=', '')->groupBy('department')->get()->getResultArray() as $row) {
            $departmentNames[] = trim((string) $row['department']);
        }
        foreach ($db->table('media_assignments')->select('department')->where('department !=', '')->groupBy('department')->get()->getResultArray() as $row) {
            $departmentNames[] = trim((string) $row['department']);
        }
        if ($db->tableExists('media_assignment_folders')) {
            foreach ($db->table('media_assignment_folders')->select('department')->where('department !=', '')->groupBy('department')->get()->getResultArray() as $row) {
                $departmentNames[] = trim((string) $row['department']);
            }
        }
        $departmentNames = array_values(array_unique(array_filter($departmentNames)));
        $result = ['departments' => 0, 'files_copied' => 0, 'assignments_updated' => 0, 'folders_updated' => 0, 'skipped' => 0, 'errors' => []];
        foreach ($departmentNames as $department) {
            try {
                $storage->ensureDepartmentDirectory($department);
                ++$result['departments'];
            } catch (RuntimeException $exception) {
                $result['errors'][] = $department . ': ' . $exception->getMessage();
            }
        }

        if ($db->tableExists('media_assignment_folders')) {
            $folderAssignments = $db->table('media_assignment_folders ma')
                ->select('ma.id, ma.department, ma.delivery_path, f.name, f.path')
                ->join('folder f', 'f.id = ma.folder_id', 'inner')
                ->get()
                ->getResultArray();
            foreach ($folderAssignments as $assignment) {
                if ($storage->isDepartmentStoredDirectory((string) ($assignment['delivery_path'] ?? ''))) {
                    continue;
                }
                try {
                    $deliveryPath = $storage->ensureDepartmentDirectory((string) $assignment['department'], $this->folderPath($assignment));
                    $db->table('media_assignment_folders')->where('id', (int) $assignment['id'])->update(['delivery_path' => $storage->databasePath($deliveryPath)]);
                    ++$result['folders_updated'];
                } catch (RuntimeException $exception) {
                    ++$result['skipped'];
                    $result['errors'][] = 'Folder assignment #' . (int) $assignment['id'] . ': ' . $exception->getMessage();
                }
            }
        }

        $assignments = $db->table('media_assignments ma')
            ->select('ma.id, ma.media_id, ma.department, ma.delivery_filepath, f.filepath, f.folder_id, f.is_deleted')
            ->join('files f', 'f.id = ma.media_id', 'inner')
            ->orderBy('ma.id', 'ASC')
            ->get()
            ->getResultArray();
        $copiesByDepartmentAndMedia = [];
        foreach ($assignments as $assignment) {
            $key = mb_strtolower(trim((string) $assignment['department'])) . ':' . (int) $assignment['media_id'];
            $deliveryPath = (string) ($assignment['delivery_filepath'] ?? '');
            if (! isset($copiesByDepartmentAndMedia[$key]) && $storage->isDepartmentStoredFile($deliveryPath)) {
                $copiesByDepartmentAndMedia[$key] = $deliveryPath;
            }
        }
        foreach ($assignments as $assignment) {
            $key = mb_strtolower(trim((string) $assignment['department'])) . ':' . (int) $assignment['media_id'];
            $currentPath = (string) ($assignment['delivery_filepath'] ?? '');
            if ($storage->isDepartmentStoredFile($currentPath)) {
                continue;
            }
            if (isset($copiesByDepartmentAndMedia[$key])) {
                $db->table('media_assignments')->where('id', (int) $assignment['id'])->update(['delivery_filepath' => $copiesByDepartmentAndMedia[$key]]);
                ++$result['assignments_updated'];
                continue;
            }
            if ((int) $assignment['is_deleted'] === 1) {
                ++$result['skipped'];
                continue;
            }
            try {
                $folderPath = $this->fileFolderPath($db, empty($assignment['folder_id']) ? null : (int) $assignment['folder_id']);
                $stored = $storage->storeDepartmentCopy(
                    $storage->resolveDatabaseFile((string) $assignment['filepath']),
                    (string) $assignment['department'],
                    $folderPath
                );
                $copiesByDepartmentAndMedia[$key] = $storage->databasePath($stored['filepath']);
                $db->table('media_assignments')->where('id', (int) $assignment['id'])->update(['delivery_filepath' => $copiesByDepartmentAndMedia[$key]]);
                ++$result['files_copied'];
                ++$result['assignments_updated'];
            } catch (RuntimeException $exception) {
                ++$result['skipped'];
                $result['errors'][] = 'Media assignment #' . (int) $assignment['id'] . ': ' . $exception->getMessage();
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $folder */
    private function folderPath(array $folder): string
    {
        return trim((string) ($folder['path'] ?? '')) ?: trim((string) ($folder['name'] ?? ''));
    }

    private function fileFolderPath($db, ?int $folderId): string
    {
        if ($folderId === null) {
            return '';
        }
        $folder = $db->table('folder')->select('name, path')->where('id', $folderId)->where('is_deleted', 0)->get()->getRowArray();

        return $folder === null ? '' : $this->folderPath($folder);
    }

    /** @param array<string, mixed> $actor */
    private function action($db, array $actor, string $action, ?int $requestId, ?int $assignmentId, int $fileCount, string $department, string $now, int $cycle = 0, ?string $transactionStatus = null, ?string $authorizationStatus = null, ?string $feedback = null, array $items = []): void
    {
        $db->table('distribution_actions')->insert([
            'request_id' => $requestId, 'assignment_id' => $assignmentId, 'action' => $action,
            'cycle_number' => $cycle, 'transaction_status' => $transactionStatus,
            'authorization_status' => $authorizationStatus, 'feedback' => $feedback,
            'items_json' => $items === [] ? null : json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'actor_id' => (int) ($actor['employee_id'] ?? 0),
            'actor_name' => (string) ($actor['name'] ?? 'Unknown user'),
            'actor_role' => (string) ($actor['role'] ?? 'unknown'), 'department' => $department,
            'file_count' => $fileCount, 'created_at' => $now,
        ]);
    }

    private function notifyCreativeAdmins($db, string $type, string $title, string $message, string $link, string $now): void
    {
        foreach ($db->table('user')->select('employee_id, role')->whereIn('role', ['super_admin', 'admin', 'staff'])->where('is_active', 1)->get()->getResultArray() as $user) {
            $db->table('notifications')->insert(['recipient_id' => (string) $user['employee_id'], 'recipient_role' => (string) $user['role'], 'type' => $type, 'title' => $title, 'message' => $message, 'link' => $link, 'is_read' => 0, 'created_at' => $now]);
        }
    }

    private function notifyDepartment($db, string $department, string $message, string $link, string $now, string $title = 'New media available'): void
    {
        foreach ($db->table('user')->select('employee_id, role')->where('department', $department)->where('is_active', 1)->get()->getResultArray() as $user) {
            $db->table('notifications')->insert(['recipient_id' => (string) $user['employee_id'], 'recipient_role' => (string) $user['role'], 'type' => 'media_assigned', 'title' => $title, 'message' => $message, 'link' => $link, 'is_read' => 0, 'created_at' => $now]);
        }
    }
}
