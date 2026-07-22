<?php
/**
 * API: Create or update a scheduled report (Phase 8b).
 *
 * POST JSON { id?, name, group_by, filters, cadence, format, recipients,
 *             is_shared?, is_active? }
 *
 * Ownership mirrors ticket_queues: shared reports (is_shared, owner NULL)
 * require admin; personal reports are owned by the creator; editing a personal
 * report requires ownership (or admin). group_by / cadence / format are
 * validated against their whitelists; recipients must contain >=1 valid email.
 * next_run_at is (re)computed from the cadence on create and whenever the
 * cadence changes, so a schedule always has a concrete next due time.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/scheduled_report.php';
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

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id        = !empty($data['id']) ? (int)$data['id'] : null;
    $name      = trim((string)($data['name'] ?? ''));
    $groupBy   = (string)($data['group_by'] ?? 'status');
    $cadence   = (string)($data['cadence'] ?? 'weekly');
    $format    = (string)($data['format'] ?? 'both');
    $isShared  = !empty($data['is_shared']);
    $isActive  = array_key_exists('is_active', $data) ? (int)(bool)$data['is_active'] : 1;
    $filters   = scheduled_report_clean_filters($data['filters'] ?? []);

    if ($name === '') throw new Exception('Report name is required');
    if (mb_strlen($name) > 150) $name = mb_substr($name, 0, 150);
    if (!isset(ticket_report_dims()[$groupBy])) throw new Exception('Invalid group_by');
    if (!in_array($cadence, scheduled_report_cadences(), true)) throw new Exception('Invalid cadence');
    if (!in_array($format, scheduled_report_formats(), true)) throw new Exception('Invalid format');
    if ($isShared && !$isAdmin) throw new Exception('Only administrators can create shared reports');

    $recipients = scheduled_report_parse_recipients((string)($data['recipients'] ?? ''));
    if (!$recipients) throw new Exception('At least one valid recipient email is required');
    $recipientsStr = implode(', ', $recipients);

    $json = json_encode($filters);

    if ($id) {
        $cur = $conn->prepare("SELECT owner_analyst_id, cadence FROM scheduled_report WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Scheduled report not found');
        $ownerId   = $row['owner_analyst_id'];
        $wasShared = ($ownerId === null);

        if ($wasShared) {
            if (!$isAdmin) throw new Exception('Only administrators can edit shared reports');
        } elseif ((int)$ownerId !== $analystId && !$isAdmin) {
            throw new Exception('You can only edit your own reports');
        }

        $newOwner = $isShared ? null : ($wasShared ? $analystId : (int)$ownerId);
        // Recompute the next run only when the cadence changes, so an edit to
        // recipients/filters doesn't reset the schedule.
        $recomputeNext = ($row['cadence'] !== $cadence);

        if ($recomputeNext) {
            $nextRun = scheduled_report_next_run($cadence);
            $conn->prepare(
                "UPDATE scheduled_report
                    SET name = ?, group_by = ?, filters_json = ?, cadence = ?, format = ?,
                        recipients = ?, owner_analyst_id = ?, is_active = ?, next_run_at = ?,
                        updated_datetime = UTC_TIMESTAMP()
                  WHERE id = ?"
            )->execute([$name, $groupBy, $json, $cadence, $format, $recipientsStr, $newOwner, $isActive, $nextRun, $id]);
        } else {
            $conn->prepare(
                "UPDATE scheduled_report
                    SET name = ?, group_by = ?, filters_json = ?, cadence = ?, format = ?,
                        recipients = ?, owner_analyst_id = ?, is_active = ?,
                        updated_datetime = UTC_TIMESTAMP()
                  WHERE id = ?"
            )->execute([$name, $groupBy, $json, $cadence, $format, $recipientsStr, $newOwner, $isActive, $id]);
        }
        $newId = $id;
    } else {
        $owner = $isShared ? null : $analystId;
        $nextRun = scheduled_report_next_run($cadence);
        $conn->prepare(
            "INSERT INTO scheduled_report
                (name, group_by, filters_json, cadence, format, recipients, owner_analyst_id,
                 is_active, next_run_at, created_by_analyst_id, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$name, $groupBy, $json, $cadence, $format, $recipientsStr, $owner, $isActive, $nextRun, $analystId]);
        $newId = (int)$conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $newId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
