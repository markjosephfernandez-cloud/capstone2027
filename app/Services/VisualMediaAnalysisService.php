<?php
namespace App\Services;

use App\Libraries\MediaTagPolicy;
use App\Models\TagModel;
use RuntimeException;

class VisualMediaAnalysisService
{
    private array $requestedTasks = ['objects', 'expressions'];
    public function analyzeAndStore(array $file, ?array $tasks = null): array
    {
        $this->requestedTasks = $tasks ?? ['objects', 'expressions'];
        if (array_diff($this->requestedTasks, ['objects', 'expressions'])) throw new RuntimeException('Invalid visual analysis task.');
        $lock = fopen(WRITEPATH . 'cache/visual-analysis-' . (int) $file['id'] . '.lock', 'c');
        if (!$lock) throw new RuntimeException('Cannot lock image analysis.');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('This image is already being analyzed. Wait for that scan to finish, then retry.', 409);
            return $this->analyzeLocked($file);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function analyzeLocked(array $file): array
    {
        if (!str_starts_with((string) ($file['mime_type'] ?? ''), 'image/')
            && !in_array(strtolower($file['filetype'] ?? ''), ['jpg','jpeg','png','webp','gif','bmp','tif','tiff'], true)) {
            throw new RuntimeException('Visual analysis supports images only.');
        }
        $db = db_connect();
        if (!$db->tableExists('media_visual_analysis')) throw new RuntimeException('Run database migrations before visual analysis.');
        $path = service('storage')->resolveDatabaseFile($file['filepath']);
        $result = $this->resultsFor([(int) $file['id']])[$file['id']] ?? [];
        $fresh = array_intersect($this->requestedTasks, ['objects','expressions']) ? $this->requestAnalysis($path) : [];
        foreach (['objects','expressions'] as $task) {
            if (in_array($task, $this->requestedTasks, true)) $result[$task] = $fresh[$task];
            $result[$task] ??= ['status'=>'not_requested','items'=>[]];
        }
        unset($result['duplicates']);
        $result['analyzed_at'] = gmdate('Y-m-d H:i:s');
        $result['auto_tags'] = self::automaticTags($result);
        // Read current tags after inference, so edits made while the model was running survive.
        $current = $db->table('files')->where('id', $file['id'])->where('is_deleted', 0)->get()->getRowArray();
        if (!$current) throw new RuntimeException('This file is no longer available.');
        $tags = MediaTagPolicy::clean(implode(', ', array_merge(explode(',', (string) ($current['tags'] ?? '')), array_column($result['auto_tags'], 'label'))), $current);
        $updates = ['tags'=>$tags];
        if (in_array('objects', $this->requestedTasks, true) && $result['objects']['status'] === 'complete') $updates['detected_objects'] = implode(', ', array_unique(array_column($result['objects']['items'], 'label')));
        if (in_array('expressions', $this->requestedTasks, true) && in_array($result['expressions']['status'], ['complete','no_face'], true)) {
            $updates['emotion'] = implode(', ', array_unique(array_column($result['expressions']['items'], 'label')));
        }
        $db->transStart();
        $db->table('files')->where('id', $file['id'])->update($updates);
        foreach ($result['auto_tags'] as $tag) {
            if ($db->tableExists('tag') && !$db->table('tag')->where('file_id', $file['id'])->where('tag', $tag['label'])->countAllResults()) {
                (new TagModel())->insert(['file_id'=>$file['id'], 'tag'=>$tag['label'], 'type'=>'image', 'filepath'=>$file['filepath']]);
            }
        }
        $db->table('media_visual_analysis')->replace(['file_id'=>$file['id'], 'result_json'=>json_encode($result, JSON_THROW_ON_ERROR), 'analyzed_at'=>$result['analyzed_at']]);
        $db->transComplete();
        if (!$db->transStatus()) throw new RuntimeException('Could not save visual analysis.');
        if ((new \Config\Storage())->centralized() && $db->tableExists('server_media')) {
            $media = $db->table('server_media')->where('file_id', $file['id'])->where('is_deleted', 0)->get()->getRowArray();
            if ($media) {
                try {
                    (new ServerLibraryEditor(new ServerLibraryService()))->saveAnalysis($media['path'], [
                        'visual_analysis'=>$result, 'tags'=>array_values(array_filter(array_map('trim', explode(',', $tags)))),
                    ]);
                } catch (\Throwable $error) {
                    $result['catalog_sync'] = 'pending';
                    $db->table('media_visual_analysis')->where('file_id', $file['id'])->update(['result_json'=>json_encode($result, JSON_THROW_ON_ERROR)]);
                    log_message('warning', 'Visual tags catalog sync pending: {message}', ['message'=>$error->getMessage()]);
                }
            }
        }
        return $result;
    }

    protected function requestAnalysis(string $path): array
    {
        $unavailable = ['status'=>'unavailable', 'items'=>[]];
        try {
            $endpoint = trim((string) env('greyhound.visualModelEndpoint', ''));
            if ($endpoint === '') {
                $eventEndpoint = (new \Config\GreyhoundAi())->eventModelEndpoint();
                $endpoint = preg_replace('~/predict/?$~', '/analyze', $eventEndpoint);
                if ($endpoint === '' || $endpoint === $eventEndpoint) throw new RuntimeException('Visual endpoint not configured.');
            }
            if (filesize($path) > 29 * 1024 * 1024) throw new RuntimeException('Image exceeds analysis upload limit.');
            $response = service('curlrequest')->post($endpoint, [
                'connect_timeout'=>3, 'timeout'=>90, 'http_errors'=>false,
                'multipart'=>['media'=>new \CURLFile($path, mime_content_type($path) ?: 'image/jpeg', basename($path)), 'tasks'=>json_encode(array_values(array_intersect($this->requestedTasks, ['objects','expressions'])))],
            ]);
            if ($response->getStatusCode() !== 200) throw new RuntimeException('Visual service unavailable.');
            $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (($payload['schema_version'] ?? null) !== 1) throw new RuntimeException('Unsupported visual response.');
            return ['objects'=>self::normalizeTask($payload['objects'] ?? null), 'expressions'=>self::normalizeTask($payload['expressions'] ?? null, true)];
        } catch (\Throwable $error) {
            log_message('warning', 'Visual inference unavailable: {message}', ['message'=>$error->getMessage()]);
            return ['objects'=>$unavailable, 'expressions'=>$unavailable];
        }
    }

    public static function normalizeTask(mixed $task, bool $expressions = false): array
    {
        if (!is_array($task) || !in_array($task['status'] ?? '', ['complete','no_face','disabled','unavailable','not_requested'], true) || !is_array($task['items'] ?? null)) {
            return ['status'=>'unavailable', 'items'=>[]];
        }
        $result = ['status'=>$task['status'], 'model'=>is_string($task['model'] ?? null) ? mb_substr($task['model'], 0, 100) : '', 'items'=>[]];
        if ($result['status'] !== 'complete') return $result;
        foreach (array_slice($task['items'], 0, 100) as $item) {
            if (!is_array($item) || !is_string($item['label'] ?? null) || !is_numeric($item['confidence'] ?? null)) continue;
            $label = trim($item['label']); $score = (float) $item['confidence'];
            if ($label === '' || mb_strlen($label) > 38 || preg_match('/[<>\x00-\x1f,]/u', $label) || !is_finite($score) || $score < 0 || $score > 100) continue;
            if ($expressions && !in_array($label, ['angry','disgust','fear','happy','sad','surprise','neutral'], true)) continue;
            $box = $item['box'] ?? null;
            if (!is_array($box) || count($box) !== 4 || !array_is_list($box)) continue;
            foreach ($box as $coordinate) if (!is_numeric($coordinate) || !is_finite((float) $coordinate) || $coordinate < 0 || $coordinate > 1) continue 2;
            if ($box[2] <= $box[0] || $box[3] <= $box[1]) continue;
            $result['items'][] = ['label'=>$label, 'confidence'=>round($score, 2), 'box'=>array_map('floatval', $box)];
        }
        return $result;
    }

    public static function automaticTags(array $result): array
    {
        $tags = [];
        foreach (['objects'=>50, 'expressions'=>80] as $task=>$threshold) {
            if (($result[$task]['status'] ?? '') !== 'complete') continue;
            foreach ($result[$task]['items'] as $item) {
                if ($item['confidence'] < $threshold) continue;
                $label = $item['label'];
                $key = mb_strtolower($label);
                if (isset($tags[$key]) && $tags[$key]['confidence'] >= $item['confidence']) continue;
                $tags[$key] = ['label'=>$label, 'confidence'=>$item['confidence'], 'source'=>$task, 'model'=>$result[$task]['model'] ?? ''];
            }
        }
        return array_slice(array_values($tags), 0, 25);
    }

    public function resultsFor(array $ids): array
    {
        $db = db_connect();
        if ($ids === [] || !$db->tableExists('media_visual_analysis')) return [];
        $results = [];
        foreach ($db->table('media_visual_analysis')->whereIn('file_id', $ids)->get()->getResultArray() as $row) {
            $result = json_decode($row['result_json'], true);
            if (is_array($result)) $results[$row['file_id']] = $result;
        }
        return $results;
    }
}
