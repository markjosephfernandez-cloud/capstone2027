<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPublishingToFolders extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('folder')) {
            if (! $this->hasField('folder', 'publication_channel')) {
                $this->forge->addColumn('folder', [
                    'publication_channel' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'path'],
                ]);
            }
            if (! $this->hasField('folder', 'published_at')) {
                $this->forge->addColumn('folder', [
                    'published_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'publication_channel'],
                ]);
            }
            if (! $this->hasField('folder', 'published_by')) {
                $this->forge->addColumn('folder', [
                    'published_by' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'published_at'],
                ]);
            }
        }

        if ($this->db->tableExists('files')) {
            if (! $this->hasField('files', 'published_by')) {
                $this->forge->addColumn('files', [
                    'published_by' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'published_at'],
                ]);
            }
        }
    }

    public function down()
    {
        if ($this->db->tableExists('folder')) {
            if ($this->hasField('folder', 'published_by')) {
                $this->forge->dropColumn('folder', 'published_by');
            }
            if ($this->hasField('folder', 'published_at')) {
                $this->forge->dropColumn('folder', 'published_at');
            }
            if ($this->hasField('folder', 'publication_channel')) {
                $this->forge->dropColumn('folder', 'publication_channel');
            }
        }

        if ($this->db->tableExists('files')) {
            if ($this->hasField('files', 'published_by')) {
                $this->forge->dropColumn('files', 'published_by');
            }
        }
    }

    private function hasField(string $table, string $field): bool
    {
        return in_array($field, $this->db->getFieldNames($table), true);
    }
}
