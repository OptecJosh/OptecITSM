<?php
/**
 * API: Software licence compliance / true-up (Phase 9a).
 *
 * For every app that carries at least one active licence, compares what the
 * organisation is ENTITLED to (sum of active licence quantities) against what is
 * actually INSTALLED (distinct hosts running the app in the inventory). The
 * delta flags over-deployment (installed > entitled) — the compliance risk — and
 * under-utilisation (installed < entitled) — money potentially wasted.
 *
 * Compute-only: no new schema. Entitlement lives in software_licences.quantity,
 * installs in software_inventory_detail. Apps with no licence row are ignored
 * (nothing to be compliant against).
 *
 * GET (no params). Returns {
 *   success,
 *   summary: { licensed_apps, over_deployed_apps, over_deployed_seats,
 *              under_utilised_apps },
 *   apps: [{ app_id, app_name, publisher, entitled, installed, delta, status }]
 * }  status ∈ over | ok | unused, sorted most-over-deployed first.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('software');

try {
    $conn = connectToDatabase();

    // Per-app entitled vs installed via independent subqueries (a JOIN would
    // fan out licence rows against install rows and double-count both sides).
    $sql = "SELECT a.id AS app_id, a.display_name AS app_name, a.publisher,
                   COALESCE((SELECT SUM(l.quantity) FROM software_licences l
                              WHERE l.app_id = a.id AND l.status = 'Active' AND l.quantity IS NOT NULL), 0) AS entitled,
                   (SELECT COUNT(DISTINCT d.host_id) FROM software_inventory_detail d
                     WHERE d.app_id = a.id) AS installed
              FROM software_inventory_apps a
             WHERE EXISTS (SELECT 1 FROM software_licences l WHERE l.app_id = a.id AND l.status = 'Active')";

    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $apps = [];
    $summary = ['licensed_apps' => 0, 'over_deployed_apps' => 0, 'over_deployed_seats' => 0, 'under_utilised_apps' => 0];

    foreach ($rows as $r) {
        $entitled  = (int)$r['entitled'];
        $installed = (int)$r['installed'];
        $delta     = $installed - $entitled;

        if ($installed > $entitled)      { $status = 'over';   $summary['over_deployed_apps']++;  $summary['over_deployed_seats'] += $delta; }
        elseif ($installed === 0)        { $status = 'unused'; $summary['under_utilised_apps']++; }
        elseif ($installed < $entitled)  { $status = 'ok';     $summary['under_utilised_apps']++; }
        else                             { $status = 'ok'; }

        $summary['licensed_apps']++;
        $apps[] = [
            'app_id'    => (int)$r['app_id'],
            'app_name'  => $r['app_name'],
            'publisher' => $r['publisher'],
            'entitled'  => $entitled,
            'installed' => $installed,
            'delta'     => $delta,
            'status'    => $status,
        ];
    }

    // Most over-deployed first (largest positive delta), then by name.
    usort($apps, function ($x, $y) {
        if ($y['delta'] !== $x['delta']) return $y['delta'] <=> $x['delta'];
        return strcasecmp((string)$x['app_name'], (string)$y['app_name']);
    });

    echo json_encode(['success' => true, 'summary' => $summary, 'apps' => $apps]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
