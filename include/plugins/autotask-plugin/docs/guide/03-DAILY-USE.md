# Your day with a ticket (for technicians)

Everything in this chapter happens on the normal osTicket ticket page — you
never need to open Autotask unless you want to.

## The blue panel

Every synced ticket has a blue **TECHPIO | Autotask** strip above the reply
button. It tells you at a glance:

| You see | It means |
|---|---|
| `Autotask #T20260714.0085` | The client-side ticket number |
| `⏱ 0.75 h` | Total time logged so far — **click it** for the full list |
| 🏢 Magnolia Dental Services | Which client this belongs to |
| 👤 ProApps Techpio (email) | The customer contact |
| `AUTOTASK STATUS: New` | Live status on the client side |
| **Open in Autotask ↗** | Jump to the same ticket in Autotask |

## Replying

Type your reply exactly as you always have. It arrives in Autotask as a
normal public note the customer can read. **Internal notes stay internal**
on both sides — the customer never sees them.

## Logging time (the part that matters for billing)

Fill the three little fields under your reply before you post:

```
Time Spent:  45          ← minutes
Time Type:   Remote Support
Billable:    ✓
```

Minutes convert to decimal hours automatically — always exact:

| You enter (minutes) | Autotask shows |
|---|---|
| 10 | 0.17 h |
| 15 | 0.25 h |
| 30 | 0.50 h |
| 45 | 0.75 h |
| 60 | 1.00 h |
| 90 | 1.50 h |

What happens automatically:

- Autotask gets a **0.75 h billable Remote Support** entry carrying your
  reply text — attributed to the ticket's assigned technician, so the
  client sees a familiar name.
- The **Time Type list only shows types that exist for this client** — and
  it pre-selects the work type the ticket was created with. Usually you
  don't have to touch it.
- Your own name stays on the entry inside osTicket (⏱ popup, *Technician*
  column), so internal reports know who really did the work.

> 💡 Logging a quick phone call? `Time Spent: 10`, type stays as suggested,
> untick Billable if it's goodwill. Ten seconds, perfect books.

## The ⏱ time popup

Click the hours in the blue panel to see every entry from **both** systems:

| Date | Technician | Hours | Billable | Source | Status |
|---|---|---|---|---|---|
| 2026-07-13 21:41 | Dalbeir Singh | 0.75 | ✓ | osTicket | synced (AT #63) |
| 2026-07-13 20:37 | Basit Lone | 0.50 | ✓ | Autotask | synced (AT #43) |

Orange chip = logged here, blue chip = logged in Autotask. The total at the
bottom always matches Autotask's Time Summary.

## Files

Drag a file onto your reply — it appears in Autotask's attachment list as a
real file (up to ~6 MB). Files the client attaches in Autotask show up in
the thread as downloadable attachments, under the uploader's name.

## Statuses

The **Ticket Status** dropdown shows the client's own Autotask statuses —
*New, In Progress, Waiting Customer, On Hold, Complete…* Pick one and both
sides update. Two things to know:

- Setting **Complete** (or closing the ticket) completes it in Autotask,
  including your last reply as the resolution.
- If the ticket was completed **from Autotask**, that wins — the client's
  system is the system of record.

> ⚠️ **Close with time.** If you close a ticket without any time logged,
> the closure is flagged in the audit log — the yellow warning under the
> reply form reminds you.
