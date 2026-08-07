<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDuplicateCounter extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('files') && ! in_array('duplicate_count', $this->db->getFieldNames('files'), true)) {
            $this->forge->addColumn('files', ['duplicate_count' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0, 'null' => false, 'after' => 'duplicate_of']]);
        }
    }

    public function down()
    {
        if ($this->db->tableExists('files') && in_array('duplicate_count', $this->db->getFieldNames('files'), true)) {
            $this->forge->dropColumn('files', 'duplicate_count');
        }
    }
}
