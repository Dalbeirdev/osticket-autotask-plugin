-- ---------------------------------------------------------------------------
-- Autotask Integration — manual uninstall (DESTRUCTIVE)
--
-- Removes every table created by the plugin. Run only if you want to delete all
-- mapping/log/queue/history data. Replace `ost_` with your TABLE_PREFIX.
-- ---------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `ost_autotask_conflict`;
DROP TABLE IF EXISTS `ost_autotask_import_filter`;
DROP TABLE IF EXISTS `ost_autotask_audit`;
DROP TABLE IF EXISTS `ost_autotask_picklist_cache`;
DROP TABLE IF EXISTS `ost_autotask_time_entry`;
DROP TABLE IF EXISTS `ost_autotask_note_map`;
DROP TABLE IF EXISTS `ost_autotask_sync_history`;
DROP TABLE IF EXISTS `ost_autotask_log`;
DROP TABLE IF EXISTS `ost_autotask_sync_queue`;
DROP TABLE IF EXISTS `ost_autotask_ticket_map`;
DROP TABLE IF EXISTS `ost_autotask_settings`;
DROP TABLE IF EXISTS `ost_autotask_migrations`;

SET FOREIGN_KEY_CHECKS = 1;
