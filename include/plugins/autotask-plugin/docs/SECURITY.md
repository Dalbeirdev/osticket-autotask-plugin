# Security Notes

Result of the pre-release security review (2026-07-14). Verdict: **PASS** —
no critical or high findings. This page records the security posture and the
operational hardening expectations.

## Authentication & authorization

- Ticket-panel endpoints (`scp/autotask-panel.php`, `scp/autotask.php`)
  require an authenticated **staff** session (`staff.inc.php`) and verify
  **per-ticket access** (`Ticket::checkStaffPerm`) before returning context
  or accepting actions.
- The admin Dashboard / Clients pages require **administrator** privilege
  (`$thisstaff->isAdmin()`), enforced before any rendering or POST handling.
- `cron.php` and `selftest.php` are **CLI-only** (`PHP_SAPI` guard).

## CSRF

Every state-changing action validates the osTicket CSRF token:
admin POST handlers (`clients`, `dashboard`) and all panel actions
(`log_time`, `push_ticket`, `set_status`, `complete`). Read-only `context`
is GET/no-token by design.

## Injection

- **SQL**: all dynamic values pass through `db_input()` or explicit `(int)`
  casts; review found no raw request variables reaching `db_query()`.
- **XSS**: admin templates escape through a single `$e()` helper
  (`htmlspecialchars`, `ENT_QUOTES`); panel JSON is emitted with
  `Content-Type: application/json` (never inline in HTML); the panel JS
  escapes all interpolated values through `esc()`.
- No `eval` / `exec` / dynamic includes anywhere in the plugin.
- Inbound Autotask content (notes, descriptions) is stored through osTicket's
  thread API and sanitized by core on render.

## Secrets

- Client API secrets are **encrypted at rest** with osTicket's Crypto keyed
  by `SECRET_SALT` (subkeyed per instance store). A base64-marked fallback
  exists only for the rare case Crypto is unavailable — ensure `SECRET_SALT`
  is set (standard in every osTicket install) so the real cipher is used.
- Secrets are **never re-echoed** into forms (password field, empty value).
- The logger **redacts** secret-shaped values from all persisted payloads.

## Transport

All Autotask API calls use cURL with `CURLOPT_SSL_VERIFYPEER = true` and
`CURLOPT_SSL_VERIFYHOST = 2`. Zone URLs come from Autotask zone detection —
never from request input.

## Files

- Outbound uploads capped at ~6 MB (Autotask API limit), read through the
  osTicket file API.
- Inbound files are created through `AttachmentFile::create` (osTicket's
  content-addressed store); oversize/withheld binaries degrade to a link note.

## Operational expectations

- Run `selftest.php` after every deploy/upgrade (exit 0 gate).
- Keep `logs/` non-web-readable (default perms from INSTALLATION.md §2).
- The webroot endpoints in `scp/` must only ever be the shipped copies
  (see INSTALLATION.md §2b).
- Database credentials/`SECRET_SALT` hygiene is inherited from the osTicket
  installation itself.
