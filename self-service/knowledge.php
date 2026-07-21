<?php
/**
 * Self-Service Portal — Knowledge Base (Phase 7a).
 * Lets signed-in portal users search and read PUBLISHED articles and rate them
 * ("was this helpful?"). Deep-linkable via ?id=N (used by New Ticket deflection).
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
    <title>Knowledge Base</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; background: #f5f5f5; }
        .portal-header { background: #0078d4; color: white; padding: 0 24px; height: 48px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.15); position: sticky; top: 0; z-index: 100; }
        .portal-brand { display: flex; align-items: center; gap: 12px; font-weight: 600; font-size: 15px; }
        .portal-brand img { height: 28px; filter: brightness(0) invert(1); }
        .portal-nav { display: flex; align-items: center; gap: 4px; }
        .portal-nav a { color: rgba(255,255,255,0.8); text-decoration: none; padding: 6px 14px; border-radius: 4px; font-size: 13px; font-weight: 500; transition: all 0.15s; }
        .portal-nav a:hover  { background: rgba(255,255,255,0.15); color: white; }
        .portal-nav a.active { background: rgba(255,255,255,0.2);  color: white; }

        .kb-page { max-width: 820px; margin: 0 auto; padding: 32px 24px 64px; }
        .kb-page h1 { font-size: 26px; font-weight: 600; color: #222; margin: 0 0 6px; }
        .kb-lede { font-size: 14px; color: #666; margin-bottom: 20px; }
        .kb-search { width: 100%; padding: 11px 14px; font-size: 15px; border: 1px solid #d1d5db; border-radius: 8px; box-sizing: border-box; }
        .kb-results { margin-top: 18px; display: flex; flex-direction: column; gap: 10px; }
        .kb-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px 18px; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: box-shadow .12s, transform .12s; }
        .kb-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.09); transform: translateY(-1px); }
        .kb-card h3 { margin: 0 0 5px; font-size: 15px; color: #0f4c81; }
        .kb-card p { margin: 0; font-size: 13px; color: #666; line-height: 1.5; }
        .kb-empty { color: #888; font-size: 14px; padding: 24px 0; text-align: center; }

        .kb-article { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 26px 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .kb-back { background: none; border: none; color: #0078d4; cursor: pointer; font-size: 13px; padding: 0; margin-bottom: 14px; }
        .kb-article h1 { font-size: 24px; margin: 0 0 16px; }
        .kb-body { font-size: 14.5px; line-height: 1.65; color: #333; }
        .kb-body img { max-width: 100%; height: auto; }
        .kb-rate { margin-top: 28px; padding-top: 18px; border-top: 1px solid #eee; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .kb-rate span { font-size: 14px; color: #444; font-weight: 600; }
        .kb-rate button { padding: 6px 16px; border: 1px solid #d1d5db; background: #fff; border-radius: 999px; cursor: pointer; font-size: 13px; }
        .kb-rate button.on { background: #0078d4; color: #fff; border-color: #0078d4; }
        .kb-rate .kb-thanks { color: #16a34a; font-weight: 500; }
    </style>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
</head>
<body>
    <div class="portal-header">
        <div class="portal-brand">
            <img src="../assets/images/CompanyLogo.png" alt="Logo">
            <span><?php echo htmlspecialchars(t('self-service.portal')); ?></span>
        </div>
        <nav class="portal-nav">
            <a href="index.php"><?php echo htmlspecialchars(t('self-service.nav.dashboard')); ?></a>
            <a href="catalog.php">Request</a>
            <a href="new-ticket.php"><?php echo htmlspecialchars(t('self-service.nav.new_ticket')); ?></a>
            <a href="knowledge.php" class="active">Knowledge</a>
            <a href="help.php"><?php echo htmlspecialchars(t('self-service.nav.help')); ?></a>
        </nav>
        <?php include 'includes/user-menu.php'; ?>
    </div>

    <div class="kb-page">
        <div id="kbSearchView">
            <h1>Knowledge Base</h1>
            <p class="kb-lede">Search our help articles — you might find an answer straight away.</p>
            <input type="text" class="kb-search" id="kbSearch" placeholder="Search for an answer…" autocomplete="off">
            <div class="kb-results" id="kbResults"></div>
        </div>
        <div id="kbArticleView" style="display:none;"></div>
    </div>

    <script>
        const KB_API = '../api/self-service/';
        let kbTimer = null;
        function kbEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]); }

        async function kbSearch(q) {
            const el = document.getElementById('kbResults');
            el.innerHTML = '';
            try {
                const res = await fetch(KB_API + 'kb_search.php?q=' + encodeURIComponent(q || ''));
                const data = await res.json();
                if (!data.success) { el.innerHTML = '<div class="kb-empty">Could not load articles.</div>'; return; }
                if (!data.articles.length) { el.innerHTML = '<div class="kb-empty">No articles found. Try different words, or raise a ticket.</div>'; return; }
                el.innerHTML = data.articles.map(a =>
                    `<div class="kb-card" onclick="kbOpen(${a.id})"><h3>${kbEsc(a.title)}</h3>${a.snippet ? `<p>${kbEsc(a.snippet)}…</p>` : ''}</div>`
                ).join('');
            } catch (e) { el.innerHTML = '<div class="kb-empty">Could not load articles.</div>'; }
        }

        async function kbOpen(id) {
            try {
                const res = await fetch(KB_API + 'kb_article.php?id=' + id);
                const data = await res.json();
                if (!data.success) { alert(data.error || 'Article not found'); return; }
                const a = data.article, r = data.rating || {};
                const mine = r.mine;
                document.getElementById('kbSearchView').style.display = 'none';
                const view = document.getElementById('kbArticleView');
                view.style.display = 'block';
                view.innerHTML = `
                    <div class="kb-article">
                        <button class="kb-back" onclick="kbBack()">← Back to search</button>
                        <h1>${kbEsc(a.title)}</h1>
                        <div class="kb-body">${a.body || ''}</div>
                        <div class="kb-rate" id="kbRate">
                            <span>Was this helpful?</span>
                            <button id="kbYes" class="${mine === 1 ? 'on' : ''}" onclick="kbRate(${a.id}, 1)">👍 Yes</button>
                            <button id="kbNo" class="${mine === 0 ? 'on' : ''}" onclick="kbRate(${a.id}, 0)">👎 No</button>
                            <span class="kb-thanks" id="kbThanks" style="display:${mine === null || mine === undefined ? 'none' : 'inline'};">Thanks for your feedback!</span>
                        </div>
                    </div>`;
                window.scrollTo(0, 0);
            } catch (e) { alert('Could not load the article.'); }
        }

        function kbBack() {
            document.getElementById('kbArticleView').style.display = 'none';
            document.getElementById('kbSearchView').style.display = 'block';
        }

        async function kbRate(id, helpful) {
            try {
                const res = await fetch(KB_API + 'kb_rate.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ article_id: id, helpful }) });
                const data = await res.json();
                if (!data.success) return;
                document.getElementById('kbYes').classList.toggle('on', helpful === 1);
                document.getElementById('kbNo').classList.toggle('on', helpful === 0);
                document.getElementById('kbThanks').style.display = 'inline';
            } catch (e) { /* silent */ }
        }

        document.getElementById('kbSearch').addEventListener('input', function () {
            clearTimeout(kbTimer);
            const q = this.value;
            kbTimer = setTimeout(() => kbSearch(q), 200);
        });

        // Deep-link ?id=N opens an article directly; otherwise show a browse list.
        (function () {
            const params = new URLSearchParams(location.search);
            const id = parseInt(params.get('id'), 10);
            if (id > 0) kbOpen(id); else kbSearch('');
        })();
    </script>
</body>
</html>
