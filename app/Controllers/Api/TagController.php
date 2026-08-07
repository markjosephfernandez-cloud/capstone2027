<?php

namespace App\Controllers\Api;

use App\Models\FileModel;
use App\Models\TagModel;

class TagController extends ApiController
{
    public function index()
    {
        if ((string) ($this->actor()['role'] ?? '') === 'department') {
            return $this->error('forbidden', 'Department accounts may search only their assigned files.', 403);
        }
        $query = trim((string) $this->request->getGet('query'));
        if (mb_strlen($query) > 100) {
            return $this->error('validation_error', 'query may not exceed 100 characters.', 422);
        }

        $builder = db_connect()->table('tag')->select('tag, COUNT(*) AS usage_count')->groupBy('tag')->orderBy('usage_count', 'DESC')->orderBy('tag', 'ASC');
        if ($query !== '') {
            $builder->like('tag', $query);
        }

        return $this->success($builder->get(100)->getResultArray());
    }

    public function replace(int $fileId)
    {
        if (($response = $this->requireRole(['super_admin', 'admin', 'staff'])) !== null) {
            return $response;
        }
        $file = (new FileModel())->where('is_deleted', 0)->find($fileId);
        if ($file === null) {
            return $this->error('not_found', 'The requested file does not exist.', 404);
        }

        $tags = $this->input()['tags'] ?? null;
        if (! is_array($tags) || count($tags) > 25) {
            return $this->error('validation_error', 'tags must be an array containing no more than 25 values.', 422);
        }

        $clean = [];
        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                return $this->error('validation_error', 'Each tag must be a string.', 422);
            }
            $tag = trim(preg_replace('/\s+/u', ' ', $tag) ?? '');
            if ($tag === '' || mb_strlen($tag) > 100) {
                return $this->error('validation_error', 'Each tag must contain 1 to 100 characters.', 422);
            }
            $clean[mb_strtolower($tag)] = $tag;
        }
        $clean = array_values($clean);

        $db = db_connect();
        $db->transStart();
        (new TagModel())->where('file_id', $fileId)->delete();
        $type = $this->tagType((string) $file['filetype']);
        foreach ($clean as $tag) {
            (new TagModel())->insert(['tag' => $tag, 'type' => $type, 'filepath' => $file['filepath'], 'file_id' => $fileId]);
        }
        (new FileModel())->update($fileId, ['tags' => implode(', ', $clean), 'last_action' => 'updated', 'last_action_at' => gmdate('Y-m-d H:i:s')]);
        $db->transComplete();
        if (! $db->transStatus()) {
            return $this->error('persistence_error', 'Tags could not be saved.', 500);
        }

        service('audit')->record($this->actor(), $this->request, 'metadata', 'TAGS_REPLACED', 'file', $fileId, 'Replaced tags for "' . $file['filename'] . '".');

        return $this->success(['file_id' => $fileId, 'tags' => $clean]);
    }

    private function tagType(string $extension): string
    {
        return match (strtolower($extension)) {
            'mp4', 'mov', 'avi', 'mkv', 'webm' => 'video',
            'mp3', 'wav', 'ogg', 'm4a', 'flac' => 'audio',
            default => 'image', // The legacy tag enum has no document type.
        };
    }
}
