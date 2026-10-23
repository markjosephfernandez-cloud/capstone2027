<?php
namespace App\Controllers\Api;

use App\Models\FileModel;

class MobileFeaturedController extends MobileFeedController
{
    private function photos()
    {
        return (new FileModel())->where('is_deleted', 0)->where('release_status', 'published')
            ->where('app_published_at IS NOT NULL', null, false)->where('media_kind', 'image');
    }

    public function index()
    {
        $settings = db_connect()->table('mobile_featured')->where('id', 1)->get()->getRowArray();
        $ids = $settings['photo_ids'] === null ? null : json_decode($settings['photo_ids'], true);
        $files = $ids === null ? $this->photos()->orderBy('app_pinned', 'DESC')->orderBy('app_published_at', 'DESC')->orderBy('id', 'DESC')->findAll(5)
            : ($ids ? $this->photos()->whereIn('id', $ids)->findAll() : []);
        if ($ids !== null) {
            usort($files, fn($a, $b) => array_search((int) $a['id'], $ids, true) <=> array_search((int) $b['id'], $ids, true));
        }
        $postIds = array_values(array_unique(array_filter(array_column($files, 'app_post_id'))));
        $posts = $postIds ? array_column(db_connect()->table('mobile_posts')->whereIn('id', $postIds)
            ->where('published_at IS NOT NULL', null, false)->get()->getResultArray(), null, 'id') : [];
        $photos = array_map(function ($file) use ($posts) {
            $payload = $this->mediaPayload($file, false);
            $post = $posts[$file['app_post_id'] ?? 0] ?? null;
            if ($post) {
                $payload['title'] = $payload['display_title'] = $post['title'];
                $payload['description'] = $post['description'];
                $payload['tags'] = $post['tags'] ?? '';
                $payload['updated_at'] = $post['updated_at'];
            }
            return $payload;
        }, $files);
        return $this->success($photos, 200,
            ['version' => (int)$settings['version'], 'configured' => $ids !== null])->setHeader('Cache-Control', 'no-store');
    }

    public function save()
    {
        if ($denied = $this->requireRole(['admin', 'super_admin'])) {
            return $denied;
        }
        $input = $this->input();
        $ids = $input['photo_ids'] ?? null;
        if (!is_array($ids) || count($ids) > 20 || array_filter($ids, fn($id) => !is_int($id) || $id < 1) || count(array_unique($ids)) !== count($ids)) {
            return $this->error('invalid_photos', 'Select up to 20 different published photos.', 422);
        }
        $db = db_connect();
        $db->transStart();
        $db->table('mobile_featured')->where('id', 1)->where('version', (int)($input['version'] ?? 0))
            ->set('version', 'version + 1', false)->update(['photo_ids' => json_encode($ids)]);
        if ($db->affectedRows() !== 1) {
            $db->transRollback();
            return $this->error('featured_conflict', 'Featured changed. Close the editor and reopen it before saving.', 409);
        }
        if ($ids && count($this->photos()->whereIn('id', $ids)->findAll()) !== count($ids)) {
            $db->transRollback(); return $this->error('invalid_photos', 'Some photos are no longer public. Reopen the editor and select available photos.', 422);
        }
        $db->transComplete();
        if (! $db->transStatus()) {
            return $this->error('save_failed', 'Featured could not be saved. Try again.', 500);
        }
        return $this->index();
    }
}
