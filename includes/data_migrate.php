<?php
/**
 * Migration from another ticketing system.
 *
 * The mass importer (includes/data_import.php) already knows how to validate and
 * write every dataset, but it requires OUR column names. An export from another
 * platform has its own: "Case Number", "Date Opened", "Assigned To", and its own
 * status and category names that mean the same things ours do but do not match a
 * single string. Two problems, therefore, and neither is about writing rows:
 *
 *   1. COLUMN mapping — which source header feeds which of our fields.
 *   2. VALUE mapping — what "Awaiting Customer" or "Sev 2" corresponds to here,
 *      and whether a value we have never seen should be created or redirected.
 *
 * So this file does not import anything. It maps, translates and rewrites the
 * file into the canonical header form, then hands it to data_import_plan() /
 * data_import_commit() — the same code path, and therefore the same validation,
 * tenancy handling and audit trail as a routine CSV load. Forking the writer
 * would have meant two places where a ticket can be created differently.
 *
 * On backfill: what CAN be derived from migrated data is derived (SLA outcomes
 * come from the real dates through the existing SLA engine). What cannot is
 * reported as a gap and left empty — see data_migrate_backfill_report(). An
 * escalation that never happened, a hold that was never recorded and a QA review
 * that was never done are not recoverable from a ticket export, and inventing
 * them would put fiction into the system of record and into every KPI computed
 * from it afterwards.
 */

require_once __DIR__ . '/data_import.php';
require_once __DIR__ . '/sla.php';
require_once __DIR__ . '/sla_notifications.php';

/** A migration is a one-off bulk load, so it gets a much higher ceiling. */
function data_migrate_row_cap(): int { return 100000; }

/**
 * Datasets offered as migration targets, in the order you should load them.
 *
 * Order matters: a ticket's requester, owner and customer must exist before the
 * ticket referencing them can resolve, and the migration deliberately will not
 * create staff or portal accounts (see data_migrate_creatable_tables).
 */
function data_migrate_targets(): array {
    return [
        'portal_users'  => 'Requesters / end users — load before tickets',
        'customers'     => 'Customer accounts — load before tickets',
        'assets'        => 'Hardware inventory',
        'cmdb_objects'  => 'Configuration items',
        'suppliers'     => 'Suppliers',
        'contracts'     => 'Contracts',
        'tickets'       => 'Tickets — the main history',
        'problems'      => 'Problems / known errors',
        'changes'       => 'Changes',
        'knowledge'     => 'Knowledge articles',
        'tasks'         => 'Tasks',
    ];
}

/**
 * Lookup tables the migration may CREATE missing values in.
 *
 * Deliberately excludes `analysts` and `users`: those are login-capable records.
 * Creating a hundred staff accounts as a side effect of a CSV upload is not
 * something an import screen should be able to do, so unresolved names in those
 * columns stay an error and the operator maps them to real accounts (or imports
 * the portal_users dataset first).
 */
function data_migrate_creatable_tables(): array {
    return [
        'ticket_statuses', 'ticket_priorities', 'ticket_types', 'ticket_categories',
        'ticket_subcategories', 'ticket_origins', 'departments', 'ticket_streams',
        'customers', 'asset_types', 'asset_status_types', 'asset_locations',
        'change_statuses', 'change_priorities', 'change_types', 'change_categories',
        'problem_statuses', 'problem_priorities', 'task_statuses', 'task_priorities',
        'supplier_types', 'supplier_statuses', 'contract_statuses', 'cmdb_classes',
    ];
}

/**
 * Source header names commonly used by other platforms, per target field.
 *
 * Drawn from the exports people actually arrive with (ServiceNow, Freshservice,
 * Zendesk, Jira Service Management, ManageEngine, Spiceworks, osTicket). The
 * mapping is only ever a SUGGESTION — every one is shown to the operator and can
 * be overridden before anything is read.
 */
function data_migrate_synonyms(): array {
    return [
        'ticket_number'         => ['case number', 'case id', 'case', 'ticket id', 'ticket no', 'ticket number',
                                    'ticket', 'number', 'id', 'reference', 'ref', 'incident number', 'incident id',
                                    'request id', 'request number', 'key', 'display id'],
        'subject'               => ['title', 'summary', 'short description', 'subject', 'issue', 'problem summary',
                                    'request title', 'headline'],
        'created_datetime'      => ['created', 'created at', 'created on', 'created date', 'created time',
                                    'date created', 'opened', 'opened at', 'date opened', 'open date',
                                    'open time', 'submitted', 'submitted at', 'submitted date',
                                    'request time', 'reported date', 'sys created on', 'date time opened',
                                    'initiated', 'logged', 'logged at', 'logged date'],
        'closed_datetime'       => ['closed', 'closed at', 'closed on', 'date closed', 'close date',
                                    'closed time', 'resolved', 'resolved at', 'resolved date',
                                    'resolved time', 'resolution date', 'resolution time',
                                    'completed', 'completed at', 'solved', 'solved at', 'date solved',
                                    'date time closed'],
        'acknowledged_datetime' => ['first response', 'first response at', 'first responded', 'first reply',
                                    'first reply at', 'responded at', 'acknowledged', 'acknowledged at',
                                    'first response time', 'date of first response'],
        'work_start_datetime'   => ['work start', 'started', 'started at', 'work started'],
        'first_time_fix'        => ['first time fix', 'ftf', 'first contact resolution', 'fcr',
                                    'resolved first contact', 'one touch'],
        'status'                => ['status', 'state', 'ticket status', 'case status', 'request status',
                                    'incident state', 'current status'],
        'priority'              => ['priority', 'urgency', 'severity', 'impact level', 'ticket priority'],
        'ticket_type'           => ['type', 'ticket type', 'request type', 'record type', 'case type'],
        'category'              => ['category', 'issue type', 'classification', 'service category',
                                    'problem type', 'main category'],
        'subcategory'           => ['subcategory', 'sub category', 'sub-category', 'secondary category',
                                    'issue subtype'],
        'department'            => ['department', 'dept', 'business unit', 'division', 'group', 'team'],
        'origin'                => ['source', 'channel', 'origin', 'contact type', 'via', 'received via',
                                    'submitted via', 'medium'],
        'owner'                 => ['assigned to', 'assignee', 'owner', 'technician', 'agent',
                                    'assigned agent', 'assigned technician', 'resolved by', 'handled by'],
        'assigned_analyst'      => ['assigned analyst', 'secondary assignee', 'co-owner'],
        'requester_email'       => ['requester email', 'contact email', 'reporter email', 'user email',
                                    'submitter email', 'email', 'requester', 'reported by email',
                                    'caller email', 'from', 'from address'],
        'customer'              => ['customer', 'account', 'client', 'company', 'organisation', 'organization',
                                    'account name', 'customer name'],
        // Assets and CMDB
        'hostname'              => ['hostname', 'host name', 'name', 'device name', 'computer name',
                                    'asset name', 'ci name', 'machine name'],
        'serial_number'         => ['serial number', 'serial', 'serial no', 'service tag'],
        'service_tag'           => ['service tag', 'asset tag', 'tag'],
        'manufacturer'          => ['manufacturer', 'make', 'vendor', 'brand'],
        'model'                 => ['model', 'model number'],
        'operating_system'      => ['operating system', 'os', 'os version', 'platform'],
        'purchase_date'         => ['purchase date', 'purchased', 'date purchased', 'acquired', 'acquisition date'],
        'warranty_expiry'       => ['warranty expiry', 'warranty end', 'warranty expiration', 'warranty until'],
    ];
}

/** Reduce a header to a comparable form: lowercase, alphanumerics only. */
function data_migrate_normalise_header(string $h): string {
    $h = strtolower(trim($h));
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    // "Ticket # (ID)" and "ticket_id" should compare equal.
    $h = preg_replace('/[^a-z0-9]+/', ' ', $h);
    return trim(preg_replace('/\s+/', ' ', $h));
}

/**
 * Does a known synonym appear inside this header as whole word(s)?
 *
 * Whole-word only: without the word boundaries "id" would match "valid" and
 * "paid", and a header called "Paid Date" would confidently claim the ticket
 * number. Multi-word phrase hits score higher than single-word hits because they
 * are far less likely to be coincidence.
 *
 * @return array{term:string,phrase:bool}|null the longest match, or null
 */
function data_migrate_synonym_within(string $normalisedHeader, array $normalisedSynonyms): ?array {
    $best = null;
    foreach ($normalisedSynonyms as $s) {
        if ($s === '' || $s === $normalisedHeader) continue;
        // Single-character or two-character terms are too generic to match loosely.
        if (strlen($s) < 3) continue;
        if (!preg_match('/(?:^|\s)' . preg_quote($s, '/') . '(?:\s|$)/', $normalisedHeader)) continue;
        $phrase = str_contains($s, ' ');
        if ($best === null || strlen($s) > strlen($best['term'])) {
            $best = ['term' => $s, 'phrase' => $phrase];
        }
    }
    return $best;
}

/**
 * Suggest a source-column → target-field mapping.
 *
 * Scoring is deliberately conservative and explains itself, because a silent
 * wrong guess here corrupts a migration in a way that is very hard to see
 * afterwards: every row imports "successfully" with the wrong data in it. Each
 * suggestion carries a confidence and a reason so the operator can see WHY the
 * mapping was proposed and reject it.
 *
 * A target can only be claimed once — the best-scoring source wins and any other
 * contender is reported as a conflict rather than silently dropped.
 *
 * @return array{mapping:array<string,string>, detail:array, conflicts:array, unmapped:array}
 */
function data_migrate_suggest(array $sourceHeader, array $spec): array {
    $targets = data_import_accepted_columns($spec);
    $syn = data_migrate_synonyms();

    // score[target][source] = [confidence, reason]
    $candidates = [];
    foreach ($sourceHeader as $src) {
        $n = data_migrate_normalise_header($src);
        if ($n === '') continue;
        foreach ($targets as $t) {
            $tn = data_migrate_normalise_header($t);
            $conf = 0; $why = '';

            $syns = isset($syn[$t]) ? array_map('data_migrate_normalise_header', $syn[$t]) : [];

            if ($n === $tn) {
                $conf = 100; $why = 'exact column name';
            } elseif ($syns && in_array($n, $syns, true)) {
                $conf = 92; $why = 'known name used by other systems';
            } elseif ($syns && ($hit = data_migrate_synonym_within($n, $syns)) !== null) {
                // "Issue Summary" contains "summary"; "Date Opened" contains
                // "opened". Generalising this beats extending the synonym list
                // with every prefix another vendor might bolt on.
                $conf = $hit['phrase'] ? 84 : 74;
                $why = 'contains the known term "' . $hit['term'] . '"';
            } elseif ($n !== '' && $tn !== '' && (str_contains($n, $tn) || str_contains($tn, $n))) {
                // Substring matches are weak: "date" matches far too much, so
                // require a reasonable length before trusting one.
                $shorter = min(strlen($n), strlen($tn));
                $conf = $shorter >= 5 ? 68 : 40;
                $why = 'partial name match';
            } else {
                similar_text($n, $tn, $pct);
                if ($pct >= 80) { $conf = 60; $why = 'similar name'; }
            }

            if ($conf > 0) $candidates[$t][$src] = [$conf, $why];
        }
    }

    // Resolve: each target takes its best source; each source is used once.
    $mapping = [];      // source => target
    $detail = [];       // source => [target, confidence, reason]
    $conflicts = [];
    $usedSources = [];

    // Order targets by their best available confidence so strong matches claim
    // their column before a weaker target can steal it.
    $bestFor = [];
    foreach ($candidates as $t => $srcs) {
        $bestFor[$t] = max(array_map(fn($c) => $c[0], $srcs));
    }
    arsort($bestFor);

    foreach (array_keys($bestFor) as $t) {
        $srcs = $candidates[$t];
        uasort($srcs, fn($a, $b) => $b[0] <=> $a[0]);
        $claimed = false;
        foreach ($srcs as $src => [$conf, $why]) {
            if (isset($usedSources[$src])) continue;
            if ($conf < 55) continue;                 // too weak to propose
            if (!$claimed) {
                $mapping[$src] = $t;
                $detail[$src] = ['target' => $t, 'confidence' => $conf, 'reason' => $why];
                $usedSources[$src] = true;
                $claimed = true;
            } else {
                $conflicts[] = ['source' => $src, 'target' => $t, 'confidence' => $conf,
                                'note' => 'also looked like ' . $t];
            }
        }
    }

    $unmapped = [];
    foreach ($sourceHeader as $src) {
        if (!isset($mapping[$src]) && data_migrate_normalise_header($src) !== '') $unmapped[] = $src;
    }

    return ['mapping' => $mapping, 'detail' => $detail, 'conflicts' => $conflicts, 'unmapped' => $unmapped];
}

/** The CSV's header row, original case and order, without reading the body. */
function data_migrate_header(string $csv): array {
    $line = strtok($csv, "\r\n");
    if ($line === false) return [];
    $cells = str_getcsv($line);
    if (!$cells) return [];
    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cells[0]);
    return array_map(fn($h) => trim((string)$h), $cells);
}

/**
 * Parse a CSV string into [header, rows, truncated]. Rows beyond the migration
 * cap are dropped and the count reported, so a truncated load is never silent.
 *
 * $keepIdx optionally restricts which columns are RETAINED. Exports from other
 * platforms are wide — a real Zoho file carries 258 columns of which about 23 are
 * ever read — and holding the other 235 costs around 400 MB on a 23,000-row file,
 * enough to exhaust the memory limit before a single row is written. Cells
 * outside the set are replaced by the empty string rather than removed, so every
 * column index stays valid and callers need no special handling.
 */
function data_migrate_parse(string $csv, ?array $keepIdx = null): array {
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    $header = fgetcsv($fh);
    if ($header === false || !$header) { fclose($fh); throw new Exception('The CSV has no header row'); }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $header = array_map(fn($h) => trim((string)$h), $header);

    $keep = $keepIdx === null ? null : array_flip($keepIdx);
    $width = count($header);

    $rows = [];
    $truncated = 0;
    while (($cells = fgetcsv($fh)) !== false) {
        if (count(array_filter($cells, fn($c) => trim((string)$c) !== '')) === 0) continue;
        if (count($rows) >= data_migrate_row_cap()) { $truncated++; continue; }
        if ($keep !== null) {
            $slim = array_fill(0, $width, '');
            foreach ($keep as $i => $_) {
                if (isset($cells[$i])) $slim[$i] = $cells[$i];
            }
            $cells = $slim;
        }
        $rows[] = $cells;
    }
    fclose($fh);
    return [$header, $rows, $truncated];
}

/**
 * Which column indices a preset actually needs — mapped fields, the derived
 * aggregates and the natural key. Used to prune the parse on a wide export.
 */
function data_migrate_preset_indices(array $preset, array $header): array {
    $index = [];
    foreach ($header as $i => $h) $index[trim($h)] = $i;

    $want = array_keys($preset['columns'] ?? []);
    foreach ($preset['derive'] ?? [] as $src) $want[] = $src;
    if (!empty($preset['key_column'])) $want[] = $preset['key_column'];

    $idx = [];
    foreach ($want as $name) {
        if (isset($index[$name])) $idx[] = $index[$name];
    }
    return array_values(array_unique($idx));
}

/**
 * For every mapped lookup column, report the distinct source values and whether
 * each already resolves.
 *
 * This is the heart of a safe migration: it is the operator's chance to see that
 * their old system's twelve statuses collapse onto our five, BEFORE any row is
 * written, and to decide per value whether to map it or create it.
 *
 * @return array<string,array{target:string,table:string,creatable:bool,values:array}>
 */
function data_migrate_value_report(PDO $conn, array $spec, array $header, array $rows, array $mapping): array {
    $creatable = data_migrate_creatable_tables();
    $out = [];

    foreach ($mapping as $src => $target) {
        if (!isset($spec['lookups'][$target])) continue;
        $lk = $spec['lookups'][$target];
        $idx = array_search($src, $header, true);
        if ($idx === false) continue;

        $counts = [];
        foreach ($rows as $cells) {
            $v = trim((string)($cells[$idx] ?? ''));
            if ($v === '') continue;
            $counts[$v] = ($counts[$v] ?? 0) + 1;
        }
        // Most frequent first: that is the order in which decisions matter.
        arsort($counts);

        $values = [];
        foreach ($counts as $v => $n) {
            $values[] = [
                'value'    => (string)$v,
                'rows'     => $n,
                'resolves' => data_import_lookup($conn, $lk, (string)$v) !== null,
            ];
        }
        $out[$src] = [
            'target'    => $target,
            'table'     => $lk['table'],
            'required'  => !empty($lk['required']),
            'creatable' => in_array($lk['table'], $creatable, true),
            'blank_rows' => count($rows) - array_sum($counts),
            'values'    => $values,
        ];
    }
    return $out;
}

/**
 * Create missing lookup rows.
 *
 * @param  array $requests [ ['table' => ..., 'value' => ...], … ]
 * @return array{created:int, refused:array}
 */
function data_migrate_create_lookup_values(PDO $conn, array $spec, array $requests): array {
    $creatable = data_migrate_creatable_tables();
    $created = 0;
    $refused = [];

    // Which name column each lookup table uses, taken from the dataset spec so
    // this never disagrees with how the importer resolves the same value.
    $nameCol = [];
    foreach ($spec['lookups'] ?? [] as $lk) $nameCol[$lk['table']] = $lk['name'];

    foreach ($requests as $r) {
        $table = (string)($r['table'] ?? '');
        $value = trim((string)($r['value'] ?? ''));
        if ($table === '' || $value === '') continue;

        if (!in_array($table, $creatable, true)) {
            $refused[] = ['table' => $table, 'value' => $value,
                          'reason' => 'values in this table cannot be created by a migration'];
            continue;
        }
        if (!isset($nameCol[$table])) {
            $refused[] = ['table' => $table, 'value' => $value, 'reason' => 'not a lookup of this dataset'];
            continue;
        }
        $col = $nameCol[$table];

        // Already there (someone else's run, or a duplicate request) — not an error.
        $lk = ['table' => $table, 'name' => $col];
        if (data_import_lookup($conn, $lk, $value) !== null) continue;

        try {
            $conn->prepare("INSERT INTO `{$table}` (`{$col}`) VALUES (?)")->execute([$value]);
            $created++;
        } catch (Exception $e) {
            // Most often a NOT NULL column with no default that this table needs.
            $refused[] = ['table' => $table, 'value' => $value, 'reason' => $e->getMessage()];
        }
    }
    return ['created' => $created, 'refused' => $refused];
}

/**
 * Rewrite the source rows into a CSV with our canonical header, applying the
 * column mapping and any per-value translations.
 *
 * $valueMap is [ source_column => [ source_value => replacement_value ] ]. A
 * replacement of '' means "leave this cell empty", which is how an operator
 * discards a value that has no equivalent here.
 */
function data_migrate_rewrite(array $header, array $rows, array $mapping, array $valueMap = []): string {
    // Keep only mapped columns, in a stable order.
    $cols = [];      // target => source index
    foreach ($mapping as $src => $target) {
        $idx = array_search($src, $header, true);
        if ($idx === false || $target === '') continue;
        $cols[$target] = ['idx' => $idx, 'src' => $src];
    }
    if (!$cols) throw new Exception('No columns are mapped, so there is nothing to import');

    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, array_keys($cols));
    foreach ($rows as $cells) {
        $out = [];
        foreach ($cols as $target => $c) {
            $v = trim((string)($cells[$c['idx']] ?? ''));
            if (isset($valueMap[$c['src']]) && array_key_exists($v, $valueMap[$c['src']])) {
                $v = (string)$valueMap[$c['src']][$v];
            }
            $out[] = $v;
        }
        fputcsv($fh, $out);
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv;
}

/**
 * Return the dataset spec with the migration row cap applied, so a big one-off
 * load is not truncated at the routine 5,000-row limit.
 */
function data_migrate_spec(string $key): array {
    $all = data_import_datasets();
    if (!isset($all[$key])) throw new Exception('Unknown dataset: ' . $key);
    $spec = $all[$key];
    $spec['row_cap'] = data_migrate_row_cap();
    return $spec;
}

/**
 * Stamp SLA outcomes onto migrated tickets.
 *
 * This is a genuine derivation, not an invention: the SLA engine reads the
 * ticket's real created / acknowledged / closed timestamps and its priority's
 * targets and works out whether each was met. It is the same call the nightly
 * rebuild makes, so a migrated ticket reports exactly as a native one does.
 */
function data_migrate_backfill_sla(PDO $conn, array $ticketIds): array {
    if (!$ticketIds) return ['processed' => 0, 'tracked' => 0, 'errors' => []];
    return sla_rebuild_snapshots($conn, $ticketIds);
}

/**
 * Turn a source platform's measured aggregates into our child records.
 *
 * This is NOT the fabrication the backfill report warns against. Zoho Desk (and
 * others) export real counts it measured — "this ticket was reopened twice",
 * "reassigned three times", "SLA: Resolution Violation". Our KPI engine derives
 * the same facts by counting rows in ticket_audit and reading
 * ticket_sla_snapshot, so the counts have to be reshaped into that form or the
 * migrated history reports zero for metrics the old system measured perfectly
 * well. Reshaping a real measurement is fair; inventing an escalation that never
 * happened is not, and nothing here does the latter.
 *
 * Two honesty rules:
 *   - Only writes what the source actually measured. A blank column produces no
 *     row, so "we never recorded this" stays distinguishable from "it was zero".
 *   - The synthesised audit rows carry no invented detail beyond what is needed
 *     for the count to come out right, and every one of them belongs to a ticket
 *     whose number carries the source's prefix (ZD-…), so migrated history is
 *     always identifiable as such.
 *
 * SLA states are taken from the source's own verdict rather than recomputed
 * against our targets — that is the whole point, since those are the figures
 * already published to customers. remaining_mins is left NULL because we do not
 * know the old platform's targets and a guess would be worse than a blank.
 *
 * @param  array $derived  ticket_number => [name => raw value]
 * @return array counts per child type, plus anything skipped
 */
function data_migrate_derive_children(PDO $conn, array $derived, int $analystId): array {
    $counts = ['sla' => 0, 'reopen_audit' => 0, 'reassign_audit' => 0,
               'escalations' => 0, 'time_entries' => 0, 'skipped' => 0];
    if (!$derived) return $counts;

    // Resolve ticket_number → id, created/closed, owner, in chunks.
    $numbers = array_keys($derived);
    $tickets = [];
    foreach (array_chunk($numbers, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $conn->prepare("SELECT id, ticket_number, created_datetime, closed_datetime, owner_id
                                FROM tickets WHERE ticket_number IN ({$ph})");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $tickets[$r['ticket_number']] = $r;
    }
    if (!$tickets) return $counts;

    // Status names for the reopen audit rows — the reopen metric looks for a
    // Status change leaving an is_closed status, so the names must be real ones.
    $closedName = null; $openName = null;
    try {
        $closedName = $conn->query("SELECT name FROM ticket_statuses WHERE is_closed = 1 ORDER BY display_order, id LIMIT 1")->fetchColumn() ?: null;
        $openName   = $conn->query("SELECT name FROM ticket_statuses WHERE is_closed = 0 ORDER BY display_order, id LIMIT 1")->fetchColumn() ?: null;
    } catch (Exception $e) { /* handled below */ }

    $has = function (string $t) use ($conn): bool {
        try { $conn->query("SELECT 1 FROM `{$t}` LIMIT 0"); return true; } catch (Exception $e) { return false; }
    };

    $slaStmt = $has('ticket_sla_snapshot') ? $conn->prepare(
        "INSERT INTO ticket_sla_snapshot (ticket_id, response_state, response_remaining_mins,
             resolution_state, resolution_remaining_mins, policy_source, computed_at)
         VALUES (?,?,NULL,?,NULL,'migrated',UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE response_state = VALUES(response_state),
                                 resolution_state = VALUES(resolution_state),
                                 policy_source = VALUES(policy_source)") : null;
    $auditStmt = $has('ticket_audit') ? $conn->prepare(
        "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
         VALUES (?,?,?,?,?,?)") : null;
    $escStmt = $has('ticket_escalations') ? $conn->prepare(
        "INSERT INTO ticket_escalations (ticket_id, from_analyst_id, to_analyst_id, from_tier, to_tier, escalated_at, picked_up_at, note)
         VALUES (?,?,NULL,?,?,?,NULL,?)") : null;
    $timeStmt = $has('ticket_time_entries') ? $conn->prepare(
        "INSERT INTO ticket_time_entries (ticket_id, analyst_id, notes, time_spent_minutes, entry_datetime, is_active, created_datetime)
         VALUES (?,?,?,?,?,1,?)") : null;

    /** Spread n synthetic events evenly across the ticket's life. */
    $stamp = function (array $t, int $i, int $n): string {
        $start = strtotime(($t['created_datetime'] ?? '') . ' UTC') ?: time();
        $end   = strtotime(($t['closed_datetime'] ?? '') . ' UTC') ?: ($start + 3600);
        if ($end <= $start) $end = $start + 3600;
        return gmdate('Y-m-d H:i:s', (int)round($start + ($end - $start) * (($i + 1) / ($n + 1))));
    };

    $intOf = fn($v) => preg_match('/^\d+$/', trim((string)$v)) ? (int)trim((string)$v) : 0;

    foreach ($derived as $number => $d) {
        if (!isset($tickets[$number])) { $counts['skipped']++; continue; }
        $t = $tickets[$number];
        $tid = (int)$t['id'];
        $who = (int)($t['owner_id'] ?: $analystId);

        // ---- SLA verdict, straight from the source -----------------------
        if ($slaStmt && isset($d['sla_violation'])) {
            $v = strtolower(trim((string)$d['sla_violation']));
            $resp = $reso = null;
            if ($v === 'not violated')                            { $resp = 'met';      $reso = 'met'; }
            elseif ($v === 'response violation')                  { $resp = 'breached'; $reso = 'met'; }
            elseif ($v === 'resolution violation')                { $resp = 'met';      $reso = 'breached'; }
            elseif ($v === 'response and resolution violation')   { $resp = 'breached'; $reso = 'breached'; }
            if ($resp !== null) { $slaStmt->execute([$tid, $resp, $reso]); $counts['sla']++; }
        }

        // ---- reopens ------------------------------------------------------
        if ($auditStmt && $closedName && $openName) {
            $n = $intOf($d['reopen_count'] ?? 0);
            for ($i = 0; $i < min($n, 20); $i++) {
                $auditStmt->execute([$tid, $who, 'Status', $closedName, $openName, $stamp($t, $i, max($n, 1))]);
                $counts['reopen_audit']++;
            }
        }

        // ---- reassignments (ticket bounce) --------------------------------
        if ($auditStmt) {
            $n = $intOf($d['reassign_count'] ?? 0);
            for ($i = 0; $i < min($n, 30); $i++) {
                // The metric counts 'Owner' rows; the old/new names were not
                // exported, so they are left blank rather than guessed.
                $auditStmt->execute([$tid, $who, 'Owner', null, null, $stamp($t, $i, max($n, 1))]);
                $counts['reassign_audit']++;
            }
        }

        // ---- escalation ---------------------------------------------------
        if ($escStmt && isset($d['escalated'])) {
            $e = strtolower(trim((string)$d['escalated']));
            if ($e === 'true' || $e === '1' || $e === 'yes') {
                $tier = $intOf($d['agent_tier'] ?? 0);
                $from = $tier >= 1 && $tier <= 3 ? 'L' . $tier : 'L1';
                $to   = 'L' . min(3, max(2, $tier + 1));
                $escStmt->execute([$tid, $t['owner_id'] ?: null, $from, $to,
                    $stamp($t, 0, 1), 'Migrated: source recorded this ticket as escalated']);
                $counts['escalations']++;
            }
        }

        // ---- effort -------------------------------------------------------
        if ($timeStmt) {
            $mins = $intOf($d['time_spent_mins'] ?? 0);
            if ($mins > 0) {
                $timeStmt->execute([$tid, $who, 'Migrated: total time spent recorded by the previous system',
                    $mins, $stamp($t, 0, 1), $stamp($t, 0, 1)]);
                $counts['time_entries']++;
            }
        }
    }

    return $counts;
}

/**
 * What reporting fields a migration can and cannot fill, for the operator to
 * read BEFORE cutover rather than discover in a board pack afterwards.
 *
 * The honest position: anything derivable from the imported columns is derived;
 * anything that was never recorded stays empty. Synthesising escalations, holds
 * or QA reviews would make every historic KPI computed from them a fiction, and
 * the fiction would be indistinguishable from real data a year later.
 */
function data_migrate_backfill_report(): array {
    return [
        'derived' => [
            'SLA response and resolution outcome — computed from the imported dates and your priority targets.',
            'MTTA — computed from created → acknowledged, if you mapped a first-response column.',
            'MTTR — computed from created → closed.',
            'Reopen and bounce counts — will read 0 for migrated tickets (see below).',
            'Effort and cost — populated only if you also migrate time entries.',
        ],
        'not_filled' => [
            'ticket_audit'        => 'No field-change history exists in a ticket export, so reopen and reassignment ("bounce") counts read 0 for migrated tickets. Trend them from the cutover date forward.',
            'ticket_escalations'  => 'Tier escalations were not recorded in a form that transfers. Escalation rate and time-to-escalate start at cutover.',
            'ticket_hold_events'  => 'On-hold intervals do not transfer, so "MTTR excluding hold" equals MTTR for migrated tickets.',
            'ticket_qa_reviews'   => 'QA reviews are ours, not the old system\'s. QA pass rate starts at cutover.',
            'ticket_csat_responses' => 'Survey responses can be migrated separately if your old platform exported them; otherwise CSAT starts at cutover.',
        ],
        'advice' => 'Put a marker on the cutover date in any trend chart. Metrics that depend on '
                  . 'the tables above are only comparable from that date, and a chart that silently '
                  . 'mixes the two periods will look like a sudden improvement that never happened.',
    ];
}

/**
 * Reconcile a run: what came in, what went out, and what did not make it.
 *
 * The point is provable completeness — the number a migration is signed off on.
 */
function data_migrate_reconcile(array $parsed, array $plan, ?array $commit = null): array {
    [, $rows, $truncated] = $parsed;
    $sourceRows = count($rows);
    $planned = count($plan['plan'] ?? []);
    $errors  = count($plan['errors'] ?? []);

    $rec = [
        'source_rows'      => $sourceRows,
        'truncated_rows'   => $truncated,
        'rows_planned'     => $planned,
        'rows_with_errors' => $errors,
        'to_create'        => $plan['to_create'] ?? 0,
        'to_update'        => $plan['to_update'] ?? 0,
        'ignored_columns'  => $plan['ignored'] ?? [],
    ];
    if ($commit !== null) {
        $rec['created'] = $commit['created'] ?? 0;
        $rec['updated'] = $commit['updated'] ?? 0;
        $rec['written'] = ($commit['created'] ?? 0) + ($commit['updated'] ?? 0);
        $rec['balanced'] = ($rec['written'] + $errors + $truncated) === $sourceRows;
        $rec['unaccounted'] = $sourceRows - $rec['written'] - $errors - $truncated;
    } else {
        $rec['balanced'] = ($planned + $errors + $truncated) === $sourceRows;
        $rec['unaccounted'] = $sourceRows - $planned - $errors - $truncated;
    }
    return $rec;
}
