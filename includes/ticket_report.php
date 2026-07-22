<?php
/**
 * Shared ticket-report query (Phase 8b).
 *
 * Extracted from api/reporting/get_ticket_report.php so the aggregation runs in
 * exactly ONE place, reused by both the ad-hoc report endpoint and the
 * scheduled-reports cron (cron/scheduled_reports.php). A report is "the shared
 * filter engine (includes/ticket_filter.php) plus a GROUP BY", scoped to what
 * the acting analyst may see (ticketTenantFilter).
 *
 * Whitelisted grouping dimensions live here (ticket_report_dims). SLA-outcome
 * dimensions (Phase 8a) group on the cached ticket_sla_snapshot table.
 */

require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/ticket_filter.php';

/**
 * The grouping dimensions: dimension key → [extra join, group expression, NULL
 * label]. ticket_statuses (ts) is ALWAYS base-joined by the caller (the filter
 * engine uses ts.name), so 'status' needs no join of its own.
 */
function ticket_report_dims(): array {
    return [
        'status'        => ['join' => '',                                                                 'expr' => 'ts.name',                             'null' => 'No status'],
        'priority'      => ['join' => 'LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id',          'expr' => 'tp.name',                             'null' => 'No priority'],
        'type'          => ['join' => 'LEFT JOIN ticket_types tt ON tt.id = t.ticket_type_id',            'expr' => 'tt.name',                             'null' => 'No type'],
        'category'      => ['join' => 'LEFT JOIN ticket_categories tc ON tc.id = t.category_id',          'expr' => 'tc.name',                             'null' => 'No category'],
        'subcategory'   => ['join' => 'LEFT JOIN ticket_subcategories tsc ON tsc.id = t.subcategory_id',  'expr' => 'tsc.name',                            'null' => 'No subcategory'],
        'assignee'      => ['join' => 'LEFT JOIN analysts a ON a.id = t.assigned_analyst_id',             'expr' => 'a.full_name',                         'null' => 'Unassigned'],
        'department'    => ['join' => 'LEFT JOIN departments d ON d.id = t.department_id',                'expr' => 'd.name',                              'null' => 'No department'],
        'customer'      => ['join' => 'LEFT JOIN tenants tn ON tn.id = t.tenant_id',                      'expr' => 'tn.name',                             'null' => 'No customer'],
        'origin'        => ['join' => 'LEFT JOIN ticket_origins to2 ON to2.id = t.origin_id',            'expr' => 'to2.name',                            'null' => 'No origin'],
        // Tags are M:N — a ticket contributes one row per tag (so a multi-tagged
        // ticket is counted under each), and untagged tickets collapse to one
        // "Untagged" row. The total therefore may exceed the ticket count.
        'tag'           => ['join' => 'LEFT JOIN ticket_tag_map ttm ON ttm.ticket_id = t.id LEFT JOIN ticket_tags tg ON tg.id = ttm.tag_id', 'expr' => 'tg.name', 'null' => 'Untagged'],
        'created_month' => ['join' => '',                                                                 'expr' => "DATE_FORMAT(t.created_datetime, '%Y-%m')", 'null' => 'Unknown'],
        // SLA outcome (Phase 8a) — group on the cached snapshot. A ticket with no
        // snapshot row (untracked, or not yet stamped) LEFT-JOINs to NULL and,
        // with the 'na' state, collapses to "Not tracked". Only one of these dims
        // is ever active per request, so both reuse the `ss` alias.
        'sla_response_outcome'   => ['join' => 'LEFT JOIN ticket_sla_snapshot ss ON ss.ticket_id = t.id', 'expr' => "CASE ss.response_state WHEN 'ok' THEN 'On track' WHEN 'approaching' THEN 'Approaching breach' WHEN 'breached' THEN 'Breached' WHEN 'met' THEN 'Met' ELSE 'Not tracked' END", 'null' => 'Not tracked'],
        'sla_resolution_outcome' => ['join' => 'LEFT JOIN ticket_sla_snapshot ss ON ss.ticket_id = t.id', 'expr' => "CASE ss.resolution_state WHEN 'ok' THEN 'On track' WHEN 'approaching' THEN 'Approaching breach' WHEN 'breached' THEN 'Breached' WHEN 'met' THEN 'Met' ELSE 'Not tracked' END", 'null' => 'Not tracked'],
    ];
}

/**
 * Human-friendly label for a dimension key (matches the report builder UI).
 */
function ticket_report_dim_label(string $groupBy): string {
    $labels = [
        'status' => 'Status', 'priority' => 'Priority', 'type' => 'Type',
        'category' => 'Category', 'subcategory' => 'Subcategory', 'assignee' => 'Assignee',
        'department' => 'Department', 'customer' => 'Customer', 'origin' => 'Origin',
        'tag' => 'Tag', 'created_month' => 'Created month',
        'sla_response_outcome' => 'SLA response outcome',
        'sla_resolution_outcome' => 'SLA resolution outcome',
    ];
    return $labels[$groupBy] ?? $groupBy;
}

/**
 * The filter keys a report / queue is allowed to persist. ticket_filter_build
 * ignores unknown keys anyway, but whitelisting keeps stored JSON clean.
 */
function ticket_report_allowed_filter_keys(): array {
    return ['status','priority_id','ticket_type_id','category_id','subcategory_id',
            'tag_id','watched_by','tenant_id','origin_id','assignee_id','department_id',
            'created_from','created_to','keyword','sla_response_state','sla_resolution_state'];
}

/**
 * Run a report: aggregate ticket counts by one dimension over the shared filter
 * engine, scoped to $analystId's company access. Returns
 *   [ 'group_by' => string, 'dim_label' => string, 'total' => int,
 *     'rows' => [ ['label' => string, 'count' => int], ... ] ]
 * ordered by count desc. Throws on an unknown $groupBy.
 */
function ticket_report_run(PDO $conn, int $analystId, string $groupBy, array $filters): array {
    $dims = ticket_report_dims();
    if (!isset($dims[$groupBy])) {
        throw new Exception('Invalid group_by');
    }
    $dim = $dims[$groupBy];

    list($fSql, $fParams) = ticket_filter_build($filters);

    // Tenant scope + hide trashed (same base predicate as the ticket list/counts).
    list($ttSql, $ttParams) = ticketTenantFilter($conn, $analystId, 't');
    $ttSql .= " AND t.deleted_datetime IS NULL";

    $sql = "SELECT COALESCE(" . $dim['expr'] . ", ?) AS grp, COUNT(*) AS cnt
              FROM tickets t
              LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
              " . $dim['join'] . "
             WHERE 1=1" . $ttSql . $fSql . "
          GROUP BY grp
          ORDER BY cnt DESC, grp ASC";

    // Positional params bind in SQL order: the COALESCE null-label (SELECT) first,
    // then tenant params, then filter params (both in WHERE).
    $params = array_merge([$dim['null']], $ttParams, $fParams);
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    $total = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c = (int)$r['cnt'];
        $total += $c;
        $rows[] = ['label' => $r['grp'], 'count' => $c];
    }

    return [
        'group_by'  => $groupBy,
        'dim_label' => ticket_report_dim_label($groupBy),
        'total'     => $total,
        'rows'      => $rows,
    ];
}
