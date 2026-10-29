<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\RolePolicy;

class DashboardController extends BaseController
{
    /** Return only the current user's activity unless they are a Super Admin. */
    protected function recentActivity(array $actor): array
    {
        $db = db_connect();
        if (! $db->tableExists('audit_logs')) {
            return [];
        }
        $activity = $db->table('audit_logs')->select('id, user_name, action, description, created_at');
        if (! RolePolicy::isSuperAdmin($actor)) {
            $actorId = (string) ($actor['employee_id'] ?? '');
            if (! ctype_digit($actorId) || (int) $actorId < 1) {
                return [];
            }
            $activity->where('user_id', $actorId);
        }

        return $activity->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->limit(5)->get()->getResultArray();
    }

    protected function storageStats(array $actor, int $totalBytes): array
    {
        try {
            $storagePath = service('storage')->root();
            $diskTotal = RolePolicy::isCreativeAdmin($actor) ? @disk_total_space($storagePath) : $totalBytes;
            $diskFree = RolePolicy::isCreativeAdmin($actor) ? @disk_free_space($storagePath) : 0;
            if ($diskTotal === false || $diskFree === false) {
                throw new \RuntimeException('Storage capacity is unavailable.');
            }

            return ['disk_total' => $diskTotal, 'disk_free' => $diskFree, 'disk_used' => max(0, $diskTotal - $diskFree), 'storage_available' => true];
        } catch (\RuntimeException $exception) {
            log_message('warning', 'Dashboard storage unavailable: {message}', ['message' => $exception->getMessage()]);

            return ['disk_total' => 0, 'disk_free' => 0, 'disk_used' => 0, 'storage_available' => false];
        }
    }

    public function index()
    {
        if (session()->get('isLoggedIn') !== true) {
            return redirect()->to(site_url('login'));
        }

        helper('dashboard');
        $db = db_connect();
        $actor = session()->get();
        $departmentUser = RolePolicy::role($actor) === 'department';
        $fileTable = $departmentUser ? 'department_files' : 'files';
        $summaryQuery = $db->table('vw_dashboard_summary')
            ->where('library_scope', $departmentUser ? 'department' : 'central');
        if ($departmentUser) $summaryQuery->where('department', (string)($actor['department'] ?? ''));
        $summary = $summaryQuery->get()->getRowArray();
        $activeFiles = (int) ($summary['total_files'] ?? 0);
        $totalBytes = (int) ($summary['total_bytes'] ?? 0);
        $totalFiles = $activeFiles;
        $categoriesBuilder = $db->table($fileTable)->select('filetype, COUNT(*) AS file_count, COALESCE(SUM(size), 0) AS total_size')->where('is_deleted', 0);
        if ($departmentUser) $categoriesBuilder->where('department', (string)($actor['department'] ?? ''));
        $categories = $categoriesBuilder->groupBy('filetype')
            ->orderBy('total_size', 'DESC')
            ->limit(6)
            ->get()
            ->getResultArray();
        $trendStart = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $trendStart = $trendStart->modify('-29 days');
        $uploadTrendBuilder = $db->table($fileTable)
            ->select('DATE(`dateupload`) AS `upload_date`, COUNT(*) AS `upload_count`', false)
            ->where('is_deleted', 0)
            ->where('dateupload >=', $trendStart->format('Y-m-d 00:00:00'));
        if ($departmentUser) $uploadTrendBuilder->where('department', (string)($actor['department'] ?? ''));
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
        $activity = $this->recentActivity($actor);
        $canManageDistribution = RolePolicy::allows($actor, 'distribution.manage');
        $departmentRequests = $canManageDistribution && $db->tableExists('media_requests')
            ? $db->table('media_requests r')
                ->select('r.id, r.department, r.title, r.status, r.authorization_status, r.submitted_at, requester.name AS requester_name')
                ->join('user requester', 'requester.employee_id = r.requested_by', 'left')
                ->orderBy('r.submitted_at', 'DESC')
                ->limit(5)
                ->get()
                ->getResultArray()
            : [];
        $storageStats = $this->storageStats($actor, $totalBytes);

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
                ...$storageStats,
            ],
            'categories' => $categories,
            'uploadTrend' => $uploadTrend,
            'activity'   => $activity,
            'canManageDistribution' => $canManageDistribution,
            'departmentRequests' => $departmentRequests,
        ]);
    }
}
