<?php
/**
 * API: Export an entity to CSV (Phase 10b). Admin only.
 * GET ?entity=assets|users → CSV of the entity's whitelisted columns
 * (data_io.php). The header row matches the columns an import expects, so an
 * export → edit → import round-trip works.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // auth + admin
require_once '../../includes/data_io.php';

try {
    $entities = data_io_entities();
    $key = $_GET['entity'] ?? '';
    if (!isset($entities[$key])) throw new Exception('Invalid entity');
    $spec = $entities[$key];

    $conn = connectToDatabase();
    $cols = array_keys($spec['columns']);
    $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));

    $stmt = $conn->query("SELECT $colList FROM `{$spec['table']}` ORDER BY `{$spec['match']}` ASC");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $key . '-export-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $cols);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, array_map(fn($c) => $row[$c], $cols));
    }
    fclose($out);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
