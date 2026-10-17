<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTrashOriginalPath extends Migration
{
    public function up()
    {
        if (! in_array('original_filepath', $this->db->getFieldNames('trashfiles'), true)) {
            $this->forge->addColumn('trashfiles', [
                'original_filepath' => ['type' => 'TEXT', 'null' => true],
            ]);
        }
    }

    public function down()
    {
        // Restoring this schema while physical Trash is populated would lose
        // the only record of the original locations.
        if ($this->db->table('trashfiles')->where('original_filepath IS NOT NULL', null, false)->countAllResults() > 0) {
            throw new \RuntimeException('Restore or permanently delete physical Trash items before rolling back this migration.');
        }
        $this->forge->dropColumn('trashfiles', 'original_filepath');
    }
}
