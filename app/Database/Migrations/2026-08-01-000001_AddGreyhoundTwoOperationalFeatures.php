<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddGreyhoundTwoOperationalFeatures extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('ai_event_detections')) {
            $this->forge->addField([
                'id'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'media_id'         => ['type' => 'INT', 'constraint' => 11, 'null' => false],
                'event_name'       => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'confidence_score' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
                'detector_version' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => false],
                'evidence_json'    => ['type' => 'TEXT', 'null' => true],
                'needs_review'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'processed_at'     => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('media_id', 'uniq_event_detection_media');
            $this->forge->addKey(['event_name', 'needs_review'], false, false, 'idx_event_review');
            $this->forge->createTable('ai_event_detections', true);
        }

        if (! $this->db->tableExists('privacy_consents')) {
            $this->forge->addField([
                'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'employee_id'    => ['type' => 'INT', 'constraint' => 11, 'null' => false],
                'policy_version' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
                'accepted_at'    => ['type' => 'DATETIME', 'null' => false],
                'reminded_at'    => ['type' => 'DATETIME', 'null' => true],
                'ip_address'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['employee_id', 'policy_version'], 'uniq_user_policy_consent');
            $this->forge->addKey('reminded_at', false, false, 'idx_consent_reminder');
            $this->forge->createTable('privacy_consents', true);
        }

        if ($this->db->tableExists('files') && ! $this->hasIndex('files', 'idx_files_search_listing')) {
            $this->db->query('CREATE INDEX `idx_files_search_listing` ON `files` (`is_deleted`, `filetype`, `dateupload`)');
        }
    }

    public function down()
    {
        if ($this->db->tableExists('ai_event_detections')) {
            $this->forge->dropTable('ai_event_detections', true);
        }
        if ($this->db->tableExists('privacy_consents')) {
            $this->forge->dropTable('privacy_consents', true);
        }
        if ($this->db->tableExists('files') && $this->hasIndex('files', 'idx_files_search_listing')) {
            $this->db->query('DROP INDEX `idx_files_search_listing` ON `files`');
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return $this->db->query("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index])->getNumRows() > 0;
    }
}
