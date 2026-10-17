-- =====================================================================
-- Greyhound-Hub Add-on Migration
-- Adds: AI Metadata Passport, Watermarking, Notifications, Audit Log
-- Run this against the existing `last_fm` database.
-- Safe to re-run: every statement uses IF NOT EXISTS / guards.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. AI MEDIA RELEASE METADATA PASSPORT
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `passports` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `passport_uid` VARCHAR(40) NOT NULL COMMENT 'Public passport identifier, e.g. FU-PSP-2026-000123',
  `media_id` INT(11) NOT NULL,
  `event_name` VARCHAR(150) DEFAULT NULL,
  `department` VARCHAR(150) DEFAULT NULL,
  `photographer` VARCHAR(150) DEFAULT NULL,
  `uploaded_by` VARCHAR(50) DEFAULT NULL COMMENT 'user.employee_id',
  `date_taken` DATETIME DEFAULT NULL,
  `upload_date` DATETIME DEFAULT NULL,
  `gps_location` VARCHAR(150) DEFAULT NULL,
  `device_info` VARCHAR(255) DEFAULT NULL,
  `ai_classification` VARCHAR(150) DEFAULT NULL,
  `confidence_score` DECIMAL(5,2) DEFAULT NULL,
  `ai_version` VARCHAR(50) DEFAULT NULL,
  `processing_time_ms` INT(11) DEFAULT NULL,
  `needs_review` TINYINT(1) DEFAULT 0,
  -- Required temporarily by the historical review migration; removed by 2026-08-06.
  `approval_status` VARCHAR(30) DEFAULT NULL,
  `copyright_owner` VARCHAR(150) DEFAULT 'Foundation University',
  `distribution_rights` VARCHAR(255) DEFAULT 'Internal Use Only',
  `usage_restrictions` TEXT DEFAULT NULL,
  `digital_signature` VARCHAR(64) DEFAULT NULL COMMENT 'SHA-256 hash sealing the passport fields',
  `c2pa_compliant` TINYINT(1) DEFAULT 1,
  `qr_payload` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_passport_uid` (`passport_uid`),
  UNIQUE KEY `uniq_media_id` (`media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `passport_versions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `passport_id` INT(11) NOT NULL,
  `version_no` INT(11) NOT NULL DEFAULT 1,
  `snapshot_json` LONGTEXT DEFAULT NULL COMMENT 'Full JSON snapshot of the passport at this version',
  `changed_by` VARCHAR(50) DEFAULT NULL,
  `change_note` VARCHAR(255) DEFAULT NULL,
  `changed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_passport_id` (`passport_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 2. AUTOMATIC WATERMARKING
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `watermark_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `enabled` TINYINT(1) DEFAULT 1,
  `watermark_text` VARCHAR(150) DEFAULT 'Foundation University | FU Creatives',
  `position` ENUM('bottom_right','bottom_left','top_right','top_left','center') DEFAULT 'bottom_right',
  `opacity` INT(11) DEFAULT 55 COMMENT 'Percent 0-100',
  `resize_percent` INT(11) DEFAULT 100 COMMENT 'Scale of watermark text/logo relative to default',
  `logo_path` VARCHAR(255) DEFAULT NULL,
  `updated_by` VARCHAR(50) DEFAULT NULL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `watermark_settings` (`id`, `enabled`, `watermark_text`, `position`, `opacity`, `resize_percent`)
SELECT 1, 1, 'Foundation University | FU Creatives', 'bottom_right', 55, 100
WHERE NOT EXISTS (SELECT 1 FROM `watermark_settings` WHERE `id` = 1);

-- Track watermark state per file without altering the large `files` table structure much
CREATE TABLE IF NOT EXISTS `file_watermarks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `media_id` INT(11) NOT NULL,
  `watermarked_path` VARCHAR(500) DEFAULT NULL,
  `applied_by` VARCHAR(50) DEFAULT NULL,
  `applied_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `settings_snapshot` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_media_id` (`media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `recipient_id` VARCHAR(50) DEFAULT NULL COMMENT 'user.employee_id, NULL = role-wide notification',
  `recipient_role` VARCHAR(50) DEFAULT NULL,
  `type` VARCHAR(50) DEFAULT NULL COMMENT 'passport_generated | watermark_done | general',
  `title` VARCHAR(200) DEFAULT NULL,
  `message` TEXT DEFAULT NULL,
  `link` VARCHAR(255) DEFAULT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 4. AUDIT LOGS (richer than the existing `activity` table)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` VARCHAR(50) DEFAULT NULL,
  `user_name` VARCHAR(150) DEFAULT NULL,
  `department` VARCHAR(150) DEFAULT NULL,
  `module` VARCHAR(50) DEFAULT NULL COMMENT 'upload | download | delete | watermark | metadata | auth',
  `action` VARCHAR(50) DEFAULT NULL COMMENT 'LOGIN | LOGOUT | UPLOAD | DOWNLOAD | DELETE | WATERMARK_TOGGLE | METADATA_EDIT | PASSPORT_GENERATED',
  `target_type` VARCHAR(50) DEFAULT NULL COMMENT 'file | passport | settings',
  `target_id` INT(11) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_module` (`module`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
