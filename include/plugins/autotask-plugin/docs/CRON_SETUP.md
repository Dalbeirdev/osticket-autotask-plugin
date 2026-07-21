# Cron Setup

The plugin's two-way (inbound) sync, retry queue and housekeeping run on a
schedule. There are **two supported ways** to drive it. Use one.

The inbound pull is throttled to the **Scheduled Sync Interval** you chose in
config (default 5 min); the outbound retry queue drains on every tick.

---

## Option A — osTicket's built-in cron (recommended)

osTicket fires a `cron` signal which this plugin handles automatically. You just
need osTicket's own cron to run frequently.

### A1. System crontab (every 5 minutes)

```cron
*/5 * * * * php /var/www/osticket/api/cron.php
```

### A2. HTTP cron (if you can't use CLI)

```cron
*/5 * * * * curl -s "https://<helpdesk>/api/cron.php" >/dev/null
```

> The interval here only needs to be ≤ your configured sync interval. Running
> osTicket cron every 5 minutes is the usual recommendation.

---

## Option B — Dedicated plugin cron runner

Point system cron straight at the plugin's [`cron.php`](../cron.php). Useful if
you want the integration to run on its own cadence independent of osTicket cron.

```cron
# Incremental sync every 5 minutes
*/5 * * * * php /var/www/osticket/include/plugins/autotask-plugin/cron.php

# Optional: a nightly full sync (30-day lookback) at 02:15
15 2 * * * php /var/www/osticket/include/plugins/autotask-plugin/cron.php full
```

### Web invocation (guarded)

If you must trigger it over HTTP, set a shared secret and pass it:

```bash
export AUTOTASK_CRON_SECRET="a-long-random-string"   # in the PHP environment
```

```cron
*/5 * * * * curl -s "https://<helpdesk>/include/plugins/autotask-plugin/cron.php?key=a-long-random-string" >/dev/null
```

Requests without the correct `key` are rejected with HTTP 403.

---

## Windows Task Scheduler

```powershell
# Every 5 minutes, incremental sync
$action  = New-ScheduledTaskAction -Execute "C:\php\php.exe" `
           -Argument "C:\inetpub\osticket\include\plugins\autotask-plugin\cron.php"
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
           -RepetitionInterval (New-TimeSpan -Minutes 5)
Register-ScheduledTask -TaskName "Autotask Sync" -Action $action -Trigger $trigger
```

---

## Manual & full sync

From the **Dashboard** you can run **Incremental** or **Full** sync on demand —
no cron required for one-off runs.

## Verifying cron

- Dashboard → **Last Inbound Sync** timestamp should advance each interval.
- Logs (category `scheduler`) record each incremental run with counts.
- Stuck `processing` jobs are auto-reaped after 10 minutes.
