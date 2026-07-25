# Building the management report in Power BI

How to turn the analytics bundle into the pack you show directors. Everything
below assumes you downloaded **Reporting → Export → Bundle export → Analytics /
Power BI**, which gives you a zip of CSVs plus a `README.txt` and a
`_manifest.csv`.

The point of the bundle is that `tickets.csv` is already a fact table: the KPI
markers are calculated per row using the *same* definitions as the in-app KPI
scorecard, so your report and the scorecard agree. Redefine a metric in DAX and
they will drift — if you need a different definition, change
`includes/kpi_engine.php` and `data_export_ticket_derived()` together.

---

## 1. Load and model

Import every CSV in the zip. Then set the relationships:

| From | To | Cardinality |
|---|---|---|
| `ticket_escalations[ticket_number]` | `tickets[ticket_number]` | many → 1 |
| `ticket_hold_events[ticket_number]` | `tickets[ticket_number]` | many → 1 |
| `ticket_qa_reviews[ticket_number]` | `tickets[ticket_number]` | many → 1 |
| `ticket_time_entries[ticket_id]` | `tickets[id]` | many → 1 |
| `ticket_csat[ticket_id]` | `tickets[id]` | many → 1 |
| `ticket_sla_snapshot[ticket_number]` | `tickets[ticket_number]` | 1 → 1 |
| `kpi_measurements[kpi_name]` | `kpi_definitions[name]` | many → 1 |

The child files carry `ticket_number` deliberately, so you never have to join on
an internal database id.

### A date table

Add one — do not rely on `created_datetime`:

```dax
Dates =
ADDCOLUMNS(
    CALENDAR ( MIN ( tickets[created_date] ), MAX ( tickets[created_date] ) ),
    "Year",        YEAR ( [Date] ),
    "MonthNumber", MONTH ( [Date] ),
    "Month",       FORMAT ( [Date], "MMM yyyy" ),
    "Quarter",     "Q" & FORMAT ( [Date], "Q" ),
    "WeekdayNo",   WEEKDAY ( [Date], 2 ),
    "Weekday",     FORMAT ( [Date], "ddd" )
)
```

Mark it as a date table and relate `Dates[Date]` → `tickets[created_date]`.

`tickets.csv` also ships `created_month`, `created_week`, `created_weekday`,
`created_hour` and `closed_month` pre-cut, so simple groupings need no date
logic at all.

---

## 2. Measures

These mirror the scorecard. Note the deliberate use of `AVERAGE` on `sla_met`
and the blank-vs-zero rule: blank means "not applicable", so it must drop out of
an average rather than count as a failure.

```dax
Tickets Raised   = COUNTROWS ( tickets )
Tickets Closed   = CALCULATE ( COUNTROWS ( tickets ), NOT ISBLANK ( tickets[closed_date] ) )
Open Tickets     = CALCULATE ( COUNTROWS ( tickets ), ISBLANK ( tickets[closed_date] ) )

-- Backlog movement: positive means the team closed more than came in.
Backlog Movement = [Tickets Closed] - [Tickets Raised]

-- MTTA in minutes, MTTR in hours (the units directors expect).
MTTA (mins)      = AVERAGE ( tickets[ack_minutes] )
MTTR (hrs)       = AVERAGE ( tickets[resolution_hours] )

-- MTTR with customer/vendor waiting time stripped out. Use this when someone
-- objects that a slow ticket "was not our fault".
MTTR excl hold (hrs) =
DIVIDE ( AVERAGE ( tickets[resolution_minutes_excl_hold] ), 60 )

-- SLA attainment. sla_met is 1/0/blank, so AVERAGE gives the rate directly and
-- tickets with no applicable SLA are correctly excluded.
SLA Attainment % = AVERAGE ( tickets[sla_met] )

CSAT             = AVERAGE ( tickets[csat_rating] )
CSAT Responses   = CALCULATE ( COUNTROWS ( tickets ), NOT ISBLANK ( tickets[csat_rating] ) )

Reopen Rate %    = DIVIDE ( SUM ( tickets[reopen_count] ), [Tickets Closed] )
Bounce (avg)     = AVERAGE ( tickets[owner_changes] )

Escalation Rate % =
DIVIDE ( CALCULATE ( COUNTROWS ( tickets ), tickets[escalation_count] > 0 ), [Tickets Raised] )

First Time Fix % = DIVIDE ( CALCULATE ( COUNTROWS ( tickets ), tickets[first_time_fix] = 1 ), [Tickets Closed] )
Out of Hours %   = DIVIDE ( CALCULATE ( COUNTROWS ( tickets ), tickets[out_of_hours] = 1 ), [Tickets Raised] )

Effort (hrs)     = SUM ( tickets[logged_hours] )
Labour Cost      = SUM ( tickets[labour_cost] )
Cost per Ticket  = DIVIDE ( [Labour Cost], [Tickets Closed] )

QA Pass Rate %   = DIVIDE ( CALCULATE ( COUNTROWS ( tickets ), tickets[qa_passed] = 1 ), [QA Reviewed] )
QA Reviewed      = CALCULATE ( COUNTROWS ( tickets ), tickets[qa_review_count] > 0 )
```

### Month-on-month movement

The single most useful thing on a management pack is the direction of travel:

```dax
MTTR PM   = CALCULATE ( [MTTR (hrs)], DATEADD ( Dates[Date], -1, MONTH ) )
MTTR MoM  = DIVIDE ( [MTTR (hrs)] - [MTTR PM], [MTTR PM] )
```

Repeat for `SLA Attainment %` and `CSAT`. For MTTR and reopen rate a *negative*
MoM is good, so set the conditional-formatting direction per measure rather than
applying one rule to the whole table.

---

## 3. Suggested pages

**Page 1 — Service overview (the one they actually look at).**
KPI cards across the top: Tickets Raised, SLA Attainment %, MTTR (hrs), CSAT,
Cost per Ticket, each with its MoM arrow. Below: a combo chart of volume
(columns) against SLA Attainment % (line) by month — this is the chart that
shows "we absorbed more work and still improved". Then a donut of priority mix
and a bar of top ten categories.

**Page 2 — Performance detail.** MTTR and MTTA by priority and by month
(small multiples). MTTR vs MTTR-excluding-hold side by side. Reopen rate and
bounce by month. A table by owner with tickets closed, MTTR, QA pass rate and
CSAT — filtered by tier.

**Page 3 — Demand and capacity.** Heatmap of `created_weekday` against
`created_hour` (this is what justifies shift patterns). Out-of-hours share by
month. Effort hours and labour cost by tier. Tickets per analyst per month.

**Page 4 — Quality and risk.** QA pass rate by review type and reviewer.
Escalation rate and time-to-escalate by tier. Hold time split by reason
(`hold_reasons` on the fact, or `ticket_hold_events` for the distribution).
Backlog movement by month.

**Page 5 — Scorecard.** Straight from `kpi_measurements` joined to
`kpi_definitions`: value against target, coloured by the RAG thresholds and by
`direction` (for some KPIs lower is better). This is the page that ties the
report back to the in-app scorecard.

---

## 4. Getting data to report on

On a test or demonstration install, **System → Demo data → Reporting & KPI
history** generates back-dated tickets with the whole instrumentation filled in:
acknowledgement times, SLA outcomes, escalations, hold intervals, QA reviews,
CSAT, logged time and audit trail.

The generated data is deliberately shaped so the report has a story: volume
grows with a seasonal dip in August and December and a September peak, while
response and resolution times, SLA breaches, reopens and escalations all
improve across the period, and CSAT climbs. A report built on flat random data
demonstrates nothing, so the curves are explicit — see
`demoReportingCurves()` in `includes/demo_reporting.php` to re-tune them.

**Configure SLA targets first.** Resolution and response times are generated as a
multiple of each priority's own SLA target, so that the outcomes the SLA engine
computes afterwards line up with the intended trend — attainment rising from
roughly 50% to the low 90s across the period. Generate before setting targets and
the tickets come out `na`, contributing nothing to attainment. Order:

1. Tickets → Settings → SLA: enforcement date back-dated **before** the demo
   window, a response/resolution target on every priority, and a calendar on each.
2. Generate the history.
3. `cron/sla_snapshot_rebuild.php`, then `cron/kpi_snapshot.php --backfill=18`.

The generator warns on screen if any priority is missing a target.

Notes:

- Every generated ticket is numbered `DMO-YYMM-NNNN`. **Remove** on the same
  page deletes exactly those and nothing else, so it is safe to regenerate.
- Set a **seed** if you need the same numbers twice — useful when the same pack
  is presented more than once.
- Tick **set tier, rate and contracted hours** if your analysts have none, or
  every tier-split KPI and all the cost measures will be blank. This writes to
  real analyst records, which is why it is opt-in.
- After generating, run `cron/kpi_snapshot.php?token=<sla_cron_token>` so the
  in-app scorecard has monthly values too. The export does not need it — the
  fact table is computed on read.

---

## 5. Refreshing

Point Power BI at the bundle URL rather than re-downloading by hand:

```
https://<your-host>/api/export/export_bundle.php?bundle=analytics&from=2025-01-01
```

It requires an authenticated session, so for scheduled refresh either use a
gateway with stored credentials or export on a schedule and drop the zip
somewhere Power BI can reach. Use `from`/`to` on a large history — each dataset
is capped at 200,000 rows and `_manifest.csv` tells you if a file hit that cap.
