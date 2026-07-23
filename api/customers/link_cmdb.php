<?php
/**
 * API: Link / unlink a CMDB object (CI) to a customer.
 * POST JSON { customer_id, cmdb_object_id, action: link|unlink }.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('customers');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : 0;
    $objectId   = !empty($data['cmdb_object_id']) ? (int)$data['cmdb_object_id'] : 0;
    $action     = ($data['action'] ?? 'link') === 'unlink' ? 'unlink' : 'link';
    if ($customerId <= 0 || $objectId <= 0) throw new Exception('customer_id and cmdb_object_id are required');

    if ($action === 'unlink') {
        $conn->prepare("DELETE FROM customer_cmdb_objects WHERE customer_id = ? AND cmdb_object_id = ?")->execute([$customerId, $objectId]);
        echo json_encode(['success' => true]);
        exit;
    }

    $chk = $conn->prepare("SELECT 1 FROM customers WHERE id = ?"); $chk->execute([$customerId]);
    if (!$chk->fetchColumn()) throw new Exception('Customer not found');
    $chk = $conn->prepare("SELECT 1 FROM cmdb_objects WHERE id = ?"); $chk->execute([$objectId]);
    if (!$chk->fetchColumn()) throw new Exception('CMDB object not found');

    try {
        $conn->prepare("INSERT INTO customer_cmdb_objects (customer_id, cmdb_object_id, created_datetime, created_by_analyst_id) VALUES (?, ?, UTC_TIMESTAMP(), ?)")
             ->execute([$customerId, $objectId, $analystId]);
        echo json_encode(['success' => true, 'already_linked' => false]);
    } catch (PDOException $pe) {
        if ($pe->errorInfo[1] == 1062) { echo json_encode(['success' => true, 'already_linked' => true]); exit; }
        throw $pe;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
