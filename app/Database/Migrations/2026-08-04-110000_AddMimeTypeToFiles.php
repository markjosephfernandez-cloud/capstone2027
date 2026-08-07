<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMimeTypeToFiles extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('mime_type', 'files')) {
            $this->forge->addColumn('files', [
                'mime_type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                    'null'       => true,
                    'after'      => 'filetype',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('mime_type', 'files')) {
            $this->forge->dropColumn('files', 'mime_type');
        }
    }
}
