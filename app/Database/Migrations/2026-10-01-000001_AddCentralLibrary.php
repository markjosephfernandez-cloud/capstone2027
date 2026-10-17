<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCentralLibrary extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('media_assignments')) {
            $indexes = $this->db->getIndexData('media_assignments');
            if (isset($indexes['uniq_media_department_request'])) $this->forge->dropKey('media_assignments','uniq_media_department_request');
            if (!isset($indexes['uniq_media_department_cycle'])) {
                $this->forge->addUniqueKey(['media_id','department','request_id','cycle_number'],'uniq_media_department_cycle');
                $this->forge->processIndexes('media_assignments');
            }
        }
        $this->forge->addField([
            'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
            'source_key'=>['type'=>'VARCHAR','constraint'=>64],
            'path_hash'=>['type'=>'VARCHAR','constraint'=>64], 'path'=>['type'=>'TEXT'],
            'is_dir'=>['type'=>'SMALLINT','default'=>0], 'is_deleted'=>['type'=>'SMALLINT','default'=>0],
            'file_id'=>['type'=>'INT','null'=>true], 'folder_id'=>['type'=>'INT','null'=>true],
            'metadata_json'=>['type'=>'LONGTEXT','null'=>true], 'updated_at'=>['type'=>'DATETIME'],
        ]);
        $this->forge->addKey('id',true);
        $this->forge->addUniqueKey(['source_key','path_hash']);
        $this->forge->addKey('file_id');
        $this->forge->createTable('server_media',true);
        $this->forge->addField(['source_key'=>['type'=>'VARCHAR','constraint'=>64], 'payload'=>['type'=>'LONGTEXT'], 'updated_at'=>['type'=>'DATETIME']]);
        $this->forge->addKey('source_key',true);
        $this->forge->createTable('server_metadata',true);
        $this->forge->addField([
            'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
            'employee_id'=>['type'=>'INT'], 'media_id'=>['type'=>'BIGINT','unsigned'=>true], 'viewed_at'=>['type'=>'DATETIME'],
        ]);
        $this->forge->addKey('id',true); $this->forge->addUniqueKey(['employee_id','media_id']);
        $this->forge->createTable('server_recent',true);
        $this->forge->addField([
            'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
            'employee_id'=>['type'=>'INT'], 'department_file_id'=>['type'=>'INT'], 'viewed_at'=>['type'=>'DATETIME'],
        ]);
        $this->forge->addKey('id',true); $this->forge->addUniqueKey(['employee_id','department_file_id']);
        $this->forge->createTable('department_recent',true);
        $this->forge->addField([
            'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true], 'media_id'=>['type'=>'BIGINT','unsigned'=>true],
            'status'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'pending'], 'attempts'=>['type'=>'INT','default'=>0],
            'result_json'=>['type'=>'LONGTEXT','null'=>true], 'last_error'=>['type'=>'TEXT','null'=>true], 'updated_at'=>['type'=>'DATETIME'],
        ]);
        $this->forge->addKey('id',true); $this->forge->addUniqueKey('media_id'); $this->forge->createTable('server_analysis_jobs',true);
        $this->forge->addField([
            'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
            'table_name'=>['type'=>'VARCHAR','constraint'=>64], 'record_id'=>['type'=>'BIGINT'],
            'old_path'=>['type'=>'TEXT'], 'new_path'=>['type'=>'TEXT'], 'sha256'=>['type'=>'VARCHAR','constraint'=>64],
            'status'=>['type'=>'VARCHAR','constraint'=>20], 'updated_at'=>['type'=>'DATETIME'],
        ]);
        $this->forge->addKey('id',true); $this->forge->addUniqueKey(['table_name','record_id']); $this->forge->createTable('central_storage_migration',true);
    }

    public function down()
    {
        foreach (['central_storage_migration','server_analysis_jobs','department_recent','server_recent','server_metadata','server_media'] as $table) $this->forge->dropTable($table,true);
    }
}
