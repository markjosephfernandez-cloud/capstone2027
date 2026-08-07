<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddNestedFolders extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('folder') || in_array('parent_id', $this->db->getFieldNames('folder'), true)) {
            return;
        }
        $this->forge->addColumn('folder', [
            'parent_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'id'],
        ]);
        $this->db->query('CREATE INDEX `idx_folder_parent` ON `folder` (`parent_id`, `name`)');
    }

    public function down()
    {
        if ($this->db->tableExists('folder') && in_array('parent_id', $this->db->getFieldNames('folder'), true)) {
            $this->forge->dropColumn('folder', 'parent_id');
        }
    }
}
