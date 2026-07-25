<?php
/**
 * Module data export (read-only).
 *
 * One registry of exportable datasets, one per meaningful table per module, and
 * the query builder behind api/export/export_dataset.php. Deliberately separate
 * from data_io.php: that file describes the narrow, round-trippable CSV *import*
 * surface, while this one is export-only and therefore free to hand over every
 * non-sensitive column plus resolved lookup names.
 *
 * Design notes
 *   - Columns are discovered from the LIVE schema, not hardcoded, so a dataset
 *     never breaks when a column is added and never leaks one that shouldn't
 *     leave (see data_export_is_sensitive).
 *   - On top of its own columns a dataset may declare DERIVED columns: SQL
 *     expressions (usually correlated subqueries) that fold related tables into
 *     one analytics-ready row. Each declares what it 'needs' and is dropped
 *     silently when that table/column is absent, so the same dataset works on a
 *     half-migrated install. This is what makes the ticket export usable as a
 *     BI fact table instead of forcing the analyst to re-join KPI tables by hand.
 *   - Every configured column (tenant / date / order / lookup joins) is verified
 *     against the live table first; anything missing is skipped rather than
 *     fatal, so a partially-migrated install still exports.
 *   - Access is per dataset: the analyst needs the dataset's MODULE, and
 *     'admin_only' datasets additionally need is_admin. Tenant-scoped datasets
 *     are filtered to the active company exactly like the module's own lists.
 *   - Table and column names only ever come from this registry, never from the
 *     request, so the interpolation below is not an injection surface.
 */

require_once __DIR__ . '/tenancy.php';

/** Hard ceiling on exported rows, whatever the filters. */
function data_export_max_rows(): int { return 200000; }

/**
 * Exportable datasets: key => spec.
 *
 *   module      module key the caller must have access to
 *   table       source table
 *   tenant      true if the table is company-scoped on tenant_id
 *   date        column used by the from/to date filter (optional)
 *   order       ORDER BY column (defaults to id)
 *   soft_delete column that must be NULL for a live row (optional)
 *   admin_only  true = is_admin required on top of the module
 *   group       display grouping override (defaults to the module's name)
 *   resolve     [fk_column => [table, column, as]] LEFT JOINed for readable names
 *   derived     [alias => ['needs' => ['table' | 'table.column', …], 'sql' => expr]]
 *               extra SELECT expressions over alias t, included only when every
 *               dependency exists on this install
 */
/**
 * Derived KPI / analytics columns for the ticket export.
 *
 * These exist so one CSV is a complete BI fact table: every KPI marker the K1
 * instrumentation records lives on a child table (SLA snapshot, escalations,
 * hold events, QA reviews, time entries, CSAT, audit), and re-joining seven
 * tables in the BI tool is both painful and the point at which two people start
 * computing MTTR two different ways.
 *
 * The definitions below deliberately MIRROR includes/kpi_engine.php so a chart
 * built on this export agrees with the KPI scorecard:
 *   - MTTR  = created -> closed
 *   - MTTA  = created -> acknowledged
 *   - reopen = a Status audit row moving OUT of an is_closed status
 *   - bounce = count of 'Owner' audit rows
 *   - tier  = the OWNER's tier, not the assignee's
 * Change one, change both.
 *
 * Every expression is parameter-free and references only alias t, so it can be
 * dropped into the SELECT list without touching the parameter list.
 */
function data_export_ticket_derived(): array {
    // A ticket is "closed" per the status flag, not per closed_datetime, because
    // an imported ticket can carry a closure date without a closed status.
    $closedNames = '(SELECT s2.name FROM ticket_statuses s2 WHERE s2.is_closed = 1)';
    $firstEsc = '(SELECT MIN(e.escalated_at) FROM ticket_escalations e WHERE e.ticket_id = t.id)';
    $holdMins = '(SELECT SUM(TIMESTAMPDIFF(MINUTE, h.entered_at, h.exited_at))
                    FROM ticket_hold_events h
                   WHERE h.ticket_id = t.id AND h.exited_at IS NOT NULL)';

    return [
        // ---- Cycle times -------------------------------------------------
        'ack_minutes' => [
            'needs' => ['tickets.acknowledged_datetime'],
            'sql'   => 'TIMESTAMPDIFF(MINUTE, t.created_datetime, t.acknowledged_datetime)',
        ],
        'resolution_minutes' => [
            'needs' => ['tickets.closed_datetime'],
            'sql'   => 'TIMESTAMPDIFF(MINUTE, t.created_datetime, t.closed_datetime)',
        ],
        'resolution_hours' => [
            'needs' => ['tickets.closed_datetime'],
            'sql'   => 'ROUND(TIMESTAMPDIFF(MINUTE, t.created_datetime, t.closed_datetime) / 60, 2)',
        ],
        // MTTR with customer/vendor waiting time removed — the number the team
        // can actually be held to, and the one directors ask for second.
        'resolution_minutes_excl_hold' => [
            'needs' => ['tickets.closed_datetime', 'ticket_hold_events.entered_at'],
            'sql'   => "TIMESTAMPDIFF(MINUTE, t.created_datetime, t.closed_datetime) - COALESCE({$holdMins}, 0)",
        ],
        'is_closed' => [
            'needs' => ['ticket_statuses.is_closed'],
            'sql'   => '(SELECT s.is_closed FROM ticket_statuses s WHERE s.id = t.status_id)',
        ],

        // ---- Owner dimension (tier drives every KPI split) ---------------
        'owner_tier' => [
            'needs' => ['analysts.tier'],
            'sql'   => '(SELECT a.tier FROM analysts a WHERE a.id = t.owner_id)',
        ],
        'owner_manager' => [
            'needs' => ['analysts.manager_id'],
            'sql'   => '(SELECT m.full_name FROM analysts a
                           LEFT JOIN analysts m ON m.id = a.manager_id
                          WHERE a.id = t.owner_id)',
        ],

        // ---- SLA outcome (Phase 8a snapshot) -----------------------------
        'sla_response_state' => [
            'needs' => ['ticket_sla_snapshot.response_state'],
            'sql'   => '(SELECT sn.response_state FROM ticket_sla_snapshot sn WHERE sn.ticket_id = t.id)',
        ],
        'sla_resolution_state' => [
            'needs' => ['ticket_sla_snapshot.resolution_state'],
            'sql'   => '(SELECT sn.resolution_state FROM ticket_sla_snapshot sn WHERE sn.ticket_id = t.id)',
        ],
        'sla_response_remaining_mins' => [
            'needs' => ['ticket_sla_snapshot.response_remaining_mins'],
            'sql'   => '(SELECT sn.response_remaining_mins FROM ticket_sla_snapshot sn WHERE sn.ticket_id = t.id)',
        ],
        'sla_resolution_remaining_mins' => [
            'needs' => ['ticket_sla_snapshot.resolution_remaining_mins'],
            'sql'   => '(SELECT sn.resolution_remaining_mins FROM ticket_sla_snapshot sn WHERE sn.ticket_id = t.id)',
        ],
        'sla_policy_source' => [
            'needs' => ['ticket_sla_snapshot.policy_source'],
            'sql'   => '(SELECT sn.policy_source FROM ticket_sla_snapshot sn WHERE sn.ticket_id = t.id)',
        ],
        // One 0/1 measure to average into an attainment percentage. 'na' (no SLA
        // applies) must stay NULL so it drops out of the average rather than
        // counting as a pass and quietly inflating the figure.
        'sla_met' => [
            'needs' => ['ticket_sla_snapshot.resolution_state'],
            'sql'   => "(SELECT CASE
                                  WHEN sn.resolution_state IN ('met','ok') THEN 1
                                  WHEN sn.resolution_state = 'breached'    THEN 0
                                  ELSE NULL END
                           FROM ticket_sla_snapshot sn WHERE sn.ticket_id = t.id)",
        ],

        // ---- Escalations (K1) --------------------------------------------
        'escalation_count' => [
            'needs' => ['ticket_escalations.ticket_id'],
            'sql'   => '(SELECT COUNT(*) FROM ticket_escalations e WHERE e.ticket_id = t.id)',
        ],
        'first_escalated_at' => [
            'needs' => ['ticket_escalations.escalated_at'],
            'sql'   => $firstEsc,
        ],
        'escalated_from_tier' => [
            'needs' => ['ticket_escalations.from_tier'],
            'sql'   => '(SELECT e.from_tier FROM ticket_escalations e
                          WHERE e.ticket_id = t.id ORDER BY e.escalated_at ASC LIMIT 1)',
        ],
        'escalated_to_tier' => [
            'needs' => ['ticket_escalations.to_tier'],
            'sql'   => '(SELECT e.to_tier FROM ticket_escalations e
                          WHERE e.ticket_id = t.id ORDER BY e.escalated_at ASC LIMIT 1)',
        ],
        'minutes_to_escalate' => [
            'needs' => ['ticket_escalations.escalated_at', 'tickets.acknowledged_datetime'],
            'sql'   => "TIMESTAMPDIFF(MINUTE, t.acknowledged_datetime, {$firstEsc})",
        ],

        // ---- On-hold (K1) -------------------------------------------------
        'hold_event_count' => [
            'needs' => ['ticket_hold_events.ticket_id'],
            'sql'   => '(SELECT COUNT(*) FROM ticket_hold_events h WHERE h.ticket_id = t.id)',
        ],
        'hold_minutes' => [
            'needs' => ['ticket_hold_events.entered_at'],
            'sql'   => $holdMins,
        ],
        'hold_reasons' => [
            'needs' => ['ticket_hold_events.reason'],
            'sql'   => "(SELECT GROUP_CONCAT(DISTINCT h.reason ORDER BY h.reason SEPARATOR '|')
                           FROM ticket_hold_events h WHERE h.ticket_id = t.id)",
        ],

        // ---- Quality (K1) -------------------------------------------------
        'qa_review_count' => [
            'needs' => ['ticket_qa_reviews.ticket_id'],
            'sql'   => '(SELECT COUNT(*) FROM ticket_qa_reviews q WHERE q.ticket_id = t.id)',
        ],
        'qa_passed' => [
            'needs' => ['ticket_qa_reviews.passed'],
            'sql'   => '(SELECT q.passed FROM ticket_qa_reviews q
                          WHERE q.ticket_id = t.id ORDER BY q.created_datetime DESC, q.id DESC LIMIT 1)',
        ],
        'qa_score' => [
            'needs' => ['ticket_qa_reviews.score'],
            'sql'   => '(SELECT ROUND(AVG(q.score), 2) FROM ticket_qa_reviews q WHERE q.ticket_id = t.id)',
        ],

        // ---- Satisfaction -------------------------------------------------
        'csat_rating' => [
            'needs' => ['ticket_csat_responses.rating'],
            'sql'   => '(SELECT c.rating FROM ticket_csat_responses c
                          WHERE c.ticket_id = t.id AND c.rating IS NOT NULL
                       ORDER BY c.responded_datetime DESC, c.id DESC LIMIT 1)',
        ],

        // ---- Effort & cost (K3) -------------------------------------------
        'logged_minutes' => [
            'needs' => ['ticket_time_entries.time_spent_minutes'],
            'sql'   => '(SELECT SUM(te.time_spent_minutes) FROM ticket_time_entries te
                          WHERE te.ticket_id = t.id AND te.is_active = 1)',
        ],
        'logged_hours' => [
            'needs' => ['ticket_time_entries.time_spent_minutes'],
            'sql'   => '(SELECT ROUND(SUM(te.time_spent_minutes) / 60, 2) FROM ticket_time_entries te
                          WHERE te.ticket_id = t.id AND te.is_active = 1)',
        ],
        // Costed at the rate of whoever logged each entry, not the owner's rate,
        // so an L3 hour on an L1 ticket costs what it actually cost.
        'labour_cost' => [
            'needs' => ['ticket_time_entries.time_spent_minutes', 'analysts.loaded_rate'],
            'sql'   => '(SELECT ROUND(SUM(te.time_spent_minutes / 60 * COALESCE(a.loaded_rate, 0)), 2)
                           FROM ticket_time_entries te
                           LEFT JOIN analysts a ON a.id = te.analyst_id
                          WHERE te.ticket_id = t.id AND te.is_active = 1)',
        ],

        // ---- Rework (mirrors kpi_engine reopen / bounce) -------------------
        'reopen_count' => [
            'needs' => ['ticket_audit.field_name', 'ticket_statuses.is_closed'],
            'sql'   => "(SELECT COUNT(*) FROM ticket_audit ta
                          WHERE ta.ticket_id = t.id AND ta.field_name = 'Status'
                            AND ta.old_value IN {$closedNames}
                            AND (ta.new_value NOT IN {$closedNames} OR ta.new_value IS NULL))",
        ],
        'owner_changes' => [
            'needs' => ['ticket_audit.field_name'],
            'sql'   => "(SELECT COUNT(*) FROM ticket_audit ta
                          WHERE ta.ticket_id = t.id AND ta.field_name = 'Owner')",
        ],
        'note_count' => [
            'needs' => ['ticket_notes.ticket_id'],
            'sql'   => '(SELECT COUNT(*) FROM ticket_notes n WHERE n.ticket_id = t.id)',
        ],

        // ---- Date dimensions --------------------------------------------
        // Emitted so the BI tool can group by month/weekday/hour without the
        // report author writing DAX date logic against a datetime string.
        'created_date'  => ['needs' => [], 'sql' => 'DATE(t.created_datetime)'],
        'created_year'  => ['needs' => [], 'sql' => 'YEAR(t.created_datetime)'],
        'created_month' => ['needs' => [], 'sql' => "DATE_FORMAT(t.created_datetime, '%Y-%m')"],
        'created_week'  => ['needs' => [], 'sql' => "DATE_FORMAT(t.created_datetime, '%x-W%v')"],
        'created_weekday' => ['needs' => [], 'sql' => 'DAYNAME(t.created_datetime)'],
        'created_hour'  => ['needs' => [], 'sql' => 'HOUR(t.created_datetime)'],
        'closed_date'   => ['needs' => ['tickets.closed_datetime'], 'sql' => 'DATE(t.closed_datetime)'],
        'closed_month'  => ['needs' => ['tickets.closed_datetime'], 'sql' => "DATE_FORMAT(t.closed_datetime, '%Y-%m')"],
        // Raised outside Mon-Fri 08:00-18:00 (K3 out-of-hours demand).
        'out_of_hours'  => [
            'needs' => [],
            'sql'   => '(CASE WHEN DAYOFWEEK(t.created_datetime) IN (1, 7)
                               OR HOUR(t.created_datetime) < 8
                               OR HOUR(t.created_datetime) >= 18
                          THEN 1 ELSE 0 END)',
        ],
    ];
}

function data_export_datasets(): array {
    $analyst = fn(string $col, string $as) => [$col => ['table' => 'analysts', 'column' => 'full_name', 'as' => $as]];

    return [
        // ---- Tickets -----------------------------------------------------
        'tickets' => [
            'module' => 'tickets', 'label' => 'Tickets', 'table' => 'tickets',
            'description' => 'The BI fact table: one row per ticket with resolved lookups plus every KPI marker '
                . '— MTTA/MTTR, SLA outcome, escalations, hold time, QA, CSAT, effort, cost, reopens and date '
                . 'dimensions. Wide by design; use a date range on a large history.',
            'tenant' => true, 'date' => 'created_datetime', 'order' => 'id', 'soft_delete' => 'deleted_datetime',
            'derived' => data_export_ticket_derived(),
            'resolve' => [
                'status_id'           => ['table' => 'ticket_statuses',      'column' => 'name', 'as' => 'status'],
                'priority_id'         => ['table' => 'ticket_priorities',    'column' => 'name', 'as' => 'priority'],
                'ticket_type_id'      => ['table' => 'ticket_types',         'column' => 'name', 'as' => 'ticket_type'],
                'category_id'         => ['table' => 'ticket_categories',    'column' => 'name', 'as' => 'category'],
                'subcategory_id'      => ['table' => 'ticket_subcategories', 'column' => 'name', 'as' => 'subcategory'],
                'department_id'       => ['table' => 'departments',          'column' => 'name', 'as' => 'department'],
                'origin_id'           => ['table' => 'ticket_origins',       'column' => 'name', 'as' => 'origin'],
                'stream_id'           => ['table' => 'ticket_streams',       'column' => 'name', 'as' => 'stream'],
                'customer_id'         => ['table' => 'customers',            'column' => 'name', 'as' => 'customer'],
                'assigned_analyst_id' => ['table' => 'analysts',             'column' => 'full_name', 'as' => 'assigned_analyst'],
                'owner_id'            => ['table' => 'analysts',             'column' => 'full_name', 'as' => 'owner'],
            ],
        ],
        'ticket_time_entries' => [
            'module' => 'tickets', 'label' => 'Ticket time entries', 'table' => 'ticket_time_entries',
            'description' => 'Logged time per ticket — the basis of effort and cost reporting.',
            'tenant' => false, 'date' => 'entry_datetime', 'order' => 'id',
            'resolve' => $analyst('analyst_id', 'analyst'),
        ],
        'ticket_notes' => [
            'module' => 'tickets', 'label' => 'Ticket notes', 'table' => 'ticket_notes',
            'description' => 'Internal notes and updates recorded against tickets.',
            'tenant' => false, 'date' => 'created_datetime', 'order' => 'id',
            'resolve' => $analyst('analyst_id', 'analyst'),
        ],
        'ticket_csat' => [
            'module' => 'tickets', 'label' => 'CSAT responses', 'table' => 'ticket_csat_responses',
            'description' => 'Customer satisfaction ratings and comments.',
            'tenant' => false, 'date' => 'responded_datetime', 'order' => 'id',
        ],
        'ticket_audit' => [
            'module' => 'tickets', 'label' => 'Ticket audit trail', 'table' => 'ticket_audit',
            'description' => 'Every field change on every ticket. Large — use a date range.',
            'tenant' => false, 'date' => 'created_datetime', 'order' => 'id',
            'resolve' => $analyst('analyst_id', 'analyst'),
        ],

        // ---- KPI instrumentation (K1) --------------------------------------
        // The ticket export folds these into one row each; they are also offered
        // raw, because "how long was it on hold, and how many times" is a
        // distribution, and a single summed column can't answer it.
        'ticket_sla_snapshot' => [
            'module' => 'tickets', 'label' => 'Ticket SLA outcomes', 'table' => 'ticket_sla_snapshot',
            'description' => 'Cached response/resolution SLA state per ticket, as stamped by the breach cron.',
            'tenant' => false, 'order' => 'ticket_id',
            'resolve' => ['ticket_id' => ['table' => 'tickets', 'column' => 'ticket_number', 'as' => 'ticket_number']],
        ],
        'ticket_escalations' => [
            'module' => 'tickets', 'label' => 'Ticket escalations', 'table' => 'ticket_escalations',
            'description' => 'One row per tier escalation (L1→L2→L3) with who handed over and when it was picked up.',
            'tenant' => false, 'date' => 'escalated_at', 'order' => 'id',
            'resolve' => [
                'ticket_id'       => ['table' => 'tickets',  'column' => 'ticket_number', 'as' => 'ticket_number'],
                'from_analyst_id' => ['table' => 'analysts', 'column' => 'full_name', 'as' => 'from_analyst'],
                'to_analyst_id'   => ['table' => 'analysts', 'column' => 'full_name', 'as' => 'to_analyst'],
            ],
        ],
        'ticket_hold_events' => [
            'module' => 'tickets', 'label' => 'Ticket hold events', 'table' => 'ticket_hold_events',
            'description' => 'On-hold intervals with reason — separates our delay from customer and vendor waiting time.',
            'tenant' => false, 'date' => 'entered_at', 'order' => 'id',
            'resolve' => ['ticket_id' => ['table' => 'tickets', 'column' => 'ticket_number', 'as' => 'ticket_number']],
            'derived' => [
                'hold_minutes' => [
                    'needs' => ['ticket_hold_events.exited_at'],
                    'sql'   => 'TIMESTAMPDIFF(MINUTE, t.entered_at, t.exited_at)',
                ],
            ],
        ],
        'ticket_qa_reviews' => [
            'module' => 'tickets', 'label' => 'Ticket QA reviews', 'table' => 'ticket_qa_reviews',
            'description' => 'Sampled quality reviews with pass/fail and score, by review type.',
            'tenant' => false, 'date' => 'created_datetime', 'order' => 'id',
            'resolve' => [
                'ticket_id'           => ['table' => 'tickets',  'column' => 'ticket_number', 'as' => 'ticket_number'],
                'reviewer_analyst_id' => ['table' => 'analysts', 'column' => 'full_name', 'as' => 'reviewer'],
            ],
        ],

        // ---- Assets & software -------------------------------------------
        'assets' => [
            'module' => 'assets', 'label' => 'Assets', 'table' => 'assets',
            'description' => 'Hardware inventory including warranty, lifecycle and purchase data.',
            'tenant' => true, 'date' => 'purchase_date', 'order' => 'id',
            'resolve' => [
                'asset_type_id'   => ['table' => 'asset_types',        'column' => 'name', 'as' => 'asset_type'],
                'asset_status_id' => ['table' => 'asset_status_types', 'column' => 'name', 'as' => 'asset_status'],
                'location_id'     => ['table' => 'asset_locations',    'column' => 'name', 'as' => 'location'],
                'supplier_id'     => ['table' => 'suppliers',          'column' => 'legal_name', 'as' => 'supplier'],
            ],
        ],
        'software_licences' => [
            'module' => 'software', 'label' => 'Software licences', 'table' => 'software_licences',
            'description' => 'Licence entitlements, quantities, cost and renewal dates.',
            'tenant' => false, 'order' => 'id',
        ],
        'software_installed' => [
            'module' => 'software', 'label' => 'Installed software', 'table' => 'software_inventory_apps',
            'description' => 'Discovered application inventory. Large on a big estate.',
            'tenant' => false, 'order' => 'id',
        ],

        // ---- Changes / problems / CMDB -----------------------------------
        'changes' => [
            'module' => 'changes', 'label' => 'Changes', 'table' => 'changes',
            'description' => 'Change records with schedule, risk scoring and CAB fields.',
            'tenant' => true, 'date' => 'created_datetime', 'order' => 'id',
            'resolve' => [
                'status_id'      => ['table' => 'change_statuses',    'column' => 'name', 'as' => 'status'],
                'priority_id'    => ['table' => 'change_priorities',  'column' => 'name', 'as' => 'priority'],
                'change_type_id' => ['table' => 'change_types',       'column' => 'name', 'as' => 'change_type'],
                'impact_id'      => ['table' => 'change_impacts',     'column' => 'name', 'as' => 'impact'],
                'category_id'    => ['table' => 'change_categories',  'column' => 'name', 'as' => 'change_category'],
                'assigned_to_id' => ['table' => 'analysts',           'column' => 'full_name', 'as' => 'assigned_to'],
                'approver_id'    => ['table' => 'analysts',           'column' => 'full_name', 'as' => 'approver'],
            ],
        ],
        'problems' => [
            'module' => 'problems', 'label' => 'Problems (KEDB)', 'table' => 'problems',
            'description' => 'Known errors with status, priority and owner.',
            'tenant' => true, 'date' => 'created_datetime', 'order' => 'id',
            'resolve' => [
                'status_id'           => ['table' => 'problem_statuses',   'column' => 'name', 'as' => 'status'],
                'priority_id'         => ['table' => 'problem_priorities', 'column' => 'name', 'as' => 'priority'],
                'assigned_analyst_id' => ['table' => 'analysts',           'column' => 'full_name', 'as' => 'assigned_analyst'],
            ],
        ],
        'cmdb_objects' => [
            'module' => 'cmdb', 'label' => 'CMDB configuration items', 'table' => 'cmdb_objects',
            'description' => 'Configuration items with their class. Property values are a separate dataset.',
            'tenant' => false, 'date' => 'created_datetime', 'order' => 'id',
            'resolve' => ['class_id' => ['table' => 'cmdb_classes', 'column' => 'name', 'as' => 'class']],
        ],
        'cmdb_object_properties' => [
            'module' => 'cmdb', 'label' => 'CMDB property values', 'table' => 'cmdb_object_properties',
            'description' => 'Per-CI property values (one row per property).',
            'tenant' => false, 'order' => 'id',
        ],

        // ---- Contracts / suppliers ---------------------------------------
        'contracts' => [
            'module' => 'contracts', 'label' => 'Contracts', 'table' => 'contracts',
            'description' => 'Contract terms, value, renewal dates and service coverage.',
            'tenant' => false, 'date' => 'contract_start', 'order' => 'id',
            'resolve' => [
                'supplier_id'         => ['table' => 'suppliers',          'column' => 'legal_name', 'as' => 'supplier'],
                'contract_status_id'  => ['table' => 'contract_statuses',  'column' => 'name', 'as' => 'status'],
                'payment_schedule_id' => ['table' => 'payment_schedules',  'column' => 'name', 'as' => 'payment_schedule'],
                'contract_owner_id'   => ['table' => 'analysts',           'column' => 'full_name', 'as' => 'contract_owner'],
            ],
        ],
        'suppliers' => [
            'module' => 'contracts', 'label' => 'Suppliers', 'table' => 'suppliers',
            'description' => 'Supplier records with type and status.',
            'tenant' => false, 'order' => 'id',
            'resolve' => [
                'supplier_type_id'   => ['table' => 'supplier_types',    'column' => 'name', 'as' => 'supplier_type'],
                'supplier_status_id' => ['table' => 'supplier_statuses', 'column' => 'name', 'as' => 'status'],
            ],
        ],
        'contacts' => [
            'module' => 'contracts', 'label' => 'Supplier contacts', 'table' => 'contacts',
            'description' => 'Contacts held against suppliers.',
            'tenant' => false, 'order' => 'id',
            'resolve' => ['supplier_id' => ['table' => 'suppliers', 'column' => 'legal_name', 'as' => 'supplier']],
        ],

        // ---- Knowledge / forms / tasks / calendar -------------------------
        'knowledge_articles' => [
            'module' => 'knowledge', 'label' => 'Knowledge articles', 'table' => 'knowledge_articles',
            'description' => 'Published and draft KB articles, including body HTML.',
            'tenant' => true, 'date' => 'created_datetime', 'order' => 'id',
        ],
        'form_submissions' => [
            'module' => 'forms', 'label' => 'Form submissions', 'table' => 'form_submissions',
            'description' => 'Submission headers. Field values are a separate dataset.',
            'tenant' => false, 'date' => 'created_datetime', 'order' => 'id',
            'resolve' => ['form_id' => ['table' => 'forms', 'column' => 'name', 'as' => 'form']],
        ],
        'form_submission_data' => [
            'module' => 'forms', 'label' => 'Form submission values', 'table' => 'form_submission_data',
            'description' => 'One row per answered field.',
            'tenant' => false, 'order' => 'id',
        ],
        'tasks' => [
            'module' => 'tasks', 'label' => 'Tasks', 'table' => 'tasks',
            'description' => 'Tasks with status, priority, dates and assignee.',
            'tenant' => false, 'date' => 'created_datetime', 'order' => 'id',
            'resolve' => [
                'status_id'           => ['table' => 'task_statuses',   'column' => 'name', 'as' => 'status'],
                'priority_id'         => ['table' => 'task_priorities', 'column' => 'name', 'as' => 'priority'],
                'assigned_analyst_id' => ['table' => 'analysts',        'column' => 'full_name', 'as' => 'assigned_analyst'],
            ],
        ],
        'calendar_events' => [
            'module' => 'calendar', 'label' => 'Calendar events', 'table' => 'calendar_events',
            'description' => 'Calendar entries and their category.',
            'tenant' => false, 'date' => 'start_datetime', 'order' => 'id',
            'resolve' => ['category_id' => ['table' => 'calendar_categories', 'column' => 'name', 'as' => 'category']],
        ],

        // ---- Customers / overtime / KPI / LMS -----------------------------
        'customers' => [
            'module' => 'customers', 'label' => 'Customers', 'table' => 'customers',
            'description' => 'Customer accounts and their primary contact.',
            'tenant' => false, 'date' => 'created_datetime', 'order' => 'id',
            'resolve' => ['tenant_id' => ['table' => 'tenants', 'column' => 'name', 'as' => 'company']],
        ],
        'overtime_requests' => [
            'module' => 'overtime', 'label' => 'Overtime requests', 'table' => 'overtime_requests',
            'description' => 'Submitted overtime with approval state — the payroll feed.',
            'tenant' => true, 'date' => 'work_date', 'order' => 'id',
            'resolve' => [
                'analyst_id'    => ['table' => 'analysts', 'column' => 'full_name', 'as' => 'analyst'],
                'decided_by_id' => ['table' => 'analysts', 'column' => 'full_name', 'as' => 'decided_by'],
            ],
        ],
        // Targets and RAG thresholds — without these the measurements export is
        // just numbers, and the BI report can't colour anything red or green.
        'kpi_definitions' => [
            'module' => 'kpi', 'label' => 'KPI definitions & targets', 'table' => 'kpi_definitions',
            'description' => 'The KPI catalogue: scorecard, unit, target, RAG thresholds and direction of good.',
            'tenant' => false, 'order' => 'id',
        ],
        'kpi_measurements' => [
            'module' => 'kpi', 'label' => 'KPI measurements', 'table' => 'kpi_measurements',
            'description' => 'Monthly KPI values with their definition name and scorecard.',
            'tenant' => false, 'order' => 'id',
            'resolve' => [
                'kpi_id' => ['table' => 'kpi_definitions', 'column' => 'name', 'as' => 'kpi_name'],
            ],
        ],
        'lms_progress' => [
            'module' => 'lms', 'label' => 'Course progress', 'table' => 'lms_progress',
            'description' => 'Per-analyst course status, score and completion date.',
            'tenant' => false, 'date' => 'completion_datetime', 'order' => 'id',
            'resolve' => [
                'analyst_id' => ['table' => 'analysts',    'column' => 'full_name', 'as' => 'analyst'],
                'course_id'  => ['table' => 'lms_courses', 'column' => 'title',     'as' => 'course'],
            ],
        ],
        // The development JOURNAL is deliberately not exportable: it is visible
        // only to an analyst and their line manager, and an export would hand it
        // to anyone with the Reporting module.
        'analyst_certifications' => [
            'module' => 'lms', 'label' => 'Certifications held', 'table' => 'analyst_certifications',
            'description' => 'Certifications per analyst with award and expiry dates — the compliance record.',
            'tenant' => false, 'date' => 'expires_date', 'order' => 'id',
            'resolve' => ['analyst_id' => ['table' => 'analysts', 'column' => 'full_name', 'as' => 'analyst']],
        ],
        'analyst_training_records' => [
            'module' => 'lms', 'label' => 'Training completed', 'table' => 'analyst_training_records',
            'description' => 'Training completed outside the LMS, with hours and cost.',
            'tenant' => false, 'date' => 'completed_date', 'order' => 'id',
            'resolve' => ['analyst_id' => ['table' => 'analysts', 'column' => 'full_name', 'as' => 'analyst']],
        ],

        // ---- Admin-only ---------------------------------------------------
        'analysts' => [
            'module' => 'reporting', 'group' => 'People & system', 'label' => 'Analysts (staff)', 'table' => 'analysts',
            'description' => 'Staff records — no credentials, secrets or MFA data. Admin only.',
            'tenant' => false, 'order' => 'id', 'admin_only' => true,
            'resolve' => ['manager_id' => ['table' => 'analysts', 'column' => 'full_name', 'as' => 'manager']],
        ],
        'users' => [
            'module' => 'reporting', 'group' => 'People & system', 'label' => 'Portal users', 'table' => 'users',
            'description' => 'End users of the self-service portal. Admin only.',
            'tenant' => false, 'order' => 'id', 'admin_only' => true,
        ],
        'system_logs' => [
            'module' => 'reporting', 'group' => 'People & system', 'label' => 'System logs', 'table' => 'system_logs',
            'description' => 'Application log. Large — use a date range. Admin only.',
            'tenant' => false, 'date' => 'created_datetime', 'order' => 'id', 'admin_only' => true,
        ],
    ];
}

/**
 * Never export a column whose name suggests a credential, token or other secret
 * — belt and braces on top of the per-dataset choices above.
 */
function data_export_is_sensitive(string $column): bool {
    return (bool)preg_match(
        '/(password|passwd|secret|token|api_key|apikey|access_key|private_key|salt|_hash|hash_|mfa|totp|recovery_code|credential|suspend_data|session)/i',
        $column
    );
}

/** Live column names for a table (registry-supplied name only), [] if absent. */
function data_export_table_columns(PDO $conn, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $rows = $conn->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $cache[$table] = array_map(fn($r) => $r['Field'], $rows);
    } catch (Exception $e) {
        $cache[$table] = [];
    }
    return $cache[$table];
}

/** Does the dataset's table exist on this install? */
function data_export_available(PDO $conn, array $spec): bool {
    return data_export_table_columns($conn, $spec['table']) !== [];
}

/**
 * Are all of a derived column's dependencies present?
 *
 * Each dep is 'table' or 'table.column'. A missing dep means the expression
 * would reference something that doesn't exist here, so the column is dropped
 * rather than failing the whole export — the same tolerance the resolve joins
 * already have.
 */
function data_export_deps_met(PDO $conn, array $needs): bool {
    foreach ($needs as $dep) {
        [$table, $column] = array_pad(explode('.', $dep, 2), 2, null);
        $cols = data_export_table_columns($conn, $table);
        if ($cols === []) return false;
        if ($column !== null && !in_array($column, $cols, true)) return false;
    }
    return true;
}

/**
 * Build the export query for a dataset.
 *
 * @param  string|null $from  inclusive 'Y-m-d' lower bound on the date column
 * @param  string|null $to    inclusive 'Y-m-d' upper bound (whole day included)
 * @return array{0:string,1:array,2:array}  [sql, params, headerRow]
 */
function data_export_plan(PDO $conn, array $spec, int $analystId, ?string $from = null, ?string $to = null): array {
    $table = $spec['table'];
    $cols = array_values(array_filter(
        data_export_table_columns($conn, $table),
        fn($c) => !data_export_is_sensitive($c) && !in_array($c, $spec['exclude'] ?? [], true)
    ));
    if (!$cols) throw new Exception('This dataset is not available on this install.');

    $select = array_map(fn($c) => "t.`$c`", $cols);
    $headers = $cols;

    // Resolved lookup names, skipped silently when the fk/table/column is absent.
    $joins = [];
    $i = 0;
    foreach ($spec['resolve'] ?? [] as $fk => $r) {
        if (!in_array($fk, $cols, true)) continue;
        if (!in_array($r['column'], data_export_table_columns($conn, $r['table']), true)) continue;
        $alias = 'r' . $i++;
        // A resolved name must not collide with a real column of the table, or
        // one would silently overwrite the other in the fetched row.
        $as = $r['as'];
        while (in_array($as, $headers, true)) $as .= '_name';
        $joins[] = "LEFT JOIN `{$r['table']}` {$alias} ON {$alias}.id = t.`{$fk}`";
        $select[] = "{$alias}.`{$r['column']}` AS `{$as}`";
        $headers[] = $as;
    }

    // Derived analytics columns. Appended after the resolved names so the plain
    // columns keep their familiar positions in the CSV.
    foreach ($spec['derived'] ?? [] as $as => $d) {
        if (!data_export_deps_met($conn, $d['needs'] ?? [])) continue;
        while (in_array($as, $headers, true)) $as .= '_calc';
        $select[] = '(' . $d['sql'] . ") AS `{$as}`";
        $headers[] = $as;
    }

    $where = ' WHERE 1=1';
    $params = [];

    if (!empty($spec['soft_delete']) && in_array($spec['soft_delete'], $cols, true)) {
        $where .= " AND t.`{$spec['soft_delete']}` IS NULL";
    }
    if (!empty($spec['tenant']) && in_array('tenant_id', data_export_table_columns($conn, $table), true)) {
        [$tsql, $tparams] = activeTenantFilter($conn, $analystId, 't');
        $where .= $tsql;
        $params = array_merge($params, $tparams);
    }
    $dateCol = $spec['date'] ?? null;
    if ($dateCol && in_array($dateCol, $cols, true)) {
        if ($from !== null) { $where .= " AND t.`{$dateCol}` >= ?"; $params[] = $from . ' 00:00:00'; }
        if ($to !== null)   { $where .= " AND t.`{$dateCol}` <= ?"; $params[] = $to . ' 23:59:59'; }
    }

    $order = $spec['order'] ?? 'id';
    if (!in_array($order, $cols, true)) $order = $cols[0];

    $sql = 'SELECT ' . implode(', ', $select)
         . " FROM `{$table}` t "
         . implode(' ', $joins)
         . $where
         . " ORDER BY t.`{$order}` ASC"
         . ' LIMIT ' . data_export_max_rows();

    return [$sql, $params, $headers];
}

/**
 * May this analyst export this dataset?
 *
 * The one place the rule lives, so the dataset list, the single-dataset export
 * and the bundle can never disagree about who may see what. Assumes the caller
 * has already required includes/functions.php (for analystCanAccessModule) and
 * has separately enforced the Reporting module, which is the surface gate.
 */
function data_export_can(PDO $conn, array $spec, int $analystId, bool $isAdmin): bool {
    if (!empty($spec['admin_only']) && !$isAdmin) return false;
    if (!analystCanAccessModule($conn, $analystId, $spec['module'])) return false;
    return data_export_available($conn, $spec);
}

/**
 * Named multi-dataset bundles.
 *
 * 'analytics' is the BI feed: the ticket fact table, its KPI child tables, the
 * KPI catalogue (for targets/thresholds) and the dimension tables a report joins
 * against — i.e. everything needed to build a management report without hand
 * picking sixteen separate downloads. 'all' is every registered dataset.
 *
 * Unknown names return [] and the caller reports it, rather than silently
 * exporting nothing and looking like an empty database.
 */
function data_export_bundles(): array {
    return [
        'analytics' => [
            'label' => 'Analytics / Power BI',
            'description' => 'Ticket fact table, KPI instrumentation, KPI targets and the dimensions to join them.',
            'datasets' => [
                'tickets', 'ticket_sla_snapshot', 'ticket_escalations', 'ticket_hold_events',
                'ticket_qa_reviews', 'ticket_time_entries', 'ticket_csat',
                'kpi_definitions', 'kpi_measurements',
                'analysts', 'customers', 'assets', 'changes', 'problems', 'contracts',
                'overtime_requests',
            ],
        ],
        'all' => [
            'label' => 'Everything',
            'description' => 'Every dataset you have access to. Large.',
            'datasets' => null,   // resolved to all registered keys
        ],
    ];
}

/** Validate a 'Y-m-d' date filter value; returns it or null. */
function data_export_valid_date($v): ?string {
    $v = trim((string)$v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}
