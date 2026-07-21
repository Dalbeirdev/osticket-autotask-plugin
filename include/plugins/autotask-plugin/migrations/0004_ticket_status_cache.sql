-- ---------------------------------------------------------------------------
-- Autotask Integration — migration 0004: cache Autotask status on the map
--
-- Avoids a live getTicket() API call on every ticket-view panel load; the
-- status is kept fresh by inbound sync + outbound status changes, and
-- lazy-populated once for pre-existing mappings.
-- ---------------------------------------------------------------------------

ALTER TABLE `%PREFIX%autotask_ticket_map`
  ADD COLUMN `autotask_status` VARCHAR(16) NULL AFTER `autotask_ticket_number`;
