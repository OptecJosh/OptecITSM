<?php
/**
 * FreeITSM REST API v1 — tasks resource (kanban Tasks module).
 *
 * Mirrors the module's internal endpoints so a task touched via the API is
 * indistinguishable from one touched in the UI:
 *   - create mirrors api/tasks/save.php: only title is required, defaults
 *     To Do / Medium (falling back to the is_default rows), board_position
 *     appends to the end of the target status column.
 *   - status changes drive completed_datetime exactly like the UI
 *     (COALESCE-stamped on entering a closed status, cleared on reopening),
 *     and a PATCH that moves an open task into a closed status fires the
 *     task.completed workflow event with save.php's exact payload.
 *   - POST /tasks/{id}/move mirrors reorder.php: status + position change
 *     with the column re-packed — and, like the UI's drag, it does NOT fire
 *     the workflow event (that asymmetry is the product's, preserved for
 *     parity).
 *   - comments are create-only (no edit/delete exists in the product);
 *     DELETE is a hard delete that cascades subtasks/comments/tags.
 *
 * Tasks have no audit trail in the product — none is invented here.
 * Task tables carry no tenant_id (install-wide), BUT a task can link to a
 * ticket, and tickets ARE company-scoped: the API validates ticket links
 * against the key's company scope and only reveals linked-ticket details the
 * key could read directly — tighter than the UI, which doesn't check.
 */

require_once dirname(__DIR__, 3) . '/workflow/includes/engine.php';

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function apiTaskSelect(): string {
    return "SELECT t.*,
                   ts.name AS status_name, ts.is_closed AS status_is_closed, ts.colour AS status_colour,
                   tp.name AS priority_name, tp.colour AS priority_colour,
                   a.full_name AS analyst_name,
                   tm.name AS team_name,
                   cb.full_name AS created_by_name
            FROM tasks t
            LEFT JOIN task_statuses   ts ON ts.id = t.status_id
            LEFT JOIN task_priorities tp ON tp.id = t.priority_id
            LEFT JOIN analysts        a  ON a.id  = t.assigned_analyst_id
            LEFT JOIN teams           tm ON tm.id = t.assigned_team_id
            LEFT JOIN analysts        cb ON cb.id = t.created_by_id";
}

function apiSerializeTask(PDO $conn, array $r): array {
    $rel = function ($id, $name, array $extra = []) {
        return $id === null ? null : array_merge(['id' => (int)$id, 'name' => $name], $extra);
    };

    $tagsStmt = $conn->prepare(
        "SELECT tg.name FROM task_tag_map m JOIN task_tags tg ON tg.id = m.tag_id
         WHERE m.task_id = ? ORDER BY tg.display_order, tg.name"
    );
    $tagsStmt->execute([(int)$r['id']]);

    $subStmt = $conn->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN ts.is_closed = 1 THEN 1 ELSE 0 END) AS done
         FROM tasks s LEFT JOIN task_statuses ts ON ts.id = s.status_id
         WHERE s.parent_task_id = ?"
    );
    $subStmt->execute([(int)$r['id']]);
    $sub = $subStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'done' => 0];

    return [
        'id'          => (int)$r['id'],
        'title'       => $r['title'],
        'description' => $r['description'],
        'status'      => $rel($r['status_id'], $r['status_name'], [
            'is_closed' => (bool)($r['status_is_closed'] ?? false),
            'colour'    => $r['status_colour'] ?? null,
        ]),
        'priority'    => $rel($r['priority_id'], $r['priority_name'], ['colour' => $r['priority_colour'] ?? null]),
        'assigned_analyst' => $rel($r['assigned_analyst_id'], $r['analyst_name']),
        'assigned_team'    => $rel($r['assigned_team_id'], $r['team_name']),
        'start_date'  => $r['start_date'],
        'due_date'    => $r['due_date'],
        'parent_task_id' => $r['parent_task_id'] !== null ? (int)$r['parent_task_id'] : null,
        'ticket_id'   => $r['ticket_id'] !== null ? (int)$r['ticket_id'] : null,
        'change_id'   => $r['change_id'] !== null ? (int)$r['change_id'] : null,
        'contract_id' => $r['contract_id'] !== null ? (int)$r['contract_id'] : null,
        'board_position' => (int)$r['board_position'],
        'tags'        => $tagsStmt->fetchAll(PDO::FETCH_COLUMN),
        'subtasks'    => ['total' => (int)$sub['total'], 'done' => (int)($sub['done'] ?? 0)],
        'created_by'  => $rel($r['created_by_id'], $r['created_by_name']),
        'created_at'  => apiIsoDate($r['created_datetime']),
        'updated_at'  => apiIsoDate($r['updated_datetime']),
        'completed_at' => apiIsoDate($r['completed_datetime']),
    ];
}

function apiLoadTask(PDO $conn, int $taskId): array {
    $stmt = $conn->prepare(apiTaskSelect() . " WHERE t.id = ?");
    $stmt->execute([$taskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Task not found.');
    }
    return $row;
}

/** Resolve a task lookup (status/priority) by name or id — strict 422 on unknown. */
function apiResolveTaskLookup(PDO $conn, array $body, string $key, string $table, bool $withClosed = false): ?array {
    $cols = 'id, name' . ($withClosed ? ', is_closed' : '');
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
        apiError(422, 'invalid_field', "Unknown task $key: " . ($body[$key] ?? $body[$key . '_id']));
    }
    $out = [(int)$row['id'], $row['name']];
    if ($withClosed) {
        $out[] = (int)$row['is_closed'];
    }
    return $out;
}

/** The default row of a task lookup table: [id, name(, is_closed)]. Prefers the named seed, then is_default. */
function apiTaskLookupDefault(PDO $conn, string $table, string $preferName, bool $withClosed = false): array {
    $cols = 'id, name' . ($withClosed ? ', is_closed' : '');
    $stmt = $conn->prepare("SELECT $cols FROM `$table` WHERE name = ? LIMIT 1");
    $stmt->execute([$preferName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $row = $conn->query("SELECT $cols FROM `$table` WHERE is_default = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) {
        return $withClosed ? [null, null, 0] : [null, null];
    }
    $out = [(int)$row['id'], $row['name']];
    if ($withClosed) {
        $out[] = (int)$row['is_closed'];
    }
    return $out;
}

/**
 * Validate the three link columns + parent task. Ticket links are checked
 * against the key's company scope (tickets are tenant-scoped; tasks aren't).
 * Returns the validated value or exits with 404/422.
 */
function apiTaskValidateLink(PDO $conn, array $apiKey, string $field, $value) {
    if ($value === '' || $value === null) {
        return null;
    }
    $id = (int)$value;
    switch ($field) {
        case 'ticket_id':
            if (!apiKeyCanAccessTicket($conn, $apiKey, $id)) {
                apiError(422, 'invalid_field', "Unknown ticket id: {$id}");
            }
            return $id;
        case 'change_id':
            $stmt = $conn->prepare("SELECT id FROM changes WHERE id = ?");
            break;
        case 'contract_id':
            $stmt = $conn->prepare("SELECT id FROM contracts WHERE id = ?");
            break;
        case 'parent_task_id':
            $stmt = $conn->prepare("SELECT id FROM tasks WHERE id = ?");
            break;
        default:
            return null;
    }
    try {
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) {
            apiError(422, 'invalid_field', "Unknown " . str_replace('_id', '', $field) . " id: {$id}");
        }
    } catch (Exception $e) {
        apiError(422, 'invalid_field', ucfirst(str_replace('_id', ' ', $field)) . "links are not available on this install.");
    }
    return $id;
}

/** Resolve a tags array (names or ids) to ids — strict 422 on unknown (tags are a curated list). */
function apiTaskResolveTags(PDO $conn, array $tags): array {
    $ids = [];
    foreach ($tags as $t) {
        if (is_numeric($t)) {
            $stmt = $conn->prepare("SELECT id FROM task_tags WHERE id = ?");
            $stmt->execute([(int)$t]);
        } else {
            $stmt = $conn->prepare("SELECT id FROM task_tags WHERE name = ?");
            $stmt->execute([trim((string)$t)]);
        }
        $id = $stmt->fetchColumn();
        if ($id === false) {
            apiError(422, 'invalid_field', "Unknown tag: {$t}. Tags are managed in Tasks > Settings.");
        }
        $ids[(int)$id] = true;
    }
    return array_keys($ids);
}

function apiTaskSyncTags(PDO $conn, int $taskId, array $tagIds): void {
    $conn->prepare("DELETE FROM task_tag_map WHERE task_id = ?")->execute([$taskId]);
    $ins = $conn->prepare("INSERT IGNORE INTO task_tag_map (task_id, tag_id) VALUES (?, ?)");
    foreach ($tagIds as $tid) {
        $ins->execute([$taskId, $tid]);
    }
}

/** save.php's exact task.completed dispatch (open -> closed transitions via PATCH only). */
function apiTaskCompletedDispatch(PDO $conn, int $taskId): void {
    try {
        $rb = $conn->prepare("SELECT title, priority_id, assigned_analyst_id FROM tasks WHERE id = ?");
        $rb->execute([$taskId]);
        $taskRow = $rb->fetch(PDO::FETCH_ASSOC) ?: [];
        WorkflowEngine::dispatch('task.completed', [
            'task' => [
                'id'          => $taskId,
                'title'       => $taskRow['title'] ?? null,
                'priority_id' => isset($taskRow['priority_id']) ? (int)$taskRow['priority_id'] : null,
                'assignee_id' => isset($taskRow['assigned_analyst_id']) ? (int)$taskRow['assigned_analyst_id'] : null,
            ],
        ]);
    } catch (Exception $wfEx) {
        error_log('Workflow dispatch error in API v1 task: ' . $wfEx->getMessage());
    }
}

// ---------------------------------------------------------------------------
// GET /tasks
// ---------------------------------------------------------------------------
function apiTasksList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];

    // Top-level tasks by default (the board's view); parent_task_id=N lists a
    // task's subtasks instead.
    if (isset($_GET['parent_task_id']) && $_GET['parent_task_id'] !== '') {
        $where[] = 't.parent_task_id = ?';
        $args[]  = (int)$_GET['parent_task_id'];
    } else {
        $where[] = 't.parent_task_id IS NULL';
    }

    $state = strtolower(trim($_GET['state'] ?? 'all'));
    if ($state === 'open')   $where[] = "(ts.is_closed IS NULL OR ts.is_closed = 0)";
    if ($state === 'closed') $where[] = "ts.is_closed = 1";

    foreach ([
        'status_id'           => 't.status_id',
        'priority_id'         => 't.priority_id',
        'assigned_analyst_id' => 't.assigned_analyst_id',
        'assigned_team_id'    => 't.assigned_team_id',
        'ticket_id'           => 't.ticket_id',
        'change_id'           => 't.change_id',
        'contract_id'         => 't.contract_id',
    ] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col = ?";
            $args[]  = (int)$_GET[$param];
        }
    }
    foreach (['status' => 'ts.name', 'priority' => 'tp.name'] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col = ?";
            $args[]  = trim($_GET[$param]);
        }
    }
    if (($_GET['unassigned'] ?? '') === 'true') {
        $where[] = 't.assigned_analyst_id IS NULL';
    }
    if (isset($_GET['tag']) && trim($_GET['tag']) !== '') {
        $where[] = 't.id IN (SELECT m.task_id FROM task_tag_map m JOIN task_tags tg ON tg.id = m.tag_id WHERE tg.name = ?)';
        $args[]  = trim($_GET['tag']);
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = '(t.title LIKE ? OR t.description LIKE ?)';
        $like = '%' . trim($_GET['q']) . '%';
        array_push($args, $like, $like);
    }
    foreach (['due_before' => ['t.due_date', '<='], 'due_after' => ['t.due_date', '>=']] as $param => [$col, $op]) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col $op ?";
            $args[]  = apiParseDateOnly($_GET[$param], $param);
        }
    }
    if (($_GET['overdue'] ?? '') === 'true') {
        $where[] = "t.due_date < CURDATE() AND (ts.is_closed IS NULL OR ts.is_closed = 0)";
    }

    $sortable = [
        'created_at' => 't.created_datetime', 'updated_at' => 't.updated_datetime',
        'due_date' => 't.due_date', 'board_position' => 't.board_position',
        'title' => 't.title', 'id' => 't.id', 'completed_at' => 't.completed_datetime',
    ];
    $sortParam = trim($_GET['sort'] ?? 'board_position');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) {
        apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    }
    $orderSql = $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC') . ', t.created_datetime DESC';

    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) FROM tasks t
         LEFT JOIN task_statuses ts ON ts.id = t.status_id
         LEFT JOIN task_priorities tp ON tp.id = t.priority_id
         WHERE $whereSql"
    );
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(apiTaskSelect() . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
    $stmt->execute($args);
    $tasks = array_map(function ($r) use ($conn) {
        return apiSerializeTask($conn, $r);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    apiRespond($tasks, 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// ---------------------------------------------------------------------------
// GET /tasks/{id}
// ---------------------------------------------------------------------------
function apiTasksGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $r = apiLoadTask($conn, $params[0]);
    $task = apiSerializeTask($conn, $r);

    // Parent summary
    $task['parent'] = null;
    if ($r['parent_task_id'] !== null) {
        $p = $conn->prepare("SELECT id, title FROM tasks WHERE id = ?");
        $p->execute([(int)$r['parent_task_id']]);
        $pr = $p->fetch(PDO::FETCH_ASSOC);
        if ($pr) {
            $task['parent'] = ['id' => (int)$pr['id'], 'title' => $pr['title']];
        }
    }

    // Subtask list (ordered like the UI)
    $s = $conn->prepare(
        "SELECT s.id, s.title, s.board_position, ts.name AS status, ts.is_closed
         FROM tasks s LEFT JOIN task_statuses ts ON ts.id = s.status_id
         WHERE s.parent_task_id = ? ORDER BY s.board_position ASC, s.created_datetime ASC"
    );
    $s->execute([$params[0]]);
    $task['subtask_list'] = array_map(function ($x) {
        return [
            'id'             => (int)$x['id'],
            'title'          => $x['title'],
            'status'         => $x['status'],
            'is_closed'      => (bool)$x['is_closed'],
            'board_position' => (int)$x['board_position'],
        ];
    }, $s->fetchAll(PDO::FETCH_ASSOC));

    // Linked ticket/change summaries — ticket details only when the key's
    // company scope could read that ticket directly (tighter than the UI).
    $task['linked_ticket'] = null;
    if ($r['ticket_id'] !== null && apiKeyCanAccessTicket($conn, $apiKey, (int)$r['ticket_id'])) {
        $t = $conn->prepare("SELECT id, ticket_number, subject FROM tickets WHERE id = ?");
        $t->execute([(int)$r['ticket_id']]);
        $tr = $t->fetch(PDO::FETCH_ASSOC);
        if ($tr) {
            $task['linked_ticket'] = ['id' => (int)$tr['id'], 'ticket_number' => $tr['ticket_number'], 'subject' => $tr['subject']];
        }
    }
    $task['linked_change'] = null;
    if ($r['change_id'] !== null) {
        try {
            $c = $conn->prepare("SELECT id, title FROM changes WHERE id = ?");
            $c->execute([(int)$r['change_id']]);
            $cr = $c->fetch(PDO::FETCH_ASSOC);
            if ($cr) {
                $task['linked_change'] = ['id' => (int)$cr['id'], 'title' => $cr['title']];
            }
        } catch (Exception $e) { /* change module absent */ }
    }

    // Comments (ascending, like the UI)
    $cm = $conn->prepare(
        "SELECT c.id, c.comment, c.created_datetime, c.analyst_id, a.full_name AS analyst_name
         FROM task_comments c LEFT JOIN analysts a ON a.id = c.analyst_id
         WHERE c.task_id = ? ORDER BY c.created_datetime ASC, c.id ASC"
    );
    $cm->execute([$params[0]]);
    $task['comments'] = array_map(function ($c) {
        return [
            'id'         => (int)$c['id'],
            'text'       => $c['comment'],
            'analyst'    => $c['analyst_id'] === null ? null : ['id' => (int)$c['analyst_id'], 'name' => $c['analyst_name']],
            'created_at' => apiIsoDate($c['created_datetime']),
        ];
    }, $cm->fetchAll(PDO::FETCH_ASSOC));

    apiRespond($task);
}

// ---------------------------------------------------------------------------
// POST /tasks
// ---------------------------------------------------------------------------
function apiTasksCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $title = trim((string)($body['title'] ?? ''));
    if ($title === '') {
        apiError(422, 'missing_field', "'title' is required.");
    }

    $status   = apiResolveTaskLookup($conn, $body, 'status', 'task_statuses', true)
        ?? apiTaskLookupDefault($conn, 'task_statuses', 'To Do', true);
    $priority = apiResolveTaskLookup($conn, $body, 'priority', 'task_priorities')
        ?? apiTaskLookupDefault($conn, 'task_priorities', 'Medium');

    $analystId = null;
    if (isset($body['assigned_analyst_id']) && $body['assigned_analyst_id'] !== '') {
        $analystId = (int)$body['assigned_analyst_id'];
        apiResolveAnalyst($conn, $analystId);
    }
    $teamId = null;
    if (isset($body['assigned_team_id']) && $body['assigned_team_id'] !== '') {
        $teamId = (int)$body['assigned_team_id'];
        $tStmt = $conn->prepare("SELECT id FROM teams WHERE id = ?");
        $tStmt->execute([$teamId]);
        if (!$tStmt->fetchColumn()) {
            apiError(422, 'invalid_field', "Unknown team id: {$teamId}");
        }
    }

    $links = [];
    foreach (['parent_task_id', 'ticket_id', 'change_id', 'contract_id'] as $field) {
        $links[$field] = apiTaskValidateLink($conn, $apiKey, $field, $body[$field] ?? null);
    }

    $startDate = apiParseDateOnly($body['start_date'] ?? null, 'start_date');
    $dueDate   = apiParseDateOnly($body['due_date'] ?? null, 'due_date');
    $description = trim((string)($body['description'] ?? '')) ?: null;

    $tagIds = null;
    if (isset($body['tags']) && is_array($body['tags'])) {
        $tagIds = apiTaskResolveTags($conn, $body['tags']);
    }

    // Append to the end of the target status column (top-level tasks only) — save.php's rule.
    $posStmt = $conn->prepare("SELECT COALESCE(MAX(board_position), -1) + 1 FROM tasks WHERE status_id = ? AND parent_task_id IS NULL");
    $posStmt->execute([$status[0]]);
    $boardPosition = (int)$posStmt->fetchColumn();

    $ins = $conn->prepare(
        "INSERT INTO tasks (title, description, status_id, priority_id, start_date, due_date,
                            assigned_analyst_id, assigned_team_id, parent_task_id,
                            ticket_id, change_id, contract_id, board_position, created_by_id,
                            completed_datetime, created_datetime, updated_datetime)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $ins->execute([
        $title, $description, $status[0], $priority[0], $startDate, $dueDate,
        $analystId, $teamId, $links['parent_task_id'],
        $links['ticket_id'], $links['change_id'], $links['contract_id'],
        $boardPosition, (int)$apiKey['analyst_id'],
        !empty($status[2]) ? gmdate('Y-m-d H:i:s') : null,
    ]);
    $taskId = (int)$conn->lastInsertId();

    if ($tagIds !== null) {
        apiTaskSyncTags($conn, $taskId, $tagIds);
    }

    apiRespond(apiSerializeTask($conn, apiLoadTask($conn, $taskId)), 201);
}

// ---------------------------------------------------------------------------
// PATCH /tasks/{id}
// ---------------------------------------------------------------------------
function apiTasksUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $taskId = $params[0];
    $current = apiLoadTask($conn, $taskId);
    if (!$body) {
        apiError(422, 'missing_field', 'No fields to update.');
    }

    $updates = [];
    $args    = [];

    if (array_key_exists('title', $body)) {
        $title = trim((string)$body['title']);
        if ($title === '') {
            apiError(422, 'invalid_field', "'title' cannot be empty.");
        }
        $updates[] = 'title = ?';
        $args[]    = $title;
    }
    if (array_key_exists('description', $body)) {
        $updates[] = 'description = ?';
        $args[]    = trim((string)$body['description']) ?: null;
    }

    // Status — completed_datetime mechanics + workflow dispatch mirror save.php.
    $wasClosed = (bool)($current['status_is_closed'] ?? false);
    $firesCompleted = false;
    $status = apiResolveTaskLookup($conn, $body, 'status', 'task_statuses', true);
    if ($status !== null && $status[0] !== (int)$current['status_id']) {
        $updates[] = 'status_id = ?';
        $args[]    = $status[0];
        if ($status[2]) {
            $updates[] = 'completed_datetime = COALESCE(completed_datetime, UTC_TIMESTAMP())';
            $firesCompleted = !$wasClosed;
        } else {
            $updates[] = 'completed_datetime = NULL';
        }
    }
    $priority = apiResolveTaskLookup($conn, $body, 'priority', 'task_priorities');
    if ($priority !== null && $priority[0] !== ($current['priority_id'] !== null ? (int)$current['priority_id'] : null)) {
        $updates[] = 'priority_id = ?';
        $args[]    = $priority[0];
    }

    if (array_key_exists('assigned_analyst_id', $body)) {
        $newAnalyst = ($body['assigned_analyst_id'] === '' || $body['assigned_analyst_id'] === null) ? null : (int)$body['assigned_analyst_id'];
        if ($newAnalyst !== null) {
            apiResolveAnalyst($conn, $newAnalyst);
        }
        $updates[] = 'assigned_analyst_id = ?';
        $args[]    = $newAnalyst;
    }
    if (array_key_exists('assigned_team_id', $body)) {
        $newTeam = ($body['assigned_team_id'] === '' || $body['assigned_team_id'] === null) ? null : (int)$body['assigned_team_id'];
        if ($newTeam !== null) {
            $tStmt = $conn->prepare("SELECT id FROM teams WHERE id = ?");
            $tStmt->execute([$newTeam]);
            if (!$tStmt->fetchColumn()) {
                apiError(422, 'invalid_field', "Unknown team id: {$newTeam}");
            }
        }
        $updates[] = 'assigned_team_id = ?';
        $args[]    = $newTeam;
    }

    foreach (['start_date', 'due_date'] as $field) {
        if (array_key_exists($field, $body)) {
            $updates[] = "$field = ?";
            $args[]    = apiParseDateOnly($body[$field], $field);
        }
    }

    foreach (['parent_task_id', 'ticket_id', 'change_id', 'contract_id'] as $field) {
        if (!array_key_exists($field, $body)) {
            continue;
        }
        if ($field === 'parent_task_id' && (int)$body[$field] === (int)$taskId) {
            apiError(422, 'invalid_field', 'A task cannot be its own parent.');
        }
        $updates[] = "$field = ?";
        $args[]    = apiTaskValidateLink($conn, $apiKey, $field, $body[$field]);
    }

    if (array_key_exists('board_position', $body) && $body['board_position'] !== '' && $body['board_position'] !== null) {
        $updates[] = 'board_position = ?';
        $args[]    = max(0, (int)$body['board_position']);
    }

    $tagIds = null;
    if (isset($body['tags']) && is_array($body['tags'])) {
        $tagIds = apiTaskResolveTags($conn, $body['tags']);
    }

    if (!$updates && $tagIds === null) {
        apiRespond(apiSerializeTask($conn, $current)); // nothing to do
    }

    if ($updates) {
        $updates[] = 'updated_datetime = UTC_TIMESTAMP()';
        $args[]    = $taskId;
        $conn->prepare('UPDATE tasks SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);
    }
    if ($tagIds !== null) {
        apiTaskSyncTags($conn, $taskId, $tagIds);
        $conn->prepare("UPDATE tasks SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$taskId]);
    }

    if ($firesCompleted) {
        apiTaskCompletedDispatch($conn, $taskId);
    }

    apiRespond(apiSerializeTask($conn, apiLoadTask($conn, $taskId)));
}

// ---------------------------------------------------------------------------
// POST /tasks/{id}/move — kanban move (mirrors reorder.php: NO workflow event)
// ---------------------------------------------------------------------------
function apiTasksMove(PDO $conn, array $apiKey, array $params, array $body): void {
    $taskId = $params[0];
    $current = apiLoadTask($conn, $taskId);

    $status = apiResolveTaskLookup($conn, $body, 'status', 'task_statuses', true);
    $targetStatusId = $status !== null ? $status[0] : (int)$current['status_id'];
    $targetIsClosed = $status !== null ? (bool)$status[2] : (bool)$current['status_is_closed'];

    $position = array_key_exists('position', $body) && $body['position'] !== null && $body['position'] !== ''
        ? max(0, (int)$body['position'])
        : null; // null = end of column

    $conn->beginTransaction();
    try {
        // Status + completed_datetime, exactly like reorder.php.
        $conn->prepare(
            "UPDATE tasks SET status_id = ?,
                    completed_datetime = " . ($targetIsClosed ? "COALESCE(completed_datetime, UTC_TIMESTAMP())" : "NULL") . ",
                    updated_datetime = UTC_TIMESTAMP()
             WHERE id = ?"
        )->execute([$targetStatusId, $taskId]);

        // Re-pack the target column: existing top-level tasks in order, with
        // the moved task spliced in at the requested position (default: end).
        $colStmt = $conn->prepare(
            "SELECT id FROM tasks
             WHERE status_id = ? AND parent_task_id IS NULL AND id != ?
             ORDER BY board_position ASC, created_datetime ASC"
        );
        $colStmt->execute([$targetStatusId, $taskId]);
        $column = array_map('intval', $colStmt->fetchAll(PDO::FETCH_COLUMN));

        $insertAt = ($position === null || $position > count($column)) ? count($column) : $position;
        array_splice($column, $insertAt, 0, [$taskId]);

        $posUpd = $conn->prepare("UPDATE tasks SET board_position = ? WHERE id = ?");
        foreach ($column as $i => $id) {
            $posUpd->execute([$i, $id]);
        }
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    // NB: no task.completed dispatch here — the UI's drag (reorder.php)
    // doesn't fire it either; only a status change via PATCH does.
    apiRespond(apiSerializeTask($conn, apiLoadTask($conn, $taskId)));
}

// ---------------------------------------------------------------------------
// DELETE /tasks/{id} — hard delete. Children removed explicitly, not via FK
// cascade: installs grown via Database Verify were missing the parent and
// comments cascade FKs, which orphaned subtasks/comments (same fix as the
// UI's delete.php).
// ---------------------------------------------------------------------------
function apiTasksDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTask($conn, $params[0]);

    // Walk the subtask tree, then delete comments/tag links for every task in
    // it, then the tasks themselves, children first.
    $ids = [$params[0]];
    $frontier = [$params[0]];
    while ($frontier) {
        $ph = implode(',', array_fill(0, count($frontier), '?'));
        $kids = $conn->prepare("SELECT id FROM tasks WHERE parent_task_id IN ($ph)");
        $kids->execute($frontier);
        $frontier = array_map('intval', $kids->fetchAll(PDO::FETCH_COLUMN));
        $ids = array_merge($ids, $frontier);
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $conn->prepare("DELETE FROM task_comments WHERE task_id IN ($ph)")->execute($ids);
    $conn->prepare("DELETE FROM task_tag_map WHERE task_id IN ($ph)")->execute($ids);
    foreach (array_reverse($ids) as $taskId) {
        $conn->prepare("DELETE FROM tasks WHERE id = ?")->execute([$taskId]);
    }

    apiRespond(['id' => $params[0], 'deleted' => true, 'subtasks_deleted' => count($ids) - 1]);
}

// ---------------------------------------------------------------------------
// Comments — create-only, like the UI
// ---------------------------------------------------------------------------
function apiTaskCommentsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTask($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT c.id, c.comment, c.created_datetime, c.analyst_id, a.full_name AS analyst_name
         FROM task_comments c LEFT JOIN analysts a ON a.id = c.analyst_id
         WHERE c.task_id = ? ORDER BY c.created_datetime ASC, c.id ASC"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($c) {
        return [
            'id'         => (int)$c['id'],
            'text'       => $c['comment'],
            'analyst'    => $c['analyst_id'] === null ? null : ['id' => (int)$c['analyst_id'], 'name' => $c['analyst_name']],
            'created_at' => apiIsoDate($c['created_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

function apiTaskCommentsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadTask($conn, $params[0]);
    $text = trim((string)($body['text'] ?? ''));
    if ($text === '') {
        apiError(422, 'missing_field', "'text' is required.");
    }
    $conn->prepare("INSERT INTO task_comments (task_id, analyst_id, comment, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
         ->execute([$params[0], (int)$apiKey['analyst_id'], $text]);
    $commentId = (int)$conn->lastInsertId();
    $conn->prepare("UPDATE tasks SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$params[0]]);
    apiRespond(['id' => $commentId, 'task_id' => $params[0], 'text' => $text], 201);
}

// ---------------------------------------------------------------------------
// Reference lookups
// ---------------------------------------------------------------------------
function apiTaskStatusesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT id, name, is_closed, is_default, colour, is_active, display_order
         FROM task_statuses ORDER BY display_order, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($s) {
        return [
            'id'            => (int)$s['id'],
            'name'          => $s['name'],
            'is_closed'     => (bool)$s['is_closed'],
            'is_default'    => (bool)$s['is_default'],
            'colour'        => $s['colour'],
            'is_active'     => (bool)$s['is_active'],
            'display_order' => (int)$s['display_order'],
        ];
    }, $rows));
}

function apiTaskPrioritiesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT id, name, is_default, colour, is_active FROM task_priorities ORDER BY display_order, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($p) {
        return [
            'id'         => (int)$p['id'],
            'name'       => $p['name'],
            'is_default' => (bool)$p['is_default'],
            'colour'     => $p['colour'],
            'is_active'  => (bool)$p['is_active'],
        ];
    }, $rows));
}

function apiTaskTagsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT t.id, t.name, t.colour,
                (SELECT COUNT(*) FROM task_tag_map m WHERE m.tag_id = t.id) AS task_count
         FROM task_tags t ORDER BY t.display_order, t.name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($t) {
        return [
            'id'         => (int)$t['id'],
            'name'       => $t['name'],
            'colour'     => $t['colour'],
            'task_count' => (int)$t['task_count'],
        ];
    }, $rows));
}
