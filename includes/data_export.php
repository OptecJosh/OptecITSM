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
 */
function data_export_datasets(): array {
    $analyst = fn(string $col, string $as) => [$col => ['table' => 'analysts', 'column' => 'full_name', 'as' => $as]];

    return [
        // ---- Tickets -----------------------------------------------------
        'tickets' => [
            'module' => 'tickets', 'label' => 'Tickets', 'table' => 'tickets',
            'description' => 'One row per ticket with resolved status, priority, type, category and owner.',
            'tenant' => true, 'date' => 'created_datetime', 'order' => 'id', 'soft_delete' => 'deleted_datetime',
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

/** Validate a 'Y-m-d' date filter value; returns it or null. */
function data_export_valid_date($v): ?string {
    $v = trim((string)$v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}
