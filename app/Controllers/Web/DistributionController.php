<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\RolePolicy;
use App\Models\FileModel;
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
        $direct = $db->table('media_assignments ma')->select('ma.department, ma.sent_by, ma.sent_at, ma.status, u.name AS sender_name, COUNT(*) AS file_count')->join('user u', 'u.employee_id = ma.sent_by', 'left')->where('ma.method', 'direct');
        if ($role === 'department') {
            $direct->where('ma.department', (string) ($actor['department'] ?? ''));
        }
        $direct = $direct->groupBy('ma.department, ma.sent_by, ma.sent_at, ma.status, u.name')->orderBy('ma.sent_at', 'DESC')->limit(100)->get()->getResultArray();
        $files = RolePolicy::allows($actor, 'files.assign') ? (new FileModel())->where('is_deleted', 0)->where('storage_stage', 'processed')->orderBy('dateupload', 'DESC')->findAll(100) : [];
        $departments = RolePolicy::allows($actor, 'files.assign') ? $db->table('user')->select('department')->where('department !=', '')->groupBy('department')->orderBy('department')->get()->getResultArray() : [];
        $actions = $db->table('distribution_actions')->orderBy('created_at', 'DESC')->limit(200)->get()->getResultArray();
        return view('distribution/index', ['user' => $actor, 'activePage' => 'distribution', 'title' => 'Distribution Workflow', 'requests' => $requests, 'directSends' => $direct, 'files' => $files, 'departments' => $departments, 'actions' => $actions, 'canManage' => RolePolicy::allows($actor, 'distribution.manage'), 'isDepartment' => $role === 'department', 'success' => session()->getFlashdata('success'), 'error' => session()->getFlashdata('error')]);
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
            service('audit')->record($actor, $this->request, 'distribution', 'REQUEST_SUBMITTED', 'media_request', 0, 'Submitted department request "' . $title . '".');
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
        $department = trim(mb_substr((string) $this->request->getPost('department'), 0, 150));
        if ($requestId !== null) {
            $request = db_connect()->table('media_requests')->where('id', $requestId)->where('status', 'submitted')->get()->getRowArray();
            if ($request === null) {
                return redirect()->to(site_url('workspace/distribution'))->with('error', 'This request has already been processed or is unavailable.');
            }
            $department = (string) $request['department'];
        }
        try {
            $count = service('distribution')->assign($ids, $department, $requestId, $method, $actor);
            if ($count < 1) {
                throw new RuntimeException('No new media assignments were created.');
            }
            service('audit')->record($actor, $this->request, 'distribution', $method === 'direct' ? 'DIRECT_SENT' : 'TRANSMITTED', $requestId === null ? 'assignment' : 'media_request', $requestId, ($method === 'direct' ? 'Direct sent ' : 'Transmitted ') . $count . ' media file(s) to ' . $department . '.');
            return redirect()->to(site_url('workspace/distribution?tab=' . ($method === 'direct' ? 'direct' : 'requests')))->with('success', $count . ' media file(s) sent to ' . $department . '.');
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('workspace/distribution'))->with('error', $exception->getMessage());
        }
    }
}
