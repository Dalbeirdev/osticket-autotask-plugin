# Read this first — Agent Access & Roles

> **The single most common "it's not working" is really an access problem.**
> A ticket that "didn't sync" almost always *did* sync — it just landed in a
> department the agent can't see. Five minutes here saves that confusion for
> everyone.

## The two rules that govern what an agent can do

osTicket decides this **per department**, not globally — even for
administrators:

1. **See a ticket** — the agent must have **access to that ticket's
   department**. No access = the ticket is invisible in their queues and
   search, as if it never arrived.
2. **Close a ticket** — the agent's **role in that department** must include
   the **Close Tickets** permission. Without it, *Complete / Closed* simply
   don't appear in the status dropdown.

## Why this matters with Autotask

The integration routes tickets to departments by Autotask **queue** (for
example *Level II Support → Help Desk*, *Monitoring → NOC*). So tickets will
land in **NOC, Help Desk, Cyber security** and any other routing department —
and an agent who only has access to *Support / Sales* will never see them.

## Do this once — the clean way (recommended)

Give every routing department a default role, so **new agents inherit access
automatically** and no ticket is ever invisible:

**Admin Panel → Agents (top menu) → Departments** → open each department
(*Support, Sales, Maintenance, Help Desk, NOC, Cyber security, …*) → set its
**Access / default role** to one that includes **Close Tickets** (for example
*All Access*) → **Save**.

## Per-agent access (when you add someone)

**Admin Panel → Agents (top menu)** → open the agent → **Access** tab → **Add**
each department they should work → pick a role with **Close Tickets** → **Save**.
Set their **Primary Department** too.

## A "main admin" who must see everything

An administrator still needs department access to see tickets in the queues.
Add **all** departments to their Access list (each with *All Access*), or make
sure every department has a default role they inherit. Then they see and can
close every ticket.

## Checklist — every time you add an agent or a role

- [ ] Agent has **access to every department** they must work (including the
      Autotask routing ones: NOC, Help Desk, Cyber security…).
- [ ] Their role in those departments includes **Close Tickets** (else they
      can't complete a ticket).
- [ ] A **Primary Department** is set on the agent.
- [ ] New routing department created later? Give it a **default role** so
      existing agents keep full coverage.

> 💡 Rule of thumb: **if a synced ticket "can't be found" or "can't be
> closed", check department access first** — it's the answer 9 times out of 10.
