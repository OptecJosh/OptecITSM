<?php
/**
 * FreeITSM REST API v1 — changes resource (Change Management).
 *
 * Mirrors the module's internal endpoints so a change touched via the API is
 * indistinguishable from one touched in the UI:
 *   - create/update mirror api/change-management/save.php: per-field audit
 *     with the SAME human field labels ('Title', 'Status', 'Work Start', …),
 *     display NAMES for lookups, '(empty)' placeholders, 200-char truncation,
 *     action_type 'status_change' for status else 'field_change', longtext
 *     bodies deliberately NOT audited, and the change.approved workflow event
 *     on a genuine transition into Approved.
 *   - risk is computed server-side exactly like the UI: score = likelihood ×
 *     impact (1-5 each), banded Low ≤4 / Medium ≤9 / High ≤15 / Very High ≤20
 *     / Critical.
 *   - CAB voting mirrors submit_cab_vote.php: the key's acts-as analyst must
 *     be an un-voted member; any required Reject sends the change back to
 *     Draft; the all/majority threshold on required members auto-approves
 *     (with approval_datetime + audit + workflow dispatch).
 *   - comments are append + delete (no edit), always internal — like the UI.
 *   - DELETE removes the change, its attachment files, and cascades children.
 *
 * Changes are NOT company-scoped (no tenant_id — matches the UI), so a key's
 * company scope does not restrict these routes. CHG-#### references are
 * derived from the id, exactly as the UI renders them.
 */

require_once dirname(__DIR__, 3) . '/workflow/includes/engine.php';

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function apiChangeNumber(int $id): string {
    return 'CHG-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

function apiChangeSelect(): string {
    return "SELECT c.*,
                   ct.name AS type_name,
                   cs.name AS status_name, cs.is_closed AS status_is_closed,
                   cp.name AS priority_name,
                   ci.name AS impact_name,
                   cc.name AS category_name,
                   rq.full_name AS requester_name,
                   asg.full_name AS assigned_to_name,
                   ap.full_name AS approver_name,
                   cb.full_name AS created_by_name
            FROM changes c
            LEFT JOIN change_types      ct  ON ct.id = c.change_type_id
            LEFT JOIN change_statuses   cs  ON cs.id = c.status_id
            LEFT JOIN change_priorities cp  ON cp.id = c.priority_id
            LEFT JOIN change_impacts    ci  ON ci.id = c.impact_id
            LEFT JOIN change_categories cc  ON cc.id = c.category_id
            LEFT JOIN analysts          rq  ON rq.id = c.requester_id
            LEFT JOIN analysts          asg ON asg.id = c.assigned_to_id
            LEFT JOIN analysts          ap  ON ap.id = c.approver_id
            LEFT JOIN analysts          cb  ON cb.id = c.created_by_id";
}

/** Summary shape (lists). Detail adds the longtext bodies, PIR, attachments, links. */
function apiSerializeChange(array $r): array {
    $rel = function ($id, $name, array $extra = []) {
        return $id === null ? null : array_merge(['id' => (int)$id, 'name' => $name], $extra);
    };
    $category = null;
    if ($r['category_id'] !== null) {
        $category = ['id' => (int)$r['category_id'], 'name' => $r['category_name']];
    } elseif ($r['category'] !== null && $r['category'] !== '') {
        $category = ['id' => null, 'name' => $r['category']]; // legacy free-text category
    }
    return [
        'id'            => (int)$r['id'],
        'change_number' => apiChangeNumber((int)$r['id']),
        'title'         => $r['title'],
        'change_type'   => $rel($r['change_type_id'], $r['type_name']),
        'status'        => $rel($r['status_id'], $r['status_name'], ['is_closed' => (bool)($r['status_is_closed'] ?? false)]),
        'priority'      => $rel($r['priority_id'], $r['priority_name']),
        'impact'        => $rel($r['impact_id'], $r['impact_name']),
        'category'      => $category,
        'requester'     => $rel($r['requester_id'], $r['requester_name']),
        'assigned_to'   => $rel($r['assigned_to_id'], $r['assigned_to_name']),
        'approver'      => $rel($r['approver_id'], $r['approver_name']),
        'approval_at'   => apiIsoDate($r['approval_datetime']),
        'cab'           => [
            'required'      => (bool)$r['cab_required'],
            'approval_type' => $r['cab_approval_type'],
        ],
        'risk'          => [
            'likelihood' => $r['risk_likelihood'] !== null ? (int)$r['risk_likelihood'] : null,
            'impact'     => $r['risk_impact_score'] !== null ? (int)$r['risk_impact_score'] : null,
            'score'      => $r['risk_score'] !== null ? (int)$r['risk_score'] : null,
            'level'      => $r['risk_level'],
        ],
        'schedule'      => [
            'work_start_at'   => apiIsoDate($r['work_start_datetime']),
            'work_end_at'     => apiIsoDate($r['work_end_datetime']),
            'outage_start_at' => apiIsoDate($r['outage_start_datetime']),
            'outage_end_at'   => apiIsoDate($r['outage_end_datetime']),
        ],
        'created_by'    => $rel($r['created_by_id'], $r['created_by_name']),
        'created_at'    => apiIsoDate($r['created_datetime']),
        'modified_at'   => apiIsoDate($r['modified_datetime']),
    ];
}

function apiLoadChange(PDO $conn, int $changeId): array {
    $stmt = $conn->prepare(apiChangeSelect() . " WHERE c.id = ?");
    $stmt->execute([$changeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Change not found.');
    }
    return $row;
}

function apiChangeAuditWrite(PDO $conn, int $changeId, int $analystId, string $actionType, ?string $field, ?string $old, ?string $new): void {
    $stmt = $conn->prepare(
        "INSERT INTO change_audit (change_id, analyst_id, action_type, field_name, old_value, new_value, created_datetime)
         VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
    );
    $stmt->execute([$changeId, $analystId, $actionType, $field, $old, $new]);
}

/** The UI's risk banding (save.php calculateRiskLevel). */
function apiChangeRiskLevel(?int $score): ?string {
    if ($score === null) return null;
    if ($score <= 4)  return 'Low';
    if ($score <= 9)  return 'Medium';
    if ($score <= 15) return 'High';
    if ($score <= 20) return 'Very High';
    return 'Critical';
}

/**
 * Resolve a change lookup (type/status/priority/impact) by name or id from
 * body keys "<key>" / "<key>_id". Strict: unknown values are a 422 (the UI's
 * silent fall-back-to-default is a footgun for machines). Returns
 * [id, name, extraCol?] or null when neither key was sent.
 */
function apiResolveChangeLookup(PDO $conn, array $body, string $key, string $table, string $extraCol = ''): ?array {
    $cols = 'id, name' . ($extraCol !== '' ? ", $extraCol" : '');
    if (isset($body[$key . '_id']) && $body[$key . '_id'] !== '' && $body[$key . '_id'] !== null) {
        $stmt = $conn->prepare("SELECT $cols FROM `$table` WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$body[$key . '_id']]);
    } elseif (isset($body[$key]) && trim((string)$body[$key]) !== '') {
        $stmt = $conn->prepare("SELECT $cols FROM `$table` WHERE name = ? LIMIT 1");
        $stmt->execute([trim((string)$body[$key])]);
    } else {
        return null;
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(422, 'invalid_field', "Unknown $key: " . ($body[$key] ?? $body[$key . '_id']));
    }
    $out = [(int)$row['id'], $row['name']];
    if ($extraCol !== '') {
        $out[] = (int)$row[$extraCol];
    }
    return $out;
}

/** The default (is_default=1) row of a change lookup table: [id, name] or [null, null]. */
function apiChangeLookupDefault(PDO $conn, string $table): array {
    $row = $conn->query("SELECT id, name FROM `$table` WHERE is_default = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    return $row ? [(int)$row['id'], $row['name']] : [null, null];
}

/** Validate a 1-5 risk input. Null/'' clears. */
function apiChangeRiskInput($value, string $field): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    $v = (int)$value;
    if ($v < 1 || $v > 5) {
        apiError(422, 'invalid_field', "'{$field}' must be between 1 and 5.");
    }
    return $v;
}

/** Fire change.approved exactly like the UI (save.php / submit_cab_vote.php). */
function apiChangeApprovedDispatch(int $changeId, ?string $title, ?string $riskLevel, ?int $approverId): void {
    try {
        WorkflowEngine::dispatch('change.approved', [
            'change' => [
                'id'    => $changeId,
                'title' => $title,
                'risk'  => $riskLevel,
            ],
            'approver' => [
                'id' => $approverId,
            ],
        ]);
    } catch (Exception $wfEx) {
        error_log('Workflow dispatch error in API v1 change: ' . $wfEx->getMessage());
    }
}

// ---------------------------------------------------------------------------
// GET /changes
// ---------------------------------------------------------------------------
function apiChangesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];

    $state = strtolower(trim($_GET['state'] ?? 'all'));
    if ($state === 'open')   $where[] = "(cs.is_closed IS NULL OR cs.is_closed = 0)";
    if ($state === 'closed') $where[] = "cs.is_closed = 1";

    foreach ([
        'status_id'      => 'c.status_id',
        'change_type_id' => 'c.change_type_id',
        'priority_id'    => 'c.priority_id',
        'impact_id'      => 'c.impact_id',
        'category_id'    => 'c.category_id',
        'requester_id'   => 'c.requester_id',
        'assigned_to_id' => 'c.assigned_to_id',
        'approver_id'    => 'c.approver_id',
    ] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col = ?";
            $args[]  = (int)$_GET[$param];
        }
    }
    foreach ([
        'status'      => 'cs.name',
        'change_type' => 'ct.name',
        'priority'    => 'cp.name',
        'impact'      => 'ci.name',
        'risk_level'  => 'c.risk_level',
    ] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col = ?";
            $args[]  = trim($_GET[$param]);
        }
    }
    if (isset($_GET['cab_required']) && $_GET['cab_required'] !== '') {
        $where[] = 'c.cab_required = ?';
        $args[]  = $_GET['cab_required'] === 'true' ? 1 : 0;
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $q = trim($_GET['q']);
        // "CHG-0042" (or "chg42") finds the change by its reference.
        if (preg_match('/^chg-?0*(\d+)$/i', $q, $m)) {
            $where[] = '(c.title LIKE ? OR c.id = ?)';
            $args[]  = '%' . $q . '%';
            $args[]  = (int)$m[1];
        } else {
            $where[] = 'c.title LIKE ?';
            $args[]  = '%' . $q . '%';
        }
    }
    foreach ([
        'created_since'   => ['c.created_datetime',    '>='],
        'modified_since'  => ['c.modified_datetime',   '>='],
        'work_start_from' => ['c.work_start_datetime', '>='],
        'work_start_to'   => ['c.work_start_datetime', '<'],
    ] as $param => [$col, $op]) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col $op ?";
            $args[]  = apiParseDate($_GET[$param], $param);
        }
    }

    $sortable = [
        'id' => 'c.id', 'created_at' => 'c.created_datetime', 'modified_at' => 'c.modified_datetime',
        'title' => 'c.title', 'work_start_at' => 'c.work_start_datetime', 'risk_score' => 'c.risk_score',
        'priority' => 'c.priority_id', 'status' => 'c.status_id',
    ];
    $sortParam = trim($_GET['sort'] ?? '-created_at');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) {
        apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    }
    $orderSql = $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC');

    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) FROM changes c
         LEFT JOIN change_statuses cs ON cs.id = c.status_id
         LEFT JOIN change_types ct ON ct.id = c.change_type_id
         LEFT JOIN change_priorities cp ON cp.id = c.priority_id
         LEFT JOIN change_impacts ci ON ci.id = c.impact_id
         WHERE $whereSql"
    );
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(apiChangeSelect() . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
    $stmt->execute($args);
    $changes = array_map('apiSerializeChange', $stmt->fetchAll(PDO::FETCH_ASSOC));

    apiRespond($changes, 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// ---------------------------------------------------------------------------
// GET /changes/{id} — detail incl. bodies, PIR, attachments, linked problems
// ---------------------------------------------------------------------------
function apiChangesGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $r = apiLoadChange($conn, $params[0]);
    $change = apiSerializeChange($r);

    $change['description']        = $r['description'];
    $change['reason_for_change']  = $r['reason_for_change'];
    $change['risk']['evaluation'] = $r['risk_evaluation'];
    $change['test_plan']          = $r['test_plan'];
    $change['rollback_plan']      = $r['rollback_plan'];
    $change['pir'] = [
        'review'          => $r['post_implementation_review'],
        'was_successful'  => $r['pir_was_successful'] === null ? null : (bool)$r['pir_was_successful'],
        'actual_start_at' => apiIsoDate($r['pir_actual_start']),
        'actual_end_at'   => apiIsoDate($r['pir_actual_end']),
        'lessons_learned' => $r['pir_lessons_learned'],
        'follow_up'       => $r['pir_follow_up'],
    ];

    $att = $conn->prepare(
        "SELECT id, file_name, file_size, file_type, uploaded_datetime
         FROM change_attachments WHERE change_id = ? ORDER BY uploaded_datetime ASC"
    );
    $att->execute([$params[0]]);
    $change['attachments'] = array_map(function ($a) {
        return [
            'id'          => (int)$a['id'],
            'file_name'   => $a['file_name'],
            'file_size'   => $a['file_size'] !== null ? (int)$a['file_size'] : null,
            'file_type'   => $a['file_type'],
            'uploaded_at' => apiIsoDate($a['uploaded_datetime']),
        ];
    }, $att->fetchAll(PDO::FETCH_ASSOC));

    // Problems this change fixes (linked from the problem side; read both ways).
    $change['linked_problems'] = [];
    try {
        $pr = $conn->prepare(
            "SELECT p.id, p.problem_number, p.title, cr.relation_type
             FROM change_relations cr
             JOIN problems p ON p.id = cr.related_id
             WHERE cr.change_id = ? AND cr.related_type = 'problem'
             ORDER BY p.created_datetime DESC"
        );
        $pr->execute([$params[0]]);
        $change['linked_problems'] = array_map(function ($p) {
            return [
                'id'             => (int)$p['id'],
                'problem_number' => $p['problem_number'],
                'title'          => $p['title'],
                'relation_type'  => $p['relation_type'],
            ];
        }, $pr->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { /* problems module absent */ }

    apiRespond($change);
}

// ---------------------------------------------------------------------------
// POST /changes
// ---------------------------------------------------------------------------
function apiChangesCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $title = trim((string)($body['title'] ?? ''));
    if ($title === '') {
        apiError(422, 'missing_field', "'title' is required.");
    }
    $actorId = (int)$apiKey['analyst_id'];

    // Lookups: explicit (strict) or the module defaults (Normal/Draft/Medium/Medium).
    $type     = apiResolveChangeLookup($conn, $body, 'change_type', 'change_types') ?? apiChangeLookupDefault($conn, 'change_types');
    $status   = apiResolveChangeLookup($conn, $body, 'status', 'change_statuses', 'is_closed');
    if ($status === null) {
        $d = apiChangeLookupDefault($conn, 'change_statuses');
        $status = [$d[0], $d[1], 0];
    }
    $priority = apiResolveChangeLookup($conn, $body, 'priority', 'change_priorities') ?? apiChangeLookupDefault($conn, 'change_priorities');
    $impact   = apiResolveChangeLookup($conn, $body, 'impact', 'change_impacts') ?? apiChangeLookupDefault($conn, 'change_impacts');

    $categoryId = null;
    if (isset($body['category_id']) && $body['category_id'] !== '' && $body['category_id'] !== null) {
        $categoryId = (int)$body['category_id'];
        $cStmt = $conn->prepare("SELECT id FROM change_categories WHERE id = ?");
        $cStmt->execute([$categoryId]);
        if (!$cStmt->fetchColumn()) {
            apiError(422, 'invalid_field', "Unknown category id: {$categoryId}");
        }
    }

    $people = [];
    foreach (['requester_id', 'assigned_to_id', 'approver_id'] as $field) {
        $people[$field] = null;
        if (isset($body[$field]) && $body[$field] !== '' && $body[$field] !== null) {
            $people[$field] = (int)$body[$field];
            apiResolveAnalyst($conn, $people[$field]);
        }
    }

    $dates = [];
    foreach (['work_start_at' => 'work_start_datetime', 'work_end_at' => 'work_end_datetime',
              'outage_start_at' => 'outage_start_datetime', 'outage_end_at' => 'outage_end_datetime',
              'pir_actual_start_at' => 'pir_actual_start', 'pir_actual_end_at' => 'pir_actual_end'] as $in => $col) {
        $dates[$col] = isset($body[$in]) && $body[$in] !== '' && $body[$in] !== null
            ? apiParseDate((string)$body[$in], $in) : null;
    }

    $riskLikelihood = apiChangeRiskInput($body['risk_likelihood'] ?? null, 'risk_likelihood');
    $riskImpact     = apiChangeRiskInput($body['risk_impact_score'] ?? null, 'risk_impact_score');
    $riskScore = ($riskLikelihood !== null && $riskImpact !== null) ? $riskLikelihood * $riskImpact : null;
    $riskLevel = apiChangeRiskLevel($riskScore);

    $cabRequired = !empty($body['cab_required']) ? 1 : 0;
    $cabType = $body['cab_approval_type'] ?? 'all';
    if (!in_array($cabType, ['all', 'majority'], true)) {
        $cabType = 'all';
    }

    $text = function ($key) use ($body) {
        $v = trim((string)($body[$key] ?? ''));
        return $v === '' ? null : $v;
    };

    $ins = $conn->prepare(
        "INSERT INTO changes (
            title, change_type_id, status_id, priority_id, impact_id, category, category_id,
            requester_id, assigned_to_id, approver_id,
            work_start_datetime, work_end_datetime, outage_start_datetime, outage_end_datetime,
            description, reason_for_change, risk_evaluation, test_plan, rollback_plan,
            post_implementation_review, risk_likelihood, risk_impact_score, risk_score, risk_level,
            pir_actual_start, pir_actual_end, pir_lessons_learned, pir_follow_up,
            cab_required, cab_approval_type, created_by_id, created_datetime, modified_datetime
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $ins->execute([
        $title, $type[0], $status[0], $priority[0], $impact[0], $text('category'), $categoryId,
        $people['requester_id'], $people['assigned_to_id'], $people['approver_id'],
        $dates['work_start_datetime'], $dates['work_end_datetime'],
        $dates['outage_start_datetime'], $dates['outage_end_datetime'],
        $text('description'), $text('reason_for_change'), $text('risk_evaluation'),
        $text('test_plan'), $text('rollback_plan'), $text('post_implementation_review'),
        $riskLikelihood, $riskImpact, $riskScore, $riskLevel,
        $dates['pir_actual_start'], $dates['pir_actual_end'],
        $text('pir_lessons_learned'), $text('pir_follow_up'),
        $cabRequired, $cabType, $actorId,
    ]);
    $changeId = (int)$conn->lastInsertId();

    // Creation audit row (same shape as the UI's, but naming the real status).
    apiChangeAuditWrite($conn, $changeId, $actorId, 'status_change', 'Status', null, 'Created as ' . ($status[1] ?? 'Draft'));

    apiRespond(apiSerializeChange(apiLoadChange($conn, $changeId)), 201);
}

// ---------------------------------------------------------------------------
// PATCH /changes/{id}
// ---------------------------------------------------------------------------
function apiChangesUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $changeId = $params[0];
    $current = apiLoadChange($conn, $changeId);
    if (!$body) {
        apiError(422, 'missing_field', 'No fields to update.');
    }
    $actorId = (int)$apiKey['analyst_id'];

    $updates = [];
    $args    = [];
    $audits  = [];   // [actionType, label, old, new] — the UI's exact audit shape

    // The UI's audit-value normalisation: '(empty)' placeholder + 200-char cap.
    $auditVal = function ($v) {
        $s = ($v === null || $v === '') ? '(empty)' : (string)$v;
        return strlen($s) > 200 ? substr($s, 0, 200) . '...' : $s;
    };
    $queueAudit = function (string $label, $old, $new, string $actionType = 'field_change') use (&$audits, $auditVal) {
        $audits[] = [$actionType, $label, $auditVal($old), $auditVal($new)];
    };

    if (array_key_exists('title', $body)) {
        $title = trim((string)$body['title']);
        if ($title === '') {
            apiError(422, 'invalid_field', "'title' cannot be empty.");
        }
        if ($title !== $current['title']) {
            $updates[] = 'title = ?';
            $args[]    = $title;
            $queueAudit('Title', $current['title'], $title);
        }
    }

    // Lookup fields (name or id; audited as display names).
    $newStatusName = null;
    $wasApproved = ($current['status_name'] === 'Approved');
    foreach ([
        ['change_type', 'change_types',      'change_type_id', 'Type',     'type_name'],
        ['status',      'change_statuses',   'status_id',      'Status',   'status_name'],
        ['priority',    'change_priorities', 'priority_id',    'Priority', 'priority_name'],
        ['impact',      'change_impacts',    'impact_id',      'Impact',   'impact_name'],
    ] as [$key, $table, $col, $label, $currentNameKey]) {
        $res = apiResolveChangeLookup($conn, $body, $key, $table);
        if ($res === null || $res[0] === ((int)($current[$col] ?? 0) ?: null)) {
            continue;
        }
        $updates[] = "$col = ?";
        $args[]    = $res[0];
        $queueAudit($label, $current[$currentNameKey], $res[1], $key === 'status' ? 'status_change' : 'field_change');
        if ($key === 'status') {
            $newStatusName = $res[1];
        }
    }

    if (array_key_exists('category_id', $body)) {
        $newCatId = ($body['category_id'] === '' || $body['category_id'] === null) ? null : (int)$body['category_id'];
        $newCatName = null;
        if ($newCatId !== null) {
            $cStmt = $conn->prepare("SELECT name FROM change_categories WHERE id = ?");
            $cStmt->execute([$newCatId]);
            $newCatName = $cStmt->fetchColumn();
            if ($newCatName === false) {
                apiError(422, 'invalid_field', "Unknown category id: {$newCatId}");
            }
        }
        if ($newCatId !== ($current['category_id'] !== null ? (int)$current['category_id'] : null)) {
            $updates[] = 'category_id = ?';
            $args[]    = $newCatId;
            $queueAudit('Category', $current['category_name'], $newCatName);
        }
    }
    if (array_key_exists('category', $body)) {
        $newCat = trim((string)$body['category']) ?: null;
        if ($newCat !== $current['category']) {
            $updates[] = 'category = ?';
            $args[]    = $newCat;
            $queueAudit('Category', $current['category'], $newCat);
        }
    }

    // People
    foreach ([
        'requester_id'   => ['Requester',   'requester_name'],
        'assigned_to_id' => ['Assigned To', 'assigned_to_name'],
        'approver_id'    => ['Approver',    'approver_name'],
    ] as $field => [$label, $currentNameKey]) {
        if (!array_key_exists($field, $body)) {
            continue;
        }
        $newId = ($body[$field] === '' || $body[$field] === null) ? null : (int)$body[$field];
        $newName = $newId !== null ? apiResolveAnalyst($conn, $newId) : null;
        if ($newId !== ($current[$field] !== null ? (int)$current[$field] : null)) {
            $updates[] = "$field = ?";
            $args[]    = $newId;
            $queueAudit($label, $current[$currentNameKey], $newName);
        }
    }

    // Schedule / PIR dates
    foreach ([
        'work_start_at'       => ['work_start_datetime',    'Work Start'],
        'work_end_at'         => ['work_end_datetime',      'Work End'],
        'outage_start_at'     => ['outage_start_datetime',  'Outage Start'],
        'outage_end_at'       => ['outage_end_datetime',    'Outage End'],
        'pir_actual_start_at' => ['pir_actual_start',       'PIR Actual Start'],
        'pir_actual_end_at'   => ['pir_actual_end',         'PIR Actual End'],
    ] as $in => [$col, $label]) {
        if (!array_key_exists($in, $body)) {
            continue;
        }
        $newVal = ($body[$in] === null || $body[$in] === '') ? null : apiParseDate((string)$body[$in], $in);
        if ($newVal !== $current[$col]) {
            $updates[] = "$col = ?";
            $args[]    = $newVal;
            $queueAudit($label, $current[$col], $newVal);
        }
    }

    // Longtext bodies — updated but NOT audited (same as the UI).
    foreach (['description', 'reason_for_change', 'risk_evaluation', 'test_plan',
              'rollback_plan', 'post_implementation_review', 'pir_lessons_learned', 'pir_follow_up'] as $field) {
        if (!array_key_exists($field, $body)) {
            continue;
        }
        $newVal = trim((string)$body[$field]) ?: null;
        if ($newVal !== $current[$field]) {
            $updates[] = "$field = ?";
            $args[]    = $newVal;
        }
    }

    // Risk inputs — recompute score + level from the merged pair.
    $riskTouched = array_key_exists('risk_likelihood', $body) || array_key_exists('risk_impact_score', $body);
    if ($riskTouched) {
        $newLikelihood = array_key_exists('risk_likelihood', $body)
            ? apiChangeRiskInput($body['risk_likelihood'], 'risk_likelihood')
            : ($current['risk_likelihood'] !== null ? (int)$current['risk_likelihood'] : null);
        $newImpact = array_key_exists('risk_impact_score', $body)
            ? apiChangeRiskInput($body['risk_impact_score'], 'risk_impact_score')
            : ($current['risk_impact_score'] !== null ? (int)$current['risk_impact_score'] : null);
        $newScore = ($newLikelihood !== null && $newImpact !== null) ? $newLikelihood * $newImpact : null;
        $newLevel = apiChangeRiskLevel($newScore);

        $oldLikelihood = $current['risk_likelihood'] !== null ? (int)$current['risk_likelihood'] : null;
        $oldImpact     = $current['risk_impact_score'] !== null ? (int)$current['risk_impact_score'] : null;
        if ($newLikelihood !== $oldLikelihood) {
            $updates[] = 'risk_likelihood = ?';
            $args[]    = $newLikelihood;
            $queueAudit('Risk Likelihood', $oldLikelihood, $newLikelihood);
        }
        if ($newImpact !== $oldImpact) {
            $updates[] = 'risk_impact_score = ?';
            $args[]    = $newImpact;
            $queueAudit('Risk Impact Score', $oldImpact, $newImpact);
        }
        if ($newLikelihood !== $oldLikelihood || $newImpact !== $oldImpact) {
            if ($newLevel !== $current['risk_level']) {
                $queueAudit('Risk Level', $current['risk_level'], $newLevel);
            }
            $updates[] = 'risk_score = ?';
            $args[]    = $newScore;
            $updates[] = 'risk_level = ?';
            $args[]    = $newLevel;
        }
    }

    // PIR success flag + CAB settings
    if (array_key_exists('pir_was_successful', $body)) {
        $newVal = ($body['pir_was_successful'] === null || $body['pir_was_successful'] === '') ? null : (int)(bool)$body['pir_was_successful'];
        $oldVal = $current['pir_was_successful'] !== null ? (int)$current['pir_was_successful'] : null;
        if ($newVal !== $oldVal) {
            $updates[] = 'pir_was_successful = ?';
            $args[]    = $newVal;
            $queueAudit('PIR Successful', $oldVal, $newVal);
        }
    }
    if (array_key_exists('cab_required', $body)) {
        $newVal = !empty($body['cab_required']) ? 1 : 0;
        if ($newVal !== (int)$current['cab_required']) {
            $updates[] = 'cab_required = ?';
            $args[]    = $newVal;
            $queueAudit('CAB Required', (int)$current['cab_required'], $newVal);
        }
    }
    if (array_key_exists('cab_approval_type', $body)) {
        $newVal = in_array($body['cab_approval_type'], ['all', 'majority'], true) ? $body['cab_approval_type'] : 'all';
        if ($newVal !== $current['cab_approval_type']) {
            $updates[] = 'cab_approval_type = ?';
            $args[]    = $newVal;
            $queueAudit('CAB Approval Type', $current['cab_approval_type'], $newVal);
        }
    }

    if (!$updates) {
        apiRespond(apiSerializeChange($current)); // idempotent PATCH
    }

    $updates[] = 'modified_datetime = UTC_TIMESTAMP()';
    $args[]    = $changeId;
    $conn->prepare('UPDATE changes SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);

    foreach ($audits as [$actionType, $label, $old, $new]) {
        apiChangeAuditWrite($conn, $changeId, $actorId, $actionType, $label, $old, $new);
    }

    // change.approved on a genuine manual transition into Approved (UI parity).
    if ($newStatusName === 'Approved' && !$wasApproved) {
        $fresh = apiLoadChange($conn, $changeId);
        apiChangeApprovedDispatch($changeId, $fresh['title'], $fresh['risk_level'],
            $fresh['approver_id'] !== null ? (int)$fresh['approver_id'] : null);
        apiRespond(apiSerializeChange($fresh));
    }

    apiRespond(apiSerializeChange(apiLoadChange($conn, $changeId)));
}

// ---------------------------------------------------------------------------
// DELETE /changes/{id} — permanent; removes attachment files, cascades children
// ---------------------------------------------------------------------------
function apiChangesDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadChange($conn, $params[0]);

    // Remove attachment files from disk, then rows (mirrors delete.php).
    $att = $conn->prepare("SELECT file_path FROM change_attachments WHERE change_id = ?");
    $att->execute([$params[0]]);
    foreach ($att->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $filePath = dirname(__DIR__, 3) . '/change-management/attachments/' . $a['file_path'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }
    $conn->prepare("DELETE FROM change_attachments WHERE change_id = ?")->execute([$params[0]]);
    $conn->prepare("DELETE FROM changes WHERE id = ?")->execute([$params[0]]);
    $dir = dirname(__DIR__, 3) . '/change-management/attachments/' . $params[0];
    if (is_dir($dir)) {
        @rmdir($dir);
    }

    apiRespond(['id' => $params[0], 'deleted' => true]);
}

// ---------------------------------------------------------------------------
// Comments — append + delete (no edit), always internal, like the UI
// ---------------------------------------------------------------------------
function apiChangeCommentsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadChange($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT cm.id, cm.comment_text, cm.created_datetime, cm.analyst_id, a.full_name AS analyst_name
         FROM change_comments cm LEFT JOIN analysts a ON a.id = cm.analyst_id
         WHERE cm.change_id = ? ORDER BY cm.created_datetime DESC, cm.id DESC"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($c) {
        return [
            'id'         => (int)$c['id'],
            'text'       => $c['comment_text'],
            'analyst'    => $c['analyst_id'] === null ? null : ['id' => (int)$c['analyst_id'], 'name' => $c['analyst_name']],
            'created_at' => apiIsoDate($c['created_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

function apiChangeCommentsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadChange($conn, $params[0]);
    $text = trim((string)($body['text'] ?? ''));
    if ($text === '') {
        apiError(422, 'missing_field', "'text' is required.");
    }
    $actorId = (int)$apiKey['analyst_id'];
    $conn->prepare(
        "INSERT INTO change_comments (change_id, analyst_id, comment_text, is_internal, created_datetime)
         VALUES (?, ?, ?, 1, UTC_TIMESTAMP())"
    )->execute([$params[0], $actorId, $text]);
    $commentId = (int)$conn->lastInsertId();

    // Audit with a 100-char preview, same shape as save_comment.php.
    $preview = mb_strlen($text) > 100 ? mb_substr($text, 0, 100) . '...' : $text;
    apiChangeAuditWrite($conn, $params[0], $actorId, 'comment', null, null, $preview);

    apiRespond(['id' => $commentId, 'change_id' => $params[0], 'text' => $text], 201);
}

function apiChangeCommentsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadChange($conn, $params[0]);
    $stmt = $conn->prepare("DELETE FROM change_comments WHERE id = ? AND change_id = ?");
    $stmt->execute([$params[1], $params[0]]);
    if ($stmt->rowCount() === 0) {
        apiError(404, 'not_found', 'Comment not found.');
    }
    apiRespond(['id' => $params[1], 'deleted' => true]);
}

// ---------------------------------------------------------------------------
// GET /changes/{id}/audit
// ---------------------------------------------------------------------------
function apiChangeAuditList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadChange($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT au.id, au.action_type, au.field_name, au.old_value, au.new_value, au.created_datetime,
                au.analyst_id, a.full_name AS analyst_name
         FROM change_audit au LEFT JOIN analysts a ON a.id = au.analyst_id
         WHERE au.change_id = ? ORDER BY au.created_datetime DESC, au.id DESC"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($e) {
        return [
            'id'         => (int)$e['id'],
            'action'     => $e['action_type'],
            'field'      => $e['field_name'],
            'old_value'  => $e['old_value'],
            'new_value'  => $e['new_value'],
            'analyst'    => $e['analyst_id'] === null ? null : ['id' => (int)$e['analyst_id'], 'name' => $e['analyst_name']],
            'created_at' => apiIsoDate($e['created_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// ---------------------------------------------------------------------------
// CAB — roster + votes
// ---------------------------------------------------------------------------
function apiChangeCabGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $change = apiLoadChange($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT m.analyst_id, a.full_name, m.is_required, m.vote, m.vote_comment, m.vote_datetime
         FROM change_cab_members m LEFT JOIN analysts a ON a.id = m.analyst_id
         WHERE m.change_id = ? ORDER BY a.full_name"
    );
    $stmt->execute([$params[0]]);
    $members = array_map(function ($m) {
        return [
            'analyst_id'  => (int)$m['analyst_id'],
            'name'        => $m['full_name'],
            'is_required' => (bool)$m['is_required'],
            'vote'        => $m['vote'],
            'vote_comment' => $m['vote_comment'],
            'voted_at'    => apiIsoDate($m['vote_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $required = array_values(array_filter($members, fn($m) => $m['is_required']));
    apiRespond([
        'cab_required'  => (bool)$change['cab_required'],
        'approval_type' => $change['cab_approval_type'],
        'members'       => $members,
        'progress'      => [
            'required_total'    => count($required),
            'required_approved' => count(array_filter($required, fn($m) => $m['vote'] === 'Approve')),
            'required_rejected' => count(array_filter($required, fn($m) => $m['vote'] === 'Reject')),
        ],
    ]);
}

/** POST /changes/{id}/cab — replace the roster (diff-sync + audit, like save_cab_members.php). */
function apiChangeCabSave(PDO $conn, array $apiKey, array $params, array $body): void {
    $changeId = $params[0];
    apiLoadChange($conn, $changeId);
    $actorId = (int)$apiKey['analyst_id'];

    $incoming = $body['members'] ?? null;
    if (!is_array($incoming)) {
        apiError(422, 'missing_field', "'members' is required: [{\"analyst_id\": 1, \"is_required\": true}, …].");
    }
    $wanted = [];
    foreach ($incoming as $m) {
        $aid = isset($m['analyst_id']) ? (int)$m['analyst_id'] : 0;
        if ($aid <= 0) {
            apiError(422, 'invalid_field', "Each member needs an 'analyst_id'.");
        }
        apiResolveAnalyst($conn, $aid);
        $wanted[$aid] = array_key_exists('is_required', $m) ? (bool)$m['is_required'] : true;
    }

    $exStmt = $conn->prepare("SELECT analyst_id, is_required FROM change_cab_members WHERE change_id = ?");
    $exStmt->execute([$changeId]);
    $existing = [];
    foreach ($exStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing[(int)$row['analyst_id']] = (bool)$row['is_required'];
    }

    $nameOf = function (int $aid) use ($conn) {
        $s = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
        $s->execute([$aid]);
        return $s->fetchColumn() ?: (string)$aid;
    };

    foreach ($wanted as $aid => $isRequired) {
        if (!isset($existing[$aid])) {
            $conn->prepare(
                "INSERT INTO change_cab_members (change_id, analyst_id, is_required, added_by_id, added_datetime)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP())"
            )->execute([$changeId, $aid, $isRequired ? 1 : 0, $actorId]);
            apiChangeAuditWrite($conn, $changeId, $actorId, 'cab_vote', 'CAB Member', null,
                'Added: ' . $nameOf($aid) . ' (' . ($isRequired ? 'Required' : 'Optional') . ')');
        } elseif ($existing[$aid] !== $isRequired) {
            $conn->prepare("UPDATE change_cab_members SET is_required = ? WHERE change_id = ? AND analyst_id = ?")
                 ->execute([$isRequired ? 1 : 0, $changeId, $aid]);
            apiChangeAuditWrite($conn, $changeId, $actorId, 'cab_vote', 'CAB Member', null,
                $nameOf($aid) . ': ' . ($isRequired ? 'Optional → Required' : 'Required → Optional'));
        }
    }
    foreach ($existing as $aid => $isRequired) {
        if (!isset($wanted[$aid])) {
            $conn->prepare("DELETE FROM change_cab_members WHERE change_id = ? AND analyst_id = ?")
                 ->execute([$changeId, $aid]);
            apiChangeAuditWrite($conn, $changeId, $actorId, 'cab_vote', 'CAB Member', null, 'Removed: ' . $nameOf($aid));
        }
    }

    apiChangeCabGet($conn, $apiKey, $params, $body);
}

/** POST /changes/{id}/cab/vote — the key's acts-as analyst votes; mirrors submit_cab_vote.php. */
function apiChangeCabVote(PDO $conn, array $apiKey, array $params, array $body): void {
    $changeId = $params[0];
    apiLoadChange($conn, $changeId);
    $actorId = (int)$apiKey['analyst_id'];

    $vote = $body['vote'] ?? '';
    if (!in_array($vote, ['Approve', 'Reject', 'Abstain'], true)) {
        apiError(422, 'invalid_field', "'vote' must be Approve, Reject or Abstain.");
    }
    $voteComment = trim((string)($body['comment'] ?? ''));

    $memberStmt = $conn->prepare("SELECT id, vote FROM change_cab_members WHERE change_id = ? AND analyst_id = ?");
    $memberStmt->execute([$changeId, $actorId]);
    $membership = $memberStmt->fetch(PDO::FETCH_ASSOC);
    if (!$membership) {
        apiError(403, 'forbidden', 'The analyst this key acts as is not a CAB member for this change.');
    }
    if ($membership['vote'] !== null) {
        apiError(409, 'conflict', 'This CAB member has already voted on this change.');
    }

    $conn->prepare(
        "UPDATE change_cab_members SET vote = ?, vote_comment = ?, vote_datetime = UTC_TIMESTAMP()
         WHERE change_id = ? AND analyst_id = ?"
    )->execute([$vote, $voteComment ?: null, $changeId, $actorId]);

    $nameStmt = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
    $nameStmt->execute([$actorId]);
    $analystName = $nameStmt->fetchColumn() ?: 'Unknown';

    $auditDisplay = "$vote by $analystName";
    if ($voteComment) {
        $preview = mb_strlen($voteComment) > 80 ? mb_substr($voteComment, 0, 80) . '...' : $voteComment;
        $auditDisplay .= ": $preview";
    }
    apiChangeAuditWrite($conn, $changeId, $actorId, 'cab_vote', 'CAB Vote', null, $auditDisplay);

    // Auto-transition — identical mechanics to submit_cab_vote.php.
    $statusChanged = false;
    $newStatus = null;

    $changeStmt = $conn->prepare(
        "SELECT c.cab_approval_type, c.title, c.risk_level, c.approver_id, cs.name AS status
         FROM changes c LEFT JOIN change_statuses cs ON cs.id = c.status_id WHERE c.id = ?"
    );
    $changeStmt->execute([$changeId]);
    $changeRow = $changeStmt->fetch(PDO::FETCH_ASSOC);

    $statusIdFor = function ($name) use ($conn) {
        $s = $conn->prepare("SELECT id FROM change_statuses WHERE name = ? LIMIT 1");
        $s->execute([$name]);
        return $s->fetchColumn() ?: null;
    };

    if ($changeRow && $changeRow['status'] === 'Pending Approval') {
        $approvalType = $changeRow['cab_approval_type'] ?: 'all';
        $reqStmt = $conn->prepare("SELECT vote FROM change_cab_members WHERE change_id = ? AND is_required = 1");
        $reqStmt->execute([$changeId]);
        $reqVotes = $reqStmt->fetchAll(PDO::FETCH_COLUMN);

        $totalRequired = count($reqVotes);
        $approved = count(array_filter($reqVotes, fn($v) => $v === 'Approve'));
        $rejected = count(array_filter($reqVotes, fn($v) => $v === 'Reject'));

        if ($rejected > 0) {
            $conn->prepare("UPDATE changes SET status_id = ?, modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$statusIdFor('Draft'), $changeId]);
            apiChangeAuditWrite($conn, $changeId, $actorId, 'status_change', 'Status', 'Pending Approval', 'Draft');
            $statusChanged = true;
            $newStatus = 'Draft';
        } elseif ($totalRequired > 0) {
            $thresholdMet = ($approvalType === 'majority') ? ($approved > $totalRequired / 2) : ($approved === $totalRequired);
            if ($thresholdMet) {
                $conn->prepare("UPDATE changes SET status_id = ?, approval_datetime = UTC_TIMESTAMP(), modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
                     ->execute([$statusIdFor('Approved'), $changeId]);
                apiChangeAuditWrite($conn, $changeId, $actorId, 'status_change', 'Status', 'Pending Approval', 'Approved');
                $statusChanged = true;
                $newStatus = 'Approved';
            }
        }
    }

    if ($statusChanged && $newStatus === 'Approved') {
        apiChangeApprovedDispatch($changeId, $changeRow['title'] ?? null, $changeRow['risk_level'] ?? null,
            isset($changeRow['approver_id']) && $changeRow['approver_id'] !== null ? (int)$changeRow['approver_id'] : null);
    }

    apiRespond([
        'change_id'      => $changeId,
        'vote'           => $vote,
        'status_changed' => $statusChanged,
        'new_status'     => $newStatus,
    ], 201);
}

// ---------------------------------------------------------------------------
// Reference lookups
// ---------------------------------------------------------------------------
function apiChangeLookupList(PDO $conn, string $table, bool $hasIsClosed = false): void {
    $cols = 'id, name, colour, is_default, is_active' . ($hasIsClosed ? ', is_closed' : '');
    $rows = $conn->query("SELECT $cols FROM `$table` ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($r) use ($hasIsClosed) {
        $out = [
            'id'         => (int)$r['id'],
            'name'       => $r['name'],
            'colour'     => $r['colour'],
            'is_default' => (bool)$r['is_default'],
            'is_active'  => (bool)$r['is_active'],
        ];
        if ($hasIsClosed) {
            $out['is_closed'] = (bool)$r['is_closed'];
        }
        return $out;
    }, $rows));
}

function apiChangeStatusesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiChangeLookupList($conn, 'change_statuses', true);
}
function apiChangeTypesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiChangeLookupList($conn, 'change_types');
}
function apiChangePrioritiesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiChangeLookupList($conn, 'change_priorities');
}
function apiChangeImpactsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiChangeLookupList($conn, 'change_impacts');
}
function apiChangeCategoriesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query("SELECT id, name, description, is_active FROM change_categories ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($r) {
        return [
            'id'          => (int)$r['id'],
            'name'        => $r['name'],
            'description' => $r['description'],
            'is_active'   => (bool)$r['is_active'],
        ];
    }, $rows));
}
