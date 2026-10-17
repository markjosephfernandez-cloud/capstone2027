<?php

namespace App\Controllers\Api;

use App\Models\FileModel;
use CodeIgniter\I18n\Time;

class MobileHistoryController extends ApiController
{
    public function watch()
    {
        $file = $this->publishedFile();
        if ($file === null) return $this->error('not_found', 'Published media not found.', 404);

        $progress = $this->input()['progress_seconds'] ?? null;
        $progress = is_numeric($progress) ? max(0, (int) $progress) : null;
        $db = db_connect();
        $userId = (int) $this->actor()['employee_id'];
        $fileId = (int) $file['id'];
        $db->transStart();
        // Serialize repeat plays of this file, including simultaneous requests.
        $db->query('SELECT id FROM files WHERE id = ? FOR UPDATE', [$fileId]);
        $existing = $db->table('mobile_watch_history')->where('user_employee_id', $userId)
            ->where('file_id', $fileId)->orderBy('watched_at', 'DESC')->orderBy('id', 'DESC')->get(1)->getRowArray();
        $data = ['file_id' => $fileId, 'user_employee_id' => $userId,
            'progress_seconds' => $progress, 'watched_at' => Time::now('UTC')->toDateTimeString()];
        if ($existing) {
            $id = (int) $existing['id'];
            $db->table('mobile_watch_history')->where('id', $id)->update($data);
            $db->table('mobile_watch_history')->where('user_employee_id', $userId)
                ->where('file_id', $fileId)->where('id !=', $id)->delete();
        } else {
            $db->table('mobile_watch_history')->insert($data);
            $id = (int) $db->insertID();
        }
        $db->transComplete();
        if (! $db->transStatus()) return $this->error('history_failed', 'Could not save watch history.', 500);
        return $this->success(['id' => $id], 201);
    }

    public function download()
    {
        $file = $this->publishedFile();
        if ($file === null || ! (bool) $file['downloadable']) return $this->error('not_found', 'Downloadable media not found.', 404);

        $size = $this->input()['file_size_bytes'] ?? null;
        $size = is_numeric($size) ? max(0, (int) $size) : null;
        $db = db_connect();
        $db->table('mobile_download_history')->insert([
            'file_id' => (int) $file['id'],
            'user_employee_id' => (int) $this->actor()['employee_id'],
            'file_size_bytes' => $size,
            'downloaded_at' => Time::now('UTC')->toDateTimeString(),
        ]);

        return $this->success(['id' => (int) $db->insertID()], 201);
    }

    public function watchList()
    {
        return $this->success($this->history('mobile_watch_history', 'watched_at', 'progress_seconds'));
    }

    public function downloadList()
    {
        return $this->success($this->history('mobile_download_history', 'downloaded_at', 'file_size_bytes'));
    }

    public function clearWatch()
    {
        db_connect()->table('mobile_watch_history')->where('user_employee_id', (int) $this->actor()['employee_id'])->delete();
        return $this->success();
    }

    public function clearDownload()
    {
        db_connect()->table('mobile_download_history')->where('user_employee_id', (int) $this->actor()['employee_id'])->delete();
        return $this->success();
    }

    /** @return array<string, mixed>|null */
    private function publishedFile(): ?array
    {
        $fileId = (int) ($this->input()['file_id'] ?? 0);
        if ($fileId < 1) return null;

        return (new FileModel())->where([
            'id' => $fileId,
            'is_deleted' => 0,
            'release_status' => 'published',
        ])->where('app_published_at IS NOT NULL', null, false)->first();
    }

    /** @return list<array<string, mixed>> */
    private function history(string $table, string $timestamp, string $extraColumn): array
    {
        $query = db_connect()->table($table . ' h')
            ->select("h.id, h.file_id, h.{$extraColumn}, h.{$timestamp}, f.filename, f.display_title, f.filetype, f.media_kind")
            ->join('files f', 'f.id=h.file_id')
            ->where('h.user_employee_id', (int) $this->actor()['employee_id'])
            ->where('f.is_deleted', 0)
            ->where('f.release_status', 'published')->where('f.app_published_at IS NOT NULL', null, false)
            ->orderBy('h.' . $timestamp, 'DESC')->orderBy('h.id', 'DESC');
        if ($table === 'mobile_watch_history') {
            // Keep only the most recent play per account/video, before pagination.
            $query->where('NOT EXISTS (SELECT 1 FROM mobile_watch_history newer WHERE newer.user_employee_id = h.user_employee_id AND newer.file_id = h.file_id AND (newer.watched_at > h.watched_at OR (newer.watched_at = h.watched_at AND newer.id > h.id)))', null, false);
        }
        $rows = $query->limit(200)
            ->get()->getResultArray();

        foreach ($rows as &$row) {
            $path = $row['media_kind'] === 'video'
                ? 'api/v1/mobile/media/' . $row['file_id'] . '/stream'
                : 'api/v1/mobile/media/' . $row['file_id'] . '/preview';
            $row['file_url'] = $this->externalUrl($path);
            $row['filename'] = $row['display_title'] ?: $row['filename'];
            unset($row['display_title']);
        }

        return $rows;
    }
}
