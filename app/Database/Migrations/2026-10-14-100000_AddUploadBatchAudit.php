<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class AddUploadBatchAudit extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'batch_key'=>['type'=>'VARCHAR','constraint'=>64],
            'audit_id'=>['type'=>'INT','null'=>true],
            'items_json'=>['type'=>'LONGTEXT'],
            'expected_count'=>['type'=>'INT','default'=>0],
        ]);
        $this->forge->addKey('batch_key', true);
        $this->forge->createTable('server_upload_audit_batches', true);
        // Audit IP history is intentionally erased; rollback cannot recover it.
        $this->db->table('audit_logs')->where('ip_address IS NOT NULL', null, false)->update(['ip_address'=>null]);
    }
    public function down()
    {
        $this->forge->dropTable('server_upload_audit_batches', true);
    }
}
