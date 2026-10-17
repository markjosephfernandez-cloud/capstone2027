<?php
namespace App\Services;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/** Department metadata and storage are owned independently of admin originals. */
class DepartmentLibraryService
{
    public function __construct(private BaseConnection $db, private StorageService $storage) {}

    public function capture(array $assignment, array $file, array &$createdPaths): int
    {
        if (($assignment['status'] ?? '') !== 'completed') throw new RuntimeException('Only completed deliveries can enter the department library.');
        $department = (string)$assignment['department'];
        $delivery = trim((string)($assignment['delivery_filepath'] ?? ''));
        if ($delivery === '') throw new RuntimeException('The department delivery path is missing.');
        $key = hash('sha256', $delivery);
        $existing = $this->db->table('department_files')->where('department',$department)->where('delivery_key',$key)->get()->getRowArray();
        if ($existing) return (int)$existing['id']; // Never resurrect independently deleted copies.
        try {
            $physical = $this->storage->resolveDatabaseFile($delivery);
            $ownerRoot = realpath($this->storage->departmentRoot() . '/' . $this->storage->departmentFolderName($department));
            if (!$ownerRoot || !str_starts_with($physical, $ownerRoot . DIRECTORY_SEPARATOR)) throw new RuntimeException('Delivery is outside its department storage.');
        } catch (RuntimeException $error) {
            // An old recovery record may have moved its only copy into admin Trash.
            $trash = $this->db->tableExists('trashfiles') ? $this->db->table('trashfiles')->where('file_id',$file['id'])->get()->getRowArray() : null;
            if (!$trash || ($trash['original_filepath'] ?? '') !== $delivery || (int)$file['is_deleted'] !== 1) throw $error;
            $physical = $this->storage->resolveDatabaseFile($file['filepath']);
        }
        // Recovery records can use the department copy as their admin original. Detach it.
        $sameAsOriginal = false;
        try { $sameAsOriginal = $physical === $this->storage->resolveDatabaseFile((string)$file['filepath']); } catch (RuntimeException) {}
        if ($sameAsOriginal) {
            $copy = $this->storage->storeDepartmentCopy($physical, $department, 'Independent library');
            $createdPaths[] = $copy['filepath'];
            $delivery = $this->storage->databasePath($copy['filepath']);
        }
        $fields = array_flip($this->db->getFieldNames('department_files'));
        $snapshot = array_intersect_key($file, $fields);
        unset($snapshot['id']);
        $snapshot = array_merge($snapshot, [
            'department'=>$department, 'source_media_id'=>(int)$file['id'], 'source_assignment_id'=>(int)$assignment['id'],
            'delivery_key'=>$key, 'filepath'=>$delivery, 'is_deleted'=>0, 'deleted_at'=>null,
            'folder_id'=>(new \Config\Storage())->centralized() ? $this->deliveryFolder($department,$delivery) : $this->folder($department, empty($file['folder_id']) ? null : (int)$file['folder_id']),
            'assigned_at'=>$assignment['completed_at'] ?? $assignment['sent_at'] ?? gmdate('Y-m-d H:i:s'),
        ]);
        if (!$this->db->table('department_files')->insert($snapshot)) throw new RuntimeException('Could not save independent department record.');
        return (int)$this->db->insertID();
    }

    private function deliveryFolder(string $department, string $delivery): ?int
    {
        $physical = $this->storage->resolveDatabaseFile($delivery);
        return $this->captureFolderPath($department,$this->storage->databasePath(dirname($physical)));
    }

    public function captureFolderPath(string $department, string $delivery): ?int
    {
        $root = $this->storage->departmentRoot() . '/' . $this->storage->departmentFolderName($department);
        $physical = $this->storage->resolveDatabaseDirectory($delivery);
        if ($physical === $root) return null;
        if (!str_starts_with($physical,$root.'/')) throw new RuntimeException('Delivery is outside the department.');
        $relative = substr($physical,strlen($root)+1);
        $parent = null; $path = $root;
        foreach (explode('/',$relative) as $name) {
            $path .= '/' . $name;
            $stored = $this->storage->databasePath($path);
            $row = $this->db->table('department_folders')->where('department',$department)->where('path',$stored)->where('is_deleted',0)->get()->getRowArray();
            if ($row) $parent = (int)$row['id'];
            else {
                $this->db->table('department_folders')->insert(['department'=>$department,'name'=>$name,'path'=>$stored,'parent_id'=>$parent,'is_deleted'=>0,'created_at'=>gmdate('Y-m-d H:i:s')]);
                $parent = (int)$this->db->insertID();
            }
        }
        return $parent;
    }

    private function folder(string $department, ?int $sourceId, array $visited = []): ?int
    {
        if (!$sourceId) return null;
        if (isset($visited[$sourceId])) throw new RuntimeException('Folder hierarchy contains a cycle.');
        $visited[$sourceId]=true;
        $existing=$this->db->table('department_folders')->where('department',$department)->where('source_folder_id',$sourceId)->get()->getRowArray();
        if ($existing) return (int)$existing['id'];
        $source=$this->db->table('folder')->where('id',$sourceId)->get()->getRowArray();
        if (!$source) return null;
        $parent=$this->folder($department, empty($source['parent_id'])?null:(int)$source['parent_id'], $visited);
        if (!$this->db->table('department_folders')->insert([
            'department'=>$department,'source_folder_id'=>$sourceId,'parent_id'=>$parent,'name'=>$source['name'],
            'path'=>$source['path']??$source['name'],'description'=>$source['description']??null,'created_at'=>$source['created_at']??gmdate('Y-m-d H:i:s'),'is_deleted'=>0,
        ])) throw new RuntimeException('Could not save department folder.');
        return (int)$this->db->insertID();
    }

    /** Preserves original source IDs for reports, without depending on their continued existence. */
    public function backfill(): array
    {
        $rows=$this->db->table('media_assignments')->where('status','completed')->orderBy('id')->get()->getResultArray();
        $report=['captured'=>0,'existing'=>0,'unavailable'=>[]];
        foreach ($rows as $assignment) {
            $file=$this->db->table('files')->where('id',$assignment['media_id'])->get()->getRowArray();
            if (!$file || empty($assignment['delivery_filepath'])) { $report['unavailable'][]=(int)$assignment['id']; continue; }
            $key=hash('sha256',$assignment['delivery_filepath']);
            if ($this->db->table('department_files')->where('department',$assignment['department'])->where('delivery_key',$key)->countAllResults()) { ++$report['existing']; continue; }
            $created=[];
            $this->db->transBegin();
            try {
                $this->capture($assignment,$file,$created);
                if (!$this->db->transStatus() || !$this->db->transCommit()) throw new RuntimeException('Department migration transaction failed.');
                ++$report['captured'];
            } catch (\Throwable $error) {
                $this->db->transRollback();
                foreach ($created as $path) if (is_file($path)) unlink($path);
                throw $error;
            }
        }
        return $report;
    }
}
