<?php

namespace App\Services;

use RuntimeException;

/** Creates an integrity-sealed, versioned metadata passport for media. */
class PassportService
{
    /** @param array<string, mixed> $actor */
    public function backfillMissing(array $actor, int $limit = 250): int
    {
        $db = db_connect();
        if (! $db->tableExists('passports')) {
            return 0;
        }
        $files = $db->table('files f')
            ->select('f.*')
            ->join('passports p', 'p.media_id = f.id', 'left')
            ->where('p.id IS NULL', null, false)
            ->where('f.is_deleted', 0)
            ->orderBy('f.id', 'ASC')
            ->limit(max(1, min(1000, $limit)))
            ->get()
            ->getResultArray();
        $created = 0;
        foreach ($files as $file) {
            $passportActor = $this->passportActorForFile($file, $actor);
            $detection = service('eventDetection')->classifyAndStore($file);
            if ($this->generate($file, $passportActor, $detection) !== null) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Reprocesses a small batch of existing image passports that do not yet
     * contain an AI confidence score. A small batch keeps the web request
     * responsive when the model is running on a local CPU.
     *
     * @param array<string, mixed> $actor @return array{processed:int, classified:int, unavailable:int}
     */
    public function reanalyzeMissingAi(array $actor, int $limit = 20): array
    {
        $db = db_connect();
        if (! $db->tableExists('passports')) {
            return ['processed' => 0, 'classified' => 0, 'unavailable' => 0];
        }

        $files = $db->table('files f')
            ->select('f.*')
            ->join('passports p', 'p.media_id = f.id')
            ->where('f.is_deleted', 0)
            ->whereIn('f.filetype', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])
            ->where('(p.confidence_score IS NULL OR p.confidence_score <= 0)', null, false)
            ->orderBy('p.updated_at', 'ASC')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->getResultArray();

        $result = ['processed' => 0, 'classified' => 0, 'unavailable' => 0];
        foreach ($files as $file) {
            $detection = service('eventDetection')->classifyAndStore($file);
            $this->generate($file, $this->passportActorForFile($file, $actor), $detection);
            $result['processed']++;
            if ((float) ($detection['confidence_score'] ?? 0) > 0) {
                $result['classified']++;
            } else {
                $result['unavailable']++;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $file @param array<string, mixed> $actor @param array<string, mixed> $detection */
    public function generate(array $file, array $actor, array $detection): ?array
    {
        $db = db_connect();
        if (! $db->tableExists('passports')) {
            return null;
        }

        $mediaId = (int) $file['id'];
        $existing = $db->table('passports')->where('media_id', $mediaId)->get()->getRowArray();
        $passportUid = $existing['passport_uid'] ?? sprintf('FU-PSP-%s-%06d', gmdate('Y'), $mediaId);
        $contentHash = $this->contentHash((string) ($file['filepath'] ?? ''));
        $dateTaken = $this->extractDateTaken((string) ($file['filepath'] ?? ''));
        $payload = [
            'passport_uid'        => $passportUid,
            'media_id'            => $mediaId,
            'event_name'          => $detection['event_name'] ?? null,
            'department'          => $actor['department'] ?? null,
            'photographer'        => $actor['name'] ?? null,
            'uploaded_by'         => (string) ($actor['employee_id'] ?? ''),
            'date_taken'          => $dateTaken,
            'upload_date'         => $file['dateupload'] ?? gmdate('Y-m-d H:i:s'),
            'ai_classification'   => $detection['classification'] ?? null,
            'confidence_score'    => $detection['confidence_score'] ?? null,
            'ai_version'          => $detection['detector_version'] ?? null,
            'processing_time_ms'  => $detection['processing_time_ms'] ?? null,
            'needs_review'        => ! empty($detection['needs_review']) ? 1 : 0,
            'copyright_owner'     => 'Foundation University',
            'distribution_rights' => 'Internal Use Only',
            'usage_restrictions'  => 'Automatically published after AI processing.',
            // A SHA-256 integrity seal is stored now; formal C2PA manifest signing
            // remains disabled until a trusted certificate and signer are configured.
            'c2pa_compliant'      => 0,
        ];
        $payload['digital_signature'] = hash('sha256', json_encode([
            'passport_uid' => $passportUid,
            'media_id'     => $mediaId,
            'content_hash' => $contentHash,
            'event_name'   => $payload['event_name'],
            'uploaded_by'  => $payload['uploaded_by'],
            'date_taken'   => $payload['date_taken'],
            'created_at'   => $payload['upload_date'],
        ], JSON_UNESCAPED_SLASHES));
        $payload['qr_payload'] = json_encode([
            'passport_uid' => $passportUid,
            'media_id'     => $mediaId,
            'integrity'    => $payload['digital_signature'],
        ], JSON_UNESCAPED_SLASHES);

        if ($existing === null) {
            $db->table('passports')->insert($payload);
            $passportId = (int) $db->insertID();
        } else {
            $passportId = (int) $existing['id'];
            $db->table('passports')->where('id', $passportId)->update($payload);
        }

        if ($db->tableExists('passport_versions')) {
            $version = (int) $db->table('passport_versions')->where('passport_id', $passportId)->countAllResults() + 1;
            $db->table('passport_versions')->insert([
                'passport_id'   => $passportId,
                'version_no'    => $version,
                'snapshot_json' => json_encode(array_merge($payload, ['content_hash' => $contentHash]), JSON_UNESCAPED_SLASHES),
                'changed_by'    => (string) ($actor['employee_id'] ?? ''),
                'change_note'   => $existing === null ? 'Passport generated on upload' : 'Passport metadata refreshed',
            ]);
        }
        if ($db->tableExists('notifications')) {
            $db->table('notifications')->insert([
                'recipient_id'   => (string) ($actor['employee_id'] ?? ''),
                'type'           => 'passport_generated',
                'title'          => 'Metadata passport generated',
                'message'        => 'A metadata passport was generated for "' . ($file['display_title'] ?? 'Untitled media') . '".',
                'link'           => site_url('workspace/passports'),
            ]);
        }

        return ['id' => $passportId, 'passport_uid' => $passportUid, 'needs_review' => (bool) $payload['needs_review']];
    }

    private function extractDateTaken(string $filepath): ?string
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        try {
            $path = service('storage')->resolveDatabaseFile($filepath);
        } catch (RuntimeException) {
            return null;
        }

        $exif = @exif_read_data($path, null, true, false);
        return is_array($exif) ? $this->dateTakenFromExif($exif) : null;
    }

    /** @param array<string, mixed> $exif */
    private function dateTakenFromExif(array $exif): ?string
    {
        $values = [
            $exif['EXIF']['DateTimeOriginal'] ?? null,
            $exif['EXIF']['DateTimeDigitized'] ?? null,
            $exif['IFD0']['DateTime'] ?? null,
            $exif['DateTimeOriginal'] ?? null,
            $exif['DateTimeDigitized'] ?? null,
            $exif['DateTime'] ?? null,
        ];

        foreach ($values as $value) {
            $value = trim((string) $value);
            if (preg_match('/^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', $value, $parts) !== 1) {
                continue;
            }

            $year = (int) $parts[1];
            $month = (int) $parts[2];
            $day = (int) $parts[3];
            $hour = (int) $parts[4];
            $minute = (int) $parts[5];
            $second = (int) $parts[6];
            if ($year < 1900 || ! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
                continue;
            }

            return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
        }

        return null;
    }

    private function contentHash(string $filepath): ?string
    {
        try {
            $path = service('storage')->resolveDatabaseFile($filepath);

            return hash_file('sha256', $path) ?: null;
        } catch (RuntimeException) {
            return null;
        }
    }

    /** @param array<string, mixed> $file @param array<string, mixed> $actor @return array<string, mixed> */
    private function passportActorForFile(array $file, array $actor): array
    {
        $uploader = ! empty($file['employee_id'])
            ? db_connect()->table('user')->where('employee_id', (int) $file['employee_id'])->get()->getRowArray()
            : null;

        return is_array($uploader) ? array_merge($actor, [
            'employee_id' => (int) $uploader['employee_id'],
            'name' => $uploader['name'] ?? $actor['name'] ?? 'Unknown uploader',
            'department' => $uploader['department'] ?? $actor['department'] ?? null,
        ]) : $actor;
    }
}
