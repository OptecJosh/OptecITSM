<?php
/**
 * API: Unified cross-module audit log (Phase 10a).
 *
 * A read-only AGGREGATOR — it UNION-normalises the existing per-module audit
 * tables (ticket_audit, change_audit, problem_audit, asset_history, system_logs)
 * into one stream of { when, actor, module, entity, action, detail } rows. No
 * data migration; the per-module tables stay authoritative. Each source is
 * table-exists-guarded so a partial install just contributes fewer streams.
 *
 * GET params (all optional):
 *   module     one of tickets|changes|problems|assets|system (else all)
 *   actor_id   analyst id
 *   date_from  YYYY-MM-DD (inclusive)
 *   date_to    YYYY-MM-DD (inclusive)
 *   keyword    substring over the row's text
 *   page       1-based (default 1), limit (default 50, max 200)
 *   format=csv streams a CSV of the matching rows (capped at 10000), no paging
 *
 * Admin-gated (audit data spans every company and actor).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // auth + admin (loads functions.php)

$isCsv = (($_GET['format'] ?? '') === 'csv');
if (!$isCsv) header('Content-Type: application/json');

/**
 * Each source: [module label, base SQL up to WHERE 1=1, the four filter-column
 * expressions (date / actor / keyword-concat), and whether its table exists].
 * The SELECT column list is identical across sources so they UNION cleanly.
 */
function audit_sources(PDO $conn): array {
    $exists = function (string $t) use ($conn): bool {
        $s = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?");
        $s->execute([DB_NAME, $t]);
        return (int)$s->fetchColumn() > 0;
    };

    return [
        'tickets' => [
            'exists'   => $exists('ticket_audit'),
            'date_col' => 'ta.created_datetime',
            'actor_col'=> 'ta.analyst_id',
            'kw'       => "CONCAT_WS(' ', COALESCE(t.ticket_number,''), ta.field_name, COALESCE(ta.old_value,''), COALESCE(ta.new_value,''))",
            'sql'      => "SELECT ta.created_datetime AS log_dt, ta.analyst_id AS actor_id, an.full_name AS actor_name,
                                  'Tickets' AS module,
                                  CONCAT('Ticket ', COALESCE(t.ticket_number, CONCAT('#', ta.ticket_id))) AS entity,
                                  ta.field_name AS action,
                                  CONCAT_WS(' \xe2\x86\x92 ', NULLIF(ta.old_value,''), NULLIF(ta.new_value,'')) AS detail
                             FROM ticket_audit ta
                        LEFT JOIN analysts an ON an.id = ta.analyst_id
                        LEFT JOIN tickets t ON t.id = ta.ticket_id
                            WHERE 1=1",
        ],
        'changes' => [
            'exists'   => $exists('change_audit'),
            'date_col' => 'ca.created_datetime',
            'actor_col'=> 'ca.analyst_id',
            'kw'       => "CONCAT_WS(' ', ca.action_type, COALESCE(ca.field_name,''), COALESCE(ca.old_value,''), COALESCE(ca.new_value,''))",
            'sql'      => "SELECT ca.created_datetime AS log_dt, ca.analyst_id AS actor_id, an.full_name AS actor_name,
                                  'Changes' AS module,
                                  CONCAT('Change #', ca.change_id) AS entity,
                                  CONCAT_WS(': ', ca.action_type, NULLIF(ca.field_name,'')) AS action,
                                  CONCAT_WS(' \xe2\x86\x92 ', NULLIF(ca.old_value,''), NULLIF(ca.new_value,'')) AS detail
                             FROM change_audit ca
                        LEFT JOIN analysts an ON an.id = ca.analyst_id
                            WHERE 1=1",
        ],
        'problems' => [
            'exists'   => $exists('problem_audit'),
            'date_col' => 'pa.created_datetime',
            'actor_col'=> 'pa.analyst_id',
            'kw'       => "CONCAT_WS(' ', pa.action_type, COALESCE(pa.field_name,''), COALESCE(pa.old_value,''), COALESCE(pa.new_value,''))",
            'sql'      => "SELECT pa.created_datetime AS log_dt, pa.analyst_id AS actor_id, an.full_name AS actor_name,
                                  'Problems' AS module,
                                  CONCAT('Problem #', pa.problem_id) AS entity,
                                  CONCAT_WS(': ', pa.action_type, NULLIF(pa.field_name,'')) AS action,
                                  CONCAT_WS(' \xe2\x86\x92 ', NULLIF(pa.old_value,''), NULLIF(pa.new_value,'')) AS detail
                             FROM problem_audit pa
                        LEFT JOIN analysts an ON an.id = pa.analyst_id
                            WHERE 1=1",
        ],
        'assets' => [
            'exists'   => $exists('asset_history'),
            'date_col' => 'ah.created_datetime',
            'actor_col'=> 'ah.analyst_id',
            'kw'       => "CONCAT_WS(' ', COALESCE(a.hostname,''), ah.field_name, COALESCE(ah.old_value,''), COALESCE(ah.new_value,''))",
            'sql'      => "SELECT ah.created_datetime AS log_dt, ah.analyst_id AS actor_id, an.full_name AS actor_name,
                                  'Assets' AS module,
                                  CONCAT('Asset ', COALESCE(a.hostname, CONCAT('#', ah.asset_id))) AS entity,
                                  ah.field_name AS action,
                                  CONCAT_WS(' \xe2\x86\x92 ', NULLIF(ah.old_value,''), NULLIF(ah.new_value,'')) AS detail
                             FROM asset_history ah
                        LEFT JOIN analysts an ON an.id = ah.analyst_id
                        LEFT JOIN assets a ON a.id = ah.asset_id
                            WHERE 1=1",
        ],
        'system' => [
            'exists'   => $exists('system_logs'),
            'date_col' => 'sl.created_datetime',
            'actor_col'=> 'sl.analyst_id',
            'kw'       => "CONCAT_WS(' ', sl.log_type, LEFT(sl.details, 500))",
            'sql'      => "SELECT sl.created_datetime AS log_dt, sl.analyst_id AS actor_id, an.full_name AS actor_name,
                                  'System' AS module,
                                  sl.log_type AS entity,
                                  sl.log_type AS action,
                                  LEFT(sl.details, 500) AS detail
                             FROM system_logs sl
                        LEFT JOIN analysts an ON an.id = sl.analyst_id
                            WHERE 1=1",
        ],
    ];
}

try {
    $conn = connectToDatabase();

    $module    = $_GET['module']    ?? '';
    $actorId   = isset($_GET['actor_id']) && $_GET['actor_id'] !== '' ? (int)$_GET['actor_id'] : null;
    $dateFrom  = (isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'])) ? $_GET['date_from'] : null;
    $dateTo    = (isset($_GET['date_to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']))   ? $_GET['date_to']   : null;
    $keyword   = trim((string)($_GET['keyword'] ?? ''));

    $limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;
    $page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    $sources = audit_sources($conn);
    if ($module !== '' && !isset($sources[$module])) {
        throw new Exception('Invalid module');
    }

    $parts = [];
    $params = [];
    foreach ($sources as $key => $s) {
        if (!$s['exists']) continue;
        if ($module !== '' && $module !== $key) continue;

        $sql = $s['sql'];
        if ($dateFrom !== null) { $sql .= " AND {$s['date_col']} >= ?"; $params[] = $dateFrom . ' 00:00:00'; }
        if ($dateTo !== null)   { $sql .= " AND {$s['date_col']} < DATE_ADD(?, INTERVAL 1 DAY)"; $params[] = $dateTo . ' 00:00:00'; }
        if ($actorId !== null)  { $sql .= " AND {$s['actor_col']} = ?"; $params[] = $actorId; }
        if ($keyword !== '')    { $sql .= " AND {$s['kw']} LIKE ?"; $params[] = '%' . $keyword . '%'; }
        $parts[] = $sql;
    }

    if (!$parts) {
        // No sources at all (fresh/partial install).
        if ($isCsv) { audit_emit_csv([]); exit; }
        echo json_encode(['success' => true, 'rows' => [], 'page' => $page, 'has_more' => false]);
        exit;
    }

    $union = implode("\nUNION ALL\n", array_map(fn($p) => "($p)", $parts));

    if ($isCsv) {
        $sql = "SELECT * FROM ($union) x ORDER BY log_dt DESC LIMIT 10000";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        audit_emit_csv($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // Fetch one extra row to know whether there's a next page.
    $sql = "SELECT * FROM ($union) x ORDER BY log_dt DESC LIMIT " . ($limit + 1) . " OFFSET " . $offset;
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);

    $out = array_map(fn($r) => [
        'when'   => $r['log_dt'],
        'actor'  => $r['actor_name'],
        'module' => $r['module'],
        'entity' => $r['entity'],
        'action' => $r['action'],
        'detail' => $r['detail'],
    ], $rows);

    echo json_encode(['success' => true, 'rows' => $out, 'page' => $page, 'has_more' => $hasMore]);

} catch (Exception $e) {
    if ($isCsv) { http_response_code(500); echo 'Error: ' . $e->getMessage(); exit; }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/** Stream normalised rows as a CSV download. */
function audit_emit_csv(array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['When (UTC)', 'Actor', 'Module', 'Entity', 'Action', 'Detail']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['log_dt'], $r['actor_name'], $r['module'], $r['entity'], $r['action'], $r['detail']]);
    }
    fclose($out);
}
