<?php
/**
 * API: export several datasets at once as a zip of CSVs (the BI feed).
 *
 * GET ?bundle=analytics|all [&from=YYYY-MM-DD][&to=YYYY-MM-DD]
 *     ?datasets=tickets,assets,…      explicit list instead of a named bundle
 *
 * Why this exists: a management report is built from a fact table plus its
 * dimensions, and downloading sixteen CSVs by hand — then re-picking the same
 * sixteen next month — is where reporting projects die. One click, one zip, and
 * a README inside it describing how the files relate.
 *
 * Access is per dataset via data_export_can(), so a bundle silently contains
 * only what the caller could already have exported one file at a time. A dataset
 * that fails its access or availability check is skipped and reported in the
 * manifest, never a hard error — an analyst without the KPI module still gets a
 * useful ticket export.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/data_export.php';

function bundle_fail(int $code, string $message): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if (!isset($_SESSION['analyst_id'])) bundle_fail(401, 'Not authenticated');

$tmpDir = null;
$zipPath = null;

/** Remove the working directory and the zip, whatever happened. */
function bundle_cleanup(?string $tmpDir, ?string $zipPath): void {
    if ($tmpDir && is_dir($tmpDir)) {
        foreach (glob($tmpDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($tmpDir);
    }
    if ($zipPath && is_file($zipPath)) @unlink($zipPath);
}

try {
    if (!class_exists('ZipArchive')) {
        bundle_fail(500, 'The PHP zip extension is not installed on this server, so bundles cannot be built. Export datasets individually instead.');
    }

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    if (!analystCanAccessModule($conn, $analystId, 'reporting')) {
        bundle_fail(403, 'You do not have access to reporting');
    }
    $isAdmin = analystIsAdmin($conn, $analystId);

    $all = data_export_datasets();
    $bundles = data_export_bundles();

    // Resolve which datasets were asked for.
    $requested = [];
    $bundleKey = trim((string)($_GET['bundle'] ?? ''));
    $explicit  = trim((string)($_GET['datasets'] ?? ''));

    if ($explicit !== '') {
        foreach (explode(',', $explicit) as $k) {
            $k = trim($k);
            if ($k !== '' && isset($all[$k])) $requested[] = $k;
        }
        if (!$requested) bundle_fail(400, 'None of the requested datasets exist');
        $bundleLabel = 'Selected datasets';
    } elseif ($bundleKey !== '') {
        if (!isset($bundles[$bundleKey])) bundle_fail(400, 'Unknown bundle');
        $requested = $bundles[$bundleKey]['datasets'] ?? array_keys($all);
        // A preset may name a dataset a later release renamed; drop it quietly.
        $requested = array_values(array_filter($requested, fn($k) => isset($all[$k])));
        $bundleLabel = $bundles[$bundleKey]['label'];
    } else {
        bundle_fail(400, 'Specify bundle= or datasets=');
    }

    $from = data_export_valid_date($_GET['from'] ?? null);
    $to   = data_export_valid_date($_GET['to'] ?? null);

    $tmpDir = sys_get_temp_dir() . '/freeitsm-bundle-' . bin2hex(random_bytes(6));
    if (!@mkdir($tmpDir, 0700) && !is_dir($tmpDir)) {
        bundle_fail(500, 'Could not create a working directory for the bundle');
    }

    // Build each CSV on disk rather than in memory: a full ticket history plus
    // its audit trail will not fit in a PHP memory limit.
    $manifest = [];
    $skipped  = [];

    foreach ($requested as $key) {
        $spec = $all[$key];
        if (!data_export_can($conn, $spec, $analystId, $isAdmin)) {
            $skipped[$key] = !empty($spec['admin_only']) && !$isAdmin
                ? 'skipped — administrators only'
                : 'skipped — no access to the ' . $spec['module'] . ' module, or not present on this install';
            continue;
        }

        try {
            [$sql, $params, $headers] = data_export_plan($conn, $spec, $analystId, $from, $to);
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);

            $file = $tmpDir . '/' . $key . '.csv';
            $fh = fopen($file, 'w');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, $headers);
            $rows = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($fh, array_map(fn($h) => $row[$h] ?? null, $headers));
                $rows++;
            }
            fclose($fh);
            $stmt->closeCursor();

            $manifest[$key] = [
                'file'    => $key . '.csv',
                'label'   => $spec['label'],
                'rows'    => $rows,
                'columns' => count($headers),
                'date_filtered' => !empty($spec['date']) && ($from || $to) ? $spec['date'] : '',
                'capped'  => $rows >= data_export_max_rows() ? 'YES — row cap reached, narrow the date range' : '',
            ];
        } catch (Exception $e) {
            // One broken dataset must not lose the other fifteen.
            $skipped[$key] = 'skipped — ' . $e->getMessage();
            error_log('export_bundle: dataset ' . $key . ' failed: ' . $e->getMessage());
        }
    }

    if (!$manifest) {
        bundle_cleanup($tmpDir, null);
        bundle_fail(403, 'None of the requested datasets are available to you');
    }

    // ---- manifest + readme ------------------------------------------------
    $manifestFile = $tmpDir . '/_manifest.csv';
    $fh = fopen($manifestFile, 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['dataset', 'file', 'label', 'rows', 'columns', 'date_filtered_on', 'row_cap_hit']);
    foreach ($manifest as $key => $m) {
        fputcsv($fh, [$key, $m['file'], $m['label'], $m['rows'], $m['columns'], $m['date_filtered'], $m['capped']]);
    }
    foreach ($skipped as $key => $why) {
        fputcsv($fh, [$key, '', $why, '', '', '', '']);
    }
    fclose($fh);

    $range = ($from || $to) ? (($from ?: 'start') . ' to ' . ($to ?: 'today')) : 'all dates';
    $generated = date('Y-m-d H:i');
    $readme = <<<TXT
FreeITSM data export bundle
===========================
Bundle    : {$bundleLabel}
Generated : {$generated}
Date range: {$range}

See _manifest.csv for the row and column count of every file, and for any
dataset that was skipped and why.

How the files relate
--------------------
tickets.csv is the fact table: one row per ticket. As well as the ticket's own
fields it carries the KPI markers already calculated, so you do not need to
re-derive them:

  ack_minutes                     created -> first acknowledgement (MTTA)
  resolution_minutes              created -> closed (MTTR)
  resolution_minutes_excl_hold    MTTR with on-hold waiting time removed
  owner_tier                      L1/L2/L3 of the ticket owner
  sla_response_state              ok | approaching | breached | met | na
  sla_resolution_state            as above
  sla_met                         1 met, 0 breached, blank where no SLA applies
                                  -> AVERAGE this column for SLA attainment %
  escalation_count                number of tier escalations
  minutes_to_escalate             acknowledgement -> first escalation
  hold_minutes / hold_event_count total on-hold time and how many holds
  qa_passed / qa_score            latest QA outcome, mean QA score
  csat_rating                     latest satisfaction rating
  logged_hours / labour_cost      effort, and its cost at each logger's rate
  reopen_count / owner_changes    rework and reassignment ("bounce")
  created_month, created_week,    ready-made groupings, so the report needs no
  created_weekday, created_hour   date parsing
  out_of_hours                    1 if raised outside Mon-Fri 08:00-18:00

Join the other files to tickets.csv on ticket_number (each child file carries
it, so you never have to join on an internal id):

  ticket_sla_snapshot.csv   1 row per ticket   SLA state, uncached detail
  ticket_escalations.csv    many per ticket    each handover, with tiers
  ticket_hold_events.csv    many per ticket    each hold, with reason
  ticket_qa_reviews.csv     many per ticket    each quality review
  ticket_time_entries.csv   many per ticket    each logged time entry
  ticket_csat.csv           many per ticket    each survey response

kpi_measurements.csv holds the monthly KPI values shown on the scorecard, and
kpi_definitions.csv holds each KPI's target, RAG thresholds and direction of
good — join them on the KPI name to colour a chart the same way the scorecard
does.

analysts.csv, customers.csv, assets.csv and the rest are dimension tables.

A note on agreement
-------------------
The derived ticket columns use the SAME definitions as the in-app KPI scorecard
(MTTR is created->closed; tier is the OWNER's tier; a reopen is a status moving
out of a closed status). A report built on this file will therefore match the
scorecard. If you redefine a metric in the BI tool, expect the two to diverge.

Blank vs zero
-------------
Blank means "not applicable or not recorded" and zero means a real zero. Keep
that distinction when loading: coercing blanks to 0 will drag every average
down. In particular sla_met is deliberately blank for tickets with no SLA.
TXT;
    file_put_contents($tmpDir . '/README.txt', $readme);

    // ---- zip it ----------------------------------------------------------
    $zipPath = $tmpDir . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        bundle_cleanup($tmpDir, null);
        bundle_fail(500, 'Could not create the zip archive');
    }
    foreach (glob($tmpDir . '/*') ?: [] as $f) {
        $zip->addFile($f, basename($f));
    }
    $zip->close();

    $name = 'freeitsm-' . ($bundleKey !== '' ? $bundleKey : 'export')
          . '-' . date('Y-m-d') . '.zip';

    // Audit before streaming: once the download starts the client can abort and
    // we would lose the record of what left the system.
    try {
        $totalRows = array_sum(array_column($manifest, 'rows'));
        $conn->prepare("INSERT INTO system_logs (log_type, analyst_id, details, created_datetime) VALUES ('data_export', ?, ?, UTC_TIMESTAMP())")
             ->execute([$analystId, 'Bundle export (' . ($bundleKey !== '' ? $bundleKey : 'custom') . '): '
                 . count($manifest) . ' datasets, ' . $totalRows . ' rows, ' . $range]);
    } catch (Exception $e) { /* non-fatal */ }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('X-Content-Type-Options: nosniff');
    readfile($zipPath);

    bundle_cleanup($tmpDir, $zipPath);

} catch (Exception $e) {
    bundle_cleanup($tmpDir ?? null, $zipPath ?? null);
    if (!headers_sent()) bundle_fail(500, $e->getMessage());
    error_log('export_bundle failed: ' . $e->getMessage());
}
