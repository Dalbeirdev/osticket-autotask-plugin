# How it works — the story of one ticket

Meet the cast we'll follow through this whole guide:

- **Magnolia Dental Services** — your client. They use **Autotask**.
- **Sarah** — Magnolia's front-desk manager. She reports problems.
- **Basit** — the technician *assigned* to Magnolia's tickets in Autotask.
- **Dalbeir** — one of *your* technicians, working in **osTicket**.

## Monday, 9:02 — Sarah has a problem

Patients in the waiting area keep losing Wi-Fi. Sarah creates a ticket in
Autotask, like she always does:

```
Title:     Wi-Fi keeps dropping in the waiting area
Priority:  Medium        Status: New        Assigned to: Basit
Attached:  wifi-complaints.png
```

## 9:03 — the ticket appears in osTicket

Within one sync cycle, the ticket lands in your osTicket helpdesk — routed
straight to the department you chose for Magnolia. Everything came along:

- Subject, description, and Sarah as the customer (her real email)
- Priority **Medium** arrives as **Normal** (the plugin translates the
  vocabulary: Medium→Normal, Critical→Emergency)
- Status **New**, the attached screenshot as a real downloadable file
- A blue **TECHPIO | Autotask** panel on the ticket showing the Autotask
  number, the client, and an **Open in Autotask** button

## 9:40 — Dalbeir fixes it and logs his time

Dalbeir remotes in, finds the neighbouring café's Wi-Fi on the same channel,
moves the access point to channel 11. Then he replies **in osTicket, on the
normal reply form**:

```
Reply:      "I moved your guest network to channel 11 — the café next
             door was interfering. Please watch it today."
Time Spent: 30   (Minutes)
Time Type:  Remote Support
Billable:   ✓
```

He clicks Post Reply. Done. Nothing else to fill in, no second system to
open.

## 9:41 — what Magnolia sees in Autotask

- Sarah gets the reply — a completely normal, human reply.
- A time entry appears: **0.50 h, Billable, Remote Support** — attributed to
  **Basit**, the ticket's assigned technician. Magnolia sees *their*
  technician handling *their* ticket. Your internal team stays your business.
- Meanwhile in osTicket, the ⏱ popup honestly records that **Dalbeir** did
  the work, marked with an **osTicket** source chip.

## Tuesday — closing the loop

Basit adds his own note and a 15-minute follow-up entry *from the Autotask
side* — both appear in the osTicket thread within a minute, under Basit's
name, at the real time he wrote them. Sarah confirms everything works, and
the ticket is set to **Complete** in Autotask. The osTicket copy closes
itself.

**Total time on the ticket: 0.75 h (0.50 billable) — identical on both
sides, ready for invoicing.**

## The rules of the game (worth remembering)

1. **Autotask is the front desk.** Clients create and assign tickets there,
   using their own queues and technicians.
2. **osTicket is the workshop.** Any of your technicians can pick up any
   imported ticket and work it.
3. **Everything a client should see syncs out; nothing private leaks.**
   Internal notes, system notices and your team's names stay inside.
4. **Nothing ever duplicates.** Every reply, time entry and file is tracked
   by ID — you can sync a hundred times and get exactly one copy.
