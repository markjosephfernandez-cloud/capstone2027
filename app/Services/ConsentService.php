<?php

namespace App\Services;

use CodeIgniter\HTTP\IncomingRequest;

/** Tracks acknowledgement of the in-app data-privacy notice. */
class ConsentService
{
    public const POLICY_VERSION = '2026-08';

    /** @param array<string, mixed> $actor */
    public function requiresConsent(array $actor): bool
    {
        $employeeId = (int) ($actor['employee_id'] ?? 0);
        $db = db_connect();
        if ($employeeId < 1 || ! $db->tableExists('privacy_consents')) {
            return false;
        }

        return $db->table('privacy_consents')
            ->where('employee_id', $employeeId)
            ->where('policy_version', self::POLICY_VERSION)
            ->countAllResults() === 0;
    }

    /** @param array<string, mixed> $actor */
    public function accept(array $actor, IncomingRequest $request): void
    {
        $employeeId = (int) ($actor['employee_id'] ?? 0);
        $db = db_connect();
        if ($employeeId < 1 || ! $db->tableExists('privacy_consents')) {
            return;
        }
        $existing = $db->table('privacy_consents')->where('employee_id', $employeeId)->where('policy_version', self::POLICY_VERSION)->get()->getRowArray();
        $payload = ['employee_id' => $employeeId, 'policy_version' => self::POLICY_VERSION, 'accepted_at' => gmdate('Y-m-d H:i:s'), 'reminded_at' => gmdate('Y-m-d H:i:s'), 'ip_address' => $request->getIPAddress()];
        if ($existing === null) {
            $db->table('privacy_consents')->insert($payload);
        } else {
            $db->table('privacy_consents')->where('id', (int) $existing['id'])->update($payload);
        }
    }
}
