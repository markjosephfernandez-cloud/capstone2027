<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddUserAdministrationFields extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('user')) {
            return;
        }
        $fields = $this->db->getFieldNames('user');
        $columns = [];
        if (! in_array('is_active', $fields, true)) {
            $columns['is_active'] = ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1, 'null' => false, 'after' => 'role'];
        }
        if (! in_array('created_at', $fields, true)) {
            $columns['created_at'] = ['type' => 'DATETIME', 'null' => true];
        }
        if (! in_array('updated_at', $fields, true)) {
            $columns['updated_at'] = ['type' => 'DATETIME', 'null' => true];
        }
        if (! in_array('last_login_at', $fields, true)) {
            $columns['last_login_at'] = ['type' => 'DATETIME', 'null' => true];
        }
        if ($columns !== []) {
            $this->forge->addColumn('user', $columns);
        }
        $this->db->query('UPDATE `user` SET `is_active` = 1 WHERE `is_active` IS NULL');
    }

    public function down()
    {
        if (! $this->db->tableExists('user')) {
            return;
        }
        $fields = array_intersect(['is_active', 'created_at', 'updated_at', 'last_login_at'], $this->db->getFieldNames('user'));
        if ($fields !== []) {
            $this->forge->dropColumn('user', array_values($fields));
        }
    }
}
