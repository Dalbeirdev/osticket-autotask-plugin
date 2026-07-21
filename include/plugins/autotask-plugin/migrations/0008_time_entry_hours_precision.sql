-- ---------------------------------------------------------------------------
-- Autotask Integration — migration 0008: full hour precision on time entries.
--
-- `hours_worked` was DECIMAL(8,2), so Autotask's real values were rounded on
-- storage (0.4167 h -> 0.42 h). Three such entries made the panel total read
-- 1.11 h where Autotask showed 1h 06m (1.1001 h). Autotask keeps 4 decimals;
-- so do we now. Existing rows self-heal on the next sync, because the
-- refresh step rewrites any entry whose hours differ from Autotask.
-- ---------------------------------------------------------------------------

ALTER TABLE `%PREFIX%autotask_time_entry`
  MODIFY COLUMN `hours_worked` DECIMAL(10,4) NOT NULL DEFAULT 0;
