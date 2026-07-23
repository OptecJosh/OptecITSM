<?php
/**
 * API: List ticket work streams (NOC/SOC) for the KPI stream dropdown (K1c).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();
    $rows = $conn->query("SELECT id, name, is_active, display_order FROM ticket_streams ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'streams' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
