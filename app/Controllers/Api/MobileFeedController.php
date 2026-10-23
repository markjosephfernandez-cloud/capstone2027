<?php

namespace App\Controllers\Api;

use App\Libraries\MediaTitle;
use App\Libraries\MediaStreamResponse;
use App\Services\ThumbnailService;
use App\Services\VideoPreviewService;
use App\Models\FileModel;
use App\Models\FolderModel;
use RuntimeException;

class MobileFeedController extends ApiController
{
    public function home()
    {
        $offset = max(0, (int) $this->request->getGet('offset'));
        $pageSize = 50;
        $table = db_connect()->prefixTable('files');
        $files = (new FileModel())
            ->where('is_deleted', 0)
            ->where('release_status', 'published')->where('app_published_at IS NOT NULL', null, false)
            ->whereIn('media_kind', ['image', 'video'])
            ->where("(app_post_id IS NULL OR id IN (SELECT MIN(id) FROM {$table} WHERE is_deleted = 0 AND release_status = 'published' AND app_published_at IS NOT NULL GROUP BY app_post_id))", null, false)
            ->orderBy('app_pinned', 'DESC')
            ->orderBy('CASE WHEN app_post_id IS NULL THEN published_at ELSE app_published_at END', 'DESC', false)
            ->orderBy('id', 'DESC')
            ->findAll($pageSize + 1, $offset);
        $hasMore = count($files) > $pageSize;
        $files = array_slice($files, 0, $pageSize);

        $items = [];

        foreach ($files as $file) {
            $items[] = $this->mediaPayload($file);
        }

        return $this->success($items, 200, [
            'count' => count($items),
            'next_offset' => $hasMore ? $offset + $pageSize : null,
        ]);
    }

    public function library()
    {
        $id = (int) $this->actor()['employee_id'];
        $builder = (new FileModel())->where('is_deleted', 0)->where('release_status', 'published')->where('app_published_at IS NOT NULL', null, false);
        if ($this->request->getGet('collection') === 'liked') {
            $ids = array_column(db_connect()->table('mobile_media_likes')->select('file_id')->where(['user_employee_id' => $id, 'reaction' => 'like'])->get()->getResultArray(), 'file_id');
            if ($ids === []) {
                return $this->success([]);
            }
            $builder->whereIn('id', $ids);
        } else {
            $builder->where('employee_id', $id);
        }
        return $this->success(array_map(fn ($file) => $this->mediaPayload($file), $builder->orderBy('CASE WHEN app_post_id IS NULL THEN published_at ELSE app_published_at END', 'DESC', false)->findAll()));
    }

    public function shorts()
    {
        $files = (new FileModel())
            ->where('is_deleted', 0)
            ->where('release_status', 'published')->where('app_published_at IS NOT NULL', null, false)
            ->where('media_kind', 'video')
            ->orderBy('CASE WHEN app_post_id IS NULL THEN published_at ELSE app_published_at END', 'DESC', false)
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
            ->where(['is_deleted' => 0, 'release_status' => 'published'])->where('app_published_at IS NOT NULL', null, false)
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
        if ($query === '') {
            return $this->success([], 200, ['suggestions' => []]);
        }
        $folderIds = array_map('intval', array_column((new FolderModel())->where('is_deleted', 0)->select('id')->like('name', $query)->findAll(), 'id'));
        $matchingPosts = db_connect()->table('mobile_posts')->select('id')
            ->groupStart()->like('title', $query)->orLike('description', $query)->orLike('tags', $query)->groupEnd();
        $builder = (new FileModel())->where(['is_deleted' => 0, 'release_status' => 'published'])->where('app_published_at IS NOT NULL', null, false)
            ->groupStart()->like('display_title', $query)
            ->orLike('classification', $query)->orLike('detected_objects', $query)->orLike('publication_channel', $query)
            ->orWhereIn('app_post_id', $matchingPosts);
        if ($folderIds !== []) {
            $builder->orWhereIn('folder_id', $folderIds);
        }
        $builder->groupEnd()->orderBy('CASE WHEN app_post_id IS NULL THEN published_at ELSE app_published_at END', 'DESC', false);
        $files = $builder->findAll(50);
        $seen = [];
        $files = array_values(array_filter($files, static function ($file) use (&$seen) {
            $key = empty($file['app_post_id']) ? 'file:' . $file['id'] : 'post:' . $file['app_post_id'];
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            return true;
        }));
        $suggestions = [];
        $items = array_map(fn ($file) => $this->mediaPayload($file), $files);
        foreach ($files as $index => $file) {
            foreach ([$items[$index]['display_title'], $file['display_title'] ?? null, $file['publication_channel'] ?? null, $file['classification'] ?? null, $items[$index]['tags'] ?? null, $file['detected_objects'] ?? null] as $value) {
                if ($value && stripos((string) $value, $query) !== false) {
                    $suggestions[(string) $value] = true;
                }
            }
        }
        return $this->success($items, 200, ['suggestions' => array_slice(array_keys($suggestions), 0, 10)]);
    }

    public function preview(int $id)
    {
        $file = (new FileModel())
            ->where('is_deleted', 0)
            ->where('release_status', 'published')->where('app_published_at IS NOT NULL', null, false)
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

    public function thumbnail(int $id)
    {
        $file = (new FileModel())->where(['is_deleted' => 0, 'release_status' => 'published'])->where('app_published_at IS NOT NULL', null, false)->find($id);
        if ($file === null || ! in_array($file['media_kind'] ?? '', ['image', 'video'], true)) {
            return $this->error('not_found', 'The published media does not exist.', 404);
        }
        session()->close();
        try {
            $source = service('storage')->resolveDatabaseFile((string) $file['filepath']);
            $path = $file['media_kind'] === 'video'
                ? (new VideoPreviewService())->thumbnailForFile($source)
                : (new ThumbnailService())->forFile($source);
        } catch (RuntimeException $exception) {
            log_message('warning', 'Published thumbnail unavailable: {message}', ['message' => $exception->getMessage()]);
            return $this->error('thumbnail_unavailable', 'The media thumbnail is unavailable.', 404);
        }
        $etag = '"' . pathinfo($path, PATHINFO_FILENAME) . '"';
        if (in_array($etag, array_map('trim', explode(',', $this->request->getHeaderLine('If-None-Match'))), true)) {
            return $this->response->setStatusCode(304)->setHeader('ETag', $etag)
                ->setHeader('Cache-Control', 'private, no-cache')->setBody('');
        }
        return $this->response->download($path, null)->setContentType('image/jpeg', '')->inline()
            ->setHeader('ETag', $etag)->removeHeader('Cache-Control')->setHeader('Cache-Control', 'private, no-cache');
    }

    public function stream(int $id)
    {
        $file = (new FileModel())
            ->where('is_deleted', 0)
            ->where('release_status', 'published')->where('app_published_at IS NOT NULL', null, false)
            ->find($id);

        if ($file === null || (string) ($file['media_kind'] ?? '') !== 'video') {
            return $this->error('not_found', 'The published video does not exist.', 404);
        }

        // Release this client's session before filesystem inspection or preparation.
        session()->close();
        try {
            $path = service('storage')->resolveDatabaseFile((string) $file['filepath']);
            $mimeType = \App\Services\VideoMimeType::detect($path);
        } catch (RuntimeException) {
            return $this->error('file_unavailable', 'The video file is unavailable.', 404);
        }

        $allowedMimeTypes = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm', 'video/x-m4v'];
        if (! is_string($mimeType) || ! in_array($mimeType, $allowedMimeTypes, true)) {
            return $this->error('unsupported_media', 'This file is not a supported video.', 415);
        }

        try {
            $preview = (new VideoPreviewService())->forFile($path);
            if ($preview !== $path) {
                $mimeType = 'video/mp4';
            }
            $path = $preview;
        } catch (RuntimeException $exception) {
            log_message('warning', 'Published video preview unavailable: {message}', ['message' => $exception->getMessage()]);
            return $this->error('preview_unavailable', 'The video preview could not be prepared.', 503);
        }
        return $this->streamVideoResponse($path, $mimeType);
    }

    public function download(int $id)
    {
        $file = (new FileModel())->where(['is_deleted' => 0, 'release_status' => 'published', 'downloadable' => 1])->where('app_published_at IS NOT NULL', null, false)->find($id);
        if ($file === null) {
            return $this->error('not_found', 'This media is not available for download.', 404);
        }
        try { $path = service('storage')->resolveDatabaseFile((string) $file['filepath']); }
        catch (RuntimeException) { return $this->error('file_unavailable', 'The media file is unavailable.', 404); }
        return $this->response->download($path, null)->setFileName((string) $file['filename']);
    }

    /** @param array<string, mixed> $file */
    protected function mediaPayload(array $file, bool $includePost = true): array
    {
        $kind = (string) ($file['media_kind'] ?? 'document');
        $mediaPath = $kind === 'video'
            ? 'api/v1/mobile/media/' . $file['id'] . '/stream'
            : 'api/v1/mobile/media/' . $file['id'] . '/preview';

        $channel = trim((string) ($file['publication_channel'] ?? ''));
        if ($channel === '' || strcasecmp($channel, 'General Events') === 0) {
            $channel = \App\Services\AppPublishingService::DEFAULT_CHANNEL;
        }

        $payload = [
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
            'channel'       => $channel,
            'channel_id'    => $this->channelKey($channel),
            'tags'           => '',
            'classification' => $file['classification'] ?? null,
            'like_count'     => !$includePost ? 0 : db_connect()->table('mobile_media_likes')->where(['file_id' => (int) $file['id'], 'reaction' => 'like'])->countAllResults(),
            'comment_count'  => !$includePost ? 0 : db_connect()->table('mobile_comments')->where('file_id', (int) $file['id'])->countAllResults(),
            'file_url'      => $this->externalUrl($mediaPath),
            'stream_url'    => $kind === 'video' ? $this->externalUrl($mediaPath) : null,
            'download_url'  => (bool) $file['downloadable'] ? $this->externalUrl('api/v1/mobile/media/' . $file['id'] . '/download') : null,
            'thumbnail_url' => in_array($kind, ['image', 'video'], true)
                ? $this->externalUrl('api/v1/mobile/media/' . $file['id'] . '/thumbnail') : null,
        ];
        if ($includePost && !empty($file['app_post_id'])) {
            $post = db_connect()->table('mobile_posts')->where('id', $file['app_post_id'])->get()->getRowArray();
            if ($post && $post['published_at']) {
                $payload['title'] = $post['title'];
                $payload['display_title'] = $post['title'];
                $payload['description'] = $post['description'];
                $payload['tags'] = $post['tags'] ?? '';
                $payload['updated_at'] = $post['updated_at'];
                $members = (new FileModel())->where('app_post_id', $post['id'])->where('is_deleted', 0)
                    ->where('release_status', 'published')->where('app_published_at IS NOT NULL', null, false)->orderBy('id')->findAll();
                $payload['post_id'] = (int)$post['id'];
                $payload['pinned'] = (bool)$post['pinned'];
                $payload['thumbnail_mode'] = $post['thumbnail_mode'];
                $payload['thumbnail_ids'] = json_decode($post['thumbnail_ids'], true);
                $payload['files'] = array_map(fn($member) => array_replace($this->mediaPayload($member, false), ['tags' => $post['tags'] ?? '']), $members);
                $payload['published_at'] = $post['published_at'];
            }
        }
        return $payload;
    }

    private function channelKey(string $channel): string
    {
        return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $channel) ?? ''), '-');
    }

    private function streamVideoResponse(string $path, string $mimeType)
    {
        $size = filesize($path);
        if ($size === false || $size < 1) {
            return $this->error('file_unavailable', 'The video file is unavailable.', 404);
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;
        $headOnly = strtoupper($this->request->getMethod()) === 'HEAD';
        $range = $headOnly ? '' : trim($this->request->getHeaderLine('Range'));
        if (preg_match('/^bytes=(\d*)-(\d*)$/iD', $range, $matches) === 1 && ($matches[1] !== '' || $matches[2] !== '')) {
            if ($matches[1] === '' && $matches[2] !== '') {
                $start = $size - min((int) $matches[2], $size);
            } elseif ($matches[1] !== '') {
                $start = (int) $matches[1];
                $end = $matches[2] === '' ? $end : min((int) $matches[2], $end);
            }
            if ($start > $end || $start >= $size) {
                return $this->response
                    ->setStatusCode(416)
                    ->setHeader('Content-Range', 'bytes */' . $size)
                    ->setBody('Requested video range is unavailable.');
            }
            $status = 206;
        }

        $length = $end - $start + 1;
        try {
            $response = new MediaStreamResponse($path, $mimeType, $start, $length, $status === 206, $headOnly);
        } catch (RuntimeException) {
            return $this->error('file_unavailable', 'The video file is unavailable.', 404);
        }

        return $status === 206
            ? $response->setHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size)
            : $response;
    }

    /** @param array<string, mixed> $file */
    private function previewName(array $file): string
    {
        $title = preg_replace('/[^\pL\pN _.-]+/u', '', MediaTitle::forFile($file)) ?: 'media';
        $extension = trim((string) ($file['filetype'] ?? ''));
        return $title . ($extension === '' ? '' : '.' . $extension);
    }
}
