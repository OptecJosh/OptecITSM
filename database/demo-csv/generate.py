# Generate the demo CSV bundle for freeitsm's Mass import (System > Mass import).
# Only lookup values that a base install is guaranteed to have (seeded statuses,
# priorities, types) or that an earlier file in the bundle creates are used, so the
# bundle imports cleanly in order. Dates are absolute and centred on 2026.

import csv, os
from datetime import date, timedelta

OUT = os.path.dirname(os.path.abspath(__file__))
os.makedirs(OUT, exist_ok=True)
TODAY = date(2026, 7, 25)


def write(name, header, rows):
    path = os.path.join(OUT, name)
    with open(path, 'w', newline='', encoding='utf-8') as f:
        w = csv.writer(f, lineterminator='\n')
        w.writerow(header)
        w.writerows(rows)
    print(f'{name:<34} {len(rows):>4} rows')


def d(offset):
    return (TODAY + timedelta(days=offset)).isoformat()


def dt(offset, hour=9, minute=0):
    return f'{d(offset)} {hour:02d}:{minute:02d}:00'


# --------------------------------------------------------------- portal users --
FIRST = ['Olivia', 'Noah', 'Amelia', 'Oliver', 'Isla', 'Arthur', 'Freya', 'Leo', 'Ava', 'Henry',
         'Ivy', 'Jack', 'Poppy', 'Charlie', 'Mia', 'Thomas', 'Willow', 'Oscar', 'Rosie', 'George',
         'Ella', 'Theo', 'Sofia', 'Alfie']
LAST = ['Ashworth', 'Bramley', 'Cavendish', 'Dunmore', 'Ellwood', 'Fairbrother', 'Gledhill',
        'Hartley', 'Inskip', 'Jerrard', 'Kentish', 'Lomax', 'Marchant', 'Newbold', 'Ollerton',
        'Pemberton', 'Quarrell', 'Rushworth', 'Sanderling', 'Thorncroft', 'Underhill',
        'Vasey', 'Wetherby', 'Yarrow']
DEPTS = ['Finance', 'Operations', 'Sales', 'HR', 'Legal', 'Marketing', 'Warehouse', 'Facilities']

users = []
for i in range(24):
    fn, ln = FIRST[i], LAST[i]
    email = f'{fn.lower()}.{ln.lower()}@northgate-demo.co.uk'
    users.append([email, f'{fn} {ln}', fn])
write('01-portal-users.csv', ['email', 'display_name', 'preferred_name'], users)
USER_EMAILS = [u[0] for u in users]

# ------------------------------------------------------------------ customers --
CUSTOMERS = [
    ('Northgate Foods',            'NGF-001', 'Priya Raman',      'priya.raman@northgatefoods.example',   '0161 496 0121', 1,
     'Manufacturing. 3 sites, 24x7 line monitoring. Escalation path agreed with plant managers.'),
    ('Harlow & Boyd LLP',          'HBL-002', 'Duncan Boyd',      'd.boyd@harlowboyd.example',            '020 7946 0333', 1,
     'Legal. Very sensitive to any downtime during court hours (09:00-17:00).'),
    ('Cranbourne Academy Trust',   'CAT-003', 'Meera Shah',       'm.shah@cranbourne.example',            '0117 496 0177', 1,
     'Education. 6 schools, term-time change freeze.'),
    ('Pentland Logistics',         'PLG-004', 'Ross McAllister',  'ross.m@pentlandlogistics.example',     '0141 496 0212', 1,
     'Logistics. Depot WAN links are the usual culprit.'),
    ('Wexford Medical Group',      'WMG-005', 'Dr Aoife Nolan',   'a.nolan@wexfordmedical.example',       '0113 496 0455', 1,
     'Healthcare. Clinical systems out of scope; we cover the estate only.'),
    ('Brightling Retail',          'BRT-006', 'Sam Okonjo',       'sam.okonjo@brightling.example',        '0121 496 0788', 1,
     'Retail. 40 stores, EPOS uptime is the metric they care about.'),
    ('Ardleigh Construction',      'ARD-007', 'Tanya Whitmore',   't.whitmore@ardleigh.example',          '01206 496 011', 1,
     'Construction. Site cabins on 4G, expect patchy connectivity.'),
    ('Silverwood Financial',       'SVW-008', 'Gerald Pike',      'g.pike@silverwoodfin.example',         '020 7946 0912', 1,
     'Financial services. Change approval requires their CAB sign-off too.'),
    ('Meridian Housing',           'MER-009', 'Beth Cargill',     'b.cargill@meridianhousing.example',    '0191 496 0344', 1,
     'Housing association. Field staff on tablets.'),
    ('Kestrel Aviation Services',  'KAV-010', 'Idris Lamb',       'i.lamb@kestrelaviation.example',       '01293 496 088', 1,
     'Aviation ground services. Airside access needed for on-site work.'),
    ('Thornbury Council',          'THC-011', 'Marion Deeks',     'm.deeks@thornbury.example',            '01454 496 099', 1,
     'Public sector. Procurement is slow; keep quotes valid 90 days.'),
    ('Larkspur Media',             'LKM-012', 'Cody Vance',       'cody@larkspurmedia.example',           '020 7946 0455', 0,
     'Former customer - contract ended, kept for reporting history.'),
]
write('02-customers.csv',
      ['name', 'account_ref', 'contact_name', 'contact_email', 'contact_phone', 'is_active', 'notes'],
      [list(c) for c in CUSTOMERS])
CUSTOMER_NAMES = [c[0] for c in CUSTOMERS]

# ------------------------------------------------------------------ suppliers --
SUPPLIERS = [
    ('Ravensworth IT Distribution', 'Ravensworth', '04412771', 'GB412771330', 'Unit 4 Ravensworth Park', 'Meadowfield', 'Durham', 'County Durham', 'DH7 8EX', 'United Kingdom', 1, 1, 'Primary hardware distributor. Next-day on stocked lines.'),
    ('Calderstone Networks Ltd',    'Calderstone', '05531882', 'GB553188221', '12 Calder Business Park',  '',            'Wakefield', 'West Yorkshire', 'WF2 7AW', 'United Kingdom', 1, 1, 'Switching, firewalls, WAN circuits.'),
    ('Ashcombe Software Group',     'Ashcombe',    '06642993', 'GB664299312', '1 Ashcombe Court',         'Fleet Road',  'Farnborough', 'Hampshire', 'GU14 7QQ', 'United Kingdom', 0, 1, 'Licence reseller - Microsoft, Adobe, Atlassian.'),
    ('Brookmere Cloud Services',    'Brookmere',   '07753004', 'GB775300423', 'Brookmere House',          'Dock Street', 'Leeds', 'West Yorkshire', 'LS10 1JF', 'United Kingdom', 0, 1, 'Hosting and backup-as-a-service.'),
    ('Tidewell Print Solutions',    'Tidewell',    '08864115', 'GB886411534', '7 Tidewell Estate',        '',            'Portsmouth', 'Hampshire', 'PO6 1TR', 'United Kingdom', 1, 1, 'MFDs, break-fix and consumables.'),
    ('Glenmoor Security Partners',  'Glenmoor',    '09975226', 'GB997522645', '30 Glenmoor Street',       'Floor 3',     'Glasgow', 'Lanarkshire', 'G2 4JR', 'United Kingdom', 0, 1, 'SOC tooling and annual pen testing.'),
    ('Pellworth Telecom',           'Pellworth',   '10086337', 'GB008633756', 'Pellworth Exchange',       'Bath Road',   'Bristol', 'Somerset', 'BS4 3EN', 'United Kingdom', 0, 1, 'Mobile estate and SIP trunks.'),
    ('Marlbrook Facilities',        'Marlbrook',   '11197448', 'GB119744867', 'Marlbrook Yard',           '',            'Bromsgrove', 'Worcestershire', 'B60 1DX', 'United Kingdom', 1, 0, 'Comms room works, cabling, air-con.'),
]
write('03-suppliers.csv',
      ['legal_name', 'trading_name', 'reg_number', 'vat_number', 'address_line_1', 'address_line_2',
       'city', 'county', 'postcode', 'country', 'supplies_assets', 'is_active', 'comments'],
      [list(s) for s in SUPPLIERS])
SUPPLIER_NAMES = [s[0] for s in SUPPLIERS]

# ---------------------------------------------------------- supplier contacts --
CONTACT_ROWS = [
    ('Yvonne',  'Prentice',  'y.prentice@ravensworth.example',   '07700 900101', 'Account Manager',      '0191 496 0501', '0191 496 0500', SUPPLIER_NAMES[0]),
    ('Callum',  'Rennie',    'c.rennie@ravensworth.example',     '07700 900102', 'Technical Pre-sales',  '0191 496 0502', '0191 496 0500', SUPPLIER_NAMES[0]),
    ('Bernard', 'Lisle',     'b.lisle@calderstone.example',      '07700 900103', 'Account Director',     '01924 496 011', '01924 496 010', SUPPLIER_NAMES[1]),
    ('Nia',     'Trevalyan', 'n.trevalyan@calderstone.example',  '07700 900104', 'Support Lead',         '01924 496 012', '01924 496 010', SUPPLIER_NAMES[1]),
    ('Hugo',    'Fenwick',   'h.fenwick@ashcombe.example',       '07700 900105', 'Licensing Specialist', '01252 496 021', '01252 496 020', SUPPLIER_NAMES[2]),
    ('Simone',  'Adekunle',  's.adekunle@brookmere.example',     '07700 900106', 'Service Delivery Mgr', '0113 496 0031', '0113 496 0030', SUPPLIER_NAMES[3]),
    ('Raymond', 'Culshaw',   'r.culshaw@tidewell.example',       '07700 900107', 'Field Service Mgr',    '023 9496 0041', '023 9496 0040', SUPPLIER_NAMES[4]),
    ('Petra',   'Vaughan',   'p.vaughan@glenmoor.example',       '07700 900108', 'Head of SOC',          '0141 496 0051', '0141 496 0050', SUPPLIER_NAMES[5]),
    ('Alastair','Rooke',     'a.rooke@pellworth.example',        '07700 900109', 'Mobile Account Mgr',   '0117 496 0061', '0117 496 0060', SUPPLIER_NAMES[6]),
    ('Denise',  'Attwater',  'd.attwater@marlbrook.example',     '07700 900110', 'Works Coordinator',    '01527 496 071', '01527 496 070', SUPPLIER_NAMES[7]),
]
write('04-supplier-contacts.csv',
      ['first_name', 'surname', 'email', 'mobile', 'job_title', 'direct_dial', 'switchboard', 'supplier'],
      [list(c) + [] for c in CONTACT_ROWS])

# ------------------------------------------------------------------ contracts --
CONTRACTS = [
    ('CTR-2026-001', 'Hardware supply and next-day replacement', SUPPLIER_NAMES[0], d(-540), d(190),  90, 48000.00, 'GBP', 'IT-CAPEX',  'Mon-Fri 08:00-18:00',  '4 business hours', 'Next business day', 'Covers laptops, desktops and monitors on the standard build list.'),
    ('CTR-2026-002', 'Network support and circuit management',   SUPPLIER_NAMES[1], d(-400), d(-20),  60, 66500.00, 'GBP', 'IT-OPEX',   '24x7',                 '1 hour P1',        '8 hours P1',        'EXPIRED ON PURPOSE - use this to test the expiry reminder and renewal flow.'),
    ('CTR-2026-003', 'Microsoft 365 E3 licensing',               SUPPLIER_NAMES[2], d(-330), d(35),   30, 121000.00,'GBP', 'IT-LIC',    'Business hours',       'Next business day','5 business days',   'True-up each April. Seat count reconciled against the licence compliance view.'),
    ('CTR-2026-004', 'Cloud hosting and offsite backup',         SUPPLIER_NAMES[3], d(-620), d(110),  90, 93750.00, 'GBP', 'IT-OPEX',   '24x7',                 '30 minutes P1',    '4 hours P1',        'Includes 30-day retention and two DR tests a year.'),
    ('CTR-2026-005', 'Managed print service',                    SUPPLIER_NAMES[4], d(-250), d(480),  60, 28400.00, 'GBP', 'FAC-OPEX',  'Mon-Fri 09:00-17:00',  '8 business hours', '2 business days',   'Per-click billing. Consumables included, staples are not.'),
    ('CTR-2026-006', 'SOC monitoring and annual pen test',       SUPPLIER_NAMES[5], d(-180), d(6),    90, 74000.00, 'GBP', 'SEC-OPEX',  '24x7',                 '15 minutes P1',    '2 hours P1',        'EXPIRES IN DAYS - use for the 7-day expiry reminder window.'),
    ('CTR-2026-007', 'Mobile estate and SIP trunks',             SUPPLIER_NAMES[6], d(-700), d(300),  30, 41200.00, 'GBP', 'IT-OPEX',   'Business hours',       '1 business day',   '5 business days',   '180 handsets, 6 SIP channels.'),
    ('CTR-2026-008', 'Comms room maintenance and cabling',       SUPPLIER_NAMES[7], d(-95),  d(635),  90, 15900.00, 'GBP', 'FAC-CAPEX', 'Mon-Fri 08:00-16:00',  '2 business days',  '10 business days',  'Quarterly air-con service, ad-hoc cabling at day rate.'),
    ('CTR-2026-009', 'Out-of-hours service desk overflow',       SUPPLIER_NAMES[3], d(-60),  d(670),  60, 52000.00, 'GBP', 'IT-OPEX',   '18:00-08:00 + weekends','30 minutes P1',   '4 hours P1',        'Overflow only; tickets are handed back at 08:00.'),
    ('CTR-2026-010', 'Legacy AS400 support (winding down)',      SUPPLIER_NAMES[0], d(-900), d(-200), 30, 9800.00,  'GBP', 'IT-OPEX',   'Business hours',       '2 business days',  '10 business days',  'Long expired - keep inactive to test filtering out dead contracts.'),
]
write('05-contracts.csv',
      ['contract_number', 'title', 'supplier', 'contract_start', 'contract_end', 'notice_period_days',
       'contract_value', 'currency', 'cost_centre', 'service_hours', 'response_sla', 'resolution_sla', 'coverage_notes'],
      [[c[0], c[1], c[2], c[3], c[4], c[5], c[6], c[7], c[8], c[9], c[10], c[11], c[12]] for c in CONTRACTS])

# ----------------------------------------------------------------- CMDB items --
CIS = [
    ('SRV-DC01', 'Server'), ('SRV-DC02', 'Server'), ('SRV-FILE01', 'Server'), ('SRV-APP01', 'Server'),
    ('SRV-APP02', 'Server'), ('SRV-SQL01', 'Server'), ('SRV-SQL02', 'Server'), ('SRV-BACKUP01', 'Server'),
    ('SRV-RDS01', 'Server'), ('SRV-PRINT01', 'Server'),
    ('SQLDB-Finance', 'Database'), ('SQLDB-WMS', 'Database'), ('SQLDB-ITSM', 'Database'), ('SQLDB-Reporting', 'Database'),
    ('Sage 200', 'Application'), ('Warehouse Management System', 'Application'), ('Microsoft 365', 'Application'),
    ('Payroll Portal', 'Application'), ('EPOS Central', 'Application'), ('Site Access Control', 'Application'),
    ('Active Directory', 'Service'), ('DNS / DHCP', 'Service'), ('File and Print', 'Service'),
    ('Email Flow', 'Service'), ('Offsite Backup', 'Service'), ('Remote Access (VPN)', 'Service'),
    ('FW-HQ-01', 'Network Device'), ('SW-HQ-CORE-01', 'Network Device'), ('SW-HQ-EDGE-02', 'Network Device'),
    ('AP-HQ-FLOOR2-05', 'Network Device'), ('RTR-DEPOT-LEEDS', 'Network Device'), ('RTR-DEPOT-BRISTOL', 'Network Device'),
]
write('06-cmdb-cis.csv', ['name', 'class', 'is_planned'],
      [[n, c, 'no'] for n, c in CIS])

# --------------------------------------------------------------------- assets --
MODELS = [
    ('Dell', 'Latitude 5550', 'Windows 11 Pro', 1249.00),
    ('Dell', 'Latitude 7450', 'Windows 11 Pro', 1599.00),
    ('Dell', 'OptiPlex 7010', 'Windows 11 Pro', 899.00),
    ('Lenovo', 'ThinkPad T14 Gen 5', 'Windows 11 Pro', 1399.00),
    ('Lenovo', 'ThinkCentre M70q', 'Windows 11 Pro', 799.00),
    ('HP', 'EliteBook 840 G11', 'Windows 11 Pro', 1479.00),
    ('HP', 'ProDesk 400 G9', 'Windows 11 Pro', 749.00),
    ('Apple', 'MacBook Pro 14 M4', 'macOS 15', 2199.00),
]
assets = []
for i in range(36):
    man, model, os_, cost = MODELS[i % len(MODELS)]
    tag = f'{chr(65 + i % 26)}{chr(66 + i % 25)}{1000 + i * 7}'
    purchase = -1500 + i * 38                      # spread over ~4 years
    warranty = purchase + 1095                     # 3-year warranty
    eol = purchase + 1825                          # 5-year life
    host = f'NG-{"LT" if "Book" in model or "Latitude" in model or "ThinkPad" in model else "DT"}-{1001 + i}'
    row = [host, man, model, tag, os_, 'northgate.local', USER_EMAILS[i % len(USER_EMAILS)].split('@')[0],
           f'PO-2{i:04d}', f'{cost:.2f}', d(purchase), d(warranty), d(eol),
           SUPPLIER_NAMES[0] if i % 3 else SUPPLIER_NAMES[4]]
    # A handful already disposed of, to exercise the lifecycle view.
    if i in (2, 9, 17, 28):
        row += [d(eol + 30), 'WEEE recycling - certificate on file']
    else:
        row += ['', '']
    assets.append(row)
write('07-assets.csv',
      ['hostname', 'manufacturer', 'model', 'service_tag', 'operating_system', 'domain', 'logged_in_user',
       'order_number', 'purchase_cost', 'purchase_date', 'warranty_expiry', 'end_of_life_date', 'supplier',
       'disposal_date', 'disposal_method'],
      assets)

# -------------------------------------------------------------------- tickets --
SUBJECTS = [
    ('Laptop will not power on after update', 'Incident', 'High'),
    ('Cannot access shared finance folder', 'Incident', 'Normal'),
    ('Request new starter account - Warehouse', 'Service Request', 'Normal'),
    ('Outlook repeatedly asking for password', 'Incident', 'Normal'),
    ('Depot WAN link dropping every few minutes', 'Incident', 'Critical'),
    ('Please install Sage 200 client', 'Service Request', 'Low'),
    ('Printer on floor 2 jamming constantly', 'Incident', 'Low'),
    ('MFA reset - lost phone', 'Incident', 'High'),
    ('New starter kit for 3 site engineers', 'Service Request', 'Normal'),
    ('EPOS till 4 offline at Brightling Leeds', 'Incident', 'Critical'),
    ('Mailbox full, cannot send', 'Incident', 'Normal'),
    ('How do I share a Teams recording?', 'Question', 'Low'),
    ('VPN disconnects when on site cabin 4G', 'Incident', 'High'),
    ('Request additional monitor', 'Service Request', 'Low'),
    ('Backup job failed overnight - SQLDB-Finance', 'Incident', 'High'),
    ('Suspicious phishing email reported', 'Incident', 'High'),
    ('Access to Payroll Portal for new HR advisor', 'Service Request', 'Normal'),
    ('Warehouse scanners not connecting to WMS', 'Incident', 'Critical'),
    ('Password expired while working away', 'Incident', 'Normal'),
    ('Order replacement docking station', 'Service Request', 'Low'),
    ('Site Access Control card reader unresponsive', 'Incident', 'High'),
    ('Request shared mailbox for tenders inbox', 'Service Request', 'Low'),
    ('Sage 200 reports running very slowly', 'Incident', 'Normal'),
    ('Guest wifi not issuing addresses', 'Incident', 'Normal'),
    ('Decommission leaver account - Legal', 'Service Request', 'Normal'),
    ('Teams call quality poor from Bristol depot', 'Incident', 'Normal'),
    ('Two-factor prompt loop on new phone', 'Incident', 'Normal'),
    ('Request Adobe Acrobat licence', 'Service Request', 'Low'),
    ('File server disk nearly full', 'Incident', 'High'),
    ('Cannot print to MFD after firmware update', 'Incident', 'Normal'),
    ('New depot switch install - Leeds', 'Service Request', 'Normal'),
    ('Recover deleted contract folder', 'Incident', 'High'),
    ('Set up meeting room display', 'Service Request', 'Low'),
    ('Antivirus flagged file on SRV-APP02', 'Incident', 'High'),
    ('Request bulk export of ITSM tickets', 'Question', 'Low'),
    ('Onboard 12 seasonal warehouse staff', 'Service Request', 'High'),
    ('Air-con alarm in HQ comms room', 'Incident', 'Critical'),
    ('Mobile handset replacement - damaged screen', 'Service Request', 'Normal'),
    ('SharePoint sync errors on 4 laptops', 'Incident', 'Normal'),
    ('Quarterly access review evidence needed', 'Question', 'Normal'),
]
tickets = []
for i, (subject, ttype, prio) in enumerate(SUBJECTS):
    created_off = -88 + i * 2
    # Roughly two thirds closed, spread across statuses.
    if i % 5 == 0:
        status, closed = 'Open', ''
    elif i % 5 == 1:
        status, closed = 'In Progress', ''
    elif i % 5 == 2:
        status, closed = 'On Hold', ''
    else:
        status = 'Closed'
        closed = dt(created_off + (1 if prio in ('Critical', 'High') else 4), 14, 30)
    ack = dt(created_off, 9, 25 if prio in ('Critical', 'High') else 55)
    tickets.append([
        '',                                  # ticket_number - blank so one is generated
        subject,
        dt(created_off, 8, 40),
        closed,
        ack,
        status,
        prio,
        ttype,
        'Email' if i % 3 else 'Phone',
        CUSTOMER_NAMES[i % 11],              # the inactive 12th customer is left out
        USER_EMAILS[i % len(USER_EMAILS)],
        'yes' if status == 'Closed' and i % 4 == 0 else 'no',
    ])
write('08-tickets.csv',
      ['ticket_number', 'subject', 'created_datetime', 'closed_datetime', 'acknowledged_datetime',
       'status', 'priority', 'ticket_type', 'origin', 'customer', 'requester_email', 'first_time_fix'],
      tickets)

# ------------------------------------------------------------------- problems --
PROBLEMS = [
    ('Depot WAN instability on Leeds and Bristol links', 'New', 'High',
     'Both depots drop packets in bursts during the working day; users see Teams and WMS failures.',
     'Provider reports CRC errors on the local loop at both sites. Suspect shared upstream card.',
     'Failover to 4G bonded router during an outage; performance is degraded but usable.', 'no'),
    ('Outlook credential prompts after MFA policy change', 'Investigating', 'Medium',
     'Recurring password prompts across ~30 users since the conditional access change.',
     'Modern auth disabled on a legacy connector still in the policy set.',
     'Clear cached credentials and re-register the device.', 'no'),
    ('Sage 200 report timeouts at month end', 'Root Cause Identified', 'High',
     'Finance reports time out between the 28th and the 2nd every month.',
     'Missing index on the posting table; the plan flips to a scan once the table passes ~4m rows.',
     'Run the heavy reports out of hours until the index ships.', 'yes'),
    ('Warehouse scanners lose WMS session over wifi roam', 'Known Error', 'High',
     'Scanners drop the WMS session when roaming between aisles.',
     'AP firmware does not honour the fast-roam setting; vendor bug ref WX-4471.',
     'Pin the affected aisles to a single AP channel until firmware 9.4 is released.', 'yes'),
    ('Backup job fails when a snapshot is left mounted', 'Known Error', 'Medium',
     'Nightly SQL backup fails roughly one night in ten.',
     'A failed snapshot is not cleaned up, so the next run cannot acquire the volume.',
     'Dismount the stale snapshot and re-run the job.', 'yes'),
    ('MFD firmware 3.18 breaks Windows print queues', 'Resolved', 'Medium',
     'After the vendor firmware push, queues fail with 0x0000011b.',
     'Firmware changed the IPP negotiation; the queue must be recreated as IPP rather than RAW.',
     'Recreate the queue using IPP.', 'yes'),
    ('Guest wifi DHCP pool exhaustion', 'Resolved', 'Low',
     'Guests intermittently fail to get an address on busy days.',
     'Lease time of 24 hours on a /24 pool; visitor churn exhausts it.',
     'Shorten the lease to 4 hours.', 'no'),
    ('Air-con short-cycling in HQ comms room', 'Investigating', 'Critical',
     'Temperature alarms during warm afternoons; the unit short-cycles rather than holding setpoint.',
     'Suspected refrigerant loss - Marlbrook engineer booked.',
     'Portable unit plus propped door with a monitored temperature probe.', 'no'),
    ('Site Access Control readers freeze after power blip', 'New', 'Medium',
     'Readers need a manual power cycle after any brief mains interruption.',
     'Unknown - controller may lack a watchdog.',
     'Power cycle the controller cabinet.', 'no'),
    ('SharePoint sync errors on long file paths', 'Known Error', 'Low',
     'Sync stalls on paths over 255 characters, mostly in the tender library.',
     'Known Microsoft limitation with the legacy sync client.',
     'Shorten folder names or map the library as a drive.', 'yes'),
    ('VPN drops on 4G with aggressive NAT timeouts', 'Investigating', 'Medium',
     'Site cabins lose the tunnel every few minutes on one carrier.',
     'Carrier NAT timeout is shorter than the tunnel keepalive.',
     'Reduce the keepalive interval to 20 seconds on affected profiles.', 'no'),
    ('File server growth outpacing capacity plan', 'Closed', 'Low',
     'SRV-FILE01 hit 92% twice this quarter.',
     'No quota policy on the shared drives; media files never archived.',
     'Emergency clean-up of the recycle bins and old ISO images.', 'no'),
]
write('09-problems.csv',
      ['problem_number', 'title', 'status', 'priority', 'description', 'root_cause', 'workaround', 'is_known_error'],
      [['', p[0], p[1], p[2], p[3], p[4], p[5], p[6]] for p in PROBLEMS])

# -------------------------------------------------------------------- changes --
CHANGES = [
    ('Replace core switch SW-HQ-CORE-01', 'Normal', 'Scheduled', 'High', 'High',
     'Swap the ageing core switch for the new chassis, migrating uplinks in pairs.',
     'End of support in November and two PSU alerts this quarter.',
     'Failover test on each uplink pair before moving the next.', 'Roll back by re-patching uplinks to the old chassis, which stays racked for 7 days.', 12, 12, 'yes'),
    ('Apply July security patch baseline to servers', 'Standard', 'Completed', 'Medium', 'Medium',
     'Monthly patch ring across all server groups.',
     'Monthly compliance requirement.',
     'Patch the pilot ring first and check application smoke tests.', 'Uninstall the patch bundle and reboot.', -18, -17, 'no'),
    ('Enable conditional access for legacy protocols', 'Normal', 'Approved', 'High', 'High',
     'Block legacy auth except for two named service accounts.',
     'Closes the credential-stuffing route flagged in the pen test.',
     'Report-only mode for a week, then enforce.', 'Set the policy back to report-only.', 4, 4, 'yes'),
    ('Install depot switch at Leeds', 'Normal', 'Scheduled', 'Medium', 'Low',
     'New 24-port PoE switch for the mezzanine extension.',
     'Fifteen new scanner docks need wired uplinks.',
     'Verify PoE budget and VLAN trunking before cutover.', 'Leave the existing switch in place; no user impact until patched.', 9, 9, 'no'),
    ('Migrate file shares to Brookmere cloud storage', 'Normal', 'Pending Approval', 'High', 'High',
     'Phase 1: move the tender and marketing libraries.',
     'Capacity relief on SRV-FILE01 and better offsite recovery.',
     'Copy, verify checksums, run in parallel for a week.', 'Repoint the DFS namespace back to the on-premise target.', 21, 23, 'yes'),
    ('Decommission legacy AS400 gateway', 'Normal', 'Draft', 'Low', 'Medium',
     'Retire the gateway once the last finance extract is migrated.',
     'Contract CTR-2026-010 has expired and the platform is unsupported.',
     'Confirm 30 days with no traffic on the gateway interface.', 'Power the gateway back on; config is backed up.', 40, 41, 'yes'),
    ('Firmware upgrade for wifi APs to 9.4', 'Normal', 'Approved', 'High', 'Medium',
     'Fixes the fast-roam bug behind the warehouse scanner problem.',
     'Known error WX-4471 affects picking productivity daily.',
     'Upgrade two APs in aisle 7 and run a full picking shift before the rest.', 'Downgrade to 9.2 from the local image store.', 6, 6, 'yes'),
    ('Add index to Sage posting table', 'Normal', 'Completed', 'Medium', 'Medium',
     'Create the covering index identified in the month-end problem investigation.',
     'Removes the month-end report timeouts.',
     'Create ONLINE, then re-run the three slowest reports.', 'Drop the index.', -6, -6, 'no'),
    ('Emergency reboot of SRV-APP02 after AV quarantine', 'Emergency', 'Completed', 'Critical', 'High',
     'Service restart and reboot after the antivirus quarantined a DLL in use.',
     'Application down for all users; approved verbally by the duty manager.',
     'Smoke test the application login and a test transaction.', 'Restore the quarantined file from the AV vault.', -3, -3, 'no'),
    ('Rotate service account credentials (quarterly)', 'Standard', 'Completed', 'Medium', 'Low',
     'Quarterly rotation for the eight monitored service accounts.',
     'Security policy SEC-04.',
     'Rotate one account at a time and confirm dependent services.', 'Restore the previous credential from the vault.', -30, -30, 'no'),
    ('Replace HQ comms room air-con unit', 'Normal', 'Pending Approval', 'Critical', 'High',
     'Marlbrook to replace the failing unit; portable cooling in place meanwhile.',
     'Repeated temperature alarms risk an unplanned shutdown.',
     'Monitor the probe for 48 hours after commissioning.', 'Keep the portable unit on site for a fortnight.', 14, 15, 'yes'),
    ('Term-time change freeze for Cranbourne Academy Trust', 'Standard', 'Approved', 'Low', 'Low',
     'Administrative change recording the agreed freeze window.',
     'Customer contractual requirement.',
     'None required.', 'Remove the freeze window.', 2, 2, 'no'),
    ('Upgrade ITSM database server to SQL 2022', 'Normal', 'Draft', 'High', 'High',
     'In-place upgrade of SQLDB-ITSM with a full backup taken first.',
     'Support for 2017 ends next year.',
     'Restore the backup to a test instance and upgrade there first.', 'Restore the pre-upgrade full backup.', 55, 56, 'yes'),
    ('Enable disk quotas on shared drives', 'Standard', 'Rejected', 'Low', 'Medium',
     'Apply 200GB soft quotas per department share.',
     'Prevents a repeat of the file server capacity incident.',
     'Report-only quotas for two weeks.', 'Remove the quota policy.', 8, 8, 'no'),
    ('Roll out new standard laptop build (Win11 24H2)', 'Normal', 'In Progress', 'Medium', 'Medium',
     'New image with the updated security baseline and Sage client preinstalled.',
     'Reduces new-starter setup from 4 hours to 40 minutes.',
     'Ten pilot devices across three departments for a fortnight.', 'Continue issuing the previous image; both remain available.', -10, 25, 'no'),
]
write('10-changes.csv',
      ['title', 'change_type', 'status', 'priority', 'impact', 'description', 'reason_for_change',
       'test_plan', 'rollback_plan', 'work_start_datetime', 'work_end_datetime', 'cab_required'],
      [[c[0], c[1], c[2], c[3], c[4], c[5], c[6], c[7], c[8], dt(c[9], 20, 0), dt(c[10], 23, 30), c[11]] for c in CHANGES])

# ---------------------------------------------------------------------- tasks --
TASKS = [
    ('Chase Calderstone for the CRC error report', 'To Do', 'High', -2, 3),
    ('Book Marlbrook engineer for air-con replacement', 'In Progress', 'Urgent', -1, 2),
    ('Write up the month-end index change for the CAB', 'Done', 'Medium', -12, -8),
    ('Order 15 scanner docks for Leeds mezzanine', 'To Do', 'Medium', 0, 7),
    ('Reconcile M365 seat count before the April true-up', 'To Do', 'High', 1, 21),
    ('Draft the quarterly access review evidence pack', 'In Progress', 'Medium', -4, 5),
    ('Decommission the AS400 gateway once traffic is zero', 'Blocked', 'Low', -20, 40),
    ('Archive old ISO images from SRV-FILE01', 'Done', 'Low', -25, -20),
    ('Test the bonded 4G failover at Bristol depot', 'To Do', 'High', 2, 9),
    ('Update the new starter runbook for the Win11 build', 'In Progress', 'Medium', -6, 10),
    ('Review overtime claims for the June payroll cut-off', 'Done', 'High', -30, -26),
    ('Refresh the ITIL certification matrix for the team', 'To Do', 'Low', 3, 30),
    ('Schedule the two DR tests required by CTR-2026-004', 'To Do', 'Medium', 4, 45),
    ('Collect pen test evidence for Glenmoor renewal', 'In Progress', 'Urgent', -3, 4),
    ('Retire the last four out-of-warranty laptops', 'To Do', 'Low', 5, 25),
    ('Publish the phishing awareness KB article', 'Done', 'Medium', -9, -7),
    ('Confirm term-time freeze dates with Cranbourne', 'Done', 'Low', -15, -14),
    ('Rebuild the guest wifi DHCP scope with 4h leases', 'Done', 'Medium', -22, -21),
    ('Get quotes for the SQL 2022 upgrade licensing', 'To Do', 'Medium', 6, 35),
    ('Tidy the CMDB relationships for the EPOS service', 'To Do', 'Low', 7, 40),
]
write('11-tasks.csv',
      ['title', 'description', 'status', 'priority', 'start_date', 'due_date', 'completed_datetime'],
      [[t[0], '', t[1], t[2], d(t[3]), d(t[4]), dt(t[4], 16, 0) if t[1] == 'Done' else ''] for t in TASKS])

# ------------------------------------------------------------------- calendar --
EVENTS = [
    ('Core switch replacement window', 'Change window - SW-HQ-CORE-01 swap', 12, 20, 0, 4, 'HQ comms room', 'no'),
    ('July server patch ring', 'Monthly patch baseline', -18, 19, 0, 6, 'Remote', 'no'),
    ('Wifi AP firmware upgrade (aisle 7 pilot)', 'Pilot before estate-wide rollout', 6, 6, 0, 3, 'Warehouse', 'no'),
    ('Cranbourne term-time change freeze', 'No non-emergency changes', 20, 9, 0, 0, 'Customer site', 'yes'),
    ('Quarterly DR test - Brookmere', 'Contractual DR test 1 of 2', 33, 9, 0, 8, 'Remote', 'no'),
    ('Glenmoor annual pen test', 'External testing window', -20, 9, 0, 40, 'Remote', 'no'),
    ('CAB - weekly', 'Standing change advisory board', 2, 10, 0, 1, 'Meeting room 2', 'no'),
    ('CAB - weekly', 'Standing change advisory board', 9, 10, 0, 1, 'Meeting room 2', 'no'),
    ('Air-con replacement - HQ comms room', 'Marlbrook engineer on site', 14, 8, 0, 6, 'HQ comms room', 'no'),
    ('Leeds depot switch install', 'New PoE switch for mezzanine', 9, 7, 30, 5, 'Leeds depot', 'no'),
    ('Payroll cut-off - overtime claims due', 'All claims approved by 12:00', 4, 9, 0, 3, 'Remote', 'no'),
    ('New starter induction - 12 seasonal staff', 'Accounts and kit handover', 11, 9, 0, 2, 'Warehouse training room', 'no'),
    ('Sage 200 month-end freeze', 'Avoid changes touching finance systems', 28, 0, 0, 0, 'Remote', 'yes'),
    ('ITSM SQL 2022 upgrade (provisional)', 'Awaiting CAB approval', 55, 19, 0, 5, 'Remote', 'no'),
    ('Team training - detection engineering', 'L3 development session', 16, 13, 0, 3, 'Meeting room 1', 'no'),
]
def event_end(day, hour, minute, dur):
    """End timestamp, rolling into the next day when a window runs past midnight."""
    total = hour + dur
    return dt(day + total // 24, total % 24, minute)


write('12-calendar-events.csv',
      ['title', 'description', 'start_datetime', 'end_datetime', 'all_day', 'location'],
      [[e[0], e[1], dt(e[2], e[3], e[4]), event_end(e[2], e[3], e[4], e[5]), e[7], e[6]] for e in EVENTS])

# ------------------------------------------------------------------ knowledge --
KB = [
    ('How to reset MFA when a phone is lost or replaced',
     '<h2>When to use this</h2><p>A user has lost their phone, replaced it, or wiped it, and can no longer approve sign-in requests.</p>'
     '<h2>Steps</h2><ol><li>Confirm the caller\'s identity against two pieces of information held in the HR record.</li>'
     '<li>In the admin centre, open the user and choose <strong>Require re-register MFA</strong>.</li>'
     '<li>Ask the user to sign in on a desktop and follow the enrolment prompt.</li>'
     '<li>Confirm a successful sign-in from the new device before closing the ticket.</li></ol>'
     '<h2>Do not</h2><p>Do not disable MFA to "get them working" - raise a temporary access pass instead.</p>'),
    ('Outlook keeps asking for a password',
     '<h2>Cause</h2><p>Nearly always cached credentials after a policy change, or a legacy connector still in scope.</p>'
     '<h2>Fix</h2><ol><li>Close Outlook.</li><li>Remove the stored entries under Credential Manager > Windows Credentials.</li>'
     '<li>Reopen Outlook and sign in fully.</li><li>If it recurs within a day, check whether the device is compliant.</li></ol>'),
    ('Requesting a new starter account',
     '<h2>What we need</h2><ul><li>Full name and preferred name</li><li>Start date</li><li>Department and manager</li>'
     '<li>Which named role profile to copy access from</li><li>Kit required (laptop, monitor, handset)</li></ul>'
     '<h2>Lead time</h2><p>Five working days for a standard build; ten if new hardware must be ordered.</p>'),
    ('Warehouse scanner loses its WMS session',
     '<h2>Known error</h2><p>AP firmware does not honour fast roam (vendor ref WX-4471).</p>'
     '<h2>Workaround</h2><p>Pin the affected aisle to a single AP channel. Firmware 9.4 fixes it and is scheduled.</p>'),
    ('Depot WAN link troubleshooting',
     '<h2>First checks</h2><ol><li>Confirm whether both depots are affected - if so it is upstream.</li>'
     '<li>Check the router interface for CRC errors.</li><li>Fail over to the bonded 4G profile.</li>'
     '<li>Raise with Calderstone quoting the circuit ID and the error counters.</li></ol>'),
    ('Recovering a deleted file or folder',
     '<h2>Options in order</h2><ol><li>Previous Versions on the share.</li><li>The share\'s recycle bin (30 days).</li>'
     '<li>Offsite backup restore - raise a ticket, 4 hour response.</li></ol><p>Always confirm the exact path and a date to restore from.</p>'),
    ('Standard laptop build (Windows 11 24H2)',
     '<h2>What is included</h2><ul><li>Security baseline</li><li>M365 apps</li><li>Sage 200 client</li>'
     '<li>VPN profile</li><li>Printer discovery by site</li></ul><h2>Build time</h2><p>Around 40 minutes unattended.</p>'),
    ('Reporting a suspicious or phishing email',
     '<h2>Do this</h2><ol><li>Use the Report Phishing button - do not forward it.</li>'
     '<li>If you clicked a link or entered credentials, tell the service desk immediately and change your password.</li></ol>'
     '<p>Reporting a genuine email by mistake causes no harm. Reporting nothing does.</p>'),
    ('Printing to an MFD after the 3.18 firmware update',
     '<h2>Symptom</h2><p>Queues fail with error 0x0000011b after the vendor firmware push.</p>'
     '<h2>Fix</h2><p>Recreate the queue as IPP rather than RAW. Existing RAW queues cannot be repaired in place.</p>'),
    ('Requesting software (including Adobe and Sage)',
     '<h2>Process</h2><ol><li>Raise a service request naming the software and business reason.</li>'
     '<li>Licence availability is checked against the compliance view.</li>'
     '<li>If none is spare, a purchase is raised with the licence reseller - allow ten working days.</li></ol>'),
    ('Out-of-hours support and escalation path',
     '<h2>Cover</h2><p>18:00-08:00 and weekends run through the overflow desk under CTR-2026-009.</p>'
     '<h2>Escalation</h2><p>P1 only: duty manager, then the head of managed services. Tickets hand back at 08:00.</p>'),
    ('Requesting a shared mailbox',
     '<h2>What we need</h2><ul><li>Display name and address</li><li>Owner (must be a named person)</li>'
     '<li>Who needs access, and whether they need Send As</li></ul><p>Created within two working days.</p>'),
]
write('13-knowledge-articles.csv',
      ['title', 'body', 'is_published', 'next_review_date'],
      [[k[0], k[1], 'yes' if i % 6 else 'no', d(120 + i * 5)] for i, k in enumerate(KB)])

# ------------------------------------------------- overtime (needs real emails) --
DEMO_ANALYSTS = ['james.smith@example.com', 'sarah.williams@example.com',
                 'michael.jones@example.com', 'laura.brown@example.com']
OT_REASONS = [
    'P1 depot WAN outage - stayed on the bridge until the circuit was stable',
    'Core switch replacement window',
    'July patch ring - server reboots outside business hours',
    'Emergency reboot of SRV-APP02 after AV quarantine',
    'Weekend cover while the overflow desk contract was being signed',
    'Wifi firmware pilot in the warehouse before the early shift',
    'Air-con failure - stayed to fit and monitor portable cooling',
    'Month-end finance support after the index change',
    'Scanner rollout at Leeds depot ahead of the picking shift',
    'On-call callout - backup job failure investigation',
]
ot = []
for i in range(20):
    email = DEMO_ANALYSTS[i % 4]
    work = -70 + i * 3
    hours = [2.0, 3.5, 4.0, 5.0, 2.5, 6.0][i % 6]
    start_h = 18 if i % 3 else 6
    status = ['approved', 'approved', 'approved', 'pending', 'rejected'][i % 5]
    ot.append([email, d(work), f'{start_h:02d}:00', f'{int(start_h + hours) % 24:02d}:{int((hours % 1) * 60):02d}',
               f'{hours:.2f}', 'weekday' if (i % 7) else 'weekend',
               '1.50' if (i % 7) else '2.00', OT_REASONS[i % len(OT_REASONS)], status])
write('14-overtime-requests.csv',
      ['analyst_email', 'work_date', 'start_time', 'end_time', 'hours', 'overtime_type',
       'rate_multiplier', 'reason', 'status'],
      ot)

# ------------------------------------------------------ certification catalogue --
CATALOGUE = [
    ('ITIL 4 Foundation', 'PeopleCert', 'Service management fundamentals - expected for every analyst.', '', 10, 'yes'),
    ('CompTIA A+', 'CompTIA', 'Core hardware and OS troubleshooting.', 36, 20, 'yes'),
    ('CompTIA Network+', 'CompTIA', 'Networking fundamentals.', 36, 30, 'yes'),
    ('CompTIA Security+', 'CompTIA', 'Security baseline for L2 and above.', 36, 40, 'yes'),
    ('Microsoft MD-102 Endpoint Administrator', 'Microsoft', 'Intune and endpoint management.', 12, 50, 'yes'),
    ('Microsoft AZ-104 Azure Administrator', 'Microsoft', 'Azure administration.', 12, 60, 'yes'),
    ('Microsoft SC-200 Security Operations Analyst', 'Microsoft', 'SOC analyst track - L2/L3.', 12, 70, 'yes'),
    ('Cisco CCNA', 'Cisco', 'Routing and switching - required for depot network work.', 36, 80, 'yes'),
    ('Fortinet NSE 4', 'Fortinet', 'Firewall administration.', 24, 90, 'yes'),
    ('VMware VCP-DCV', 'Broadcom', 'Virtualisation - one holder minimum per shift.', 24, 100, 'yes'),
    ('Certified Ethical Hacker', 'EC-Council', 'Offensive security awareness for L3.', 36, 110, 'yes'),
    ('ISO 27001 Lead Implementer', 'PECB', 'Needed for the certification audit.', 36, 120, 'no'),
]
write('15-certification-catalogue.csv',
      ['name', 'issuer', 'description', 'validity_months', 'display_order', 'is_active'],
      [list(c) for c in CATALOGUE])

# ------------------------------------------------------- certifications held ---
CERTS = [
    (0, 'ITIL 4 Foundation', 'achieved', -900, None, 'GR750193'),
    (0, 'CompTIA Security+', 'achieved', -400, 695, 'COMP001020304050'),
    (0, 'Microsoft SC-200 Security Operations Analyst', 'achieved', -300, 65, 'MSFT-SC200-88213'),
    (0, 'Certified Ethical Hacker', 'in_progress', None, None, ''),
    (1, 'ITIL 4 Foundation', 'achieved', -600, None, 'GR661820'),
    (1, 'CompTIA A+', 'achieved', -1100, 5, 'COMP001020304051'),
    (1, 'CompTIA Network+', 'achieved', -500, 595, 'COMP001020304052'),
    (1, 'Cisco CCNA', 'achieved', -220, 875, 'CSCO13998211'),
    (2, 'ITIL 4 Foundation', 'achieved', -350, None, 'GR701122'),
    (2, 'Microsoft MD-102 Endpoint Administrator', 'achieved', -320, 45, 'MSFT-MD102-40021'),
    (2, 'Fortinet NSE 4', 'achieved', -100, 630, 'FTNT-NSE4-77120'),
    (2, 'Microsoft AZ-104 Azure Administrator', 'planned', None, None, ''),
    (3, 'ITIL 4 Foundation', 'achieved', -120, None, 'GR712004'),
    (3, 'CompTIA A+', 'achieved', -200, 895, 'COMP001020304053'),
    (3, 'VMware VCP-DCV', 'in_progress', None, None, ''),
    (3, 'CompTIA Security+', 'expired', -1500, -400, 'COMP001020304054'),
]
certs = []
for idx, title, status, awarded, expires, cred in CERTS:
    certs.append([
        DEMO_ANALYSTS[idx], title, title, status,
        d(awarded) if awarded is not None else '',
        d(expires) if expires is not None else '',
        cred, '',
        'Renewal booked' if (expires is not None and 0 < expires < 120) else '',
    ])
write('16-certifications-held.csv',
      ['analyst_email', 'title', 'catalogue_name', 'status', 'awarded_date', 'expires_date',
       'credential_id', 'evidence_url', 'notes'],
      certs)

# --------------------------------------------------------- training completed ---
TRAINING = [
    (0, 'Detection engineering workshop (3 days)', 'Glenmoor Security Partners', 'course', -140, 21.0, 2400.00),
    (0, 'KQL for threat hunting', 'Microsoft Learn', 'reading', -60, 6.0, 0.00),
    (0, 'Incident command tabletop exercise', 'Internal', 'on_the_job', -35, 4.0, 0.00),
    (0, 'SC-200 exam', 'PearsonVUE', 'exam', -300, 2.5, 165.00),
    (1, 'Cisco CCNA bootcamp', 'Calderstone Networks Ltd', 'course', -240, 40.0, 3100.00),
    (1, 'Structured cabling practical', 'Marlbrook Facilities', 'on_the_job', -80, 8.0, 0.00),
    (1, 'Wireless site survey fundamentals', 'Vendor webinar', 'webinar', -45, 1.5, 0.00),
    (1, 'CCNA exam', 'PearsonVUE', 'exam', -220, 2.0, 246.00),
    (2, 'Intune deep dive', 'Ashcombe Software Group', 'course', -320, 16.0, 1450.00),
    (2, 'Fortinet NSE 4 training', 'Fortinet', 'course', -110, 24.0, 1900.00),
    (2, 'Autopilot enrolment clinic', 'Internal', 'on_the_job', -25, 3.0, 0.00),
    (2, 'ITSM reporting and KPIs', 'Internal', 'course', -12, 2.0, 0.00),
    (3, 'Service desk customer skills', 'Internal', 'course', -150, 6.0, 0.00),
    (3, 'ITIL 4 Foundation course', 'PeopleCert partner', 'course', -125, 16.0, 1150.00),
    (3, 'vSphere 8 basics', 'Broadcom', 'course', -40, 24.0, 2050.00),
    (3, 'Shadowing L2 on major incidents', 'Internal', 'on_the_job', -20, 12.0, 0.00),
    (0, 'Managed services leadership forum', 'Industry event', 'conference', -70, 8.0, 495.00),
    (1, 'Depot network resilience review', 'Internal', 'on_the_job', -15, 5.0, 0.00),
    (2, 'Phishing simulation debrief', 'Internal', 'webinar', -8, 1.0, 0.00),
    (3, 'Backup and restore practical test', 'Brookmere Cloud Services', 'on_the_job', -5, 4.0, 0.00),
]
write('17-training-completed.csv',
      ['analyst_email', 'title', 'provider', 'training_type', 'completed_date', 'hours', 'cost', 'notes'],
      [[DEMO_ANALYSTS[i], t, p, ty, d(off), f'{h:.2f}', f'{c:.2f}', ''] for i, t, p, ty, off, h, c in TRAINING])

print('\nWritten to', OUT)
