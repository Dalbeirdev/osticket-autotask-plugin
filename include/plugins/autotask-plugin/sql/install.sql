-- ---------------------------------------------------------------------------
-- Autotask Integration — full schema snapshot (manual install reference)
--
-- The plugin installs these automatically via the migration runner. This file
-- is provided for DBAs who prefer to provision the schema by hand.
--
-- Replace `ost_` below with your osTicket TABLE_PREFIX if different.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ost_autotask_migrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename` VARCHAR(191) NOT NULL,
  `applied_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_autotask_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skey` VARCHAR(191) NOT NULL,
  `svalue` LONGTEXT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_skey` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_autotask_ticket_map` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `osticket_ticket_id` INT UNSIGNED NOT NULL,
  `autotask_ticket_id` BIGINT UNSIGNED NULL,
  `autotask_ticket_number` VARCHAR(64) NULL,
  `last_sync_time` DATETIME NULL,
  `last_updated_by` VARCHAR(16) NOT NULL DEFAULT 'system',
  `sync_direction` ENUM('to_autotask','to_osticket','bidirectional') NOT NULL DEFAULT 'bidirectional',
  `osticket_hash` VARCHAR(64) NULL,
  `autotask_lastactivity` DATETIME NULL,
  `created` DATETIME NOT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_osticket` (`osticket_ticket_id`),
  KEY `idx_autotask` (`autotask_ticket_id`),
  KEY `idx_lastsync` (`last_sync_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_autotask_sync_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `osticket_ticket_id` INT UNSIGNED NULL,
  `entity_type` VARCHAR(32) NOT NULL,
  `action` VARCHAR(32) NOT NULL,
  `payload` LONGTEXT NULL,
  `status` ENUM('pending','processing','done','failed','dead') NOT NULL DEFAULT 'pending',
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `next_attempt_at` DATETIME NOT NULL,
  `last_error` TEXT NULL,
  `dedupe_key` VARCHAR(191) NULL,
  `created` DATETIME NOT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_next` (`status`, `next_attempt_at`),
  KEY `idx_ticket` (`osticket_ticket_id`),
  UNIQUE KEY `uq_dedupe` (`dedupe_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_autotask_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `level` ENUM('debug','info','warning','error') NOT NULL DEFAULT 'info',
  `category` VARCHAR(64) NOT NULL DEFAULT 'general',
  `osticket_ticket_id` INT UNSIGNED NULL,
  `autotask_ticket_id` BIGINT UNSIGNED NULL,
  `message` TEXT NOT NULL,
  `request` MEDIUMTEXT NULL,
  `response` MEDIUMTEXT NULL,
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_level` (`level`),
  KEY `idx_category` (`category`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_autotask_sync_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `map_id` INT UNSIGNED NULL,
  `osticket_ticket_id` INT UNSIGNED NULL,
  `autotask_ticket_id` BIGINT UNSIGNED NULL,
  `direction` ENUM('to_autotask','to_osticket') NOT NULL,
  `entity_type` VARCHAR(32) NOT NULL,
  `status` ENUM('success','failed','skipped') NOT NULL,
  `summary` VARCHAR(255) NULL,
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_map` (`map_id`),
  KEY `idx_created` (`created`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- NOTE: the sync_queue also gains a `direction` column (migration 0002):
--   ALTER TABLE `ost_autotask_sync_queue`
--     ADD COLUMN `direction` ENUM('to_autotask','to_osticket')
--     NOT NULL DEFAULT 'to_autotask' AFTER `entity_type`;

-- ---- Enterprise foundation tables (migration 0003) ----------------------

CREATE TABLE IF NOT EXISTS `ost_autotask_note_map` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_map_id` INT UNSIGNED NOT NULL,
  `osticket_entry_id` INT UNSIGNED NULL,
  `autotask_note_id` BIGINT UNSIGNED NULL,
  `direction` ENUM('to_autotask','to_osticket') NOT NULL,
  `note_type` VARCHAR(16) NOT NULL DEFAULT 'note',
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_at_note` (`autotask_note_id`),
  UNIQUE KEY `uq_os_entry` (`osticket_entry_id`),
  KEY `idx_map` (`ticket_map_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_autotask_time_entry` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_map_id` INT UNSIGNED NOT NULL,
  `osticket_ticket_id` INT UNSIGNED NOT NULL,
  `autotask_ticket_id` BIGINT UNSIGNED NULL,
  `autotask_time_entry_id` BIGINT UNSIGNED NULL,
  `resource_id` INT UNSIGNED NULL,
  `start_datetime` DATETIME NULL,
  `end_datetime` DATETIME NULL,
  `hours_worked` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `billing_code_id` INT NULL,
  `role_id` INT NULL,
  `billable` TINYINT(1) NOT NULL DEFAULT 1,
  `summary_notes` TEXT NULL,
  `internal_notes` TEXT NULL,
  `status` ENUM('pending','synced','failed') NOT NULL DEFAULT 'pending',
  `created_by` INT UNSIGNED NULL,
  `last_error` TEXT NULL,
  `created` DATETIME NOT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_map` (`ticket_map_id`),
  KEY `idx_at_te` (`autotask_time_entry_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_autotask_picklist_cache` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity` VARCHAR(48) NOT NULL,
  `field` VARCHAR(48) NOT NULL,
  `value` VARCHAR(64) NOT NULL,
  `label` VARCHAR(191) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `fetched_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_efv` (`entity`, `field`, `value`),
  KEY `idx_ef` (`entity`, `field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_autotask_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id` INT UNSIGNED NULL,
  `staff_name` VARCHAR(128) NULL,
  `action` VARCHAR(64) NOT NULL,
  `entity` VARCHAR(32) NULL,
  `osticket_ticket_id` INT UNSIGNED NULL,
  `autotask_ticket_id` BIGINT UNSIGNED NULL,
  `detail` LONGTEXT NULL,
  `ip` VARCHAR(45) NULL,
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_staff` (`staff_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_autotask_import_filter` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `criteria` LONGTEXT NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created` DATETIME NOT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ost_autotask_conflict` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_map_id` INT UNSIGNED NOT NULL,
  `osticket_ticket_id` INT UNSIGNED NULL,
  `autotask_ticket_id` BIGINT UNSIGNED NULL,
  `field` VARCHAR(48) NOT NULL,
  `osticket_value` TEXT NULL,
  `autotask_value` TEXT NULL,
  `resolution` ENUM('unresolved','autotask_wins','osticket_wins','manual') NOT NULL DEFAULT 'unresolved',
  `resolved` TINYINT(1) NOT NULL DEFAULT 0,
  `created` DATETIME NOT NULL,
  `resolved_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_map` (`ticket_map_id`),
  KEY `idx_resolved` (`resolved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
