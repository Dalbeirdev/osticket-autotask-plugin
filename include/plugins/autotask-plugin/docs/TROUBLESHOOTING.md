# Troubleshooting Guide

> First stop: the **Dashboard** → *Recent Logs* (filter by **Errors**) and
> *Failed & Dead Jobs*. Set **Log Level = debug** temporarily to capture
> redacted request/response payloads.

## Connection / authentication

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Save blocked: *"Authentication failed"* | Wrong username/secret/integration code | Re-copy credentials; ensure the API user is enabled for Web Services. |
| Save blocked: *"Zone detection failed"* | Username typo, or outbound HTTPS blocked | Verify username; set the **Zone URL** manually; allow outbound 443 to `*.autotask.net`. |
| *"PHP cURL extension is required"* | `curl` not installed | Install/enable `php-curl` and restart PHP-FPM/Apache. |

## Tickets not appearing in Autotask

1. Is **Enable Integration** ticked and the connection green?
2. Is cron running? (Dashboard *Last Inbound Sync* should advance.) See
   [CRON_SETUP.md](CRON_SETUP.md).
3. Check *Failed & Dead Jobs*. A common cause is **"No Autotask company
   resolved"** — set a valid **Default Company ID**.
4. Click **Retry All Failed** after fixing the root cause.

## Updates not flowing

- **Replies/notes**: confirm the ticket has a mapping row (it must have been
  created *after* the plugin was enabled). Pre-existing tickets are not
  back-filled automatically — run a **Full Sync** or recreate the mapping.
- **Status/priority**: these are queued via the `model.updated` signal. If your
  osTicket build doesn't emit dirty data, the plugin conservatively queues a
  status/priority refresh on any ticket update.

## Inbound (Autotask → osTicket) issues

| Symptom | Cause | Fix |
|---------|-------|-----|
| Nothing imported | Auto-import disabled or filters exclude the ticket | Enable **Auto-import**; check queue/company/resource filters and the import window. |
| Duplicate notes/attachments repeating each tick | Pre-2.1.2 schema (global dedupe key) | Upgrade — migration `0007` re-scopes dedupe per instance + record type. |
| Wrong open/closed mapping | Custom Status Map lines overriding name parity | Clear the **Status Map** (name parity mirrors the tenant's own status names); `Complete` maps to the closed state. |
| Time entry edited in Autotask, osTicket thread unchanged | By design | The ⏱ popup/totals reflect the edit; the thread entry is immutable (billing history). |

## Field-parity issues

| Symptom | Cause | Fix |
|---------|-------|-----|
| Imported entries all timestamped at import time / hours skewed | Date writes bypassing the DB session timezone | Fixed in current code (`FROM_UNIXTIME`); older imports keep their stamps. |
| Time entry arrives non-billable although Billable was ticked | `field_billable` config doesn't match the form (`time_bill`) | Current default is `time_bill`; check the global time-field names. |
| Minutes arrive as hours | `time_spent_unit` misread (stored as JSON by choice fields) | Fixed in current code; ensure unit is `minutes`. |
| Panel/dropdown changes don't appear | Stale `scp/` endpoint copies | Re-copy per INSTALLATION.md §2b, then Ctrl+F5. |
| osTicket-side entries attributed to the default resource in Autotask | Ticket unassigned in Autotask | Assign the ticket in Autotask — entries follow the assigned resource. |

## Performance

- Lower **Batch Size** if a single run times out.
- Increase the **Scheduled Sync Interval** on very large instances.
- Ensure the DB indexes from the migration exist (see
  [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md)).

## Rate limiting (HTTP 429)

The client performs a short inline retry, then defers to the queue with
exponential backoff. Sustained 429s mean you're exceeding Autotask's API
threshold — raise the interval and/or lower batch size.

## "It crashed osTicket"

It shouldn't — every signal handler is wrapped in a `try/catch`. If you see a
fatal, capture it from `error_log` (prefixed `[Autotask]`) and the plugin logs,
then disable **Enable Integration** while you investigate.

## Resetting the inbound cursor

The inbound delta cursor is stored in `ost_autotask_settings`
(`skey = inbound_cursor_utc`). A **Full Sync** resets it to the chosen lookback
window.
