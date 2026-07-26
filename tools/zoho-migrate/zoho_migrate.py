#!/usr/bin/env python3
"""
Zoho Desk -> freeitsm migration.

Turns a Zoho "Cases" CSV export into files the platform's own Mass import can
load, and tells you exactly what to create in the platform first so nothing has
to be guessed at import time.

    python zoho_migrate.py profile   <export.csv>
    python zoho_migrate.py transform <export.csv> [--out DIR] [--mapping mapping.json]

WHY IT WORKS THIS WAY
---------------------
The transform deliberately produces CSVs for System > Mass import rather than
writing to the database. That import already resolves every lookup BY NAME,
previews before it writes, reports per-row problems, and runs in one
transaction — so the migration inherits all of it, and a bad mapping costs you a
preview rather than a restore. It is also re-runnable: tickets carry their Zoho
id in `external_ref`, so a second run updates the same rows instead of creating
16,000 more.

WHAT IT WILL NOT DO
-------------------
Invent people. Zoho stores the ticket owner as a numeric id, so agents must be
mapped to real analysts before owners can be set. The tool recovers each id's
SURNAME from the Created By / Modified By columns of the same file and writes
them into the mapping as hints, so the job is "match 15 surnames to your staff",
not "decode 15 opaque ids". Unmapped owners leave the ticket unassigned and are
counted in the report rather than silently dropped.
"""

import argparse
import collections
import csv
import html
import json
import os
import re
import sys

csv.field_size_limit(10_000_000)

HERE = os.path.dirname(os.path.abspath(__file__))
DEFAULT_MAPPING = os.path.join(HERE, 'mapping.json')

# Columns whose values become platform records, so the report can list them.
CLASSIFIERS = ['Status', 'Priority', 'Channel', 'Ticket Type - ITIL', 'name(Team Id)',
               'Category', 'Sub Category', 'Event Category', 'Event Sub Category',
               'SR Category', 'SR Sub Category', 'INC Category', 'INC Sub Category']


# --------------------------------------------------------------------------- io
def read_rows(path):
    with open(path, encoding='utf-8-sig', errors='replace', newline='') as f:
        return list(csv.DictReader(f))


def load_mapping(path):
    with open(path, encoding='utf-8') as f:
        return json.load(f)


def write_csv(path, header, rows):
    with open(path, 'w', encoding='utf-8', newline='') as f:
        w = csv.writer(f, lineterminator='\n')
        w.writerow(header)
        w.writerows(rows)
    print(f'  {os.path.basename(path):<28} {len(rows):>6} rows')


# ---------------------------------------------------------------------- helpers
def val(row, col):
    return (row.get(col) or '').strip()


def html_to_text(s):
    """Zoho descriptions are HTML. The importer stores the body as escaped text,
    so tags would show up literally — flatten to readable plain text instead."""
    if not s:
        return ''
    s = re.sub(r'(?i)<br\s*/?>', '\n', s)
    s = re.sub(r'(?i)</p\s*>', '\n\n', s)
    s = re.sub(r'(?i)<li[^>]*>', '\n • ', s)
    s = re.sub(r'<[^>]+>', '', s)
    s = html.unescape(s)
    s = re.sub(r'[ \t]+\n', '\n', s)
    s = re.sub(r'\n{3,}', '\n\n', s)
    return s.strip()


def owner_names(rows):
    """Zoho id -> most common surname, recovered from the Created/Modified By pairs."""
    seen = {}
    for r in rows:
        for id_col, name_col in (('Created By', 'lastName(Created By)'),
                                 ('Modified By', 'lastName(Modified By)')):
            i, nm = val(r, id_col), val(r, name_col)
            if i and nm:
                seen.setdefault(i, collections.Counter())[nm] += 1
    return {i: c.most_common(1)[0][0] for i, c in seen.items()}


def type_categories(row, itil_type):
    """Zoho keeps a separate category pair per ITIL type; pick the right one and
    fall back to the legacy generic pair."""
    by_type = {
        'Event':           ('Event Category', 'Event Sub Category'),
        'Service Request': ('SR Category', 'SR Sub Category'),
        'Incident':        ('INC Category', 'INC Sub Category'),
    }
    cat_col, sub_col = by_type.get(itil_type, ('Category', 'Sub Category'))
    cat, sub = val(row, cat_col), val(row, sub_col)
    if not cat:
        cat, sub = val(row, 'Category'), val(row, 'Sub Category')
    return cat, sub


# --------------------------------------------------------------------- profile
def cmd_profile(args):
    rows = read_rows(args.csv)
    n = len(rows)
    print(f'{args.csv}\n{n} rows, {len(rows[0])} columns\n')

    print('FILL RATES (columns with any data)')
    for col in rows[0].keys():
        filled = sum(1 for r in rows if val(r, col))
        if filled:
            print(f'  {col:<34} {filled:>6}/{n}  {filled*100//n:>3}%')

    print('\nVALUE SETS (what has to exist in the platform)')
    for col in CLASSIFIERS:
        if col not in rows[0]:
            continue
        c = collections.Counter(val(r, col) for r in rows if val(r, col))
        print(f'\n  {col}  —  {len(c)} distinct')
        for v, k in c.most_common(30):
            print(f'      {k:>6}  {v}')

    names = owner_names(rows)
    owners = collections.Counter(val(r, 'Ticket Owner') for r in rows if val(r, 'Ticket Owner'))
    print(f'\nTICKET OWNERS  —  {len(owners)} distinct ids')
    for oid, cnt in owners.most_common():
        print(f'      {cnt:>6}  {oid}  {names.get(oid, "(no name in file)")}')
    return 0


# ------------------------------------------------------------------- transform
def cmd_transform(args):
    rows = read_rows(args.csv)
    m = load_mapping(args.mapping)
    out = args.out or os.path.join(os.path.dirname(os.path.abspath(args.csv)), 'freeitsm-import')
    os.makedirs(out, exist_ok=True)

    opts = m.get('options', {})
    fallback_email = opts.get('fallback_requester_email', 'zoho-import@localhost')
    time_cap = int(opts.get('max_time_minutes', 1440))
    names = owner_names(rows)

    # Agent map: keys are Zoho ids, values the analyst's full name in freeitsm.
    agents = m.get('agents', {})
    unmapped_owner_rows = collections.Counter()
    unmapped_values = collections.defaultdict(collections.Counter)

    def mapped(kind, value, row_note=None):
        """Map a Zoho value through the mapping, recording anything unmapped."""
        table = m.get(kind, {})
        if value in table:
            return table[value]
        if value:
            unmapped_values[kind][value] += 1
        return table.get('__default__', '')

    customers, users, tickets = {}, {}, []
    stats = collections.Counter()

    for r in rows:
        zid = val(r, 'ID')
        if not zid:
            stats['skipped_no_id'] += 1
            continue

        itil = val(r, 'Ticket Type - ITIL')
        cat, sub = type_categories(r, itil)

        # ---- customer + requester ----------------------------------------
        account = val(r, 'accountName(Account ID)')
        if account:
            customers.setdefault(account, {'name': account, 'contact': val(r, 'lastName(Contact ID)')})

        email = val(r, 'Email').lower()
        if email:
            users.setdefault(email, val(r, 'lastName(Contact ID)') or email.split('@')[0])
        else:
            stats['no_requester_email'] += 1

        # ---- owner ---------------------------------------------------------
        owner_id = val(r, 'Ticket Owner')
        owner = agents.get(owner_id, '')
        if owner_id and not owner:
            unmapped_owner_rows[f'{owner_id} ({names.get(owner_id, "unknown")})'] += 1

        # ---- body ----------------------------------------------------------
        body_parts = []
        desc = html_to_text(val(r, 'Description'))
        if desc:
            body_parts.append(desc)
        resolution = html_to_text(val(r, 'Resolution'))
        if resolution:
            body_parts.append('--- Resolution ---\n' + resolution)

        # Everything the ticket cannot hold as a field, kept rather than lost.
        meta = [f'Zoho ID: {zid}']
        for label, col in (('Request', 'Request Id'), ('Channel', 'Channel'), ('SLA', 'SLA Name'),
                           ('SLA outcome', 'SLA Violation Type'), ('Tags', 'Tags'),
                           ('Zoho status', 'Status'), ('Zoho priority', 'Priority'),
                           ('Team', 'name(Team Id)'), ('Due', 'Due Date')):
            if val(r, col):
                meta.append(f'{label}: {val(r, col)}')
        spent = val(r, 'Total Time Spent')
        if spent.isdigit() and int(spent) > 0:
            meta.append(f'Time spent (Zoho): {spent} min')
            if int(spent) > time_cap:
                stats['time_over_cap'] += 1
        body_parts.append('--- Imported from Zoho ---\n' + '\n'.join(meta))

        status = mapped('statuses', val(r, 'Status'))
        closed = val(r, 'Ticket Closed Time')
        # A ticket the platform considers open cannot carry a closed date.
        if status and status.lower() not in ('closed',):
            closed = ''

        tickets.append({
            'external_ref': zid,
            'subject': (val(r, 'Subject') or f'Zoho ticket {zid}')[:255],
            'body': '\n\n'.join(body_parts),
            'status': status,
            'priority': mapped('priorities', val(r, 'Priority')),
            'ticket_type': mapped('types', itil),
            'category': cat,
            'subcategory': sub,
            'department': mapped('departments', val(r, 'name(Team Id)')),
            'origin': mapped('channels', val(r, 'Channel')),
            'customer': account,
            'requester_email': email or fallback_email,
            'owner': owner,
            'assigned_analyst': owner,
            'created_datetime': val(r, 'Created Time'),
            'closed_datetime': closed,
            'acknowledged_datetime': val(r, 'Agent Responded Time'),
        })
        stats['tickets'] += 1

    # ---- write ------------------------------------------------------------
    print(f'\nWriting to {out}')
    write_csv(os.path.join(out, '10-customers.csv'),
              ['name', 'contact_name', 'is_active'],
              [[c['name'], c['contact'], 1] for c in customers.values()])

    write_csv(os.path.join(out, '20-portal-users.csv'),
              ['email', 'display_name'],
              [[e, n] for e, n in sorted(users.items())])

    tcols = ['external_ref', 'subject', 'body', 'status', 'priority', 'ticket_type', 'category',
             'subcategory', 'department', 'origin', 'customer', 'requester_email', 'owner',
             'assigned_analyst', 'created_datetime', 'closed_datetime', 'acknowledged_datetime']
    write_csv(os.path.join(out, '30-tickets.csv'), tcols, [[t[c] for c in tcols] for t in tickets])

    write_prerequisites(out, rows, tickets, m, names, unmapped_owner_rows, unmapped_values, stats, fallback_email)
    print(f'\n  PREREQUISITES.md            what to create before importing')
    return 0


def write_prerequisites(out, rows, tickets, m, names, unmapped_owners, unmapped_values, stats, fallback_email):
    """The half of a migration that usually lives in someone's head."""
    used = lambda field: collections.Counter(t[field] for t in tickets if t[field])
    agents = m.get('agents', {})

    L = []
    L.append('# Before you import\n')
    L.append(f'Source: {len(rows)} Zoho rows -> {stats["tickets"]} tickets.\n')
    L.append('Create the things below **first**. The importer matches every lookup by NAME '
             'and reports a row rather than guessing, so anything missing here shows up as '
             'a per-row error in the preview instead of silently blanking a field.\n')

    # --- agents
    L.append('\n## 1. Analysts\n')
    filled = {z: f for z, f in agents.items() if f.strip()}
    if filled:
        L.append('These names must exist in System > Analysts, spelled exactly:\n')
        for zid, full in sorted(filled.items(), key=lambda kv: kv[1]):
            L.append(f'- **{full}**  _(Zoho {zid}, surname in export: {names.get(zid, "?")})_')
    else:
        L.append('No agents are mapped yet, so every ticket imports **unassigned**. Open '
                 '`mapping.json`, put each analyst full name against its Zoho id (the '
                 'surnames below came out of this export), then re-run the transform.\n')
    if unmapped_owners:
        L.append('\n**Unmapped owners** — these tickets import unassigned. Add each id to '
                 '`agents` in mapping.json and re-run:\n')
        for who, cnt in unmapped_owners.most_common():
            L.append(f'- {who} — {cnt} tickets')
    else:
        L.append('\nEvery ticket owner in the export is mapped.')

    # --- everything else that is looked up by name
    for title, field, note in (
        ('2. Ticket types', 'ticket_type', 'System > Tickets settings. "Event" and "Change" are not in a stock install.'),
        ('3. Statuses', 'status', 'Stock installs already have these five.'),
        ('4. Priorities', 'priority', 'Stock installs already have these.'),
        ('5. Origins (channels)', 'origin', 'Create any that are missing — "Automation" and "Security Logging" are Zoho-specific.'),
        ('6. Departments', 'department', 'From the Zoho team names.'),
    ):
        c = used(field)
        L.append(f'\n## {title}\n')
        L.append(f'{note}\n')
        for v, k in c.most_common():
            L.append(f'- `{v}` — {k} tickets')

    # --- categories, grouped by the type they belong to
    L.append('\n## 7. Categories and subcategories\n')
    L.append('Zoho keeps a separate category set per ITIL type, which is how they are exported '
             'here. Until per-type categories land in the platform these are all global, so '
             'create them as one list and scope them later.\n')
    by_type = collections.defaultdict(lambda: collections.defaultdict(collections.Counter))
    for t in tickets:
        if t['category']:
            by_type[t['ticket_type'] or '(no type)'][t['category']][t['subcategory'] or '(none)'] += 1
    for typ in sorted(by_type):
        L.append(f'\n**{typ}**\n')
        for cat in sorted(by_type[typ]):
            total = sum(by_type[typ][cat].values())
            L.append(f'- `{cat}` — {total} tickets')
            for sub, k in by_type[typ][cat].most_common():
                if sub != '(none)':
                    L.append(f'    - `{sub}` — {k}')

    # --- anything the mapping did not know about
    if unmapped_values:
        L.append('\n## Unmapped values\n')
        L.append('Seen in the export with no entry in mapping.json. They fell back to the '
                 'default (usually blank) — add them and re-run if they matter.\n')
        for kind, c in unmapped_values.items():
            L.append(f'\n**{kind}**\n')
            for v, k in c.most_common():
                L.append(f'- `{v}` — {k} rows')

    # --- honest notes
    L.append('\n## What did not come across\n')
    threaded = sum(1 for r in rows
                   if val(r, 'Number of Threads').isdigit() and int(val(r, 'Number of Threads')) > 1)
    L.append(f'- **Conversation threads.** {threaded} tickets had more than one thread in Zoho, but a '
             f'Cases CSV export carries no thread bodies - only the opening description and the '
             f'resolution, both of which are in the ticket body. Getting the conversations across '
             f'needs the Zoho API, not this file.')
    L.append(f'- **{stats["no_requester_email"]} tickets have no requester email** and use '
             f'`{fallback_email}`. Change `fallback_requester_email` in mapping.json if you would '
             f'rather they hang off a real address.')
    L.append('- **Time spent** is carried in the ticket body, not as time entries — the importer '
             'has no time-entry dataset yet, and attributing 16,000 entries to analysts who may not '
             'map is worse than leaving the number visible.')
    if stats['time_over_cap']:
        L.append(f'- {stats["time_over_cap"]} rows report more than the sanity cap of time spent; '
                 f'they are recorded verbatim but are probably Zoho artefacts.')
    L.append('- **Attachments, CSAT ratings, CAB approval dates and configuration items** are not '
             'imported: the first are not in a CSV export at all, the rest are too sparse here to '
             'be worth a mapping (12 ratings, 4 CIs).')

    L.append('\n## Then import, in this order\n')
    L.append('System > Mass import, previewing each one:\n')
    L.append('1. `10-customers.csv` -> **Customers**')
    L.append('2. `20-portal-users.csv` -> **Portal users**')
    L.append('3. `30-tickets.csv` -> **Tickets**')
    L.append('\nTickets carry their Zoho id in `external_ref`, so a second run **updates** the same '
             'tickets rather than creating duplicates. Fix a mapping, re-run the transform, '
             're-import — the ticket numbers stay stable.')

    with open(os.path.join(out, 'PREREQUISITES.md'), 'w', encoding='utf-8') as f:
        f.write('\n'.join(L) + '\n')


def main():
    ap = argparse.ArgumentParser(description='Zoho Desk -> freeitsm migration')
    sub = ap.add_subparsers(dest='cmd', required=True)

    p1 = sub.add_parser('profile', help='describe an export: fill rates, value sets, owners')
    p1.add_argument('csv')
    p1.set_defaults(func=cmd_profile)

    p2 = sub.add_parser('transform', help='write import-ready CSVs + PREREQUISITES.md')
    p2.add_argument('csv')
    p2.add_argument('--out', default=None)
    p2.add_argument('--mapping', default=DEFAULT_MAPPING)
    p2.set_defaults(func=cmd_transform)

    args = ap.parse_args()
    return args.func(args)


if __name__ == '__main__':
    sys.exit(main())
