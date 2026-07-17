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
| 4B | Ticket reading-pane info panel + SLA surfacing | ▶ Next |
| 5 | Queues & filtering | ⏳ Planned |
| 4A | Ad-hoc report builder | ⏳ Planned |

**Build order:** 4B → 5 → 4A. 4B is a quick, visible win; Phase 5 builds the
shared filter engine; 4A (reports) is built last, on top of that engine.

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

## Cross-cutting / deferred
- **Filter engine is shared** across list, queues, and reports — build once.
- Wire `sla_priority_change_behaviour` (for both priority changes and CI-linking).
- Advanced filter operators (is-not, specific date before/after).
- Team-scoped queues (v1 is personal-or-global only).
- i18n: new UI strings in Phase 3b and 4B are **English-only** pending
  translation-key additions.
