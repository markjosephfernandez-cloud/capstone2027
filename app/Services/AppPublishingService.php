<?php
namespace App\Services;

use App\Models\FileModel;
use InvalidArgumentException;
use RuntimeException;

class AppPublishingService
{
    public const DEFAULT_CHANNEL = 'Foundation University';

    /** Call inside the file-management posting transaction. Photos form one post; videos form individual posts. */
    public function receive(array $fileIds, string $title, string $description, string $channel = self::DEFAULT_CHANNEL): array
    {
        // Untitled incoming drafts are private; saving/publishing still requires a title.
        $title = trim($title) === '' ? '' : \App\Libraries\MediaTitle::validate($title);
        if (mb_strlen($description) > 5000 || $channel === '' || mb_strlen($channel) > 30) throw new InvalidArgumentException('Enter a channel of at most 30 characters and description of at most 5,000.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $fileIds))));
        if (!$ids) throw new InvalidArgumentException('Select at least one photo or video.');
        $files = (new FileModel())->whereIn('id', $ids)->where('is_deleted', 0)->whereIn('media_kind', ['image', 'video'])->orderBy('id')->findAll();
        if (!$files) throw new InvalidArgumentException('No available photos or videos were selected.');
        $photos = array_values(array_filter($files, fn($f) => $f['media_kind'] === 'image'));
        $groups = $photos ? [$photos] : [];
        foreach ($files as $file) if ($file['media_kind'] === 'video') $groups[] = [$file];
        $db = db_connect(); $now = gmdate('Y-m-d H:i:s'); $posts = [];
        foreach ($groups as $group) {
            $members = array_map('intval', array_column($group, 'id'));
            $db->table('mobile_posts')->insert([
                'title' => mb_substr($title, 0, 255), 'description' => $description, 'channel' => mb_substr($channel, 0, 255),
                'thumbnail_mode' => $group[0]['media_kind'] === 'image' ? 'collage' : 'single',
                'thumbnail_ids' => json_encode(array_slice($members, 0, 5)),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $postId = (int) $db->insertID();
            $db->table('files')->whereIn('id', $members)->update([
                'app_post_id' => $postId, 'app_published_at' => null, 'app_pinned' => 0,
                'release_status' => 'published', 'published_at' => $now,
                'publication_channel' => $channel,
            ]);
            $posts[] = $postId;
        }
        return $posts;
    }

    public function members(int $postId): array
    {
        return (new FileModel())->where('app_post_id', $postId)->where('is_deleted', 0)
            ->where('release_status', 'published')->orderBy('id')->findAll();
    }

    /** Save metadata and publish together, so regular users never see a partially edited post. */
    public function update(int $postId, array $input, int $actorId, bool $publish): array
    {
        $db = db_connect();
        $post = $db->table('mobile_posts')->where('id', $postId)->get()->getRowArray();
        if (!$post) throw new InvalidArgumentException('Post not found.');
        $files = $this->members($postId);
        if (!$files) throw new InvalidArgumentException('This post no longer has available media.');
        $title = trim((string)($input['title'] ?? $post['title']));
        $description = trim((string)($input['description'] ?? $post['description']));
        if ($title === '' || mb_strlen($title) > 150 || mb_strlen($description) > 5000) throw new InvalidArgumentException('Enter a title of 1–150 characters and a description of at most 5,000 characters.');
        $tags = array_key_exists('tags', $input) ? self::normalizeTags($input['tags']) : ($post['tags'] ?? '');
        $mode = $input['thumbnail_mode'] ?? $post['thumbnail_mode'];
        $ids = $input['thumbnail_ids'] ?? json_decode($post['thumbnail_ids'], true);
        if (!is_array($ids) || !in_array($mode, ['single', 'collage'], true)) throw new InvalidArgumentException('Choose a single image or a collage.');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $allowed = array_map('intval', array_column($files, 'id'));
        $isPhoto = $files[0]['media_kind'] === 'image';
        $required = $mode === 'collage' ? min(5, count($files)) : 1;
        if (count($ids) !== $required || array_diff($ids, $allowed) || (!$isPhoto && $mode !== 'single')) throw new InvalidArgumentException('Select one thumbnail, or five photos for a collage (all photos when the post has fewer than five).');
        $version = filter_var($input['version'] ?? null, FILTER_VALIDATE_INT);
        if ($version === false || $version !== (int)$post['version']) throw new RuntimeException('This post changed. Close the editor and open it again before saving.');
        $now = gmdate('Y-m-d H:i:s');
        $changes = ['tags' => $tags, 'title' => $title, 'description' => $description, 'thumbnail_mode' => $mode,
            'thumbnail_ids' => json_encode($ids), 'pinned' => !empty($input['pinned'] ?? $post['pinned']) ? 1 : 0,
            'version' => $version + 1, 'updated_at' => $now];
        if ($publish) $changes += ['published_at' => $post['published_at'] ?: $now, 'published_by' => $actorId];
        $db->transStart();
        $db->table('mobile_posts')->where('id', $postId)->where('version', $version)->update($changes);
        if ($db->affectedRows() !== 1) { $db->transRollback(); throw new RuntimeException('Another admin updated this post. Reopen it to load the latest changes.'); }
        // Post metadata belongs to mobile_posts; keep each source file's identity intact.
        $fileChanges = ['app_pinned' => $changes['pinned'], 'updated_at' => $now];
        if ($publish) $fileChanges['app_published_at'] = $changes['published_at'];
        $db->table('files')->where('app_post_id', $postId)->where('is_deleted', 0)->where('release_status', 'published')->update($fileChanges);
        $db->transComplete();
        if (!$db->transStatus()) throw new RuntimeException('The post could not be saved. Please try again.');
        return array_merge($post, $changes);
    }

    public static function normalizeTags($value): string
    {
        if (!is_string($value) && !is_array($value)) throw new InvalidArgumentException('Tags must be text or a list of tags.');
        $tags = [];
        foreach (is_array($value) ? $value : [$value] as $entry) {
            if (!is_string($entry)) throw new InvalidArgumentException('Each tag must be text.');
            foreach (preg_split('/[,;\r\n]+/u', $entry) as $tag) {
                $tag = trim(preg_replace('/^#+\s*/u', '', trim($tag)));
                if ($tag === '') continue;
                if (mb_strlen($tag) > 50) throw new InvalidArgumentException('Each tag must be at most 50 characters.');
                $key = mb_strtolower($tag);
                if (!isset($tags[$key])) $tags[$key] = $tag;
            }
        }
        if (count($tags) > 20) throw new InvalidArgumentException('Use at most 20 tags per post.');
        return implode(', ', $tags);
    }

    public function unpublish(int $postId, array $input): array
    {
        $db = db_connect();
        $post = $db->table('mobile_posts')->where('id', $postId)->get()->getRowArray();
        if (!$post || !$this->members($postId)) throw new InvalidArgumentException('Post not found.');
        $version = filter_var($input['version'] ?? null, FILTER_VALIDATE_INT);
        if ($version === false || $version !== (int)$post['version']) throw new RuntimeException('This post changed. Reopen it before trying again.');
        $changes = ['published_at' => null, 'published_by' => null, 'version' => $version + 1, 'updated_at' => gmdate('Y-m-d H:i:s')];
        $db->transStart();
        $db->table('mobile_posts')->where('id', $postId)->where('version', $version)->update($changes);
        if ($db->affectedRows() !== 1) { $db->transRollback(); throw new RuntimeException('Another admin updated this post. Please try again.'); }
        $db->table('files')->where('app_post_id', $postId)->update(['app_published_at' => null, 'app_pinned' => 0, 'updated_at' => $changes['updated_at']]);
        $db->transComplete();
        if (!$db->transStatus()) throw new RuntimeException('The post could not be unpublished. Please try again.');
        return array_merge($post, $changes);
    }
}
