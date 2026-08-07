<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class GroupApprovalRequests extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('approvals')) {
            return;
        }
        $fields = $this->db->getFieldNames('approvals');
        $columns = [];
        if (! in_array('request_group', $fields, true)) {
            $columns['request_group'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'id'];
        }
        if (! in_array('request_type', $fields, true)) {
            $columns['request_type'] = ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'single', 'after' => 'request_group'];
        }
        if (! in_array('folder_id', $fields, true)) {
            $columns['folder_id'] = ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'request_type'];
        }
        if (! in_array('group_title', $fields, true)) {
            $columns['group_title'] = ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'folder_id'];
        }
        if ($columns !== []) {
            $this->forge->addColumn('approvals', $columns);
        }

        $this->db->query("UPDATE `approvals` SET `request_group` = CONCAT('legacy-', `id`) WHERE `request_group` IS NULL OR `request_group` = ''");
        $this->db->query("UPDATE `approvals` SET `request_type` = 'single' WHERE `request_type` IS NULL OR `request_type` = ''");
        $this->db->query("UPDATE `approvals` SET `group_title` = `title` WHERE `group_title` IS NULL OR `group_title` = ''");
        if (! $this->hasIndex('approvals', 'idx_approval_request_group')) {
            $this->db->query('CREATE INDEX `idx_approval_request_group` ON `approvals` (`request_group`, `current_stage`, `overall_status`)');
        }
    }

    public function down()
    {
        if (! $this->db->tableExists('approvals')) {
            return;
        }
        if ($this->hasIndex('approvals', 'idx_approval_request_group')) {
            $this->db->query('DROP INDEX `idx_approval_request_group` ON `approvals`');
        }
        foreach (['group_title', 'folder_id', 'request_type', 'request_group'] as $field) {
            if (in_array($field, $this->db->getFieldNames('approvals'), true)) {
                $this->forge->dropColumn('approvals', $field);
            }
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return $this->db->query("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index])->getNumRows() > 0;
    }
}
