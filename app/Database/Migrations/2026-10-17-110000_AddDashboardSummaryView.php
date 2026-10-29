<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDashboardSummaryView extends Migration
{
    public function up()
    {
        $table = fn (string $name): string => $this->db->escapeIdentifiers($this->db->prefixTable($name));
        $view = $table('vw_dashboard_summary');
        $files = $table('files');
        $departmentFiles = $table('department_files');
        $this->db->query("CREATE VIEW {$view} AS
            SELECT 'central' AS library_scope, NULL AS department,
                COUNT(*) AS total_files, COALESCE(SUM(size), 0) AS total_bytes
            FROM {$files} WHERE is_deleted = 0
            UNION ALL
            SELECT 'department' AS library_scope, department,
                COUNT(*) AS total_files, COALESCE(SUM(size), 0) AS total_bytes
            FROM {$departmentFiles} WHERE is_deleted = 0 GROUP BY department");
    }

    public function down()
    {
        $this->db->query('DROP VIEW IF EXISTS ' . $this->db->escapeIdentifiers($this->db->prefixTable('vw_dashboard_summary')));
    }
}
