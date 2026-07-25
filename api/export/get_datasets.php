<?php
/**
 * API: the datasets this analyst may export (module data export).
 * GET (no params) → { success, datasets:[{key,label,group,description,has_date,
 * admin_only}] }, grouped client-side. Only datasets whose module the caller can
 * access are returned, admin-only ones only for admins, and only those whose
 * table actually exists on this install.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/data_export.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('reporting');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $isAdmin = analystIsAdmin($conn, $analystId);
    $moduleNames = getModuleRegistry();

    $out = [];
    foreach (data_export_datasets() as $key => $spec) {
        if (!empty($spec['admin_only']) && !$isAdmin) continue;
        if (!analystCanAccessModule($conn, $analystId, $spec['module'])) continue;
        if (!data_export_available($conn, $spec)) continue;

        $out[] = [
            'key'         => $key,
            'label'       => $spec['label'],
            'group'       => $spec['group'] ?? ($moduleNames[$spec['module']] ?? ucfirst($spec['module'])),
            'description' => $spec['description'] ?? '',
            'has_date'    => !empty($spec['date']),
            'date_field'  => $spec['date'] ?? null,
            'tenant'      => !empty($spec['tenant']),
            'admin_only'  => !empty($spec['admin_only']),
        ];
    }

    echo json_encode([
        'success'   => true,
        'datasets'  => $out,
        'max_rows'  => data_export_max_rows(),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
