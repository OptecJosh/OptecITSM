<?php
/**
 * Named source presets for the migration tool.
 *
 * The generic mapper (includes/data_migrate.php) guesses a column mapping from
 * names. That is the right default for an unknown file, but a known platform can
 * do far better than guessing, because three things need to be right and only
 * the first is a naming problem:
 *
 *   1. WHICH column feeds which field — and which of several near-duplicates to
 *      prefer. A Zoho export can carry both "Priority" and "Priority (Corrected)";
 *      only one of them is the cleaned-up column the customer actually curates.
 *   2. UNITS. Zoho reports durations in milliseconds. Loaded as minutes, a
 *      30-hour resolution becomes 108,960,000 minutes and every derived metric
 *      is nonsense — silently, because nothing about the number looks wrong.
 *   3. DATE ORDER. "13/01/2025" is unambiguous but "01/02/2025" is not, and PHP's
 *      strtotime() reads the latter as 1 February or 2 January depending on
 *      separators. A preset states the order outright instead of leaving a
 *      migration's entire timeline to a coin toss.
 *
 * Transforms run during the rewrite, so the importer still receives canonical
 * ISO dates and plain integers and needs to know nothing about any of this.
 */

/** Preset key => definition. */
function migrate_sources(): array {
    return [
        'zoho_desk' => [
            'label'   => 'Zoho Desk',
            'dataset' => 'tickets',
            'notes'   => 'Never open a Zoho export in Excel before importing — it rewrites the '
                       . '18-digit IDs as scientific notation (1.25E+17) and they cannot be '
                       . 'recovered. Use a text editor, or Data > From Text/CSV with every column '
                       . 'set to Text. Include Agent Name and Agent Tier if your export offers '
                       . 'them: the raw Ticket Owner column is a numeric id with no name attached, '
                       . 'so without them ownership and tier splits are lost. Day-first dates and '
                       . 'millisecond durations are converted for you.',

            // Natural key. Zoho's own "ID" is an 18-digit number that Excel
            // destroys; Request Id is short, stable and survives.
            'key_column' => 'Request Id',
            'key_prefix' => 'ZD-',

            // source column => our field. Cleaned/curated columns are preferred
            // over the raw ones wherever the export carries both.
            'columns' => [
                'Request Id'                          => 'ticket_number',
                'Subject'                             => 'subject',
                'Created Time'                        => 'created_datetime',
                'Ticket Closed Time'                  => 'closed_datetime',
                'Agent Responded Time'                => 'acknowledged_datetime',
                'Status'                              => 'status',
                'Priority (Corrected)'                => 'priority',
                'ITIL Type (Filled)'                  => 'ticket_type',
                'New Taxonomy Category'               => 'category',
                'New Taxonomy Sub Category'           => 'subcategory',
                'Channel'                             => 'origin',
                'Customer'                            => 'customer',
                'Email'                               => 'requester_email',
                'Agent Name'                          => 'owner',
                'Team (Amended)'                      => 'department',
            ],

            // Fields the importer has no column for, but which carry real
            // measured values we can turn into child records after the load.
            // See data_migrate_derive_children().
            'derive' => [
                'sla_violation'   => 'SLA Violation Type',
                'reopen_count'    => 'Number of Reopen',
                'reassign_count'  => 'Number of Reassign',
                'escalated'       => 'Is Escalated',
                'time_spent_mins' => 'Total Time Spent',
                'agent_tier'      => 'Agent Tier',
                'resolution_ms'   => 'Resolution Time in Business Hours',
                'response_ms'     => 'First Response Time in Business Hours',
            ],

            'transforms' => [
                'Created Time'         => 'date_dmy',
                'Ticket Closed Time'   => 'date_dmy',
                'Agent Responded Time' => 'date_dmy',
            ],

            // Value translations, per OUR field name. '' discards the value and
            // leaves the cell blank, which is how a source value with no
            // equivalent here is dropped without failing the row.
            'values' => [
                'priority' => [
                    'P1 - Critical'        => 'P1',
                    'P2 - High'            => 'P2',
                    'P3 - Medium'          => 'P3',
                    'Medium'               => 'P3',
                    'P3'                   => 'P3',
                    'P4 - Event'           => 'P4',
                    'P4 - Service Request' => 'P4',
                    'P4 - Low'             => 'P4',
                    'P5 - Question'        => 'P4',
                    'P1'                   => 'P1',
                    'P2'                   => 'P2',
                    'N/A'                  => '',
                    'null'                 => '',
                ],
                'status' => [
                    'Closed'            => 'Closed',
                    'Closed Completed'  => 'Closed',
                    'Closed Incomplete' => 'Closed',
                    'Auto-Closed'       => 'Closed',
                    'Resolved'          => 'Resolved',
                    'Open'              => 'Open',
                    'On Hold'           => 'On Hold',
                    'Awaiting Customer' => 'On Hold',
                    'Awaiting Engineer' => 'Open',
                    'Awaiting Vendor'   => 'On Hold',
                    // Low-volume states seen in the real export.
                    'Customer Review Complete' => 'Closed',
                    'Investigating'            => 'Open',
                    'Awaiting Deployment'      => 'On Hold',
                ],
                'origin' => [
                    'Email'            => 'Email',
                    'Phone'            => 'Phone',
                    'Web'              => 'Web',
                    'Chat'             => 'Chat',
                    'Automation'       => 'Automation',
                    'Security Logging' => 'Automation',
                ],
                'ticket_type' => [
                    'Event'           => 'Event',
                    'Incident'        => 'Incident',
                    'Service Request' => 'Service Request',
                    'Change'          => 'Change',
                ],
            ],
        ],
    ];
}

/**
 * Apply a preset's value transform to one cell.
 *
 * date_dmy: day-first "13/01/2025 16:48" → "2025-01-13 16:48:00". Returns '' for
 * anything that is not that exact shape, which is deliberate: a misaligned row
 * carrying HTML in a date column must produce an empty date the importer can
 * reject, never a date silently invented from a partial parse.
 *
 * ms_to_minutes: milliseconds → whole minutes.
 */
function migrate_transform(string $kind, string $value): string {
    $v = trim($value);
    if ($v === '') return '';

    if ($kind === 'date_dmy') {
        if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$#', $v, $m)) {
            return '';
        }
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31 || !checkdate($mo, $d, $y)) return '';
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d,
            (int)($m[4] ?? 0), (int)($m[5] ?? 0), (int)($m[6] ?? 0));
    }

    if ($kind === 'ms_to_minutes') {
        if (!preg_match('/^\d+$/', $v)) return '';
        return (string)(int)round(((int)$v) / 60000);
    }

    return $v;
}

/**
 * Build the mapping + value-map + rewritten rows for a preset.
 *
 * Returns the same shapes the manual flow produces, so the preview, commit and
 * reconciliation paths are identical whether the operator picked a preset or
 * mapped the columns by hand.
 *
 * $rows is taken BY REFERENCE and rewritten in place. Building a transformed
 * copy doubled peak memory on a large migration — 318 MB became 600 MB on a
 * 22,000-row file — for no benefit, since the untransformed rows are never
 * wanted again.
 *
 * @return array{mapping:array, value_map:array, missing:array, derive_idx:array}
 */
function migrate_apply_source(array $preset, array $header, array &$rows): array {
    $index = [];
    foreach ($header as $i => $h) $index[trim($h)] = $i;

    // Only map columns this export actually has — a preset describes a platform,
    // not one customer's chosen export columns.
    $mapping = [];
    $missing = [];
    foreach ($preset['columns'] as $src => $target) {
        if (isset($index[$src])) $mapping[$src] = $target;
        else $missing[] = $src;
    }

    // Where the derived aggregates live, for the post-import pass.
    $deriveIdx = [];
    foreach ($preset['derive'] ?? [] as $name => $src) {
        if (isset($index[$src])) $deriveIdx[$name] = $index[$src];
    }

    // Transforms are applied to the source cells before the generic rewrite, so
    // everything downstream sees canonical values. Resolve the work out of the
    // loop: this runs once per row across tens of thousands of rows.
    $transforms = [];
    foreach ($preset['transforms'] ?? [] as $src => $kind) {
        if (isset($index[$src])) $transforms[$index[$src]] = $kind;
    }
    $keyI = (!empty($preset['key_column']) && isset($index[$preset['key_column']]))
        ? $index[$preset['key_column']] : null;
    $keyPrefix = $preset['key_prefix'] ?? '';

    foreach ($rows as &$cells) {
        foreach ($transforms as $i => $kind) {
            $cells[$i] = migrate_transform($kind, (string)($cells[$i] ?? ''));
        }
        // Prefix the natural key so a migrated ticket is identifiable forever,
        // and cannot collide with a number this system generates itself.
        if ($keyI !== null) {
            $k = trim((string)($cells[$keyI] ?? ''));
            $cells[$keyI] = ($k !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $k))
                ? $keyPrefix . $k
                : '';   // unusable key → importer reports the row rather than inventing one
        }
    }
    unset($cells);   // break the reference before it can alias the last row

    // Value maps are keyed by OUR field in the preset; the rewrite wants them
    // keyed by the SOURCE column.
    $valueMap = [];
    foreach ($mapping as $src => $target) {
        if (isset($preset['values'][$target])) $valueMap[$src] = $preset['values'][$target];
    }

    return ['mapping' => $mapping, 'value_map' => $valueMap,
            'missing' => $missing, 'derive_idx' => $deriveIdx];
}
