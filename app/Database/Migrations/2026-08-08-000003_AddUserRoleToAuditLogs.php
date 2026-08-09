<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddUserRoleToAuditLogs extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('audit_logs') && ! in_array('user_role', $this->db->getFieldNames('audit_logs'), true)) {
            $this->forge->addColumn('audit_logs', [
                'user_role' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 50,
                    'null'       => true,
                    'after'      => 'user_name',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->tableExists('audit_logs') && in_array('user_role', $this->db->getFieldNames('audit_logs'), true)) {
            $this->forge->dropColumn('audit_logs', 'user_role');
        }
    }
}
