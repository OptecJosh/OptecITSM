<?php
/**
 * Mass CSV import for the data modules.
 *
 * The write-side counterpart to data_export.php. Where the exporter can be
 * schema-driven and generous, an importer has to be explicit: every column it
 * will write is declared here with a type, and every foreign key is declared as a
 * LOOKUP resolved by human-readable name ("Open", "High", "Dell", an analyst's
 * full name, a requester's email). That is what makes an export → edit → import
 * round-trip work in a spreadsheet, and what stops a CSV from writing a raw id
 * into a column it has no business touching.
 *
 * Spec keys
 *   module      module key the caller must have access to (admin is required too)
 *   table       target table
 *   match       natural-key column for create-or-update; null = always create
 *   tenant      true → new rows are stamped with the active company, and matching
 *               happens within it
 *   generate    [column => generator] filled in for new rows (ticket/problem number)
 *   defaults    [column => 'now'] stamped on insert when the CSV omits it
 *   columns     [csv column => ['type'=>..., 'max'=>..., 'required'=>bool, 'values'=>[]]]
 *   lookups     [csv column => ['table','name','target','required'?]] — resolve a
 *               name to an id and write it to `target`
 *   notes       one-line hint shown in the UI
 *
 * Deliberately NOT importable: analysts (they carry credentials and module access
 * — create staff in System > Analysts), and anything holding secrets or wiring
 * (API keys, workflows, SLA policies, RBAC). The development journal is excluded
 * for the same reason it is excluded from export: it belongs to two people.
 */

require_once __DIR__ . '/tenancy.php';

/** Hard cap on rows per import run. */
function data_import_row_cap(): int { return 5000; }

function data_import_datasets(): array {
    return [
        // ---- Tickets ------------------------------------------------------
        'tickets' => [
            'module' => 'tickets', 'label' => 'Tickets', 'table' => 'tickets',
            'match' => 'ticket_number', 'tenant' => true,
            'generate' => ['ticket_number' => 'ticket'],
            'defaults' => ['created_datetime' => 'now'],
            'notes' => 'Leave ticket_number blank to create new tickets (one is generated); supply it to update. Names must already exist — statuses, priorities and the rest are matched by name.',
            'columns' => [
                'ticket_number'         => ['type' => 'string', 'max' => 50],
                'subject'               => ['type' => 'string', 'max' => 255, 'required' => true],
                'created_datetime'      => ['type' => 'datetime'],
                'closed_datetime'       => ['type' => 'datetime'],
                'work_start_datetime'   => ['type' => 'datetime'],
                'acknowledged_datetime' => ['type' => 'datetime'],
                'first_time_fix'        => ['type' => 'bool'],
                'it_training_provided'  => ['type' => 'bool'],
                'playbook_eligible'     => ['type' => 'bool'],
            ],
            'lookups' => [
                'status'           => ['table' => 'ticket_statuses',      'name' => 'name',  'target' => 'status_id', 'required' => true],
                'priority'         => ['table' => 'ticket_priorities',    'name' => 'name',  'target' => 'priority_id'],
                'ticket_type'      => ['table' => 'ticket_types',         'name' => 'name',  'target' => 'ticket_type_id'],
                'category'         => ['table' => 'ticket_categories',    'name' => 'name',  'target' => 'category_id'],
                'subcategory'      => ['table' => 'ticket_subcategories', 'name' => 'name',  'target' => 'subcategory_id'],
                'department'       => ['table' => 'departments',          'name' => 'name',  'target' => 'department_id'],
                'origin'           => ['table' => 'ticket_origins',       'name' => 'name',  'target' => 'origin_id'],
                'stream'           => ['table' => 'ticket_streams',       'name' => 'name',  'target' => 'stream_id'],
                'customer'         => ['table' => 'customers',            'name' => 'name',  'target' => 'customer_id'],
                'requester_email'  => ['table' => 'users',                'name' => 'email', 'target' => 'user_id'],
                'owner'            => ['table' => 'analysts',             'name' => 'full_name', 'target' => 'owner_id'],
                'assigned_analyst' => ['table' => 'analysts',             'name' => 'full_name', 'target' => 'assigned_analyst_id'],
            ],
        ],

        // ---- Assets & CMDB ------------------------------------------------
        'assets' => [
            'module' => 'assets', 'label' => 'Assets', 'table' => 'assets',
            'match' => 'hostname', 'tenant' => true,
            'notes' => 'Matched on hostname within your active company. Richer than the assets import on System > Backup: type, status, location and supplier are resolved by name.',
            'columns' => [
                'hostname'         => ['type' => 'string', 'max' => 50, 'required' => true],
                'manufacturer'     => ['type' => 'string', 'max' => 50],
                'model'            => ['type' => 'string', 'max' => 50],
                'service_tag'      => ['type' => 'string', 'max' => 50],
                'operating_system' => ['type' => 'string', 'max' => 50],
                'domain'           => ['type' => 'string', 'max' => 100],
                'logged_in_user'   => ['type' => 'string', 'max' => 100],
                'order_number'     => ['type' => 'string', 'max' => 100],
                'purchase_cost'    => ['type' => 'decimal'],
                'purchase_date'    => ['type' => 'date'],
                'warranty_expiry'  => ['type' => 'date'],
                'end_of_life_date' => ['type' => 'date'],
                'disposal_date'    => ['type' => 'date'],
                'disposal_method'  => ['type' => 'string', 'max' => 100],
            ],
            'lookups' => [
                'asset_type'   => ['table' => 'asset_types',        'name' => 'name', 'target' => 'asset_type_id'],
                'asset_status' => ['table' => 'asset_status_types', 'name' => 'name', 'target' => 'asset_status_id'],
                'location'     => ['table' => 'asset_locations',    'name' => 'name', 'target' => 'location_id'],
                'supplier'     => ['table' => 'suppliers',          'name' => 'legal_name', 'target' => 'supplier_id'],
            ],
        ],
        'cmdb_objects' => [
            'module' => 'cmdb', 'label' => 'CMDB configuration items', 'table' => 'cmdb_objects',
            'match' => 'name', 'tenant' => false,
            'defaults' => ['created_datetime' => 'now'],
            'notes' => 'Creates CIs of an existing class. Property values and relationships are not part of a CSV — add those in the CMDB itself.',
            'columns' => [
                'name'       => ['type' => 'string', 'max' => 255, 'required' => true],
                'is_planned' => ['type' => 'bool'],
            ],
            'lookups' => [
                'class' => ['table' => 'cmdb_classes', 'name' => 'name', 'target' => 'class_id', 'required' => true],
            ],
        ],

        // ---- Changes / problems ------------------------------------------
        'changes' => [
            'module' => 'changes', 'label' => 'Changes', 'table' => 'changes',
            'match' => null, 'tenant' => true,
            'defaults' => ['created_datetime' => 'now'],
            'notes' => 'Always creates (changes have no natural key). Risk scoring is left to the module.',
            'columns' => [
                'title'                 => ['type' => 'string', 'max' => 255, 'required' => true],
                'description'           => ['type' => 'text'],
                'reason_for_change'     => ['type' => 'text'],
                'test_plan'             => ['type' => 'text'],
                'rollback_plan'         => ['type' => 'text'],
                'work_start_datetime'   => ['type' => 'datetime'],
                'work_end_datetime'     => ['type' => 'datetime'],
                'outage_start_datetime' => ['type' => 'datetime'],
                'outage_end_datetime'   => ['type' => 'datetime'],
                'cab_required'          => ['type' => 'bool'],
            ],
            'lookups' => [
                'change_type'  => ['table' => 'change_types',      'name' => 'name', 'target' => 'change_type_id'],
                'status'       => ['table' => 'change_statuses',   'name' => 'name', 'target' => 'status_id', 'required' => true],
                'priority'     => ['table' => 'change_priorities', 'name' => 'name', 'target' => 'priority_id'],
                'impact'       => ['table' => 'change_impacts',    'name' => 'name', 'target' => 'impact_id'],
                'change_category' => ['table' => 'change_categories', 'name' => 'name', 'target' => 'category_id'],
                'assigned_to'  => ['table' => 'analysts', 'name' => 'full_name', 'target' => 'assigned_to_id'],
                'approver'     => ['table' => 'analysts', 'name' => 'full_name', 'target' => 'approver_id'],
            ],
        ],
        'problems' => [
            'module' => 'problems', 'label' => 'Problems (KEDB)', 'table' => 'problems',
            'match' => 'problem_number', 'tenant' => true,
            'generate' => ['problem_number' => 'problem'],
            'defaults' => ['created_datetime' => 'now'],
            'notes' => 'Leave problem_number blank to create; supply it to update. Ticket and change links are made in the module.',
            'columns' => [
                'problem_number' => ['type' => 'string', 'max' => 50],
                'title'          => ['type' => 'string', 'max' => 255, 'required' => true],
                'description'    => ['type' => 'text'],
                'root_cause'     => ['type' => 'text'],
                'workaround'     => ['type' => 'text'],
                'is_known_error' => ['type' => 'bool'],
                'closed_datetime'=> ['type' => 'datetime'],
            ],
            'lookups' => [
                'status'           => ['table' => 'problem_statuses',   'name' => 'name', 'target' => 'status_id', 'required' => true],
                'priority'         => ['table' => 'problem_priorities', 'name' => 'name', 'target' => 'priority_id'],
                'assigned_analyst' => ['table' => 'analysts', 'name' => 'full_name', 'target' => 'assigned_analyst_id'],
            ],
        ],

        // ---- Contracts & suppliers ---------------------------------------
        'suppliers' => [
            'module' => 'contracts', 'label' => 'Suppliers', 'table' => 'suppliers',
            'match' => 'legal_name', 'tenant' => false,
            'defaults' => ['created_datetime' => 'now'],
            'notes' => 'Matched on legal_name.',
            'columns' => [
                'legal_name'      => ['type' => 'string', 'max' => 255, 'required' => true],
                'trading_name'    => ['type' => 'string', 'max' => 255],
                'reg_number'      => ['type' => 'string', 'max' => 50],
                'vat_number'      => ['type' => 'string', 'max' => 50],
                'address_line_1'  => ['type' => 'string', 'max' => 255],
                'address_line_2'  => ['type' => 'string', 'max' => 255],
                'city'            => ['type' => 'string', 'max' => 100],
                'county'          => ['type' => 'string', 'max' => 100],
                'postcode'        => ['type' => 'string', 'max' => 20],
                'country'         => ['type' => 'string', 'max' => 100],
                'comments'        => ['type' => 'text'],
                'supplies_assets' => ['type' => 'bool'],
                'is_active'       => ['type' => 'bool'],
            ],
            'lookups' => [
                'supplier_type'   => ['table' => 'supplier_types',    'name' => 'name', 'target' => 'supplier_type_id'],
                'supplier_status' => ['table' => 'supplier_statuses', 'name' => 'name', 'target' => 'supplier_status_id'],
            ],
        ],
        'contacts' => [
            'module' => 'contracts', 'label' => 'Supplier contacts', 'table' => 'contacts',
            'match' => 'email', 'tenant' => false,
            'defaults' => ['created_datetime' => 'now'],
            'notes' => 'Matched on email. The supplier must already exist (matched on its legal name).',
            'columns' => [
                'first_name'  => ['type' => 'string', 'max' => 100, 'required' => true],
                'surname'     => ['type' => 'string', 'max' => 100],
                'email'       => ['type' => 'email',  'max' => 255, 'required' => true],
                'mobile'      => ['type' => 'string', 'max' => 50],
                'job_title'   => ['type' => 'string', 'max' => 100],
                'direct_dial' => ['type' => 'string', 'max' => 50],
                'switchboard' => ['type' => 'string', 'max' => 50],
                'is_active'   => ['type' => 'bool'],
            ],
            'lookups' => [
                'supplier' => ['table' => 'suppliers', 'name' => 'legal_name', 'target' => 'supplier_id', 'required' => true],
            ],
        ],
        'contracts' => [
            'module' => 'contracts', 'label' => 'Contracts', 'table' => 'contracts',
            'match' => 'contract_number', 'tenant' => false,
            'defaults' => ['created_datetime' => 'now'],
            'notes' => 'Matched on contract_number. Covered assets are linked in the contract itself.',
            'columns' => [
                'contract_number'     => ['type' => 'string', 'max' => 100, 'required' => true],
                'title'               => ['type' => 'string', 'max' => 255, 'required' => true],
                'description'         => ['type' => 'text'],
                'contract_start'      => ['type' => 'date'],
                'contract_end'        => ['type' => 'date'],
                'notice_period_days'  => ['type' => 'int'],
                'notice_date'         => ['type' => 'date'],
                'contract_value'      => ['type' => 'decimal'],
                'currency'            => ['type' => 'string', 'max' => 10],
                'cost_centre'         => ['type' => 'string', 'max' => 100],
                'service_hours'       => ['type' => 'string', 'max' => 255],
                'response_sla'        => ['type' => 'string', 'max' => 255],
                'resolution_sla'      => ['type' => 'string', 'max' => 255],
                'coverage_notes'      => ['type' => 'text'],
                'is_active'           => ['type' => 'bool'],
            ],
            'lookups' => [
                'supplier'         => ['table' => 'suppliers',         'name' => 'legal_name', 'target' => 'supplier_id', 'required' => true],
                'contract_status'  => ['table' => 'contract_statuses', 'name' => 'name', 'target' => 'contract_status_id'],
                'payment_schedule' => ['table' => 'payment_schedules', 'name' => 'name', 'target' => 'payment_schedule_id'],
                'contract_owner'   => ['table' => 'analysts',          'name' => 'full_name', 'target' => 'contract_owner_id'],
            ],
        ],

        // ---- Knowledge / tasks / calendar --------------------------------
        'knowledge_articles' => [
            'module' => 'knowledge', 'label' => 'Knowledge articles', 'table' => 'knowledge_articles',
            'match' => 'title', 'tenant' => true,
            'defaults' => ['created_datetime' => 'now'],
            'notes' => 'Matched on title. body accepts HTML. Set is_published to 1 to publish on import.',
            'columns' => [
                'title'            => ['type' => 'string', 'max' => 255, 'required' => true],
                'body'             => ['type' => 'text'],
                'is_published'     => ['type' => 'bool'],
                'next_review_date' => ['type' => 'date'],
            ],
            'lookups' => [
                'author' => ['table' => 'analysts', 'name' => 'full_name', 'target' => 'author_id'],
                'owner'  => ['table' => 'analysts', 'name' => 'full_name', 'target' => 'owner_id'],
            ],
        ],
        'tasks' => [
            'module' => 'tasks', 'label' => 'Tasks', 'table' => 'tasks',
            'match' => null, 'tenant' => false,
            'defaults' => ['created_datetime' => 'now'],
            'notes' => 'Always creates. Use for bulk-loading a backlog.',
            'columns' => [
                'title'              => ['type' => 'string', 'max' => 255, 'required' => true],
                'description'        => ['type' => 'text'],
                'start_date'         => ['type' => 'date'],
                'due_date'           => ['type' => 'date'],
                'completed_datetime' => ['type' => 'datetime'],
            ],
            'lookups' => [
                'status'           => ['table' => 'task_statuses',   'name' => 'name', 'target' => 'status_id'],
                'priority'         => ['table' => 'task_priorities', 'name' => 'name', 'target' => 'priority_id'],
                'assigned_analyst' => ['table' => 'analysts', 'name' => 'full_name', 'target' => 'assigned_analyst_id'],
            ],
        ],
        'calendar_events' => [
            'module' => 'calendar', 'label' => 'Calendar events', 'table' => 'calendar_events',
            'match' => null, 'tenant' => false,
            'notes' => 'Always creates. all_day events still need a start_datetime.',
            'columns' => [
                'title'          => ['type' => 'string', 'max' => 255, 'required' => true],
                'description'    => ['type' => 'text'],
                'start_datetime' => ['type' => 'datetime', 'required' => true],
                'end_datetime'   => ['type' => 'datetime'],
                'all_day'        => ['type' => 'bool'],
                'location'       => ['type' => 'string', 'max' => 255],
            ],
            'lookups' => [
                'category' => ['table' => 'calendar_categories', 'name' => 'name', 'target' => 'category_id'],
            ],
        ],

        // ---- Customers / portal users ------------------------------------
        'customers' => [
            'module' => 'customers', 'label' => 'Customers', 'table' => 'customers',
            'match' => 'name', 'tenant' => false,
            'defaults' => ['created_datetime' => 'now', 'updated_datetime' => 'now'],
            'notes' => 'Matched on name. CI links are made on the customer record.',
            'columns' => [
                'name'          => ['type' => 'string', 'max' => 150, 'required' => true],
                'account_ref'   => ['type' => 'string', 'max' => 100],
                'contact_name'  => ['type' => 'string', 'max' => 150],
                'contact_email' => ['type' => 'email',  'max' => 255],
                'contact_phone' => ['type' => 'string', 'max' => 50],
                'notes'         => ['type' => 'text'],
                'is_active'     => ['type' => 'bool'],
            ],
            'lookups' => [
                'company' => ['table' => 'tenants', 'name' => 'name', 'target' => 'tenant_id'],
            ],
        ],
        'users' => [
            'module' => 'tickets', 'group' => 'People & system', 'label' => 'Portal users', 'table' => 'users',
            'match' => 'email', 'tenant' => false,
            'notes' => 'Self-service portal users, matched on email. No passwords are ever imported — portal users sign in by SSO or a reset link.',
            'columns' => [
                'email'          => ['type' => 'email',  'max' => 255, 'required' => true],
                'display_name'   => ['type' => 'string', 'max' => 255],
                'preferred_name' => ['type' => 'string', 'max' => 100],
            ],
        ],

        // ---- Overtime / KPI / LMS ----------------------------------------
        'overtime_requests' => [
            'module' => 'overtime', 'label' => 'Overtime requests', 'table' => 'overtime_requests',
            'match' => null, 'tenant' => true,
            'defaults' => ['created_datetime' => 'now', 'submitted_datetime' => 'now'],
            'notes' => 'Always creates. status is one of pending / approved / rejected; hours is the claimed total.',
            'columns' => [
                'work_date'       => ['type' => 'date', 'required' => true],
                'start_time'      => ['type' => 'string', 'max' => 8],
                'end_time'        => ['type' => 'string', 'max' => 8],
                'hours'           => ['type' => 'decimal', 'required' => true],
                'overtime_type'   => ['type' => 'string', 'max' => 40],
                'rate_multiplier' => ['type' => 'decimal'],
                'reason'          => ['type' => 'text'],
                'status'          => ['type' => 'enum', 'values' => ['pending', 'approved', 'rejected']],
            ],
            'lookups' => [
                'analyst_email' => ['table' => 'analysts', 'name' => 'email', 'target' => 'analyst_id', 'required' => true],
            ],
        ],
        'kpi_measurements' => [
            'module' => 'kpi', 'label' => 'KPI measurements', 'table' => 'kpi_measurements',
            'match' => null, 'tenant' => false,
            'defaults' => ['entered_at' => 'now', 'updated_at' => 'now'],
            'notes' => 'One row per KPI per month. kpi_name must match a KPI in the catalogue exactly; period_month is YYYY-MM. Re-importing the same KPI + month updates it.',
            'columns' => [
                'period_month' => ['type' => 'string', 'max' => 7, 'required' => true],
                'value'        => ['type' => 'decimal'],
                'status'       => ['type' => 'enum', 'values' => ['green', 'amber', 'red', 'info', 'na']],
                'note'         => ['type' => 'text'],
            ],
            'lookups' => [
                'kpi_name' => ['table' => 'kpi_definitions', 'name' => 'name', 'target' => 'kpi_id', 'required' => true],
            ],
            'upsert_on' => ['kpi_id', 'period_month'],   // composite natural key
        ],
        'lms_certifications' => [
            'module' => 'lms', 'label' => 'Certification catalogue', 'table' => 'lms_certifications',
            'match' => 'name', 'tenant' => false,
            'defaults' => ['created_datetime' => 'now', 'updated_datetime' => 'now'],
            'notes' => 'The catalogue of recognised certifications. validity_months drives the suggested expiry when one is awarded.',
            'columns' => [
                'name'            => ['type' => 'string', 'max' => 150, 'required' => true],
                'issuer'          => ['type' => 'string', 'max' => 150],
                'description'     => ['type' => 'string', 'max' => 500],
                'validity_months' => ['type' => 'int'],
                'display_order'   => ['type' => 'int'],
                'is_active'       => ['type' => 'bool'],
            ],
        ],
        'analyst_certifications' => [
            'module' => 'lms', 'label' => 'Certifications held', 'table' => 'analyst_certifications',
            'match' => null, 'tenant' => false,
            'defaults' => ['created_datetime' => 'now', 'updated_datetime' => 'now'],
            'notes' => 'Certifications held by staff, matched to the analyst by email. Re-importing the same analyst + title updates that record.',
            'columns' => [
                'title'         => ['type' => 'string', 'max' => 150, 'required' => true],
                'issuer'        => ['type' => 'string', 'max' => 150],
                'status'        => ['type' => 'enum', 'values' => ['planned', 'in_progress', 'achieved', 'expired', 'revoked']],
                'awarded_date'  => ['type' => 'date'],
                'expires_date'  => ['type' => 'date'],
                'credential_id' => ['type' => 'string', 'max' => 120],
                'evidence_url'  => ['type' => 'string', 'max' => 500],
                'notes'         => ['type' => 'string', 'max' => 1000],
            ],
            'lookups' => [
                'analyst_email' => ['table' => 'analysts', 'name' => 'email', 'target' => 'analyst_id', 'required' => true],
                'catalogue_name'=> ['table' => 'lms_certifications', 'name' => 'name', 'target' => 'certification_id'],
            ],
            'upsert_on' => ['analyst_id', 'title'],
        ],
        'analyst_training_records' => [
            'module' => 'lms', 'label' => 'Training completed', 'table' => 'analyst_training_records',
            'match' => null, 'tenant' => false,
            'defaults' => ['created_datetime' => 'now', 'updated_datetime' => 'now'],
            'notes' => 'Training completed outside the LMS, matched to the analyst by email. Re-importing the same analyst + title + date updates that record.',
            'columns' => [
                'title'          => ['type' => 'string', 'max' => 200, 'required' => true],
                'provider'       => ['type' => 'string', 'max' => 150],
                'training_type'  => ['type' => 'enum', 'values' => ['course', 'webinar', 'conference', 'exam', 'on_the_job', 'reading', 'other']],
                'completed_date' => ['type' => 'date'],
                'hours'          => ['type' => 'decimal'],
                'cost'           => ['type' => 'decimal'],
                'evidence_url'   => ['type' => 'string', 'max' => 500],
                'notes'          => ['type' => 'string', 'max' => 1000],
            ],
            'lookups' => [
                'analyst_email' => ['table' => 'analysts', 'name' => 'email', 'target' => 'analyst_id', 'required' => true],
            ],
            'upsert_on' => ['analyst_id', 'title', 'completed_date'],
        ],
    ];
}

/**
 * Validate + normalise one CSV cell.
 * @return array{0:bool,1:mixed,2:?string} [ok, value, error]
 */
function data_import_normalise(string $col, array $spec, $raw): array {
    $v = trim((string)$raw);
    if ($v === '') {
        if (!empty($spec['required'])) return [false, null, "$col is required"];
        return [true, null, null];
    }
    switch ($spec['type'] ?? 'string') {
        case 'email':
            if (!filter_var($v, FILTER_VALIDATE_EMAIL)) return [false, null, "$col is not a valid email address"];
            return [true, $v, null];

        case 'date':
            $ts = strtotime($v);
            if ($ts === false) return [false, null, "$col is not a valid date"];
            return [true, date('Y-m-d', $ts), null];

        case 'datetime':
            $ts = strtotime($v);
            if ($ts === false) return [false, null, "$col is not a valid date/time"];
            return [true, date('Y-m-d H:i:s', $ts), null];

        case 'int':
            if (!preg_match('/^-?\d+$/', $v)) return [false, null, "$col must be a whole number"];
            return [true, (int)$v, null];

        case 'decimal':
            if (!is_numeric($v)) return [false, null, "$col must be a number"];
            return [true, (string)(float)$v, null];

        case 'bool':
            $t = strtolower($v);
            if (in_array($t, ['1', 'y', 'yes', 'true', 't'], true))  return [true, 1, null];
            if (in_array($t, ['0', 'n', 'no', 'false', 'f'], true)) return [true, 0, null];
            return [false, null, "$col must be yes/no (1/0)"];

        case 'enum':
            $allowed = $spec['values'] ?? [];
            $t = strtolower(str_replace(' ', '_', $v));
            if (!in_array($t, $allowed, true)) {
                return [false, null, "$col must be one of: " . implode(', ', $allowed)];
            }
            return [true, $t, null];

        case 'text':
            return [true, $v, null];

        case 'string':
        default:
            if (isset($spec['max']) && mb_strlen($v) > $spec['max']) $v = mb_substr($v, 0, $spec['max']);
            return [true, $v, null];
    }
}

/** The lowercased header row of a CSV, or [] if it has none. */
function data_import_header(string $csv): array {
    $line = strtok($csv, "\r\n");
    if ($line === false) return [];
    $cells = str_getcsv($line);
    if (!$cells) return [];
    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cells[0]);
    return array_values(array_filter(array_map(fn($h) => strtolower(trim((string)$h)), $cells), fn($h) => $h !== ''));
}

/** Every column name a dataset accepts, lowercased. */
function data_import_accepted_columns(array $spec): array {
    return array_map('strtolower', data_import_template_columns($spec));
}

/** The columns a dataset cannot import without, lowercased. */
function data_import_required_columns(array $spec): array {
    $req = [];
    foreach ($spec['columns'] as $col => $c) {
        if (!empty($c['required'])) $req[] = strtolower($col);
    }
    foreach ($spec['lookups'] ?? [] as $col => $lk) {
        if (!empty($lk['required'])) $req[] = strtolower($col);
    }
    return $req;
}

/**
 * Which dataset does this CSV look like?
 *
 * Picking the wrong dataset is the easiest mistake to make here — the file is
 * right, the dropdown is on last time's choice, and the only symptom is a
 * complaint about a column the file was never meant to have. So rather than
 * leave the user to decode that, rank every dataset by how well its accepted
 * columns cover the header row, and let the caller offer the better match.
 *
 * @return array<int,array{key:string,label:string,matched:int,coverage:float,usable:bool}>
 *         best first; only datasets sharing at least one column are returned.
 */
function data_import_detect(array $header): array {
    if (!$header) return [];
    $scored = [];
    foreach (data_import_datasets() as $key => $spec) {
        $accepted = data_import_accepted_columns($spec);
        $matched = count(array_intersect($header, $accepted));
        if ($matched === 0) continue;
        $missing = array_diff(data_import_required_columns($spec), $header);
        $scored[] = [
            'key'      => $key,
            'label'    => $spec['label'],
            'matched'  => $matched,
            'coverage' => round($matched / count($header), 3),
            'usable'   => empty($missing),      // has everything it must have
        ];
    }
    // A dataset that could actually run wins over one that merely shares names.
    usort($scored, function ($a, $b) {
        if ($a['usable'] !== $b['usable']) return $a['usable'] ? -1 : 1;
        if ($a['coverage'] !== $b['coverage']) return $b['coverage'] <=> $a['coverage'];
        return $b['matched'] <=> $a['matched'];
    });
    return $scored;
}

/**
 * Resolve a lookup value ("Open", "Jane Bloggs", "jane@example.com") to an id.
 * Cached per table+value; case-insensitive; returns null when not found so the
 * caller can decide whether that is an error.
 */
function data_import_lookup(PDO $conn, array $lk, string $value): ?int {
    static $cache = [];
    $key = $lk['table'] . '|' . $lk['name'] . '|' . mb_strtolower($value);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = $conn->prepare("SELECT id FROM `{$lk['table']}` WHERE LOWER(`{$lk['name']}`) = LOWER(?) LIMIT 1");
        $stmt->execute([$value]);
        $id = $stmt->fetchColumn();
        $cache[$key] = $id !== false ? (int)$id : null;
    } catch (Exception $e) {
        $cache[$key] = null;
    }
    return $cache[$key];
}

/** The CSV header a dataset expects: match key, columns, then lookup names. */
function data_import_template_columns(array $spec): array {
    $cols = array_keys($spec['columns']);
    foreach (array_keys($spec['lookups'] ?? []) as $lk) {
        if (!in_array($lk, $cols, true)) $cols[] = $lk;
    }
    return $cols;
}

/** Generate a unique identifier for a generated column. */
function data_import_generate(PDO $conn, string $kind): string {
    if ($kind === 'ticket') {
        for ($i = 0; $i < 20; $i++) {
            $n = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90))
               . '-' . rand(100, 999) . '-' . str_pad((string)rand(0, 99999), 5, '0', STR_PAD_LEFT);
            $c = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_number = ?");
            $c->execute([$n]);
            if (!(int)$c->fetchColumn()) return $n;
        }
        throw new Exception('Could not generate a unique ticket number');
    }
    if ($kind === 'problem') {
        // PRB-##### like ProblemsService, from the current max rather than the id
        // (the row does not exist yet), retrying on collision.
        for ($i = 0; $i < 20; $i++) {
            $max = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM problems")->fetchColumn();
            $n = 'PRB-' . str_pad((string)($max + 1 + $i), 5, '0', STR_PAD_LEFT);
            $c = $conn->prepare("SELECT COUNT(*) FROM problems WHERE problem_number = ?");
            $c->execute([$n]);
            if (!(int)$c->fetchColumn()) return $n;
        }
        throw new Exception('Could not generate a unique problem number');
    }
    throw new Exception('Unknown generator: ' . $kind);
}

/**
 * Parse and validate a CSV against a dataset spec, producing an executable plan.
 *
 * Nothing is written here — the same plan drives the preview and the commit, so
 * what the preview reports is exactly what the commit does.
 *
 * @return array{plan:array,errors:array,to_create:int,to_update:int,ignored:array}
 */
function data_import_plan(PDO $conn, array $spec, int $analystId, string $csv): array {
    if (trim($csv) === '') throw new Exception('No CSV supplied');

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $csv);
    rewind($fh);

    $header = fgetcsv($fh);
    if ($header === false || !$header) throw new Exception('The CSV has no header row');
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);

    $colIndex = [];      // csv column => index, for declared value columns
    $lkIndex  = [];      // csv column => index, for lookups
    $ignored  = [];      // header cells we do not recognise
    foreach ($header as $i => $h) {
        if ($h === '') continue;
        if (isset($spec['columns'][$h]))      $colIndex[$h] = $i;
        elseif (isset($spec['lookups'][$h]))  $lkIndex[$h]  = $i;
        else                                  $ignored[] = $h;
    }

    // Every required column and required lookup must be present in the header.
    foreach ($spec['columns'] as $col => $c) {
        if (!empty($c['required']) && !isset($colIndex[$col])) {
            throw new Exception("The CSV must include a '$col' column");
        }
    }
    foreach ($spec['lookups'] ?? [] as $col => $lk) {
        if (!empty($lk['required']) && !isset($lkIndex[$col])) {
            throw new Exception("The CSV must include a '$col' column");
        }
    }
    if (!$colIndex && !$lkIndex) throw new Exception('None of the CSV columns are recognised for this dataset');

    $multi         = isMultiTenant($conn);
    $activeTenant  = $multi ? getActiveTenantId($conn, $analystId) : null;
    $defaultTenant = $multi ? getDefaultTenantId($conn) : null;
    $tenantScoped  = !empty($spec['tenant']);

    /** Find an existing row by the dataset's natural key, honouring tenancy. */
    $findByMatch = function (string $value) use ($conn, $spec, $multi, $activeTenant, $defaultTenant, $tenantScoped): ?int {
        $col = $spec['match'];
        if ($tenantScoped && $multi) {
            if ($activeTenant === $defaultTenant) {
                $s = $conn->prepare("SELECT id FROM `{$spec['table']}` WHERE LOWER(`$col`) = LOWER(?) AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1");
            } else {
                $s = $conn->prepare("SELECT id FROM `{$spec['table']}` WHERE LOWER(`$col`) = LOWER(?) AND tenant_id = ? LIMIT 1");
            }
            $s->execute([$value, $activeTenant]);
        } else {
            $s = $conn->prepare("SELECT id FROM `{$spec['table']}` WHERE LOWER(`$col`) = LOWER(?) LIMIT 1");
            $s->execute([$value]);
        }
        $id = $s->fetchColumn();
        return $id !== false ? (int)$id : null;
    };

    /** Find an existing row by a composite natural key (upsert_on). */
    $findByComposite = function (array $values) use ($conn, $spec): ?int {
        $where = [];
        $args  = [];
        foreach ($spec['upsert_on'] as $col) {
            if (!array_key_exists($col, $values) || $values[$col] === null) {
                $where[] = "`$col` IS NULL";
            } else {
                $where[] = "`$col` = ?";
                $args[]  = $values[$col];
            }
        }
        $s = $conn->prepare("SELECT id FROM `{$spec['table']}` WHERE " . implode(' AND ', $where) . " LIMIT 1");
        $s->execute($args);
        $id = $s->fetchColumn();
        return $id !== false ? (int)$id : null;
    };

    $errors   = [];
    $plan     = [];
    $toCreate = 0;
    $toUpdate = 0;
    $rowNum   = 1;   // the header was row 1
    // A one-off migration from another system is far bigger than a routine CSV
    // load, so the spec may raise the cap (see includes/data_migrate.php).
    $rowCap   = max(1, (int)($spec['row_cap'] ?? data_import_row_cap()));

    while (($cells = fgetcsv($fh)) !== false) {
        $rowNum++;
        if ($rowNum - 1 > $rowCap) {
            $errors[] = ['row' => $rowNum, 'message' => 'Row cap (' . $rowCap . ') reached - the rest of the file was ignored'];
            break;
        }
        if (count(array_filter($cells, fn($c) => trim((string)$c) !== '')) === 0) continue;   // blank line

        $values   = [];
        $rowError = null;

        foreach ($colIndex as $col => $idx) {
            [$ok, $val, $err] = data_import_normalise($col, $spec['columns'][$col], $cells[$idx] ?? '');
            if (!$ok) { $rowError = $err; break; }
            if ($val !== null) $values[$col] = $val;
        }
        if ($rowError) { $errors[] = ['row' => $rowNum, 'message' => $rowError]; continue; }

        // Lookups: a value that cannot be resolved is an error, never a silent NULL.
        foreach ($lkIndex as $col => $idx) {
            $raw = trim((string)($cells[$idx] ?? ''));
            $lk  = $spec['lookups'][$col];
            if ($raw === '') {
                if (!empty($lk['required'])) { $rowError = "$col is required"; break; }
                continue;
            }
            $id = data_import_lookup($conn, $lk, $raw);
            if ($id === null) { $rowError = "$col \"$raw\" does not exist - create it first, or correct the spelling"; break; }
            $values[$lk['target']] = $id;
        }
        if ($rowError) { $errors[] = ['row' => $rowNum, 'message' => $rowError]; continue; }

        // Which existing row (if any) does this line correspond to?
        $existingId = null;
        if (!empty($spec['upsert_on'])) {
            $ready = true;
            foreach ($spec['upsert_on'] as $c) {
                if (!array_key_exists($c, $values)) { $ready = false; break; }
            }
            if ($ready) $existingId = $findByComposite($values);
        } elseif (!empty($spec['match']) && !empty($values[$spec['match']])) {
            $existingId = $findByMatch((string)$values[$spec['match']]);
        }

        if ($existingId) $toUpdate++; else $toCreate++;
        $plan[] = ['row' => $rowNum, 'id' => $existingId, 'values' => $values];
    }
    fclose($fh);

    return [
        'plan'      => $plan,
        'errors'    => $errors,
        'to_create' => $toCreate,
        'to_update' => $toUpdate,
        'ignored'   => array_values(array_unique($ignored)),
    ];
}

/**
 * Apply a plan from data_import_plan(). One transaction: if a row fails its
 * INSERT/UPDATE the whole run rolls back, rather than leaving a module half
 * loaded with no way to tell how far it got.
 *
 * @return array{created:int,updated:int}
 */
function data_import_commit(PDO $conn, array $spec, int $analystId, array $plan): array {
    $multi        = isMultiTenant($conn);
    $activeTenant = $multi ? getActiveTenantId($conn, $analystId) : null;
    $tenantScoped = !empty($spec['tenant']);
    $created = 0;
    $updated = 0;

    $conn->beginTransaction();
    try {
        foreach ($plan as $p) {
            $vals = $p['values'];

            if ($p['id']) {
                $set  = [];
                $args = [];
                foreach ($vals as $col => $v) {
                    if (!empty($spec['match']) && $col === $spec['match']) continue;    // never rewrite the key
                    if (isset($spec['generate'][$col])) continue;                       // nor a generated one
                    $set[]  = "`$col` = ?";
                    $args[] = $v;
                }
                foreach ($spec['defaults'] ?? [] as $col => $kind) {
                    if ($kind === 'now' && preg_match('/updated|modified/', $col)) {
                        $set[] = "`$col` = UTC_TIMESTAMP()";
                    }
                }
                if ($set) {
                    $args[] = $p['id'];
                    $conn->prepare("UPDATE `{$spec['table']}` SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
                }
                $updated++;
                continue;
            }

            // Generated keys (ticket / problem number) when the CSV left them blank.
            foreach ($spec['generate'] ?? [] as $col => $kind) {
                if (empty($vals[$col])) $vals[$col] = data_import_generate($conn, $kind);
            }
            // Stamped defaults the CSV did not supply.
            foreach ($spec['defaults'] ?? [] as $col => $kind) {
                if ($kind === 'now' && !array_key_exists($col, $vals)) $vals[$col] = gmdate('Y-m-d H:i:s');
            }
            if ($tenantScoped && $multi && $activeTenant !== null && !array_key_exists('tenant_id', $vals)) {
                $vals['tenant_id'] = $activeTenant;
            }

            $cols = array_keys($vals);
            $sql = "INSERT INTO `{$spec['table']}` (`" . implode('`, `', $cols) . "`) VALUES ("
                 . implode(', ', array_fill(0, count($cols), '?')) . ")";
            $conn->prepare($sql)->execute(array_values($vals));
            $created++;
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

    return ['created' => $created, 'updated' => $updated];
}
