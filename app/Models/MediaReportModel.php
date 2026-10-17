<?php

namespace App\Models;

use CodeIgniter\Model;

/** Read-only reports; existing controllers retain responsibility for authorization. */
class MediaReportModel extends Model
{
    public function processingReport(int $page = 1): array
    {
        if (! $this->db->tableExists('vw_media_processing_status')) {
            return ['available' => false, 'rows' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
        }
        $total = $this->db->table('vw_media_processing_status')->countAllResults();
        $pages = max(1, (int) ceil($total / 20));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->table('vw_media_processing_status')
            ->orderBy('uploaded_at', 'DESC')->orderBy('file_id', 'DESC')
            ->limit(20, ($page - 1) * 20)->get()->getResultArray();
        return ['available' => true] + compact('rows', 'page', 'pages', 'total');
    }

    public function departmentSummary(): array
    {
        return $this->db->tableExists('vw_department_media_summary')
            ? $this->db->table('vw_department_media_summary')->orderBy('total_files', 'DESC')->orderBy('department')->get()->getResultArray()
            : [];
    }
}
