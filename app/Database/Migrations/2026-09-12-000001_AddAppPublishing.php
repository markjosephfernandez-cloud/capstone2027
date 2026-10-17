<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAppPublishing extends Migration
{
    public function up()
    {
        $this->forge->addColumn('files', [
            'app_post_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'app_published_at' => ['type' => 'DATETIME', 'null' => true],
            'app_pinned' => ['type' => 'TINYINT', 'default' => 0],
        ]);
        // Preserve media that was already public before this workflow was installed.
        $this->db->table('files')->where('release_status', 'published')->where('is_deleted', 0)
            ->update(['app_published_at' => gmdate('Y-m-d H:i:s')]);
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'title' => ['type' => 'VARCHAR', 'constraint' => 255],
            'description' => ['type' => 'TEXT', 'null' => true],
            'channel' => ['type' => 'VARCHAR', 'constraint' => 255],
            'thumbnail_mode' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'collage'],
            'thumbnail_ids' => ['type' => 'TEXT'],
            'pinned' => ['type' => 'TINYINT', 'default' => 0],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
            'published_at' => ['type' => 'DATETIME', 'null' => true],
            'published_by' => ['type' => 'INT', 'null' => true],
            'version' => ['type' => 'INT', 'default' => 1],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('mobile_posts');
        $this->db->query('CREATE INDEX app_post_visibility ON ' . $this->db->prefixTable('files') . ' (app_post_id, app_published_at)');
    }

    public function down()
    {
        throw new \RuntimeException('App publishing cannot be rolled back while it controls public media visibility.');
    }
}
