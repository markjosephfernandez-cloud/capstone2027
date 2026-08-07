<?php

namespace App\Controllers\Api;

class RecentController extends ApiController
{
    public function index()
    {
        if (($response = $this->requireRole(['super_admin', 'admin', 'staff'])) !== null) {
            return $response;
        }
        $actor = $this->actor();
        $rows  = db_connect()->table('recent r')
            ->select('r.id, r.item_type, r.item_name, r.filepath, r.timestamp, u.name AS user_name')
            ->join('user u', 'u.employee_id = r.employee_id', 'left')
            ->where('r.employee_id', (int) $actor['employee_id'])
            ->orderBy('r.timestamp', 'DESC')
            ->get($this->perPage(), ($this->page() - 1) * $this->perPage())
            ->getResultArray();

        foreach ($rows as &$row) {
            unset($row['filepath']); // Never disclose a server path.
        }

        return $this->success($rows, 200, ['page' => $this->page(), 'per_page' => $this->perPage()]);
    }
}
