# Wiping test data

Two different jobs, two different tools. Pick by what you want to keep.

| | **Reset data** | **Full reset** |
|---|---|---|
| Keeps your configuration | yes | no |
| Deletes records | yes, per group | yes, all of it |
| Where | System → Reset data, or `scripts/wipe-test-data.php` | shell, recreating the database |
| Use when | a UAT round is finished and you want the same platform, empty | the install itself is scratch and you want factory settings |

Take a backup either way. System → Backup & data gives you one in the browser; on
the server:

```bash
cd /root/freeitsm && docker compose exec db mysqldump -ufreeitsm -pfreeitsm freeitsm > /root/pre-wipe-$(date +%F-%H%M).sql
```

---

## Reset data — keep the platform, delete the records

**System → Reset data.** Tick the groups you want gone, Preview, type the
confirmation phrase, delete. The preview lists every table and its exact row
count, and the delete button stays locked until the preview matches your current
selection — so you can never confirm one thing and delete another.

The same thing on the command line, over the same registry:

```bash
docker compose exec app php scripts/wipe-test-data.php
```

```bash
docker compose exec app php scripts/wipe-test-data.php --all
```

```bash
docker compose exec app php scripts/wipe-test-data.php --all --confirm
```

With no arguments it lists the groups and their row counts. `--all` or
`--groups=tickets,assets` gives a dry run. **Nothing is deleted without
`--confirm`**, and `--confirm` on its own is refused rather than assumed to mean
everything.

### The groups

| Group id | Deletes |
|---|---|
| `tickets` | tickets, audit trail, notes, time entries, CSAT, watchers, links, approvals, tags, affected CIs, SLA snapshots, KPI capture, recordings, stored emails, chat transcripts |
| `changes` | changes, audit, comments, checklists, attachments, CAB roster and votes, links to tickets/problems/CIs |
| `problems` | problems, notes journal, audit, incident links |
| `assets` | assets, hardware detail, history, checkout log, user assignments, Intune/vCenter sync results |
| `software` | discovered applications, per-host detail, sync log, licences |
| `cmdb` | configuration items, property values, relationships, per-CI SLA overrides, network diagrams |
| `contracts` | contracts, term values, asset coverage, suppliers, contacts, RFP documents and scoring |
| `customers` | customer accounts and their CI links |
| `knowledge` | articles, versions, ratings, tag assignments, portal announcements |
| `forms` | submissions and their answers |
| `tasks` | tasks, comments, tag assignments, calendar events, rota entries |
| `checks` | morning check results |
| `status` | status-page incidents and affected services |
| `people` | overtime claims, certifications held, training completed, journal entries, LMS progress |
| `kpi` | KPI measurements |
| `portal_users` | portal accounts, their preferences and SSO identities |
| `logs` | application log, cron history, SLA notification ledger, workflow executions and fire-once ledger, webhook deliveries, mailbox activity, login bans, reset tokens, trusted devices, wiki scan results |

### What always survives

Analysts, teams, companies, module access, RBAC roles, system settings, mailboxes
and messaging channels, API keys, SLA policies, targets and calendars, notification
rules, every status / priority / type / category lookup, departments, CMDB classes
and property definitions, custom field definitions, forms and their versions,
service catalogue items, workflow definitions, scheduled reports, KPI definitions,
the certification catalogue, LMS courses and groups, morning check definitions,
process maps, dashboard layouts, the knowledge tag vocabulary.

**The safety rule:** only tables named in `data_reset_groups()`
(`includes/data_reset.php`) are ever deleted. A table added to the schema next
month is kept by default, so the failure mode is leftover rows — never lost
configuration.

### Details worth knowing

- **One transaction.** FK checks are suspended for the run so ordering across
  groups can't bite, and the whole thing rolls back on any error. A failed wipe
  leaves the database exactly as it was.
- **Two tables are narrowed rather than emptied.** `custom_field_values` holds
  ticket values *and* CI values, so wiping tickets deletes only
  `entity_type = 'ticket'`. Software dashboard widgets pinned to a specific
  application go with the software group; general layout widgets stay.
- **AUTO_INCREMENT is not reset** — DELETE is used rather than TRUNCATE, because
  TRUNCATE is DDL and would commit implicitly, losing the rollback guarantee. New
  tickets carry on from the old ids. Cosmetic only.
- **Groups are independent, but some pairings make more sense than others.**
  Wiping `cmdb` while keeping `tickets` leaves tickets whose affected-CI links are
  gone (the links themselves are in the `tickets` group). Wiping `portal_users`
  while keeping tickets leaves tickets with no requester — the FK is set to NULL,
  nothing breaks, but the ticket says nothing about who asked.
- **Files on disk are not touched.** Ticket and change attachments live in Docker
  volumes; wiping the database leaves them orphaned. Harmless, but to clear them:
  `docker compose exec app sh -c 'rm -rf /var/www/html/tickets/attachments/* /var/www/html/change-management/attachments/*'`
- **The run is logged** to `system_logs` as a `data_reset` entry, written *after*
  the deletes so it survives a `logs` wipe. It shows in System → Audit log.
- **Web and CLI share one registry**, so they cannot disagree about what counts as
  a record.

---

## Full reset — factory fresh

Recreates the database from `database/freeitsm.sql`, which is the canonical schema
*and* seeds the Default company plus an `admin` account. This wipes your
configuration too.

```bash
cd /root/freeitsm && docker compose exec -T db mysql -uroot -prootpassword -e "DROP DATABASE freeitsm; CREATE DATABASE freeitsm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON freeitsm.* TO 'freeitsm'@'%';"
```

```bash
cd /root/freeitsm && docker compose exec -T db mysql -ufreeitsm -pfreeitsm freeitsm < database/freeitsm.sql
```

Then sign in as **`admin` / `freeitsm`**, change that password immediately, and run
**Database Verify** — the canonical SQL is complete as of its last edit, but
db_verify adds keys, indexes and seeds introduced since.

**Do not use `docker compose down -v`.** That also destroys the `encryption-keys`
volume. Without that key every encrypted setting — mailbox credentials, messaging
channel secrets — becomes unreadable, *including in any backup you restore*. If you
ever do lose it, expect to re-enter those credentials by hand.

The `./database/freeitsm.sql` bind mount only runs automatically on an empty data
directory, which is why the import above is explicit.

---

## Per-module demo data

The JSON demo importer (System → Demo Data) **deletes every table it inserts into**
before loading, so re-importing a module resets that module's demo tables. It
deletes real rows in those tables too, not just demo ones — test installs only.

The CSV bundle in `database/demo-csv/` is additive by contrast: it only ever
creates or updates. There is no un-import for it, so use Reset data or a backup to
get back.
