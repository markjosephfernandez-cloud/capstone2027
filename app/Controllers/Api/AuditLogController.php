<?php

namespace App\Controllers\Api;

class AuditLogController extends ApiController
{
    public function index()
    {
        if (($response = $this->requireRole(['super_admin'])) !== null) {
            return $response;
        }
        $db = db_connect();
        if (! $db->tableExists('audit_logs')) {
            return $this->error('schema_missing', 'The audit log migration has not been run.', 503);
        }

        $builder = $db->table('audit_logs')->orderBy('created_at', 'DESC')->orderBy('id', 'DESC');
        foreach (['module', 'action', 'user_id'] as $field) {
            $value = trim((string) $this->request->getGet($field));
            if ($value !== '') {
                $builder->where($field, mb_substr($value, 0, 100));
            }
        }
        foreach (['from' => 'created_at >=', 'to' => 'created_at <='] as $parameter => $condition) {
            $date = $this->request->getGet($parameter);
            if ($date !== null && $date !== '') {
                $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $date);
                if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                    return $this->error('validation_error', $parameter . ' must use YYYY-MM-DD.', 422);
                }
                $builder->where($condition, $parameter === 'from' ? $date . ' 00:00:00' : $date . ' 23:59:59');
            }
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->get($this->perPage(), ($this->page() - 1) * $this->perPage())->getResultArray();

        return $this->success($rows, 200, ['page' => $this->page(), 'per_page' => $this->perPage(), 'total' => $total]);
    }
}
