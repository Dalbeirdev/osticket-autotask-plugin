# Deploying to autotask.foxeraclub.com (Hostinger hPanel)

Target: the live osTicket at **https://autotask.foxeraclub.com/** (Hostinger,
PHP 8.3, LiteSpeed).

> **Never overwritten by any method below:** `include/ost-config.php` — it holds
> the database credentials and `SECRET_SALT` (which decrypts the stored Autotask
> API secrets). It is in `.gitignore` and must stay on the server untouched.
> **Back up the database before every deploy.**

---

## Option A — SSH + git pull (best: one command per deploy)

Requires SSH (hPanel → **Advanced → SSH Access**; included on Premium/Business
plans). One-time setup, then every future deploy is a single `git pull`.

```bash
ssh -p <port> <user>@<host>                       # credentials from hPanel
cd ~/domains/autotask.foxeraclub.com/public_html  # your osTicket root

# 1. SAFETY FIRST — snapshot files + database
mysqldump -u <dbuser> -p <dbname> > ~/backup-$(date +%F).sql
tar czf ~/site-$(date +%F).tar.gz .

# 2. turn the live folder into a working copy of the repo (one time)
git init
git remote add origin https://github.com/Dalbeirdev/osticket-autotask-plugin.git
git fetch origin autotask-integration-2026-07-21
git checkout -f -t origin/autotask-integration-2026-07-21
```

`checkout -f` overwrites **tracked** files with the repo version. `ost-config.php`
is untracked/ignored, so it survives.

**Every deploy after that:**

```bash
cd ~/domains/autotask.foxeraclub.com/public_html
git pull origin autotask-integration-2026-07-21
```

---

## Option B — hPanel Git tool (no SSH needed)

hPanel → **Websites → autotask.foxeraclub.com → Advanced → GIT**

1. **Repository:** `https://github.com/Dalbeirdev/osticket-autotask-plugin.git`
2. **Branch:** `autotask-integration-2026-07-21`
3. **Directory:** the subdomain's `public_html`

⚠️ Hostinger's Git tool wants an **empty** target directory for the first clone.
Since the site already exists, do this instead:

- Clone into a **new** folder, e.g. `autotask-app`
- Copy the live `include/ost-config.php` into the new folder's `include/`
- hPanel → **Domains → Subdomains** → point `autotask.foxeraclub.com` at the new
  folder as its document root
- Keep the old folder for a day as a rollback

Then use **Deploy** in the Git panel (or its auto-deployment webhook, which you
can add to GitHub under *Settings → Webhooks*) for every future release.

---

## Option C — File Manager / FTP (quick one-off)

Upload only what changed:

| From the repo | To the server |
|---|---|
| `include/plugins/autotask-plugin/` | same path (whole folder) |
| `scp/autotask-panel.php`, `scp/autotask.php`, `scp/autotask-docs.php`, `scp/autotask-timefields.php` | `scp/` |
| `include/class.thread.php`, `include/class.ticket.php` | `include/` |
| `include/staff/ticket-view.inc.php`, `include/staff/footer.inc.php` | `include/staff/` |

Never upload `include/ost-config.php`.

---

## First-time server setup (once)

1. **Core time-tracking schema** — run once in hPanel → **Databases → phpMyAdmin**:
   `include/plugins/autotask-plugin/deploy/timebill-schema.sql`
2. **Plugin install** — SCP → Admin Panel → Manage → Plugins → *Autotask
   Integration* → Install → Enable. Tables are created automatically.
3. **Register the client** on the Clients page (API username / secret /
   integration code / department / import filters).
   *If the database was restored from another server, either copy that server's
   `SECRET_SALT` into `ost-config.php` or simply re-enter each API secret once.*
4. **Cron** — hPanel → **Advanced → Cron Jobs**, every 5 minutes:
   ```
   /usr/bin/php ~/domains/autotask.foxeraclub.com/public_html/include/plugins/autotask-plugin/cron.php
   ```

## After every deploy

1. Migrations apply themselves on the first page load (schema version check).
2. Health check (SSH): `php include/plugins/autotask-plugin/selftest.php` — expect
   **27 PASS, exit 0**.
3. Hard-refresh a ticket page (**Ctrl+F5**); if LiteSpeed caches, purge it in
   hPanel → *Performance*.
4. Smoke test: open a synced ticket → blue panel + Autotask cards render, ⏱ popup
   totals match Autotask, footer PDF link opens.

## Rollback

```bash
git log --oneline -5
git checkout -f <previous-commit>
mysql -u <dbuser> -p <dbname> < ~/backup-<date>.sql   # only if a migration must go back
```
