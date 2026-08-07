<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMobileCommunityTables extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'file_id' => ['type' => 'INT'],
            'user_employee_id' => ['type' => 'INT'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['file_id', 'user_employee_id']);
        $this->forge->addKey('file_id');
        $this->forge->addForeignKey('file_id', 'files', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_employee_id', 'user', 'employee_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('mobile_media_likes', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'file_id' => ['type' => 'INT'],
            'user_employee_id' => ['type' => 'INT'],
            'comment' => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['file_id', 'created_at']);
        $this->forge->addForeignKey('file_id', 'files', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_employee_id', 'user', 'employee_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('mobile_comments', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'channel_key' => ['type' => 'VARCHAR', 'constraint' => 100],
            'user_employee_id' => ['type' => 'INT'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['channel_key', 'user_employee_id']);
        $this->forge->addForeignKey('user_employee_id', 'user', 'employee_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('mobile_channel_subscriptions', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_employee_id' => ['type' => 'INT'],
            'query' => ['type' => 'VARCHAR', 'constraint' => 255],
            'searched_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_employee_id', 'searched_at']);
        $this->forge->addForeignKey('user_employee_id', 'user', 'employee_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('mobile_search_history', true);

        $this->forge->addField([
            'user_employee_id' => ['type' => 'INT'],
            'username' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'bio' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'profile_image' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('user_employee_id', true);
        $this->forge->addUniqueKey('username');
        $this->forge->addForeignKey('user_employee_id', 'user', 'employee_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('mobile_profiles', true);
    }

    public function down()
    {
        foreach (['mobile_search_history', 'mobile_channel_subscriptions', 'mobile_comments', 'mobile_media_likes', 'mobile_profiles'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
