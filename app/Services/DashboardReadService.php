<?php

namespace App\Services;

use App\Libraries\RolePolicy;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/** Dashboard reads only. Views retain rows so authorization precedes aggregation. */
final class DashboardReadService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    private function visibleFiles(array $actor): BaseBuilder
    {
        if (RolePolicy::role($actor) === 'department') {
            return $this->db->table('vw_dashboard_active_department_files')
                ->where('department', (string) ($actor['department'] ?? ''));
        }

        // All other recognized roles have files.view_all in the existing policy.
        return $this->db->table('vw_dashboard_active_files');
    }

    /**
     * @param array<string, mixed> $actor
     * @return array{active_files: int, total_bytes: int}
     */
    public function totals(array $actor): array
    {
        $row = $this->visibleFiles($actor)
            ->select('COUNT(*) AS active_files, COALESCE(SUM(size), 0) AS total_bytes')
            ->get()->getRowArray();

        return [
            'active_files' => (int) ($row['active_files'] ?? 0),
            'total_bytes'  => (int) ($row['total_bytes'] ?? 0),
        ];
    }

    public function categories(array $actor): array
    {
        return $this->visibleFiles($actor)
            ->select('filetype, COUNT(*) AS file_count, COALESCE(SUM(size), 0) AS total_size')
            ->groupBy('filetype')->orderBy('total_size', 'DESC')->limit(6)
            ->get()->getResultArray();
    }

    /**
     * Zero-fills the 30 UTC calendar dates ending on the supplied day.
     *
     * @param array<string, mixed> $actor
     * @return list<array{date: string, label: string, count: int}>
     */
    public function uploadTrend(array $actor, ?DateTimeImmutable $today = null): array
    {
        $trendStart = ($today ?? new DateTimeImmutable('today', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->modify('-29 days');
        // Preserve dateupload and the original inclusive lower bound. Only the
        // 30 displayed dates are emitted below, even if future records exist.
        $uploadRows = $this->visibleFiles($actor)
            ->select('DATE(dateupload) AS upload_date, COUNT(*) AS upload_count', false)
            ->where('dateupload >=', $trendStart->format('Y-m-d 00:00:00'))
            ->groupBy('DATE(dateupload)', false)->orderBy('upload_date', 'ASC')
            ->get()->getResultArray();
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

        return $uploadTrend;
    }

    public function recentActivity(array $actor): array
    {
        if (! $this->db->tableExists('audit_logs')) {
            return [];
        }
        $activity = $this->db->table('audit_logs')->select('id, user_name, action, description, created_at');
        if (! RolePolicy::isSuperAdmin($actor)) {
            $actorId = (string) ($actor['employee_id'] ?? '');
            if (! ctype_digit($actorId) || (int) $actorId < 1) {
                return [];
            }
            $activity->where('user_id', $actorId);
        }

        return $activity->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->limit(5)->get()->getResultArray();
    }

    public function departmentRequests(array $actor): array
    {
        if (! RolePolicy::allows($actor, 'distribution.manage') || ! $this->db->tableExists('media_requests')) {
            return [];
        }

        return $this->db->table('vw_dashboard_department_requests')
            ->select('id, department, title, status, authorization_status, submitted_at, requester_name')
            ->orderBy('submitted_at', 'DESC')->limit(5)->get()->getResultArray();
    }
}
