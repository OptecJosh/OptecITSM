/**
 * Phase 16a — the icon helper for JavaScript-rendered UI.
 *
 * Deliberately holds NO path data. The registry lives in includes/icons.php and is
 * injected into the page as `window.__ICONS` by iconsBootstrapScript(), so there is
 * exactly one definition of every icon and the PHP and JS versions cannot drift.
 *
 * If __ICONS is missing (a page that forgot the bootstrap call), icon() returns an
 * empty string rather than throwing: a missing glyph is a visual bug, not a reason
 * for the whole reading pane to fail to render.
 *
 *   icon('trash')                        -> '<svg …>'
 *   icon('star', { fill: true })         -> filled variant
 *   icon('x', { class: 'unlink-x' })     -> extra classes
 *   icon('alert-triangle', { title: 'Warning' })  -> labelled for screen readers
 *
 * Omit `title` when the icon sits next to its own text label, which is the common
 * case — otherwise a screen reader reads the label twice.
 */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    window.icon = function (name, opts) {
        var reg = window.__ICONS || {};
        var body = reg[name];
        if (!body) return '';

        opts = opts || {};
        var cls = ('ficon ficon-' + name + ' ' + (opts.class || '')).trim();
        var size = opts.size ? ' width="' + parseInt(opts.size, 10) + '" height="' + parseInt(opts.size, 10) + '"' : '';
        var fill = opts.fill ? 'currentColor' : 'none';
        var sw = (opts.strokeWidth !== undefined && opts.strokeWidth !== null) ? opts.strokeWidth : 2;

        // Labelled -> role="img" plus a <title>. Unlabelled -> hidden from
        // assistive tech, which is right when the icon sits beside its own text.
        var a11yAttrs = opts.title ? ' role="img"' : ' aria-hidden="true" focusable="false"';
        var titleEl = opts.title ? '<title>' + esc(opts.title) + '</title>' : '';

        return '<svg class="' + esc(cls) + '"' + size
            + ' viewBox="0 0 24 24" fill="' + fill + '" stroke="currentColor"'
            + ' stroke-width="' + sw + '" stroke-linecap="round" stroke-linejoin="round"'
            + a11yAttrs + '>' + titleEl + body + '</svg>';
    };

    /** True when the bootstrap ran — handy in a console when an icon is missing. */
    window.iconsReady = function () {
        return !!(window.__ICONS && Object.keys(window.__ICONS).length);
    };
}());
