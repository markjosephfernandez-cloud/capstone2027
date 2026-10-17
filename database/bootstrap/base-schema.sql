-- Empty baseline schema for a NEW Greyhound database. Contains no users or media.
-- Import addons.sql next, then run php spark migrate.
SET NAMES utf8mb4;

CREATE TABLE `files` (
  `id` int(11) NOT NULL COMMENT 'A unique numerical identifier for each file, essential for precise file referencing and tracking.',
  `filename` varchar(255) NOT NULL COMMENT 'The designated name of each file, useful for quick identification and access.',
  `filepath` text DEFAULT NULL,
  `filetype` varchar(255) DEFAULT NULL,
  `size` bigint(20) NOT NULL COMMENT 'The file’s size in relevant units (bytes, KB, MB), aiding in assessing storage needs and data management.',
  `dateupload` datetime DEFAULT NULL,
  `description` text DEFAULT NULL COMMENT 'Provides a brief summary or additional information about the content or purpose of each file, aiding in file management and identification.',
  `last_scanned` datetime DEFAULT NULL,
  `filehash` text DEFAULT NULL,
  `detected_objects` text DEFAULT NULL,
  `classification` text DEFAULT NULL,
  `pose` text DEFAULT NULL,
  `gesture` text DEFAULT NULL,
  `datecreated` text DEFAULT NULL,
  `emotion` varchar(255) DEFAULT NULL,
  `content_hash` text DEFAULT NULL,
  `scan_id` varchar(255) DEFAULT NULL,
  `bounding_boxes` text DEFAULT NULL,
  `duplicate_of` int(11) DEFAULT NULL,
  `new_scanned` tinyint(1) DEFAULT 1,
  `featured` tinyint(1) DEFAULT NULL,
  `folder_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `last_action` enum('updated','deleted','restored','moved_to_trash','duplicated','renamed','downloaded','uploaded') DEFAULT NULL,
  `last_action_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `folder` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `recent` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `item_type` enum('file','folder') NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `filepath` varchar(255) NOT NULL,
  `timestamp` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tag` (
  `tag_id` int(11) NOT NULL,
  `tag` varchar(255) NOT NULL,
  `type` enum('image','audio','video') NOT NULL,
  `filepath` text NOT NULL,
  `file_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `trashfiles` (
  `id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  `deleted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_by` int(11) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `user` (
  `employee_id` int(11) NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `position` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_address` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('admin','school_user','guest','staff') DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_file_employee` (`employee_id`),
  ADD KEY `fk_files_folder` (`folder_id`);

ALTER TABLE `folder`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `recent`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`);

ALTER TABLE `tag`
  ADD PRIMARY KEY (`tag_id`),
  ADD KEY `idx_tag_file` (`file_id`);

ALTER TABLE `trashfiles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_trash_file` (`file_id`),
  ADD KEY `fk_trash_user` (`deleted_by`);

ALTER TABLE `user`
  ADD PRIMARY KEY (`employee_id`);

ALTER TABLE `files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'A unique numerical identifier for each file, essential for precise file referencing and tracking.';

ALTER TABLE `folder`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `recent`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `tag`
  MODIFY `tag_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `trashfiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `files`
  ADD CONSTRAINT `fk_file_employee` FOREIGN KEY (`employee_id`) REFERENCES `user` (`employee_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_file_folder` FOREIGN KEY (`folder_id`) REFERENCES `folder` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_files_folder` FOREIGN KEY (`folder_id`) REFERENCES `folder` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `recent`
  ADD CONSTRAINT `recent_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `user` (`employee_id`) ON DELETE CASCADE;

ALTER TABLE `tag`
  ADD CONSTRAINT `fk_tag_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `trashfiles`
  ADD CONSTRAINT `fk_trash_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_trash_user` FOREIGN KEY (`deleted_by`) REFERENCES `user` (`employee_id`) ON DELETE SET NULL;
