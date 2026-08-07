<?php
/**
 * passport_helper.php
 * Core logic for Feature 2: AI Media Release Metadata Passport.
 * Include after config.php (needs $conn) and audit_helper.php.
 */

require_once __DIR__ . '/audit_helper.php';

/**
 * Very small "AI" classifier stub. In production this calls the same
 * TensorFlow Lite / Python service used by Feature 1 (AI Event Detection).
 * Here it mock-classifies from the filename/description so the rest of the
 * pipeline (confidence score, manual-review flag, auto-tag) is fully wired.
 */
if (!function_exists('mock_classify_event')) {
    function mock_classify_event(string $filename, string $description = ''): array
    {
        $events = [
            'Dal-uy Events'          => ['dal-uy', 'daluy'],
            'Kasadyaan Celebrations' => ['kasadyaan'],
            'EXPO Activities'        => ['expo'],
            'Graduation'             => ['grad', 'commencement'],
            'Recognition Day'        => ['recognition'],
            'Sports Fest'            => ['sports', 'sportsfest'],
            'Orientation'            => ['orientation', 'orasyon'],
            'General University Events' => [],
        ];

        $haystack = strtolower($filename . ' ' . $description);
        foreach ($events as $eventName => $keywords) {
            foreach ($keywords as $kw) {
                if ($kw !== '' && strpos($haystack, $kw) !== false) {
                    // Deterministic "confidence" derived from string so results are stable, not random noise
                    $confidence = 82 + (crc32($haystack) % 17); // 82-98
                    return ['event' => $eventName, 'confidence' => $confidence];
                }
            }
        }

        // No keyword hit -> General event with a lower, review-triggering confidence
        $confidence = 55 + (crc32($haystack) % 20); // 55-74, below the 80% auto-accept bar
        return ['event' => 'General University Events', 'confidence' => $confidence];
    }
}

if (!function_exists('generate_passport_uid')) {
    function generate_passport_uid(int $mediaId): string
    {
        return 'FU-PSP-' . date('Y') . '-' . str_pad((string) $mediaId, 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('build_digital_signature')) {
    function build_digital_signature(array $fields): string
    {
        // Demonstrates a sealed, tamper-evident record. Swap for a real
        // asymmetric signature (e.g. openssl_sign with an org key) in production.
        ksort($fields);
        return hash('sha256', json_encode($fields) . '|FU-CREATIVES-SALT');
    }
}

/**
 * Generate (or regenerate) the metadata passport for a media/file record.
 * Returns the passport row as an associative array, or null on failure.
 */
if (!function_exists('generate_passport')) {
    function generate_passport(mysqli $conn, int $mediaId, ?string $actorId = null): ?array
    {
        $fileStmt = $conn->prepare("SELECT * FROM files WHERE id = ?");
        $fileStmt->bind_param("i", $mediaId);
        $fileStmt->execute();
        $file = $fileStmt->get_result()->fetch_assoc();
        $fileStmt->close();

        if (!$file) {
            return null;
        }

        $uploaderId = $file['employee_id'] ?? $actorId;
        $uploaderName = null;
        $department = null;
        if ($uploaderId) {
            $uStmt = $conn->prepare("SELECT name, department FROM user WHERE employee_id = ?");
            $uStmt->bind_param("s", $uploaderId);
            $uStmt->execute();
            $u = $uStmt->get_result()->fetch_assoc();
            $uStmt->close();
            if ($u) {
                $uploaderName = $u['name'];
                $department = $u['department'];
            }
        }

        $classification = mock_classify_event($file['filename'], $file['description'] ?? '');
        $needsReview = $classification['confidence'] < 80 ? 1 : 0;

        $startedAt = microtime(true);
        // Simulated AI processing time. Replace with the real elapsed time
        // once this calls the live TFLite/Python classification endpoint.
        $processingTimeMs = (int) round((microtime(true) - $startedAt) * 1000) + 180 + (crc32($file['filename']) % 220);

        $passportUid = generate_passport_uid($mediaId);
        $gps = null; // Populate from EXIF GPS tags when available; left null for privacy by default.
        $device = null; // Populate from EXIF Make/Model when available.

        $signatureFields = [
            'media_id'          => $mediaId,
            'passport_uid'      => $passportUid,
            'event_name'        => $classification['event'],
            'confidence_score'  => $classification['confidence'],
            'uploaded_by'       => $uploaderId,
            'upload_date'       => $file['dateupload'],
            'copyright_owner'   => 'Foundation University',
        ];
        $signature = build_digital_signature($signatureFields);

        $qrPayload = json_encode([
            'passport_uid' => $passportUid,
            'media_id'     => $mediaId,
            'event'        => $classification['event'],
            'signature'    => $signature,
        ]);

        // Upsert
        $existing = get_passport_by_media_id($conn, $mediaId);
        if ($existing) {
            $stmt = $conn->prepare(
                "UPDATE passports SET
                    event_name = ?, department = ?, uploaded_by = ?, upload_date = ?,
                    ai_classification = ?, confidence_score = ?, ai_version = ?, processing_time_ms = ?,
                    needs_review = ?, digital_signature = ?, qr_payload = ?
                 WHERE media_id = ?"
            );
            $aiVersion = 'FU-EventNet v1.3 (mock)';
            $stmt->bind_param(
                "sssssdsiissi",
                $classification['event'],
                $department,
                $uploaderId,
                $file['dateupload'],
                $classification['event'],
                $classification['confidence'],
                $aiVersion,
                $processingTimeMs,
                $needsReview,
                $signature,
                $qrPayload,
                $mediaId
            );
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO passports
                    (passport_uid, media_id, event_name, department, photographer, uploaded_by,
                     date_taken, upload_date, gps_location, device_info, ai_classification,
                     confidence_score, ai_version, processing_time_ms, needs_review,
                     digital_signature, qr_payload)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $aiVersion = 'FU-EventNet v1.3 (mock)';
            $photographer = $uploaderName;
            $dateTaken = $file['dateupload'];
            $stmt->bind_param(
                "sisssssssssdsiiss",
                $passportUid,
                $mediaId,
                $classification['event'],
                $department,
                $photographer,
                $uploaderId,
                $dateTaken,
                $file['dateupload'],
                $gps,
                $device,
                $classification['event'],
                $classification['confidence'],
                $aiVersion,
                $processingTimeMs,
                $needsReview,
                $signature,
                $qrPayload
            );
            $stmt->execute();
            $stmt->close();
        }

        $passport = get_passport_by_media_id($conn, $mediaId);

        // Version history snapshot
        if ($passport) {
            $verStmt = $conn->prepare("SELECT COALESCE(MAX(version_no),0)+1 AS v FROM passport_versions WHERE passport_id = ?");
            $verStmt->bind_param("i", $passport['id']);
            $verStmt->execute();
            $nextVersion = (int) ($verStmt->get_result()->fetch_assoc()['v'] ?? 1);
            $verStmt->close();

            $snapshot = json_encode($passport);
            $note = $existing ? 'Passport regenerated' : 'Passport generated';
            $ins = $conn->prepare(
                "INSERT INTO passport_versions (passport_id, version_no, snapshot_json, changed_by, change_note)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $ins->bind_param("iisss", $passport['id'], $nextVersion, $snapshot, $actorId, $note);
            $ins->execute();
            $ins->close();
        }

        record_audit($conn, [
            'module'      => 'metadata',
            'action'      => 'PASSPORT_GENERATED',
            'target_type' => 'passport',
            'target_id'   => $passport['id'] ?? null,
            'description' => "Generated metadata passport {$passportUid} for \"{$file['filename']}\" (event: {$classification['event']}, confidence: {$classification['confidence']}%)",
        ]);

        if ($needsReview) {
            notify_users($conn, [
                'recipient_role' => 'FU Creative Staff',
                'type'           => 'general',
                'title'          => 'AI classification needs review',
                'message'        => "\"{$file['filename']}\" was classified as {$classification['event']} at only {$classification['confidence']}% confidence and needs manual review.",
                'link'           => "passport_view.php?media_id={$mediaId}",
            ]);
        }

        return $passport;
    }
}

if (!function_exists('get_passport_by_media_id')) {
    function get_passport_by_media_id(mysqli $conn, int $mediaId): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM passports WHERE media_id = ?");
        $stmt->bind_param("i", $mediaId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}
