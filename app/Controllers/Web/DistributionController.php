<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\MediaTitle;
use App\Libraries\RolePolicy;
use App\Models\FileModel;
use App\Models\FolderModel;
use RuntimeException;

class DistributionController extends BaseController
{
    public function index()
    {
        if (session()->get('isLoggedIn') !== true) {
            return redirect()->to(site_url('login'));
        }
        $actor = session()->get();
        $role = RolePolicy::role($actor);
        if (! RolePolicy::allows($actor, 'distribution.manage') && ! RolePolicy::allows($actor, 'requests.manage_own')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Distribution Workflow is not available for your role.');
        }
        $focusedRequestId = filter_var($this->request->getGet('request'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $focusedRequestId = $focusedRequestId === false ? null : (int) $focusedRequestId;
        $db = db_connect();
        $requests = $db->table('media_requests r')
            ->select('r.*, requester.name AS requester_name, transmitter.name AS transmitter_name, COUNT(DISTINCT ma.media_id) AS file_count')
            ->join('user requester', 'requester.employee_id = r.requested_by', 'left')
            ->join('user transmitter', 'transmitter.employee_id = r.transmitted_by', 'left')
            ->join('media_assignments ma', 'ma.request_id = r.id', 'left');
        $requests->where('r.transaction_type', 'request');
        if ($role === 'department') {
            $requests->where('r.department', (string) ($actor['department'] ?? ''));
        }
        $requests = $requests->groupBy('r.id')->orderBy('r.updated_at', 'DESC')->limit(100)->get()->getResultArray();
        $requestFiles = $this->requestFiles(array_map(static fn (array $request): int => (int) $request['id'], $requests));
        $direct = $db->table('media_requests r')
            ->select('r.id, r.transaction_code, r.department, r.status, r.authorization_status, r.current_cycle, r.submitted_at AS sent_at, u.name AS sender_name, COUNT(DISTINCT ma.media_id) AS file_count')
            ->join('user u', 'u.employee_id = r.original_sender', 'left')
            ->join('media_assignments ma', 'ma.request_id = r.id', 'left')
            ->where('r.transaction_type', 'direct');
        if ($role === 'department') {
            $direct->where('r.department', (string) ($actor['department'] ?? ''));
        }
        $direct = $direct->groupBy('r.id, r.transaction_code, r.department, r.status, r.authorization_status, r.current_cycle, r.submitted_at, u.name')->orderBy('r.updated_at', 'DESC')->limit(100)->get()->getResultArray();
        $requestFiles += $this->requestFiles(array_map(static fn (array $transaction): int => (int) $transaction['id'], $direct));
        $files = RolePolicy::allows($actor, 'files.assign') ? (new FileModel())->where('is_deleted', 0)->orderBy('dateupload', 'DESC')->findAll(500) : [];
        $folders = RolePolicy::allows($actor, 'files.assign') ? $db->table('folder fo')
            ->select('fo.id, fo.parent_id, fo.name, fo.path, COUNT(DISTINCT f.id) AS file_count, (SELECT COUNT(*) FROM folder child WHERE child.parent_id = fo.id AND child.is_deleted = 0) AS subfolder_count', false)
            ->join('files f', 'f.folder_id = fo.id AND f.is_deleted = 0', 'left')
            ->where('fo.is_deleted', 0)
            ->groupBy('fo.id, fo.parent_id, fo.name, fo.path')
            ->orderBy('fo.name', 'ASC')
            ->get()
            ->getResultArray() : [];
        $departments = RolePolicy::allows($actor, 'files.assign')
            ? $db->table('user')->select('department')
                ->where('role', 'department')
                ->where('department !=', '')
                ->groupBy('department')->orderBy('department')->get()->getResultArray()
            : [];
        $actionQuery = $db->table('distribution_actions')->orderBy('created_at', 'DESC')->limit(500);
        if ($role === 'department') $actionQuery->where('department', (string) ($actor['department'] ?? ''));
        $actions = $actionQuery->get()->getResultArray();
        $historyByRequest = [];
        foreach ($actions as $action) if (! empty($action['request_id'])) $historyByRequest[(int) $action['request_id']][] = $action;
        foreach ($historyByRequest as &$requestHistory) {
            $requestHistory = $this->withDisplayCycles($requestHistory);
        }
        unset($requestHistory);
        return view('distribution/index', ['user' => $actor, 'activePage' => 'distribution', 'title' => 'Distribution Workflow', 'requests' => $requests, 'requestFiles' => $requestFiles, 'directSends' => $direct, 'files' => $files, 'folders' => $folders, 'departments' => $departments, 'actions' => $actions, 'historyByRequest' => $historyByRequest, 'canManage' => RolePolicy::allows($actor, 'distribution.manage'), 'isDepartment' => $role === 'department', 'focusedRequestId' => $focusedRequestId, 'success' => session()->getFlashdata('success'), 'error' => session()->getFlashdata('error')]);
    }

    /**
     * Supplies reliable labels for legacy action rows whose cycle_number is zero.
     * The returned order remains newest-first for the preview timeline.
     *
     * @param list<array<string, mixed>> $history
     * @return list<array<string, mixed>>
     */
    private function withDisplayCycles(array $history): array
    {
        $cycle = 0;
        $startNextCycle = true;

        foreach (array_reverse(array_keys($history)) as $index) {
            $action = strtolower((string) ($history[$index]['action'] ?? ''));
            $storedCycle = max(0, (int) ($history[$index]['cycle_number'] ?? 0));

            if ($action === 'submitted') {
                $history[$index]['display_cycle'] = null;
                $history[$index]['display_action'] = 'submitted';
                continue;
            }

            if (in_array($action, ['original', 'resubmission', 'transmitted', 'direct_sent'], true)) {
                if ($startNextCycle) {
                    $cycle = max($cycle + 1, $storedCycle, 1);
                } else {
                    $cycle = max($cycle, $storedCycle, 1);
                }
                $startNextCycle = false;
            } else {
                $cycle = max($cycle, $storedCycle, 1);
            }

            $history[$index]['display_cycle'] = $cycle;
            $history[$index]['display_action'] = $action === 'original' && $cycle > 1 ? 'resubmission' : $action;

            if ($action === 'marked_incomplete') {
                $startNextCycle = true;
            }
        }

        return $history;
    }

    public function requestMedia()
    {
        $actor = session()->get();
        if (session()->get('isLoggedIn') !== true || ! RolePolicy::allows($actor, 'requests.manage_own')) {
            return redirect()->to(site_url('workspace/file-management'))->with('error', 'Only department accounts can submit media requests.');
        }
        $title = trim(mb_substr((string) $this->request->getPost('title'), 0, 180));
        $description = trim(mb_substr((string) $this->request->getPost('description'), 0, 4000));
        if ($title === '') {
            return redirect()->to(site_url('workspace/distribution'))->with('error', 'Enter an event or request title.');
        }
        try {
            service('distribution')->createRequest($actor, $title, $description);
            service('audit')->record($actor, $this->request, 'distribution', 'REQUEST_MEDIA', 'media_request', 0, 'Requested media "' . $title . '" for ' . (string) ($actor['department'] ?? 'Unknown department') . '.');
            return redirect()->to(site_url('workspace/distribution'))->with('success', 'Media request submitted to Creative Staff.');
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('workspace/distribution'))->with('error', $exception->getMessage());
        }
    }

    public function transmit(int $requestId)
    {
        return $this->assignFromPost('request', $requestId);
    }

    public function directSend()
    {
        return $this->assignFromPost('direct', null);
    }

    public function complete(int $requestId)
    {
        $actor = session()->get();
        if (session()->get('isLoggedIn') !== true || ! RolePolicy::allows($actor, 'requests.manage_own')) {
            return redirect()->to(site_url('workspace/distribution'))->with('error', 'Only the receiving department can complete this request.');
        }
        try {
            service('distribution')->authorize($requestId, $actor, (string) $this->request->getPost('feedback'));
            service('audit')->record($actor, $this->request, 'distribution', 'TRANSACTION_AUTHORIZED', 'media_request', $requestId, 'Department confirmed the transaction complete with feedback.');
            return redirect()->to(site_url('workspace/distribution?request=' . $requestId))->with('success', 'Transaction completed and authorized for Department File Management.');
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('workspace/distribution?request=' . $requestId))->with('error', $exception->getMessage());
        }
    }

    public function incomplete(int $requestId)
    {
        $actor = session()->get();
        if (session()->get('isLoggedIn') !== true || ! RolePolicy::allows($actor, 'requests.manage_own')) {
            return redirect()->to(site_url('workspace/distribution'))->with('error', 'Only the receiving department can review this transaction.');
        }
        try {
            service('distribution')->markIncomplete($requestId, $actor, (string) $this->request->getPost('feedback'));
            service('audit')->record($actor, $this->request, 'distribution', 'TRANSACTION_INCOMPLETE', 'media_request', $requestId, 'Department marked the transaction incomplete with feedback.');
            return redirect()->to(site_url('workspace/distribution?request=' . $requestId))->with('success', 'The Admin was notified that corrections are required.');
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('workspace/distribution?request=' . $requestId))->with('error', $exception->getMessage());
        }
    }

    /** Serves media only inside an authorized transaction review. */
    public function reviewPreview(int $requestId, int $fileId)
    {
        if (session()->get('isLoggedIn') !== true) return redirect()->to(site_url('login'));
        $actor = session()->get();
        $db = db_connect();
        $request = $db->table('media_requests')->where('id', $requestId)->get()->getRowArray();
        $allowed = $request !== null && (RolePolicy::allows($actor, 'distribution.manage') || (RolePolicy::allows($actor, 'requests.manage_own') && (string) $request['department'] === (string) ($actor['department'] ?? '')));
        if (! $allowed || $db->table('media_assignments')->where('request_id', $requestId)->where('media_id', $fileId)->countAllResults() === 0) {
            return $this->response->setStatusCode(403)->setBody('This file is not available in your transaction review.');
        }
        $assignment = $db->table('media_assignments')->where('request_id',$requestId)->where('media_id',$fileId)->orderBy('id','DESC')->get()->getRowArray();
        $file = ($assignment['status'] ?? '') === 'completed'
            ? $db->table('department_files')->where('source_assignment_id',$assignment['id'])->where('department',$request['department'])->where('is_deleted',0)->get()->getRowArray()
            : (new FileModel())->where('is_deleted', 0)->find($fileId);
        if ((new \Config\Storage())->centralized() && $file && ($assignment['status'] ?? '') !== 'completed') {
            $file['filepath'] = $assignment['delivery_filepath'] ?? '';
        }
        if ($file === null) return $this->response->setStatusCode(404)->setBody('File not found.');
        try {
            $path = service('storage')->resolveDatabaseFile((string) $file['filepath']);
        } catch (RuntimeException) {
            return $this->response->setStatusCode(404)->setBody('File not found.');
        }
        session()->close();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($this->request->getGet('thumbnail') === '1') {
            try {
                $thumbnail = is_string($mime) && str_starts_with($mime, 'video/')
                    ? (new \App\Services\VideoPreviewService())->thumbnailForFile($path)
                    : (new \App\Services\ThumbnailService())->forFile($path);
                $etag = '"' . pathinfo($thumbnail, PATHINFO_FILENAME) . '"';
                $response = $this->response->removeHeader('Cache-Control')->removeHeader('Pragma')->removeHeader('Expires')
                    ->setHeader('Cache-Control', 'private, no-cache, must-revalidate')
                    ->setHeader('Vary', 'Cookie')->setHeader('ETag', $etag)
                    ->setHeader('X-Content-Type-Options', 'nosniff');
                $validators = array_map('trim', explode(',', $this->request->getHeaderLine('If-None-Match')));
                if (in_array($etag, $validators, true) || in_array('W/' . $etag, $validators, true) || in_array('*', $validators, true)) {
                    return $response->setStatusCode(304)->setBody('');
                }
                $body = file_get_contents($thumbnail);
                if ($body === false) throw new RuntimeException('Thumbnail unavailable.');
                return $response->setContentType('image/jpeg')->setHeader('Content-Length', (string) strlen($body))->setBody($body);
            } catch (RuntimeException $exception) {
                log_message('warning', 'Transaction thumbnail unavailable: {message}', ['message' => $exception->getMessage()]);
                return $this->response->setStatusCode(404)->setBody('Thumbnail unavailable.')->noCache();
            }
        }
        if (! is_string($mime) || ! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return $this->response->setStatusCode(404)->setBody('Preview is unavailable for this file type.');
        }
        return $this->response->setHeader('Content-Type', $mime)->setHeader('Content-Disposition', 'inline')->setBody((string) file_get_contents($path))->noCache();
    }

    private function assignFromPost(string $method, ?int $requestId)
    {
        $actor = session()->get();
        if (session()->get('isLoggedIn') !== true || ! RolePolicy::allows($actor, 'files.assign')) {
            return redirect()->to(site_url('workspace/distribution'))->with('error', 'Only Creative Staff can distribute media.');
        }
        $ids = array_values(array_filter(array_map('intval', (array) $this->request->getPost('file_ids'))));
        $folderIds = array_values(array_filter(array_map('intval', (array) $this->request->getPost('folder_ids'))));
        $selectedFolderNames = $this->folderNames($folderIds);
        if ($folderIds !== []) {
            $folderIds = $this->expandedFolderIds($folderIds);
            $ids = array_values(array_unique(array_merge($ids, $this->fileIdsFromFolders($folderIds))));
        }
        $department = trim(mb_substr((string) $this->request->getPost('department'), 0, 150));
        if ($requestId !== null) {
            $request = db_connect()->table('media_requests')->where('id', $requestId)->whereIn('status', ['submitted', 'pending_incomplete'])->get()->getRowArray();
            if ($request === null) {
                return redirect()->to(site_url('workspace/distribution'))->with('error', 'This request has already been processed or is unavailable.');
            }
            $department = (string) $request['department'];
        }
        try {
            $fileNames = $this->mediaNames($ids);
            $delivery = service('distribution')->assign($ids, $folderIds, $department, $requestId, $method, $actor);
            $fileCount = (int) $delivery['file_count'];
            $folderCount = (int) $delivery['folder_count'];
            if ($fileCount + $folderCount < 1) {
                throw new RuntimeException('No new file or folder assignments were created.');
            }
            $action = $method === 'direct' ? 'DIRECT_SENT' : 'TRANSMITTED';
            $verb = $method === 'direct' ? 'Direct sent' : 'Transmitted';
            $files = $fileNames === [] ? $fileCount . ' media file(s)' : implode(', ', array_slice($fileNames, 0, 8)) . (count($fileNames) > 8 ? ', and more' : '');
            $folders = $selectedFolderNames === [] ? '' : ' Folders: ' . implode(', ', $selectedFolderNames) . '.';
            $summary = $folderCount . ' folder' . ($folderCount === 1 ? '' : 's') . ' and ' . $fileCount . ' media file' . ($fileCount === 1 ? '' : 's');
            service('audit')->record($actor, $this->request, 'distribution', $action, $requestId === null ? 'assignment' : 'media_request', $requestId, $verb . ' ' . $summary . ' to ' . $department . ' by ' . (string) ($actor['name'] ?? 'Unknown user') . '. Files: ' . $files . '.' . $folders);
            return redirect()->to(site_url('workspace/distribution?tab=' . ($method === 'direct' ? 'direct' : 'requests')))->with('success', ucfirst($verb) . ' ' . $summary . ' to ' . $department . '.');
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('workspace/distribution'))->with('error', $exception->getMessage());
        }
    }

    /** @param list<int> $ids @return list<string> */
    private function mediaNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach ((new FileModel())->whereIn('id', $ids)->findAll() as $file) {
            $names[] = MediaTitle::forFile($file);
        }

        return $names;
    }

    /** @param list<int> $folderIds @return list<string> */
    private function folderNames(array $folderIds): array
    {
        $folderIds = array_values(array_unique(array_filter(array_map('intval', $folderIds), static fn (int $id): bool => $id > 0)));
        if ($folderIds === []) {
            return [];
        }

        return array_values(array_map(static fn (array $folder): string => (string) $folder['name'], (new FolderModel())->where('is_deleted', 0)->whereIn('id', $folderIds)->findAll()));
    }

    /** @param list<int> $requestIds @return array<int, list<array<string, mixed>>> */
    private function requestFiles(array $requestIds): array
    {
        $requestIds = array_values(array_unique(array_filter($requestIds, static fn (int $id): bool => $id > 0)));
        if ($requestIds === []) {
            return [];
        }

        $rows = db_connect()->table('media_assignments ma')
            ->select('ma.request_id, ma.media_id AS id, ma.cycle_number, COALESCE(df.display_title, f.display_title) AS display_title, COALESCE(df.filename, f.filename) AS filename, COALESCE(df.filetype, f.filetype) AS filetype, COALESCE(df.mime_type, f.mime_type) AS mime_type, COALESCE(df.folder_id, f.folder_id) AS folder_id, COALESCE(dfo.name, fo.name) AS folder_name', false)
            ->join('files f', 'f.id = ma.media_id', 'left')
            ->join('department_files df', 'df.source_assignment_id = ma.id AND df.department = ma.department', 'left')
            ->join('department_folders dfo', 'dfo.id = df.folder_id', 'left')
            ->join('folder fo', 'fo.id = f.folder_id', 'left')
            ->whereIn('ma.request_id', $requestIds)
            ->groupStart()->where('f.id IS NOT NULL', null, false)->orWhere('df.id IS NOT NULL', null, false)->groupEnd()
            ->orderBy('fo.name', 'ASC')
            ->orderBy('f.display_title', 'ASC')
            ->get()
            ->getResultArray();

        $files = [];
        foreach ($rows as $row) {
            $row['media_title'] = MediaTitle::forFile($row);
            $files[(int) $row['request_id']][] = $row;
        }

        return $files;
    }

    /** @param list<int> $folderIds @return list<int> */
    private function fileIdsFromFolders(array $folderIds): array
    {
        $folderIds = $this->expandedFolderIds($folderIds);
        if ($folderIds === []) {
            return [];
        }

        $rows = (new FileModel())
            ->select('id')
            ->where('is_deleted', 0)
            ->whereIn('folder_id', $folderIds)
            ->findAll();

        return array_values(array_map(static fn (array $file): int => (int) $file['id'], $rows));
    }

    /** @param list<int> $folderIds @return list<int> */
    private function expandedFolderIds(array $folderIds): array
    {
        $folderIds = array_values(array_unique(array_filter(array_map('intval', $folderIds), static fn (int $id): bool => $id > 0)));
        if ($folderIds === []) {
            return [];
        }

        $allFolders = (new FolderModel())->where('is_deleted', 0)->findAll();
        $children = [];
        foreach ($allFolders as $folder) {
            $parent = empty($folder['parent_id']) ? 0 : (int) $folder['parent_id'];
            $children[$parent][] = (int) $folder['id'];
        }

        $expanded = [];
        $stack = $folderIds;
        while ($stack !== []) {
            $id = (int) array_pop($stack);
            if (isset($expanded[$id])) {
                continue;
            }
            $expanded[$id] = true;
            foreach ($children[$id] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }

        return array_values(array_map('intval', array_keys($expanded)));
    }
}
