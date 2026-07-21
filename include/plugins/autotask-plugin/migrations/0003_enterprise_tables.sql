-- ---------------------------------------------------------------------------
-- Autotask Integration — migration 0003: enterprise foundation tables
--
-- Adds storage for the Notes, Time Entry, Closure, Import-Filter, Audit and
-- Conflict modules. Relationships to autotask_ticket_map are LOGICAL (indexed
-- columns, no enforced FK constraints) to match osTicket's own schema style and
-- keep migrations robust across MySQL/MariaDB versions.
-- ---------------------------------------------------------------------------

-- Map osTicket thread entries <-> Autotask notes (two-way dedupe).
CREATE TABLE IF NOT EXISTS `%PREFIX%autotask_note_map` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_map_id` INT UNSIGNED NOT NULL,
  `osticket_entry_id` INT UNSIGNED NULL,
  `autotask_note_id` BIGINT UNSIGNED NULL,
  `direction` ENUM('to_autotask','to_osticket') NOT NULL,
  `note_type` VARCHAR(16) NOT NULL DEFAULT 'note',   -- note | reply
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_at_note` (`autotask_note_id`),
  UNIQUE KEY `uq_os_entry` (`osticket_entry_id`),
  KEY `idx_map` (`ticket_map_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Time entries created/synced for a mapped ticket.
CREATE TABLE IF NOT EXISTS `%PREFIX%autotask_time_entry` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_map_id` INT UNSIGNED NOT NULL,
  `osticket_ticket_id` INT UNSIGNED NOT NULL,
  `autotask_ticket_id` BIGINT UNSIGNED NULL,
  `autotask_time_entry_id` BIGINT UNSIGNED NULL,
  `resource_id` INT UNSIGNED NULL,
  `start_datetime` DATETIME NULL,
  `end_datetime` DATETIME NULL,
  `hours_worked` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `billing_code_id` INT NULL,      -- Autotask work type / billing code
  `role_id` INT NULL,
  `billable` TINYINT(1) NOT NULL DEFAULT 1,
  `summary_notes` TEXT NULL,
  `internal_notes` TEXT NULL,
  `status` ENUM('pending','synced','failed') NOT NULL DEFAULT 'pending',
  `created_by` INT UNSIGNED NULL,  -- osTicket staff id
  `last_error` TEXT NULL,
  `created` DATETIME NOT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_map` (`ticket_map_id`),
  KEY `idx_at_te` (`autotask_time_entry_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cached Autotask picklists (status/priority/queue/work types/roles/...).
CREATE TABLE IF NOT EXISTS `%PREFIX%autotask_picklist_cache` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity` VARCHAR(48) NOT NULL,   -- Tickets, TimeEntries, ...
  `field` VARCHAR(48) NOT NULL,    -- status, priority, queueID, billingCodeID...
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

-- Audit trail of privileged actions / change tracking.
CREATE TABLE IF NOT EXISTS `%PREFIX%autotask_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id` INT UNSIGNED NULL,
  `staff_name` VARCHAR(128) NULL,
  `action` VARCHAR(64) NOT NULL,   -- time.add, ticket.close, note.add, config.save...
  `entity` VARCHAR(32) NULL,
  `osticket_ticket_id` INT UNSIGNED NULL,
  `autotask_ticket_id` BIGINT UNSIGNED NULL,
  `detail` LONGTEXT NULL,          -- JSON context / before->after
  `ip` VARCHAR(45) NULL,
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_staff` (`staff_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Saved import filter sets (the admin checkboxes).
CREATE TABLE IF NOT EXISTS `%PREFIX%autotask_import_filter` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `criteria` LONGTEXT NOT NULL,    -- JSON: statuses[], queues[], companies[], resources[], unassigned, date_from/to, include_closed
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created` DATETIME NOT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Detected field-level conflicts awaiting resolution.
CREATE TABLE IF NOT EXISTS `%PREFIX%autotask_conflict` (
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
