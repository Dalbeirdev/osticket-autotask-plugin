-- ---------------------------------------------------------------------------
-- osTicket Time Tracking & Billing mod — database provisioning
--
-- The PHP side of this feature is already merged into the osTicket codebase
-- (ticket-view time fields + timer, thread-entry time display, ticket total,
-- Admin > Settings > Ticket Time Settings, scp/timebill.php reports).
-- This script adds everything the DATABASE needs, and is safe to re-run
-- (idempotent). Adjust the ost_ prefix if your install differs.
--
-- Usage:  mysql -u root osticket < timebill-schema.sql
-- ---------------------------------------------------------------------------

-- 1) Thread-entry time columns (minutes, time-type list item id, billable
--    flag, invoiced flag). Used by class.thread.php / class.ticket.php.
ALTER TABLE `ost_thread_entry`
    ADD COLUMN IF NOT EXISTS `time_spent`   INT UNSIGNED     NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `time_type`    INT UNSIGNED     NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `time_bill`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `time_invoice` TINYINT UNSIGNED NOT NULL DEFAULT 0;

-- 2) "Time Type" dynamic list (DynamicList::lookup(['type' => 'time-type'])).
--    SortCol keeps the custom order: Telephone, Email, Remote, Workshop, Onsite.
INSERT INTO `ost_list` (`name`, `name_plural`, `sort_mode`, `masks`, `type`, `configuration`, `notes`, `created`, `updated`)
SELECT 'Time Type', 'Time Types', 'SortCol', 0, 'time-type', '{}', 'Work types selectable when logging time on a ticket.', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `ost_list` WHERE `type` = 'time-type');

INSERT INTO `ost_list_items` (`list_id`, `status`, `value`, `extra`, `sort`, `properties`)
SELECT l.`id`, 1, v.`value`, NULL, v.`sort`, '{}'
FROM `ost_list` l
JOIN (
    SELECT 'Telephone' AS `value`, 1 AS `sort` UNION ALL
    SELECT 'Email',     2 UNION ALL
    SELECT 'Remote',    3 UNION ALL
    SELECT 'Workshop',  4 UNION ALL
    SELECT 'Onsite',    5
) v
WHERE l.`type` = 'time-type'
  AND NOT EXISTS (
      SELECT 1 FROM `ost_list_items` i
      WHERE i.`list_id` = l.`id` AND i.`value` = v.`value`
  );

-- 3) Enable the feature (Admin Panel > Settings > Ticket Time Settings).
--    isthreadtime        = show Time Spent / Time Type on reply & note forms
--    isthreadtimer       = auto-running timer with play/pause/reset controls
--    isthreadbill        = show the "Billable?" checkbox
--    isthreadbilldefault = tick "Billable?" by default
--    isclienttime        = expose logged time in the client portal (off)
INSERT INTO `ost_config` (`namespace`, `key`, `value`)
SELECT 'core', c.`key`, c.`value`
FROM (
    SELECT 'isthreadtime' AS `key`, '1' AS `value` UNION ALL
    SELECT 'isthreadtimer',        '1' UNION ALL
    SELECT 'isthreadbill',         '1' UNION ALL
    SELECT 'isthreadbilldefault',  '1' UNION ALL
    SELECT 'isclienttime',         '0'
) c
WHERE NOT EXISTS (
    SELECT 1 FROM `ost_config` x
    WHERE x.`namespace` = 'core' AND x.`key` = c.`key`
);
