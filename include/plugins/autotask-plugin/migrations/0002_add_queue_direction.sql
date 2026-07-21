-- ---------------------------------------------------------------------------
-- Autotask Integration — migration 0002: add direction to the sync queue
--
-- Enables the queue to carry BOTH outbound (osTicket -> Autotask) and inbound
-- (Autotask -> osTicket) jobs, so every synchronization operation is queued,
-- retried and backed off uniformly.
-- ---------------------------------------------------------------------------

ALTER TABLE `%PREFIX%autotask_sync_queue`
  ADD COLUMN `direction` ENUM('to_autotask','to_osticket')
  NOT NULL DEFAULT 'to_autotask' AFTER `entity_type`;
