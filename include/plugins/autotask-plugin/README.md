# Autotask Integration for osTicket

Production-ready, **native osTicket plugin** providing multi-tenant two-way
ticket synchronization with **Autotask PSA** via the Autotask REST API.

> Plain PHP 8.x, MySQL and the osTicket Plugin Framework.
> No Composer / Laravel / Symfony / React / Vue / Node dependencies.

**Your clients work in Autotask. Your team works in osTicket. Both sides stay
identical — tickets, conversations, time entries, attachments, status and
priority — and nothing ever reveals that a second system exists.**

---

## ✨ What it does

| Area | Capability |
|---|---|
| **Multi-tenant** | One osTicket serves many client Autotask tenants — separate credentials, filters, defaults and routing per client, fully isolated |
| **Import** | Autotask → osTicket by import filters (queue / company / resource / status / window), with attachments included from the first sync |
| **Export** | osTicket → Autotask: new tickets, replies, internal notes, attachments, status, priority |
| **Department routing** | Map each Autotask **queue** to its own osTicket department (NOC, Help Desk, Cyber security…), with a fallback department |
| **Time entries** | Both directions, exact to 4 decimals (30 min = 0.50 h), billable flag, work type, real technician, shown in a ⏱ panel popup with an osTicket / Autotask source chip |
| **Attribution** | Outbound time is credited to the ticket's **assigned Autotask resource** — the client never sees your internal team; the real worker stays recorded internally |
| **Field parity** | Status and priority mirrored **by name** (auto-created, vocabulary-bridged), Time Type list mirrored from the tenant's work types, Autotask due dates, original timestamps and authors |
| **Ticket panel** | In-ticket Autotask card (organization, contact, SLA, queue, resource, contract…) plus a live **Time Summary** (worked / estimated) |
| **Loop prevention** | ID-based dedupe per tenant and record type — sync a hundred times, get exactly one of each |
| **Reliability** | Retry queue with exponential backoff, rate-limit handling, every hook exception-guarded, structured DB logging with secret redaction |
| **Ops** | Admin dashboard, incremental / full sync, DB migration runner, and a 27-check `selftest.php` pre-deploy gate |

---

## 🚀 Quick start

```bash
# 1. plugin code
cp -r autotask-plugin <osticket>/include/plugins/

# 2. webroot endpoints (REQUIRED — these do not update themselves)
cp <plugin>/scp-panel.php       <osticket>/scp/autotask-panel.php
cp <plugin>/scp-autotask.php    <osticket>/scp/autotask.php
cp <plugin>/scp-docs.php        <osticket>/scp/autotask-docs.php
cp <plugin>/scp-timefields.php  <osticket>/scp/autotask-timefields.php  # only if your
                                # install has no reply-form time fields yet
```

3. **Admin Panel → Manage → Plugins → Add New Plugin** → install & enable
   *Autotask Integration* (tables are created automatically).
4. Register your first client on the **Clients** page (credentials, department,
   import filters, defaults) — saving runs a live connection test.
5. Set up cron — [docs/CRON_SETUP.md](docs/CRON_SETUP.md).
6. Gate on the health check:

```bash
php <plugin>/selftest.php          # 27 checks, exit 0 = good to go
php <plugin>/selftest.php --live=2 # + live API round-trip
```

---

## 📚 Documentation

A friendly illustrated guide ships with the plugin and is linked in the
osTicket footer for staff: **`docs/Autotask-Integration-Guide.pdf`**
(5 short chapters + technical appendices).

Markdown sources:
[Installation](docs/INSTALLATION.md) ·
[Configuration](docs/CONFIGURATION.md) ·
[Cron setup](docs/CRON_SETUP.md) ·
[Upgrades](docs/UPGRADE.md) ·
[Troubleshooting](docs/TROUBLESHOOTING.md) ·
[Security](docs/SECURITY.md) ·
[Autotask API notes](docs/API.md) ·
[Database schema](docs/DATABASE_SCHEMA.md)

---

## 🔐 Security model

- Client API secrets are **encrypted at rest** (osTicket Crypto + `SECRET_SALT`)
  and never re-displayed.
- Every state-changing admin/panel action requires an authenticated staff or
  admin session **plus a valid CSRF token**; panel endpoints also verify
  per-ticket permission.
- All SQL goes through `db_input()`/integer casts; all output is escaped.
- Logs **redact** secrets; API calls verify TLS peer and host.
- `cron.php` and `selftest.php` are CLI-only.

Full review notes: [docs/SECURITY.md](docs/SECURITY.md).

---

## ⚙️ Requirements

- osTicket **1.17 / 1.18**
- PHP **8.0 – 8.3** with `curl` and `json`
- MySQL 5.7+ / MariaDB 10.3+
- An Autotask PSA API user per client tenant (username, secret, integration code)
- The core time-tracking fields on the reply form (`time_spent`, `time_type`,
  `time_bill`) — see [docs/INSTALLATION.md](docs/INSTALLATION.md) §3

---

## ⚠️ Good to know

- **Billable needs a contract.** If an Autotask ticket has no contract,
  Autotask itself forces every time entry to non-billable, whatever the API
  sends. The panel warns you when that's the case.
- **Assignment is not synced by design** — Autotask is the system of record;
  work is scoped by import filters, and any osTicket technician can handle
  any imported ticket.
- **Autotask API limits:** attachments ≤ ~6 MB (larger files arrive as a link
  note), tickets cannot be deleted through the API, and service-ticket hours
  are derived from the start/stop window.

---

## 📝 License

GPLv2 — same license as osTicket.
