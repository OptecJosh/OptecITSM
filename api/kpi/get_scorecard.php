<?php
/**
 * API: KPI scorecard for a period (K0).
 * GET ?period=YYYY-MM (default current month).
 * Returns definitions grouped by scorecard/section, each with the period's
 * measurement + a 6-month trend + computed RAG. Team-wide (not company-scoped).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/kpi.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('kpi');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $period = kpi_valid_period($_GET['period'] ?? '') ?? date('Y-m');
    $window = kpi_period_window($period, 6);

    // Definitions.
    $defs = $conn->query(
        "SELECT id, scorecard, section, name, description, target_text, source_status,
                cadence, unit, direction, green_threshold, amber_threshold
           FROM kpi_definitions
          WHERE is_active = 1
       ORDER BY FIELD(scorecard,'L1','L2','L3','L3_BAU','COMBINED'), display_order, id"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Measurements for the trend window (one query), keyed by kpi_id -> period -> value/status.
    $ph = implode(',', array_fill(0, count($window), '?'));
    $mstmt = $conn->prepare(
        "SELECT kpi_id, period_month, value, status, note
           FROM kpi_measurements WHERE period_month IN ($ph)"
    );
    $mstmt->execute($window);
    $byKpi = [];
    foreach ($mstmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $byKpi[(int)$m['kpi_id']][$m['period_month']] = $m;
    }

    $scorecards = [];   // scorecard => section => [rows]
    foreach ($defs as $d) {
        $id = (int)$d['id'];
        $meas = $byKpi[$id][$period] ?? null;
        $value = $meas && $meas['value'] !== null ? (float)$meas['value'] : null;

        // Status: explicit hand-set status wins; else auto from thresholds.
        $status = $meas['status'] ?? null;
        if (!$status || $status === 'info') {
            $auto = kpi_compute_status($d['direction'], $d['green_threshold'], $d['amber_threshold'], $value);
            if ($auto !== 'info') $status = $auto;
        }
        $status = $status ?: 'na';

        // 6-month trend (nulls where no measurement).
        $trend = [];
        foreach ($window as $p) {
            $row = $byKpi[$id][$p] ?? null;
            $trend[] = ($row && $row['value'] !== null) ? (float)$row['value'] : null;
        }

        $sc = $d['scorecard'];
        $sec = $d['section'] ?: '';
        $scorecards[$sc][$sec][] = [
            'id'          => $id,
            'name'        => $d['name'],
            'description' => $d['description'],
            'target_text' => $d['target_text'],
            'source'      => $d['source_status'],
            'cadence'     => $d['cadence'],
            'unit'        => $d['unit'],
            'direction'   => $d['direction'],
            'green'       => $d['green_threshold'] !== null ? (float)$d['green_threshold'] : null,
            'amber'       => $d['amber_threshold'] !== null ? (float)$d['amber_threshold'] : null,
            'value'       => $value,
            'status'      => $status,
            'note'        => $meas['note'] ?? null,
            'trend'       => $trend,
        ];
    }

    echo json_encode([
        'success'     => true,
        'period'      => $period,
        'window'      => $window,
        'labels'      => kpi_scorecards(),
        'scorecards'  => $scorecards,
        'can_measure' => true,                          // any module-access analyst may record
        'can_edit'    => analystIsAdmin($conn, $analystId),  // admins edit targets/thresholds
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
