<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMobileFeatured extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT'],
            'photo_ids' => ['type' => 'TEXT', 'null' => true],
            'version' => ['type' => 'INT', 'default' => 1],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('mobile_featured', true);
        $this->db->table('mobile_featured')->insert(['id' => 1, 'photo_ids' => null, 'version' => 1]);
    }

    public function down()
    {
        $this->forge->dropTable('mobile_featured', true);
    }
}
