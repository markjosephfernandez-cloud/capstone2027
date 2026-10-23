<?php

use App\Database\Migrations\AddDashboardViews;
use App\Services\DashboardReadService;
use CodeIgniter\Test\CIUnitTestCase;

final class DashboardViewsTest extends CIUnitTestCase
{
    public function testScopesDatesEmptyResultsAndReversibleViews(): void
    {
        $db = db_connect();
        $this->assertSame(':memory:', $db->database);
        $forge = \Config\Database::forge($db);
        $schemas = [
            'files' => 'id employee_id folder_id filetype size dateupload is_deleted',
            'department_files' => 'id employee_id folder_id filetype size dateupload is_deleted department',
            'media_requests' => 'id department requested_by transaction_type title status authorization_status submitted_at',
            'user' => 'employee_id name',
            'audit_logs' => 'id user_id user_name action description created_at',
        ];
        require_once APPPATH . 'Database/Migrations/2026-10-17-000001_AddDashboardViews.php';
        $migration = new AddDashboardViews($forge);
        try {
            foreach ($schemas as $table => $columns) {
                $forge->dropTable($table, true);
                foreach (explode(' ', $columns) as $column) {
                    $forge->addField([$column => ['type' => in_array($column, ['id', 'employee_id', 'folder_id', 'size', 'is_deleted', 'requested_by']) ? 'INTEGER' : 'TEXT', 'null' => true]]);
                }
                $forge->createTable($table);
            }
            $migration->up();
            $service = new DashboardReadService($db);
            $admin = ['role' => 'admin', 'employee_id' => 7];
            $this->assertSame(['active_files' => 0, 'total_bytes' => 0], $service->totals($admin));
            $this->assertSame([], $service->categories($admin));
            $this->assertSame([], $service->departmentRequests($admin));
            $today = new DateTimeImmutable('2026-10-17', new DateTimeZone('UTC'));
            $this->assertCount(30, $service->uploadTrend($admin, $today));
            $this->assertSame(0, array_sum(array_column($service->uploadTrend($admin, $today), 'count')));

            foreach ([
                [1, 0, '2026-09-18 00:00:00', 'jpg', 10],
                [2, 0, '2026-09-17 23:59:59', 'jpg', 20],
                [3, 1, '2026-10-17 12:00:00', 'jpg', 1000],
                [4, null, '2026-10-17 12:00:00', 'jpg', 1000],
                [5, 0, null, null, 30],
                [6, 0, '2026-10-18 00:00:00', 'mp4', 40],
                [7, 0, '2026-10-17 23:59:59', 'jpg', 50],
            ] as [$id, $deleted, $date, $type, $size]) {
                $row = ['id' => $id, 'employee_id' => 7, 'filetype' => $type, 'size' => $size, 'dateupload' => $date, 'is_deleted' => $deleted];
                $db->table('files')->insert($row);
                // Department copies have independent IDs/provenance and deletion.
                $db->table('department_files')->insert($row + ['department' => 'A']);
            }
            $db->table('department_files')->insert(['id' => 99, 'department' => 'B', 'size' => 9999, 'is_deleted' => 0, 'dateupload' => '2026-10-17 12:00:00']);
            foreach (['super_admin', 'admin', 'staff', 'writer', 'department', 'unknown'] as $role) {
                $actor = ['role' => $role, 'employee_id' => 888, 'department' => 'A'];
                $this->assertSame(['active_files' => 5, 'total_bytes' => 150], $service->totals($actor));
                $trend = $service->uploadTrend($actor, $today);
                $this->assertCount(30, $trend);
                $this->assertSame(1, $trend[0]['count']);
                $this->assertSame(1, $trend[29]['count']);
                $this->assertSame(2, array_sum(array_column($trend, 'count')));
                $this->assertSame(150, array_sum(array_column($service->categories($actor), 'total_size')));
            }
            $this->assertSame(9999, $service->totals(['role' => 'department', 'department' => 'B'])['total_bytes']);
            $this->assertSame(0, $service->totals(['role' => 'department'])['active_files']);
            $db->table('user')->insert(['employee_id' => 7, 'name' => 'Requester']);
            for ($id = 1; $id <= 7; $id++) {
                $db->table('audit_logs')->insert(['id' => $id, 'user_id' => $id % 2 ? '7' : '8', 'created_at' => '2026-10-17 12:00:00']);
                $db->table('media_requests')->insert(['id' => $id, 'department' => 'B', 'requested_by' => $id === 7 ? 999 : 7, 'transaction_type' => $id % 2 ? 'direct' : 'request', 'submitted_at' => '2026-10-17 12:00:0' . $id]);
            }
            foreach (['admin', 'staff', 'writer', 'department', 'unknown'] as $role) {
                $this->assertSame([7, 5, 3, 1], array_map('intval', array_column($service->recentActivity(['role' => $role, 'employee_id' => 7]), 'id')));
            }
            $this->assertSame([7, 6, 5, 4, 3], array_map('intval', array_column($service->recentActivity(['role' => 'super_admin']), 'id')));
            foreach (['', 'abc', '0', '-1', '7 OR 1=1'] as $id) {
                $this->assertSame([], $service->recentActivity(['role' => 'admin', 'employee_id' => $id]));
            }
            foreach (['department', 'writer', 'unknown'] as $role) {
                $this->assertSame([], $service->departmentRequests(['role' => $role]));
            }
            foreach (['super_admin', 'admin', 'staff'] as $role) {
                $requests = $service->departmentRequests(['role' => $role]);
                $this->assertSame([7, 6, 5, 4, 3], array_map('intval', array_column($requests, 'id')));
                $this->assertNull($requests[0]['requester_name']);
                $this->assertSame('Requester', $requests[1]['requester_name']);
            }
            $migration->down();
            $this->assertSame([], $db->query("SELECT name FROM sqlite_master WHERE type = 'view' AND name LIKE '%vw_dashboard_%'")->getResultArray());
            $this->assertSame(7, $db->table('files')->countAllResults());
            $migration->up();
            $this->assertSame(5, $service->totals($admin)['active_files']);
        } finally {
            $migration->down();
            foreach (array_keys($schemas) as $table) {
                $forge->dropTable($table, true);
            }
            $db->resetDataCache();
        }
    }
}
