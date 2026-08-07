<?php

namespace App\Services;

use RuntimeException;

/** Transactional request, assignment, history, and notification operations. */
class DistributionService
{
    /** @param array<string, mixed> $actor */
    public function createRequest(array $actor, string $title, string $description): int
    {
        $department = trim((string) ($actor['department'] ?? ''));
        if ($department === '') {
            throw new RuntimeException('Your account must have a department before requesting media.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $db = db_connect();
        $db->transStart();
        $db->table('media_requests')->insert(['department' => $department, 'title' => $title, 'description' => $description, 'status' => 'submitted', 'requested_by' => (int) $actor['employee_id'], 'submitted_at' => $now, 'updated_at' => $now]);
        $id = (int) $db->insertID();
        $this->action($db, $actor, 'submitted', $id, null, 0, $department, $now);
        $this->notifyCreativeAdmins($db, 'department_request', 'New Department Request', $department . "\n" . $title, site_url('workspace/distribution?tab=requests'), $now);
        $db->transComplete();
        if (! $db->transStatus()) {
            throw new RuntimeException('The media request could not be submitted.');
        }
        return $id;
    }

    /** @param list<int> $mediaIds @param array<string, mixed> $actor */
    public function assign(array $mediaIds, string $department, ?int $requestId, string $method, array $actor): int
    {
        $mediaIds = array_values(array_unique(array_filter(array_map('intval', $mediaIds), static fn (int $id): bool => $id > 0)));
        if ($mediaIds === [] || $department === '' || ! in_array($method, ['request', 'direct'], true)) {
            throw new RuntimeException('Choose media, a department, and a valid assignment method.');
        }
        $db = db_connect();
        if ($method === 'request' && ($requestId === null || $db->table('media_requests')->where('id', $requestId)->where('department', $department)->where('status', 'submitted')->countAllResults() === 0)) {
            throw new RuntimeException('Choose an open request belonging to the selected department.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $db->transStart();
        $count = 0;
        foreach ($mediaIds as $mediaId) {
            if ($db->table('files')->where('id', $mediaId)->where('is_deleted', 0)->countAllResults() === 0) {
                continue;
            }
            $payload = ['media_id' => $mediaId, 'department' => $department, 'request_id' => $requestId, 'method' => $method, 'status' => $method === 'direct' ? 'completed' : 'sent', 'sent_by' => (int) $actor['employee_id'], 'sent_at' => $now, 'completed_by' => $method === 'direct' ? (int) $actor['employee_id'] : null, 'completed_at' => $method === 'direct' ? $now : null];
            $existing = $db->table('media_assignments')->where('media_id', $mediaId)->where('department', $department);
            $requestId === null ? $existing->where('request_id IS NULL', null, false) : $existing->where('request_id', $requestId);
            if ($existing->countAllResults() === 0) {
                $db->table('media_assignments')->insert($payload);
                $assignmentId = (int) $db->insertID();
                $this->action($db, $actor, $method === 'direct' ? 'direct_sent' : 'transmitted', $requestId, $assignmentId, 1, $department, $now);
                if ($method === 'direct') {
                    $this->action($db, $actor, 'completed', null, $assignmentId, 1, $department, $now);
                }
                ++$count;
            }
            $db->table('files')->where('id', $mediaId)->update(['distribution_type' => 'department', 'distribution_target' => $department, 'last_action' => $method === 'direct' ? 'direct_sent' : 'transmitted', 'last_action_at' => $now]);
        }
        if ($requestId !== null && $count > 0) {
            $db->table('media_requests')->where('id', $requestId)->update(['status' => 'transmitted', 'transmitted_by' => (int) $actor['employee_id'], 'transmitted_at' => $now, 'updated_at' => $now]);
        }
        if ($count > 0) {
            $this->notifyDepartment($db, $department, $method === 'request' ? 'Your requested files are now available.' : 'Creative Staff assigned new media to your department.', site_url('workspace/file-management'), $now);
        }
        $db->transComplete();
        if (! $db->transStatus()) {
            throw new RuntimeException('The media assignment could not be completed.');
        }
        return $count;
    }

    /** @param array<string, mixed> $actor */
    private function action($db, array $actor, string $action, ?int $requestId, ?int $assignmentId, int $fileCount, string $department, string $now): void
    {
        $db->table('distribution_actions')->insert(['request_id' => $requestId, 'assignment_id' => $assignmentId, 'action' => $action, 'actor_id' => (int) ($actor['employee_id'] ?? 0), 'actor_name' => (string) ($actor['name'] ?? 'Unknown user'), 'actor_role' => (string) ($actor['role'] ?? 'unknown'), 'department' => $department, 'file_count' => $fileCount, 'created_at' => $now]);
    }

    private function notifyCreativeAdmins($db, string $type, string $title, string $message, string $link, string $now): void
    {
        foreach ($db->table('user')->select('employee_id, role')->whereIn('role', ['super_admin', 'admin', 'staff'])->where('is_active', 1)->get()->getResultArray() as $user) {
            $db->table('notifications')->insert(['recipient_id' => (string) $user['employee_id'], 'recipient_role' => (string) $user['role'], 'type' => $type, 'title' => $title, 'message' => $message, 'link' => $link, 'is_read' => 0, 'created_at' => $now]);
        }
    }

    private function notifyDepartment($db, string $department, string $message, string $link, string $now): void
    {
        foreach ($db->table('user')->select('employee_id, role')->where('department', $department)->where('is_active', 1)->get()->getResultArray() as $user) {
            $db->table('notifications')->insert(['recipient_id' => (string) $user['employee_id'], 'recipient_role' => (string) $user['role'], 'type' => 'media_assigned', 'title' => 'New media available', 'message' => $message, 'link' => $link, 'is_read' => 0, 'created_at' => $now]);
        }
    }
}
