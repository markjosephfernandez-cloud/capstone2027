<?php
namespace App\Services;

use RuntimeException;

/** Copy selected roots exactly once; no permanent local staging library. */
class CentralDistributionService
{
    public function __construct(private readonly ServerLibraryService $library, private readonly StorageService $storage) {}

    public function deliver(array $paths, array $folders, string $department, array $actor, ?int $requestId = null): array
    {
        @set_time_limit(0);
        if (!\App\Libraries\RolePolicy::allows($actor,'files.assign') || !\App\Libraries\RolePolicy::allows($actor,'files.view_all')) throw new RuntimeException('Your account cannot distribute media.');
        if (count($paths) > ServerDistributionPicker::MAX_FILES || count($folders) > ServerDistributionPicker::MAX_FILES
            || count(array_filter(array_merge($paths,$folders),'is_string')) !== count($paths)+count($folders)) throw new RuntimeException('Choose a valid selection.');
        $db = db_connect();
        if (!$db->table('user')->where('role','department')->where('department',$department)->where('is_active',1)->countAllResults()) throw new RuntimeException('Choose an active department.');
        if ($requestId !== null) {
            $request = $db->table('media_requests')->where('id',$requestId)->where('department',$department)->get()->getRowArray();
            if (!$request || !in_array($request['status'],['submitted','pending_incomplete'],true)) throw new RuntimeException('Choose a new or incomplete transaction.');
        }
        $roots = array_values(array_unique($folders));
        foreach ($roots as $root) $this->library->resolve($root,true);
        $roots = array_values(array_filter($roots, static function ($root) use ($roots) {
            foreach ($roots as $other) if ($other !== $root && str_starts_with($root,$other.'/')) return false;
            return true;
        }));
        foreach (array_unique($paths) as $path) {
            $this->library->resolve($path);
            foreach ($roots as $root) if ($path === $root || str_starts_with($path,$root.'/')) continue 2;
            $roots[] = $path;
        }
        if (!$roots) throw new RuntimeException('Select a file or folder.');
        $destination = $this->storage->ensureDepartmentDirectory($department);
        if (is_link($destination.'/.delivery.lock')) throw new RuntimeException('Linked delivery lock rejected.');
        $lock = fopen($destination.'/.delivery.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Another delivery is in progress. Retry shortly.');
        $created = []; $ids = []; $folderIds = []; $deliveries = []; $folderDeliveries = []; $replacements = []; $backups = [];
        $identities = new CentralMediaService($this->library);
        $hadSession = session_status() === PHP_SESSION_ACTIVE;
        $previousAbort = ignore_user_abort(true);
        if ($hadSession) session()->close();
        try {
            $names = [];
            foreach ($roots as $root) {
                $name = basename($root);
                if (isset($names[mb_strtolower($name)]) || is_link($destination.'/'.$name)) throw new RuntimeException('Selected names conflict at the destination.');
                $names[mb_strtolower($name)] = true;
                if (file_exists($destination.'/'.$name)) {
                    $portable = $this->storage->databasePath($destination.'/'.$name);
                    $directory = is_dir($destination.'/'.$name);
                    $table = $directory ? 'media_assignment_folders' : 'media_assignments';
                    $column = $directory ? 'delivery_path' : 'delivery_filepath';
                    $sourceId = $directory ? $identities->folder($root) : $identities->register($root,$actor);
                    $previous = $requestId === null ? null : $db->table($table)->where('request_id',$requestId)
                        ->where('cycle_number',$request['current_cycle'])->where($directory ? 'folder_id' : 'media_id',$sourceId)
                        ->where($column,$portable)->where('status','pending')->get()->getRowArray();
                    if (!$previous || $request['status'] !== 'pending_incomplete') throw new RuntimeException('Destination already contains '.$name.'. Resolve the conflict before sending; nothing will be overwritten.');
                    $replacements[$destination.'/'.$name] = $portable;
                }
            }
            // Retain previous review copies on the server before installing corrections.
            foreach ($replacements as $physical=>$portable) {
                $history = $destination.'/.delivery-history';
                if (is_link($history)) throw new RuntimeException('Linked delivery history rejected.');
                if (!is_dir($history) && !mkdir($history,0770)) throw new RuntimeException('Could not preserve delivery history.');
                $archive = $history.'/'.bin2hex(random_bytes(16)).'-'.basename($physical);
                if (!rename($physical,$archive)) throw new RuntimeException('Could not preserve the previous delivery.');
                $backups[$physical] = $archive;
            }
            $copy = function (string $path, string $target) use (&$copy,&$created,&$ids,&$deliveries,$identities,$actor): void {
                try { $source = $this->library->resolve($path); $directory = false; }
                catch (RuntimeException) { $source = $this->library->resolve($path,true); $directory = true; }
                if ($directory) {
                    if (!mkdir($target,0770)) throw new RuntimeException('Could not create destination folder.');
                    $created[] = $target;
                    foreach (new \DirectoryIterator($source) as $entry) {
                        $name = $entry->getFilename();
                        if (ServerLibraryService::isMetadataName($name)) continue;
                        if ($entry->isLink()) throw new RuntimeException('Linked items cannot be distributed.');
                        $child = $path . '/' . $name;
                        // Match the picker: unsupported share names are not selected media.
                        try { $this->library->relative($child); }
                        catch (RuntimeException) { continue; }
                        $copy($child, $target . '/' . $name);
                    }
                    return;
                }
                $id = $identities->register($path,$actor);
                $input = fopen($source,'rb'); $output = @fopen($target,'xb');
                if (!$input || !$output) { if (is_resource($input)) fclose($input); if (is_resource($output)) fclose($output); throw new RuntimeException('Could not create department copy.'); }
                $created[] = $target;
                try { $bytes = stream_copy_to_stream($input,$output); fflush($output); }
                finally { fclose($input); fclose($output); }
                if ($bytes !== filesize($source) || hash_file('sha256',$source) !== hash_file('sha256',$target)) throw new RuntimeException('Department copy verification failed.');
                $ids[] = $id;
                $deliveries[$id] = $this->storage->databasePath($target);
            };
            foreach ($roots as $root) {
                $target = $destination.'/'.basename($root);
                $copy($root,$target);
                if (is_dir($target)) {
                    $id = $identities->folder($root); $folderIds[] = $id;
                    $folderDeliveries[$id] = $this->storage->databasePath($target);
                }
            }
            $db->transBegin();
            try {
                foreach ($backups as $physical=>$archive) {
                    $old = $replacements[$physical]; $new = $this->storage->databasePath($archive);
                    foreach (['media_assignments'=>'delivery_filepath','media_assignment_folders'=>'delivery_path'] as $table=>$column) {
                        $rows = $db->table($table)->where('request_id',$requestId)->groupStart()->where($column,$old)->orLike($column,$old.'/','after')->groupEnd()->get()->getResultArray();
                        foreach ($rows as $row) $db->table($table)->where('id',$row['id'])->update([$column=>$new.substr($row[$column],strlen($old))]);
                    }
                }
                $result = service('distribution')->assign($ids,$folderIds,$department,$requestId,$requestId === null ? 'direct' : 'request',$actor);
                $requestId = (int)$result['request_id'];
                foreach ($deliveries as $id=>$path) $db->table('media_assignments')->where('request_id',$requestId)->where('cycle_number',$result['cycle'])->where('media_id',$id)->where('status','pending')->update(['delivery_filepath'=>$path]);
                foreach ($folderDeliveries as $id=>$path) $db->table('media_assignment_folders')->where('request_id',$requestId)->where('cycle_number',$result['cycle'])->where('folder_id',$id)->where('status','pending')->update(['delivery_path'=>$path]);
                if (!$db->transStatus() || !$db->transCommit()) throw new RuntimeException('Could not record the delivery.');
            } catch (\Throwable $error) { while ($db->transDepth > 0) $db->transRollback(); throw $error; }
            return $result + ['copied'=>count($ids)];
        } catch (\Throwable $error) {
            foreach (array_reverse($created) as $path) { if (is_dir($path)) @rmdir($path); else @unlink($path); }
            foreach (array_reverse($backups,true) as $physical=>$archive) {
                if (!rename($archive,$physical)) log_message('critical','Could not restore prior department delivery: {path}',['path'=>$physical]);
            }
            throw $error;
        } finally {
            flock($lock,LOCK_UN); fclose($lock);
            ignore_user_abort((bool)$previousAbort);
            if ($hadSession) session()->start();
        }
    }
}
