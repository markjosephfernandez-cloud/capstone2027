<?php
namespace App\Services;

use RuntimeException;

/** Queue missing image predictions without replacing existing AI or human decisions. */
class IFoundAnalysisQueue
{
    public function enqueue(array $ids): array
    {
        $db = db_connect();
        $files = $db->table('files')->whereIn('id', $ids)->where('is_deleted', 0)->get()->getResultArray();
        $queued = []; $skipped = count($ids) - count($files); $unavailable = 0;
        foreach ($files as $file) {
            if (!str_starts_with((string) $file['mime_type'], 'image/') && !in_array(strtolower((string) $file['filetype']), ['jpg','jpeg','png','webp','bmp','tif','tiff','gif'], true)) { $skipped++; continue; }
            if ($db->table('ai_event_detections')->where('media_id', $file['id'])->countAllResults()) { $skipped++; continue; }
            $media = $db->table('server_media')->where('file_id', $file['id'])->where('is_deleted', 0)->orderBy('id','DESC')->get()->getRowArray();
            if (!$media) { $unavailable++; continue; }
            $db->table('server_analysis_jobs')->ignore(true)->insert(['media_id'=>$media['id'],'status'=>'pending','attempts'=>0,'updated_at'=>gmdate('Y-m-d H:i:s')]);
            // Reset only failed jobs. Never reset an in-progress/pending job's attempts.
            $db->table('server_analysis_jobs')->where('media_id',$media['id'])->where('status','failed')->update(['status'=>'pending','attempts'=>0,'last_error'=>null,'updated_at'=>gmdate('Y-m-d H:i:s')]);
            $job = $db->table('server_analysis_jobs')->where('media_id',$media['id'])->get()->getRowArray();
            if (!$job) throw new RuntimeException('Could not queue image analysis.');
            if ($job['status'] === 'complete') { $skipped++; continue; }
            $queued[] = (int) $file['id'];
        }
        return compact('queued','skipped','unavailable');
    }

    public function status(array $ids): array
    {
        $db = db_connect(); $results = [];
        foreach ($ids as $id) {
            $file = $db->table('files')->select('id, filename')->where('id',$id)->where('is_deleted',0)->get()->getRowArray();
            if (!$file) { $results[] = ['file_id'=>$id,'status'=>'unavailable']; continue; }
            $media = $db->table('server_media')->where('file_id',$id)->where('is_deleted',0)->orderBy('id','DESC')->get()->getRowArray();
            $job = $media ? $db->table('server_analysis_jobs')->where('media_id',$media['id'])->get()->getRowArray() : null;
            $results[] = ['file_id'=>$id, 'filename'=>$file['filename'], 'status'=>$job && $job['status'] === 'failed' && (int) $job['attempts'] < 3 ? 'retrying' : ($job['status'] ?? 'unavailable')];
        }
        return $results;
    }
}
