<?php
/**
 * Wipe test data — CLI.
 *
 * The command-line twin of System > Reset data, over the same registry
 * (includes/data_reset.php), so the two cannot disagree about what counts as a
 * record and what counts as configuration.
 *
 * Usage (from the repo root, inside the app container):
 *
 *   php scripts/wipe-test-data.php                        # list the groups + row counts
 *   php scripts/wipe-test-data.php --all                  # dry run over every group
 *   php scripts/wipe-test-data.php --groups=tickets,assets
 *   php scripts/wipe-test-data.php --all --confirm        # actually delete
 *
 * DRY RUN IS THE DEFAULT. Nothing is deleted without --confirm, and --confirm
 * without --all or --groups is refused rather than assumed to mean everything.
 *
 * In Docker:
 *   docker compose exec app php scripts/wipe-test-data.php --all
 *   docker compose exec app php scripts/wipe-test-data.php --all --confirm
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/data_reset.php';

// ---- arguments -------------------------------------------------------------
$args = array_slice($argv, 1);
$confirm = in_array('--confirm', $args, true);
$all = in_array('--all', $args, true);
$groupIds = [];
foreach ($args as $arg) {
    if (strpos($arg, '--groups=') === 0) {
        $groupIds = array_values(array_filter(array_map('trim', explode(',', substr($arg, 9)))));
    }
}

$registry = data_reset_groups();
if ($all) $groupIds = array_keys($registry);

$unknown = array_diff($groupIds, array_keys($registry));
if ($unknown) {
    fwrite(STDERR, "Unknown group(s): " . implode(', ', $unknown) . "\n");
    fwrite(STDERR, "Valid groups: " . implode(', ', array_keys($registry)) . "\n");
    exit(1);
}

$conn = connectToDatabase();

/** Right-pad for the fixed-width listing. */
$pad = fn($s, $n) => str_pad(mb_substr((string)$s, 0, $n), $n);

// ---- no selection: show what there is -------------------------------------
if (!$groupIds) {
    $plan = data_reset_plan($conn, array_keys($registry));
    echo "Deletable data groups (nothing has been touched):\n\n";
    echo '  ' . $pad('GROUP', 16) . $pad('ROWS', 10) . "WHAT IT IS\n";
    echo '  ' . str_repeat('-', 100) . "\n";
    foreach ($plan['groups'] as $g) {
        echo '  ' . $pad($g['id'], 16) . $pad(number_format($g['rows']), 10) . $g['label'] . "\n";
    }
    echo "\n  Total: " . number_format($plan['total']) . " row(s)\n\n";
    echo "Next: --groups=a,b for a dry run, or --all. Add --confirm to delete.\n";
    echo "Configuration (analysts, companies, permissions, mailboxes, SLA policies,\n";
    echo "statuses, CMDB classes, forms, workflows, KPI definitions) is never touched.\n";
    exit(0);
}

// ---- dry run / execute ----------------------------------------------------
$plan = data_reset_plan($conn, $groupIds);

echo ($confirm ? "DELETING" : "DRY RUN") . " — " . count($plan['groups']) . " group(s):\n\n";
foreach ($plan['groups'] as $g) {
    echo '  ' . $pad($g['label'], 46) . str_pad(number_format($g['rows']), 12, ' ', STR_PAD_LEFT) . " rows\n";
    foreach ($g['tables'] as $t) {
        if ($t['rows'] === 0) continue;
        $name = $t['table'] . (!empty($t['where']) ? ' [' . $t['where'] . ']' : '');
        echo '      ' . $pad($name, 42) . str_pad(number_format($t['rows']), 12, ' ', STR_PAD_LEFT) . "\n";
    }
}
echo "\n  " . $pad('TOTAL', 46) . str_pad(number_format($plan['total']), 12, ' ', STR_PAD_LEFT) . " rows\n";
if ($plan['missing']) {
    echo "\n  Not on this install, skipped: " . implode(', ', $plan['missing']) . "\n";
}

if (!$confirm) {
    echo "\nDry run — nothing was deleted. Re-run with --confirm to delete.\n";
    echo "Take a backup first: docker compose exec db mysqldump -ufreeitsm -pfreeitsm freeitsm > backup.sql\n";
    exit(0);
}

if ($plan['total'] === 0) {
    echo "\nNothing to delete.\n";
    exit(0);
}

echo "\nDeleting…\n";
try {
    $result = data_reset_execute($conn, $plan['tables']);
} catch (Exception $e) {
    fwrite(STDERR, "\nFAILED — rolled back, nothing was deleted: " . $e->getMessage() . "\n");
    exit(1);
}

data_reset_log($conn, null, array_column($plan['groups'], 'id'), $result['total']);
echo "Deleted " . number_format($result['total']) . " row(s) across " . count($plan['tables']) . " table(s).\n";
echo "Logged to system_logs as a data_reset entry.\n";
