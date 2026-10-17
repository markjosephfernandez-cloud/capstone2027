<?php

namespace App\Controllers\Api;

use App\Models\FileModel;
use CodeIgniter\I18n\Time;

class MobileInteractionController extends ApiController
{
    public function engagement(int $id)
    {
        if (! $this->published($id)) return $this->error('not_found', 'Media not found.', 404);
        $userId = (int) $this->actor()['employee_id'];
        $db = db_connect();
        $reactionRow = $db->table('mobile_media_likes')->select('reaction')->where(['file_id' => $id, 'user_employee_id' => $userId])->get()->getRowArray();
        $reaction = $reactionRow['reaction'] ?? null;
        return $this->success([
            'reaction' => $reaction,
            'liked' => $reaction === 'like',
            'disliked' => $reaction === 'dislike',
            'like_count' => $db->table('mobile_media_likes')->where(['file_id' => $id, 'reaction' => 'like'])->countAllResults(),
            'dislike_count' => $db->table('mobile_media_likes')->where(['file_id' => $id, 'reaction' => 'dislike'])->countAllResults(),
            'comment_count' => $db->table('mobile_comments')->where('file_id', $id)->countAllResults(),
        ]);
    }

    public function like(int $id)
    {
        return $this->toggleReaction($id, 'like');
    }

    public function dislike(int $id)
    {
        return $this->toggleReaction($id, 'dislike');
    }

    private function toggleReaction(int $id, string $reaction)
    {
        if (! $this->published($id)) return $this->error('not_found', 'Media not found.', 404);
        $userId = (int) $this->actor()['employee_id'];
        $table = db_connect()->table('mobile_media_likes');
        $where = ['file_id' => $id, 'user_employee_id' => $userId];
        $existing = $table->where($where)->get()->getRowArray();
        if ($existing && $existing['reaction'] === $reaction) {
            $table->where('id', $existing['id'])->delete();
            $currentReaction = null;
        } elseif ($existing) {
            $table->where('id', $existing['id'])->update(['reaction' => $reaction]);
            $currentReaction = $reaction;
        } else {
            $table->insert($where + ['reaction' => $reaction, 'created_at' => Time::now('UTC')->toDateTimeString()]);
            $currentReaction = $reaction;
        }

        return $this->success([
            'reaction' => $currentReaction,
            'liked' => $currentReaction === 'like',
            'disliked' => $currentReaction === 'dislike',
            'like_count' => db_connect()->table('mobile_media_likes')->where(['file_id' => $id, 'reaction' => 'like'])->countAllResults(),
            'dislike_count' => db_connect()->table('mobile_media_likes')->where(['file_id' => $id, 'reaction' => 'dislike'])->countAllResults(),
        ]);
    }

    public function comments(int $id)
    {
        if (! $this->published($id)) return $this->error('not_found', 'Media not found.', 404);
        $rows = db_connect()->table('mobile_comments c')
            ->select('c.id,c.comment,c.created_at,u.employee_id,u.name,u.email_address,p.username,p.profile_image')
            ->join('user u', 'u.employee_id=c.user_employee_id')
            ->join('mobile_profiles p', 'p.user_employee_id=u.employee_id', 'left')
            ->where('c.file_id', $id)->orderBy('c.created_at', 'ASC')->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['username'] = $row['username'] ?: $row['name'];
            $row['profile_image_url'] = $row['profile_image'] ? $this->externalUrl('api/v1/mobile/profile/avatar/' . $row['employee_id']) : null;
            unset($row['profile_image']);
        }
        return $this->success($rows, 200, ['count' => count($rows)]);
    }

    public function addComment(int $id)
    {
        if (! $this->published($id)) return $this->error('not_found', 'Media not found.', 404);
        $comment = trim((string) ($this->input()['comment'] ?? ''));
        if ($comment === '' || mb_strlen($comment) > 2000) return $this->error('invalid_comment', 'Comment must be between 1 and 2000 characters.', 422);
        $now = Time::now('UTC')->toDateTimeString();
        $db = db_connect();
        $db->table('mobile_comments')->insert(['file_id' => $id, 'user_employee_id' => (int) $this->actor()['employee_id'], 'comment' => $comment, 'created_at' => $now]);
        return $this->success(['id' => (int) $db->insertID(), 'comment' => $comment, 'created_at' => $now, 'name' => $this->actor()['name']], 201);
    }

    public function subscription(string $channel)
    {
        $key = $this->channelKey($channel);
        $userId = (int) $this->actor()['employee_id'];
        $table = db_connect()->table('mobile_channel_subscriptions');
        $where = ['channel_key' => $key, 'user_employee_id' => $userId];
        $existing = $table->where($where)->get()->getRowArray();
        if (strtolower($this->request->getMethod()) === 'post') {
            if ($existing) $table->where('id', $existing['id'])->delete();
            else $table->ignore(true)->insert($where + ['created_at' => Time::now('UTC')->toDateTimeString()]);
            $existing = $existing ? null : $where;
        }
        return $this->success(['subscribed' => (bool) $existing, 'subscriber_count' => db_connect()->table('mobile_channel_subscriptions')->where('channel_key', $key)->countAllResults()]);
    }

    private function published(int $id): bool
    {
        return (new FileModel())->where(['id' => $id, 'is_deleted' => 0, 'release_status' => 'published'])->where('app_published_at IS NOT NULL', null, false)->countAllResults() > 0;
    }

    private function channelKey(string $channel): string
    {
        return mb_substr(strtolower(trim(rawurldecode($channel))), 0, 100);
    }
}
