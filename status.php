<?php
/**
 * PUBLIC system status page (Phase 7b). No authentication — anyone with the URL
 * can view it, BUT it only shows data when an admin has enabled it (the
 * status_page_public setting; the API returns enabled:false otherwise).
 */
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Status</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; background: #f5f7fa; color: #1f2937; }
        .sp-header { background: #0f172a; color: #fff; padding: 22px 24px; text-align: center; }
        .sp-header img { height: 30px; filter: brightness(0) invert(1); margin-bottom: 10px; }
        .sp-header h1 { margin: 0; font-size: 22px; font-weight: 600; }
        .sp-wrap { max-width: 760px; margin: 0 auto; padding: 24px 20px 64px; }
        .sp-overall { border-radius: 10px; padding: 16px 20px; margin-bottom: 22px; font-size: 16px; font-weight: 600; }
        .sp-overall.ok { background: #dcfce7; color: #166534; }
        .sp-overall.issues { background: #fef3c7; color: #92400e; }
        .sp-section-title { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; font-weight: 700; margin: 22px 0 10px; }
        .sp-svc { display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 13px 16px; margin-bottom: 8px; }
        .sp-svc-name { font-weight: 500; }
        .sp-svc-desc { font-size: 12px; color: #9ca3af; margin-top: 2px; }
        .sp-badge { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 600; white-space: nowrap; }
        .sp-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .sp-badge.ok { color: #166534; } .sp-badge.ok .sp-dot { background: #16a34a; }
        .sp-badge.bad { color: #b45309; } .sp-badge.bad .sp-dot { background: #f59e0b; }
        .sp-incident { background: #fff; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; border-radius: 8px; padding: 14px 16px; margin-bottom: 10px; }
        .sp-incident h3 { margin: 0 0 4px; font-size: 15px; }
        .sp-incident .meta { font-size: 12px; color: #6b7280; margin-bottom: 8px; }
        .sp-incident .meta .pill { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 999px; padding: 1px 8px; font-weight: 600; }
        .sp-incident p { margin: 0; font-size: 13.5px; line-height: 1.55; color: #374151; }
        .sp-note { text-align: center; color: #6b7280; padding: 48px 0; font-size: 15px; }
        .sp-foot { text-align: center; color: #9ca3af; font-size: 12px; margin-top: 30px; }
    </style>
</head>
<body>
    <div class="sp-header">
        <img src="assets/images/CompanyLogo.png" alt="Logo" onerror="this.style.display='none'">
        <h1>System Status</h1>
    </div>
    <div class="sp-wrap" id="spWrap">
        <div class="sp-note" id="spNote">Loading…</div>
    </div>

    <script>
        function spEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]); }
        async function loadStatus() {
            const wrap = document.getElementById('spWrap');
            let data;
            try {
                const res = await fetch('api/service-status/get_public_status.php');
                data = await res.json();
            } catch (e) { wrap.innerHTML = '<div class="sp-note">Status is temporarily unavailable.</div>'; return; }
            if (!data.success || !data.enabled) {
                wrap.innerHTML = '<div class="sp-note">The status page is not currently available.</div>';
                return;
            }
            const services = data.services || [];
            const incidents = data.incidents || [];
            const allOk = services.length > 0 && services.every(s => s.operational) && incidents.length === 0;

            let html = '';
            html += '<div class="sp-overall ' + (allOk ? 'ok' : 'issues') + '">' +
                (allOk ? 'All systems operational' : 'Some systems are experiencing issues') + '</div>';

            if (incidents.length) {
                html += '<div class="sp-section-title">Active incidents</div>';
                html += incidents.map(i =>
                    '<div class="sp-incident"><h3>' + spEsc(i.title) + '</h3>' +
                    '<div class="meta"><span class="pill">' + spEsc(i.status) + '</span>' +
                    (i.services ? ' &middot; ' + spEsc(i.services) : '') + '</div>' +
                    (i.comment ? '<p>' + spEsc(i.comment) + '</p>' : '') + '</div>'
                ).join('');
            }

            html += '<div class="sp-section-title">Services</div>';
            if (!services.length) {
                html += '<div class="sp-note">No services are being monitored.</div>';
            } else {
                html += services.map(s => {
                    const cls = s.operational ? 'ok' : 'bad';
                    return '<div class="sp-svc"><div><div class="sp-svc-name">' + spEsc(s.name) + '</div>' +
                        (s.description ? '<div class="sp-svc-desc">' + spEsc(s.description) + '</div>' : '') + '</div>' +
                        '<span class="sp-badge ' + cls + '"><span class="sp-dot"></span>' + spEsc(s.status) + '</span></div>';
                }).join('');
            }
            html += '<div class="sp-foot">Updated ' + new Date().toLocaleString() + '</div>';
            wrap.innerHTML = html;
        }
        loadStatus();
        setInterval(loadStatus, 60000);
    </script>
</body>
</html>
