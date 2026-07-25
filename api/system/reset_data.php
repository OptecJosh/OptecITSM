<?php
/**
 * API: test-data reset — preview or execute.
 *
 * POST JSON { mode: preview|execute, groups: [ids], confirm: "<phrase>" }
 *
 * preview → per-group row counts and the exact table list, writes nothing.
 * execute → deletes those tables in one transaction, and requires the confirmation
 *           phrase typed exactly (data_reset_confirm_phrase()).
 *
 * Admin only. Only tables named in the data_reset_groups() registry can ever be
 * touched, and an unknown group id is ignored rather than widened — the request
 * cannot name a table of its own.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // auth + admin
require_once '../../includes/functions.php';
require_once '../../includes/data_reset.php';
header('Content-Type: application/json');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode = ($in['mode'] ?? 'preview') === 'execute' ? 'execute' : 'preview';
    $groupIds = is_array($in['groups'] ?? null) ? array_values(array_filter($in['groups'], 'is_string')) : [];

    if (!$groupIds) throw new Exception('Choose at least one group of data to delete');

    $plan = data_reset_plan($conn, $groupIds);
    if (!$plan['groups']) throw new Exception('None of those groups exist');

    if ($mode === 'preview') {
        echo json_encode([
            'success'        => true,
            'mode'           => 'preview',
            'total'          => $plan['total'],
            'groups'         => $plan['groups'],
            'tables'         => $plan['tables'],
            'missing'        => $plan['missing'],
            'confirm_phrase' => data_reset_confirm_phrase(),
        ]);
        exit;
    }

    // ---- execute ----
    if (trim((string)($in['confirm'] ?? '')) !== data_reset_confirm_phrase()) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Type ' . data_reset_confirm_phrase() . ' exactly to confirm.',
        ]);
        exit;
    }

    $result = data_reset_execute($conn, $plan['tables']);
    data_reset_log($conn, $analystId, array_column($plan['groups'], 'id'), $result['total']);

    echo json_encode([
        'success' => true,
        'mode'    => 'execute',
        'total'   => $result['total'],
        'deleted' => $result['deleted'],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
