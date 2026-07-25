<?php
/**
 * LMS API: record or update a completed training item for an analyst.
 *
 * POST JSON { id?, analyst_id, title, provider?, training_type?, completed_date?,
 *             hours?, cost?, course_id?, evidence_url?, notes? }
 *
 * This is the OFFLINE/external training log — conferences, vendor courses, exams,
 * on-the-job. Internal LMS course completions come from lms_progress and are not
 * duplicated here; course_id is only for tying an external record to a course.
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

try {
    $conn = connectToDatabase();
    $viewerId = (int)$_SESSION['analyst_id'];
    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    $id = !empty($in['id']) ? (int)$in['id'] : null;
    $analystId = !empty($in['analyst_id']) ? (int)$in['analyst_id'] : $viewerId;

    if ($id) {
        $owner = $conn->prepare("SELECT analyst_id FROM analyst_training_records WHERE id = ?");
        $owner->execute([$id]);
        $ownerId = $owner->fetchColumn();
        if ($ownerId === false) throw new Exception('Training record not found');
        $analystId = (int)$ownerId;
    }
    if (!lmsCanEditTraining($conn, $viewerId, $analystId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You cannot change this training record.']);
        exit;
    }

    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') throw new Exception('A title is required');
    $title = mb_substr($title, 0, 200);

    $type = in_array($in['training_type'] ?? '', lmsTrainingTypes(), true) ? $in['training_type'] : 'course';

    $completed = trim((string)($in['completed_date'] ?? ''));
    if ($completed !== '') {
        $ts = strtotime($completed);
        if ($ts === false) throw new Exception('Completed date is not a valid date');
        $completed = date('Y-m-d', $ts);
    } else {
        $completed = null;
    }

    $numeric = function ($raw, string $label) {
        $raw = trim((string)$raw);
        if ($raw === '') return null;
        if (!is_numeric($raw)) throw new Exception("$label must be a number");
        if ((float)$raw < 0) throw new Exception("$label cannot be negative");
        return (string)round((float)$raw, 2);
    };
    $hours = $numeric($in['hours'] ?? '', 'Hours');
    $cost  = $numeric($in['cost'] ?? '', 'Cost');

    $courseId = !empty($in['course_id']) ? (int)$in['course_id'] : null;
    if ($courseId) {
        $c = $conn->prepare("SELECT 1 FROM lms_courses WHERE id = ?");
        $c->execute([$courseId]);
        if (!$c->fetchColumn()) $courseId = null;   // course deleted since — keep the record
    }

    $provider = trim((string)($in['provider'] ?? ''));
    $evidence = trim((string)($in['evidence_url'] ?? ''));
    $notes    = trim((string)($in['notes'] ?? ''));

    $fields = [
        $title,
        $provider !== '' ? mb_substr($provider, 0, 150) : null,
        $type,
        $completed,
        $hours,
        $cost,
        $courseId,
        $evidence !== '' ? mb_substr($evidence, 0, 500) : null,
        $notes !== '' ? mb_substr($notes, 0, 1000) : null,
    ];

    if ($id) {
        $conn->prepare(
            "UPDATE analyst_training_records
                SET title = ?, provider = ?, training_type = ?, completed_date = ?, hours = ?, cost = ?,
                    course_id = ?, evidence_url = ?, notes = ?, updated_datetime = UTC_TIMESTAMP()
              WHERE id = ?"
        )->execute(array_merge($fields, [$id]));
        $newId = $id;
    } else {
        $conn->prepare(
            "INSERT INTO analyst_training_records
                (analyst_id, title, provider, training_type, completed_date, hours, cost,
                 course_id, evidence_url, notes, created_by_id, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute(array_merge([$analystId], $fields, [$viewerId]));
        $newId = (int)$conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $newId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
