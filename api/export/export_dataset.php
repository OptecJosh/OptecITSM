<?php
/**
 * API: stream a dataset export (module data export).
 *
 * GET ?dataset=<key>[&format=csv|json][&from=YYYY-MM-DD][&to=YYYY-MM-DD]
 *
 * Access mirrors get_datasets.php: the caller needs the Reporting module (this is
 * a reporting surface) AND the dataset's own module, plus is_admin for admin-only
 * datasets. Tenant-scoped datasets are filtered to the active company, so an
 * export can never contain a company the analyst can't already see.
 *
 * The response streams row by row (unbuffered where the driver allows it) so a
 * large table doesn't have to fit in memory, capped at data_export_max_rows().
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/data_export.php';

/** Fail as JSON — nothing has been streamed yet at that point. */
function export_fail(int $code, string $message): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if (!isset($_SESSION['analyst_id'])) export_fail(401, 'Not authenticated');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $datasets = data_export_datasets();
    $key = (string)($_GET['dataset'] ?? '');
    if (!isset($datasets[$key])) export_fail(400, 'Unknown dataset');
    $spec = $datasets[$key];

    if (!analystCanAccessModule($conn, $analystId, 'reporting')
        || !analystCanAccessModule($conn, $analystId, $spec['module'])) {
        export_fail(403, 'You do not have access to this data');
    }
    if (!empty($spec['admin_only']) && !analystIsAdmin($conn, $analystId)) {
        export_fail(403, 'This dataset is restricted to administrators');
    }
    if (!data_export_available($conn, $spec)) export_fail(404, 'This dataset is not available on this install');

    $from = data_export_valid_date($_GET['from'] ?? null);
    $to   = data_export_valid_date($_GET['to'] ?? null);
    $format = ($_GET['format'] ?? 'csv') === 'json' ? 'json' : 'csv';

    [$sql, $params, $headers] = data_export_plan($conn, $spec, $analystId, $from, $to);

    // Stream rather than buffer: a full ticket_audit export is far too big to hold.
    try { $conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false); } catch (Exception $e) { /* driver may refuse */ }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $filename = $key . '-export-' . date('Y-m-d') . '.' . $format;
    header('Content-Type: ' . ($format === 'json' ? 'application/json' : 'text/csv') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    if ($format === 'json') {
        echo "[\n";
        $first = true;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo ($first ? '' : ",\n") . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $first = false;
            if (connection_aborted()) break;
        }
        echo "\n]\n";
    } else {
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");           // BOM so Excel reads UTF-8 correctly
        fputcsv($out, $headers);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, array_map(fn($h) => $row[$h] ?? null, $headers));
            if (connection_aborted()) break;
        }
        fclose($out);
    }

} catch (Exception $e) {
    if (!headers_sent()) export_fail(500, $e->getMessage());
    error_log('export_dataset failed for ' . ($key ?? '?') . ': ' . $e->getMessage());
}
