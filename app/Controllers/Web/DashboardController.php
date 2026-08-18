<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\RolePolicy;

class DashboardController extends BaseController
{
    public function index()
    {
        if (session()->get('isLoggedIn') !== true) {
            return redirect()->to(site_url('login'));
        }

        helper('dashboard');
        $db         = db_connect();
        $actor      = session()->get();
        $departmentUser = RolePolicy::role($actor) === 'department';
        $assignedIds = $departmentUser ? array_map('intval', array_column($db->table('media_assignments')->select('media_id')->where('department', (string) ($actor['department'] ?? ''))->groupBy('media_id')->get()->getResultArray(), 'media_id')) : [];
        $visible = $db->table('files')->where('is_deleted', 0);
        if ($departmentUser) $assignedIds === [] ? $visible->where('id', 0) : $visible->whereIn('id', $assignedIds);
        $activeFiles = (int) $visible->countAllResults();
        $visible = $db->table('files')->where('is_deleted', 0);
        if ($departmentUser) $assignedIds === [] ? $visible->where('id', 0) : $visible->whereIn('id', $assignedIds);
        $totalBytes = (int) ($visible->selectSum('size')->get()->getRowArray()['size'] ?? 0);
        $totalFiles = $activeFiles;
        $categoriesBuilder = $db->table('files')->select('filetype, COUNT(*) AS file_count, COALESCE(SUM(size), 0) AS total_size')->where('is_deleted', 0);
        if ($departmentUser) $assignedIds === [] ? $categoriesBuilder->where('id', 0) : $categoriesBuilder->whereIn('id', $assignedIds);
        $categories = $categoriesBuilder->groupBy('filetype')
            ->orderBy('total_size', 'DESC')
            ->limit(6)
            ->get()
            ->getResultArray();
        $trendStart = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $trendStart = $trendStart->modify('-29 days');
        $uploadTrendBuilder = $db->table('files')
            ->select('DATE(`dateupload`) AS `upload_date`, COUNT(*) AS `upload_count`', false)
            ->where('is_deleted', 0)
            ->where('dateupload >=', $trendStart->format('Y-m-d 00:00:00'));
        if ($departmentUser) $assignedIds === [] ? $uploadTrendBuilder->where('id', 0) : $uploadTrendBuilder->whereIn('id', $assignedIds);
        $uploadRows = $uploadTrendBuilder
            ->groupBy('DATE(`dateupload`)', false)
            ->orderBy('upload_date', 'ASC')
            ->get()
            ->getResultArray();
        $uploadsByDate = [];
        foreach ($uploadRows as $uploadRow) {
            $uploadsByDate[(string) $uploadRow['upload_date']] = (int) $uploadRow['upload_count'];
        }
        $uploadTrend = [];
        for ($day = 0; $day < 30; $day++) {
            $date = $trendStart->modify('+' . $day . ' days');
            $dateKey = $date->format('Y-m-d');
            $uploadTrend[] = [
                'date'  => $dateKey,
                'label' => $date->format('M j'),
                'count' => $uploadsByDate[$dateKey] ?? 0,
            ];
        }
        $activity = RolePolicy::isCreativeAdmin($actor) && $db->tableExists('audit_logs')
            ? $db->table('audit_logs')->select('user_name, action, description, created_at')->orderBy('created_at', 'DESC')->limit(5)->get()->getResultArray()
            : [];
        $canManageDistribution = RolePolicy::allows($actor, 'distribution.manage');
        $departmentRequests = $canManageDistribution && $db->tableExists('media_requests')
            ? $db->table('media_requests r')
                ->select('r.id, r.department, r.title, r.status, r.submitted_at, requester.name AS requester_name')
                ->join('user requester', 'requester.employee_id = r.requested_by', 'left')
                ->orderBy('r.submitted_at', 'DESC')
                ->limit(5)
                ->get()
                ->getResultArray()
            : [];
        $storagePath = service('storage')->root();
        $diskTotal   = RolePolicy::isCreativeAdmin($actor) ? (@disk_total_space($storagePath) ?: 0) : $totalBytes;
        $diskFree    = RolePolicy::isCreativeAdmin($actor) ? (@disk_free_space($storagePath) ?: 0) : 0;
        $diskUsed    = max(0, $diskTotal - $diskFree);

        return view('dashboard', [
            'user'       => session()->get(),
            'activePage' => 'dashboard',
            'isAdmin'     => RolePolicy::isSuperAdmin($actor),
            'error'       => session()->getFlashdata('error'),
            'success'     => session()->getFlashdata('success'),
            'consentRequired' => service('consent')->requiresConsent(session()->get()),
            'stats'      => [
                'total_files'  => $totalFiles,
                'active_files' => $activeFiles,
                'total_bytes'  => $totalBytes,
                'disk_total'   => $diskTotal,
                'disk_free'    => $diskFree,
                'disk_used'    => $diskUsed,
            ],
            'categories' => $categories,
            'uploadTrend' => $uploadTrend,
            'activity'   => $activity,
            'canManageDistribution' => $canManageDistribution,
            'departmentRequests' => $departmentRequests,
        ]);
    }
}
