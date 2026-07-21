# Database Schema

All tables use the osTicket `TABLE_PREFIX` (shown here as `ost_`), InnoDB and
`utf8mb4`. They are created automatically by the migration runner
([`class.installer.php`](../class.installer.php)); the full DDL snapshot lives in
[`sql/install.sql`](../sql/install.sql).

## `ost_autotask_migrations`
Tracks applied migration files for incremental upgrades.

| Column | Type | Notes |
|--------|------|-------|
| id | INT UNSIGNED PK | |
| filename | VARCHAR(191) UNIQUE | e.g. `0001_initial_schema.sql` |
| applied_at | DATETIME | |

## `ost_autotask_settings`
Runtime key/value store (sync cursors, last-run timestamps, cached test result).
Distinct from encrypted credentials, which live in osTicket's PluginConfig.

| Column | Type | Notes |
|--------|------|-------|
| id | INT UNSIGNED PK | |
| skey | VARCHAR(191) UNIQUE | e.g. `inbound_cursor_utc` |
| svalue | LONGTEXT | JSON-encoded |
| updated | DATETIME | |

## `ost_autotask_ticket_map`  *(Ticket Mapping)*
One row per synced ticket — the heart of the integration.

| Column | Type | Notes |
|--------|------|-------|
| id | INT UNSIGNED PK | |
| osticket_ticket_id | INT UNSIGNED **UNIQUE** | |
| autotask_ticket_id | BIGINT UNSIGNED (idx) | NULL until created |
| autotask_ticket_number | VARCHAR(64) | |
| last_sync_time | DATETIME (idx) | |
| last_updated_by | ENUM-ish VARCHAR(16) | `osticket` / `autotask` / `system` |
| sync_direction | ENUM | `to_autotask` / `to_osticket` / `bidirectional` |
| osticket_hash | VARCHAR(64) | change-detection hash |
| autotask_lastactivity | DATETIME | inbound cursor per ticket |
| created / updated | DATETIME | |

## `ost_autotask_sync_queue`  *(Sync / Retry Queue)*
Durable outbound jobs with exponential backoff.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED PK | |
| osticket_ticket_id | INT UNSIGNED (idx) | |
| entity_type | VARCHAR(32) | `ticket`/`note`/`reply`/`status`/`priority` |
| action | VARCHAR(32) | `create`/`update`/`append` |
| payload | LONGTEXT | JSON |
| status | ENUM | `pending`/`processing`/`done`/`failed`/`dead` |
| attempts | SMALLINT UNSIGNED | |
| next_attempt_at | DATETIME | indexed with status |
| last_error | TEXT | |
| dedupe_key | VARCHAR(191) **UNIQUE** | collapses duplicate pending jobs |
| created / updated | DATETIME | |

Index: `idx_status_next (status, next_attempt_at)` powers efficient claiming.

## `ost_autotask_log`  *(Error / API Logs)*

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED PK | |
| level | ENUM | `debug`/`info`/`warning`/`error` (idx) |
| category | VARCHAR(64) (idx) | `api`/`auth`/`outbound`/`inbound`/`queue`/… |
| osticket_ticket_id | INT UNSIGNED | |
| autotask_ticket_id | BIGINT UNSIGNED | |
| message | TEXT | |
| request / response | MEDIUMTEXT | captured at debug, **secrets redacted** |
| created | DATETIME (idx) | |

## `ost_autotask_sync_history`  *(Sync History)*

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED PK | |
| map_id | INT UNSIGNED (idx) | → ticket_map.id |
| osticket_ticket_id / autotask_ticket_id | | |
| direction | ENUM | `to_autotask` / `to_osticket` |
| entity_type | VARCHAR(32) | |
| status | ENUM | `success`/`failed`/`skipped` (idx) |
| summary | VARCHAR(255) | |
| created | DATETIME (idx) | |

## Enterprise foundation tables (migration 0003)

> Relationships to `ost_autotask_ticket_map` are **logical** (indexed columns,
> no enforced FK constraints) to match osTicket's schema style.

### `ost_autotask_note_map`
Two-way dedupe map between osTicket thread entries and Autotask records.
Key columns: `instance_id`, `ticket_map_id`, `osticket_entry_id`,
`autotask_note_id`, `direction`, `note_type`
(`note`/`reply`/`timeentry`/`attachment`).

Since schema **2.1.2** (migration `0007`) the unique keys are
(`instance_id`, `note_type`, `autotask_note_id`) and
(`instance_id`, `note_type`, `osticket_entry_id`) — Autotask notes, time
entries and attachments are separate id-spaces, per tenant; a global key
silently swallowed dedupe markers on id collisions.

### `ost_autotask_time_entry`
Time entries created/synced for a mapped ticket. Maps to Autotask `TimeEntries`:
`hours_worked`, `start/end_datetime`, `billing_code_id` (work type), `role_id`,
`billable`, `summary_notes`, `internal_notes`, `status`, `created_by` (staff id).

### `ost_autotask_picklist_cache`
Cached Autotask picklist values for performance + pre-submit validation.
Unique on (`entity`, `field`, `value`); stores `label`, `is_active`, `is_default`.

### `ost_autotask_audit`
Privileged-action audit trail / change tracking: `staff_id`, `action`
(e.g. `time.add`, `ticket.close`), `osticket/autotask_ticket_id`, `detail` (JSON), `ip`.

### `ost_autotask_import_filter`
Saved import filter sets (admin checkboxes). `criteria` JSON holds statuses,
queues, companies, resources, unassigned flag, date range, include-closed.

### `ost_autotask_conflict`
Field-level conflicts awaiting resolution: `field`, `osticket_value`,
`autotask_value`, `resolution` (`unresolved`/`autotask_wins`/`osticket_wins`/`manual`).

## Entity relationships

```
ost_ticket (core) 1───1 ost_autotask_ticket_map ──┬─< ost_autotask_sync_history
                                                   └─ (logical) Autotask Ticket
ost_autotask_sync_queue  ── outbound jobs keyed by osticket_ticket_id
ost_autotask_log         ── cross-cutting audit/diagnostics
ost_autotask_settings    ── runtime cursors & cached state
```
