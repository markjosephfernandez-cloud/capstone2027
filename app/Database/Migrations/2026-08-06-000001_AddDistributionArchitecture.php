<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Adds the Greyhound Hub 2.0 distribution workflow without replacing legacy data. */
class AddDistributionArchitecture extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('user')) {
            $this->db->query("ALTER TABLE `user` MODIFY `role` ENUM('super_admin','admin','writer','department','staff','school_user','guest') NULL");
            // Preserve legacy authority while adopting the approved role names.
            $this->db->query("UPDATE `user` SET `role` = 'super_admin' WHERE `role` = 'admin'");
            $this->db->query("UPDATE `user` SET `role` = 'admin' WHERE `role` = 'staff'");
        }
        $this->addColumn('files', 'storage_stage', ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'processed', 'null' => false, 'after' => 'release_status']);
        $this->addColumn('files', 'distribution_type', ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'after' => 'storage_stage']);
        $this->addColumn('files', 'distribution_target', ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true, 'after' => 'distribution_type']);
        $this->addColumn('files', 'processed_at', ['type' => 'DATETIME', 'null' => true, 'after' => 'distribution_target']);

        if (! $this->db->tableExists('duplicate_media')) {
            $this->forge->addField([
                'id'               => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'original_file_id' => ['type' => 'INT', 'constraint' => 11, 'null' => false],
                'filename'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
                'filepath'         => ['type' => 'TEXT', 'null' => false],
                'mime_type'        => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'size'             => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
                'content_hash'     => ['type' => 'CHAR', 'constraint' => 64, 'null' => false],
                'uploaded_by'      => ['type' => 'INT', 'constraint' => 11, 'null' => false],
                'status'           => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'pending'],
                'created_at'       => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('original_file_id', false, false, 'idx_duplicate_original');
            $this->forge->addKey('content_hash', false, false, 'idx_duplicate_hash');
            $this->forge->createTable('duplicate_media', true);
        }

        if (! $this->db->tableExists('media_requests')) {
            $this->forge->addField([
                'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'department'     => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => false],
                'title'          => ['type' => 'VARCHAR', 'constraint' => 180, 'null' => false],
                'description'    => ['type' => 'TEXT', 'null' => true],
                'status'         => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'submitted'],
                'requested_by'   => ['type' => 'INT', 'constraint' => 11, 'null' => false],
                'submitted_at'   => ['type' => 'DATETIME', 'null' => false],
                'transmitted_by' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'transmitted_at' => ['type' => 'DATETIME', 'null' => true],
                'completed_by'   => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'completed_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['department', 'status'], false, false, 'idx_requests_department_status');
            $this->forge->createTable('media_requests', true);
        }

        if (! $this->db->tableExists('media_assignments')) {
            $this->forge->addField([
                'id'            => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'media_id'      => ['type' => 'INT', 'constraint' => 11, 'null' => false],
                'department'    => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => false],
                'request_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'method'        => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => false],
                'status'        => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'sent'],
                'sent_by'       => ['type' => 'INT', 'constraint' => 11, 'null' => false],
                'sent_at'       => ['type' => 'DATETIME', 'null' => false],
                'completed_by'  => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'completed_at'  => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['media_id', 'department', 'request_id'], 'uniq_media_department_request');
            $this->forge->addKey(['department', 'status'], false, false, 'idx_assignment_department_status');
            $this->forge->createTable('media_assignments', true);
        }

        if (! $this->db->tableExists('distribution_actions')) {
            $this->forge->addField([
                'id'            => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'request_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'assignment_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'action'        => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => false],
                'actor_id'      => ['type' => 'INT', 'constraint' => 11, 'null' => false],
                'actor_name'    => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => false],
                'actor_role'    => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => false],
                'department'    => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'file_count'    => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
                'created_at'    => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['request_id', 'created_at'], false, false, 'idx_distribution_request');
            $this->forge->addKey(['assignment_id', 'created_at'], false, false, 'idx_distribution_assignment');
            $this->forge->createTable('distribution_actions', true);
        }

        $this->addIndex('files', 'idx_files_stage_distribution', '`is_deleted`, `storage_stage`, `distribution_type`');
        $this->addIndex('files', 'idx_files_content_hash', '`content_hash`');
    }

    public function down()
    {
        foreach (['distribution_actions', 'media_assignments', 'media_requests', 'duplicate_media'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table, true);
            }
        }
        if ($this->db->tableExists('files')) {
            $fields = array_intersect(['storage_stage', 'distribution_type', 'distribution_target', 'processed_at'], $this->db->getFieldNames('files'));
            if ($fields !== []) {
                $this->forge->dropColumn('files', array_values($fields));
            }
        }
        if ($this->db->tableExists('user')) {
            $this->db->query("UPDATE `user` SET `role` = 'admin' WHERE `role` = 'super_admin'");
            $this->db->query("UPDATE `user` SET `role` = 'staff' WHERE `role` IN ('writer', 'department')");
            $this->db->query("ALTER TABLE `user` MODIFY `role` ENUM('admin','school_user','guest','staff') NULL");
        }
    }

    /** @param array<string, mixed> $definition */
    private function addColumn(string $table, string $column, array $definition): void
    {
        if ($this->db->tableExists($table) && ! in_array($column, $this->db->getFieldNames($table), true)) {
            $this->forge->addColumn($table, [$column => $definition]);
        }
    }

    private function addIndex(string $table, string $index, string $columns): void
    {
        if ($this->db->query('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ' . $this->db->escape($index))->getNumRows() === 0) {
            $this->db->query('ALTER TABLE `' . $table . '` ADD INDEX `' . $index . '` (' . $columns . ')');
        }
    }
}
