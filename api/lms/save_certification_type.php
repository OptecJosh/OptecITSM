<?php
/**
 * LMS API: manage the certification catalogue (lms_certifications).
 *
 * POST JSON { id?, name, issuer?, description?, validity_months?, is_active?,
 *             display_order? }  — or { id, delete: true }.
 *
 * LMS managers only: the catalogue is organisation-wide, unlike the per-analyst
 * records. Deleting a catalogue entry leaves any held certification in place
 * (FK SET NULL) — the person still holds it.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/lms_access.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('lms');

try {
    $conn = connectToDatabase();
    $viewerId = (int)$_SESSION['analyst_id'];
    if (!lmsCanManage($conn, $viewerId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only LMS managers can change the certification catalogue.']);
        exit;
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = !empty($in['id']) ? (int)$in['id'] : null;

    if ($id && !empty($in['delete'])) {
        $conn->prepare("DELETE FROM lms_certifications WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true, 'deleted' => true]);
        exit;
    }

    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') throw new Exception('A name is required');
    $name = mb_substr($name, 0, 150);

    $issuer = trim((string)($in['issuer'] ?? ''));
    $desc   = trim((string)($in['description'] ?? ''));
    $validity = trim((string)($in['validity_months'] ?? ''));
    if ($validity !== '' && (!ctype_digit($validity) || (int)$validity <= 0)) {
        throw new Exception('Validity must be a whole number of months');
    }

    $fields = [
        $name,
        $issuer !== '' ? mb_substr($issuer, 0, 150) : null,
        $desc !== '' ? mb_substr($desc, 0, 500) : null,
        $validity !== '' ? (int)$validity : null,
        array_key_exists('is_active', $in) ? (int)(bool)$in['is_active'] : 1,
        isset($in['display_order']) ? (int)$in['display_order'] : 0,
    ];

    if ($id) {
        $conn->prepare(
            "UPDATE lms_certifications
                SET name = ?, issuer = ?, description = ?, validity_months = ?, is_active = ?, display_order = ?,
                    updated_datetime = UTC_TIMESTAMP()
              WHERE id = ?"
        )->execute(array_merge($fields, [$id]));
        $newId = $id;
    } else {
        $conn->prepare(
            "INSERT INTO lms_certifications
                (name, issuer, description, validity_months, is_active, display_order,
                 created_by_id, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute(array_merge($fields, [$viewerId]));
        $newId = (int)$conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $newId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
