/**
 * Network Mapper — editor module (chunk B scope).
 *
 * Responsibilities at chunk B:
 *   - load the diagram + its CMDB class palette
 *   - render the palette as draggable tiles (drop-handling lives in chunk C)
 *   - autosave: dirty tracking, debounced save, Word-style status indicator,
 *     per-analyst toggle persisted in user_preferences. Mirrors the
 *     Process Mapper pattern key-for-key so behaviour stays consistent.
 *   - manual Save → save_diagram.php
 *   - Save-as-new-version → modal → create_version.php → navigate to the new id
 *   - read-only banner for historical versions (no children = leaf = editable)
 *
 * What chunk B intentionally does NOT do:
 *   - drag-to-canvas, CMDB object picker on drop, node rendering (chunk C)
 *   - connector drawing / relationships pull-in (chunk D)
 *   - zoom, PNG export, S/M/L sizing (phase 2)
 *
 * Convention: every exported entry point goes on window.NM so the inline
 * HTML can call NM.save(), NM.toggleAutosave() etc. without scope concerns.
 */
(function () {
    'use strict';

    const API = '../api/network-mapper/';
    const CMDB_API = '../api/cmdb/';
    const SYSTEM_API = '../api/system/';

    const AUTOSAVE_PREF_KEY = 'network_mapper_autosave';
    const AUTOSAVE_DEBOUNCE_MS = 2000;
    const MIN_SAVING_VISIBLE_MS = 400;

    // ---- state ----
    let diagramId = 0;
    let diagram = null;          // metadata; null while loading
    let classes = [];            // CMDB classes for the palette
    let nodes = [];              // current canvas nodes (empty in chunk B)
    let connectors = [];         // current canvas connectors (empty in chunk B)

    let dirty = false;
    let autosaveOn = false;
    let autosaveTimer = null;
    let saveInFlight = false;
    let lastSavingShownAt = 0;

    // ---- DOM refs (filled in init) ----
    let elTitle, elVersionPill, elMetaRow, elMetaAuthor, elMetaCreated, elMetaUpdated;
    let elStatus, elSaveBtn, elSaveVersionBtn, elAutosaveToggle, elAutosaveWrap;
    let elPaletteBody, elCanvas, elReadonlyBanner;

    // =========================================================
    //  Initialisation
    // =========================================================
    function init(id) {
        diagramId = id;

        elTitle           = document.getElementById('diagramTitle');
        elVersionPill     = document.getElementById('versionPill');
        elMetaRow         = document.getElementById('metaRow');
        elMetaAuthor      = document.getElementById('metaAuthor');
        elMetaCreated     = document.getElementById('metaCreated');
        elMetaUpdated     = document.getElementById('metaUpdated');
        elStatus          = document.getElementById('saveStatus');
        elSaveBtn         = document.getElementById('saveBtn');
        elSaveVersionBtn  = document.getElementById('saveVersionBtn');
        elAutosaveToggle  = document.getElementById('nmAutosaveToggle');
        elAutosaveWrap    = document.getElementById('autosaveWrap');
        elPaletteBody     = document.getElementById('paletteBody');
        elCanvas          = document.getElementById('canvas');
        elReadonlyBanner  = document.getElementById('readonlyBanner');

        // Load diagram + palette + autosave preference in parallel
        Promise.all([loadDiagram(), loadClasses(), loadAutosavePreference()]).catch(() => {});
    }

    // =========================================================
    //  Diagram + palette loading
    // =========================================================
    async function loadDiagram() {
        try {
            const resp = await fetch(API + 'get_diagram.php?id=' + diagramId);
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Failed to load');
            diagram = data.diagram;
            nodes = data.nodes || [];
            connectors = data.connectors || [];
            renderHeader();
            applyReadOnlyState();
            setStatus(autosaveOn ? 'saved' : 'off');
        } catch (e) {
            elTitle.textContent = 'Failed to load diagram';
            elStatus.textContent = e.message;
        }
    }

    async function loadClasses() {
        try {
            const resp = await fetch(CMDB_API + 'get_classes.php');
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Failed to load classes');
            classes = (data.classes || []).filter(c => c.is_active);
            renderPalette();
        } catch (e) {
            elPaletteBody.innerHTML = '<div class="nm-palette-empty">Failed to load classes: ' + escapeHtml(e.message) + '</div>';
        }
    }

    function renderHeader() {
        document.title = 'FreeITSM — ' + (diagram.title || 'Network Diagram');
        elTitle.textContent = diagram.title || '(untitled)';

        const label = diagram.version_label || 'v?';
        if (diagram.is_current) {
            elVersionPill.className = 'nm-version-pill';
            elVersionPill.textContent = label + ' (current)';
        } else {
            elVersionPill.className = 'nm-version-pill readonly';
            elVersionPill.textContent = label + ' (read-only)';
        }
        elVersionPill.style.display = '';

        elMetaRow.style.display = '';
        elMetaAuthor.textContent  = diagram.author_name || 'Unknown';
        elMetaCreated.textContent = formatDate(diagram.created_datetime);
        elMetaUpdated.textContent = formatDate(diagram.updated_datetime);
    }

    function renderPalette() {
        if (!classes.length) {
            elPaletteBody.innerHTML = '<div class="nm-palette-empty">No CMDB classes defined yet. <a href="../cmdb/settings/">Create one</a> to start dragging objects onto the diagram.</div>';
            return;
        }
        const html = classes.map(c => {
            const icon = window.nmRenderIcon ? window.nmRenderIcon(c.icon_key || 'box', 28) : '';
            const objCount = c.object_count || 0;
            return `
                <div class="nm-palette-tile" draggable="true" data-class-id="${c.id}" data-icon-key="${escapeAttr(c.icon_key || 'box')}" title="Drag onto the canvas (coming in chunk C)">
                    <div class="nm-palette-tile-icon">${icon}</div>
                    <div class="nm-palette-tile-name">${escapeHtml(c.name)}</div>
                    <div class="nm-palette-tile-count">${objCount} object${objCount === 1 ? '' : 's'}</div>
                </div>`;
        }).join('');
        elPaletteBody.innerHTML = html;

        // Drag-start: stash the class id for the drop handler to pick up in chunk C
        elPaletteBody.querySelectorAll('.nm-palette-tile').forEach(tile => {
            tile.addEventListener('dragstart', onTileDragStart);
        });
    }

    function onTileDragStart(e) {
        const classId = e.currentTarget.dataset.classId;
        e.dataTransfer.setData('text/plain', JSON.stringify({ kind: 'nm-class', class_id: parseInt(classId, 10) }));
        e.dataTransfer.effectAllowed = 'copy';
    }

    // =========================================================
    //  Read-only mode (historical version)
    // =========================================================
    function applyReadOnlyState() {
        if (diagram.is_current) {
            elReadonlyBanner.style.display = 'none';
            return;
        }
        elReadonlyBanner.style.display = '';
        elSaveBtn.disabled = true;
        elSaveBtn.title = 'This is a historical version — read-only';
        elAutosaveWrap.style.display = 'none';
        // Save-as-new-version on a non-leaf is refused by the backend (create_version
        // only forks from the leaf), so disable it here too.
        elSaveVersionBtn.disabled = true;
        elSaveVersionBtn.title = 'Only the current version can be forked into a new version';
    }

    // =========================================================
    //  Autosave: dirty / debounce / status / preference
    // =========================================================

    // Single entry-point for "something changed". Will be called once chunk C
    // wires up node moves; for chunk B it's just here for the title/version
    // editing surfaces that come in chunk B's metadata editor (not yet — keeping
    // chunk B's surface to autosave plumbing only).
    function markDirty() {
        if (!diagram || !diagram.is_current) return;
        dirty = true;
        setStatus('unsaved');
        if (autosaveOn) scheduleAutosave();
    }

    function scheduleAutosave() {
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(() => {
            if (!autosaveOn || !dirty || saveInFlight) return;
            // Drag-state deferral lands with drag in chunk C; for chunk B there are
            // no active drags, so we can fire straight away.
            save(true);
        }, AUTOSAVE_DEBOUNCE_MS);
    }

    // Status states: 'idle' | 'unsaved' | 'saving' | 'saved' | 'failed' | 'off'
    function setStatus(state) {
        if (!elStatus) return;
        const map = {
            idle:    { html: '', cls: '' },
            unsaved: { html: autosaveOn ? 'Unsaved' : 'Unsaved changes', cls: 'nm-status-unsaved' },
            saving:  { html: '<span class="nm-status-spinner"></span> Saving…', cls: 'nm-status-saving' },
            saved:   { html: '<span class="nm-status-tick">✓</span> Saved', cls: 'nm-status-saved' },
            failed:  { html: '<span class="nm-status-warn">⚠</span> Save failed — <a href="#" id="nmRetrySave">retry</a>', cls: 'nm-status-failed' },
            off:     { html: 'Autosave off', cls: 'nm-status-off' }
        };
        const s = map[state] || map.idle;
        elStatus.className = 'nm-status ' + s.cls;
        elStatus.innerHTML = s.html;
        if (state === 'failed') {
            const retry = document.getElementById('nmRetrySave');
            if (retry) retry.onclick = (e) => { e.preventDefault(); save(autosaveOn); };
        }
    }

    async function loadAutosavePreference() {
        try {
            const r = await fetch(SYSTEM_API + 'get_user_preference.php?key=' + encodeURIComponent(AUTOSAVE_PREF_KEY), { credentials: 'same-origin' });
            const d = await r.json();
            applyAutosaveState(d.success && d.value === '1', false);
        } catch (e) {
            applyAutosaveState(false, false);
        }
    }

    function applyAutosaveState(on, persist) {
        autosaveOn = !!on;
        if (elAutosaveToggle) elAutosaveToggle.checked = autosaveOn;
        if (!diagram) {
            setStatus('idle');
        } else if (!diagram.is_current) {
            // Read-only versions don't show a save status — banner does the work
            setStatus('idle');
        } else if (dirty) {
            setStatus('unsaved');
            if (autosaveOn) scheduleAutosave();
        } else {
            setStatus(autosaveOn ? 'saved' : 'off');
        }
        if (persist) {
            fetch(SYSTEM_API + 'set_user_preference.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key: AUTOSAVE_PREF_KEY, value: autosaveOn ? '1' : '0' })
            }).catch(() => {});
        }
    }

    function toggleAutosave(on) {
        clearTimeout(autosaveTimer);
        applyAutosaveState(on, true);
        if (autosaveOn && dirty && diagram && diagram.is_current) scheduleAutosave();
    }

    // =========================================================
    //  Save
    // =========================================================
    async function save(isAutoSave) {
        if (!diagram || !diagram.is_current || saveInFlight) return;
        saveInFlight = true;
        setStatus('saving');
        lastSavingShownAt = Date.now();

        try {
            const resp = await fetch(API + 'save_diagram.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: diagramId,
                    nodes: nodes.map(n => ({
                        id: n.id,
                        cmdb_object_id: n.cmdb_object_id,
                        x: n.x, y: n.y,
                        size: n.size || 'medium',
                        icon_override: n.icon_override || null
                    })),
                    connectors: connectors.map(c => ({
                        id: c.id,
                        from_node_id: c.from_node_id,
                        to_node_id: c.to_node_id,
                        cmdb_relationship_id: c.cmdb_relationship_id || null,
                        label: c.label || null,
                        line_style: c.line_style || 'solid'
                    }))
                })
            });
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Save failed');

            dirty = false;
            // Hold "Saving…" on screen for MIN_SAVING_VISIBLE_MS so it doesn't flash
            const elapsed = Date.now() - lastSavingShownAt;
            const wait = Math.max(0, MIN_SAVING_VISIBLE_MS - elapsed);
            setTimeout(() => {
                setStatus('saved');
                if (!isAutoSave && window.showToast) showToast('Saved', 'success');
            }, wait);
        } catch (e) {
            setStatus('failed');
            if (!isAutoSave && window.showToast) showToast('Save failed: ' + e.message, 'error');
        } finally {
            saveInFlight = false;
        }
    }

    // =========================================================
    //  Save as new version
    // =========================================================
    async function openNewVersionModal() {
        if (!diagram || !diagram.is_current) {
            if (window.showToast) showToast('Only the current version can be forked', 'error');
            return;
        }
        // create_version.php clones from the *persisted* state, so any in-memory
        // edits would be silently dropped. Save first so the user gets what
        // they see — they don't need to think about persistence semantics.
        if (dirty) {
            if (window.showToast) showToast('Saving pending changes first…', 'info');
            await save(false);
            if (dirty) return; // save failed; bail and let the user retry
        }
        // Pre-fill with the current diagram's metadata so the user only needs to
        // tweak the version label most of the time
        document.getElementById('nvTitle').value = diagram.title || '';
        document.getElementById('nvDescription').value = diagram.description || '';
        document.getElementById('nvVersionLabel').value = suggestNextVersionLabel(diagram.version_label);
        document.getElementById('newVersionModal').classList.add('active');
        setTimeout(() => document.getElementById('nvVersionLabel').focus(), 50);
    }

    function closeNewVersionModal() {
        document.getElementById('newVersionModal').classList.remove('active');
    }

    function suggestNextVersionLabel(current) {
        if (!current) return 'v2';
        // Try to bump a trailing integer ("v3" -> "v4", "Draft 2" -> "Draft 3")
        const m = String(current).match(/^(.*?)(\d+)\s*$/);
        if (m) return m[1] + (parseInt(m[2], 10) + 1);
        return current + ' (new)';
    }

    async function createNewVersion() {
        const title = document.getElementById('nvTitle').value.trim();
        const description = document.getElementById('nvDescription').value.trim();
        const versionLabel = document.getElementById('nvVersionLabel').value.trim();
        if (!title) {
            if (window.showToast) showToast('Title is required', 'error');
            return;
        }
        const btn = document.getElementById('nvCreateBtn');
        btn.disabled = true;
        try {
            const resp = await fetch(API + 'create_version.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    parent_diagram_id: diagramId,
                    title, description, version_label: versionLabel
                })
            });
            const data = await resp.json();
            if (!data.success) throw new Error(data.error || 'Failed to create version');
            // Navigate to the new (leaf, editable) version
            window.location.href = 'diagram.php?id=' + data.id;
        } catch (e) {
            if (window.showToast) showToast('Failed: ' + e.message, 'error');
            btn.disabled = false;
        }
    }

    // =========================================================
    //  Helpers
    // =========================================================
    function formatDate(s) {
        if (!s) return '—';
        try { return new Date(s.replace(' ', 'T') + 'Z').toLocaleString(); }
        catch (e) { return s; }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function escapeAttr(s) { return escapeHtml(s).replace(/'/g, "\\'"); }

    // =========================================================
    //  Public surface
    // =========================================================
    window.NM = {
        init,
        save: () => save(false),
        toggleAutosave,
        openNewVersionModal,
        closeNewVersionModal,
        createNewVersion,
        markDirty
    };
})();
