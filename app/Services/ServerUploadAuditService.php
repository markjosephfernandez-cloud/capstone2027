<?php
namespace App\Services;
use CodeIgniter\Database\BaseConnection;

/** One evolving audit record per uploader/batch; retries replace the item's outcome. */
class ServerUploadAuditService
{
    public function __construct(private readonly BaseConnection $db) {}

    public function record(array $actor, array $metadata, string $path, bool $success): void
    {
        $batch = (string) ($metadata['upload_batch_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $batch)) return;
        $key = hash('sha256', (string)$actor['employee_id'] . ':' . $batch);
        $item = (string)($metadata['audit_item_id'] ?? '');
        if ($item === '') $item = hash('sha256', ($metadata['folder_id'] ?? '') . '/' . ($metadata['relative_path'] ?? '') . '/' . ($metadata['name'] ?? $path));
        $expected = max(0, (int)($metadata['audit_total'] ?? 0));
        $this->db->transBegin();
        try {
            $this->db->table('server_upload_audit_batches')->ignore(true)->insert([
                'batch_key'=>$key, 'audit_id'=>null, 'items_json'=>'{}', 'expected_count'=>$expected,
            ]);
            // Lock each batch so parallel file completions cannot overwrite each other.
            $sql = 'SELECT * FROM ' . $this->db->protectIdentifiers('server_upload_audit_batches', true) . ' WHERE batch_key = ?';
            if ($this->db->DBDriver !== 'SQLite3') $sql .= ' FOR UPDATE';
            $state = $this->db->query($sql, [$key])->getRowArray();
            $items = json_decode($state['items_json'], true, 512, JSON_THROW_ON_ERROR);
            // A late failed retry must not erase an already completed upload.
            if (empty($items[$item]['success']) || $success) $items[$item] = ['path'=>$path, 'success'=>$success];
            $expected = max($expected, (int)$state['expected_count'], count($items));
            $uploaded = count(array_filter($items, static fn ($row) => $row['success']));
            $failed = count($items) - $uploaded;
            $pending = $expected - count($items);
            $description = 'Server Library upload batch: ' . $uploaded . ' uploaded, ' . $failed . ' failed, ' . $pending . ' pending or not received; ' . $expected . ' selected. Batch: ' . $batch . '.';
            foreach ($items as $row) {
                $line = "\n" . ($row['success'] ? 'Uploaded: ' : 'Failed: ') . $row['path'];
                if (strlen($description) + strlen($line) > 60000) { $description .= "\nAdditional file details retained in upload batch records."; break; }
                $description .= $line;
            }
            $auditId = $state['audit_id'];
            if ($auditId === null) {
                $this->db->table('audit_logs')->insert([
                    'user_id'=>(string)$actor['employee_id'], 'user_name'=>$actor['name']??null,
                    'user_role'=>$actor['role']??null, 'department'=>$actor['department']??null,
                    'module'=>'upload', 'action'=>'SERVER_LIBRARY_UPLOAD', 'target_type'=>'server_library',
                    'target_id'=>null, 'description'=>$description, 'ip_address'=>null, 'created_at'=>gmdate('Y-m-d H:i:s'),
                ]);
                $auditId = $this->db->insertID();
            } else {
                $this->db->table('audit_logs')->where('id',$auditId)->update(['description'=>$description, 'ip_address'=>null]);
            }
            $this->db->table('server_upload_audit_batches')->where('batch_key',$key)->update([
                'audit_id'=>$auditId, 'items_json'=>json_encode($items,JSON_THROW_ON_ERROR), 'expected_count'=>$expected,
            ]);
            if (!$this->db->transStatus()) throw new \RuntimeException('Upload batch audit could not be saved.');
            $this->db->transCommit();
        } catch (\Throwable $error) { $this->db->transRollback(); throw $error; }
    }
}
