<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\I18n\Time;
use RuntimeException;

class TrashService
{
    public const RETENTION_DAYS = 30;

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function purgeExpiredTrash(array $actor, ?IncomingRequest $request = null, int $limit = 500): int
    {
        $limit = max(1, min(5000, $limit));
        $expiredFiles = $this->db->table('files f')
            ->select('f.*')
            ->join('trashfiles tf', 'tf.file_id = f.id', 'left')
            ->where('f.is_deleted', 1)
            ->where('COALESCE(tf.deleted_at, f.last_action_at, f.dateupload) <= DATE_SUB(NOW(), INTERVAL ' . self::RETENTION_DAYS . ' DAY)', null, false)
            ->limit($limit)
            ->get()
            ->getResultArray();

        $cleanupActor = [
            'employee_id' => 0,
            'name'        => 'Trash retention cleanup',
            'department'  => 'System',
            'role'        => 'system',
        ];
        $purged = 0;
        foreach ($expiredFiles as $file) {
            try {
                $this->permanentlyRemoveMedia($file, $cleanupActor, 'AUTO_TRASH_PURGE', $request);
                $purged++;
            } catch (\Throwable) {
                $this->recordAudit($actor, $request, 'delete', 'AUTO_TRASH_PURGE_FAILED', 'file', (int) $file['id'], 'Trash retention cleanup could not purge "' . $file['filename'] . '".');
            }
        }

        return $purged;
    }

    /**
     * @param list<array<string, mixed>> $files
     * @return list<array<string, mixed>>
     */
    public function withRetention(array $files): array
    {
        $now = time();
        foreach ($files as &$file) {
            $deletedAt = (string) ($file['deleted_at'] ?? $file['last_action_at'] ?? $file['dateupload'] ?? '');
            $deletedTimestamp = $deletedAt === '' ? false : strtotime($deletedAt);
            if ($deletedTimestamp === false) {
                $file['trash_expires_at'] = '-';
                $file['trash_days_remaining'] = self::RETENTION_DAYS;
                continue;
            }

            $expiresTimestamp = $deletedTimestamp + (self::RETENTION_DAYS * 86400);
            $remainingSeconds = $expiresTimestamp - $now;
            $file['trash_expires_at'] = date('M d, Y h:i A', $expiresTimestamp);
            $file['trash_days_remaining'] = max(0, (int) ceil($remainingSeconds / 86400));
        }
        unset($file);

        return $files;
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed> $actor
     */
    public function permanentlyRemoveMedia(array $file, array $actor, string $auditAction, ?IncomingRequest $request = null): void
    {
        $fileId = (int) $file['id'];
        $filename = (string) ($file['filename'] ?? 'media');
        $artifactPaths = $this->managedArtifactPaths($file);

        $this->deletePhysicalArtifacts($artifactPaths);
        $this->deleteMediaDatabaseRows($file);

        $this->recordAudit($actor, $request, 'delete', $auditAction, 'file', $fileId, 'Permanently deleted "' . $filename . '" from Trash.');
    }

    /**
     * @param array<string, mixed> $file
     * @return list<string>
     */
    private function managedArtifactPaths(array $file): array
    {
        $paths = [];
        $filepath = trim((string) ($file['filepath'] ?? ''));
        if ($filepath !== '') {
            $paths[] = $filepath;
        }

        if ($this->db->tableExists('file_watermarks')) {
            foreach ($this->db->table('file_watermarks')->select('watermarked_path')->where('media_id', (int) $file['id'])->get()->getResultArray() as $watermark) {
                $watermarkedPath = trim((string) ($watermark['watermarked_path'] ?? ''));
                if ($watermarkedPath !== '') {
                    $paths[] = $watermarkedPath;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /** @param list<string> $paths */
    private function deletePhysicalArtifacts(array $paths): void
    {
        $storage = service('storage');
        foreach ($paths as $path) {
            try {
                $resolved = $storage->resolveDatabaseFile($path);
            } catch (RuntimeException) {
                continue;
            }

            if (is_file($resolved) && ! @unlink($resolved)) {
                throw new RuntimeException('A media file could not be removed from storage.');
            }
        }
    }

    /** @param array<string, mixed> $file */
    private function deleteMediaDatabaseRows(array $file): void
    {
        $fileId = (int) $file['id'];
        $filepath = (string) ($file['filepath'] ?? '');

        $passportIds = [];
        if ($this->db->tableExists('passports')) {
            $passportIds = array_map('intval', array_column($this->db->table('passports')->select('id')->where('media_id', $fileId)->get()->getResultArray(), 'id'));
        }
        $this->db->transStart();
        if ($passportIds !== [] && $this->db->tableExists('passport_versions')) {
            $this->db->table('passport_versions')->whereIn('passport_id', $passportIds)->delete();
        }
        if ($this->db->tableExists('passports')) {
            $this->db->table('passports')->where('media_id', $fileId)->delete();
        }
        if ($this->db->tableExists('file_watermarks')) {
            $this->db->table('file_watermarks')->where('media_id', $fileId)->delete();
        }
        if ($this->db->tableExists('ai_event_detections')) {
            $this->db->table('ai_event_detections')->where('media_id', $fileId)->delete();
        }
        if ($this->db->tableExists('recent') && $filepath !== '') {
            $this->db->table('recent')->where('filepath', $filepath)->delete();
        }
        if ($this->db->tableExists('tag')) {
            $this->db->table('tag')->where('file_id', $fileId)->delete();
            if ($filepath !== '') {
                $this->db->table('tag')->where('filepath', $filepath)->delete();
            }
        }
        if ($this->db->tableExists('album_files') && $filepath !== '') {
            $this->db->table('album_files')->where('filepath', $filepath)->delete();
        }
        $this->db->table('trashfiles')->where('file_id', $fileId)->delete();
        $this->db->table('files')->where('id', $fileId)->delete();
        $this->db->transComplete();
        if (! $this->db->transStatus()) {
            throw new RuntimeException('The media database record could not be permanently deleted.');
        }
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function recordAudit(array $actor, ?IncomingRequest $request, string $module, string $action, string $targetType, ?int $targetId, string $description): void
    {
        if ($request !== null) {
            service('audit')->record($actor, $request, $module, $action, $targetType, $targetId, $description);
            return;
        }

        if (! $this->db->tableExists('audit_logs')) {
            return;
        }

        $this->db->table('audit_logs')->insert([
            'user_id'     => (string) ($actor['employee_id'] ?? 0),
            'user_name'   => $actor['name'] ?? null,
            'department'  => $actor['department'] ?? null,
            'module'      => $module,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'description' => $description,
            'ip_address'  => 'CLI',
            'created_at'  => Time::now('UTC')->toDateTimeString(),
        ]);
    }
}
