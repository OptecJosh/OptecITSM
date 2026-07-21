<?php
/**
 * API: Create Ticket from Self-Service Portal
 * POST - Creates a new ticket for the logged-in user
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$userId = (int)$_SESSION['ss_user_id'];
$input = json_decode(file_get_contents('php://input'), true);

$subject = trim($input['subject'] ?? '');
$description = trim($input['description'] ?? '');
$priority = $input['priority'] ?? 'Normal';
$mailboxId = !empty($input['mailbox_id']) ? (int)$input['mailbox_id'] : null;
$catalogItemId = !empty($input['catalog_item_id']) ? (int)$input['catalog_item_id'] : null;
$formAnswersInput = is_array($input['form_answers'] ?? null) ? $input['form_answers'] : [];
$inputAttachments = $input['attachments'] ?? [];
$recordingIds = array_values(array_filter(array_map('intval', $input['recording_ids'] ?? [])));

if (empty($subject)) {
    echo json_encode(['success' => false, 'error' => 'Subject is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Catalog request (Phase 7c): apply the item's default routing. An invalid or
    // inactive item id is ignored (treated as a plain request).
    $catCategoryId = null; $catDepartmentId = null; $catFormId = null;
    if ($catalogItemId) {
        $ci = $conn->prepare("SELECT category_id, department_id, priority_id, form_id FROM service_catalog_items WHERE id = ? AND is_active = 1");
        $ci->execute([$catalogItemId]);
        $item = $ci->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            $catalogItemId = null;
        } else {
            $catCategoryId   = $item['category_id']   !== null ? (int)$item['category_id']   : null;
            $catDepartmentId = $item['department_id'] !== null ? (int)$item['department_id'] : null;
            $catFormId       = $item['form_id']       !== null ? (int)$item['form_id']       : null;
            if ($item['priority_id'] !== null) {
                $pn = $conn->prepare("SELECT name FROM ticket_priorities WHERE id = ?");
                $pn->execute([(int)$item['priority_id']]);
                $n = $pn->fetchColumn();
                if ($n !== false) $priority = $n; // item's priority wins
            }
        }
    }

    // Catalog form (Phase 7c-2): if the item carries a form, validate the
    // submitted answers now and prepare both a readable summary (appended to the
    // ticket body) and the rows to persist as a linked submission. Validation
    // failures throw before the transaction opens. No workflow dispatch — the
    // form.submitted automation is for analyst/standalone fills, and firing it
    // here could raise a duplicate ticket.
    $formFields = [];        // ordered field rows for this form
    $formAnswers = [];       // field_id => stored string value
    $formSummaryHtml = '';   // appended to the initial email body
    if ($catalogItemId && $catFormId) {
        $ff = $conn->prepare("SELECT id, label, field_type, is_required FROM form_fields WHERE form_id = ? ORDER BY sort_order, id");
        $ff->execute([$catFormId]);
        $formFields = $ff->fetchAll(PDO::FETCH_ASSOC);

        $byId = [];
        foreach ($formFields as $fld) { $byId[(int)$fld['id']] = $fld; }
        foreach ($formAnswersInput as $fid => $val) {
            $fid = (int)$fid;
            if (!isset($byId[$fid])) continue; // ignore unknown fields
            if (is_bool($val)) $val = $val ? '1' : '0';
            if (is_array($val)) $val = json_encode(array_values($val));
            $formAnswers[$fid] = (string)$val;
        }

        foreach ($formFields as $fld) {
            $fid  = (int)$fld['id'];
            $val  = array_key_exists($fid, $formAnswers) ? $formAnswers[$fid] : '';
            $type = $fld['field_type'];
            if ((int)$fld['is_required'] === 1) {
                $empty = ($val === '' || $val === null);
                if ($type === 'checkbox' && (string)$val === '0') $empty = true;
                if ($type === 'checkboxes') { $d = json_decode((string)$val, true); $empty = !is_array($d) || count($d) === 0; }
                if ($empty) throw new Exception('"' . $fld['label'] . '" is required.');
            }
            if ($val !== '' && $val !== null) {
                if ($type === 'email'  && !filter_var((string)$val, FILTER_VALIDATE_EMAIL)) throw new Exception('"' . $fld['label'] . '" must be a valid email address.');
                if ($type === 'number' && !is_numeric((string)$val)) throw new Exception('"' . $fld['label'] . '" must be a number.');
            }
        }

        $rows = '';
        foreach ($formFields as $fld) {
            $fid = (int)$fld['id'];
            if (!array_key_exists($fid, $formAnswers)) continue;
            $val = $formAnswers[$fid];
            $decoded = json_decode($val, true);
            if (is_array($decoded)) $val = implode(', ', $decoded);
            if ($fld['field_type'] === 'checkbox') $val = ((string)$val === '1') ? 'Yes' : 'No';
            if ($val === '') continue;
            $rows .= '<tr><td style="padding:4px 12px 4px 0;font-weight:600;vertical-align:top;white-space:nowrap;">'
                   . htmlspecialchars($fld['label']) . '</td><td style="padding:4px 0;">' . nl2br(htmlspecialchars($val)) . '</td></tr>';
        }
        if ($rows !== '') {
            $formSummaryHtml = '<hr><p><strong>Request details</strong></p><table cellpadding="0" cellspacing="0">' . $rows . '</table>';
        }
    }

    // Validate the submitted priority against the CONFIGURED active priorities
    // (not a hardcoded Low/Normal/High list) so custom priorities like
    // Urgent/Critical are accepted (#40). Fall back to the default priority,
    // then Normal, then the first available, if the submitted one isn't valid.
    $activePrios = $conn->query("SELECT name, is_default FROM ticket_priorities WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $prioNames = array_column($activePrios, 'name');
    if (!in_array($priority, $prioNames, true)) {
        $default = '';
        foreach ($activePrios as $p) { if ((int)$p['is_default'] === 1) { $default = $p['name']; break; } }
        $priority = $default !== '' ? $default : (in_array('Normal', $prioNames, true) ? 'Normal' : ($prioNames[0] ?? 'Normal'));
    }

    $conn->beginTransaction();

    // Get user details
    $userStmt = $conn->prepare("SELECT email, display_name FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('User not found');
    }

    $fromEmail = $user['email'];
    $fromName = $user['display_name'] ?: $user['email'];

    // Generate unique ticket number
    $ticketNumber = generateTicketNumber($conn);

    // Create ticket. Resolve status/priority names to ids via subselects.
    $ticketSql = "INSERT INTO tickets (
        ticket_number, subject, status_id, priority_id, department_id, category_id, catalog_item_id,
        user_id, created_datetime, updated_datetime
    ) VALUES (
        ?, ?,
        (SELECT id FROM ticket_statuses   WHERE name = 'Open' LIMIT 1),
        (SELECT id FROM ticket_priorities WHERE name = ? LIMIT 1),
        ?, ?, ?,
        ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()
    )";

    $ticketStmt = $conn->prepare($ticketSql);
    $ticketStmt->execute([
        $ticketNumber,
        $subject,
        $priority,
        $catDepartmentId,
        $catCategoryId,
        $catalogItemId,
        $userId,
    ]);

    $ticketId = $conn->lastInsertId();

    // Create initial email entry
    $hasAttachments = !empty($inputAttachments) ? 1 : 0;
    $bodyHtml = nl2br(htmlspecialchars($description)) . $formSummaryHtml;
    $bodyPreview = substr(strip_tags($description), 0, 200);

    $emailSql = "INSERT INTO emails (
        subject, from_address, from_name, to_recipients, received_datetime,
        body_preview, body_content, body_type, has_attachments, importance,
        is_read, ticket_id, is_initial, direction, mailbox_id
    ) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, 'html', ?, 'normal', 0, ?, 1, 'Portal', ?)";

    $emailStmt = $conn->prepare($emailSql);
    $emailStmt->execute([
        $subject,
        $fromEmail,
        $fromName,
        $fromEmail,
        $bodyPreview,
        $bodyHtml,
        $hasAttachments,
        $ticketId,
        $mailboxId
    ]);

    $emailId = $conn->lastInsertId();

    // Save attachments to disk and create database records
    if (!empty($inputAttachments)) {
        $attachDir = realpath(__DIR__ . '/../../tickets/attachments');
        $subdir = floor($emailId / 1000);
        $emailDir = $attachDir . '/' . $subdir . '/' . $emailId;

        if (!is_dir($emailDir)) {
            mkdir($emailDir, 0755, true);
        }

        $attachStmt = $conn->prepare(
            "INSERT INTO email_attachments (email_id, filename, content_type, file_path, file_size, is_inline, created_datetime)
             VALUES (?, ?, ?, ?, ?, 0, UTC_TIMESTAMP())"
        );

        foreach ($inputAttachments as $att) {
            $filename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $att['name'] ?? 'file');
            $fileData = base64_decode($att['content'] ?? '');
            $fileSize = strlen($fileData);

            // Handle duplicate filenames
            $savePath = $emailDir . '/' . $filename;
            $counter = 1;
            $info = pathinfo($filename);
            while (file_exists($savePath)) {
                $filename = $info['filename'] . '_' . $counter . '.' . ($info['extension'] ?? '');
                $savePath = $emailDir . '/' . $filename;
                $counter++;
            }

            file_put_contents($savePath, $fileData);

            $relPath = $subdir . '/' . $emailId . '/' . $filename;
            $contentType = $att['type'] ?? 'application/octet-stream';

            $attachStmt->execute([$emailId, $filename, $contentType, $relPath, $fileSize]);
        }
    }

    // Persist the catalog form submission (Phase 7c-2), linked to this ticket.
    if (!empty($formFields) && !empty($formAnswers)) {
        $subStmt = $conn->prepare("INSERT INTO form_submissions (form_id, submitted_by, ticket_id, submitted_date) VALUES (?, NULL, ?, UTC_TIMESTAMP())");
        $subStmt->execute([$catFormId, $ticketId]);
        $submissionId = (int)$conn->lastInsertId();
        $sdStmt = $conn->prepare("INSERT INTO form_submission_data (submission_id, field_id, field_value) VALUES (?, ?, ?)");
        foreach ($formAnswers as $fid => $val) {
            $sdStmt->execute([$submissionId, $fid, $val]);
        }
    }

    $conn->commit();

    // Phase 7c × 6f: a catalog request routed to a department triggers that
    // department's auto-assign strategy. Best-effort, never blocks creation.
    if ($catDepartmentId !== null) {
        require_once __DIR__ . '/../../includes/ticket_autoassign.php';
        try { autoassign_run($conn, (int)$ticketId); } catch (\Throwable $e) { error_log('autoassign (catalog) failed: ' . $e->getMessage()); }
    }

    // Claim any pending recordings this user uploaded for this ticket. File renames
    // happen outside the transaction (filesystem ops aren't transactional anyway).
    if (!empty($recordingIds)) {
        $appRoot = realpath(__DIR__ . '/../../');
        $ticketDir = $appRoot . DIRECTORY_SEPARATOR . 'recordings' . DIRECTORY_SEPARATOR . (int)$ticketId;
        if (!is_dir($ticketDir)) {
            @mkdir($ticketDir, 0755, true);
        }

        $placeholders = implode(',', array_fill(0, count($recordingIds), '?'));
        $selSql = "SELECT id, filename, file_path FROM ticket_recordings
                   WHERE id IN ($placeholders) AND ticket_id IS NULL AND recorded_by_user_id = ?";
        $selStmt = $conn->prepare($selSql);
        $selStmt->execute(array_merge($recordingIds, [$userId]));
        $pending = $selStmt->fetchAll(PDO::FETCH_ASSOC);

        $updStmt = $conn->prepare("UPDATE ticket_recordings SET ticket_id = ?, file_path = ? WHERE id = ?");
        foreach ($pending as $rec) {
            $srcAbs = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rec['file_path']);
            $dstAbs = $ticketDir . DIRECTORY_SEPARATOR . $rec['filename'];
            $dstRel = 'recordings/' . (int)$ticketId . '/' . $rec['filename'];
            if (is_file($srcAbs) && @rename($srcAbs, $dstAbs)) {
                $updStmt->execute([(int)$ticketId, $dstRel, (int)$rec['id']]);
            } else {
                // File missing or rename failed — still claim the row so the recording
                // is associated with the ticket; analyst can see it's broken in the UI
                $updStmt->execute([(int)$ticketId, $rec['file_path'], (int)$rec['id']]);
                error_log('create_ticket.php: failed to move recording ' . $rec['id'] . ' from ' . $srcAbs);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'ticket_id' => (int)$ticketId,
        'ticket_number' => $ticketNumber
    ]);

} catch (Exception $e) {
    // Guard: validation can throw before the transaction opens, and rollBack()
    // on a connection with no active transaction would itself throw.
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Generate a unique random ticket number (format: XXX-YYY-ZZZZZ)
 */
function generateTicketNumber($conn) {
    $maxAttempts = 10;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $letters = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90));
        $numbers1 = rand(0, 9) . rand(0, 9) . rand(0, 9);
        $numbers2 = rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9);
        $ticketNumber = $letters . '-' . $numbers1 . '-' . $numbers2;

        $stmt = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_number = ?");
        $stmt->execute([$ticketNumber]);
        if (!$stmt->fetchColumn()) {
            return $ticketNumber;
        }
    }
    throw new Exception('Failed to generate unique ticket number');
}
