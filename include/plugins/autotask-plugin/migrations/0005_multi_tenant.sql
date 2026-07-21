-- ---------------------------------------------------------------------------
-- Autotask Integration — migration 0005: multi-tenant foundation (schema v2)
--
-- 1. New `autotask_instance` table: one row per client Autotask tenant
--    (name, encrypted credentials, zone, registered department, options).
-- 2. Every data table gains `instance_id` (default 1) so all synced rows are
--    permanently bound to their tenant. Existing single-tenant rows keep
--    working: they all belong to the seeded instance #1.
--
-- Seeding of instance #1 from the current plugin config happens in PHP
-- (InstanceRepository::seedFromConfig) right after this migration runs,
-- because the config store is not reachable from SQL.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `%PREFIX%autotask_instance` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `code` VARCHAR(16) NOT NULL,
  `api_username` VARCHAR(255) NOT NULL DEFAULT '',
  `api_secret` TEXT NULL,
  `api_integration_code` VARCHAR(255) NOT NULL DEFAULT '',
  `zone_url` VARCHAR(255) NOT NULL DEFAULT '',
  `web_base` VARCHAR(255) NOT NULL DEFAULT '',
  `department_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `config_json` TEXT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `last_sync_at` DATETIME NULL,
  `last_ok` TINYINT(1) NULL,
  `created` DATETIME NOT NULL,
  `updated` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `%PREFIX%autotask_ticket_map`
  ADD COLUMN `instance_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD KEY `idx_instance` (`instance_id`);

ALTER TABLE `%PREFIX%autotask_sync_queue`
  ADD COLUMN `instance_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD KEY `idx_instance` (`instance_id`);

ALTER TABLE `%PREFIX%autotask_time_entry`
  ADD COLUMN `instance_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD KEY `idx_instance` (`instance_id`);

ALTER TABLE `%PREFIX%autotask_note_map`
  ADD COLUMN `instance_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD KEY `idx_instance` (`instance_id`);

ALTER TABLE `%PREFIX%autotask_audit`
  ADD COLUMN `instance_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD KEY `idx_instance` (`instance_id`);

ALTER TABLE `%PREFIX%autotask_log`
  ADD COLUMN `instance_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD KEY `idx_instance` (`instance_id`);

ALTER TABLE `%PREFIX%autotask_sync_history`
  ADD COLUMN `instance_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD KEY `idx_instance` (`instance_id`);

ALTER TABLE `%PREFIX%autotask_picklist_cache`
  ADD COLUMN `instance_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD KEY `idx_instance` (`instance_id`);

ALTER TABLE `%PREFIX%autotask_conflict`
  ADD COLUMN `instance_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD KEY `idx_instance` (`instance_id`);

ALTER TABLE `%PREFIX%autotask_import_filter`
  ADD COLUMN `instance_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`,
  ADD KEY `idx_instance` (`instance_id`);
