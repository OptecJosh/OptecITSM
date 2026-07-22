<?php
/**
 * API: Overtime report (Phase 11d).
 *
 * Aggregates overtime by agent for a period, with hours and payroll-weighted
 * hours (hours × rate_multiplier). Scope: admins see all; everyone else sees
 * their own + their direct reports. Always company-scoped via activeTenantFilter
 * (no-op at N=1) so a payroll run is per-company.
 *
 * GET params: date_from, date_to (YYYY-MM-DD), status (approved|pending|all,
 * default approved), format=csv (per-entry payroll export).
 * JSON returns { success, rows:[{agent_name,count,hours,weighted}], totals:{...} }.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
header('Content-Type: application/json');   // overridden for CSV below

$isCsv = (($_GET['format'] ?? '') === 'csv');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('overtime');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $isAdmin = analystIsAdmin($conn, $analystId);

    // Filters.
    $where = " WHERE 1=1";
    $params = [];

    $status = $_GET['status'] ?? 'approved';
    if (in_array($status, ['approved', 'pending'], true)) {
        $where .= " AND o.status = ?";
        $params[] = $status;
    } // 'all' → no status predicate (still excludes nothing)

    if (!empty($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'])) {
        $where .= " AND o.work_date >= ?";
        $params[] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])) {
        $where .= " AND o.work_date <= ?";
        $params[] = $_GET['date_to'];
    }

    // Relationship scope: admin sees all; others see own + direct reports.
    if (!$isAdmin) {
        $where .= " AND (o.analyst_id = ? OR a.manager_id = ?)";
        $params[] = $analystId;
        $params[] = $analystId;
    }

    // Company scope (payroll is per active company; no-op at N=1).
    [$tenantSql, $tenantParams] = activeTenantFilter($conn, $analystId, 'o');
    $where .= $tenantSql;
    foreach ($tenantParams as $tp) $params[] = $tp;

    if ($isCsv) {
        $sql = "SELECT a.full_name AS agent_name, o.work_date, o.start_time, o.end_time,
                       o.hours, o.overtime_type, o.rate_multiplier,
                       (o.hours * o.rate_multiplier) AS weighted, o.status
                  FROM overtime_requests o JOIN analysts a ON a.id = o.analyst_id
                  $where
              ORDER BY a.full_name, o.work_date";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="overtime-report-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Agent', 'Date', 'Start', 'End', 'Hours', 'Type', 'Multiplier', 'Weighted hours', 'Status']);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                $r['agent_name'], $r['work_date'], substr((string)$r['start_time'], 0, 5),
                substr((string)$r['end_time'], 0, 5), $r['hours'], $r['overtime_type'],
                $r['rate_multiplier'], $r['weighted'], $r['status'],
            ]);
        }
        fclose($out);
        exit;
    }

    $sql = "SELECT a.full_name AS agent_name, COUNT(*) AS cnt,
                   SUM(o.hours) AS hours, SUM(o.hours * o.rate_multiplier) AS weighted
              FROM overtime_requests o JOIN analysts a ON a.id = o.analyst_id
              $where
          GROUP BY o.analyst_id, a.full_name
          ORDER BY a.full_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    $totals = ['count' => 0, 'hours' => 0.0, 'weighted' => 0.0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'agent_name' => $r['agent_name'],
            'count'      => (int)$r['cnt'],
            'hours'      => round((float)$r['hours'], 2),
            'weighted'   => round((float)$r['weighted'], 2),
        ];
        $totals['count']    += (int)$r['cnt'];
        $totals['hours']    += (float)$r['hours'];
        $totals['weighted'] += (float)$r['weighted'];
    }
    $totals['hours'] = round($totals['hours'], 2);
    $totals['weighted'] = round($totals['weighted'], 2);

    echo json_encode(['success' => true, 'rows' => $rows, 'totals' => $totals]);

} catch (Exception $e) {
    if ($isCsv) { http_response_code(500); echo 'Error: ' . $e->getMessage(); exit; }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
