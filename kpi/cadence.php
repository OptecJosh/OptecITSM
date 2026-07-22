<?php
/**
 * KPI - Review cadence & ownership (Section 5 of the NOC KPI framework).
 * Reference reading; a metric with no meeting attached to it is decoration.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
I18n::initFromSession();
requireModuleAccess('kpi');

$current_page = 'cadence';
$path_prefix = '../';
$translationNamespaces = ['common'];

$rows = [
    ['Weekly', 'Tuning slot: alert-to-incident, noisiest rules, false-positive load. Rule changes made in-session under L2/L3 sign-off.', 'L2 + L3'],
    ['Monthly', 'Team review of real incidents and calls; tier scorecards reviewed as a team; QA samples scored; agent-level views used in 1:1s.', 'Whole team / team leads'],
    ['Quarterly', 'Gate review: targets confirmed or re-set against the quarter\'s baseline; SOC-CMM re-run; combined view goes to directors.', 'Head of Managed Services'],
    ['Annually', 'Full target reset, certification ladder review, benchmark refresh (M-Trends and peer data; reconfirm before board use).', 'Head of Managed Services'],
];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KPIs - Review cadence</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        body { --accent: var(--kpi-accent, #4338ca); }
        .k-wrap { flex: 1; padding: 20px 24px; overflow: auto; background: var(--app-bg,#f5f7fa); }
        .k-wrap h2 { margin: 0 0 4px; font-size: 20px; color: var(--text,#222); }
        .k-wrap p.sub { margin: 0 0 16px; color: var(--text-dim,#6b7280); font-size: 13px; max-width: 74ch; }
        .k-card { background: var(--surface,#fff); border: 1px solid var(--border,#e5e7eb); border-radius: 12px; box-shadow: 0 1px 3px var(--shadow,rgba(0,0,0,.05)); overflow: hidden; max-width: 900px; }
        table.k { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        table.k th, table.k td { text-align: left; padding: 12px 16px; border-bottom: 1px solid var(--border,#eee); color: var(--text,#222); vertical-align: top; }
        table.k th { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-dim,#6b7280); background: var(--surface-2,#eceff3); }
        table.k td.cad { font-weight: 700; color: var(--kpi-accent,#4338ca); white-space: nowrap; }
        table.k td.who { white-space: nowrap; color: var(--text-dim,#4b5563); }
        .k-foot { margin-top: 14px; font-size: 12.5px; color: var(--text-dim,#6b7280); max-width: 74ch; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <div class="main-container">
        <div class="k-wrap">
            <h2>Review cadence &amp; ownership</h2>
            <p class="sub">Every KPI in the scorecards is read in exactly one of these sessions. A metric with no meeting attached to it is decoration.</p>
            <div class="k-card">
                <table class="k">
                    <thead><tr><th>Cadence</th><th>What happens</th><th>Who</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="cad"><?php echo htmlspecialchars($r[0]); ?></td>
                            <td><?php echo htmlspecialchars($r[1]); ?></td>
                            <td class="who"><?php echo htmlspecialchars($r[2]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="k-foot">Source: NOC Team KPIs, Metrics &amp; Targets (Optec Managed Services). The monthly and quarterly sessions read directly off these scorecards rather than a parallel slide exercise.</p>
        </div>
    </div>
</body>
</html>
