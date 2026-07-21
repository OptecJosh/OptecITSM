# FreeITSM — Development Roadmap

Working plan for the phased build. Delivery is one git commit per phase; the
SLA engine is **compute-on-read** (no stored counters, targets re-resolve on
each read). This doc captures scope + the decisions already locked so we don't
re-litigate them.

## Status at a glance

| Phase | Scope | Status |
|-------|-------|--------|
| 1 | Ticket categories & subcategories | ✅ Done |
| 2a | Shared custom-field engine (from CMDB property engine) | ✅ Done |
| 2b | Ticket custom fields | ✅ Done |
| 3 | Multiple SLA policies, resolved per company | ✅ Done |
| 3b | Per-device (CMDB CI) SLA policies | ✅ Done |
| 4B | Ticket reading-pane info panel + SLA surfacing | ✅ Done |
| 5 | Queues & filtering (5a engine + ad-hoc, 5b custom queues) | ✅ Done |
| 4A | Ad-hoc report builder | ✅ Done |
| 6 | Agent productivity (ticketing) | ✅ Done |
| 7 | Request management & deflection (7a–7e) | ✅ Done |
| 8 | SLA snapshot, reporting & dashboards | ▶ Next |
| 9 | ITSM depth (assets / change / contracts) | ⏳ Planned |
| 10 | Platform & admin | ⏳ Planned |
| 11 | Agent overtime management (new module) | ⏳ Planned |

**Build order so far:** 4B → 5 → 4A → 6 → 7 shipped. Next is **8 → 9 → 10 → 11**,
the backlog from the whole-app review (below), highest daily value first. Each
group has a design doc under `docs/design/phase-0N-*.md`.

**Phase 7 sub-phases (all shipped):** 7a KB deflection & article ratings · 7b
public status page + announcements · 7c-1 service catalog (items, portal request,
admin) · 7c-2 catalog items with attached forms · 7d service-request approvals ·
7e per-company portal branding.

---

## Delivered

### Phase 3b — Per-device SLA policies
A ticket's **primary affected CI** can carry its own SLA policy that overrides
the customer's tier. Resolution is most-specific-first: **device → customer →
default**, still compute-on-read.

Locked decisions:
- Only the **primary** affected CI drives SLA (secondary linked CIs never do),
  so the tier is always explainable by one device or the company.
- First-linked CI auto-becomes primary; analysts can reassign; deleting the
  primary promotes the next.
- Adopting a device policy is **retroactive to ticket creation** (a consequence
  of compute-on-read) — same behaviour as a priority change today.
- `sla_priority_change_behaviour` is still **declared-but-unwired** for both
  priority changes and CI-linking; wiring it is a shared future item.

---

## Planned

### Phase 4B — Ticket reading pane (next)
Small, self-contained, visible on every ticket.
- **Relocate** the existing collapsible "Properties" editor from the top of the
  pane to directly under the links bar (`buildLinksSection`). Fields and their
  assign-actions are unchanged — position only.
- **Enrich the collapsed summary** so the basics show at a glance without
  expanding: **Ticket #, Status, Priority, Type, Category, Customer, Owner**
  (today it only shows Department / Status / Owner).
- **Compact colour-coded SLA chip** in that summary (e.g. "SLA: 2h left" /
  "Approaching" / "Breached"), sourced from the same `get_ticket_sla` state
  (reflects the Phase 3b device/customer policy source).
- **Keep the detailed SLA progress bars** (`slaContainer`) lower in the pane.

### Phase 5 — Queues & filtering
The largest new capability. Three pieces on one foundation.

1. **Shared filter engine** (`includes/ticket_filter.php`) — one server-side
   filter builder used by the ticket list (`get_emails.php`), custom queues,
   and the report builder. This is the spine; build it first.

   **Filter fields (v1, agreed):** Status, Priority, Type, Category,
   Subcategory, Customer, Assignee/Owner, Department, Origin, SLA state
   (breached / approaching / met), Created-date range, Keyword (subject +
   requester). Each field is "is any of [values]" (multi-select); dates are
   ranges; keyword is contains. Advanced operators (is-not, before/after) are
   deferred.

2. **Custom queues** — a saved set of filters.
   - `ticket_queues` table: name, `owner_analyst_id` (NULL = shared/admin-owned,
     set = personal to that analyst), display order, filter definition.
   - Scope: **personal + shared**. Any analyst creates personal queues; only
     admins create/edit shared ones (capability-gated).
   - CRUD API (list / save / delete / reorder).
   - Sidebar: a **Queues section added alongside** the existing Department /
     Analyst tabs (nothing existing changes), with per-queue counts.

3. **Ad-hoc filtering** — a filter bar on the "All Tickets" list header using
   the same fields, with a **"Save as queue"** button so an ad-hoc filter
   becomes a queue in one click.

### Phase 4A — Ad-hoc report builder
Built last, reusing the Phase 5 filter engine.
- Report across the whole ticket dataset with **grouping + aggregation** on top
  of the shared filters.
- Groupable/filterable by Phase 1 categories & subcategories and Phase 2 custom
  fields.
- SLA outcomes: met / breached counts by policy, including the Phase 3b
  device-vs-customer source.

---

## Whole-app review backlog (Phases 6–11)

From the whole-app review (2026-07-20). The product is already broad — these are
refinements, ordered by daily value. Detailed designs: `docs/design/phase-0N-*.md`.

### Phase 6 — Agent productivity (ticketing)  ▶ next
Highest daily value; built on the tickets module.
- Canned responses / reply macros — reusable snippet library in the reply modal
- Ticket merge (+ split)
- Bulk list actions — multi-select → status / assignee / priority
- Round-robin / load-balanced auto-assignment (the workflow `assign_ticket` action already references logic that doesn't exist)
- Ticket tags / labels (tasks & KB already have tags; tickets don't)
- Watchers / followers — persistent subscribers beyond per-reply CC
- (stretch) ticket templates, recurring tickets

### Phase 7 — Request management & deflection
Biggest strategic gap: turns "helpdesk" into "service management".
- Service / request catalog with fulfilment items
- Service-request approvals — generalise the Change/CAB approval pattern to tickets
- End-user KB access in the portal + suggested-articles / deflection at ticket creation
- KB "was this helpful" ratings; public status page (service-status is auth-gated today)
- Portal per-tenant branding; announcements / broadcast

### Phase 8 — SLA snapshot, reporting & dashboards
One enabler unlocks three deferred items.
- **Cached SLA status snapshot** (breach cron stamps ticket SLA state) → unlocks the SLA-state filter (5), SLA-outcome reporting (4A) and time-based SLA notifications
- Scheduled & emailed reports
- Executive / cross-module dashboard

### Phase 9 — ITSM depth (assets / change / contracts)
- Software licence compliance / true-up (installed vs entitled); licence renewal reminders
- Change freeze / blackout windows
- CMDB-driven change risk — link changes → CIs, feed impact analysis into risk
- Contract → asset / service / SLA linkage
- Asset lifecycle states + EOL / disposal

### Phase 10 — Platform & admin
- Unified cross-module audit log + export
- In-app backup + CSV import / export
- SAML SSO (OIDC exists)
- Native notification channels (Slack / Teams app, SMS)
- Multi-tenancy coverage audit — verify tenant scoping across all modules (tickets confirmed)

### Phase 11 — Agent overtime management (new module)
Track and approve agent overtime for payroll; integrates with the on-call rota.
- Overtime entries (agent, date, hours/times, type: overtime / on-call / call-out, optional ticket link, rate multiplier)
- Submit → manager approval workflow; optional TOIL (time-off-in-lieu) balance
- Rota integration (`ticket_rota_shifts/entries`) + overtime reporting / export

---

## Cross-cutting / deferred
- **Filter engine is shared** across list, queues, and reports — build once.
- Wire `sla_priority_change_behaviour` (for both priority changes and CI-linking).
- Advanced filter operators (is-not, specific date before/after).
- Team-scoped queues (v1 is personal-or-global only).
- i18n: new UI strings in Phases 3b–5 and 4A are **English-only** pending
  translation-key additions.
- Automated network discovery (SNMP / nmap → CMDB) — larger infra effort; not
  slotted into a phase yet.
- Advanced approval routing (multi-step, conditional approvers) beyond the
  Phase 7 single-step service-request approval.

---

## Backlog — from the whole-app review

A four-agent survey of the whole app (2026-07-19) confirmed freeitsm is already
unusually complete (workflow engine, webhooks, OIDC SSO, REST API v1 + API keys,
MFA, on-call rota, CMDB impact analysis, change CAB + calendar + risk, problem
KEDB, contracts with renewal reminders, AI webchat deflection, KB versioning +
review). The gaps below are refinements, grouped into phases 6–11, highest daily
value first. Detailed designs: `docs/design/phase-0N-*.md`.

### Phase 6 — Agent productivity (ticketing) · design: `docs/design/phase-06-agent-productivity.md`
Highest per-day value; builds on the queues/filters just shipped.
- **Canned responses / reply macros** *(high)* — reusable snippet library in the reply modal.
- **Ticket merge (+ split)** *(high)* — no merge today, only linking.
- **Bulk list actions** *(high)* — select-all + bulk status/assignee/priority; pairs with queues.
- **Round-robin / load-balanced auto-assignment** *(high)* — the workflow `assign_ticket` action already references round-robin logic that doesn't exist.
- **Ticket tags/labels** *(high)* — tasks & KB have tags; tickets don't.
- **Watchers / followers** *(high)* — CC is per-reply only; no subscription.
- Stretch: ticket templates (quick-create), recurring tickets, billable time (rate/cost on `ticket_time_entries`).

### Phase 7 — Request management & deflection · design: `docs/design/phase-07-request-management.md`
Biggest strategic gap — turns "helpdesk" into "service management" and cuts ticket volume.
- **Service / request catalog** with fulfillment items *(high)*.
- **Service-request approvals** *(high)* — generalise the change CAB approval pattern to tickets.
- **End-user KB access + suggested-articles deflection at ticket creation** *(high)* — KB is analyst-only; deflection lives only in webchat.
- **KB "was this helpful" ratings** *(high)*; draft→review→approve publishing gate *(med)*.
- **Public status page** *(high)* — service-status is auth-gated today.
- Portal per-tenant branding *(med)*; announcements/broadcast *(med)*.

### Phase 8 — SLA snapshot, reporting & dashboards · design: `docs/design/phase-08-sla-snapshot-reporting.md`
One enabler unlocks three deferred items.
- **Cached SLA-state snapshot** — the breach cron stamps each ticket's SLA state → unlocks the **SLA-state filter** (Phase 5 deferral), **SLA-outcome reporting** (Phase 4A deferral), and **time-based SLA notifications**.
- **Scheduled & emailed reports** *(high)* — no report scheduling today.
- **Executive / cross-module dashboard** *(med)* — widgets are per-module only.

### Phase 9 — ITSM depth (assets / change / contracts) · design: `docs/design/phase-09-itsm-depth.md`
- **Software licence compliance / true-up** *(high)* — installed vs entitled reconciliation.
- **Change freeze / blackout windows** *(high)*.
- **CMDB-driven change risk** *(med-high)* — link changes→CIs, feed impact analysis into risk.
- **Contract → asset / service / SLA linkage** *(med-high)*.
- Licence renewal reminders *(med)*; asset lifecycle states + EOL/disposal *(med)*.

### Phase 10 — Platform & admin · design: `docs/design/phase-10-platform-admin.md`
- **Unified cross-module audit log + export** *(high)* — audit is siloed per module.
- **In-app backup + CSV import/export** *(high/med)*.
- **SAML SSO** *(med)* — only OIDC today.
- Native notification channels (Slack/Teams app, SMS) *(med)*.
- **Multi-tenancy coverage audit** *(med)* — verify `ticketTenantFilter`-style scoping is applied in every module's queries (tickets/reports/queues confirmed; others unaudited).

### Phase 11 — Agent overtime management (new module) · design: `docs/design/phase-11-overtime.md`
Track and approve agent overtime for payroll.
- Overtime entries (agent, date, hours, type: overtime / on-call / call-out, reason, optional ticket link).
- Submit → manager approval workflow; rate multipliers; optional TOIL (time-off-in-lieu) balance.
- Integrate with the existing on-call **rota** (`ticket_rota_shifts/entries`).
- Reporting + payroll export.

## Deferred (beyond phase 11)
- Automated network discovery (SNMP/nmap → CMDB) — larger infra effort.
- Multilingual KB content (per-article locale).
- Native mobile app.
