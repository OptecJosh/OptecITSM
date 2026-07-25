<?php
/**
 * LMS access rules — the learner/manager split (RBAC pilot).
 *
 * Two distinct things a person can do with the LMS:
 *   - TAKE assigned courses. Needs the 'lms' module only. A learner.
 *   - MANAGE the LMS (author, upload, run groups, assign, see everyone's
 *     progress). Needs the 'lms.manage' capability (or is_admin). A manager.
 *
 * These helpers are the single source of truth for both, used by the pages, the
 * APIs and the playback gate so the rule can't drift between them.
 */

require_once __DIR__ . '/rbac.php';

/** May this analyst manage the LMS? (is_admin bypasses, via analystHasCapability.) */
function lmsCanManage(PDO $conn, int $analystId): bool {
    return analystHasCapability($conn, $analystId, Cap::LMS_MANAGE);
}

/**
 * Is this course assigned to the analyst — i.e. assigned to a learning group
 * they belong to? This is what an analyst is *entitled* to take.
 */
function lmsCourseAssignedTo(PDO $conn, int $analystId, int $courseId): bool {
    if ($analystId <= 0 || $courseId <= 0) return false;
    $sql = "SELECT 1
            FROM lms_course_assignments ca
            JOIN lms_learning_groups g ON ca.group_id = g.id AND g.is_active = 1
            JOIN lms_learning_group_members m ON m.group_id = g.id
            WHERE ca.course_id = ? AND m.analyst_id = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$courseId, $analystId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * May this analyst open/play this course? Managers (and admins) may open any
 * course — that's how Preview works. Everyone else may open only what's assigned
 * to them. This is the gate the player and the learner content APIs enforce.
 */
function lmsCanAccessCourse(PDO $conn, int $analystId, int $courseId): bool {
    if (lmsCanManage($conn, $analystId)) return true;
    return lmsCourseAssignedTo($conn, $analystId, $courseId);
}

/**
 * Hard gate for a learner course API: 403 unless the analyst may access $courseId.
 * Assumes the session check and module gate have already run.
 */
function requireLmsCourseAccessJson(PDO $conn, int $courseId): void {
    $id = (int) ($_SESSION['analyst_id'] ?? 0);
    if (!$id || !lmsCanAccessCourse($conn, $id, $courseId)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'This course has not been assigned to you.']);
        exit;
    }
}

/**
 * The courses assigned to an analyst (via any group they're in), each with their
 * own progress — the data behind the My Courses page. One row per course even if
 * it reaches them through several groups (earliest deadline wins). is_overdue is
 * computed here so the page and any caller agree.
 *
 * @return array<int,array<string,mixed>>
 */
function lmsMyCourses(PDO $conn, int $analystId): array {
    if ($analystId <= 0) return [];

    $sql = "SELECT c.id, c.title, c.description, c.content_type, c.scorm_version,
                   MIN(ca.deadline) AS deadline,
                   COALESCE(p.status, 'not_started') AS status,
                   p.score_raw, p.score_max, p.last_access, p.completion_datetime
            FROM lms_course_assignments ca
            JOIN lms_learning_groups g ON ca.group_id = g.id AND g.is_active = 1
            JOIN lms_learning_group_members m ON m.group_id = g.id AND m.analyst_id = ?
            JOIN lms_courses c ON ca.course_id = c.id AND c.is_active = 1
            LEFT JOIN lms_progress p ON p.analyst_id = ? AND p.course_id = c.id
            GROUP BY c.id, c.title, c.description, c.content_type, c.scorm_version,
                     p.status, p.score_raw, p.score_max, p.last_access, p.completion_datetime
            ORDER BY (MIN(ca.deadline) IS NULL), MIN(ca.deadline), c.title";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$analystId, $analystId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $now = new DateTime('now', new DateTimeZone('UTC'));
    foreach ($rows as &$row) {
        $row['is_overdue'] = false;
        if (!empty($row['deadline'])) {
            $deadline = new DateTime($row['deadline'], new DateTimeZone('UTC'));
            if ($now > $deadline && !in_array($row['status'], ['completed', 'passed'], true)) {
                $row['is_overdue'] = true;
            }
        }
    }
    unset($row);

    return $rows;
}

// ============================================================================
//  Development records — certifications, training log and journal
//
//  Two different visibility rules live here, deliberately:
//
//    Certifications + training records — the compliance record. Visible to the
//    analyst, their line manager (analysts.manager_id) and anyone who can manage
//    the LMS (that is their job).
//
//    Development JOURNAL — visible ONLY to the analyst and their line manager.
//    LMS managers and admins are NOT granted access: the journal is a private
//    conversation between the two, and that is the whole point of it. If an
//    organisation later wants an L&D role to read journals, that is a deliberate
//    change to lmsJournalCanAccess(), not an accident of capability inheritance.
// ============================================================================

/** Is $viewerId the line manager of $ownerId (analysts.manager_id)? */
function lmsIsLineManagerOf(PDO $conn, int $viewerId, int $ownerId): bool {
    if ($viewerId <= 0 || $ownerId <= 0 || $viewerId === $ownerId) return false;
    try {
        $stmt = $conn->prepare("SELECT manager_id FROM analysts WHERE id = ?");
        $stmt->execute([$ownerId]);
        $managerId = $stmt->fetchColumn();
        return $managerId !== false && $managerId !== null && (int)$managerId === $viewerId;
    } catch (Exception $e) {
        return false;   // column absent (pre-Database-Verify) → no manager access
    }
}

/** The analysts who report to $managerId, as [{id, full_name}]. */
function lmsDirectReports(PDO $conn, int $managerId): array {
    if ($managerId <= 0) return [];
    try {
        $stmt = $conn->prepare("SELECT id, full_name FROM analysts WHERE manager_id = ? AND is_active = 1 ORDER BY full_name");
        $stmt->execute([$managerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

/** May $viewerId see $ownerId's certifications and training records? */
function lmsCanViewTraining(PDO $conn, int $viewerId, int $ownerId): bool {
    if ($viewerId > 0 && $viewerId === $ownerId) return true;
    if (lmsIsLineManagerOf($conn, $viewerId, $ownerId)) return true;
    return lmsCanManage($conn, $viewerId);
}

/**
 * May $viewerId add or change $ownerId's certifications / training records?
 * Same set as viewing: your own record is yours to keep up to date, and your
 * manager or an LMS manager can record something on your behalf.
 */
function lmsCanEditTraining(PDO $conn, int $viewerId, int $ownerId): bool {
    return lmsCanViewTraining($conn, $viewerId, $ownerId);
}

/**
 * May $viewerId read $ownerId's development journal? The analyst and their line
 * manager, and nobody else — not LMS managers, not admins. See the note above.
 */
function lmsJournalCanAccess(PDO $conn, int $viewerId, int $ownerId): bool {
    if ($viewerId <= 0 || $ownerId <= 0) return false;
    if ($viewerId === $ownerId) return true;
    return lmsIsLineManagerOf($conn, $viewerId, $ownerId);
}

/**
 * The analysts whose training record $viewerId may open, as [{id, full_name,
 * is_self}] — themselves, their direct reports, and (for an LMS manager) every
 * active analyst. Drives the person picker on the Training page.
 */
function lmsTrainingRoster(PDO $conn, int $viewerId): array {
    $rows = [];
    try {
        if (lmsCanManage($conn, $viewerId)) {
            $rows = $conn->query("SELECT id, full_name FROM analysts WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $self = $conn->prepare("SELECT id, full_name FROM analysts WHERE id = ?");
            $self->execute([$viewerId]);
            $rows = $self->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach (lmsDirectReports($conn, $viewerId) as $r) $rows[] = $r;
        }
    } catch (Exception $e) {
        return [];
    }

    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        if (isset($seen[$id])) continue;
        $seen[$id] = true;
        $out[] = ['id' => $id, 'full_name' => $r['full_name'], 'is_self' => $id === $viewerId];
    }
    return $out;
}

/** Certification status values, in workflow order. */
function lmsCertificationStatuses(): array {
    return ['planned', 'in_progress', 'achieved', 'expired', 'revoked'];
}

/** Training record types. */
function lmsTrainingTypes(): array {
    return ['course', 'webinar', 'conference', 'exam', 'on_the_job', 'reading', 'other'];
}

/** Journal entry categories. */
function lmsJournalCategories(): array {
    return ['goal', 'progress', 'reflection', 'feedback', 'one_to_one', 'other'];
}
