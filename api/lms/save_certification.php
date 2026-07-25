<?php
/**
 * LMS API: record or update a certification held by an analyst.
 *
 * POST JSON { id?, analyst_id, certification_id?, title?, issuer?, status?,
 *             awarded_date?, expires_date?, credential_id?, evidence_url?, notes? }
 *
 * title falls back to the catalogue entry's name when certification_id is given,
 * so a caller can pick from the catalogue OR type a free-text certification.
 * If expires_date is omitted and the catalogue entry has validity_months, the
 * expiry is derived from awarded_date — that is what the catalogue is for.
 *
 * Writable by the analyst themselves, their line manager, or an LMS manager.
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

/** '' → null, otherwise a normalised 'Y-m-d' date. Throws on garbage. */
function cert_date($v, string $label): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    $ts = strtotime($v);
    if ($ts === false) throw new Exception("$label is not a valid date");
    return date('Y-m-d', $ts);
}

try {
    $conn = connectToDatabase();
    $viewerId = (int)$_SESSION['analyst_id'];
    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    $id = !empty($in['id']) ? (int)$in['id'] : null;
    $analystId = !empty($in['analyst_id']) ? (int)$in['analyst_id'] : $viewerId;

    // On update, the row's owner decides who may write — not the payload.
    if ($id) {
        $owner = $conn->prepare("SELECT analyst_id FROM analyst_certifications WHERE id = ?");
        $owner->execute([$id]);
        $ownerId = $owner->fetchColumn();
        if ($ownerId === false) throw new Exception('Certification not found');
        $analystId = (int)$ownerId;
    }
    if (!lmsCanEditTraining($conn, $viewerId, $analystId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You cannot change this training record.']);
        exit;
    }

    $certificationId = !empty($in['certification_id']) ? (int)$in['certification_id'] : null;
    $catalogue = null;
    if ($certificationId) {
        $c = $conn->prepare("SELECT id, name, issuer, validity_months FROM lms_certifications WHERE id = ?");
        $c->execute([$certificationId]);
        $catalogue = $c->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$catalogue) throw new Exception('Unknown certification');
    }

    $title = trim((string)($in['title'] ?? ''));
    if ($title === '' && $catalogue) $title = (string)$catalogue['name'];
    if ($title === '') throw new Exception('A certification name is required');
    $title = mb_substr($title, 0, 150);

    $issuer = trim((string)($in['issuer'] ?? ''));
    if ($issuer === '' && $catalogue) $issuer = (string)($catalogue['issuer'] ?? '');
    $issuer = $issuer !== '' ? mb_substr($issuer, 0, 150) : null;

    $status = in_array($in['status'] ?? '', lmsCertificationStatuses(), true) ? $in['status'] : 'achieved';
    $awarded = cert_date($in['awarded_date'] ?? '', 'Awarded date');
    $expires = cert_date($in['expires_date'] ?? '', 'Expiry date');

    // Derive the expiry from the catalogue's validity period when not given.
    if ($expires === null && $awarded !== null && $catalogue && !empty($catalogue['validity_months'])) {
        $expires = date('Y-m-d', strtotime($awarded . ' +' . (int)$catalogue['validity_months'] . ' months'));
    }
    if ($expires !== null && $awarded !== null && $expires < $awarded) {
        throw new Exception('Expiry date cannot be before the awarded date');
    }

    $credential = trim((string)($in['credential_id'] ?? ''));
    $evidence   = trim((string)($in['evidence_url'] ?? ''));
    $notes      = trim((string)($in['notes'] ?? ''));

    $fields = [
        $certificationId,
        $title,
        $issuer,
        $status,
        $awarded,
        $expires,
        $credential !== '' ? mb_substr($credential, 0, 120) : null,
        $evidence !== '' ? mb_substr($evidence, 0, 500) : null,
        $notes !== '' ? mb_substr($notes, 0, 1000) : null,
    ];

    if ($id) {
        $conn->prepare(
            "UPDATE analyst_certifications
                SET certification_id = ?, title = ?, issuer = ?, status = ?, awarded_date = ?, expires_date = ?,
                    credential_id = ?, evidence_url = ?, notes = ?, updated_datetime = UTC_TIMESTAMP()
              WHERE id = ?"
        )->execute(array_merge($fields, [$id]));
        $newId = $id;
    } else {
        $conn->prepare(
            "INSERT INTO analyst_certifications
                (analyst_id, certification_id, title, issuer, status, awarded_date, expires_date,
                 credential_id, evidence_url, notes, created_by_id, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute(array_merge([$analystId], $fields, [$viewerId]));
        $newId = (int)$conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $newId, 'expires_date' => $expires]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
