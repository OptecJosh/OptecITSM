<?php
/**
 * Phase 16a — the icon set.
 *
 * freeitsm used emoji as its icon vocabulary. Emoji are a poor fit for UI chrome
 * for three reasons that all showed up in practice:
 *   1. They render from whatever emoji font the OS ships, so the same button is a
 *      different size, weight and colour on Windows, macOS and Android — nothing
 *      lines up and nothing matches the surrounding text.
 *   2. They cannot inherit colour. A ✓ stays green-on-green in dark mode and a 🗑
 *      cannot be muted on a disabled control.
 *   3. Several carry cultural or tonal baggage that a helpdesk does not want (🤖
 *      for an AI action, 😡 on a survey).
 *
 * These are stroked SVG paths on a 24×24 grid, drawn with `currentColor`, so an
 * icon is the colour and weight of the text around it and dark mode needs no
 * special case.
 *
 * ── Single source of truth ────────────────────────────────────────────────────
 * Roughly half this platform's UI is built in JavaScript, so the icons are needed
 * in both languages. Rather than keep two registries that drift, the registry
 * lives HERE and `iconsBootstrapScript()` emits it into the page for
 * assets/js/icons.js to read. There is no build step in this project, so a
 * generated file was not an option, and a separate fetch would have made every
 * icon render async.
 *
 * ── Usage ─────────────────────────────────────────────────────────────────────
 *   PHP:  echo icon('trash');
 *         echo icon('star', ['fill' => true, 'class' => 'is-on']);
 *         echo icon('alert-triangle', ['title' => 'Warning']);   // labelled
 *   JS:   icon('trash')                                          // returns markup
 *
 * An icon with no `title` is decorative and gets aria-hidden — a screen reader
 * should not read "trash" next to a button already labelled Delete. Pass `title`
 * only when the icon is the control's ONLY label.
 */

/**
 * name => inner SVG markup, on a 24×24 viewBox.
 *
 * Keep these stroke-only where possible. `fill` variants are opt-in per call
 * (see icon()'s $opts), which is how star vs star-outline is one entry.
 */
function iconRegistry(): array {
    static $reg = null;
    if ($reg !== null) return $reg;

    return $reg = [
        // ---- status / feedback ------------------------------------------------
        'check'          => '<polyline points="20 6 9 17 4 12"/>',
        'x'              => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'alert-triangle' => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>'
                          . '<line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'info'           => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'lightbulb'      => '<path d="M9 18h6"/><path d="M10 22h4"/>'
                          . '<path d="M12 2a7 7 0 0 0-4 12.7V18h8v-3.3A7 7 0 0 0 12 2z"/>',
        'lock'           => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'shield'         => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',

        // ---- objects / navigation --------------------------------------------
        'inbox'          => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/>'
                          . '<path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'user'           => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'users'          => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>'
                          . '<path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'bookmark'       => '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>',
        'folder'         => '<path d="M4 20a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4l2 3h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2z"/>',
        // The expand/collapse affordance on a tree row. Rotated 90° by CSS when the
        // row is open, so one icon covers both states.
        'chevron-right'  => '<polyline points="9 18 15 12 9 6"/>',
        'star'           => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'calendar'       => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/>'
                          . '<line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'clipboard'      => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>'
                          . '<rect x="8" y="2" width="8" height="4" rx="1"/>',
        'link'           => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>'
                          . '<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'globe'          => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>'
                          . '<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'building'       => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 6h2M13 6h2M9 10h2M13 10h2M9 14h2M13 14h2"/>'
                          . '<path d="M10 22v-4h4v4"/>',

        // ---- actions ---------------------------------------------------------
        'pencil'         => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'trash'          => '<polyline points="3 6 5 6 21 6"/>'
                          . '<path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>'
                          . '<line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>'
                          . '<path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'shuffle'        => '<polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/>'
                          . '<polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/>'
                          . '<line x1="4" y1="4" x2="9" y2="9"/>',
        'repeat'         => '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/>'
                          . '<polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
        'arrow-right'    => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        'reply'          => '<polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>',
        'more-horizontal' => '<circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/><circle cx="5" cy="12" r="1.5"/>',
        'send'           => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'plus'           => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'settings'       => '<circle cx="12" cy="12" r="3"/>'
                          . '<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6 1.65 1.65 0 0 0 10 3.09V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'sparkles'       => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3z"/>'
                          . '<path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9L19 15z"/>',
        'menu'           => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'clock'          => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'monitor'        => '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',

        // ---- media / attachments --------------------------------------------
        'paperclip'      => '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
        'file'           => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        'file-text'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>'
                          . '<line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/>',
        'file-spreadsheet' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>'
                          . '<line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/><line x1="12" y1="12" x2="12" y2="18"/>',
        'presentation'   => '<line x1="2" y1="3" x2="22" y2="3"/>'
                          . '<path d="M21 3v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V3"/><polyline points="7 21 12 16 17 21"/>',
        'image'          => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
        'package'        => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>'
                          . '<polyline points="3.3 7 12 12 20.7 7"/><line x1="12" y1="22" x2="12" y2="12"/>',
        'music'          => '<circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/><path d="M9 18V5l12-2v13"/>',
        'film'           => '<rect x="2" y="3" width="20" height="18" rx="2"/>'
                          . '<line x1="7" y1="3" x2="7" y2="21"/><line x1="17" y1="3" x2="17" y2="21"/>'
                          . '<line x1="2" y1="9" x2="22" y2="9"/><line x1="2" y1="15" x2="22" y2="15"/>',
        'video'          => '<path d="m22 8-6 4 6 4V8z"/><rect x="2" y="6" width="14" height="12" rx="2"/>',

        // ---- CSAT faces ------------------------------------------------------
        // Kept as a five-point FACE scale rather than swapped for abstract shapes:
        // the faces are the survey's meaning, not decoration. As SVG they finally
        // render identically everywhere and can be greyed out on hover states,
        // which the emoji versions could not.
        'face-1'         => '<circle cx="12" cy="12" r="10"/><line x1="9" y1="16" x2="15" y2="16"/>'
                          . '<line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>'
                          . '<path d="M7.5 7.5 10 9"/><path d="M16.5 7.5 14 9"/>',
        'face-2'         => '<circle cx="12" cy="12" r="10"/><path d="M8.5 16s1.3-1.8 3.5-1.8 3.5 1.8 3.5 1.8"/>'
                          . '<line x1="9" y1="10" x2="9.01" y2="10"/><line x1="15" y1="10" x2="15.01" y2="10"/>',
        'face-3'         => '<circle cx="12" cy="12" r="10"/><line x1="8.5" y1="15" x2="15.5" y2="15"/>'
                          . '<line x1="9" y1="10" x2="9.01" y2="10"/><line x1="15" y1="10" x2="15.01" y2="10"/>',
        'face-4'         => '<circle cx="12" cy="12" r="10"/><path d="M8.5 14s1.3 1.8 3.5 1.8 3.5-1.8 3.5-1.8"/>'
                          . '<line x1="9" y1="10" x2="9.01" y2="10"/><line x1="15" y1="10" x2="15.01" y2="10"/>',
        'face-5'         => '<circle cx="12" cy="12" r="10"/><path d="M7.5 13.5s1.7 3 4.5 3 4.5-3 4.5-3"/>'
                          . '<line x1="9" y1="9.5" x2="9.01" y2="9.5"/><line x1="15" y1="9.5" x2="15.01" y2="9.5"/>'
                          . '<path d="M7 8.2 9.8 7.2"/><path d="M17 8.2 14.2 7.2"/>',
    ];
}

/**
 * Render an icon as inline SVG.
 *
 * $opts:
 *   class        extra classes, appended to `ficon ficon-<name>`
 *   size         px value; omit to inherit font-size (the usual case)
 *   title        accessible label. Given => role="img" + <title>. Omitted =>
 *                aria-hidden, because most icons sit beside their own text label.
 *   fill         true to fill with currentColor instead of stroking (star "on")
 *   stroke_width override the 2 default (1.5 reads better at large sizes)
 *
 * Returns '' for an unknown name rather than throwing — a missing icon should not
 * take a page down, and the empty gap is obvious in review.
 */
function icon(string $name, array $opts = []): string {
    $reg = iconRegistry();
    if (!isset($reg[$name])) return '';

    $classes = trim('ficon ficon-' . $name . ' ' . (string)($opts['class'] ?? ''));
    $sizeAttr = '';
    if (!empty($opts['size'])) {
        $s = (int)$opts['size'];
        $sizeAttr = ' width="' . $s . '" height="' . $s . '"';
    }
    $fill   = !empty($opts['fill']) ? 'currentColor' : 'none';
    $stroke = isset($opts['stroke_width']) ? (string)(float)$opts['stroke_width'] : '2';

    // A labelled icon is exposed as an image with a <title>; an unlabelled one is
    // hidden from assistive tech entirely.
    $title = isset($opts['title']) ? (string)$opts['title'] : '';
    if ($title !== '') {
        $a11yAttrs = ' role="img"';
        $titleEl   = '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    } else {
        $a11yAttrs = ' aria-hidden="true" focusable="false"';
        $titleEl   = '';
    }

    return '<svg class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '"' . $sizeAttr
         . ' viewBox="0 0 24 24" fill="' . $fill . '" stroke="currentColor"'
         . ' stroke-width="' . $stroke . '" stroke-linecap="round" stroke-linejoin="round"'
         . $a11yAttrs . '>' . $titleEl . $reg[$name] . '</svg>';
}

/**
 * The base rules every icon needs.
 *
 * Emitted inline rather than added to assets/css/theme.css on purpose: 162 files
 * pin `theme.css?v=22`, so a rule added there would need 162 cache-bust bumps in
 * one commit and would silently do nothing in any file that was missed. Inline
 * styles ship with the icons that need them and cannot be stale.
 *
 * `1em` sizing plus `vertical-align: -0.125em` is what makes an icon sit on the
 * text baseline at whatever size its container happens to be — the thing emoji
 * never did consistently.
 */
function iconsBaseStyles(): string {
    return '<style>'
        . '.ficon{width:1em;height:1em;display:inline-block;vertical-align:-0.125em;'
        . 'flex:none;stroke:currentColor;fill:none}'
        // A bare icon button should be a comfortable target and take the text
        // colour of whatever it sits in.
        . '.ficon-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;'
        . 'background:none;border:0;padding:4px;cursor:pointer;color:inherit;line-height:1;border-radius:4px}'
        . '.ficon-btn:hover{background:var(--surface-hover,#f0f0f0)}'
        . '.ficon-btn:disabled{opacity:.5;cursor:not-allowed}'
        // Semantic colours, so a tick is green and a warning amber without each
        // caller inventing its own hex.
        . '.ficon-ok{color:var(--success-accent,#16a34a)}'
        . '.ficon-warn{color:var(--warning-text,#92400e)}'
        . '.ficon-danger{color:var(--danger-accent,#d13438)}'
        . '.ficon-muted{color:var(--text-dim,#9ca3af)}'
        . '</style>';
}

/**
 * Emit the registry (and base styles) for assets/js/icons.js.
 *
 * Call this in the <head> of any page whose JavaScript renders icons, BEFORE the
 * script that uses them. Around 4 KB uncompressed, and it saves a request plus
 * the async flash a fetched icon set would cause.
 *
 * Safe to call on a page that also uses the PHP icon() helper — the styles are
 * idempotent and one page only ever emits this once.
 */
function iconsBootstrapScript(): string {
    return iconsBaseStyles()
        . '<script>window.__ICONS=' . json_encode(iconRegistry(), JSON_UNESCAPED_SLASHES) . ';</script>';
}
