# Setting it up (for the administrator)

A fresh install takes about fifteen minutes. Here's the whole journey.

## Step 1 — copy the plugin in

Put the plugin folder under `include/plugins/` of your osTicket, and copy
the four small "endpoint" files into `scp/` (they power the blue panel, the
time fields and the docs link):

```bash
cp include/plugins/<plugin-dir>/scp-panel.php       scp/autotask-panel.php
cp include/plugins/<plugin-dir>/scp-autotask.php    scp/autotask.php
cp include/plugins/<plugin-dir>/scp-timefields.php  scp/autotask-timefields.php
cp include/plugins/<plugin-dir>/scp-docs.php        scp/autotask-docs.php
```

> ⚠️ **Remember this for updates too.** These copies don't update
> themselves — re-copy them whenever you update the plugin. (If a change
> "doesn't show up", a stale copy here is the first thing to check.)

## Step 2 — install and enable

**Admin Panel → Manage → Plugins → Add New Plugin → Autotask Integration →
Install**, then enable it. The plugin creates and upgrades its own database
tables automatically — no SQL work.

## Step 3 — register your first client

Open the **Clients** page and click **Add Client**. You need four things
from the client's Autotask admin:

```
Name:              Magnolia Dental Services
Code:              MAGNOLIA          ← the little badge on the blue panel
API Username:      api-user@magnolia...
API Secret:        ●●●●●●●●          ← encrypted, never shown again
Integration Code:  XXXXXXXX
Department:        Support           ← where their tickets land in osTicket
```

Saving runs a **live connection test** — bad credentials simply won't save.
On success the plugin also mirrors the client's statuses and work types into
osTicket, so your dropdowns instantly speak their language.

### Optional: route queues to different departments

By default every imported ticket lands in the client's one department. If
your teams are split — NOC, Help Desk, Cyber security — click **+ Add queue
rule** under *Department routing by Autotask Queue* and map each Autotask
queue to its own osTicket department:

```
29682833 (Level I Support)   →  Support / NOC
29682969 (Level II Support)  →  Support / Help Desk
(everything else)            →  the Fallback Department
```

Queue IDs are right there in the ID Reference panel. Rules apply when the
ticket is imported; add or remove rules any time with + and ✕.

> 💡 **Looking for a "map technicians" step? There isn't one — on purpose.**
> Tickets are scoped by import filters (Step 4), any of your technicians can
> work them, and time is credited to the client's assigned resource. Nothing
> to map, nothing to maintain. See Chapter 5 for the full reasoning.

## Step 4 — choose what to import

Import filters decide which Autotask tickets become osTicket tickets.
Example: *"only the Service Desk queue, only open tickets, last 7 days"*:

```
Queue IDs:        29682833
Import open:      ✓        Import closed:  ✗
Import window:    7 days
```

The **ID Reference** panel right on the form lists the client's live queue,
resource and company IDs — no hunting through Autotask.

## Step 5 — set the defaults

When osTicket pushes a new ticket *to* Autotask, these fill the blanks:
default company, queue, priority, work type, and the resource + role used
when a ticket has no assignee yet. Pick them from the same ID Reference
panel.

## Step 6 — turn on the scheduler and verify

Set up the cron job (Appendix A has the one-liner), then run the built-in
health check:

```bash
php include/plugins/<plugin-dir>/selftest.php
```

**27 green checks and exit code 0** = you're live. Create a test ticket in
osTicket, watch it appear in Autotask a minute later, and take the rest of
the day off.

## The options, translated

| Option | Plain meaning |
|---|---|
| Two-way sync | osTicket changes flow back to Autotask. Leave on. |
| Auto-import | Autotask tickets flow in on schedule. Leave on. |
| Inbound notes | Replies/notes/time from Autotask appear in the thread. Leave on. |
| Sync attachments | Files travel both ways. Leave on. |
| Import system notes | Autotask's robot notes ("ticket forwarded…"). Leave **off** — it's noise. |
| Require time before close | Nags technicians who close without logging time. Recommended. |
| Close osTicket on Complete | Client completes it → your copy closes too. Recommended. |
| Status Map | Leave **empty** — statuses match by name automatically. |
