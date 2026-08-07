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
        $activity = RolePolicy::isCreativeAdmin($actor) && $db->tableExists('audit_logs')
            ? $db->table('audit_logs')->select('user_name, action, description, created_at')->orderBy('created_at', 'DESC')->limit(5)->get()->getResultArray()
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
            'activity'   => $activity,
        ]);
    }
}
