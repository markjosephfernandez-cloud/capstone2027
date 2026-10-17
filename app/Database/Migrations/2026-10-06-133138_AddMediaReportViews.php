<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMediaReportViews extends Migration
{
    public function up()
    {
        $table = fn (string $name): string => $this->db->escapeIdentifiers($this->db->prefixTable($name));
        $files = $table('files');
        $users = $table('user');
        $passports = $table('passports');
        $media = $table('server_media');
        $jobs = $table('server_analysis_jobs');
        $operational = $table('vw_media_processing_status');
        $summary = $table('vw_department_media_summary');

        // Latest related rows keep the report at exactly one row per active file.
        $this->db->query("CREATE VIEW {$operational} AS
            SELECT f.id AS file_id, f.filename, f.display_title, f.filetype,
                f.size AS size_bytes, f.dateupload AS uploaded_at,
                f.employee_id AS uploader_id, COALESCE(u.name, 'Unknown uploader') AS uploader_name,
                COALESCE(NULLIF(TRIM(p.department), ''), NULLIF(TRIM(u.department), ''), 'Unassigned') AS department,
                COALESCE(NULLIF(f.classification, ''), 'Unclassified Event') AS classification,
                COALESCE(j.status, 'not_queued') AS analysis_status,
                COALESCE(j.attempts, 0) AS analysis_attempts,
                CASE WHEN p.id IS NULL THEN 0 ELSE 1 END AS has_passport,
                p.passport_uid, COALESCE(p.needs_review, 0) AS needs_review,
                CASE WHEN j.status IN ('pending', 'failed') OR p.id IS NULL OR p.needs_review = 1
                    THEN 1 ELSE 0 END AS needs_attention
            FROM {$files} f
            LEFT JOIN {$users} u ON u.employee_id = f.employee_id
            LEFT JOIN {$passports} p ON p.id = (SELECT MAX(p2.id) FROM {$passports} p2 WHERE p2.media_id = f.id)
            LEFT JOIN {$media} m ON m.id = (SELECT MAX(m2.id) FROM {$media} m2 WHERE m2.file_id = f.id AND m2.is_deleted = 0)
            LEFT JOIN {$jobs} j ON j.media_id = m.id
            WHERE f.is_deleted = 0");

        $this->db->query("CREATE VIEW {$summary} AS
            SELECT department, COUNT(*) AS total_files,
                COALESCE(SUM(size_bytes), 0) AS total_storage_bytes,
                ROUND(COALESCE(SUM(size_bytes), 0) / 1048576.0, 2) AS total_storage_mb,
                SUM(has_passport) AS files_with_passport,
                SUM(CASE WHEN has_passport = 0 THEN 1 ELSE 0 END) AS files_missing_passport,
                ROUND(100.0 * SUM(has_passport) / COUNT(*), 2) AS passport_coverage_percent,
                SUM(CASE WHEN analysis_status = 'pending' THEN 1 ELSE 0 END) AS pending_analysis,
                SUM(CASE WHEN analysis_status = 'failed' THEN 1 ELSE 0 END) AS failed_analysis,
                SUM(needs_attention) AS files_needing_attention,
                MAX(uploaded_at) AS latest_upload_at
            FROM {$operational}
            GROUP BY department");
    }

    public function down()
    {
        foreach (['vw_department_media_summary', 'vw_media_processing_status'] as $view) {
            $this->db->query('DROP VIEW IF EXISTS ' . $this->db->escapeIdentifiers($this->db->prefixTable($view)));
        }
    }
}
