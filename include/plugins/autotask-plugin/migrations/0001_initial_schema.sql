-- ---------------------------------------------------------------------------
-- Autotask Integration — migration 0001: initial schema
--
-- %PREFIX% is replaced at runtime with osTicket's TABLE_PREFIX (e.g. ost_).
-- Statements are separated by a single semicolon at end-of-line.
-- All tables are InnoDB / utf8mb4 to match modern osTicket installs.
-- ---------------------------------------------------------------------------

-- Runtime key/value settings & sync cursors (distinct from encrypted creds).
CREATE TABLE IF NOT EXISTS `%PREFIX%autotask_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skey` VARCHAR(191) NOT NULL,
  `svalue` LONGTEXT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_skey` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mapping between osTicket tickets and Autotask tickets.
CREATE TABLE IF NOT EXISTS `%PREFIX%autotask_ticket_map` (
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

-- Outbound retry queue (osTicket -> Autotask) with exponential backoff.
CREATE TABLE IF NOT EXISTS `%PREFIX%autotask_sync_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `osticket_ticket_id` INT UNSIGNED NULL,
  `entity_type` VARCHAR(32) NOT NULL,           -- ticket | note | reply | status | priority
  `action` VARCHAR(32) NOT NULL,                -- create | update | append
  `payload` LONGTEXT NULL,                       -- JSON instructions
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

-- Structured log of API requests/responses, errors and auth failures.
CREATE TABLE IF NOT EXISTS `%PREFIX%autotask_log` (
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

-- Per-event sync history for the dashboard / audit trail.
CREATE TABLE IF NOT EXISTS `%PREFIX%autotask_sync_history` (
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
