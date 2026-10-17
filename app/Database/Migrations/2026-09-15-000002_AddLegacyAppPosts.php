<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use App\Libraries\MediaTitle;
use App\Services\AppPublishingService;

/** Give older public media the same editor and publication controls as new posts. */
class AddLegacyAppPosts extends Migration
{
    public function up()
    {
        $this->db->transStart();
        $after = 0;
        do {
            $files = $this->db->table('files')->where('id >', $after)->where('app_post_id', null)
                ->where('is_deleted', 0)->where('release_status', 'published')->where('app_published_at IS NOT NULL', null, false)
                ->whereIn('media_kind', ['image', 'video'])->orderBy('id')->get(200)->getResultArray();
            foreach ($files as $file) {
                $now = gmdate('Y-m-d H:i:s');
                $this->db->table('mobile_posts')->insert([
                    'title' => mb_substr(MediaTitle::forFile($file), 0, 150),
                    'description' => $file['description'] ?? '',
                    'channel' => $file['publication_channel'] ?: AppPublishingService::DEFAULT_CHANNEL,
                    'thumbnail_mode' => 'single', 'thumbnail_ids' => json_encode([(int)$file['id']]),
                    'pinned' => (int)$file['app_pinned'], 'created_at' => $file['published_at'] ?: $now,
                    'updated_at' => $file['updated_at'] ?: $now,
                    'published_at' => $file['published_at'] ?: $file['app_published_at'],
                ]);
                $this->db->table('files')->where('id', $file['id'])->update(['app_post_id' => (int)$this->db->insertID()]);
                $after = (int)$file['id'];
            }
        } while (count($files) === 200);
        $this->db->transComplete();
        if (!$this->db->transStatus()) throw new \RuntimeException('Could not prepare existing posts for editing.');
    }

    public function down()
    {
        throw new \RuntimeException('Keep post records to preserve subsequent admin edits and publication choices.');
    }
}
