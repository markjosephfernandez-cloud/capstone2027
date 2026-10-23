<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDashboardViews extends Migration
{
    public function up()
    {
        // No aggregation here: department/ownership fields remain available
        // before COUNT/SUM. These ordinary views do not cache any results.
        foreach ([
            'vw_dashboard_active_files' => ['files', ''],
            'vw_dashboard_active_department_files' => ['department_files', ', department'],
        ] as $view => [$table, $scope]) {
            $this->db->query('CREATE VIEW ' . $this->identifier($view) . ' AS
                SELECT id, employee_id, folder_id, filetype, size, dateupload' . $scope . '
                FROM ' . $this->identifier($table) . ' WHERE is_deleted = 0');
        }

        $this->db->query('CREATE VIEW ' . $this->identifier('vw_dashboard_department_requests') . ' AS
            SELECT r.id, r.department, r.requested_by, r.transaction_type,
                r.title, r.status, r.authorization_status, r.submitted_at,
                requester.name AS requester_name
            FROM ' . $this->identifier('media_requests') . ' r
            LEFT JOIN ' . $this->identifier('user') . ' requester
                ON requester.employee_id = r.requested_by');
    }

    public function down()
    {
        foreach (['vw_dashboard_department_requests', 'vw_dashboard_active_department_files', 'vw_dashboard_active_files'] as $view) {
            $this->db->query('DROP VIEW IF EXISTS ' . $this->identifier($view));
        }
    }

    private function identifier(string $name): string
    {
        return $this->db->escapeIdentifiers($this->db->prefixTable($name));
    }
}
