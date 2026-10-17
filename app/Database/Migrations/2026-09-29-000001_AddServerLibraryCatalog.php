<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddServerLibraryCatalog extends Migration
{
    public function up()
    {
        $hash = ['type'=>'VARCHAR', 'constraint'=>64];
        $this->forge->addField([
            'source_key'=>$hash, 'run_id'=>['type'=>'VARCHAR','constraint'=>32],
            'status'=>['type'=>'VARCHAR','constraint'=>20], 'updated_at'=>['type'=>'DATETIME','null'=>true],
            'completed_at'=>['type'=>'DATETIME','null'=>true], 'last_error'=>['type'=>'TEXT','null'=>true],
        ]);
        $this->forge->addKey('source_key', true);
        $this->forge->createTable('server_library_sources', true);
        $this->forge->addField([
            'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
            'source_key'=>$hash, 'path_hash'=>$hash, 'parent_hash'=>$hash,
            'path'=>['type'=>'TEXT'], 'name'=>['type'=>'VARCHAR','constraint'=>255],
            'is_dir'=>['type'=>'SMALLINT','default'=>0], 'size'=>['type'=>'BIGINT','unsigned'=>true,'null'=>true],
            'modified_at'=>['type'=>'BIGINT','null'=>true], 'extension'=>['type'=>'VARCHAR','constraint'=>32],
            'tags'=>['type'=>'TEXT'], 'classification'=>['type'=>'TEXT'], 'date_taken'=>['type'=>'VARCHAR','constraint'=>100],
            'uploaded_at'=>['type'=>'VARCHAR','constraint'=>40,'null'=>true],
            'run_id'=>['type'=>'VARCHAR','constraint'=>32], 'indexed_at'=>['type'=>'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['source_key','path_hash']);
        $this->forge->addKey(['source_key','parent_hash','is_dir']);
        $this->forge->addKey(['source_key','run_id']);
        $this->forge->addKey('name');
        $this->forge->createTable('server_library_index', true);
        $this->forge->addField([
            'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
            'source_key'=>$hash, 'path'=>['type'=>'TEXT'], 'path_hash'=>$hash,
            'entry_offset'=>['type'=>'BIGINT','default'=>0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['source_key','path_hash']);
        $this->forge->createTable('server_library_scan_queue', true);
    }

    public function down()
    {
        foreach (['server_library_scan_queue','server_library_index','server_library_sources'] as $table) $this->forge->dropTable($table, true);
    }
}
