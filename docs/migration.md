# Migrating from another helpdesk

A runbook for moving history off your previous platform. The screen is
**System → Migrate from another system**; this covers the order to do things in,
and — importantly — exactly which reporting metrics survive the move and which
start from your cutover date.

## Why this is separate from Mass import

**Mass import** (`system/import/`) expects our column names. It is for loading
data you prepared, or re-importing something you exported from here.

**Migrate** expects *someone else's* column names. Its whole job is the two
translation problems an export from another tool creates:

1. **Columns** — their `Case Number` is our `ticket_number`, their
   `Short description` is our `subject`.
2. **Values** — their twelve statuses have to land on our five, and their
   `Sev 2` has to become our `High`.

Both are shown to you and both are editable before anything is written. The
mapping is only ever a suggestion with a confidence score attached, because the
dangerous failure here is not an error — it is every row importing "successfully"
with the wrong data in it.

Once the file is translated it goes through the *same* writer as Mass import, so
validation, tenancy and the audit trail are identical.

## Zoho Desk

Pick **Zoho Desk** in *Coming from* and the column mapping, value translations
and format handling are all applied for you. Two things it does that a manual
mapping cannot:

- **Day-first dates.** Zoho exports `13/01/2025 16:48`. Left alone, PHP reads
  `01/02/2025` as 1 February or 2 January depending on the wind. The preset
  converts to ISO before the importer sees it.
- **Millisecond durations.** `Resolution Time in Business Hours` of `108960000`
  is 30.3 hours, not 108 million minutes.

### Exporting from Zoho

1. **Leave out the `Description` column.** Its HTML contains newlines and quotes
   that break row alignment; excluding it took a real 23,117-row export from
   96.9% usable to effectively 100%. Ticket bodies aren't needed for reporting —
   export them separately against `Request Id` if you want them.
2. **Never open the file in Excel.** It rewrites Zoho's 18-digit IDs as
   `1.25E+17` and they cannot be recovered. Use a text editor, or Excel's
   *Data → From Text/CSV* with every column set to Text.
3. Include `Agent Name` and `Agent Tier` if your export offers them — the raw
   `Ticket Owner` column is a numeric ID with no name attached, so without them
   ownership and tier splits are lost.

`Request Id` is used as the natural key, prefixed `ZD-`, so migrated tickets stay
identifiable and can never collide with numbers this system generates.

### What comes across

Zoho measures more than most exports, so several metrics I'd otherwise have
written off do survive:

| Zoho column | Becomes | Why it's legitimate |
|---|---|---|
| `SLA Violation Type` | `ticket_sla_snapshot` | Zoho's own verdict, **not recomputed** — historic attainment stays the number you published |
| `Number of Reopen` | `ticket_audit` Status rows | Real measured count, reshaped so our reopen metric counts it |
| `Number of Reassign` | `ticket_audit` Owner rows | As above, for ticket bounce |
| `Is Escalated` | `ticket_escalations` | Real flag; tier from `Agent Tier` |
| `Total Time Spent` | `ticket_time_entries` | Real effort figure |

This is reshaping real measurements into our schema, not inventing history. A
blank column still produces no row, so "never recorded" stays distinguishable
from "zero".

Two columns are usually **empty** in practice and worth checking in your own
export before relying on them: `Happiness Rating` (CSAT) and
`Ticket On Hold Time`.

### Protecting published figures

After migrating, set **`kpi_cutover_month`** in `system_settings` to the first
month this system is authoritative for. `cron/kpi_snapshot.php` then refuses to
compute any earlier period, so a routine `--backfill` can never restate history
your customers have already seen in a monthly review. Without it, one cron run
silently overwrites migrated KPI values with figures computed against *our*
calendars and targets — which will not match.

## Order of loading

Names are resolved, not invented, so a thing must exist before the thing that
points at it:

1. **Requesters / end users** — `portal_users`
2. **Customers** — `customers`
3. **Assets** and **CMDB items** — if tickets reference them
4. **Suppliers**, then **Contracts**
5. **Tickets** — the main history
6. **Problems**, **Changes**, **Knowledge**, **Tasks**

Staff accounts are deliberately **never** created by a migration. An owner or
assignee column has to map onto analysts that already exist — create them in
System → Analysts first. Creating login-capable staff records as a side effect of
a CSV upload is not something an import screen should be able to do.

## Values you have never seen

For every mapped lookup column the screen lists each distinct source value, how
many rows use it, and whether it already resolves. For anything unrecognised you
have two choices:

- **Use instead** — type one of our existing names. This is how twelve legacy
  statuses collapse onto five, and how `Sev 2` becomes `High`. Leave it blank to
  keep the value as-is.
- **Create** — add it to the lookup table. Available for statuses, priorities,
  types, categories, departments, locations and similar. *Not* available for
  analysts or portal users.

Do this deliberately rather than creating everything: importing twelve statuses
because the old system had twelve leaves you administering twelve forever.

## Reconciliation

Preview writes nothing and reports what a commit would do. Both preview and
commit show:

```
source rows = written (created + updated) + row errors + rows not read (cap)
```

If that balances, the run is **Reconciled** and every source row is accounted
for. If it does not, the screen says how many rows are unexplained — do not sign
a migration off until that number is zero. Row-level problems are listed with
their row number and reason, so a failed row is always traceable to a line in
your file.

The cap is 100,000 rows per run. Split a larger history by date and load it in
several runs; matching is by natural key, so re-running a file is an update, not
a duplicate.

## What reporting keeps, and what starts at cutover

This is the part worth reading before you present a trend that spans the move.

**Derived from what you import** — these work across migrated history:

| Metric | Needs |
|---|---|
| MTTR | created and closed dates |
| MTTA | a first-response column mapped to `acknowledged_datetime` |
| SLA response / resolution outcome | the dates above, plus your priority targets |
| Volume, backlog, priority and category mix | the ticket rows themselves |
| Effort and cost | time entries, if you migrate them separately |

SLA outcomes are computed after commit by the real SLA engine reading the real
imported dates, so a migrated ticket reports exactly as a native one does.

**Cannot be recovered from a ticket export** — left empty rather than invented:

| Table | Consequence |
|---|---|
| `ticket_audit` | Reopen rate and reassignment ("bounce") read **0** for migrated tickets |
| `ticket_escalations` | Escalation rate and time-to-escalate start at cutover |
| `ticket_hold_events` | "MTTR excluding hold" equals MTTR for migrated tickets |
| `ticket_qa_reviews` | QA pass rate starts at cutover |
| `ticket_csat_responses` | CSAT starts at cutover unless your old platform exported responses |

These are left empty on purpose. Synthesising an escalation that never happened
would make every KPI computed from it a fiction, and a year later that fiction
would be indistinguishable from real data.

**Put a cutover marker on any trend chart that crosses the date.** A chart that
silently mixes "reopen rate 0 because we have no audit history" with "reopen rate
measured properly" looks like a dramatic improvement that never happened. See
`docs/powerbi-reporting.md` for the report build.

## Cutover checklist

1. Take a backup — System → Backup & data.
2. Run **Database Verify** so every table the migration writes to exists.
3. Migrate into a **test install first** and check a handful of tickets by hand
   against the old system: dates, owner, status, SLA outcome.
4. On the real install, load in the order above, previewing each file.
5. Confirm each run reports **Reconciled**.
6. Run `cron/kpi_snapshot.php?token=<sla_cron_token>` to compute monthly KPI
   values across the migrated history.
7. Spot-check `Reporting → Export → tickets` — the fact table should show
   populated `resolution_minutes`, `sla_met` and `owner_tier`, and empty
   `escalation_count` / `hold_minutes` for migrated rows, exactly as documented
   above.
8. Record the cutover date somewhere your reports can reference it.

## If something goes wrong

A commit is one transaction per run: it either all lands or none of it does, so a
failed run leaves nothing half-written. A run that *succeeded* but mapped a
column wrongly is fixed by correcting the mapping and re-running the same file —
rows match on their natural key and are updated in place rather than duplicated.

Every run is written to the system log as `data_migration` and appears in
System → Audit log with its counts.
