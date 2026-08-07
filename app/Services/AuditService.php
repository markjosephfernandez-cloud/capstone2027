<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\I18n\Time;

class AuditService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** @param array<string, mixed> $actor */
    public function record(array $actor, IncomingRequest $request, string $module, string $action, string $targetType, ?int $targetId, string $description): void
    {
        if (! $this->db->tableExists('audit_logs')) {
            return;
        }

        $this->db->table('audit_logs')->insert([
            'user_id'     => (string) $actor['employee_id'],
            'user_name'   => $actor['name'] ?? null,
            'department'  => $actor['department'] ?? null,
            'module'      => $module,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'description' => $description,
            'ip_address'  => $request->getIPAddress(),
            'created_at'  => Time::now('UTC')->toDateTimeString(),
        ]);
    }
}
