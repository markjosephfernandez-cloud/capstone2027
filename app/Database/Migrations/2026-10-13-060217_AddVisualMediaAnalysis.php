<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddVisualMediaAnalysis extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'file_id' => ['type'=>'INT', 'unsigned'=>true],
            'result_json' => ['type'=>'LONGTEXT'],
            'analyzed_at' => ['type'=>'DATETIME'],
        ]);
        $this->forge->addKey('file_id', true);
        $this->forge->createTable('media_visual_analysis', true);
    }

    public function down()
    {
        $this->forge->dropTable('media_visual_analysis', true);
    }
}
