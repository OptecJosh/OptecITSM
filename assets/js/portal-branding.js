/*
 * Phase 7e: apply per-company self-service portal branding.
 *
 * Fetches the signed-in user's branding (resolved by their email domain) and,
 * if the company set any, recolours the portal header, swaps the portal name,
 * and fills the dashboard welcome message (#portalWelcome, if present). Fails
 * silent — the portal keeps its built-in look when no branding applies.
 */
(function () {
    fetch('../api/self-service/get_branding.php')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success || !d.branding) return;
            var b = d.branding;

            if (b.brand_color && /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(b.brand_color)) {
                var header = document.querySelector('.portal-header');
                if (header) header.style.background = b.brand_color;
            }

            if (b.portal_name) {
                document.querySelectorAll('.portal-brand span').forEach(function (s) {
                    s.textContent = b.portal_name;
                });
            }

            if (b.portal_welcome) {
                var w = document.getElementById('portalWelcome');
                if (w) { w.textContent = b.portal_welcome; w.style.display = ''; }
            }
        })
        .catch(function () { /* keep default branding */ });
})();
