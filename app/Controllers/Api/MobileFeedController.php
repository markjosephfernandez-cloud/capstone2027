<?php

namespace App\Controllers\Api;

use App\Libraries\MediaTitle;
use App\Models\FileModel;
use App\Models\FolderModel;
use RuntimeException;

class MobileFeedController extends ApiController
{
    public function home()
    {
        $files = (new FileModel())
            ->where('is_deleted', 0)
            ->where('release_status', 'published')
            ->whereIn('media_kind', ['image', 'video'])
            ->orderBy('published_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll(50);

        $items = [];

        foreach ($files as $file) {
            $items[] = $this->mediaPayload($file);
        }

        return $this->success($items, 200, [
            'count' => count($items),
        ]);
    }

    public function shorts()
    {
        $files = (new FileModel())
            ->where('is_deleted', 0)
            ->where('release_status', 'published')
            ->where('media_kind', 'video')
            ->orderBy('published_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll(50);

        return $this->success(array_map(
            fn (array $file): array => $this->mediaPayload($file),
            $files
        ), 200, ['count' => count($files)]);
    }

    public function channels()
    {
        $rows = db_connect()->table('files')
            ->select('publication_channel, COUNT(*) media_count, MAX(published_at) updated_at')
            ->where(['is_deleted' => 0, 'release_status' => 'published'])
            ->where('publication_channel IS NOT NULL', null, false)
            ->groupBy('publication_channel')->orderBy('publication_channel', 'ASC')->get()->getResultArray();
        return $this->success(array_map(fn ($row) => [
            'id' => $this->channelKey((string) $row['publication_channel']),
            'name' => $row['publication_channel'],
            'media_count' => (int) $row['media_count'],
            'updated_at' => $row['updated_at'],
            'subscriber_count' => db_connect()->table('mobile_channel_subscriptions')->where('channel_key', $this->channelKey((string) $row['publication_channel']))->countAllResults(),
        ], $rows));
    }

    public function search()
    {
        $query = trim((string) $this->request->getGet('q'));
        if ($query === '') return $this->success([], 200, ['suggestions' => []]);
        $folderIds = array_map('intval', array_column((new FolderModel())->select('id')->like('name', $query)->findAll(), 'id'));
        $builder = (new FileModel())->where(['is_deleted' => 0, 'release_status' => 'published'])
            ->groupStart()->like('display_title', $query)->orLike('tags', $query)
            ->orLike('classification', $query)->orLike('detected_objects', $query)->orLike('publication_channel', $query);
        if ($folderIds !== []) {
            $builder->orWhereIn('folder_id', $folderIds);
        }
        $builder->groupEnd()->orderBy('published_at', 'DESC');
        $files = $builder->findAll(50);
        $suggestions = [];
        foreach ($files as $file) {
            foreach ([$file['display_title'] ?? null, $file['publication_channel'] ?? null, $file['classification'] ?? null, $file['tags'] ?? null, $file['detected_objects'] ?? null] as $value) {
                if ($value && stripos((string) $value, $query) !== false) $suggestions[(string) $value] = true;
            }
        }
        return $this->success(array_map(fn ($file) => $this->mediaPayload($file), $files), 200, ['suggestions' => array_slice(array_keys($suggestions), 0, 10)]);
    }

    public function preview(int $id)
    {
        $file = (new FileModel())
            ->where('is_deleted', 0)
            ->where('release_status', 'published')
            ->find($id);

        if ($file === null || (string) ($file['media_kind'] ?? '') !== 'image') {
            return $this->error('not_found', 'The published image does not exist.', 404);
        }

        try {
            $path = service('storage')->resolveDatabaseFile((string) $file['filepath']);
        } catch (RuntimeException) {
            return $this->error('file_unavailable', 'The image file is unavailable.', 404);
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
        if (! is_string($mimeType) || ! in_array($mimeType, $allowedMimeTypes, true)) {
            return $this->error('unsupported_media', 'This file is not a supported image.', 415);
        }

        return $this->response
            ->download($path, null, true)
            ->setFileName($this->previewName($file))
            ->setContentType($mimeType)
            ->inline()
            ->noCache();
    }

    public function stream(int $id)
    {
        $file = (new FileModel())
            ->where('is_deleted', 0)
            ->where('release_status', 'published')
            ->find($id);

        if ($file === null || (string) ($file['media_kind'] ?? '') !== 'video') {
            return $this->error('not_found', 'The published video does not exist.', 404);
        }

        try {
            $path = service('storage')->resolveDatabaseFile((string) $file['filepath']);
        } catch (RuntimeException) {
            return $this->error('file_unavailable', 'The video file is unavailable.', 404);
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowedMimeTypes = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm', 'video/x-m4v'];
        if (! is_string($mimeType) || ! in_array($mimeType, $allowedMimeTypes, true)) {
            return $this->error('unsupported_media', 'This file is not a supported video.', 415);
        }

        return $this->response
            ->download($path, null, true)
            ->setFileName($this->previewName($file))
            ->setContentType($mimeType, '')
            ->inline();
    }

    public function download(int $id)
    {
        $file = (new FileModel())->where(['is_deleted' => 0, 'release_status' => 'published', 'downloadable' => 1])->find($id);
        if ($file === null) return $this->error('not_found', 'This media is not available for download.', 404);
        try { $path = service('storage')->resolveDatabaseFile((string) $file['filepath']); }
        catch (RuntimeException) { return $this->error('file_unavailable', 'The media file is unavailable.', 404); }
        return $this->response->download($path, null)->setFileName((string) $file['filename']);
    }

    /** @param array<string, mixed> $file */
    private function mediaPayload(array $file): array
    {
        $kind = (string) ($file['media_kind'] ?? 'document');
        $mediaPath = $kind === 'video'
            ? 'api/v1/mobile/media/' . $file['id'] . '/stream'
            : 'api/v1/mobile/media/' . $file['id'] . '/preview';

        return [
            'id'            => (int) $file['id'],
            'file_id'       => (int) $file['id'],
            'display_title' => MediaTitle::forFile($file),
            'title'         => MediaTitle::forFile($file),
            'description'   => $file['description'],
            'filetype'      => $file['filetype'],
            'mime_type'     => $file['mime_type'] ?? null,
            'media_kind'    => $kind,
            'size'          => (int) $file['size'],
            'downloadable'  => (bool) $file['downloadable'],
            'published_at'  => $file['published_at'],
            'updated_at'    => $file['updated_at'],
            'channel'       => $file['publication_channel'] ?? null,
            'channel_id'    => $this->channelKey((string) ($file['publication_channel'] ?? '')),
            'tags'           => $file['tags'] ?? null,
            'classification' => $file['classification'] ?? null,
            'like_count'     => db_connect()->table('mobile_media_likes')->where('file_id', (int) $file['id'])->countAllResults(),
            'comment_count'  => db_connect()->table('mobile_comments')->where('file_id', (int) $file['id'])->countAllResults(),
            'file_url'      => site_url($mediaPath),
            'stream_url'    => $kind === 'video' ? site_url($mediaPath) : null,
            'download_url'  => (bool) $file['downloadable'] ? site_url('api/v1/mobile/media/' . $file['id'] . '/download') : null,
            'thumbnail_url' => $kind === 'image' ? site_url($mediaPath) : null,
        ];
    }

    private function channelKey(string $channel): string
    {
        return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $channel) ?? ''), '-');
    }

    /** @param array<string, mixed> $file */
    private function previewName(array $file): string
    {
        $title = preg_replace('/[^\pL\pN _.-]+/u', '', MediaTitle::forFile($file)) ?: 'media';
        $extension = trim((string) ($file['filetype'] ?? ''));
        return $title . ($extension === '' ? '' : '.' . $extension);
    }
}
