<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Irreversibly removes media approvals and makes every active file publishable by title. */
class RemoveApprovalsAndAddDisplayTitles extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('files')) {
            if (! in_array('display_title', $this->db->getFieldNames('files'), true)) {
                $this->forge->addColumn('files', ['display_title' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true, 'after' => 'filename']]);
            }
            $this->db->query("UPDATE `files` SET `display_title` = LEFT(COALESCE(NULLIF(TRIM(`description`), ''), NULLIF(TRIM(`classification`), ''), REPLACE(REPLACE(SUBSTRING_INDEX(`filename`, '.', 1), '_', ' '), '-', ' '), 'Untitled media'), 150) WHERE `display_title` IS NULL OR TRIM(`display_title`) = ''");
            $this->db->query("UPDATE `files` SET `release_status` = 'published', `featured` = 1, `published_at` = COALESCE(`published_at`, `processed_at`, `dateupload`, UTC_TIMESTAMP()), `last_action` = 'published', `last_action_at` = COALESCE(`last_action_at`, UTC_TIMESTAMP()) WHERE `is_deleted` = 0");
            $this->db->query("UPDATE `files` SET `release_status` = 'withdrawn' WHERE `is_deleted` = 1");
            $this->db->query("UPDATE `files` SET `last_action` = 'updated' WHERE `last_action` = 'publication_requested'");
            $this->db->query("ALTER TABLE `files` MODIFY `display_title` VARCHAR(150) NOT NULL");
            $this->db->query("ALTER TABLE `files` MODIFY `release_status` ENUM('published','withdrawn') NOT NULL DEFAULT 'published'");
            $this->db->query("ALTER TABLE `files` MODIFY `last_action` ENUM('updated','deleted','restored','moved_to_trash','duplicated','renamed','downloaded','uploaded','tagged','published') NULL DEFAULT NULL");
            if ($this->db->tableExists('recent')) {
                $this->db->query("UPDATE `recent` r INNER JOIN `files` f ON f.`filepath` = r.`filepath` SET r.`item_name` = f.`display_title` WHERE r.`item_type` = 'file'");
            }
        }

        if ($this->db->tableExists('notifications')) {
            $this->db->table('notifications')->groupStart()->like('type', 'approval', 'after')->orWhereIn('type', ['passport_reviewed'])->groupEnd()->delete();
        }

        if ($this->db->tableExists('duplicate_media') && ! in_array('display_title', $this->db->getFieldNames('duplicate_media'), true)) {
            $this->forge->addColumn('duplicate_media', ['display_title' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true, 'after' => 'filename']]);
            $this->db->query("UPDATE `duplicate_media` SET `display_title` = 'Duplicate media upload' WHERE `display_title` IS NULL OR TRIM(`display_title`) = ''");
            $this->db->query("ALTER TABLE `duplicate_media` MODIFY `display_title` VARCHAR(150) NOT NULL");
        }

        if ($this->db->tableExists('passports')) {
            $fields = $this->db->getFieldNames('passports');
            $remove = array_values(array_intersect(['approval_status', 'reviewed_by', 'reviewed_at', 'review_notes'], $fields));
            if ($remove !== []) {
                $this->forge->dropColumn('passports', $remove);
            }
            $this->db->table('passports')->update(['usage_restrictions' => 'Automatically published after AI processing.']);
        }

        foreach (['approval_steps', 'approvals'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table, true);
            }
        }
    }

    public function down()
    {
        // Approval records were intentionally deleted and cannot be reconstructed.
        if ($this->db->tableExists('files')) {
            $this->db->query("ALTER TABLE `files` MODIFY `release_status` ENUM('draft','in_review','approved','published','withdrawn') NULL DEFAULT 'draft'");
            $this->db->query("ALTER TABLE `files` MODIFY `last_action` ENUM('updated','deleted','restored','moved_to_trash','duplicated','renamed','downloaded','uploaded','publication_requested','published') NULL DEFAULT NULL");
            if (in_array('display_title', $this->db->getFieldNames('files'), true)) {
                $this->forge->dropColumn('files', 'display_title');
            }
        }
        if ($this->db->tableExists('duplicate_media') && in_array('display_title', $this->db->getFieldNames('duplicate_media'), true)) {
            $this->forge->dropColumn('duplicate_media', 'display_title');
        }
    }
}
