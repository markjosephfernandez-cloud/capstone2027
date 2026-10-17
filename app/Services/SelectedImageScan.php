<?php
namespace App\Services;

use RuntimeException;

/** Runs only analyses that have not completed for each selected image. */
class SelectedImageScan
{
    public const TASKS = ['events', 'objects', 'expressions'];

    public function __construct(private readonly ServerLibraryService $library = new ServerLibraryService()) {}

    public static function validateModes(mixed $modes): array
    {
        if (!is_array($modes) || !$modes || count($modes) > 3 || count(array_filter($modes, 'is_string')) !== count($modes)
            || array_diff($modes, ['events','objects','expressions'])) throw new RuntimeException('Choose at least one valid scan option.');
        return array_values(array_unique($modes));
    }

    public function process(string $path, int $employee, array $modes, array &$entries): array
    {
        $this->library->resolve($path);
        $file = $this->fileFor($path, $employee);
        $id = (int) $file['id'];
        $pending = array_values(array_diff(self::TASKS, $this->completedTasks($file)));
        if (!$pending) return ['status'=>'skipped', 'file_id'=>$id, 'message'=>basename($path).' already scanned by all three analyses.'];

        $errors = [];
        if (in_array('events', $pending, true)) {
            try { $this->detectEvent($file); }
            catch (\RuntimeException $error) { $errors[] = 'Event detection: '.$error->getMessage(); }
        }
        $tasks = array_values(array_intersect($pending, ['objects', 'expressions']));
        if ($tasks) {
            try {
                $result = $this->analyzeVisual($file, $tasks);
                foreach ($tasks as $task) {
                    $finished = $task === 'expressions' ? ['complete', 'no_face'] : ['complete'];
                    if (!in_array($result[$task]['status'] ?? '', $finished, true)) $errors[] = ucfirst($task).' analysis is unavailable; retry later.';
                }
            } catch (\RuntimeException $error) { $errors[] = $error->getMessage(); }
        }
        return ['status'=>$errors ? 'failed' : 'imported', 'file_id'=>$id,
            'message'=>basename($path).($errors ? ': '.implode(' ', $errors) : ' scanned; existing results preserved.')];
    }

    protected function completedTasks(array $file): array
    {
        $db = db_connect();
        $event = $db->tableExists('ai_event_detections')
            ? $db->table('ai_event_detections')->where('media_id', $file['id'])->get()->getRowArray() : null;
        $visual = (new VisualMediaAnalysisService())->resultsFor([(int) $file['id']])[$file['id']] ?? [];
        return self::completedFromResults($file, $event, $visual);
    }

    public static function completedFromResults(array $file, ?array $event, array $visual): array
    {
        $completed = [];
        // Keyword fallback is not a successful AI scan. Reviewed results are final.
        if (!empty($event['reviewed_at']) || ($event !== null && !empty($event['processed_at'])
            && !empty($event['detector_version']) && $event['detector_version'] !== 'greyhound-keyword-baseline')
            || ($event === null && !empty($file['last_scanned']))) $completed[] = 'events';
        if (($visual['objects']['status'] ?? '') === 'complete') $completed[] = 'objects';
        if (in_array($visual['expressions']['status'] ?? '', ['complete', 'no_face'], true)) $completed[] = 'expressions';
        return $completed;
    }

    protected function fileFor(string $path, int $employee): array
    {
        $id = (new CentralMediaService($this->library))->register($path, ['employee_id'=>$employee]);
        return db_connect()->table('files')->where('id',$id)->get()->getRowArray();
    }

    protected function detectEvent(array $file): void
    {
        $review = db_connect()->table('ai_event_detections')->where('media_id',$file['id'])->get()->getRowArray();
        if (empty($review['reviewed_at'])) {
            service('eventDetection')->classifyAndStore($file, true);
            (new CentralMediaService($this->library))->syncReview((int) $file['id']);
        }
    }

    protected function analyzeVisual(array $file, array $tasks): array
    {
        return (new VisualMediaAnalysisService())->analyzeAndStore($file, $tasks);
    }


}
