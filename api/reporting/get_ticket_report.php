<?php
/**
 * API: Ad-hoc ticket report (Phase 4A).
 *
 * Aggregates ticket counts by one dimension, over the shared filter engine
 * (includes/ticket_filter.php) — so a report is "the same filters as a queue,
 * plus a GROUP BY".
 *
 * GET/POST:
 *   group_by = status | priority | type | category | subcategory | assignee |
 *              department | customer | origin | tag | created_month |
 *              sla_response_outcome | sla_resolution_outcome
 *   filters  = JSON object (see includes/ticket_filter.php); optional
 *
 * Returns { success, group_by, total, rows: [{ label, count }] } ordered by
 * count desc. NULL dimension values collapse to a "None/Unassigned" label.
 *
 * SLA-outcome aggregation (Phase 8a): now supported, grouping on the cached
 * ticket_sla_snapshot table (met/breached/approaching/ok, with untracked and
 * unstamped tickets collapsing to "Not tracked"). This lifts the earlier
 * deferral — SLA is still compute-on-read, but the snapshot makes its outcome
 * cheaply group-able. The snapshot is a cache; single-ticket views read live.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_filter.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('reporting');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    // Whitelisted grouping dimensions → [extra join, group expression, NULL label].
    // ticket_statuses (ts) is ALWAYS base-joined below (the filter engine uses
    // ts.name), so the 'status' dimension needs no extra join of its own.
    $dims = [
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
        // SLA outcome (Phase 8a) — group on the cached snapshot. A ticket with
        // no snapshot row (untracked, or not yet stamped) LEFT-JOINs to NULL and,
        // together with the 'na' state, collapses to "Not tracked" via the CASE +
        // COALESCE null-label. Only one of these dims is ever active per request,
        // so both safely reuse the `ss` alias.
        'sla_response_outcome'   => ['join' => 'LEFT JOIN ticket_sla_snapshot ss ON ss.ticket_id = t.id', 'expr' => "CASE ss.response_state WHEN 'ok' THEN 'On track' WHEN 'approaching' THEN 'Approaching breach' WHEN 'breached' THEN 'Breached' WHEN 'met' THEN 'Met' ELSE 'Not tracked' END", 'null' => 'Not tracked'],
        'sla_resolution_outcome' => ['join' => 'LEFT JOIN ticket_sla_snapshot ss ON ss.ticket_id = t.id', 'expr' => "CASE ss.resolution_state WHEN 'ok' THEN 'On track' WHEN 'approaching' THEN 'Approaching breach' WHEN 'breached' THEN 'Breached' WHEN 'met' THEN 'Met' ELSE 'Not tracked' END", 'null' => 'Not tracked'],
    ];

    $groupBy = $_GET['group_by'] ?? $_POST['group_by'] ?? 'status';
    if (!isset($dims[$groupBy])) throw new Exception('Invalid group_by');
    $dim = $dims[$groupBy];

    // Optional filters via the shared engine.
    $filtersRaw = $_GET['filters'] ?? $_POST['filters'] ?? '';
    $filters = [];
    if ($filtersRaw !== '') {
        $decoded = json_decode($filtersRaw, true);
        if (is_array($decoded)) $filters = $decoded;
    }
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

    echo json_encode(['success' => true, 'group_by' => $groupBy, 'total' => $total, 'rows' => $rows]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
