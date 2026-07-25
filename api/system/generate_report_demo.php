<?php
/**
 * API: generate (or remove) the reporting demo history.
 *
 * POST action=status                       → what's there now, and what's missing
 *      action=generate  months, per_month, seed, assign_tiers, purge_first
 *      action=purge                        → delete every DMO- ticket
 *
 * Administrators only (admin_api_guard). This writes a lot of ticket data, so it
 * is deliberately not reachable by a non-admin with the Tickets module.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';
require_once '../../includes/functions.php';
require_once '../../includes/demo_reporting.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Tens of thousands of inserts: the default 30s can be too tight on a small box.
@set_time_limit(600);

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $action = $_POST['action'] ?? $_GET['action'] ?? 'status';

    if ($action === 'status') {
        $look = demoReportingLookups($conn);
        $st = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_number LIKE ?");
        $st->execute([demoReportingPrefix() . '-%']);
        $existing = (int)$st->fetchColumn();

        $untiered = 0;
        try {
            $untiered = (int)$conn->query("SELECT COUNT(*) FROM analysts WHERE (tier IS NULL OR tier = '') AND (is_active = 1 OR is_active IS NULL)")->fetchColumn();
        } catch (Exception $e) { /* column may predate the KPI phase */ }

        echo json_encode([
            'success'         => true,
            'existing'        => $existing,
            'ready'           => $look['ok'],
            'missing'         => $look['missing'],
            'analysts'        => count($look['analysts']),
            'untiered'        => $untiered,
            'customers'       => count($look['customers']),
            'portal_users'    => count($look['users']),
            'max_months'      => demoReportingMaxMonths(),
            'max_per_month'   => demoReportingMaxPerMonth(),
            'prefix'          => demoReportingPrefix(),
        ]);
        exit;
    }

    if ($action === 'purge') {
        $res = demoReportingPurge($conn);
        try {
            $conn->prepare("INSERT INTO system_logs (log_type, analyst_id, details, created_datetime) VALUES ('demo_data', ?, ?, UTC_TIMESTAMP())")
                 ->execute([$analystId, "Purged reporting demo: {$res['tickets']} tickets, {$res['children']} child rows"]);
        } catch (Exception $e) { /* non-fatal */ }
        echo json_encode(['success' => true] + $res);
        exit;
    }

    if ($action === 'generate') {
        $result = demoReportingGenerate($conn, $analystId, [
            'months'       => (int)($_POST['months'] ?? 18),
            'per_month'    => (int)($_POST['per_month'] ?? 120),
            'seed'         => $_POST['seed'] ?? '',
            'assign_tiers' => !empty($_POST['assign_tiers']) && $_POST['assign_tiers'] !== 'false',
            'purge_first'  => !empty($_POST['purge_first']) && $_POST['purge_first'] !== 'false',
        ]);
        echo json_encode(['success' => true] + $result);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
