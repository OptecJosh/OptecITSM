# Zoho Desk → freeitsm

Turns a Zoho **Cases** CSV export into files the platform's own Mass import can
load, plus a report of what to create first.

```bash
python zoho_migrate.py profile   "Cases_export.csv"
python zoho_migrate.py transform "Cases_export.csv" --out ./freeitsm-import
```

## Why it produces CSVs instead of writing to the database

System → Mass import already resolves every lookup **by name**, previews before
it writes, reports problems per row, and runs in one transaction. Feeding it
means the migration inherits all of that: a wrong mapping costs you a preview,
not a restore. And it is re-runnable — each ticket carries its Zoho id in
`external_ref`, so a second import **updates** the tickets it created rather
than making 16,000 more.

The loop is: transform → preview → fix `mapping.json` → transform → import.

## The three steps

**1. Profile** tells you what is in the export: fill rates for all 78 columns,
every distinct value in the columns that become platform records, and the ticket
owners with the names recovered for them.

**2. Edit `mapping.json`.** Left side is exactly what Zoho exports, right side
the **name** as it exists in freeitsm. `__default__` catches anything unlisted;
an empty value leaves the field unset.

The one part that cannot be automated is **agents**: Zoho stores the ticket owner
as a numeric id. The tool recovers each id's surname from the same file's
`Created By` / `Modified By` columns, so the job is matching ~15 surnames to your
staff rather than decoding opaque ids. Leave one blank and those tickets import
unassigned — counted in the report, never silently dropped.

**3. Transform**, then read `PREREQUISITES.md` before importing. It lists, with
ticket counts, the exact analysts, ticket types, statuses, priorities, origins,
departments and categories the file needs — because the importer matches names
and reports a row rather than guessing, anything missing shows up as a per-row
error in the preview instead of quietly blanking a field.

## What comes across

| Zoho | freeitsm |
|---|---|
| ID | `external_ref` (re-run key) |
| Subject, Description | subject, opening email body |
| Resolution | a `--- Resolution ---` section in the body |
| Status, Priority | mapped by name |
| Ticket Type - ITIL | ticket type — 100% filled, unlike the legacy Ticket Type |
| Event / SR / INC Category + Sub | category and subcategory, picked per type |
| Channel | origin |
| accountName(Account ID) | customer |
| Email, lastName(Contact ID) | requester (portal user) |
| Ticket Owner | owner + assigned analyst, via the agent map |
| name(Team Id) | department |
| Created / Closed / Agent Responded | created, closed, acknowledged datetimes |
| Request Id, SLA, tags, time spent, Zoho status/priority | a footer in the body, so nothing is lost |

Zoho keeps a **separate category set per ITIL type** — Event, SR and INC each
have their own pair — which is why the report groups categories by type. Once
per-type categories land in the platform they can be scoped the same way.

## What does not

- **Conversation threads.** A Cases CSV has thread *counts*, not thread bodies.
  Only the opening description and the resolution exist in this file; the rest
  needs the Zoho API.
- **Attachments** — not in a CSV export at all.
- **Time spent** lands in the body rather than as time entries: the importer has
  no time-entry dataset, and attributing thousands of entries to analysts who may
  not map is worse than leaving the number readable.
- **CSAT, CAB dates, configuration items** — too sparse in this export to be
  worth a mapping (12, 20 and 4 rows respectively). Check your own file with
  `profile` before assuming that holds.

## Import order

1. `10-customers.csv` → **Customers**
2. `20-portal-users.csv` → **Portal users**
3. `30-tickets.csv` → **Tickets**

Tickets last, because they reference the other two by name.
