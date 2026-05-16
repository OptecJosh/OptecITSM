/**
 * Network Mapper — class icon library.
 *
 * Maps cmdb_icons.icon_key → inner SVG markup (paths/shapes). Each entry expects
 * a 24×24 viewBox and uses currentColor + stroke-width 1.8 for crisp rendering
 * at the palette tile (40px) and on-canvas node sizes (small=48 / medium=72 /
 * large=96). Style is intentionally Feather-flavoured to match the rest of the
 * UI's icon vocabulary (waffle menu, module headers, etc.).
 *
 * Keep this list in sync with the cmdb_icons table seed in
 * database/freeitsm.sql — adding an icon there but not here will render as the
 * fallback box. Adding an icon here but not there is harmless (just unused).
 */
(function () {
    'use strict';

    const ICONS = {
        server: '<rect x="3" y="4" width="18" height="6" rx="1.5"/><circle cx="7" cy="7" r="0.9" fill="currentColor"/><line x1="11" y1="7" x2="18" y2="7"/><rect x="3" y="14" width="18" height="6" rx="1.5"/><circle cx="7" cy="17" r="0.9" fill="currentColor"/><line x1="11" y1="17" x2="18" y2="17"/>',

        database: '<ellipse cx="12" cy="5.5" rx="8" ry="2.5"/><path d="M4 5.5v6c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5v-6"/><path d="M4 11.5v6c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5v-6"/>',

        application: '<rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><circle cx="6" cy="6.5" r="0.7" fill="currentColor"/><circle cx="8.5" cy="6.5" r="0.7" fill="currentColor"/><circle cx="11" cy="6.5" r="0.7" fill="currentColor"/>',

        service: '<circle cx="12" cy="12" r="3.5"/><path d="M12 2v3M12 19v3M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M2 12h3M19 12h3M4.93 19.07l2.12-2.12M16.95 7.05l2.12-2.12"/>',

        website: '<circle cx="12" cy="12" r="9"/><line x1="3" y1="12" x2="21" y2="12"/><path d="M12 3a14 14 0 0 1 4 9 14 14 0 0 1-4 9 14 14 0 0 1-4-9 14 14 0 0 1 4-9z"/>',

        api: '<polyline points="8 5 3 12 8 19"/><polyline points="16 5 21 12 16 19"/><line x1="14" y1="4" x2="10" y2="20"/>',

        vm: '<rect x="3" y="4" width="14" height="14" rx="1.5"/><path d="M7 20h14V6"/><line x1="6" y1="9" x2="14" y2="9"/><line x1="6" y1="13" x2="11" y2="13"/>',

        container: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z"/><line x1="4" y1="7.5" x2="12" y2="12"/><line x1="20" y1="7.5" x2="12" y2="12"/><line x1="12" y1="12" x2="12" y2="21"/>',

        cloud: '<path d="M17.5 19a4.5 4.5 0 1 0-1.3-8.8 6 6 0 0 0-11.6 2.3A4 4 0 0 0 5 19h12.5z"/>',

        network: '<circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="12" cy="18" r="2.5"/><line x1="7.5" y1="7.5" x2="11" y2="16"/><line x1="16.5" y1="7.5" x2="13" y2="16"/><line x1="8.5" y1="6" x2="15.5" y2="6"/>',

        firewall: '<rect x="3" y="4" width="18" height="16" rx="1"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="14" x2="21" y2="14"/><line x1="9" y1="4" x2="9" y2="9"/><line x1="15" y1="9" x2="15" y2="14"/><line x1="7" y1="14" x2="7" y2="20"/><line x1="13" y1="14" x2="13" y2="20"/><line x1="19" y1="14" x2="19" y2="20"/><line x1="12" y1="4" x2="12" y2="9"/>',

        router: '<rect x="3" y="11" width="18" height="8" rx="1.5"/><circle cx="7" cy="15" r="0.8" fill="currentColor"/><circle cx="10" cy="15" r="0.8" fill="currentColor"/><circle cx="13" cy="15" r="0.8" fill="currentColor"/><polyline points="6 7 9 4 12 7"/><polyline points="18 7 15 4 12 7"/><line x1="9" y1="4" x2="9" y2="11"/><line x1="15" y1="4" x2="15" y2="11"/>',

        switch: '<rect x="2" y="8" width="20" height="9" rx="1.5"/><line x1="6" y1="12" x2="6" y2="13"/><line x1="9" y1="12" x2="9" y2="13"/><line x1="12" y1="12" x2="12" y2="13"/><line x1="15" y1="12" x2="15" y2="13"/><line x1="18" y1="12" x2="18" y2="13"/><circle cx="20" cy="11" r="0.6" fill="currentColor"/>',

        storage: '<ellipse cx="12" cy="6" rx="8" ry="2.5"/><path d="M4 6v3c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5V6"/><path d="M4 11v3c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5v-3"/><path d="M4 16v2c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5v-2"/>',

        workstation: '<rect x="3" y="4" width="18" height="12" rx="1.5"/><line x1="8" y1="20" x2="16" y2="20"/><line x1="12" y1="16" x2="12" y2="20"/>',

        printer: '<polyline points="6 9 6 3 18 3 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="7"/>',

        person: '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',

        team: '<circle cx="9" cy="8" r="3.5"/><path d="M2 21v-1a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v1"/><circle cx="17.5" cy="9.5" r="2.5"/><path d="M22 18.5v-1a3.5 3.5 0 0 0-3.5-3.5h-1"/>',

        document: '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="14 3 14 9 20 9"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/>',

        box: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z"/><line x1="4" y1="7.5" x2="12" y2="12"/><line x1="20" y1="7.5" x2="12" y2="12"/><line x1="12" y1="12" x2="12" y2="21"/>'
    };

    const FALLBACK = ICONS.box;

    /**
     * Build a full <svg> element string for a given icon_key.
     *   key   — icon_key (e.g. 'server'); unknown keys fall back to box
     *   size  — pixel width/height (defaults to 24)
     *   extra — extra attributes string for the <svg> (e.g. 'class="nm-icon"')
     */
    function renderIcon(key, size, extra) {
        const inner = ICONS[key] || FALLBACK;
        const sz = size || 24;
        const attrs = extra || '';
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' + sz + '" height="' + sz +
               '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" ' +
               'stroke-linecap="round" stroke-linejoin="round" ' + attrs + '>' + inner + '</svg>';
    }

    window.NM_ICONS = ICONS;
    window.nmRenderIcon = renderIcon;
})();
