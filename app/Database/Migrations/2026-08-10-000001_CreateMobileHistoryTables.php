<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMobileHistoryTables extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('mobile_watch_history')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'file_id' => ['type' => 'INT'],
                'user_employee_id' => ['type' => 'INT'],
                'progress_seconds' => ['type' => 'INT', 'null' => true],
                'watched_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['user_employee_id', 'watched_at']);
            $this->forge->addForeignKey('file_id', 'files', 'id', 'CASCADE', 'CASCADE');
            $this->forge->addForeignKey('user_employee_id', 'user', 'employee_id', 'CASCADE', 'CASCADE');
            $this->forge->createTable('mobile_watch_history', true);
        }

        if (! $this->db->tableExists('mobile_download_history')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'file_id' => ['type' => 'INT'],
                'user_employee_id' => ['type' => 'INT'],
                'file_size_bytes' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'downloaded_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['user_employee_id', 'downloaded_at']);
            $this->forge->addForeignKey('file_id', 'files', 'id', 'CASCADE', 'CASCADE');
            $this->forge->addForeignKey('user_employee_id', 'user', 'employee_id', 'CASCADE', 'CASCADE');
            $this->forge->createTable('mobile_download_history', true);
        }
    }

    public function down()
    {
        foreach (['mobile_download_history', 'mobile_watch_history'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table, true);
            }
        }
    }
}
