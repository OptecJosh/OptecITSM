<?php
/**
 * API: On-demand database backup (Phase 10b). Admin only.
 *
 * Streams a mysqldump of the application database straight to the browser as a
 * timestamped .sql download. Deliberately NOT written to disk: a full dump
 * contains every secret in the database (password hashes, tokens), so persisting
 * it under the web root — or anywhere — would create an at-rest exposure and a
 * retention problem. Streaming avoids both; the operator saves it wherever their
 * backup policy dictates.
 *
 *   ?probe=1  → JSON { success, available, reason } — is mysdqldump usable here?
 *   (no param)→ streams the dump, or JSON error if unavailable (checked BEFORE
 *               any download headers are sent).
 *
 * The password is passed via the MYSQL_PWD environment variable, never on the
 * command line (which is world-visible in the process list).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';   // auth + admin

/** Split DB_SERVER into [host, port]; port null when unspecified. */
function backup_host_port(): array {
    $server = defined('DB_SERVER') ? DB_SERVER : 'localhost';
    if (strpos($server, ':') !== false) {
        [$h, $p] = explode(':', $server, 2);
        return [$h, (int)$p ?: null];
    }
    return [$server, null];
}

/** Locate a usable mysqldump. Returns [ok, pathOrNull, reason]. */
function backup_probe(): array {
    if (!function_exists('proc_open')) {
        return [false, null, 'proc_open is disabled on this server (PHP disable_functions).'];
    }
    // Try common invocations; the first that reports a version wins.
    foreach (['mysqldump', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump'] as $bin) {
        $descr = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open(escapeshellarg($bin) . ' --version', $descr, $pipes);
        if (!is_resource($p)) continue;
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        if ($code === 0 && stripos($out, 'mysqldump') !== false) {
            return [true, $bin, null];
        }
    }
    return [false, null, 'mysqldump is not installed in this container/host.'];
}

[$available, $bin, $reason] = backup_probe();

if (isset($_GET['probe'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'available' => $available, 'reason' => $reason]);
    exit;
}

if (!$available) {
    header('Content-Type: application/json');
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => $reason ?: 'Backup tool unavailable']);
    exit;
}

[$host, $port] = backup_host_port();

// UTC_TIMESTAMP-ish filename; PHP date() is server-local but only labels the file.
$stamp = date('Y-m-d_His');
$filename = 'freeitsm-backup-' . $stamp . '.sql';

$args = [$bin, '--single-transaction', '--skip-lock-tables', '--no-tablespaces',
         '--host=' . $host];
if ($port) $args[] = '--port=' . $port;
$args[] = '--user=' . DB_USERNAME;
$args[] = DB_NAME;
$cmd = implode(' ', array_map('escapeshellarg', $args));

$descr = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$env = $_ENV;
$env['MYSQL_PWD'] = DB_PASSWORD;

$proc = @proc_open($cmd, $descr, $pipes, null, $env);
if (!is_resource($proc)) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to start mysqldump']);
    exit;
}

// Headers only now that the process launched.
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');

stream_set_blocking($pipes[2], false);
while (!feof($pipes[1])) {
    $chunk = fread($pipes[1], 1 << 16);
    if ($chunk === false) break;
    echo $chunk;
    flush();
}
fclose($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$code = proc_close($proc);

if ($code !== 0) {
    // Headers are already sent; append a visible marker so a truncated/failed
    // dump can't masquerade as a good one.
    echo "\n-- BACKUP FAILED (exit {$code}): " . str_replace(["\r", "\n"], ' ', $err) . "\n";
    error_log('backup_database mysqldump failed: ' . $err);
}
