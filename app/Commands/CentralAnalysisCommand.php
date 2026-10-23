<?php
namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CentralAnalysisCommand extends BaseCommand
{
    protected $group = 'Greyhound';
    protected $name = 'server-library:analyze';
    protected $description = 'Process queued server image metadata/AI jobs without blocking uploads.';
    protected $options = ['--status'=>'Show queue counts without running analysis.','--retry-failed'=>'Reset exhausted jobs before processing a batch.'];
    public function run(array $params)
    {
        if (! (new \Config\Storage())->centralized()) {
            CLI::error('Central library is not active.');
            return EXIT_ERROR;
        }
        $db = db_connect();
        if (CLI::getOption('status') !== null) {
            CLI::write(json_encode($db->table('server_analysis_jobs')->select('status')->selectCount('id','total')->groupBy('status')->get()->getResultArray(),JSON_PRETTY_PRINT));
            return EXIT_SUCCESS;
        }
        $lock = fopen(WRITEPATH.'cache/central-analysis.lock','c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            CLI::error('Analysis worker already running.');
            return EXIT_ERROR;
        }
        try {
            if (CLI::getOption('retry-failed') !== null) {
                $db->table('server_analysis_jobs')->whereIn('status', ['pending', 'failed'])->where('attempts >=', 3)->update(['status' => 'pending', 'attempts' => 0, 'last_error' => null]);
            }
            foreach ($db->table('server_analysis_jobs')->whereIn('status',['pending','failed'])->where('attempts <',3)->orderBy('id')->limit(20)->get()->getResultArray() as $job) {
                $db->table('server_analysis_jobs')->where('id',$job['id'])->update(['attempts'=>$job['attempts']+1]);
                try {
                    $media = $db->table('server_media')->where('id',$job['media_id'])->where('is_deleted',0)->get()->getRowArray();
                    if (! $media || ! $media['file_id']) {
                        throw new \RuntimeException('Media record unavailable.');
                    }
                    $file = $db->table('files')->where('id',$media['file_id'])->where('is_deleted',0)->get()->getRowArray();
                    if (! $file) {
                        throw new \RuntimeException('Original unavailable.');
                    }
                    $review = $db->tableExists('ai_event_detections') ? $db->table('ai_event_detections')->where('media_id',$file['id'])->get()->getRowArray() : null;
                    $result = !empty($review['reviewed_at']) ? $review + ['classification'=>$file['classification'],'needs_review'=>false]
                        : ((str_starts_with($file['mime_type'] ?? '', 'image/') || in_array(strtolower($file['filetype'] ?? ''), ['jpg','jpeg','png','webp','bmp','tif','tiff','gif'], true)) ? service('eventDetection')->classifyAndStore($file, true)
                        : ['event_name'=>null,'classification'=>'Unclassified Event','confidence_score'=>0,'needs_review'=>true]);
                    if (str_starts_with($file['mime_type'] ?? '', 'image/')) {
                        $result['visual_analysis'] = (new \App\Services\VisualMediaAnalysisService())->analyzeAndStore($file);
                    }
                    $result['tags'] = array_values(array_filter(array_map('trim', explode(',', (string) ($db->table('files')->where('id', $file['id'])->get()->getRowArray()['tags'] ?? '')))));
                    $result['analysis_status'] = 'complete';
                    $originalResult = $result;
                    if ($result['needs_review'] ?? false) {
                        $result['classification'] = 'Unclassified Event';
                    }
                    $db->table('files')->where('id',$file['id'])->update(['classification'=>$result['classification']]);
                    (new \App\Services\ServerLibraryEditor(new \App\Services\ServerLibraryService()))->saveAnalysis($media['path'],$result);
                    service('passports')->generate($file,['employee_id'=>$file['employee_id']],$result);
                    $db->table('server_analysis_jobs')->where('id',$job['id'])->update(['status'=>'complete','result_json'=>$job['result_json'] ?? json_encode($originalResult,JSON_THROW_ON_ERROR),'last_error'=>null,'updated_at'=>gmdate('Y-m-d H:i:s')]);
                } catch (\Throwable $error) {
                    $db->table('server_analysis_jobs')->where('id',$job['id'])->update(['status'=>'failed','last_error'=>mb_substr($error->getMessage(),0,2000),'updated_at'=>gmdate('Y-m-d H:i:s')]);
                    CLI::error($error->getMessage());
                }
            }
        } finally { flock($lock,LOCK_UN); fclose($lock); }
        try {
            $cleanup = (new \App\Services\CentralMigrationCleanup())->run();
            if (($cleanup['remaining'] ?? 0) > 0 || ($cleanup['removed'] ?? 0) > 0) {
                CLI::write('Migration temporary cleanup: ' . json_encode($cleanup));
            }
        } catch (\Throwable $error) { CLI::error('Migration temporary cleanup deferred: '.$error->getMessage()); }
        return EXIT_SUCCESS;
    }
}
