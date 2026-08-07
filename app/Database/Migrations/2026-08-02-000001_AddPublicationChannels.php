<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPublicationChannels extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('approvals') && ! $this->hasField('approvals', 'publication_channel')) {
            $this->forge->addColumn('approvals', [
                'publication_channel' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'title'],
            ]);
        }

        if ($this->db->tableExists('files')) {
            if (! $this->hasField('files', 'publication_channel')) {
                $this->forge->addColumn('files', [
                    'publication_channel' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'featured'],
                ]);
            }
            if (! $this->hasField('files', 'published_at')) {
                $this->forge->addColumn('files', [
                    'published_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'publication_channel'],
                ]);
            }

            // Keep the existing audit values and permit the two publication states.
            $this->db->query("ALTER TABLE `files` MODIFY `last_action` ENUM('updated','deleted','restored','moved_to_trash','duplicated','renamed','downloaded','uploaded','publication_requested','published') NULL DEFAULT NULL");
        }
    }

    public function down()
    {
        if ($this->db->tableExists('approvals') && $this->hasField('approvals', 'publication_channel')) {
            $this->forge->dropColumn('approvals', 'publication_channel');
        }
        if ($this->db->tableExists('files')) {
            if ($this->hasField('files', 'published_at')) {
                $this->forge->dropColumn('files', 'published_at');
            }
            if ($this->hasField('files', 'publication_channel')) {
                $this->forge->dropColumn('files', 'publication_channel');
            }
        }
    }

    private function hasField(string $table, string $field): bool
    {
        return in_array($field, $this->db->getFieldNames($table), true);
    }
}
