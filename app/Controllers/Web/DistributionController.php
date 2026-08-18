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
        if ($role === 'department') {
            $requests->where('r.department', (string) ($actor['department'] ?? ''));
        }
        $requests = $requests->groupBy('r.id')->orderBy('r.updated_at', 'DESC')->limit(100)->get()->getResultArray();
        $requestFiles = $this->requestFiles(array_map(static fn (array $request): int => (int) $request['id'], $requests));
        $direct = $db->table('media_assignments ma')->select('ma.department, ma.sent_by, ma.sent_at, ma.status, u.name AS sender_name, COUNT(*) AS file_count')->join('user u', 'u.employee_id = ma.sent_by', 'left')->where('ma.method', 'direct');
        if ($role === 'department') {
            $direct->where('ma.department', (string) ($actor['department'] ?? ''));
        }
        $direct = $direct->groupBy('ma.department, ma.sent_by, ma.sent_at, ma.status, u.name')->orderBy('ma.sent_at', 'DESC')->limit(100)->get()->getResultArray();
        $files = RolePolicy::allows($actor, 'files.assign') ? (new FileModel())->where('is_deleted', 0)->orderBy('dateupload', 'DESC')->findAll(500) : [];
        $folders = RolePolicy::allows($actor, 'files.assign') ? $db->table('folder fo')
            ->select('fo.id, fo.parent_id, fo.name, fo.path, COUNT(DISTINCT f.id) AS file_count, (SELECT COUNT(*) FROM folder child WHERE child.parent_id = fo.id AND child.is_deleted = 0) AS subfolder_count', false)
            ->join('files f', 'f.folder_id = fo.id AND f.is_deleted = 0', 'left')
            ->where('fo.is_deleted', 0)
            ->groupBy('fo.id, fo.parent_id, fo.name, fo.path')
            ->orderBy('fo.name', 'ASC')
            ->get()
            ->getResultArray() : [];
        $departments = RolePolicy::allows($actor, 'files.assign') ? $db->table('user')->select('department')->where('department !=', '')->groupBy('department')->orderBy('department')->get()->getResultArray() : [];
        $actions = $db->table('distribution_actions')->orderBy('created_at', 'DESC')->limit(200)->get()->getResultArray();
        return view('distribution/index', ['user' => $actor, 'activePage' => 'distribution', 'title' => 'Distribution Workflow', 'requests' => $requests, 'requestFiles' => $requestFiles, 'directSends' => $direct, 'files' => $files, 'folders' => $folders, 'departments' => $departments, 'actions' => $actions, 'canManage' => RolePolicy::allows($actor, 'distribution.manage'), 'isDepartment' => $role === 'department', 'focusedRequestId' => $focusedRequestId, 'success' => session()->getFlashdata('success'), 'error' => session()->getFlashdata('error')]);
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
        $db = db_connect();
        $request = $db->table('media_requests')->where('id', $requestId)->where('department', (string) ($actor['department'] ?? ''))->where('status', 'transmitted')->get()->getRowArray();
        if ($request === null) {
            return redirect()->to(site_url('workspace/distribution'))->with('error', 'The transmitted request is unavailable.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $count = (int) $db->table('media_assignments')->where('request_id', $requestId)->countAllResults();
        $db->transStart();
        $db->table('media_requests')->where('id', $requestId)->update(['status' => 'completed', 'completed_by' => (int) $actor['employee_id'], 'completed_at' => $now, 'updated_at' => $now]);
        $db->table('media_assignments')->where('request_id', $requestId)->update(['status' => 'completed', 'completed_by' => (int) $actor['employee_id'], 'completed_at' => $now]);
        if ($db->tableExists('media_assignment_folders')) {
            $db->table('media_assignment_folders')->where('request_id', $requestId)->update([
                'status' => 'completed',
                'completed_by' => (int) $actor['employee_id'],
                'completed_at' => $now,
            ]);
        }
        $db->table('distribution_actions')->insert(['request_id' => $requestId, 'assignment_id' => null, 'action' => 'completed', 'actor_id' => (int) $actor['employee_id'], 'actor_name' => (string) $actor['name'], 'actor_role' => (string) $actor['role'], 'department' => (string) $actor['department'], 'file_count' => $count, 'created_at' => $now]);
        $db->transComplete();
        service('audit')->record($actor, $this->request, 'distribution', 'REQUEST_COMPLETED', 'media_request', $requestId, 'Completed receipt of ' . $count . ' assigned media file(s).');
        return redirect()->to(site_url('workspace/distribution'))->with('success', 'Request marked completed.');
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
            $request = db_connect()->table('media_requests')->where('id', $requestId)->where('status', 'submitted')->get()->getRowArray();
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
            ->select('ma.request_id, f.id, f.display_title, f.filename, f.filetype, f.mime_type, f.folder_id, fo.name AS folder_name')
            ->join('files f', 'f.id = ma.media_id', 'inner')
            ->join('folder fo', 'fo.id = f.folder_id', 'left')
            ->whereIn('ma.request_id', $requestIds)
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
