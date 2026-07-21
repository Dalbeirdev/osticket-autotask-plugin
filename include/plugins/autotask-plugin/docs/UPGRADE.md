# Upgrade Guide

The plugin upgrades cleanly in place. Schema changes are applied automatically
by the **incremental migration runner**.

## How upgrades work

- The shipped code declares `Installer::SCHEMA_VERSION`.
- On bootstrap the plugin compares it with the `schema_version` stored in the
  plugin config. If they differ, it scans `migrations/*.sql` and applies any
  files not yet recorded in `ost_autotask_migrations`, then records the new
  version.
- Already-applied migrations are skipped, so re-running is safe (idempotent).

## Standard upgrade procedure

1. **Back up your database** (always).
2. Replace the plugin folder under `include/plugins/` with the new version.
   Keep your existing data tables — do **not** drop them.
3. **Re-copy the webroot endpoints** (they do NOT update themselves):
   `scp-panel.php → scp/autotask-panel.php`, `scp-autotask.php → scp/autotask.php`
   (see INSTALLATION.md §2b).
4. Load any SCP page (or run cron). The migration runner executes pending
   migrations on the next bootstrap.
5. Run `php <plugin-dir>/selftest.php` — require exit 0.
6. Open the **Dashboard**, confirm the green connection badge per client, and
   run one **Incremental Sync** to confirm health.

## Migration highlights

| Schema | Migration | What / why |
|---|---|---|
| 2.x | `0005_multi_tenant.sql` | `autotask_instance` table; `instance_id` on all data tables. |
| 2.1.x | `0006_picklist_per_instance.sql` | Picklist cache scoped per client. |
| 2.1.2 | `0007_note_map_unique_scope.sql` | **Important fix**: dedupe uniqueness re-scoped to (instance, record type, id). Autotask notes / time entries / attachments are separate id-spaces per tenant; the old global key silently dropped dedupe markers on collisions, causing repeated attachment re-imports. |

## Adding a new migration (for maintainers)

1. Create `migrations/000N_description.sql` (use `%PREFIX%` for the table
   prefix; one statement per `;`, comment lines start with `--`).
2. Bump `Installer::SCHEMA_VERSION` in `class.installer.php`.
3. Ship. The new file applies automatically; older installs catch up
   incrementally.

> Write migrations to be **additive and idempotent** where possible
> (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN` guarded by checks) so partial
> failures can be re-run.

## Rolling back

There is no automatic down-migration. To roll back:

1. Restore the previous plugin folder.
2. Restore the database from your pre-upgrade backup if a migration must be
   reverted.

## Version compatibility

| Plugin | osTicket | PHP |
|--------|----------|-----|
| 1.0.x | 1.17 – 1.18 | 8.0 – 8.3 |

After a **major osTicket upgrade**, re-test connection and run a manual
incremental sync, since core signal/ORM behaviour can change between releases.
