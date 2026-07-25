<?php
/**
 * API: the deletable data groups with live row counts, for System > Reset data.
 * GET → { success, groups:[{id,label,description,rows,tables:[{table,rows}]}],
 *         total, confirm_phrase }
 *
 * Read-only and admin-only. Counting every group up front is the point: the page
 * should say "12,481 rows across 9 groups" before anyone reaches for a button.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // auth + admin
require_once '../../includes/functions.php';
require_once '../../includes/data_reset.php';
header('Content-Type: application/json');

try {
    $conn = connectToDatabase();
    $plan = data_reset_plan($conn, array_keys(data_reset_groups()));

    echo json_encode([
        'success'        => true,
        'groups'         => $plan['groups'],
        'total'          => $plan['total'],
        'missing'        => $plan['missing'],
        'confirm_phrase' => data_reset_confirm_phrase(),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
