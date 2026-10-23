<?php

namespace App\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;

/** Saves server uploads directly, with event analysis and metadata passports. */
class ServerLibraryUploadService extends ServerLibraryEditor
{
    public function chunks(): ChunkUploadService
    {
        $root = $this->privateDirectory(true) . '/upload-chunks';
        $this->rejectLink($root);
        return new ChunkUploadService($root);
    }

    public function receive(UploadedFile $file, array $metadata, array $actor, ?callable $afterSave = null): array
    {
        if (! $file->isValid() || $file->hasMoved()) {
            throw new RuntimeException('The upload is incomplete. Try again.');
        }
        $name = $file->getClientName();
        $folder = $this->library->relative((string)($metadata['folder_id'] ?? ''));
        $this->library->resolve($folder, true);
        $relative = $this->library->relative((string)($metadata['relative_path'] ?? ''));
        if ($relative !== '' && basename($relative) !== $name) {
            throw new RuntimeException('The folder upload filename does not match its path.');
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg','jpeg','png','gif','webp','mp4','mov','avi','mkv','webm','m4v','mp3','wav','ogg','pdf','doc','docx','xls','xlsx'], true)
            || !in_array($file->getMimeType(), $this->config->allowedMimeTypes(), true)) throw new RuntimeException('This file type is not supported: ' . $name);
        if ($this->config->maxUploadBytes() > 0 && $file->getSize() > $this->config->maxUploadBytes()) {
            throw new RuntimeException('The file exceeds the configured upload limit.');
        }
        $batch = (string)($metadata['upload_batch_id'] ?? '');
        if (! preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $batch)) {
            throw new RuntimeException('Invalid upload batch identifier.');
        }
        $subfolder = $relative === '' ? '' : $this->parent($relative);
        $destinationFolder = trim($folder . '/' . $subfolder, '/');
        $hash = hash_file('sha256', $file->getTempName());
        if ($hash === false) {
            throw new RuntimeException('The uploaded file cannot be read.');
        }
        $analysis = $this->config->centralized()
            ? ['event_name'=>null,'classification'=>'Unclassified Event','confidence_score'=>0,'needs_review'=>true,'analysis_status'=>'pending']
            : $this->analyze($file, $destinationFolder);
        $exif = function_exists('exif_read_data') && in_array($extension, ['jpg','jpeg'], true) ? @exif_read_data($file->getTempName()) : [];
        $dateTaken = null;
        if (is_array($exif)) {
            $date = \DateTimeImmutable::createFromFormat('!Y:m:d H:i:s', (string)($exif['DateTimeOriginal'] ?? $exif['DateTimeDigitized'] ?? ''));
            if ($date !== false) {
                $dateTaken = $date->format('Y-m-d H:i:s');
            }
        }
        $now = gmdate('Y-m-d H:i:s');
        $passport = array_merge($analysis, ['passport_uid'=>'FU-SRV-'.bin2hex(random_bytes(12)), 'filename'=>$name,
            'content_hash'=>$hash, 'date_taken'=>$dateTaken, 'upload_date'=>$now, 'uploaded_by'=>(string)($actor['employee_id'] ?? ''),
            'photographer'=>$actor['name'] ?? '', 'copyright_owner'=>'Foundation University', 'distribution_rights'=>'Internal Use Only', 'c2pa_compliant'=>false]);
        $passport['digital_signature'] = hash('sha256', json_encode($passport, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $record = ['content_hash'=>$hash, 'upload_batch_id'=>$batch, 'uploaded_at'=>$now, 'passport'=>$passport];
        $dimensions = @getimagesize($file->getTempName());
        $details = $passport + ['width'=>$dimensions[0] ?? null,'height'=>$dimensions[1] ?? null,
            'mime_type'=>$file->getMimeType(),'size'=>$file->getSize(),'exif'=>is_array($exif) ? $exif : []];
        $result = [];
        $this->edit(function (array &$state, array &$undo) use ($file, $name, $destinationFolder, $record, $actor, $details, $afterSave, &$result): void {
            $directory = $this->ensureFolder($destinationFolder, $undo);
            $finalName = $this->availableName($directory, $name);
            $relative = ($destinationFolder === '' ? '' : $destinationFolder . '/') . $finalName;
            $target = $directory . '/' . $finalName;
            $temporary = $this->privateDirectory(true) . '/incoming-' . bin2hex(random_bytes(16));
            $output = @fopen($temporary, 'xb'); $input = @fopen($file->getTempName(), 'rb');
            if ($output === false || $input === false) {
                if (is_resource($output)) {
                    fclose($output);
                    unlink($temporary);
                }
                if (is_resource($input)) {
                    fclose($input);
                }
                throw new RuntimeException('The upload could not be opened on the server.');
            }
            $undo[] = static fn () => !file_exists($temporary) || unlink($temporary);
            try { if (stream_copy_to_stream($input, $output) !== $file->getSize() || !fflush($output)) throw new RuntimeException('The server upload could not be completed.'); }
            finally { fclose($input); fclose($output); }
            $this->relocate($temporary, $target, $undo);
            $record['size'] = filesize($target); $record['modified_at'] = filemtime($target);
            $state['media'][$relative] = $record;
            $state['tags'][$relative] = array_values(array_filter([substr($record['passport']['date_taken'] ?? $record['uploaded_at'],0,4), basename($destinationFolder), $record['passport']['event_name'] ?? null]));
            if ($this->config->centralized()) {
                (new CentralMediaService($this->library))->queueAnalysis($relative, $actor, $details + ['server_path' => $relative]);
            }
            if ($afterSave) {
                $afterSave($relative);
            }
            $result = ['ok'=>true, 'uploaded'=>1, 'complete'=>true, 'duplicate'=>false, 'path'=>$relative];
        });
        $this->library->invalidateCounts($folder);
        $this->library->invalidateCounts($destinationFolder);
        $this->refreshSavedCatalog([$result['path']]);
        return $result;
    }

    protected function analyze(UploadedFile $file, string $folder): array
    {
        return service('eventDetection')->analyzeFile($file->getTempName(), $file->getClientName(), $folder);
    }

    /** Legacy file access retained for recovery of previously stored copies. */
    public function duplicateFile(string $id): array
    {
        $state = $this->state();
        if (! preg_match('/^[a-f0-9]{32}$/D', $id) || ! isset($state['duplicates'][$id])) {
            throw new RuntimeException('This duplicate is unavailable.');
        }
        $path = $this->privateDirectory(false) . '/duplicate-' . $id;
        $this->rejectLink($path);
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('This duplicate is unavailable.');
        }
        return ['path'=>$path, 'name'=>$state['duplicates'][$id]['name']];
    }

    public function restoreDuplicate(string $id): void
    {
        $restoredPath = null;
        $this->edit(function (array &$state, array &$undo) use ($id, &$restoredPath): void {
            if (! preg_match('/^[a-f0-9]{32}$/D', $id) || ! isset($state['duplicates'][$id])) {
                throw new RuntimeException('This duplicate is unavailable.');
            }
            $record = $state['duplicates'][$id];
            $relative = $this->library->relative($record['path']);
            $directory = $this->ensureFolder($this->parent($relative), $undo);
            $name = $this->availableName($directory, basename($relative));
            $source = $this->privateDirectory(true) . '/duplicate-' . $id;
            $this->rejectLink($source);
            $this->relocate($source, $directory . '/' . $name, $undo);
            $relative = ($this->parent($relative) === '' ? '' : $this->parent($relative) . '/') . $name;
            $state['media'][$relative] = $record['metadata'];
            $state['tags'][$relative] = $record['tags'] ?? array_values(array_filter([substr($record['metadata']['passport']['date_taken'] ?? $record['metadata']['uploaded_at'], 0, 4), basename($this->parent($relative)), $record['metadata']['passport']['event_name'] ?? null]));
            $this->library->invalidateCounts($this->parent($relative));
            if ($this->config->centralized()) {
                $central = new CentralMediaService($this->library);
                if (!empty($record['identities'])) {
                    $central->markDeleted($record['path'], false, $record['identities']);
                    if ($relative !== $record['path']) {
                        $central->move($record['path'], $relative);
                    }
                }
                $fileId = $central->register($relative);
                db_connect()->table('files')->where('id',$fileId)->update(['tags'=>implode(', ', $state['tags'][$relative])]);
            }
            $restoredPath = $relative;
            unset($state['duplicates'][$id]);
        });
        if ($restoredPath !== null) {
            $this->refreshSavedCatalog([$restoredPath]);
        }
    }

    private function ensureFolder(string $relative, array &$undo): string
    {
        $this->library->relative($relative);
        $current = '';
        foreach ($relative === '' ? [] : explode('/', $relative) as $name) {
            $next = $this->library->resolve($current, true) . '/' . $name;
            if (!file_exists($next) && !is_link($next)) {
                $next = $this->destination($current, $name);
                if (! @mkdir($next, 0775)) {
                    throw new RuntimeException('The upload folder could not be created.');
                }
                $undo[] = static fn () => rmdir($next);
                $this->library->invalidateCounts($current);
            }
            $current .= ($current === '' ? '' : '/') . $name;
            $this->library->resolve($current, true);
        }
        return $this->library->resolve($relative, true);
    }

    private function availableName(string $directory, string $name): string
    {
        // Validate even when the collision suffix would otherwise hide a bad name.
        $relative = ltrim(substr($directory, strlen($this->library->root())), '/');
        for ($suffix=0; $suffix<10000; $suffix++) {
            $candidate = $suffix === 0 ? $name : pathinfo($name, PATHINFO_FILENAME) . ' (' . $suffix . ').' . pathinfo($name, PATHINFO_EXTENSION);
            if (file_exists($directory . '/' . $candidate) || is_link($directory . '/' . $candidate)) {
                continue;
            }
            $this->destination($relative, $candidate);
            return $candidate;
        }
        throw new RuntimeException('No available filename could be found.');
    }
}
