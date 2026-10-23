<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\I18n\Time;

/** Writes UTC activity records while tolerating installations without audit tables. */
class AuditService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** Keep one evolving entry across all batches in a selected server scan. */
    public function recordServerScan(array $actor, ?int $auditId, array $results, int $total): ?int
    {
        if (! $this->db->tableExists('audit_logs')) {
            return null;
        }

        $counts = array_count_values(array_column($results, 'status'));
        $pending = max(0, $total - count($results));
        $action = $pending > 0 ? 'SERVER_SCAN_IN_PROGRESS' : 'SERVER_SCAN_COMPLETED';
        $description = sprintf(
            'Scanned %d of %d files: %d successful, %d skipped, %d failed.',
            count($results), max($total, count($results)),
            $counts['imported'] ?? 0, $counts['skipped'] ?? 0, $counts['failed'] ?? 0
        );
        foreach ($results as $result) {
            $line = "\n" . ucfirst($result['status'] === 'imported' ? 'successful' : $result['status'])
                . ': ' . $result['path'] . ' — ' . $result['message'];
            if (strlen($description) + strlen($line) > 60000) {
                $description .= "\nAdditional file details retained in scan job records.";
                break;
            }
            $description .= $line;
        }

        if ($auditId === null) {
            $this->record($actor, null, 'metadata', $action, 'server_library', null, $description);
            return (int) $this->db->insertID();
        }

        $this->db->table('audit_logs')->where('id', $auditId)
            ->where('user_id', (string) $actor['employee_id'])
            ->whereIn('action', ['SERVER_SCAN_IN_PROGRESS', 'SERVER_SCAN_COMPLETED'])
            ->update(['action' => $action, 'description' => $description]);

        return $auditId;
    }

    /**
     * IP addresses are intentionally not persisted in activity records.
     *
     * @param array<string, mixed> $actor
     * @param IncomingRequest|null $request Retained for compatibility with audit callers.
     */
    public function record(array $actor, ?IncomingRequest $request, string $module, string $action, string $targetType, ?int $targetId, string $description): void
    {
        if (! $this->db->tableExists('audit_logs')) {
            return;
        }

        $payload = [
            'user_id'     => (string) $actor['employee_id'],
            'user_name'   => $actor['name'] ?? null,
            'department'  => $actor['department'] ?? null,
            'module'      => $module,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'description' => $description,
            'ip_address'  => null,
            'created_at'  => Time::now('UTC')->toDateTimeString(),
        ];

        if (in_array('user_role', $this->db->getFieldNames('audit_logs'), true)) {
            $payload['user_role'] = (string) ($actor['role'] ?? 'unknown');
        }

        $this->db->table('audit_logs')->insert($payload);
    }
}
