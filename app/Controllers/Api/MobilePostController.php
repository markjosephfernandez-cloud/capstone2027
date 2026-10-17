<?php
namespace App\Controllers\Api;

use App\Services\AppPublishingService;
use App\Services\ThumbnailService;
use App\Services\VideoPreviewService;
use App\Models\FileModel;

class MobilePostController extends ApiController
{
    public function index()
    {
        if ($denied = $this->requireRole(['admin', 'super_admin'])) return $denied;
        $offset = max(0, (int)$this->request->getGet('offset'));
        $table = db_connect()->prefixTable('files');
        $postsTable = db_connect()->prefixTable('mobile_posts');
        $rows = db_connect()->table('mobile_posts')->where('published_at', null)->where("EXISTS (SELECT 1 FROM {$table} f WHERE f.app_post_id = {$postsTable}.id AND f.is_deleted = 0 AND f.release_status = 'published')", null, false)
            ->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->get(21, $offset)->getResultArray();
        $hasMore = count($rows) > 20;
        return $this->success(array_map(fn($p) => $this->payload($p), array_slice($rows, 0, 20)), 200,
            ['next_offset' => $hasMore ? $offset + 20 : null])->setHeader('Cache-Control', 'private, no-store');
    }

    public function update(int $id)
    {
        return $this->save($id, false);
    }

    public function show(int $id)
    {
        if ($denied = $this->requireRole(['admin', 'super_admin'])) return $denied;
        $post = db_connect()->table('mobile_posts')->where('id', $id)->get()->getRowArray();
        if (!$post || !(new AppPublishingService())->members($id)) return $this->error('not_found', 'Post not found.', 404);
        return $this->success($this->payload($post))->setHeader('Cache-Control', 'private, no-store');
    }

    public function unpublish(int $id)
    {
        return $this->save($id, false, true);
    }

    public function publish(int $id)
    {
        return $this->save($id, true);
    }

    private function save(int $id, bool $publish, bool $unpublish = false)
    {
        if ($denied = $this->requireRole(['admin', 'super_admin'])) return $denied;
        try {
            $service = new AppPublishingService();
            $post = $unpublish ? $service->unpublish($id, $this->input())
                : $service->update($id, $this->input(), (int)$this->actor()['employee_id'], $publish);
        } catch (\InvalidArgumentException $error) {
            return $this->error('invalid_post', $error->getMessage(), 422);
        } catch (\RuntimeException $error) {
            return $this->error('post_conflict', $error->getMessage(), 409);
        }
        return $this->success($this->payload($post))->setHeader('Cache-Control', 'private, no-store');
    }

    public function thumbnail(int $id)
    {
        if ($denied = $this->requireRole(['admin', 'super_admin'])) return $denied;
        $file = (new FileModel())->where('is_deleted', 0)->where('release_status', 'published')->where('app_post_id IS NOT NULL', null, false)->find($id);
        if (!$file || !in_array($file['media_kind'], ['image', 'video'], true)) return $this->error('not_found', 'Media not found.', 404);
        session()->close();
        try {
            $source = service('storage')->resolveDatabaseFile($file['filepath']);
            $path = $file['media_kind'] === 'video' ? (new VideoPreviewService())->thumbnailForFile($source) : (new ThumbnailService())->forFile($source);
            $uri = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($path));
        } catch (\RuntimeException $error) {
            return $this->error('thumbnail_unavailable', 'This thumbnail is unavailable.', 404);
        }
        // Authenticated JSON works for web images without putting bearer tokens in asset URLs.
        return $this->success(['uri' => $uri])->setHeader('Cache-Control', 'private, no-store');
    }

    private function payload(array $post): array
    {
        $post['id'] = (int)$post['id']; $post['version'] = (int)$post['version']; $post['pinned'] = (bool)$post['pinned'];
        // Older incoming drafts have generated titles. Only hide them before the first admin save.
        if ($post['version'] === 1 && empty($post['published_at']) && empty($post['published_by'])) {
            $post['title'] = '';
        }
        $post['thumbnail_ids'] = json_decode($post['thumbnail_ids'], true);
        $post['files'] = array_map(fn($f) => ['id' => (int)$f['id'], 'media_kind' => $f['media_kind'], 'title' => $f['display_title'], 'filename' => $f['filename']], (new AppPublishingService())->members($post['id']));
        return $post;
    }
}
