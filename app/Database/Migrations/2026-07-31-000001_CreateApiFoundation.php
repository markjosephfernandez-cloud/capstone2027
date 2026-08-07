<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds only backwards-compatible fields/tables to the imported last_fm schema.
 * It deliberately does not recreate or drop any legacy media data.
 */
class CreateApiFoundation extends Migration
{
    public function up()
    {
        foreach (['user', 'files', 'folder', 'recent', 'tag', 'trashfiles'] as $table) {
            if (! $this->db->tableExists($table)) {
                throw new \RuntimeException('Expected legacy table `' . $table . '` is missing. Import last_fm before migrating.');
            }
        }

        $this->addColumnIfMissing('user', 'password_hash', ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'password']);
        $this->addColumnIfMissing('files', 'tags', ['type' => 'TEXT', 'null' => true, 'after' => 'description']);
        $this->addColumnIfMissing('files', 'downloadable', ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => false, 'after' => 'featured']);
        $this->addColumnIfMissing('folder', 'path', ['type' => 'VARCHAR', 'constraint' => 1024, 'null' => true, 'after' => 'description']);

        if (! $this->db->tableExists('api_tokens')) {
            $this->forge->addField([
                'id'               => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'user_employee_id' => ['type' => 'INT', 'constraint' => 11, 'null' => false],
                'token_hash'       => ['type' => 'CHAR', 'constraint' => 64, 'null' => false],
                'token_name'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false, 'default' => 'API token'],
                'expires_at'       => ['type' => 'DATETIME', 'null' => true],
                'last_used_at'     => ['type' => 'DATETIME', 'null' => true],
                'revoked_at'       => ['type' => 'DATETIME', 'null' => true],
                'created_at'       => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('token_hash', 'uq_api_tokens_hash');
            $this->forge->addKey('user_employee_id', false, false, 'idx_api_tokens_user');
            $this->forge->addKey('expires_at', false, false, 'idx_api_tokens_expiry');
            $this->forge->createTable('api_tokens', true);
        }

        if (! $this->db->tableExists('audit_logs')) {
            $this->forge->addField([
                'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'user_id'     => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'user_name'   => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'department'  => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'module'      => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'action'      => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'target_type' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'target_id'   => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'description' => ['type' => 'TEXT', 'null' => true],
                'ip_address'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'created_at'  => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('user_id', false, false, 'idx_audit_logs_user');
            $this->forge->addKey('module', false, false, 'idx_audit_logs_module');
            $this->forge->addKey('created_at', false, false, 'idx_audit_logs_created');
            $this->forge->createTable('audit_logs', true);
        }

        $this->addIndexIfMissing('files', 'idx_files_api_listing', '`is_deleted`, `dateupload`');
        $this->addIndexIfMissing('folder', 'idx_folder_path', '`path`');
    }

    public function down()
    {
        // Preserve upgraded password hashes and media metadata. Tokens are the
        // only data introduced solely for this API and are safe to remove on a rollback.
        if ($this->db->tableExists('api_tokens')) {
            $this->forge->dropTable('api_tokens', true);
        }
    }

    /** @param array<string, mixed> $definition */
    private function addColumnIfMissing(string $table, string $column, array $definition): void
    {
        if (! in_array($column, $this->db->getFieldNames($table), true)) {
            $this->forge->addColumn($table, [$column => $definition]);
        }
    }

    private function addIndexIfMissing(string $table, string $index, string $columns): void
    {
        $result = $this->db->query('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ' . $this->db->escape($index));
        if ($result->getNumRows() === 0) {
            $this->db->query('ALTER TABLE `' . $table . '` ADD INDEX `' . $index . '` (' . $columns . ')');
        }
    }
}
