<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class AddIndependentDepartmentLibrary extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('department_folders')) {
            $this->forge->addField([
                'id'=>['type'=>'INT','auto_increment'=>true], 'department'=>['type'=>'VARCHAR','constraint'=>150],
                'source_folder_id'=>['type'=>'INT','null'=>true], 'parent_id'=>['type'=>'INT','null'=>true],
                'name'=>['type'=>'VARCHAR','constraint'=>255], 'path'=>['type'=>'TEXT','null'=>true],
                'description'=>['type'=>'TEXT','null'=>true], 'created_at'=>['type'=>'DATETIME','null'=>true],
                'is_deleted'=>['type'=>'TINYINT','default'=>0],
            ]);
            $this->forge->addKey('id',true);
            $this->forge->addUniqueKey(['department','source_folder_id']);
            $this->forge->createTable('department_folders');
        }
        if (!$this->db->tableExists('department_files')) {
            $fields = [
                'id'=>['type'=>'INT','auto_increment'=>true], 'department'=>['type'=>'VARCHAR','constraint'=>150],
                'source_media_id'=>['type'=>'INT','null'=>true], 'source_assignment_id'=>['type'=>'BIGINT','null'=>true],
                'delivery_key'=>['type'=>'VARCHAR','constraint'=>64], 'folder_id'=>['type'=>'INT','null'=>true],
                'filename'=>['type'=>'VARCHAR','constraint'=>255], 'filepath'=>['type'=>'TEXT'],
                'size'=>['type'=>'BIGINT','default'=>0], 'is_deleted'=>['type'=>'TINYINT','default'=>0],
                'downloadable'=>['type'=>'TINYINT','default'=>1], 'employee_id'=>['type'=>'INT','null'=>true],
            ];
            foreach (['display_title','filetype','mime_type','media_kind','description','tags','classification','detected_objects','content_hash','last_action','storage_stage','distribution_type','release_status'] as $field) $fields[$field]=['type'=>'TEXT','null'=>true];
            foreach (['dateupload','last_action_at','assigned_at','deleted_at'] as $field) $fields[$field]=['type'=>'DATETIME','null'=>true];
            $this->forge->addField($fields);
            $this->forge->addKey('id',true);
            $this->forge->addUniqueKey(['department','delivery_key']);
            $this->forge->addKey(['department','is_deleted']);
            // Source IDs are provenance, deliberately without cascading foreign keys.
            $this->forge->createTable('department_files');
        }
    }
    public function down()
    {
        $this->forge->dropTable('department_files',true);
        $this->forge->dropTable('department_folders',true);
    }
}
