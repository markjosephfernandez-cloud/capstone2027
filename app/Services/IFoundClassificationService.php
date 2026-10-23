<?php

namespace App\Services;

use Config\GreyhoundAi;

/** Compares confident image predictions across the entire folder, before pagination. */
class IFoundClassificationService
{
    public function __construct(private readonly GreyhoundAi $config = new GreyhoundAi())
    {
    }

    /** Input is newest-first; retain that order within each result group. */
    public function reviewPage(array $matchingFiles, int $limit, int $offset): array
    {
        return array_slice($this->rankedResults($matchingFiles), $offset, $limit);
    }

    public function rankedResults(array $matchingFiles, string $aiResult = ''): array
    {
        $files = $this->annotate($matchingFiles);
        if ($aiResult !== '') {
            $files = array_values(array_filter($files, static fn ($file) => $file['ai_result'] === $aiResult));
        }
        $priority = ['needs_review' => 0, 'different' => 1, 'dominant' => 2, 'reviewed' => 3, 'not_applicable' => 4];
        usort($files, static fn ($a, $b) => $priority[$a['ai_result']] <=> $priority[$b['ai_result']]);
        return $files;
    }

    public function annotate(array $visibleFiles): array
    {
        if ($visibleFiles === []) {
            return [];
        }
        $folderIds = array_values(array_unique(array_map(static fn ($file) => (int) ($file['folder_id'] ?? 0), $visibleFiles)));
        $query = db_connect()->table('files f')
            ->select('f.id, f.folder_id, f.filetype, f.mime_type, ed.event_name, ed.confidence_score, ed.detector_version, ed.evidence_json')
            ->join('ai_event_detections ed', 'ed.media_id = f.id', 'inner')
            ->where('f.is_deleted', 0)
            ->groupStart()->whereIn('f.folder_id', $folderIds);
        if (in_array(0, $folderIds, true)) {
            $query->orWhere('f.folder_id', null);
        }
        $allFiles = $query->groupEnd()->get()->getResultArray();
        $counts = [];
        foreach ($allFiles as $file) {
            $event = $this->confidentPrediction($file);
            if ($event === null) {
                continue;
            }
            $folder = (int) ($file['folder_id'] ?? 0);
            $counts[$folder][$event] = ($counts[$folder][$event] ?? 0) + 1;
        }
        foreach ($visibleFiles as &$file) {
            $folderCounts = $counts[(int) ($file['folder_id'] ?? 0)] ?? [];
            $highest = $folderCounts === [] ? 0 : max($folderCounts);
            $leaders = array_keys(array_filter($folderCounts, static fn ($count) => $count === $highest));
            $dominant = count($leaders) === 1 ? $leaders[0] : null;
            $file['dominant_event'] = $dominant;
            $file['dominant_tied'] = count($leaders) > 1;
            $file['ai_prediction'] = $this->prediction($file);
            $reviewed = ! empty($file['reviewed_at']) && (int) ($file['needs_review'] ?? 1) === 0;
            $event = $this->confidentPrediction($file);
            if (! is_string($event) || ! array_key_exists($event, $this->config->eventKeywords)) {
                $event = null;
            }
            $file['result_from_review'] = $reviewed;
            $file['ai_result'] = ! $this->isImage($file) ? 'not_applicable'
                : ($reviewed ? 'reviewed' : ($event === null || $dominant === null ? 'needs_review' : ($event === $dominant ? 'dominant' : 'different')));
        }
        unset($file);
        return $visibleFiles;
    }

    private function isImage(array $file): bool
    {
        return str_starts_with(strtolower((string) ($file['mime_type'] ?? '')), 'image/')
            || in_array(strtolower((string) ($file['filetype'] ?? '')), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic'], true);
    }

    private function evidence(array $file): array
    {
        $evidence = json_decode((string) ($file['evidence_json'] ?? ''), true);
        return is_array($evidence) ? $evidence : [];
    }

    private function confidentPrediction(array $file): ?string
    {
        $confidence = $file['confidence_score'] ?? null;
        if (! $this->isImage($file) || ! is_numeric($confidence) || ! is_finite((float) $confidence)
            || (float) $confidence < $this->config->reviewThreshold || (float) $confidence > 100
            || ($file['detector_version'] ?? '') === 'greyhound-keyword-baseline') return null;
        // Older keyword results sometimes carried a trained-model version label.
        foreach ($this->evidence($file) as $entry) {
            if (is_array($entry) && ($entry['type'] ?? '') === 'keyword') {
                return null;
            }
        }
        return $this->prediction($file);
    }

    private function prediction(array $file): ?string
    {
        // Human review changes event_name. Retain the AI prediction from evidence
        // for reference and for calculating the folder's AI-based dominant event.
        $labels = [
            'digital expo' => 'EXPO Activities', 'graduation' => 'Graduation Activities',
            'kasadyaan' => 'Kasadyaan Celebrations', 'kasadyaan:hara' => 'Kasadyaan: Hara',
            'kasadyaan:parada' => 'Kasadyaan: Parada', 'kasadyaan:pasundayag' => 'Kasadyaan: Pasundayag',
            'dal-uy' => 'Dal-uy Events', 'intramurals' => 'Intramurals',
        ];
        foreach ($this->evidence($file) as $entry) {
            if (is_array($entry) && ($entry['type'] ?? '') === 'image_classifier' && is_string($entry['value'] ?? null)) {
                return $labels[mb_strtolower(trim($entry['value']))] ?? null;
            }
        }
        $event = $file['event_name'] ?? null;
        return is_string($event) && array_key_exists($event, $this->config->eventKeywords) ? $event : null;
    }
}
