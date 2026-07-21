# Installation Guide

## 1. Prerequisites

- osTicket 1.17 or 1.18 installed and working — **with the core time-tracking
  mod** (reply-form fields `time_spent` / `time_type` / `time_bill`; see §3).
- PHP 8.0–8.3 with `curl` and `json` enabled.
- Database user able to `CREATE TABLE` / `ALTER TABLE` (for migrations).
- An Autotask API user with **Web Services API** access, per client tenant.
- `SECRET_SALT` present in `ost-config.php` (standard) — client API secrets
  are encrypted with it.

## 2. Deploy the plugin files

Copy the entire plugin folder into your helpdesk installation:

```
<osticket-root>/include/plugins/<plugin-dir>/
```

Set ownership/permissions so the web server can read all files and write to
the `logs/` directory:

```bash
chown -R www-data:www-data include/plugins/<plugin-dir>
find include/plugins/<plugin-dir> -type d -exec chmod 755 {} \;
find include/plugins/<plugin-dir> -type f -exec chmod 644 {} \;
```

### 2b. Webroot endpoints (REQUIRED — easy to miss)

Some files are **served from the osTicket `scp/` webroot** and must be copied
there. Editing only the plugin-folder copies has **no effect** on these:

```bash
cp include/plugins/<plugin-dir>/scp-panel.php        scp/autotask-panel.php
cp include/plugins/<plugin-dir>/scp-autotask.php     scp/autotask.php
# Only on installs WITHOUT their own reply-form time fields:
cp include/plugins/<plugin-dir>/scp-timefields.php   scp/autotask-timefields.php
cp include/plugins/<plugin-dir>/scp-docs.php         scp/autotask-docs.php
```

Re-copy these whenever the plugin is updated.

## 3. Core mod pieces

The time-tracking integration relies on the core mod that adds Time Spent /
Time Type / Billable fields to the staff reply and note forms and stores them
on `thread_entry` (`deploy/timebill-schema.sql` for the columns + the
Time Type dynamic list).

One template detail is required for Time-Type parity: the two dropdown loops
in `include/staff/ticket-view.inc.php` must use `$list->getItems()` (enabled
items only), **not** `getAllItems()` — the integration disables non-Autotask
time types and disabled items must not render.

## 4. Install via the Admin Panel

1. Log in to the SCP as an **Administrator**.
2. **Admin Panel → Manage → Plugins → Add New Plugin** → install
   **Autotask Integration**, then **Enable** it.

On first bootstrap the plugin runs its migrations automatically and creates /
upgrades its tables (see [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) and
[UPGRADE.md](UPGRADE.md)). No manual SQL is required.

## 5. Register clients

Open **the Clients page** and register each client Autotask tenant
(credentials, department, import filters, defaults, options) — full field
guide in [CONFIGURATION.md](CONFIGURATION.md). Saving validates the
credentials live and mirrors the tenant's statuses and work types into
osTicket.

## 6. Set up the scheduler

Inbound sync, the retry queue and housekeeping run on a schedule — configure
per [CRON_SETUP.md](CRON_SETUP.md).

## 7. Verify — the pre-flight gate

Run the self-test harness (CLI) and require a clean pass **before** going
live, and after every upgrade:

```bash
php include/plugins/<plugin-dir>/selftest.php            # L1-L3 checks
php include/plugins/<plugin-dir>/selftest.php --live=2   # + live round-trip
```

Exit code 0 = all green. Then open the Dashboard and confirm the green
**Connection: OK** badge per client.

## 8. Uninstall

**Admin Panel → Manage → Plugins → Autotask Integration → Uninstall.**

Plugin **data is preserved** by default (mappings, logs). To also drop the
tables, first enable **"Drop tables on uninstall"** in the config, then
uninstall. Manual teardown: [`sql/uninstall.sql`](../sql/uninstall.sql).
Remember to remove the `scp/` endpoint copies from §2b.

## 9. Bundled documentation (PDF)

The full guide ships as `docs/Autotask-Integration-Guide.pdf` and is served to
authenticated staff via `scp/autotask-docs.php` (copy per §2b):

```bash
cp include/plugins/<plugin-dir>/scp-docs.php scp/autotask-docs.php
```

A footer link ("Autotask Integration Guide (PDF)") is added in
`include/staff/footer.inc.php` next to the copyright line.
