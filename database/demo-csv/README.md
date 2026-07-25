# Demo CSV bundle — test data for every module

A UAT dataset for **System → Mass import**. Seventeen CSVs that interlock: the
customers, suppliers and portal users created by the early files are what the
later files (tickets, contracts, contacts) point at by name.

Load them **in filename order** through System → Mass import: pick the dataset,
choose the file, **Preview**, then **Commit**. The preview is the point — it tells
you what will be created, what will be updated, which columns it ignored, and
which rows have problems, before anything is written.

| # | File | Dataset to pick | Rows | What it lets you test |
|---|------|-----------------|-----:|------------------------|
| 01 | `01-portal-users.csv` | Portal users | 24 | Portal sign-in, requester on a ticket, self-service |
| 02 | `02-customers.csv` | Customers | 12 | Customer accounts, contacts, the ticket customer picker, one inactive account |
| 03 | `03-suppliers.csv` | Suppliers | 8 | Supplier register, asset supplier, contract counterparties |
| 04 | `04-supplier-contacts.csv` | Supplier contacts | 10 | Contacts per supplier (needs 03) |
| 05 | `05-contracts.csv` | Contracts | 10 | Renewal reminders, service/SLA coverage, expiry filtering (needs 03) |
| 06 | `06-cmdb-cis.csv` | CMDB configuration items | 32 | CMDB, change→CI impact, customer→CI links (**see note**) |
| 07 | `07-assets.csv` | Assets | 36 | Inventory, warranty + EOL reminders, disposal, contract coverage (needs 03) |
| 08 | `08-tickets.csv` | Tickets | 40 | Queues, filters, SLA, reporting, CSAT, the customer picker (needs 01 + 02) |
| 09 | `09-problems.csv` | Problems (KEDB) | 12 | KEDB, known errors, workarounds, problem→ticket linking |
| 10 | `10-changes.csv` | Changes | 15 | CAB, calendar, freeze windows, risk, PIR |
| 11 | `11-tasks.csv` | Tasks | 20 | Kanban, list and calendar views |
| 12 | `12-calendar-events.csv` | Calendar events | 15 | Calendar, change windows, freeze overlay |
| 13 | `13-knowledge-articles.csv` | Knowledge articles | 12 | KB search, publish/draft, portal deflection, review dates |
| 14 | `14-overtime-requests.csv` | Overtime requests | 20 | Submit → approve → payroll report (**needs real analyst emails**) |
| 15 | `15-certification-catalogue.csv` | Certification catalogue | 12 | The catalogue, validity periods, derived expiry |
| 16 | `16-certifications-held.csv` | Certifications held | 16 | Certification tracking, amber/red expiry, `certification.expiring` (**needs real analyst emails**) |
| 17 | `17-training-completed.csv` | Training completed | 20 | Training log, hours and cost totals (**needs real analyst emails**) |

Roughly 340 records in total.

## Two things to do first

**1. Analyst emails (files 14, 16, 17).** These reference staff, and the importer
will not invent people. They ship pointing at the four analysts the built-in demo
data creates:

```
james.smith@example.com   sarah.williams@example.com
michael.jones@example.com laura.brown@example.com
```

Either import the **core** demo module first (System → Backup & data → demo data —
note it clears the analyst table except `admin`, so **test installs only**), or
open the three files and replace the `analyst_email` column with real addresses
from System → Analysts. Getting this wrong is safe: the preview reports
`analyst_email "..." does not exist` and writes nothing.

**2. CMDB classes (file 06).** CIs need an existing class. The `class` column uses
the class names from the CMDB demo module (Server, Database, Application, Service,
Network Device). Import that module first, or edit the column to match your own
class names.

Everything else only uses lookups a base install already has: ticket statuses
(Open, In Progress, On Hold, Awaiting Response, Closed), priorities (Low, Normal,
High, Critical, Urgent), ticket types (Incident, Service Request, Question),
origins (Email, Phone), problem statuses (New → Closed), change statuses,
priorities, types and impacts, and task statuses and priorities.

## What the data deliberately contains

It is shaped to exercise edge cases, not just fill tables:

- **Contracts** — one expired months ago, one expired 20 days ago, one expiring in
  6 days and one in 35, so the 90/30/7/1-day reminder windows all have something
  to fire on.
- **Assets** — purchase dates spread over four years, so warranties and
  end-of-life dates land both sides of today; four are already disposed of.
- **Tickets** — a mix of open, in progress, on hold and closed across 90 days,
  with acknowledgement times faster for High and Critical, so MTTA, MTTR and SLA
  attainment have a real spread rather than one flat number.
- **Certifications** — achieved, in progress, planned, one expired and two
  expiring inside 90 days, which is what turns the Training page amber and red.
- **Overtime** — approved, pending and rejected claims, weekday and weekend rates.
- **Customers** — one inactive, to check it disappears from the ticket picker but
  still shows on historic tickets.
- **Problems** — several marked as known errors with workarounds, and statuses
  spread from New to Closed.

Dates are absolute and centred on **July 2026**. If you are testing much later
than that, re-run the generator or shift the date columns in a spreadsheet.

## What is not here, and why

- **Analysts** are not importable — they carry credentials and module access.
  Create staff in System → Analysts, then set each one's **tier**, loaded rate and
  contracted hours (the KPI module needs those) and their **manager**, which is
  what the development journal and overtime approval routing key off.
- **KPI measurements.** The mass importer can load them, but a KPI name can appear
  on more than one scorecard and a CSV cannot say which one, so it would resolve to
  whichever it found first. Use the KPI module's own **Scorecards → Import CSV →
  Export template** flow instead (it works on KPI ids), or just run
  `cron/kpi_snapshot.php` once the tickets above are loaded — it computes about
  thirty of the metrics from them.
- **Development journal entries** are not importable by design: a journal belongs
  to an analyst and their line manager. Add a few by hand from LMS → Journal.
- **Relationships** that a flat file cannot express — CMDB relationships,
  customer→CI links, ticket→problem links, contract→asset coverage, CI→change
  impact. Add a handful in the UI; that is itself the test for those screens.

## Re-running or resetting

Every file except tickets, changes, tasks and calendar events matches on a natural
key, so re-importing updates rather than duplicates. Those four always create, so
importing them twice gives you two copies — deliberate, since neither a change nor
a task has a natural key. Take a database backup first (System → Backup & data) if
you want a clean way back.
