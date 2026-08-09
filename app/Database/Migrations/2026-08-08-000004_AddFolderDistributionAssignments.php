<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddFolderDistributionAssignments extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('media_assignment_folders')) {
            return;
        }

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'folder_id' => ['type' => 'INT', 'constraint' => 11, 'null' => false],
            'department' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => false],
            'request_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'method' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => false],
            'status' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'sent'],
            'sent_by' => ['type' => 'INT', 'constraint' => 11, 'null' => false],
            'sent_at' => ['type' => 'DATETIME', 'null' => false],
            'completed_by' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'completed_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['department', 'folder_id'], false, false, 'idx_folder_assignment_department');
        $this->forge->addKey(['request_id', 'folder_id'], false, false, 'idx_folder_assignment_request');
        $this->forge->createTable('media_assignment_folders', true);
    }

    public function down()
    {
        if ($this->db->tableExists('media_assignment_folders')) {
            $this->forge->dropTable('media_assignment_folders', true);
        }
    }
}
