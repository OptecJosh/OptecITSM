<?php
/**
 * API: Executive dashboard data (Phase 8c).
 *
 * A single-call KPI dispatcher for the curated cross-module executive view. One
 * round-trip returns every tile + chart series, each scoped to the analyst's
 * active company. Ticket metrics use ticketTenantFilter (Default owns
 * NULL-tenant tickets); assets/changes use the generic activeTenantFilter.
 *
 * Cross-module blocks are individually try/caught so a part-installed system
 * (no assets, no change-management) degrades to an empty card instead of a 500.
 *
 * Returns {
 *   success, scope_label,
 *   tiles:  { open_tickets:int, sla:{ pct:float|null, breached:int, tracked:int } },
 *   charts: { open_by_priority:[{label,count}], sla_resolution:[{label,count}],
 *             assets_by_status:[{label,count}], changes_by_state:[{label,count}] }
 * }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_report.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('reporting');

/** Run a "label + count" grouping query, returning [] on any error. */
function exec_group_query(PDO $conn, string $sql, array $params): array {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['label' => $r['grp'], 'count' => (int)$r['cnt']];
        }
        return $rows;
    } catch (Exception $e) {
        return [];
    }
}

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    // --- Scope label -------------------------------------------------------
    $scopeLabel = 'All companies';
    if (isMultiTenant($conn)) {
        $activeId = getActiveTenantId($conn, $analystId);
        $tenant = $activeId ? getTenantById($conn, $activeId) : null;
        $scopeLabel = $tenant['name'] ?? 'Active company';
    }

    // --- Ticket scope (Default owns NULL-tenant tickets) -------------------
    list($ttSql, $ttParams) = ticketTenantFilter($conn, $analystId, 't');
    $openPredicate = " AND COALESCE(ts.is_closed, 0) = 0 AND t.closed_datetime IS NULL AND t.deleted_datetime IS NULL";

    // Tile: open tickets
    $openTickets = 0;
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM tickets t
               LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
              WHERE 1=1" . $ttSql . $openPredicate
        );
        $stmt->execute($ttParams);
        $openTickets = (int)$stmt->fetchColumn();
    } catch (Exception $e) { /* leave 0 */ }

    // Chart: open tickets by priority
    $openByPriority = exec_group_query(
        $conn,
        "SELECT COALESCE(tp.name, 'No priority') AS grp, COUNT(*) AS cnt
           FROM tickets t
           LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
           LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
          WHERE 1=1" . $ttSql . $openPredicate . "
       GROUP BY grp
       ORDER BY cnt DESC, grp ASC",
        $ttParams
    );

    // SLA resolution outcome — reuse the shared report path (Phase 8a snapshot).
    // Breach rate = breached / tracked, where "tracked" excludes "Not tracked".
    $slaResolution = [];
    $slaBreached = 0;
    $slaTracked = 0;
    try {
        $report = ticket_report_run($conn, $analystId, 'sla_resolution_outcome', []);
        $slaResolution = $report['rows'];
        foreach ($slaResolution as $row) {
            if ($row['label'] === 'Not tracked') continue;
            $slaTracked += $row['count'];
            if ($row['label'] === 'Breached') $slaBreached += $row['count'];
        }
    } catch (Exception $e) { /* leave empty */ }
    $slaPct = $slaTracked > 0 ? round($slaBreached / $slaTracked * 100, 1) : null;

    // --- Assets by status (cross-module; generic tenant scope) -------------
    list($aSql, $aParams) = activeTenantFilter($conn, $analystId, 'a', 'tenant_id');
    $assetsByStatus = exec_group_query(
        $conn,
        "SELECT COALESCE(ast.name, 'No status') AS grp, COUNT(*) AS cnt
           FROM assets a
           LEFT JOIN asset_status_types ast ON ast.id = a.asset_status_id
          WHERE 1=1" . $aSql . "
       GROUP BY grp
       ORDER BY cnt DESC, grp ASC",
        $aParams
    );

    // --- Changes by state (cross-module; generic tenant scope) -------------
    list($cSql, $cParams) = activeTenantFilter($conn, $analystId, 'c', 'tenant_id');
    $changesByState = exec_group_query(
        $conn,
        "SELECT COALESCE(cs.name, 'No status') AS grp, COUNT(*) AS cnt
           FROM changes c
           LEFT JOIN change_statuses cs ON cs.id = c.status_id
          WHERE 1=1" . $cSql . "
       GROUP BY grp
       ORDER BY cnt DESC, grp ASC",
        $cParams
    );

    echo json_encode([
        'success'     => true,
        'scope_label' => $scopeLabel,
        'tiles' => [
            'open_tickets' => $openTickets,
            'sla' => ['pct' => $slaPct, 'breached' => $slaBreached, 'tracked' => $slaTracked],
        ],
        'charts' => [
            'open_by_priority' => $openByPriority,
            'sla_resolution'   => $slaResolution,
            'assets_by_status' => $assetsByStatus,
            'changes_by_state' => $changesByState,
        ],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
