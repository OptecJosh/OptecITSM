<?php
/**
 * Self-Service Portal — Service Catalog (Phase 7c). Signed-in users browse
 * request items (grouped by category) and pick one to raise a pre-configured
 * request (via new-ticket.php?catalog=ID, which applies the item's routing).
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
I18n::initFromSession();
require_once 'includes/auth.php';

$translationNamespaces = ['common', 'self-service'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Catalog</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; background: #f5f5f5; }
        .portal-header { background: #0078d4; color: white; padding: 0 24px; height: 48px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.15); position: sticky; top: 0; z-index: 100; }
        .portal-brand { display: flex; align-items: center; gap: 12px; font-weight: 600; font-size: 15px; }
        .portal-brand img { height: 28px; filter: brightness(0) invert(1); }
        .portal-nav { display: flex; align-items: center; gap: 4px; }
        .portal-nav a { color: rgba(255,255,255,0.8); text-decoration: none; padding: 6px 14px; border-radius: 4px; font-size: 13px; font-weight: 500; }
        .portal-nav a:hover  { background: rgba(255,255,255,0.15); color: white; }
        .portal-nav a.active { background: rgba(255,255,255,0.2);  color: white; }

        .cat-page { max-width: 900px; margin: 0 auto; padding: 32px 24px 64px; }
        .cat-page h1 { font-size: 26px; font-weight: 600; color: #222; margin: 0 0 6px; }
        .cat-lede { font-size: 14px; color: #666; margin-bottom: 22px; }
        .cat-group-title { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; font-weight: 700; margin: 22px 0 10px; }
        .cat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
        .cat-card { display: block; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px 18px; text-decoration: none; color: inherit; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: box-shadow .12s, transform .12s; }
        .cat-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.09); transform: translateY(-2px); border-color: #93c5fd; }
        .cat-card .icon { font-size: 22px; margin-bottom: 8px; }
        .cat-card h3 { margin: 0 0 5px; font-size: 15px; color: #0f4c81; }
        .cat-card p { margin: 0; font-size: 13px; color: #666; line-height: 1.5; }
        .cat-note { text-align: center; color: #888; padding: 48px 0; font-size: 15px; }
    </style>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <script src="../assets/js/portal-branding.js?v=1"></script>
</head>
<body>
    <div class="portal-header">
        <div class="portal-brand">
            <img src="../assets/images/CompanyLogo.png" alt="Logo">
            <span><?php echo htmlspecialchars(t('self-service.portal')); ?></span>
        </div>
        <nav class="portal-nav">
            <a href="index.php"><?php echo htmlspecialchars(t('self-service.nav.dashboard')); ?></a>
            <a href="catalog.php" class="active">Request</a>
            <a href="new-ticket.php"><?php echo htmlspecialchars(t('self-service.nav.new_ticket')); ?></a>
            <a href="knowledge.php">Knowledge</a>
            <a href="help.php"><?php echo htmlspecialchars(t('self-service.nav.help')); ?></a>
        </nav>
        <?php include 'includes/user-menu.php'; ?>
    </div>

    <div class="cat-page">
        <h1>Request something</h1>
        <p class="cat-lede">Pick a request below and we'll route it to the right team.</p>
        <div id="catContent"><div class="cat-note">Loading…</div></div>
    </div>

    <script>
        function catEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]); }
        (async function () {
            const el = document.getElementById('catContent');
            let data;
            try { data = await (await fetch('../api/self-service/get_catalog.php')).json(); }
            catch (e) { el.innerHTML = '<div class="cat-note">Could not load the catalog.</div>'; return; }
            if (!data.success) { el.innerHTML = '<div class="cat-note">Could not load the catalog.</div>'; return; }
            const items = data.items || [];
            if (!items.length) { el.innerHTML = '<div class="cat-note">No request items are available yet. You can still <a href="new-ticket.php">raise a ticket</a>.</div>'; return; }
            const groups = {};
            items.forEach(i => { (groups[i.category] = groups[i.category] || []).push(i); });
            let html = '';
            Object.keys(groups).sort().forEach(cat => {
                html += '<div class="cat-group-title">' + catEsc(cat) + '</div><div class="cat-grid">';
                html += groups[cat].map(i =>
                    '<a class="cat-card" href="new-ticket.php?catalog=' + i.id + '">' +
                    (i.icon ? '<div class="icon">' + catEsc(i.icon) + '</div>' : '') +
                    '<h3>' + catEsc(i.name) + '</h3>' +
                    (i.description ? '<p>' + catEsc(i.description) + '</p>' : '') + '</a>'
                ).join('');
                html += '</div>';
            });
            el.innerHTML = html;
        })();
    </script>
</body>
</html>
