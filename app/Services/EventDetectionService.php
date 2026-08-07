<?php

namespace App\Services;

use App\Models\FileModel;
use App\Models\TagModel;
use Config\GreyhoundAi;

/**
 * Stores a transparent event-classification result for every uploaded file.
 * The keyword baseline is deliberately reviewable and can later be replaced
 * by a trained university-event model without changing the data contract.
 */
class EventDetectionService
{
    public function __construct(private readonly GreyhoundAi $config = new GreyhoundAi())
    {
    }

    /** @param array<string, mixed> $file @return array<string, mixed> */
    public function classifyAndStore(array $file): array
    {
        $started = hrtime(true);
        $text    = mb_strtolower(implode(' ', [
            (string) ($file['filename'] ?? ''),
            (string) ($file['description'] ?? ''),
            (string) ($file['tags'] ?? ''),
        ]));
        $remote = $this->trainedModelResult($file);
        $event = $remote['event_name'] ?? null;
        $evidence = $remote['evidence'] ?? [];

        if ($remote === null) {
            foreach ($this->config->eventKeywords as $eventName => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($text, mb_strtolower($keyword))) {
                        $event = $eventName;
                        $evidence[] = ['type' => 'keyword', 'value' => $keyword];
                    }
                }
                if ($event !== null) {
                    break;
                }
            }
        }

        $confidence = $remote['confidence_score'] ?? ($event === null ? 0.0 : (count($evidence) > 1 ? 98.0 : 92.0));
        $needsReview = $confidence < $this->config->reviewThreshold;
        $classification = $event ?? 'Unclassified university media';
        $detectedObjects = $event === null ? null : 'event: ' . $event;
        $automaticTags = $this->automaticTags($file, $event, $needsReview);
        $tags = $this->mergeTags((string) ($file['tags'] ?? ''), $automaticTags);
        $durationMs = (int) max(1, round((hrtime(true) - $started) / 1_000_000));
        $result = [
            'event_name'       => $event,
            'classification'   => $classification,
            'confidence_score' => $confidence,
            'detector_version' => $remote['detector_version'] ?? $this->config->modelVersion,
            'evidence'         => $evidence,
            'needs_review'     => $needsReview,
            'processing_time_ms' => $durationMs,
        ];

        (new FileModel())->update((int) $file['id'], [
            'classification'   => $classification,
            'detected_objects' => $detectedObjects,
            'tags'             => $tags,
            'last_scanned'     => gmdate('Y-m-d H:i:s'),
        ]);
        $this->storeTagRecords($file, $automaticTags);

        $db = db_connect();
        if ($db->tableExists('ai_event_detections')) {
            $payload = [
                'media_id'         => (int) $file['id'],
                'event_name'       => $event,
                'confidence_score' => $confidence,
                'detector_version' => $result['detector_version'],
                'evidence_json'    => json_encode($evidence, JSON_UNESCAPED_SLASHES),
                'needs_review'     => $needsReview ? 1 : 0,
                'processed_at'     => gmdate('Y-m-d H:i:s'),
            ];
            $existing = $db->table('ai_event_detections')->where('media_id', (int) $file['id'])->get()->getRowArray();
            if ($existing === null) {
                $db->table('ai_event_detections')->insert($payload);
            } else {
                $db->table('ai_event_detections')->where('id', (int) $existing['id'])->update($payload);
            }
        }

        return $result;
    }

    /** @param list<string> $automaticTags */
    private function mergeTags(string $current, array $automaticTags): string
    {
        $tags = array_filter(array_map('trim', explode(',', $current)));
        $known = [];
        foreach ($tags as $tag) {
            $known[mb_strtolower($tag)] = true;
        }
        foreach ($automaticTags as $tag) {
            if (! isset($known[mb_strtolower($tag)])) {
                $tags[] = $tag;
                $known[mb_strtolower($tag)] = true;
            }
        }

        return implode(', ', array_slice(array_values(array_unique($tags)), 0, 30));
    }

    /** @param array<string, mixed> $file @return list<string> */
    private function automaticTags(array $file, ?string $event, bool $needsReview): array
    {
        $extension = strtoupper((string) pathinfo((string) ($file['filename'] ?? ''), PATHINFO_EXTENSION));
        $extension = $extension !== '' ? $extension : 'UNKNOWN';
        $date = (string) ($file['datecreated'] ?? $file['dateupload'] ?? '');
        $year = preg_match('/^(\d{4})/', $date, $matches) === 1 ? $matches[1] : gmdate('Y');

        $tags = [
            'Media: ' . $this->mediaCategory($extension),
            'Format: ' . $extension,
            'Year: ' . $year,
        ];
        if ($event !== null) {
            $tags[] = $event;
            $tags[] = 'AI Classified';
            $tags[] = 'University Event';
        }
        if ($needsReview) {
            $tags[] = 'AI Review Needed';
        }

        $folderId = filter_var($file['folder_id'] ?? null, FILTER_VALIDATE_INT);
        if ($folderId !== false && $folderId > 0) {
            $folder = db_connect()->table('folder')->select('name')->where('id', $folderId)->get()->getRowArray();
            $name = trim((string) ($folder['name'] ?? ''));
            if ($name !== '') {
                $tags[] = 'Folder: ' . $name;
            }
        }

        return array_values(array_unique($tags));
    }

    private function mediaCategory(string $extension): string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic' => 'Image',
            'mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v' => 'Video',
            'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac' => 'Audio',
            default => 'Document',
        };
    }

    /** @param array<string, mixed> $file @param list<string> $tags */
    private function storeTagRecords(array $file, array $tags): void
    {
        $fileId = (int) ($file['id'] ?? 0);
        if ($fileId < 1 || ! db_connect()->tableExists('tag')) {
            return;
        }

        $model = new TagModel();
        foreach ($tags as $tag) {
            if ($model->where('file_id', $fileId)->where('tag', $tag)->first() !== null) {
                continue;
            }
            $model->insert([
                'tag' => $tag,
                'type' => $this->tagRecordType((string) ($file['filetype'] ?? '')),
                'filepath' => (string) ($file['filepath'] ?? ''),
                'file_id' => $fileId,
            ]);
        }
    }

    private function tagRecordType(string $extension): string
    {
        return match (strtolower($extension)) {
            'mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v' => 'video',
            'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac' => 'audio',
            default => 'image',
        };
    }

    /** @param array<string, mixed> $file @return array<string, mixed>|null */
    private function trainedModelResult(array $file): ?array
    {
        $endpoint = $this->config->eventModelEndpoint();
        if ($endpoint === '') {
            return null;
        }

        try {
            $path = service('storage')->resolveDatabaseFile((string) ($file['filepath'] ?? ''));
            if (! is_file($path) || ! is_readable($path)) {
                return null;
            }
            $filename = (string) ($file['filename'] ?? basename($path));
            $mimeType = mime_content_type($path) ?: 'application/octet-stream';
            $response = service('curlrequest')->post($endpoint, [
                'timeout' => 15,
                'connect_timeout' => 3,
                'multipart' => [
                    'media' => new \CURLFile($path, $mimeType, $filename),
                    'media_id' => (string) ($file['id'] ?? ''),
                ],
            ]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return null;
            }
            $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $event = $payload['event_name'] ?? null;
            $confidence = isset($payload['confidence_score']) ? (float) $payload['confidence_score'] : null;
            if (! is_string($event) || ! array_key_exists($event, $this->config->eventKeywords) || $confidence === null || $confidence < 0 || $confidence > 100) {
                return null;
            }

            return [
                'event_name' => $event,
                'confidence_score' => $confidence,
                'detector_version' => is_string($payload['model_version'] ?? null) ? $payload['model_version'] : 'external-event-model',
                'evidence' => is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [['type' => 'trained_model', 'value' => 'remote inference']],
            ];
        } catch (\Throwable) {
            // Upload processing must remain available if an optional inference service is offline.
            return null;
        }
    }
}
