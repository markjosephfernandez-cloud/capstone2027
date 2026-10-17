<?php
namespace App\Services;

use App\Libraries\MediaMimeType;
use Config\Storage;
use RuntimeException;

/** Stable identities bridge catalog paths to existing publishing/review records. */
class CentralMediaService
{
    public function __construct(private readonly ServerLibraryService $library = new ServerLibraryService()) {}

    public function identity(string $path, bool $directory = false): array
    {
        $path = $this->library->relative($path);
        if ($path === '') throw new RuntimeException('The root has no media identity.');
        $db = db_connect();
        $key = ['source_key'=>$this->library->catalogSourceKey(), 'path_hash'=>hash('sha256',$path)];
        $row = $db->table('server_media')->where($key)->get()->getRowArray();
        if (!$row) {
            $db->table('server_media')->ignore(true)->insert($key + ['path'=>$path,'is_dir'=>$directory ? 1 : 0,'is_deleted'=>0,'updated_at'=>gmdate('Y-m-d H:i:s')]);
            $row = $db->table('server_media')->where($key)->get()->getRowArray();
        }
        if (!$row) throw new RuntimeException('Could not register server media.');
        return $row;
    }

    public function register(string $path, array $actor = [], array $metadata = []): int
    {
        $physical = $this->library->resolve($path);
        $row = $this->identity($path);
        $db = db_connect();
        $portable = 'server-library/' . $path;
        $existing = !empty($row['file_id']) ? $db->table('files')->where('id',$row['file_id'])->get()->getRowArray()
            : $db->table('files')->where('is_deleted',0)->groupStart()->where('filepath',$portable)->orWhere('filepath',$physical)->groupEnd()->get()->getRowArray();
        if (!$existing) {
            $mime = MediaMimeType::forFile($physical);
            $folder = dirname($path) === '.' ? '' : dirname($path);
            $folderId = $folder === '' ? null : $this->folder($folder);
            $data = ['filename'=>basename($path), 'display_title'=>basename($path), 'filepath'=>$portable,
                'filetype'=>strtolower(pathinfo($path,PATHINFO_EXTENSION)), 'mime_type'=>$mime, 'size'=>filesize($physical),
                'media_kind'=>str_starts_with($mime,'image/') ? 'image' : (str_starts_with($mime,'video/') ? 'video' : 'document'),
                'folder_id'=>$folderId, 'employee_id'=>$actor['employee_id'] ?? null, 'dateupload'=>gmdate('Y-m-d H:i:s'),
                'is_deleted'=>0, 'last_action'=>'uploaded', 'last_action_at'=>gmdate('Y-m-d H:i:s')];
            if (!$db->table('files')->insert($data)) throw new RuntimeException('Could not register the original.');
            $id = (int)$db->insertID();
        } else $id = (int)$existing['id'];
        $update = ['file_id'=>$id, 'is_deleted'=>0, 'updated_at'=>gmdate('Y-m-d H:i:s')];
        // EXIF can contain binary or legacy-encoded strings. Replace invalid text
        // bytes in the metadata copy; the original file retains its exact EXIF.
        if ($metadata !== []) $update['metadata_json'] = json_encode($metadata,JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $db->table('server_media')->where('id',$row['id'])->update($update);
        return $id;
    }

    public function folder(string $path): int
    {
        $this->library->resolve($path,true);
        $row = $this->identity($path,true);
        if (!empty($row['folder_id'])) return (int)$row['folder_id'];
        $parent = dirname($path) === '.' ? '' : dirname($path);
        $parentId = $parent === '' ? null : $this->folder($parent);
        $db = db_connect();
        $existing = $db->table('folder')->where('path','server-library/'.$path)->where('is_deleted',0)->get()->getRowArray();
        if (!$existing) {
            if (!$db->table('folder')->insert(['name'=>basename($path),'path'=>'server-library/'.$path,'parent_id'=>$parentId,'is_deleted'=>0,'created_at'=>gmdate('Y-m-d H:i:s')])) throw new RuntimeException('Could not register folder.');
            $id = (int)$db->insertID();
        } else $id = (int)$existing['id'];
        $db->table('server_media')->where('id',$row['id'])->update(['folder_id'=>$id]);
        return $id;
    }

    public function viewed(string $path, int $employee): void
    {
        if ($employee < 1) return;
        $path = $this->library->relative($path);
        $db = db_connect();
        $key = ['source_key'=>$this->library->catalogSourceKey(),'path_hash'=>hash('sha256',$path),'is_dir'=>0];
        $row = $db->table('server_media')->where($key)->get()->getRowArray();
        if (!$row) {
            if (!$db->tableExists('server_library_index') || !$db->table('server_library_index')->where($key)->countAllResults()) return;
            $row = $this->identity($path);
        }
        if ((int)$row['is_deleted'] === 1) return;
        $db->table('server_recent')->onConstraint(['employee_id','media_id'])->upsert([
            'employee_id'=>$employee,'media_id'=>$row['id'],'viewed_at'=>gmdate('Y-m-d H:i:s')]);
    }

    public function queueAnalysis(string $path, array $actor, array $metadata): void
    {
        $this->register($path,$actor,$metadata);
        $row = $this->identity($path);
        db_connect()->table('server_analysis_jobs')->ignore(true)->insert(['media_id'=>$row['id'],'status'=>'pending','attempts'=>0,'updated_at'=>gmdate('Y-m-d H:i:s')]);
    }

    public function move(string $old, string $new): void
    {
        $old = $this->library->relative($old);
        $new = $this->library->relative($new);
        $db = db_connect();
        $rows = $db->table('server_media')->where('source_key',$this->library->catalogSourceKey())->where('is_deleted',0)->groupStart()->where('path',$old)->orLike('path',$old.'/','after')->groupEnd()->get()->getResultArray();
        foreach ($rows as $row) {
            $path = $new . substr($row['path'],strlen($old));
            $db->table('server_media')->where('id',$row['id'])->update(['path'=>$path,'path_hash'=>hash('sha256',$path),'updated_at'=>gmdate('Y-m-d H:i:s')]);
        }
        foreach ($rows as $row) {
            $path = $new . substr($row['path'],strlen($old));
            $parent = dirname($path);
            $parentId = $parent === '.' ? null : $this->folder($parent);
            if ($row['file_id']) {
                $db->table('files')->where('id',$row['file_id'])->update(['filepath'=>'server-library/'.$path,'filename'=>basename($path),'folder_id'=>$parentId]);
                if ($db->tableExists('tags')) $db->table('tags')->where('file_id',$row['file_id'])->update(['filepath'=>'server-library/'.$path]);
            }
            if ($row['folder_id']) $db->table('folder')->where('id',$row['folder_id'])->update(['path'=>'server-library/'.$path,'name'=>basename($path),'parent_id'=>$parentId]);
        }
    }

    public function markDeleted(string $path, bool $deleted, array $identities = []): array
    {
        $db = db_connect();
        $query = $db->table('server_media')->where('source_key',$this->library->catalogSourceKey())->where('is_deleted',$deleted ? 0 : 1)
            ->groupStart()->where('path',$path)->orLike('path',$path.'/','after')->groupEnd();
        if ($identities) $query->whereIn('id',$identities);
        $rows = $query->get()->getResultArray();
        foreach ($rows as $row) {
            $db->table('server_media')->where('id',$row['id'])->update(['is_deleted'=>(int)$deleted,
                'path_hash'=>hash('sha256',$deleted ? 'trash:'.$row['id'] : $row['path']),'updated_at'=>gmdate('Y-m-d H:i:s')]);
            if ($row['file_id']) $db->table('files')->where('id',$row['file_id'])->update(['is_deleted'=>(int)$deleted]);
            if ($row['folder_id']) $db->table('folder')->where('id',$row['folder_id'])->update(['is_deleted'=>(int)$deleted]);
        }
        return array_column($rows,'id');
    }

    public function syncReview(int $fileId): void
    {
        $db = db_connect();
        $media = $db->table('server_media')->where('source_key',$this->library->catalogSourceKey())->where('file_id',$fileId)->where('is_deleted',0)->get()->getRowArray();
        if (!$media) return;
        $file = $db->table('files')->where('id',$fileId)->get()->getRowArray();
        $review = $db->table('ai_event_detections')->where('media_id',$fileId)->get()->getRowArray();
        if (!$file || !$review) return;
        (new ServerLibraryEditor($this->library))->saveAnalysis($media['path'],[
            'event_name'=>$review['event_name'],'classification'=>$file['classification'],'confidence_score'=>$review['confidence_score'],
            'needs_review'=>(bool)$review['needs_review'],'reviewed_at'=>$review['reviewed_at'],'reviewed_by'=>$review['reviewed_by'],
            'tags'=>array_values(array_filter(array_map('trim',explode(',',(string)$file['tags'])))),
        ]);
    }
}
