-- ---------------------------------------------------------------------------
-- Autotask Integration — migration 0006: per-instance picklist uniqueness
--
-- Different client Autotasks have different picklists but can share the same
-- value ids (e.g. status "5"). The cache uniqueness must therefore include the
-- instance, or one client's refresh would overwrite another's labels.
-- ---------------------------------------------------------------------------

ALTER TABLE `%PREFIX%autotask_picklist_cache`
  DROP KEY `uq_efv`,
  ADD UNIQUE KEY `uq_iefv` (`instance_id`, `entity`, `field`, `value`);
