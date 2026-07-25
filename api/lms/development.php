<?php
/**
 * LMS API: one analyst's development record — certifications, external training
 * and their LMS course completions, plus the certification catalogue.
 *
 * GET ?analyst_id=<id> (defaults to the caller).
 *
 * Visibility: yourself, your direct reports (line manager), or anyone if you can
 * manage the LMS — lmsCanViewTraining(). The roster tells the UI who it may open.
 * The journal is deliberately NOT here; it has its own endpoint and a stricter
 * rule (owner + line manager only).
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
    $analystId = !empty($_GET['analyst_id']) ? (int)$_GET['analyst_id'] : $viewerId;

    if (!lmsCanViewTraining($conn, $viewerId, $analystId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You cannot view this training record.']);
        exit;
    }

    $who = $conn->prepare("SELECT a.id, a.full_name, a.tier, m.full_name AS manager_name
                             FROM analysts a LEFT JOIN analysts m ON m.id = a.manager_id
                            WHERE a.id = ?");
    $who->execute([$analystId]);
    $analyst = $who->fetch(PDO::FETCH_ASSOC);
    if (!$analyst) throw new Exception('Analyst not found');

    // Certifications held / in progress, soonest expiry first.
    $certs = $conn->prepare(
        "SELECT ac.*, DATEDIFF(ac.expires_date, CURDATE()) AS days_to_expiry, c.name AS catalogue_name
           FROM analyst_certifications ac
      LEFT JOIN lms_certifications c ON c.id = ac.certification_id
          WHERE ac.analyst_id = ?
       ORDER BY ac.expires_date IS NULL, ac.expires_date ASC, ac.title ASC"
    );
    $certs->execute([$analystId]);

    // External / offline training, most recent first.
    $training = $conn->prepare(
        "SELECT tr.*, c.title AS course_title
           FROM analyst_training_records tr
      LEFT JOIN lms_courses c ON c.id = tr.course_id
          WHERE tr.analyst_id = ?
       ORDER BY tr.completed_date IS NULL, tr.completed_date DESC, tr.id DESC"
    );
    $training->execute([$analystId]);

    // LMS course completions — the internal half of the training history.
    $completions = $conn->prepare(
        "SELECT p.course_id, c.title, p.status, p.score_raw, p.score_max, p.completion_datetime, p.last_access
           FROM lms_progress p
           JOIN lms_courses c ON c.id = p.course_id
          WHERE p.analyst_id = ?
       ORDER BY p.completion_datetime IS NULL, p.completion_datetime DESC, c.title"
    );
    $completions->execute([$analystId]);

    $catalogue = [];
    try {
        $catalogue = $conn->query(
            "SELECT id, name, issuer, description, validity_months, is_active, display_order
               FROM lms_certifications WHERE is_active = 1 ORDER BY display_order, name"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { /* pre-Database-Verify */ }

    echo json_encode([
        'success'     => true,
        'analyst'     => [
            'id'           => (int)$analyst['id'],
            'full_name'    => $analyst['full_name'],
            'tier'         => $analyst['tier'] ?? null,
            'manager_name' => $analyst['manager_name'],
        ],
        'is_own'      => $analystId === $viewerId,
        'can_edit'    => lmsCanEditTraining($conn, $viewerId, $analystId),
        'can_manage'  => lmsCanManage($conn, $viewerId),
        'roster'      => lmsTrainingRoster($conn, $viewerId),
        'certifications' => $certs->fetchAll(PDO::FETCH_ASSOC),
        'training'    => $training->fetchAll(PDO::FETCH_ASSOC),
        'completions' => $completions->fetchAll(PDO::FETCH_ASSOC),
        'catalogue'   => $catalogue,
        'statuses'    => lmsCertificationStatuses(),
        'types'       => lmsTrainingTypes(),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
