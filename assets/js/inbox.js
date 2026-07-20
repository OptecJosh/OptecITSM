/**
 * Inbox JavaScript - Service Desk Ticketing System
 */

// API base path - can be overridden by page before loading this script
// Default is 'api/' for root-level pages; module pages should set window.API_BASE = '../api/'
const API_BASE = window.API_BASE || 'api/';

let emails = [];
let selectedEmailId = null;
let composeMode = 'new';
let folderGrouping = 'department'; // 'department' or 'analyst' — persisted via user_preferences

/**
 * Defensive HTML cleaner for email bodies.
 *
 * Three jobs:
 *
 * 1. Balance unclosed tags. Emails frequently arrive with an open `<div>`,
 *    half a `<table>`, an unclosed `<font>`. When that HTML hits innerHTML
 *    raw, the parser balances tags at the parent element's boundary —
 *    runaway tags from an email would swallow every sibling that follows
 *    (CMDB, time-entries, notes panels nested *inside* the email body).
 *
 * 2. Strip `<style>`, `<script>`, `<link>`, `<base>`, `<meta>`. Even with
 *    balanced markup, a `<style>` block inside the email can apply
 *    page-wide selectors (`div { ... }`, `* { position: absolute; ... }`)
 *    that bleed into our chrome and make our containers look broken. The
 *    grey-box overlap reported in MFG-151-13903 was a case of an Outlook
 *    "Did you find this email helpful?" footer block whose stylesheet was
 *    repositioning content. Scripts don't execute via innerHTML in any
 *    browser, but stripping them removes them from the source of truth too.
 *
 * 3. DOMParser does the parsing in a separate document context so the
 *    stripped tags never touch the live DOM. We then serialise the cleaned
 *    body back via innerHTML.
 */
function safeEmailHtml(html) {
    if (!html) return '';
    try {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        if (!doc.body) return '';
        // Remove any tags that can leak style / positioning into the parent
        // document or fire side effects on insertion.
        const dangerous = doc.querySelectorAll('style, script, link, base, meta');
        dangerous.forEach(el => el.remove());

        // Inbound inline images (cid:) are saved server-side and their <img src>
        // rewritten to a get_attachment.php URL. That rewrite emitted a ROOT-ABSOLUTE
        // path ('/api/tickets/get_attachment.php?...') that ignores the app's
        // deployment sub-path — so on any install not served from the web root the
        // images 404. Normalise every get_attachment.php image URL to the same
        // relative API_BASE the rest of the app uses, so they resolve wherever the
        // app is mounted. Idempotent — already-relative URLs pass straight through.
        doc.querySelectorAll('img[src*="get_attachment.php"]').forEach(img => {
            const raw = img.getAttribute('src') || '';
            const qs = raw.indexOf('?');
            img.setAttribute('src', API_BASE + 'get_attachment.php' + (qs >= 0 ? raw.slice(qs) : ''));
        });

        return doc.body.innerHTML;
    } catch (e) {
        return '';
    }
}

/*
 * Email bodies are arbitrary third-party HTML. Injecting them into the page as
 * normal DOM means the app's own stylesheet cascades INTO them — most visibly
 * the global `* { box-sizing: border-box }` reset, but also link/table/list
 * rules — distorting layouts the sender authored for a bare browser. Rather
 * than keep patching individual leaks, we render each body inside a Shadow DOM:
 * outer-page selectors (including `*`) do not match elements inside a shadow
 * tree, so NO app CSS reaches the email. Inherited properties (font, colour)
 * still cross the boundary from the host, which is what we want — plain-text
 * replies keep the app's readable font while designed emails override inline.
 *
 * Flow: emailBodyHost() emits an empty host <div> carrying a token; the body
 * HTML is stashed by token. After the container's innerHTML is set, the caller
 * calls hydrateEmailBodies(root) to attach a shadow root to each host and inject
 * the (already sanitised) HTML. Hydration runs synchronously right after render,
 * so there's no flash and no stranded map entries.
 */
let _emailBodySeq = 0;
const _emailBodyPending = new Map();

function emailBodyHost(rawHtml, cls) {
    const token = 'eb' + (++_emailBodySeq);
    _emailBodyPending.set(token, safeEmailHtml(rawHtml));
    return `<div class="${cls}" data-email-body="${token}"></div>`;
}

function hydrateEmailBodies(root) {
    if (!root || !root.querySelectorAll) return;
    root.querySelectorAll('[data-email-body]').forEach(host => {
        const token = host.getAttribute('data-email-body');
        host.removeAttribute('data-email-body');
        if (!_emailBodyPending.has(token)) return;
        const bodyHtml = _emailBodyPending.get(token);
        _emailBodyPending.delete(token);
        try {
            const shadow = host.attachShadow({ mode: 'open' });
            // Belt-and-braces content-box (the app's `*` reset can't reach in here,
            // but keep it explicit); everything else the email brings itself.
            shadow.innerHTML = '<style>*{box-sizing:content-box}</style>' + bodyHtml;
        } catch (e) {
            // Shadow DOM unsupported (very old browser): fall back to inline render
            // — already sanitised, just without the CSS isolation.
            host.innerHTML = bodyHtml;
        }
    });
}

let departments = [];
let ticketTypes = [];
let ticketOrigins = [];
let ticketCategories = [];
let ticketSubcategoriesByCategory = {};   // category_id -> active subcategories (fetched lazily, cached)
let ticketSubcategoriesById = {};         // flat id -> subcategory, populated as categories are fetched
let ticketStatuses = [];
// Multi-tenancy: companies this analyst can move tickets into. Empty / length<=1 on a
// single-company install, so the "Company" picker + wrong-company warning stay hidden.
let moveCompanies = [];
let isMultiCompany = false;
let ticketPriorities = [];   // loaded once at init from get_ticket_priorities.php
let analysts = [];
let currentEmail = null;
let currentRecordings = [];
let folderCounts = {};
let adHocFilters = {};   // Phase 5: ad-hoc ticket filters (same shape as saved queues)
let ticketQueues = [];   // Phase 5: saved queues visible to this analyst (own + shared)
let activeQueueId = null;    // currently-selected queue (sidebar highlight)
let editingQueueId = null;   // queue being edited in the filter panel (null = new/none)
let queueCanManageShared = false; // admin — may create/edit shared queues
// Messaging channels (WhatsApp etc.): set when a ticket's thread loads, so the
// reading pane composes over the channel instead of email. 'email' = normal ticket.
let currentTicketChannel = 'email';
let currentChannelWindowOpen = false;
let currentChannelProvider = '';
let channelTemplates = [];
// Auto-refresh: channel tickets (WhatsApp etc.) poll for new inbound messages every
// 15s while open. lastComposerWindowOpen lets us avoid re-rendering (and wiping) the
// composer on a refresh unless the 24h-window state actually changed.
let channelRefreshTimer = null;
let lastComposerWindowOpen = null;
let currentFilter = { type: 'all' };
let expandedFolders = {};
let currentNotes = [];
let emailEditor = null;
let emailAttachments = [];
let ticketAttachments = []; // Attachments linked to current ticket

// Helper function to log audit entries
async function logAudit(ticketId, fieldName, oldValue, newValue) {
    try {
        await fetch(API_BASE + 'log_ticket_audit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: ticketId,
                field_name: fieldName,
                old_value: oldValue,
                new_value: newValue
            })
        });
    } catch (error) {
        console.error('Error logging audit:', error);
    }
}

// Helper to get display name for IDs
function getDisplayName(type, id) {
    if (!id) return null;
    if (type === 'department') {
        const dept = departments.find(d => d.id == id);
        return dept ? dept.name : id;
    } else if (type === 'ticket_type') {
        const tt = ticketTypes.find(t => t.id == id);
        return tt ? tt.name : id;
    } else if (type === 'origin') {
        const o = ticketOrigins.find(x => x.id == id);
        return o ? o.name : id;
    } else if (type === 'category') {
        const c = ticketCategories.find(x => x.id == id);
        return c ? c.name : id;
    } else if (type === 'priority') {
        const p = ticketPriorities.find(x => x.id == id);
        return p ? p.name : id;
    } else if (type === 'subcategory') {
        const s = ticketSubcategoriesById[id];
        return s ? s.name : id;
    } else if (type === 'owner') {
        const a = analysts.find(x => x.id == id);
        return a ? a.full_name : id;
    }
    return id;
}

// Resolve API base for shared endpoints (api/system/...) — works whether the page is at
// the repo root or inside a module folder.
function sharedApiBase() {
    return API_BASE.replace(/[^/]+\/?$/, '');
}

async function loadFolderGroupingPreference() {
    try {
        const res = await fetch(sharedApiBase() + 'system/get_user_preference.php?key=tickets_folder_grouping');
        const data = await res.json();
        if (data && data.success && (data.value === 'analyst' || data.value === 'department')) {
            folderGrouping = data.value;
        }
    } catch (e) { /* fall back to default */ }
    // Sync the toggle UI to whatever we ended up with
    document.querySelectorAll('.folder-group-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.group === folderGrouping);
    });
}

async function setFolderGrouping(mode) {
    if (mode !== 'department' && mode !== 'analyst') return;
    if (mode === folderGrouping) return;
    folderGrouping = mode;

    // Reset selection back to "All Tickets" so we don't leave a stale dept/analyst filter active
    currentFilter = { type: 'all' };
    document.getElementById('emailListTitle').textContent = t('tickets.list.all_tickets');

    // Update the toggle UI
    document.querySelectorAll('.folder-group-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.group === folderGrouping);
    });

    renderFolders();
    loadEmails();

    // Persist (fire-and-forget)
    fetch(sharedApiBase() + 'system/set_user_preference.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: 'tickets_folder_grouping', value: folderGrouping })
    }).catch(() => {});
}

// Load data on page load
document.addEventListener('DOMContentLoaded', function() {
    loadDepartments();
    loadTicketTypes();
    loadTicketOrigins();
    loadTicketCategories();
    loadTicketStatuses();
    loadTicketPriorities();
    loadAnalysts();
    loadMoveCompanies();
    loadFolderGroupingPreference().then(loadFolderCounts);
    loadQueues();
    loadAllTicketTags();
    initTinyMCE();
    initAttachmentHandlers();

    // Load all tickets by default
    loadEmails();

    // Check for ticket_id in URL and auto-load that ticket
    const urlParams = new URLSearchParams(window.location.search);
    const ticketId = urlParams.get('ticket_id');
    if (ticketId) {
        // Small delay to ensure page is ready, then load the ticket
        setTimeout(() => loadTicketById(ticketId), 500);
    }
});

// Initialize attachment drag/drop and file input handlers
function initAttachmentHandlers() {
    const dropzone = document.getElementById('attachmentDropzone');
    const fileInput = document.getElementById('attachmentInput');

    if (!dropzone || !fileInput) return;

    // File input change handler
    fileInput.addEventListener('change', function(e) {
        handleFiles(e.target.files);
        fileInput.value = ''; // Reset so same file can be selected again
    });

    // Drag and drop handlers
    dropzone.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.add('dragover');
    });

    dropzone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('dragover');
    });

    dropzone.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    });

    // Click on dropzone to open file browser
    dropzone.addEventListener('click', function(e) {
        if (e.target.tagName !== 'A') {
            fileInput.click();
        }
    });
}

// Handle selected files
function handleFiles(files) {
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        // Check if file already added
        if (!emailAttachments.some(a => a.name === file.name && a.size === file.size)) {
            emailAttachments.push(file);
        }
    }
    renderAttachments();
}

// Render attachment list
function renderAttachments() {
    const list = document.getElementById('attachmentList');
    if (!list) return;

    if (emailAttachments.length === 0) {
        list.innerHTML = '';
        return;
    }

    list.innerHTML = emailAttachments.map((file, index) => `
        <div class="attachment-item">
            <div class="attachment-info">
                <span class="attachment-icon">${getFileIcon(file.name)}</span>
                <span class="attachment-name">${escapeHtml(file.name)}</span>
                <span class="attachment-size">(${formatFileSize(file.size)})</span>
            </div>
            <button class="attachment-remove" onclick="removeAttachment(${index})" title="Remove">&times;</button>
        </div>
    `).join('');
}

// Remove attachment by index
function removeAttachment(index) {
    emailAttachments.splice(index, 1);
    renderAttachments();
}

// Get file icon based on extension
function getFileIcon(filename) {
    const ext = filename.split('.').pop().toLowerCase();
    const icons = {
        'pdf': '📄',
        'doc': '📝', 'docx': '📝',
        'xls': '📊', 'xlsx': '📊',
        'ppt': '📽️', 'pptx': '📽️',
        'jpg': '🖼️', 'jpeg': '🖼️', 'png': '🖼️', 'gif': '🖼️', 'bmp': '🖼️',
        'zip': '📦', 'rar': '📦', '7z': '📦',
        'txt': '📃',
        'html': '🌐', 'htm': '🌐',
        'mp3': '🎵', 'wav': '🎵',
        'mp4': '🎬', 'avi': '🎬', 'mov': '🎬'
    };
    return icons[ext] || '📎';
}

// Format file size
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

// Initialize TinyMCE editor
function initTinyMCE() {
    // Match the editor chrome + content area to the active palette. TinyMCE ships
    // its own skins (the editor renders in an iframe), so we use the bundled
    // oxide-dark UI skin + dark content CSS rather than CSS overrides. Switching
    // palette reloads the page, so this runs fresh with the right data-theme.
    // The palette declares its own light/dark mode (Theme::THEMES in
    // includes/theme.php), surfaced as data-theme-mode on <html>. TinyMCE ships
    // only a light + a dark skin, so we pick by mode — any new palette (e.g.
    // "Miami Techno") works with no change here, just its registry mode.
    const isDark = (document.documentElement.getAttribute('data-theme-mode') || 'light') === 'dark';

    tinymce.init({
        selector: '#emailBody',
        license_key: 'gpl',
        height: 350,
        menubar: false,
        skin: isDark ? 'oxide-dark' : 'oxide',
        content_css: isDark ? 'dark' : 'default',
        plugins: [
            'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
            'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
            'insertdatetime', 'media', 'table', 'help', 'wordcount'
        ],
        toolbar: 'undo redo | blocks | ' +
            'bold italic forecolor backcolor | alignleft aligncenter ' +
            'alignright alignjustify | bullist numlist outdent indent | ' +
            'link | removeformat | help',
        // 14px on desktop; 16px on touch devices (pointer: coarse) so iOS Safari
        // doesn't auto-zoom when you tap into the reply/forward editor on a phone
        // — the same <16px focus-zoom that broke the note sheet. Mouse users
        // (pointer: fine) are unchanged.
        content_style: 'body { font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; } @media (pointer: coarse) { body { font-size: 16px; } }',
        extended_valid_elements: 'div[style|data-reply-marker]',
        setup: function(editor) {
            emailEditor = editor;
        }
    });
}

// Load departments (filtered by team membership)
async function loadDepartments() {
    try {
        // Use get_my_departments.php which filters based on team membership
        const response = await fetch(API_BASE + 'get_my_departments.php');
        const data = await response.json();

        if (data.success) {
            // Already filtered by API based on team membership
            departments = data.departments;
        }
    } catch (error) {
        console.error('Error loading departments:', error);
    }
}

// Load ticket types
async function loadTicketTypes() {
    try {
        const response = await fetch(API_BASE + 'get_ticket_types.php');
        const data = await response.json();

        if (data.success) {
            ticketTypes = data.ticket_types.filter(t => t.is_active);
        }
    } catch (error) {
        console.error('Error loading ticket types:', error);
    }
}

// Load ticket origins
async function loadTicketOrigins() {
    try {
        const response = await fetch(API_BASE + 'get_ticket_origins.php');
        const data = await response.json();

        if (data.success) {
            ticketOrigins = data.origins.filter(o => o.is_active);
        }
    } catch (error) {
        console.error('Error loading ticket origins:', error);
    }
}

// Load ticket categories
async function loadTicketCategories() {
    try {
        const response = await fetch(API_BASE + 'get_ticket_categories.php');
        const data = await response.json();

        if (data.success) {
            ticketCategories = data.ticket_categories.filter(c => c.is_active);
        }
    } catch (error) {
        console.error('Error loading ticket categories:', error);
    }
}

// Load the subcategories for one category (cached per category_id). Only
// active subcategories are offered in pickers.
async function loadTicketSubcategories(categoryId) {
    if (!categoryId) return [];
    if (ticketSubcategoriesByCategory[categoryId]) {
        return ticketSubcategoriesByCategory[categoryId];
    }
    try {
        const response = await fetch(API_BASE + 'get_ticket_subcategories.php?category_id=' + encodeURIComponent(categoryId));
        const data = await response.json();
        if (data.success) {
            const active = data.ticket_subcategories.filter(s => s.is_active);
            ticketSubcategoriesByCategory[categoryId] = active;
            active.forEach(s => { ticketSubcategoriesById[s.id] = s; });
            return active;
        }
    } catch (error) {
        console.error('Error loading ticket subcategories:', error);
    }
    return [];
}

// Load the companies this analyst can move tickets into (multi-company installs only).
async function loadMoveCompanies() {
    try {
        const response = await fetch('../api/system/get_tenants.php?accessible=1');
        const data = await response.json();
        if (data.success) {
            moveCompanies = data.companies || [];
            // Multi-company UI only appears once there's more than one company in total.
            isMultiCompany = moveCompanies.length > 1;
        }
    } catch (error) {
        moveCompanies = [];
        isMultiCompany = false;
    }
}

// Move the current ticket to another company. targetId optional (used by the
// wrong-company banner's quick-move); otherwise read from the Company dropdown.
async function moveTicketCompany(targetId) {
    if (!currentEmail) return;
    const id = targetId || (document.getElementById('companySelect') || {}).value;
    if (!id) return;
    try {
        const res = await fetch(API_BASE + 'move_ticket_to_company.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: currentEmail.ticket_id, tenant_id: parseInt(id, 10) })
        });
        const data = await res.json();
        if (data.success) {
            showToast(data.message || 'Ticket moved', 'success');
            currentEmail.tenant_id = parseInt(id, 10);
            // Moving may take the ticket out of the active-company view, so refresh.
            loadFolderCounts();
            loadEmails();
            selectEmail(currentEmail.id); // re-open to refresh the company field + banner
        } else {
            showToast('Could not move ticket: ' + (data.error || 'unknown error'), 'error');
        }
    } catch (e) {
        showToast('Failed to move ticket', 'error');
    }
}

// Load ticket statuses (active only) for the reading-pane Status dropdown
async function loadTicketStatuses() {
    try {
        const response = await fetch(API_BASE + 'get_ticket_statuses.php');
        const data = await response.json();

        if (data.success) {
            ticketStatuses = data.statuses.filter(s => s.is_active);
        }
    } catch (error) {
        console.error('Error loading ticket statuses:', error);
    }
}

// Load ticket priorities (active only) for the reading-pane Priority dropdown
async function loadTicketPriorities() {
    try {
        const response = await fetch(API_BASE + 'get_ticket_priorities.php');
        const data = await response.json();

        if (data.success) {
            ticketPriorities = data.priorities.filter(p => p.is_active);
        }
    } catch (error) {
        console.error('Error loading ticket priorities:', error);
    }
}

// Load analysts
async function loadAnalysts() {
    try {
        const response = await fetch(API_BASE + 'get_analysts.php');
        const data = await response.json();

        if (data.success) {
            analysts = data.analysts.filter(a => a.is_active);
        }
    } catch (error) {
        console.error('Error loading analysts:', error);
    }
}

// Load folder counts
async function loadFolderCounts() {
    try {
        const response = await fetch(API_BASE + 'get_ticket_counts.php');
        const data = await response.json();

        if (data.success) {
            folderCounts = data;
            renderFolders();
        }
    } catch (error) {
        console.error('Error loading folder counts:', error);
    }
}

// Render folder structure. Branches on folderGrouping (department vs analyst).
function renderFolders() {
    const folderListEl = document.getElementById('folderList');

    let html = '';

    // All Tickets folder
    html += `
        <div class="folder-item ${currentFilter.type === 'all' ? 'active' : ''}" data-folder-key="all" onclick="selectFolder('all')">
            <div class="folder-name">
                <span class="folder-icon">📬</span>
                <span>${escapeHtml(t('tickets.list.all_tickets'))}</span>
            </div>
            <span class="folder-count">${folderCounts.total_count || 0}</span>
        </div>
    `;

    // Unassigned folder — semantics depend on grouping mode (no department vs no analyst)
    const unassignedCount = folderGrouping === 'analyst'
        ? (folderCounts.unassigned_analyst_count || 0)
        : (folderCounts.unassigned_count || 0);
    html += `
        <div class="folder-item drop-zone ${currentFilter.type === 'unassigned' ? 'active' : ''}"
             data-drop-type="unassigned" onclick="selectFolder('unassigned')">
            <div class="folder-name">
                <span class="folder-icon">⚠️</span>
                <span>${escapeHtml(t('tickets.list.unassigned'))}</span>
            </div>
            <span class="folder-count">${unassignedCount}</span>
        </div>
    `;

    html += '<div class="folder-divider"></div>';

    if (folderGrouping === 'analyst') {
        const analysts = folderCounts.analysts || [];
        analysts.forEach(an => {
            const folderKey = `analyst_${an.id}`;
            const isExpanded = expandedFolders[folderKey];
            const isActive = currentFilter.type === 'analyst' && currentFilter.id == an.id;

            html += `
                <div class="folder-item drop-zone ${isExpanded ? 'expanded' : ''} ${isActive ? 'active' : ''}"
                     data-drop-type="analyst" data-analyst-id="${an.id}"
                     onclick="toggleFolder('${folderKey}', ${an.id}, { kind: 'analyst' })">
                    <div class="folder-name">
                        <span class="folder-icon">👤</span>
                        <span>${escapeHtml(an.name)}</span>
                    </div>
                    <span class="folder-count">${an.count}</span>
                </div>
            `;

            html += `<div class="subfolder-group ${isExpanded ? 'expanded' : ''}"><div class="subfolder-group-inner">`;
            const statuses = (folderCounts.statuses || []).map(s => s.name);
            statuses.forEach(status => {
                const count = (an.statuses || {})[status] || 0;
                const subActive = currentFilter.type === 'analyst_status' && currentFilter.analyst_id == an.id && currentFilter.status === status;
                html += `
                    <div class="subfolder-item drop-zone ${subActive ? 'active' : ''} ${count === 0 ? 'empty' : ''}"
                         data-drop-type="analyst_status" data-analyst-id="${an.id}" data-status="${escapeHtml(status)}">
                        <span>${escapeHtml(status)}</span>
                        <span class="folder-count">${count}</span>
                    </div>
                `;
            });
            html += `</div></div>`;
        });
    } else if (folderCounts.departments) {
        folderCounts.departments.forEach(dept => {
            const folderKey = `dept_${dept.id}`;
            const isExpanded = expandedFolders[folderKey];
            const isActive = currentFilter.type === 'department' && currentFilter.id == dept.id;

            html += `
                <div class="folder-item drop-zone ${isExpanded ? 'expanded' : ''} ${isActive ? 'active' : ''}"
                     data-drop-type="department" data-dept-id="${dept.id}"
                     onclick="toggleFolder('${folderKey}', ${dept.id}, { kind: 'department' })">
                    <div class="folder-name">
                        <span class="folder-icon"></span>
                        <span>${escapeHtml(dept.name)}</span>
                    </div>
                    <span class="folder-count">${dept.count}</span>
                </div>
            `;

            html += `<div class="subfolder-group ${isExpanded ? 'expanded' : ''}"><div class="subfolder-group-inner">`;
            const statuses = (folderCounts.statuses || []).map(s => s.name);
            statuses.forEach(status => {
                const count = dept.statuses[status] || 0;
                const subActive = currentFilter.type === 'dept_status' && currentFilter.dept_id == dept.id && currentFilter.status === status;
                html += `
                    <div class="subfolder-item drop-zone ${subActive ? 'active' : ''} ${count === 0 ? 'empty' : ''}"
                         data-drop-type="dept_status" data-dept-id="${dept.id}" data-status="${escapeHtml(status)}">
                        <span>${escapeHtml(status)}</span>
                        <span class="folder-count">${count}</span>
                    </div>
                `;
            });
            html += `</div></div>`;
        });
    }

    // Queues (Phase 5): saved filters — personal (🔖) and shared (👥), each with
    // a live count. Edit/delete affordances appear on hover for queues the
    // analyst may manage (their own, or shared queues if they're an admin).
    html += '<div class="folder-divider"></div>';
    html += `<div class="queue-section-header">
        <span>Queues</span>
        <button class="queue-add-btn" title="New queue" onclick="openNewQueue()">+</button>
    </div>`;
    if (!ticketQueues.length) {
        html += `<div class="queue-empty">No saved queues yet</div>`;
    } else {
        ticketQueues.forEach(q => {
            const isActive = activeQueueId === q.id;
            const canManage = q.is_own || (q.is_shared && queueCanManageShared);
            const actions = canManage
                ? `<span class="queue-action" title="Edit queue" onclick="event.stopPropagation();editQueue(${q.id})">✎</span>
                   <span class="queue-action" title="Delete queue" onclick="event.stopPropagation();deleteQueue(${q.id})">🗑</span>`
                : '';
            html += `
                <div class="folder-item queue-item ${isActive ? 'active' : ''}" data-queue-id="${q.id}" onclick="selectQueue(${q.id})">
                    <div class="folder-name">
                        <span class="folder-icon">${q.is_shared ? '👥' : '🔖'}</span>
                        <span>${escapeHtml(q.name)}</span>
                    </div>
                    <span class="queue-item-right">${actions}<span class="folder-count">${q.count}</span></span>
                </div>
            `;
        });
    }

    // Trash folder — soft-deleted tickets, restorable. Pinned to the bottom.
    // It's a drop target (drag a ticket here to trash it) and has its own
    // right-click menu (Empty trash).
    html += '<div class="folder-divider"></div>';
    html += `
        <div class="folder-item drop-zone ${currentFilter.type === 'trash' ? 'active' : ''}" data-folder-key="trash" data-drop-type="trash"
             onclick="selectFolder('trash')" oncontextmenu="openTrashContextMenu(event)">
            <div class="folder-name">
                <span class="folder-icon">🗑️</span>
                <span>${escapeHtml(t('tickets.list.trash'))}</span>
            </div>
            <span class="folder-count">${folderCounts.trash_count || 0}</span>
        </div>
    `;

    folderListEl.innerHTML = html;

    // Wire drag-and-drop on freshly rendered folder rows
    attachFolderDropHandlers();
}

// Update only the .active class on existing folder/subfolder rows — does NOT rebuild
// the folder list. Used by selection paths so the .subfolder-group expand transition
// (which requires the element to persist) actually fires.
function updateActiveFolderClasses() {
    const list = document.getElementById('folderList');
    if (!list) return;
    list.querySelectorAll('.folder-item, .subfolder-item').forEach(el => el.classList.remove('active'));

    // Phase 5: a selected queue highlights its own row; no built-in folder is active.
    if (currentFilter.type === 'queue' && activeQueueId != null) {
        list.querySelector(`.queue-item[data-queue-id="${activeQueueId}"]`)?.classList.add('active');
        return;
    }

    if (currentFilter.type === 'all') {
        list.querySelector('[data-folder-key="all"]')?.classList.add('active');
    } else if (currentFilter.type === 'unassigned') {
        list.querySelector('[data-drop-type="unassigned"]')?.classList.add('active');
    } else if (currentFilter.type === 'department') {
        list.querySelector(`[data-drop-type="department"][data-dept-id="${currentFilter.id}"]`)
            ?.classList.add('active');
    } else if (currentFilter.type === 'dept_status') {
        const sel = `.subfolder-item[data-dept-id="${currentFilter.dept_id}"][data-status="${CSS.escape(currentFilter.status)}"]`;
        list.querySelector(sel)?.classList.add('active');
    } else if (currentFilter.type === 'analyst') {
        list.querySelector(`[data-drop-type="analyst"][data-analyst-id="${currentFilter.id}"]`)
            ?.classList.add('active');
    } else if (currentFilter.type === 'analyst_status') {
        const sel = `.subfolder-item[data-analyst-id="${currentFilter.analyst_id}"][data-status="${CSS.escape(currentFilter.status)}"]`;
        list.querySelector(sel)?.classList.add('active');
    }
}

// Toggle folder expansion. Works for both department and analyst folders.
// opts.kind — 'department' (default) or 'analyst'
// opts.selectAfter — if false, don't change the active filter/view (used by drag hover)
// opts.forceExpand — if true, only expand (no toggle), used by drag hover
function toggleFolder(folderId, groupId, opts = {}) {
    const { selectAfter = true, forceExpand = false, kind = 'department' } = opts;
    const wasExpanded = !!expandedFolders[folderId];
    let willBeExpanded;
    if (forceExpand) {
        if (wasExpanded) return;
        willBeExpanded = true;
    } else {
        willBeExpanded = !wasExpanded;
    }
    expandedFolders[folderId] = willBeExpanded;

    // Targeted class flip on the existing nodes so the CSS grid-row transition fires.
    const list = document.getElementById('folderList');
    const dataAttr = kind === 'analyst' ? 'data-analyst-id' : 'data-dept-id';
    const folderRow = list?.querySelector(`.folder-item[data-drop-type="${kind}"][${dataAttr}="${groupId}"]`);
    const subGroup = folderRow?.nextElementSibling;
    folderRow?.classList.toggle('expanded', willBeExpanded);
    if (subGroup && subGroup.classList.contains('subfolder-group')) {
        subGroup.classList.toggle('expanded', willBeExpanded);
    }

    if (selectAfter) {
        resetFilterContext();
        if (kind === 'analyst') {
            currentFilter = { type: 'analyst', id: groupId };
            const an = folderCounts.analysts?.find(a => a.id == groupId);
            document.getElementById('emailListTitle').textContent = an ? an.name : 'Analyst';
        } else {
            currentFilter = { type: 'department', id: groupId };
            const dept = folderCounts.departments?.find(d => d.id == groupId);
            document.getElementById('emailListTitle').textContent = dept ? dept.name : 'Department';
        }
        updateActiveFolderClasses();
        loadEmails();
    }
}

// Navigating to a built-in folder is a fresh view: drop any queue selection and
// ad-hoc filter so the folder shows its full contents. The funnel can then be
// used to refine the current folder.
function resetFilterContext() {
    activeQueueId = null;
    editingQueueId = null;
    adHocFilters = {};
    updateFilterIndicator();
    if (typeof bulkSelected !== 'undefined') { bulkSelected.clear(); updateBulkBar(); }
}

// Select folder
function selectFolder(type, id = null) {
    resetFilterContext();
    if (type === 'all') {
        currentFilter = { type: 'all' };
        document.getElementById('emailListTitle').textContent = t('tickets.list.all_tickets');
    } else if (type === 'unassigned') {
        currentFilter = { type: 'unassigned' };
        document.getElementById('emailListTitle').textContent = t('tickets.list.unassigned_tickets');
    } else if (type === 'department') {
        currentFilter = { type: 'department', id: id };
        const dept = folderCounts.departments.find(d => d.id == id);
        document.getElementById('emailListTitle').textContent = dept ? dept.name : t('tickets.folders.group_department');
    } else if (type === 'trash') {
        currentFilter = { type: 'trash' };
        document.getElementById('emailListTitle').textContent = t('tickets.list.trash');
    }

    updateActiveFolderClasses();
    loadEmails();
}

// Select department + status
function selectDeptStatus(deptId, status) {
    resetFilterContext();
    currentFilter = { type: 'dept_status', dept_id: deptId, status: status };
    const dept = folderCounts.departments.find(d => d.id == deptId);
    document.getElementById('emailListTitle').textContent = `${dept ? dept.name : 'Department'} - ${status}`;

    updateActiveFolderClasses();
    loadEmails();
}

// Select analyst + status
function selectAnalystStatus(analystId, status) {
    resetFilterContext();
    currentFilter = { type: 'analyst_status', analyst_id: analystId, status: status };
    const an = folderCounts.analysts?.find(a => a.id == analystId);
    document.getElementById('emailListTitle').textContent = `${an ? an.name : 'Analyst'} - ${status}`;

    updateActiveFolderClasses();
    loadEmails();
}

// ===== Drag-and-drop: tickets onto folders =====
let draggedTicketId = null;
let draggedTicketNumber = null;
let dragHoverTimer = null;
let dragHoverFolderId = null;

function attachEmailDragHandlers() {
    document.querySelectorAll('#emailList .email-item').forEach(el => {
        el.addEventListener('dragstart', (e) => {
            draggedTicketId = el.dataset.ticketId;
            draggedTicketNumber = el.dataset.ticketNumber;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', draggedTicketId);
            el.classList.add('dragging');
        });
        el.addEventListener('dragend', () => {
            el.classList.remove('dragging');
            draggedTicketId = null;
            draggedTicketNumber = null;
            cancelDragHover();
            document.querySelectorAll('.drop-target').forEach(t => t.classList.remove('drop-target'));
        });
    });
}

function attachFolderDropHandlers() {
    // Click handler for subfolder rows (delegated so status names with apostrophes are safe)
    document.querySelectorAll('#folderList .subfolder-item').forEach(el => {
        el.addEventListener('click', (e) => {
            e.stopPropagation();
            const dropType = el.dataset.dropType;
            const status = el.dataset.status;
            if (dropType === 'analyst_status') {
                const analystId = parseInt(el.dataset.analystId, 10);
                if (analystId && status) selectAnalystStatus(analystId, status);
            } else {
                const deptId = parseInt(el.dataset.deptId, 10);
                if (deptId && status) selectDeptStatus(deptId, status);
            }
        });
    });

    document.querySelectorAll('#folderList .drop-zone').forEach(el => {
        el.addEventListener('dragover', (e) => {
            if (!draggedTicketId) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            el.classList.add('drop-target');

            // Hover-to-expand on collapsed group folders (works for both dept and analyst)
            const dt = el.dataset.dropType;
            if (dt === 'department' || dt === 'analyst') {
                const groupId = dt === 'analyst' ? el.dataset.analystId : el.dataset.deptId;
                const folderId = `${dt === 'analyst' ? 'analyst' : 'dept'}_${groupId}`;
                if (!expandedFolders[folderId]) {
                    if (dragHoverFolderId !== folderId) {
                        cancelDragHover();
                        dragHoverFolderId = folderId;
                        dragHoverTimer = setTimeout(() => {
                            toggleFolder(folderId, groupId, { selectAfter: false, forceExpand: true, kind: dt });
                            dragHoverTimer = null;
                        }, 600);
                    }
                }
            }
        });
        el.addEventListener('dragleave', (e) => {
            el.classList.remove('drop-target');
            const dt = el.dataset.dropType;
            // Only cancel hover timer if leaving the row that started it
            if (dt === 'department' && dragHoverFolderId === `dept_${el.dataset.deptId}`) {
                cancelDragHover();
            } else if (dt === 'analyst' && dragHoverFolderId === `analyst_${el.dataset.analystId}`) {
                cancelDragHover();
            }
        });
        el.addEventListener('drop', (e) => {
            e.preventDefault();
            el.classList.remove('drop-target');
            cancelDragHover();
            if (!draggedTicketId) return;
            handleTicketDrop(el, draggedTicketId, draggedTicketNumber);
        });
    });
}

function cancelDragHover() {
    if (dragHoverTimer) {
        clearTimeout(dragHoverTimer);
        dragHoverTimer = null;
    }
    dragHoverFolderId = null;
}

async function handleTicketDrop(targetEl, ticketId, ticketNumber) {
    const dropType = targetEl.dataset.dropType;

    // Dropping onto the Trash folder soft-deletes the ticket.
    if (dropType === 'trash') {
        try {
            const res = await fetch(API_BASE + 'delete_ticket.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ticket_id: parseInt(ticketId, 10) })
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'failed');
            showToast(`${ticketNumber || 'Ticket'} → Trash`, 'success');
            clearReadingPaneIfTicket(parseInt(ticketId, 10));
            await loadFolderCounts();
            loadEmails();
        } catch (e) { showToast('Move to trash failed: ' + e.message, 'error'); }
        return;
    }

    const payload = { ticket_id: parseInt(ticketId, 10) };
    let toastMsg = '';

    // Capture old values from the in-memory email row for audit logging
    const sourceEmail = emails.find(e => String(e.ticket_id) === String(ticketId));
    const oldDeptName = sourceEmail ? getDisplayName('department', sourceEmail.department_id) : null;
    const oldStatusName = sourceEmail ? sourceEmail.status : null;
    const oldAnalystName = sourceEmail ? getDisplayName('owner', sourceEmail.assigned_analyst_id) : null;

    let newDeptName = null;
    let newStatusName = null;
    let newAnalystName = null;

    // "Unassigned" target means different things depending on the active grouping
    if (dropType === 'unassigned') {
        if (folderGrouping === 'analyst') {
            payload.assigned_analyst_id = '';
            toastMsg = `${ticketNumber || 'Ticket'} → Unassigned (no analyst)`;
        } else {
            payload.department_id = '';
            toastMsg = `${ticketNumber || 'Ticket'} → Unassigned`;
        }
    } else if (dropType === 'department') {
        payload.department_id = parseInt(targetEl.dataset.deptId, 10);
        const dept = folderCounts.departments.find(d => d.id == payload.department_id);
        newDeptName = dept ? dept.name : null;
        toastMsg = `${ticketNumber || 'Ticket'} → ${newDeptName || 'Department'}`;
    } else if (dropType === 'dept_status') {
        payload.department_id = parseInt(targetEl.dataset.deptId, 10);
        payload.status = targetEl.dataset.status;
        const dept = folderCounts.departments.find(d => d.id == payload.department_id);
        newDeptName = dept ? dept.name : null;
        newStatusName = payload.status;
        toastMsg = `${ticketNumber || 'Ticket'} → ${newDeptName || 'Department'} / ${payload.status}`;
    } else if (dropType === 'analyst') {
        payload.assigned_analyst_id = parseInt(targetEl.dataset.analystId, 10);
        const an = folderCounts.analysts?.find(a => a.id == payload.assigned_analyst_id);
        newAnalystName = an ? an.name : null;
        toastMsg = `${ticketNumber || 'Ticket'} → ${newAnalystName || 'Analyst'}`;
    } else if (dropType === 'analyst_status') {
        payload.assigned_analyst_id = parseInt(targetEl.dataset.analystId, 10);
        payload.status = targetEl.dataset.status;
        const an = folderCounts.analysts?.find(a => a.id == payload.assigned_analyst_id);
        newAnalystName = an ? an.name : null;
        newStatusName = payload.status;
        toastMsg = `${ticketNumber || 'Ticket'} → ${newAnalystName || 'Analyst'} / ${payload.status}`;
    } else {
        return;
    }

    try {
        const res = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Update failed');

        // Audit log — only for fields that actually changed
        const ticketIdInt = parseInt(ticketId, 10);
        const auditCalls = [];
        if (newDeptName !== oldDeptName && (dropType === 'department' || dropType === 'dept_status' || (dropType === 'unassigned' && folderGrouping !== 'analyst'))) {
            auditCalls.push(logAudit(ticketIdInt, 'Department', oldDeptName, newDeptName));
        }
        if (newStatusName !== null && newStatusName !== oldStatusName) {
            auditCalls.push(logAudit(ticketIdInt, 'Status', oldStatusName, newStatusName));
        }
        if (dropType === 'analyst' || dropType === 'analyst_status' || (dropType === 'unassigned' && folderGrouping === 'analyst')) {
            if (newAnalystName !== oldAnalystName) {
                auditCalls.push(logAudit(ticketIdInt, 'Owner', oldAnalystName, newAnalystName));
            }
        }
        await Promise.all(auditCalls);

        showToast(toastMsg, 'success');
        await loadFolderCounts();
        loadEmails();

        // If the dragged ticket is the one open in the reading pane, refresh it
        // so the Department/Status dropdowns show the new values.
        if (currentEmail && String(currentEmail.ticket_id) === String(ticketId)) {
            loadTicketById(currentEmail.ticket_id);
        }
    } catch (err) {
        console.error('Drop assign error:', err);
        showToast('Failed to move ticket: ' + (err.message || err), 'error');
    }
}

// Load emails based on current filter
async function loadEmails() {
    try {
        let url = API_BASE + 'get_emails.php?';

        if (currentFilter.type === 'unassigned') {
            // "Unassigned" semantics depend on the active grouping
            url += folderGrouping === 'analyst' ? 'assignee_id=unassigned' : 'department_id=unassigned';
        } else if (currentFilter.type === 'department') {
            url += `department_id=${currentFilter.id}`;
        } else if (currentFilter.type === 'dept_status') {
            url += `department_id=${currentFilter.dept_id}&status=${encodeURIComponent(currentFilter.status)}`;
        } else if (currentFilter.type === 'analyst') {
            url += `assignee_id=${currentFilter.id}`;
        } else if (currentFilter.type === 'analyst_status') {
            url += `assignee_id=${currentFilter.analyst_id}&status=${encodeURIComponent(currentFilter.status)}`;
        } else if (currentFilter.type === 'trash') {
            url += 'trashed=1';
        }

        // Phase 5: append the ad-hoc filter set (narrows within the current folder).
        if (adHocFilterActiveCount() > 0) {
            const sep = url.endsWith('?') ? '' : '&';
            url += sep + 'filters=' + encodeURIComponent(JSON.stringify(adHocFilters));
        }

        const response = await fetch(url);
        const data = await response.json();

        if (data.success) {
            emails = data.emails;
            renderEmailList();
        } else {
            showToast('Error loading emails: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to load emails', 'error');
    }
}

// Render email list
// ===== Phase 5: ad-hoc ticket filters =====
// A compact filter panel over the ticket list. Fields map 1:1 onto the shared
// server-side engine (includes/ticket_filter.php); the same shape is reused by
// saved queues (5b). SLA-state filtering is intentionally omitted for now
// (SLA is compute-on-read — no column to filter on yet).

function adHocFilterActiveCount() {
    let n = 0;
    for (const k in adHocFilters) {
        const v = adHocFilters[k];
        if (Array.isArray(v)) { if (v.length) n++; }
        else if (typeof v === 'string') { if (v.trim() !== '') n++; }
        else if (v != null) n++;
    }
    return n;
}

function updateFilterIndicator() {
    const btn = document.getElementById('filterToggleBtn');
    const badge = document.getElementById('filterBadge');
    const n = adHocFilterActiveCount();
    if (btn) btn.classList.toggle('filter-active', n > 0);
    if (badge) {
        badge.textContent = n;
        badge.style.display = n > 0 ? '' : 'none';
    }
}

function toggleFilterPanel() {
    const panel = document.getElementById('ticketFilterPanel');
    if (!panel) return;
    if (panel.hidden) {
        renderFilterPanel();
        panel.hidden = false;
    } else {
        panel.hidden = true;
    }
}

function renderFilterPanel() {
    const panel = document.getElementById('ticketFilterPanel');
    if (!panel) return;

    // One checkbox group. `field` is the server filter key; status filters by
    // name, everything else by numeric id (data-kind drives the read step).
    const group = (label, field, options, valueKey, labelKey) => {
        const selected = (adHocFilters[field] || []).map(String);
        const items = (options || []).map(o => {
            const val = String(o[valueKey]);
            const checked = selected.includes(val) ? 'checked' : '';
            const kind = field === 'status' ? 'str' : 'int';
            return `<label class="tf-opt"><input type="checkbox" data-field="${field}" data-kind="${kind}" value="${escapeHtml(val)}" ${checked}> ${escapeHtml(String(o[labelKey]))}</label>`;
        }).join('');
        if (!items) return '';
        return `<div class="tf-field"><div class="tf-field-label">${escapeHtml(label)}</div><div class="tf-options">${items}</div></div>`;
    };

    const kw = adHocFilters.keyword || '';
    const cf = adHocFilters.created_from || '';
    const ct = adHocFilters.created_to || '';
    const watchedMe = Array.isArray(adHocFilters.watched_by) && adHocFilters.watched_by.includes('me');
    const customerGroup = (typeof isMultiCompany !== 'undefined' && isMultiCompany)
        ? group('Customer', 'tenant_id', moveCompanies, 'id', 'name') : '';

    // Save row: create a new queue, or update the one being edited.
    const editing = editingQueueId ? ticketQueues.find(q => q.id === editingQueueId) : null;
    const qName = editing ? editing.name : '';
    const sharedChecked = editing && editing.is_shared ? 'checked' : '';
    const sharedField = queueCanManageShared
        ? `<label class="tf-shared"><input type="checkbox" id="tfQueueShared" ${sharedChecked}> Shared</label>` : '';
    const saveLabel = editing ? 'Update queue' : 'Save as queue';

    panel.innerHTML = `
        <div class="tf-top">
            <div class="tf-field tf-keyword">
                <div class="tf-field-label">Keyword</div>
                <input type="text" id="tfKeyword" placeholder="Subject or ticket #" value="${escapeHtml(kw)}">
            </div>
            <div class="tf-field">
                <div class="tf-field-label">Created from</div>
                <input type="date" id="tfCreatedFrom" value="${escapeHtml(cf)}">
            </div>
            <div class="tf-field">
                <div class="tf-field-label">Created to</div>
                <input type="date" id="tfCreatedTo" value="${escapeHtml(ct)}">
            </div>
            <div class="tf-field">
                <div class="tf-field-label">&nbsp;</div>
                <label class="tf-watched"><input type="checkbox" id="tfWatchedByMe" ${watchedMe ? 'checked' : ''}> Watched by me</label>
            </div>
        </div>
        <div class="tf-grid">
            ${group('Status', 'status', ticketStatuses, 'name', 'name')}
            ${group('Priority', 'priority_id', ticketPriorities, 'id', 'name')}
            ${group('Type', 'ticket_type_id', ticketTypes, 'id', 'name')}
            ${group('Category', 'category_id', ticketCategories, 'id', 'name')}
            ${group('Origin', 'origin_id', ticketOrigins, 'id', 'name')}
            ${group('Assignee', 'assignee_id', analysts, 'id', 'full_name')}
            ${group('Department', 'department_id', departments, 'id', 'name')}
            ${group('Tags', 'tag_id', ticketTagsAll, 'id', 'name')}
            ${customerGroup}
        </div>
        <div class="tf-actions">
            <div class="tf-save">
                <input type="text" id="tfQueueName" placeholder="Queue name…" value="${escapeHtml(qName)}" maxlength="150">
                ${sharedField}
                <button class="btn btn-secondary" onclick="saveQueueFromPanel()">${escapeHtml(saveLabel)}</button>
            </div>
            <div class="tf-actions-right">
                <button class="btn btn-secondary" onclick="clearAdHocFilters()">Clear</button>
                <button class="btn btn-primary" onclick="applyAdHocFilters()">Apply filters</button>
            </div>
        </div>
    `;
}

function readFilterPanel() {
    const panel = document.getElementById('ticketFilterPanel');
    const f = {};
    if (!panel) return f;
    panel.querySelectorAll('input[type=checkbox]:checked').forEach(cb => {
        const field = cb.dataset.field;
        const val = cb.dataset.kind === 'int' ? Number(cb.value) : cb.value;
        (f[field] = f[field] || []).push(val);
    });
    const kwEl = panel.querySelector('#tfKeyword');
    if (kwEl && kwEl.value.trim() !== '') f.keyword = kwEl.value.trim();
    const cfEl = panel.querySelector('#tfCreatedFrom');
    if (cfEl && cfEl.value) f.created_from = cfEl.value;
    const ctEl = panel.querySelector('#tfCreatedTo');
    if (ctEl && ctEl.value) f.created_to = ctEl.value;
    const wmEl = panel.querySelector('#tfWatchedByMe');
    if (wmEl && wmEl.checked) f.watched_by = ['me'];
    return f;
}

// Tweaking filters directly detaches from any selected queue — it becomes a
// one-off ad-hoc view (over all tickets) rather than "the queue".
function detachFromQueue() {
    if (currentFilter.type === 'queue') {
        currentFilter = { type: 'all' };
        const tEl = document.getElementById('emailListTitle');
        if (tEl) tEl.textContent = t('tickets.list.all_tickets');
    }
    activeQueueId = null;
    editingQueueId = null;
}

function applyAdHocFilters() {
    adHocFilters = readFilterPanel();
    detachFromQueue();
    updateFilterIndicator();
    const panel = document.getElementById('ticketFilterPanel');
    if (panel) panel.hidden = true;
    updateActiveFolderClasses();
    loadEmails();
}

function clearAdHocFilters() {
    adHocFilters = {};
    detachFromQueue();
    updateFilterIndicator();
    const panel = document.getElementById('ticketFilterPanel');
    if (panel) panel.hidden = true;
    updateActiveFolderClasses();
    loadEmails();
}

// ===== Phase 5b: saved queues =====

async function loadQueues() {
    try {
        const res = await fetch(API_BASE + 'get_ticket_queues.php');
        const data = await res.json();
        if (!data.success) return;
        ticketQueues = data.queues || [];
        queueCanManageShared = !!data.can_manage_shared;
        renderFolders();
    } catch (e) { /* queues section just won't render */ }
}

function selectQueue(id) {
    const q = ticketQueues.find(x => x.id === id);
    if (!q) return;
    activeQueueId = id;
    editingQueueId = null;
    adHocFilters = JSON.parse(JSON.stringify(q.filters || {}));
    currentFilter = { type: 'queue', id };
    const tEl = document.getElementById('emailListTitle');
    if (tEl) tEl.textContent = q.name;
    updateFilterIndicator();
    updateActiveFolderClasses();
    loadEmails();
}

// Open the filter panel blank, ready to build + save a new queue.
function openNewQueue() {
    editingQueueId = null;
    adHocFilters = {};
    renderFilterPanel();
    const panel = document.getElementById('ticketFilterPanel');
    if (panel) panel.hidden = false;
    const nameEl = document.getElementById('tfQueueName');
    if (nameEl) nameEl.focus();
}

// Open the filter panel pre-loaded with a queue's filters, in update mode.
function editQueue(id) {
    const q = ticketQueues.find(x => x.id === id);
    if (!q) return;
    editingQueueId = id;
    adHocFilters = JSON.parse(JSON.stringify(q.filters || {}));
    renderFilterPanel();
    const panel = document.getElementById('ticketFilterPanel');
    if (panel) panel.hidden = false;
}

async function saveQueueFromPanel() {
    const nameEl = document.getElementById('tfQueueName');
    const name = nameEl ? nameEl.value.trim() : '';
    if (!name) { showToast('Enter a queue name', 'error'); return; }
    const sharedEl = document.getElementById('tfQueueShared');
    const body = { name, is_shared: sharedEl ? sharedEl.checked : false, filters: readFilterPanel() };
    if (editingQueueId) body.id = editingQueueId;
    try {
        const res = await fetch(API_BASE + 'save_ticket_queue.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Save failed');
        showToast(editingQueueId ? 'Queue updated' : 'Queue saved', 'success');
        editingQueueId = null;
        const panel = document.getElementById('ticketFilterPanel');
        if (panel) panel.hidden = true;
        await loadQueues();
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    }
}

async function deleteQueue(id) {
    const q = ticketQueues.find(x => x.id === id);
    if (!q) return;
    if (!(await showConfirm({ title: 'Delete queue', message: `Delete the queue "${q.name}"?`, okLabel: 'Delete', okClass: 'primary' }))) return;
    try {
        const res = await fetch(API_BASE + 'delete_ticket_queue.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Delete failed');
        showToast('Queue deleted', 'success');
        if (activeQueueId === id) {
            activeQueueId = null; adHocFilters = {}; currentFilter = { type: 'all' };
            const tEl = document.getElementById('emailListTitle');
            if (tEl) tEl.textContent = t('tickets.list.all_tickets');
            updateFilterIndicator();
            loadEmails();
        }
        await loadQueues();
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    }
}

function renderEmailList() {
    const emailListEl = document.getElementById('emailList');

    if (emails.length === 0) {
        emailListEl.innerHTML = '<div class="reading-pane-empty">No tickets found</div>';
        return;
    }

    const inTrash = currentFilter.type === 'trash';
    emailListEl.innerHTML = emails.map(email => {
        const emailCount = email.email_count || 1;
        const countBadge = emailCount > 1 ? `<span class="email-count-badge">${emailCount}</span>` : '';
        const ticketId = email.ticket_id || email.id;
        const trashActions = inTrash ? `
                <div style="display:flex;gap:8px;margin-top:7px;">
                    <button onclick="event.stopPropagation(); restoreTicketFromTrash(${ticketId})" style="font-size:11px;padding:3px 9px;border:1px solid #c8d6cf;background:#eefaf2;color:#1b7a43;border-radius:4px;cursor:pointer;">↩ Restore</button>
                    <button onclick="event.stopPropagation(); permanentlyDeleteFromTrash(${ticketId}, '${escapeHtml(email.ticket_number || '')}')" style="font-size:11px;padding:3px 9px;border:1px solid #e6c4c4;background:#fdeceb;color:#b71c1c;border-radius:4px;cursor:pointer;">✕ Delete forever</button>
                </div>` : '';
        // Reserve a slot for the SLA dot; populated asynchronously by loadInboxSlaIndicators()
        // once the batch endpoint responds. Stays empty (and invisible) for tickets without SLA.
        return `
            <div class="email-item ${email.id === selectedEmailId ? 'selected' : ''} ${!email.is_read ? 'unread' : ''}"
                 draggable="true" data-email-id="${email.id}" data-ticket-id="${ticketId}" data-ticket-number="${escapeHtml(email.ticket_number || '')}"
                 onclick="selectEmail(${email.id})" ondblclick="selectEmailFullScreen(${email.id})"
                 oncontextmenu="openTicketContextMenu(event, ${ticketId}, '${escapeHtml(email.ticket_number || '')}')">
                <input type="checkbox" class="bulk-cb" title="Select" onclick="event.stopPropagation(); toggleBulkSelect(${ticketId}, this.checked)" ${bulkSelected.has(ticketId) ? 'checked' : ''}>
                <div class="email-from">${escapeHtml(email.ticket_number || '')} - ${escapeHtml(email.from_name || email.from_address)} ${countBadge}</div>
                <div class="email-subject">${escapeHtml(email.subject)}</div>
                <div class="email-preview">${escapeHtml(email.body_preview || '')}</div>
                <div class="email-footer-row">
                    <div class="email-time">${formatDateTime(email.received_datetime)}</div>
                    <div class="email-sla-slot" data-sla-slot="${ticketId}"></div>
                </div>${trashActions}
            </div>
        `;
    }).join('');

    // Wire drag handlers on freshly rendered email rows
    attachEmailDragHandlers();

    // Fire-and-forget batch SLA fetch to colour the dots in
    loadInboxSlaIndicators();

    // Phase 6c: reflect any active bulk selection into the toolbar.
    updateBulkBar();
}

/**
 * Populate the SLA dot in each email row via the batch endpoint.
 *
 * One request per render covers every visible row (cap = 200 server-side).
 * Tickets without SLA simply don't come back in the response, so their slot
 * stays empty. Re-rendering the list (e.g. on filter change) re-runs this.
 */
async function loadInboxSlaIndicators() {
    const slots = document.querySelectorAll('#emailList [data-sla-slot]');
    if (!slots.length) return;
    const ids = Array.from(slots).map(el => el.getAttribute('data-sla-slot')).filter(Boolean);
    if (!ids.length) return;
    try {
        const res = await fetch(API_BASE + 'get_tickets_sla_batch.php?ticket_ids=' + encodeURIComponent(ids.join(',')));
        const data = await res.json();
        if (!data.success || !data.sla) return;
        slots.forEach(slot => {
            const id = slot.getAttribute('data-sla-slot');
            const row = data.sla[id];
            if (!row) return;
            slot.innerHTML = renderInboxSlaIndicator(row);
        });
    } catch (e) {
        console.error('Batch SLA load failed:', e);
    }
}

/**
 * Build the inline dot + label for one email row.
 *
 * Surfaces the *more urgent* of response / resolution — if response is still
 * outstanding it wins (analysts care about the first thing on the clock).
 * Once response is achieved, we follow the resolution target until it lands.
 */
function renderInboxSlaIndicator(row) {
    const pickTarget = () => {
        const r = row.response;
        const f = row.resolution;
        if (r && r.achieved_at === null) return { t: r, label: 'R' };
        if (f && f.achieved_at === null) return { t: f, label: 'F' };
        if (f) return { t: f, label: 'F' };
        if (r) return { t: r, label: 'R' };
        return null;
    };
    const pick = pickTarget();
    if (!pick) return '';
    const { t, label } = pick;
    let cls = 'sla-ok';
    if (t.achieved_at !== null) {
        cls = t.breached ? 'sla-breached' : 'sla-achieved';
    } else if (t.breached) {
        cls = 'sla-breached';
    } else if (t.percent >= 80) {
        cls = 'sla-warning';
    }
    const fmt = (mins) => {
        if (mins === null || mins === undefined) return '';
        const n = Math.abs(mins);
        const sign = mins < 0 ? '-' : '';
        if (n < 60) return sign + n + 'm';
        const h = Math.floor(n / 60), r = n % 60;
        return sign + (r ? `${h}h${r}m` : `${h}h`);
    };
    const text = t.achieved_at !== null
        ? (t.breached ? 'breached' : 'met')
        : (t.breached ? `+${fmt(Math.abs(t.remaining_minutes))}` : fmt(t.remaining_minutes));
    const priorityName = row.priority ? row.priority.name : '';
    const title = `${priorityName} SLA · ${label === 'R' ? 'Response' : 'Resolution'} · ${text}`;
    return `<span class="email-sla-pill ${cls}" title="${escapeHtml(title)}">
                <span class="email-sla-dot"></span>${escapeHtml(label)} ${escapeHtml(text)}
            </span>`;
}

// Move the "selected" highlight to one row without rebuilding the whole list.
// Rebuilding (renderEmailList) on every click also re-fired the batch SLA fetch,
// which made the SLA pills flash off and back on — this just toggles a class.
function setSelectedEmailRow(emailId) {
    document.querySelectorAll('#emailList .email-item.selected')
        .forEach(el => el.classList.remove('selected'));
    const row = document.querySelector(`#emailList .email-item[data-email-id="${emailId}"]`);
    if (row) row.classList.add('selected');
}

// Select and display email by email ID
async function selectEmail(emailId) {
    selectedEmailId = emailId;
    setSelectedEmailRow(emailId);

    const readingPane = document.getElementById('readingPane');

    // Avoid the blank-pane flicker when switching between tickets: keep the
    // current ticket on screen during the (usually instant) fetch and swap
    // straight to the new one. Only blank to a spinner when the pane is empty
    // (first open), or if the fetch is actually slow — a delayed timer covers
    // that so a slow network still gives feedback.
    const hadTicket = !!currentEmail;
    let spinnerTimer = null;
    if (!hadTicket) {
        readingPane.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
    } else {
        spinnerTimer = setTimeout(() => {
            readingPane.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
        }, 250);
    }

    try {
        const response = await fetch(`${API_BASE}get_email_detail.php?id=${emailId}`);
        const data = await response.json();
        if (spinnerTimer) clearTimeout(spinnerTimer);

        if (data.success) {
            displayEmail(data.email, data.recordings || []);
        } else {
            readingPane.innerHTML = '<div class="reading-pane-empty">Error loading email</div>';
            syncPopoutToTicketState(false);
        }
    } catch (error) {
        if (spinnerTimer) clearTimeout(spinnerTimer);
        console.error('Error:', error);
        readingPane.innerHTML = '<div class="reading-pane-empty">Failed to load email</div>';
        syncPopoutToTicketState(false);
    }
}

// Load and display ticket by ticket ID (from URL parameter)
async function loadTicketById(ticketId) {
    const readingPane = document.getElementById('readingPane');
    readingPane.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

    try {
        const response = await fetch(`${API_BASE}get_email_detail.php?ticket_id=${ticketId}`);
        const data = await response.json();

        if (data.success) {
            selectedEmailId = data.email.id;
            renderEmailList();
            displayEmail(data.email, data.recordings || []);
        } else {
            readingPane.innerHTML = '<div class="reading-pane-empty">Ticket not found</div>';
            syncPopoutToTicketState(false);
        }
    } catch (error) {
        console.error('Error:', error);
        readingPane.innerHTML = '<div class="reading-pane-empty">Failed to load ticket</div>';
        syncPopoutToTicketState(false);
    }
}

// Display email in reading pane
function displayEmail(email, recordings) {
    currentEmail = email;
    currentRecordings = recordings || [];
    const readingPane = document.getElementById('readingPane');

    // Build department dropdown
    const departmentOptions = departments.map(dept =>
        `<option value="${dept.id}" ${email.department_id == dept.id ? 'selected' : ''}>${escapeHtml(dept.name)}</option>`
    ).join('');

    // Multi-company only: a Company picker (move the ticket) + a soft wrong-company
    // warning. Both stay empty on a single-company install, so nothing changes at N=1.
    let companyField = '';
    let companyWarningBanner = '';
    if (isMultiCompany) {
        const defaultCo = moveCompanies.find(c => c.is_default) || {};
        const currentTid = (email.tenant_id != null) ? email.tenant_id : defaultCo.id;
        const companyOptions = moveCompanies.map(c =>
            `<option value="${c.id}" ${String(currentTid) === String(c.id) ? 'selected' : ''}>${escapeHtml(c.name)}</option>`
        ).join('');
        companyField = `
            <div class="toolbar-field">
                <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_company'))}</label>
                <select class="toolbar-select" id="companySelect" onchange="moveTicketCompany()">
                    ${companyOptions}
                </select>
            </div>`;
        if (email.company_warning) {
            const w = email.company_warning;
            companyWarningBanner = `
                <div class="wrong-company-banner">
                    <span class="wrong-company-text">⚠ Filed under <strong>${escapeHtml(email.company_name || '')}</strong>, but the requester (${escapeHtml(w.requester)}) looks like <strong>${escapeHtml(w.suggested_name)}</strong>.</span>
                    <span class="wrong-company-actions">
                        <button class="action-btn action-btn-primary" onclick="moveTicketCompany(${w.suggested_id})">Move to ${escapeHtml(w.suggested_name)}</button>
                        <button class="action-btn" onclick="this.closest('.wrong-company-banner').remove()">Dismiss</button>
                    </span>
                </div>`;
        }
    }

    // Build ticket type dropdown
    const ticketTypeOptions = ticketTypes.map(type =>
        `<option value="${type.id}" ${email.ticket_type_id == type.id ? 'selected' : ''}>${escapeHtml(type.name)}</option>`
    ).join('');

    // Build category dropdown. Subcategory options depend on the selected
    // category and are populated asynchronously after render (populateSubcategorySelect).
    const categoryOptions = ticketCategories.map(cat =>
        `<option value="${cat.id}" ${email.category_id == cat.id ? 'selected' : ''}>${escapeHtml(cat.name)}</option>`
    ).join('');

    // Build status dropdown from the active ticket_statuses lookup
    const statusOptions = ticketStatuses.map(s =>
        `<option value="${escapeHtml(s.name)}" ${email.status === s.name ? 'selected' : ''}>${escapeHtml(s.name)}</option>`
    ).join('');

    // Build priority dropdown from the active ticket_priorities lookup.
    // A blank option lets the user clear the priority — useful since priority
    // is nullable and not every ticket needs an SLA-driving priority assigned.
    const priorityOptions = ticketPriorities.map(p =>
        `<option value="${p.id}" ${email.priority_id == p.id ? 'selected' : ''}>${escapeHtml(p.name)}</option>`
    ).join('');

    // Build ticket origin dropdown
    const originOptions = ticketOrigins.map(origin =>
        `<option value="${origin.id}" ${email.origin_id == origin.id ? 'selected' : ''}>${escapeHtml(origin.name)}</option>`
    ).join('');

    // Build first time fix dropdown
    const firstTimeFixOptions = `
        <option value="" ${email.first_time_fix === null ? 'selected' : ''}>--</option>
        <option value="1" ${email.first_time_fix === true || email.first_time_fix === 1 ? 'selected' : ''}>${escapeHtml(t('tickets.reading_pane.opt_yes'))}</option>
        <option value="0" ${email.first_time_fix === false || email.first_time_fix === 0 ? 'selected' : ''}>${escapeHtml(t('tickets.reading_pane.opt_no'))}</option>
    `;

    // Build IT training provided dropdown
    const itTrainingOptions = `
        <option value="" ${email.it_training_provided === null ? 'selected' : ''}>--</option>
        <option value="1" ${email.it_training_provided === true || email.it_training_provided === 1 ? 'selected' : ''}>${escapeHtml(t('tickets.reading_pane.opt_yes'))}</option>
        <option value="0" ${email.it_training_provided === false || email.it_training_provided === 0 ? 'selected' : ''}>${escapeHtml(t('tickets.reading_pane.opt_no'))}</option>
    `;

    // Build owner/analyst dropdown
    const ownerOptions = analysts.map(analyst =>
        `<option value="${analyst.id}" ${email.owner_id == analyst.id ? 'selected' : ''}>${escapeHtml(analyst.full_name)}</option>`
    ).join('');

    // Build summary values for collapsed view
    const summaryStatus = email.status || t('tickets.reading_pane.summary_open');
    const summaryOwner = getDisplayName('owner', email.owner_id) || t('tickets.reading_pane.summary_unassigned');
    // Phase 4B: richer at-a-glance summary shown under the links bar.
    const summaryNumber   = email.ticket_number || '—';
    const summaryPriority = getDisplayName('priority', email.priority_id) || t('tickets.reading_pane.summary_none');
    const summaryType     = getDisplayName('ticket_type', email.ticket_type_id) || t('tickets.reading_pane.summary_none');
    const summaryCategory = getDisplayName('category', email.category_id) || t('tickets.reading_pane.summary_none');
    const summaryCustomer = email.company_name || t('tickets.reading_pane.summary_none');

    // When the open ticket is in the trash, lead with a banner offering Restore /
    // Delete forever instead of the usual workflow actions.
    const isTrashed = !!email.deleted_datetime;
    const trashBanner = isTrashed ? `
        <div style="background:#fdeceb;border:1px solid #e6c4c4;border-radius:8px;padding:12px 16px;margin:0 0 14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
            <span style="font-size:18px;">🗑️</span>
            <span style="color:#b71c1c;font-weight:600;flex:1;min-width:180px;">This ticket is in the trash — its actions are disabled until you restore it.</span>
            <button onclick="restoreTicketFromTrash(${email.ticket_id})" style="font-size:12.5px;padding:6px 14px;border:1px solid #c8d6cf;background:#eefaf2;color:#1b7a43;border-radius:5px;cursor:pointer;font-weight:600;">↩ Restore</button>
            <button onclick="permanentlyDeleteFromTrash(${email.ticket_id}, '${escapeHtml(email.ticket_number || '')}')" style="font-size:12.5px;padding:6px 14px;border:1px solid #e6c4c4;background:#fff;color:#b71c1c;border-radius:5px;cursor:pointer;font-weight:600;">✕ Delete forever</button>
        </div>` : '';

    readingPane.innerHTML = trashBanner + (isTrashed ? '<div style="pointer-events:none;opacity:0.55;">' : '') + `
        <div class="ticket-properties-container" id="ticketPropertiesContainer">
            <div class="ticket-properties-header" onclick="toggleTicketProperties(event)">
                <div class="ticket-properties-title">
                    <span class="ticket-properties-chevron">&#9660;</span>
                    ${escapeHtml(t('tickets.reading_pane.properties_title'))}
                </div>
                <div class="ticket-properties-summary">
                    <span class="ticket-properties-summary-item">
                        <span class="ticket-properties-summary-label">${escapeHtml(t('tickets.reading_pane.ticket_label'))}</span>
                        <span class="ticket-properties-summary-value" id="summaryNumber">${escapeHtml(summaryNumber)}</span>
                    </span>
                    <span class="ticket-properties-summary-item">
                        <span class="ticket-properties-summary-label">${escapeHtml(t('tickets.reading_pane.summary_status'))}</span>
                        <span class="ticket-properties-summary-value" id="summaryStatus">${escapeHtml(summaryStatus)}</span>
                    </span>
                    <span class="ticket-properties-summary-item">
                        <span class="ticket-properties-summary-label">${escapeHtml(t('tickets.reading_pane.field_priority'))}</span>
                        <span class="ticket-properties-summary-value" id="summaryPriority">${escapeHtml(summaryPriority)}</span>
                    </span>
                    <span class="ticket-properties-summary-item">
                        <span class="ticket-properties-summary-label">${escapeHtml(t('tickets.reading_pane.field_type'))}</span>
                        <span class="ticket-properties-summary-value" id="summaryType">${escapeHtml(summaryType)}</span>
                    </span>
                    <span class="ticket-properties-summary-item">
                        <span class="ticket-properties-summary-label">${escapeHtml(t('tickets.reading_pane.field_category'))}</span>
                        <span class="ticket-properties-summary-value" id="summaryCategory">${escapeHtml(summaryCategory)}</span>
                    </span>
                    <span class="ticket-properties-summary-item">
                        <span class="ticket-properties-summary-label">${escapeHtml(t('tickets.reading_pane.field_company'))}</span>
                        <span class="ticket-properties-summary-value" id="summaryCustomer">${escapeHtml(summaryCustomer)}</span>
                    </span>
                    <span class="ticket-properties-summary-item">
                        <span class="ticket-properties-summary-label">${escapeHtml(t('tickets.reading_pane.summary_owner'))}</span>
                        <span class="ticket-properties-summary-value" id="summaryOwner">${escapeHtml(summaryOwner)}</span>
                    </span>
                    <span class="sla-chip" id="summarySla" style="display:none;"></span>
                </div>
            </div>
            <div class="ticket-properties-panel">
                <div class="ticket-toolbar">
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_department'))}</label>
                        <select class="toolbar-select" id="departmentSelect" onchange="assignDepartment()">
                            <option value=""></option>
                            ${departmentOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_type'))}</label>
                        <select class="toolbar-select" id="ticketTypeSelect" onchange="assignTicketType()">
                            <option value=""></option>
                            ${ticketTypeOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_category'))}</label>
                        <select class="toolbar-select" id="categorySelect" onchange="assignCategory()">
                            <option value=""></option>
                            ${categoryOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_subcategory'))}</label>
                        <select class="toolbar-select" id="subcategorySelect" onchange="assignSubcategory()" disabled>
                            <option value=""></option>
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_status'))}</label>
                        <select class="toolbar-select" id="statusSelect" onchange="assignStatus()">
                            ${statusOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_priority'))}</label>
                        <select class="toolbar-select" id="prioritySelect" onchange="assignPriority()">
                            <option value=""></option>
                            ${priorityOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_origin'))}</label>
                        <select class="toolbar-select" id="originSelect" onchange="assignOrigin()">
                            <option value=""></option>
                            ${originOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_first_time_fix'))}</label>
                        <select class="toolbar-select" id="firstTimeFixSelect" onchange="assignFirstTimeFix()">
                            ${firstTimeFixOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_it_training'))}</label>
                        <select class="toolbar-select" id="itTrainingSelect" onchange="assignItTraining()">
                            ${itTrainingOptions}
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="toolbar-label">${escapeHtml(t('tickets.reading_pane.field_owner'))}</label>
                        <select class="toolbar-select" id="ownerSelect" onchange="assignOwner()">
                            <option value=""></option>
                            ${ownerOptions}
                        </select>
                    </div>
                    ${companyField}
                </div>
            </div>
        </div>
        ${companyWarningBanner}
        <div class="email-header">
            <div class="email-subject-line">
                <span class="email-subject-text">${escapeHtml(t('tickets.reading_pane.ticket_label'))} ${escapeHtml(email.ticket_number || '')} - ${escapeHtml(email.subject)}</span>
                <button class="icon-btn ticket-popout-toggle" onclick="toggleTicketPopout()" title="${escapeHtml(t('tickets.reading_pane.toggle_fullscreen'))}" aria-label="${escapeHtml(t('tickets.reading_pane.toggle_fullscreen'))}">
                    <svg class="popout-icon-expand" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                    <svg class="popout-icon-contract" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                </button>
            </div>
            <div class="email-meta">
                <div class="email-meta-row">
                    <div class="email-meta-label">${escapeHtml(t('tickets.reading_pane.meta_from'))}</div>
                    <div class="email-meta-value">${escapeHtml(email.from_name)} &lt;${escapeHtml(email.from_address)}&gt;</div>
                </div>
                <div class="email-meta-row">
                    <div class="email-meta-label">${escapeHtml(t('tickets.reading_pane.meta_to'))}</div>
                    <div class="email-meta-value">${escapeHtml(email.to_recipients)}</div>
                    <div class="email-meta-date"><span class="email-meta-date-label">${escapeHtml(t('tickets.reading_pane.meta_date'))}</span> ${formatFullDateTime(email.received_datetime)}</div>
                </div>
                ${email.cc_recipients ? `
                <div class="email-meta-row">
                    <div class="email-meta-label">${escapeHtml(t('tickets.reading_pane.meta_cc'))}</div>
                    <div class="email-meta-value">${escapeHtml(email.cc_recipients)}</div>
                </div>
                ` : ''}
            </div>
        </div>
        <div class="attachment-info-bar" id="attachmentInfoBar" onclick="showAttachmentList()" style="display: none;">
            <span>${escapeHtml(t('tickets.actions.loading_attachments'))}</span>
        </div>
        ${buildLinksSection(email)}
        <div class="ticket-tags-bar" id="ticketTagsContainer"></div>
        <div class="ticket-watchers-bar" id="ticketWatchersContainer"></div>
        ${buildRecordingsStrip(currentRecordings)}
        <div class="action-toolbar">
            <button class="action-btn" onclick="openNoteModal()">
                <span class="action-btn-icon">📝</span>
                <span>${escapeHtml(t('tickets.actions.add_note'))}</span>
            </button>
            <button class="action-btn" onclick="openReplyModal()">
                <span class="action-btn-icon">↩️</span>
                <span>${escapeHtml(t('tickets.actions.reply'))}</span>
            </button>
            <button class="action-btn" onclick="openForwardModal()">
                <span class="action-btn-icon">➡️</span>
                <span>${escapeHtml(t('tickets.actions.forward'))}</span>
            </button>
            <button class="action-btn" onclick="openScheduleModal()">
                <span class="action-btn-icon">📅</span>
                <span>${escapeHtml(t('tickets.actions.schedule'))}</span>
            </button>
            <button class="action-btn" onclick="openTicketAiChat()">
                <span class="action-btn-icon">🤖</span>
                <span>${escapeHtml(t('tickets.actions.ask_ai'))}</span>
            </button>
            <button class="action-btn" onclick="showAuditHistory()">
                <span class="action-btn-icon">📋</span>
                <span>${escapeHtml(t('tickets.actions.audit'))}</span>
            </button>
            <button class="action-btn" onclick="requestCsatSurvey()" title="Send a satisfaction survey to the requester">
                <span class="action-btn-icon">⭐</span>
                <span>${escapeHtml(t('tickets.actions.request_feedback'))}</span>
            </button>
            <button class="action-btn action-btn-danger" onclick="deleteTicket()">
                <span class="action-btn-icon">🗑️</span>
                <span>${escapeHtml(t('tickets.actions.delete'))}</span>
            </button>
        </div>
        <div class="email-body">
            <div id="threadContainer">
                ${emailBodyHost(email.body_content, 'email-body-content')}
            </div>
            <div id="cmdbObjectsContainer"></div>
            <div id="customFieldsContainer"></div>
            <div id="slaContainer"></div>
            <div id="timeEntriesContainer"></div>
            <div id="notesContainer"></div>
        </div>
    ` + (isTrashed ? '</div>' : '');

    // Isolate the just-rendered email body in a shadow root (see emailBodyHost).
    hydrateEmailBodies(readingPane);

    // Phase 4B: relocate the Properties/info panel to sit directly under the
    // links bar (it's authored at the top of the template for variable scope,
    // but reads better below the email header + links).
    const propsPanelEl = readingPane.querySelector('#ticketPropertiesContainer');
    const linksStripEl = readingPane.querySelector('.links-strip');
    if (propsPanelEl && linksStripEl) linksStripEl.after(propsPanelEl);

    // Load full correspondence thread, notes, attachments and linked CMDB objects after rendering
    loadCorrespondenceThread(email.ticket_id);
    loadNotes(email.ticket_id);
    loadTicketAttachments(email.ticket_id);
    loadCmdbObjects(email.ticket_id);
    loadTicketTags(email.ticket_id);
    loadTicketWatchers(email.ticket_id);
    loadTimeEntries(email.ticket_id);
    loadSlaState(email.ticket_id);
    loadTicketCustomFieldsPane(email.ticket_id);

    // Subcategory options depend on the selected category — populate after render.
    populateSubcategorySelect(email.category_id);

    // A ticket is now displayed — apply popout class if the saved pref says so.
    syncPopoutToTicketState(true);
}

// Render the recordings strip that sits between the email header and the action
// toolbar. Returns the empty string when the ticket has no recordings, so the
// gap collapses cleanly. Stream URL is the same endpoint the self-service portal
// uses — auth check inside accepts either a session analyst or the ticket owner.
// The linked-items section (problems + changes + tickets combined) is rendered
// by buildLinksSection() further down; the link/unlink + modal helpers below are
// shared by it and the right-click context menu.

// Right-click "Link to problem…" — targets whichever ticket was right-clicked,
// even if a different one is open in the reading pane.
function openContextLinkProblem() {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    const subj = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId) ? (currentEmail.subject || '') : '';
    openLinkProblemModal(ctxTargetTicketId, ctxTargetTicketRef, subj);
}

let linkProblemTicketId = null;
let linkProblemTicketRef = '';
let linkProblemTicketSubject = '';
let linkProblemSearchTimer = null;

function openLinkProblemModal(ticketId, ticketRef, subject) {
    linkProblemTicketId = ticketId;
    linkProblemTicketRef = ticketRef || ('Ticket ' + ticketId);
    linkProblemTicketSubject = subject || '';
    document.getElementById('linkProblemTicketRef').textContent = linkProblemTicketRef;
    const s = document.getElementById('linkProblemSearch'); if (s) s.value = '';
    document.getElementById('linkProblemModal').classList.add('active');
    loadLinkProblemList();
}
function closeLinkProblemModal() { document.getElementById('linkProblemModal').classList.remove('active'); }
function linkProblemSearchDebounced() { clearTimeout(linkProblemSearchTimer); linkProblemSearchTimer = setTimeout(loadLinkProblemList, 250); }

async function loadLinkProblemList() {
    const list = document.getElementById('linkProblemList');
    const q = (document.getElementById('linkProblemSearch') || {}).value || '';
    list.innerHTML = '<div class="lp-empty">Loading…</div>';
    try {
        const data = await fetch('../api/problem-management/list.php?q=' + encodeURIComponent(q.trim())).then(r => r.json());
        if (!data.success) { list.innerHTML = '<div class="lp-empty">' + escapeHtml(data.error || 'Failed to load') + '</div>'; return; }
        const createRow = `<div class="lp-row lp-create" onclick="createProblemFromIncident()">
            <span class="lp-plus">＋</span><span>Create a new problem from this incident</span></div>`;
        const open = (data.problems || []).filter(p => p.is_closed != 1);
        const rows = open.map(p => `<div class="lp-row" onclick="pickProblem(${p.id})">
            <span class="lp-num">${escapeHtml(p.problem_number || ('#' + p.id))}</span>
            <span class="lp-title">${escapeHtml(p.title || '')}</span>
            <span class="lp-status">${escapeHtml(p.status_name || '')}</span></div>`).join('');
        list.innerHTML = createRow + (rows || '<div class="lp-empty">' + (q.trim() ? 'No matching open problems.' : 'No open problems yet — create one above.') + '</div>');
    } catch (e) { list.innerHTML = '<div class="lp-empty">Failed to load problems</div>'; }
}
function pickProblem(problemId) { doLinkTicketProblem({ problem_id: problemId }); }
async function createProblemFromIncident() {
    try {
        const title = linkProblemTicketSubject || ('Problem from ' + linkProblemTicketRef);
        const cr = await fetch('../api/problem-management/save.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title: title, description: '' })
        }).then(r => r.json());
        if (!cr.success) { showToast('Could not create problem: ' + (cr.error || ''), 'error'); return; }
        doLinkTicketProblem({ problem_id: cr.id });
    } catch (e) { showToast('Failed to create problem', 'error'); }
}
async function doLinkTicketProblem(target) {
    try {
        const res = await fetch('../api/problem-management/link_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ ticket_id: linkProblemTicketId }, target))
        });
        const data = await res.json();
        if (data.success) {
            showToast('Linked to problem', 'success');
            closeLinkProblemModal();
            if (currentEmail && currentEmail.ticket_id == linkProblemTicketId) selectEmail(currentEmail.id);
        } else showToast('Could not link: ' + (data.error || 'unknown error'), 'error');
    } catch (e) { showToast('Failed to link problem', 'error'); }
}
async function unlinkTicketFromProblem(problemId) {
    if (!currentEmail) return;
    try {
        const res = await fetch('../api/problem-management/unlink_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ problem_id: problemId, ticket_id: currentEmail.ticket_id })
        });
        const data = await res.json();
        if (data.success) { showToast('Unlinked', 'success'); selectEmail(currentEmail.id); }
        else showToast(data.error || 'Failed', 'error');
    } catch (e) { showToast('Failed', 'error'); }
}

// Right-click "Link to change…" — targets whichever ticket was right-clicked,
// even if a different one is open in the reading pane.
function openContextLinkChange() {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    const subj = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId) ? (currentEmail.subject || '') : '';
    openLinkChangeModal(ctxTargetTicketId, ctxTargetTicketRef, subj);
}

let linkChangeTicketId = null;
let linkChangeTicketRef = '';
let linkChangeTicketSubject = '';
let linkChangeSearchTimer = null;

function openLinkChangeModal(ticketId, ticketRef, subject) {
    linkChangeTicketId = ticketId;
    linkChangeTicketRef = ticketRef || ('Ticket ' + ticketId);
    linkChangeTicketSubject = subject || '';
    document.getElementById('linkChangeTicketRef').textContent = linkChangeTicketRef;
    const s = document.getElementById('linkChangeSearch'); if (s) s.value = '';
    document.getElementById('linkChangeModal').classList.add('active');
    loadLinkChangeList();
}
function closeLinkChangeModal() { document.getElementById('linkChangeModal').classList.remove('active'); }
function linkChangeSearchDebounced() { clearTimeout(linkChangeSearchTimer); linkChangeSearchTimer = setTimeout(loadLinkChangeList, 250); }

async function loadLinkChangeList() {
    const list = document.getElementById('linkChangeList');
    const q = (document.getElementById('linkChangeSearch') || {}).value || '';
    list.innerHTML = '<div class="lp-empty">Loading…</div>';
    try {
        const data = await fetch('../api/change-management/list.php?search=' + encodeURIComponent(q.trim())).then(r => r.json());
        if (!data.success) { list.innerHTML = '<div class="lp-empty">' + escapeHtml(data.error || 'Failed to load') + '</div>'; return; }
        const createRow = `<div class="lp-row lp-create" onclick="createChangeFromIncident()">
            <span class="lp-plus">＋</span><span>Create a new change from this ticket</span></div>`;
        const rows = (data.changes || []).map(c => {
            const ref = 'CHG-' + String(c.id).padStart(4, '0');
            return `<div class="lp-row" onclick="pickChange(${c.id})">
            <span class="lp-num">${escapeHtml(ref)}</span>
            <span class="lp-title">${escapeHtml(c.title || '')}</span>
            <span class="lp-status">${escapeHtml(c.status || '')}</span></div>`;
        }).join('');
        list.innerHTML = createRow + (rows || '<div class="lp-empty">' + (q.trim() ? 'No matching changes.' : 'No changes yet — create one above.') + '</div>');
    } catch (e) { list.innerHTML = '<div class="lp-empty">Failed to load changes</div>'; }
}
function pickChange(changeId) { doLinkTicketChange(changeId); }
async function createChangeFromIncident() {
    try {
        const title = linkChangeTicketSubject || ('Change from ' + linkChangeTicketRef);
        const cr = await fetch('../api/change-management/save.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title: title })
        }).then(r => r.json());
        if (!cr.success) { showToast('Could not create change: ' + (cr.error || ''), 'error'); return; }
        doLinkTicketChange(cr.change_id);
    } catch (e) { showToast('Failed to create change', 'error'); }
}
async function doLinkTicketChange(changeId) {
    try {
        const res = await fetch('../api/change-management/link_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ change_id: changeId, ticket_id: linkChangeTicketId })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Linked to change', 'success');
            closeLinkChangeModal();
            if (currentEmail && currentEmail.ticket_id == linkChangeTicketId) selectEmail(currentEmail.id);
        } else showToast('Could not link: ' + (data.error || 'unknown error'), 'error');
    } catch (e) { showToast('Failed to link change', 'error'); }
}
async function unlinkTicketFromChange(changeId) {
    if (!currentEmail) return;
    try {
        const res = await fetch('../api/change-management/unlink_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ change_id: changeId, ticket_id: currentEmail.ticket_id })
        });
        const data = await res.json();
        if (data.success) { showToast('Unlinked', 'success'); selectEmail(currentEmail.id); }
        else showToast(data.error || 'Failed', 'error');
    } catch (e) { showToast('Failed', 'error'); }
}

// ============================================================
// Unified "Links" section (#38): one strip with pills for every linked
// problem / change / ticket, plus a single "Link to…" menu. Replaces the three
// separate strips — far better use of space on a wide reading pane.
// ============================================================
function buildLinksSection(email) {
    const pills = [];

    // Problems (⚠) — open in the Problem module.
    (email.problems || []).forEach(p => {
        pills.push(`<a class="pm-ticket-badge" href="../problem-management/index.php?id=${p.id}" target="_blank" title="Problem: ${escapeHtml(p.title || '')}">
            ⚠ ${escapeHtml(p.problem_number || ('#' + p.id))}${p.status ? ' · ' + escapeHtml(p.status) : ''}
            <span class="pm-ticket-unlink" onclick="event.preventDefault();event.stopPropagation();unlinkTicketFromProblem(${p.id});">✕</span>
        </a>`);
    });

    // Changes (🔁) — open in the Change module.
    (email.changes || []).forEach(c => {
        const ref = 'CHG-' + String(c.id).padStart(4, '0');
        pills.push(`<a class="pm-ticket-badge" href="../change-management/index.php?id=${c.id}" target="_blank" title="Change: ${escapeHtml(c.title || '')}">
            🔁 ${escapeHtml(ref)}${c.status ? ' · ' + escapeHtml(c.status) : ''}
            <span class="pm-ticket-unlink" onclick="event.preventDefault();event.stopPropagation();unlinkTicketFromChange(${c.id});">✕</span>
        </a>`);
    });

    // Linked tickets (🔗) — grouped by relation, open in-place.
    const L = email.linked_tickets || {};
    const tp = (item, prefix) => `<a class="pm-ticket-badge" href="#" onclick="event.preventDefault();loadTicketById(${item.ticket_id});" title="${escapeHtml(item.subject || '')}">
        🔗 ${escapeHtml(prefix)} ${escapeHtml(item.ticket_number || ('#' + item.ticket_id))}${item.status ? ' · ' + escapeHtml(item.status) : ''}
        <span class="pm-ticket-unlink" onclick="event.preventDefault();event.stopPropagation();unlinkTicketLink(${item.link_id});">✕</span>
    </a>`;
    if (L.parent) pills.push(tp(L.parent, 'Parent:'));
    (L.children || []).forEach(c => pills.push(tp(c, 'Child:')));
    if (L.duplicate_of) pills.push(tp(L.duplicate_of, 'Duplicate of:'));
    (L.duplicates || []).forEach(d => pills.push(tp(d, 'Duplicate:')));
    (L.related || []).forEach(r => pills.push(tp(r, 'Related:')));

    const body = pills.length
        ? pills.join('')
        : `<span style="color:#9ca3af;font-size:13px;">Not linked to anything yet</span>`;

    return `<div class="problem-strip links-strip">
        <span class="problem-strip-label">Links</span>
        ${body}
        <div class="link-add-wrap">
            <button class="problem-link-btn" onclick="toggleLinkAddMenu(event)">Link to… ▾</button>
            <div class="link-add-menu" id="linkAddMenu">
                <button type="button" onclick="linkAddChoose('problem')">Problem</button>
                <button type="button" onclick="linkAddChoose('change')">Change</button>
                <button type="button" onclick="linkAddChoose('ticket')">Ticket</button>
            </div>
        </div>
    </div>`;
}

// "Link to…" popover: pick Problem / Change / Ticket, then open its picker for
// the open ticket. Closes on the next click anywhere.
function toggleLinkAddMenu(e) {
    e.stopPropagation();
    const m = document.getElementById('linkAddMenu');
    if (!m) return;
    const open = m.classList.toggle('open');
    if (open) setTimeout(() => document.addEventListener('click', closeLinkAddMenu, { once: true }), 0);
}
function closeLinkAddMenu() {
    const m = document.getElementById('linkAddMenu');
    if (m) m.classList.remove('open');
}
function linkAddChoose(kind) {
    closeLinkAddMenu();
    if (!currentEmail) return;
    const id = currentEmail.ticket_id, ref = currentEmail.ticket_number, subj = currentEmail.subject || '';
    if (kind === 'problem') openLinkProblemModal(id, ref, subj);
    else if (kind === 'change') openLinkChangeModal(id, ref, subj);
    else openLinkTicketModal(id, ref, subj);
}

// Right-click "Link to ticket…" — targets whichever ticket was right-clicked.
function openContextLinkTicket() {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    openLinkTicketModal(ctxTargetTicketId, ctxTargetTicketRef, '');
}

let linkTicketSourceId = null;
let linkTicketSourceRef = '';
let linkTicketSearchTimer = null;

function openLinkTicketModal(ticketId, ticketRef, subject) {
    linkTicketSourceId = ticketId;
    linkTicketSourceRef = ticketRef || ('Ticket ' + ticketId);
    document.getElementById('linkTicketRef').textContent = linkTicketSourceRef;
    const rel = document.querySelector('input[name="ticketLinkRelation"][value="related"]');
    if (rel) rel.checked = true;
    const s = document.getElementById('linkTicketSearch'); if (s) s.value = '';
    document.getElementById('linkTicketModal').classList.add('active');
    loadLinkTicketList();
}
function closeLinkTicketModal() { document.getElementById('linkTicketModal').classList.remove('active'); }
function linkTicketSearchDebounced() { clearTimeout(linkTicketSearchTimer); linkTicketSearchTimer = setTimeout(loadLinkTicketList, 250); }

async function loadLinkTicketList() {
    const list = document.getElementById('linkTicketList');
    const q = (document.getElementById('linkTicketSearch') || {}).value || '';
    list.innerHTML = '<div class="lp-empty">Loading…</div>';
    try {
        const data = await fetch('../api/tickets/list_linkable_tickets.php?source_ticket_id=' + encodeURIComponent(linkTicketSourceId) + '&q=' + encodeURIComponent(q.trim())).then(r => r.json());
        if (!data.success) { list.innerHTML = '<div class="lp-empty">' + escapeHtml(data.error || 'Failed to load') + '</div>'; return; }
        const rows = (data.tickets || []).map(tk => `<div class="lp-row" onclick="pickLinkTicket(${tk.id})">
            <span class="lp-num">${escapeHtml(tk.ticket_number || ('#' + tk.id))}</span>
            <span class="lp-title">${escapeHtml(tk.subject || '')}</span>
            <span class="lp-status">${escapeHtml(tk.status || '')}</span></div>`).join('');
        list.innerHTML = rows || '<div class="lp-empty">' + (q.trim() ? 'No matching tickets.' : 'No other tickets found.') + '</div>';
    } catch (e) { list.innerHTML = '<div class="lp-empty">Failed to load tickets</div>'; }
}

async function pickLinkTicket(targetId) {
    const relEl = document.querySelector('input[name="ticketLinkRelation"]:checked');
    const relation = relEl ? relEl.value : 'related';
    try {
        const res = await fetch('../api/tickets/create_ticket_link.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ source_ticket_id: linkTicketSourceId, target_ticket_id: targetId, relation: relation })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Tickets linked', 'success');
            closeLinkTicketModal();
            if (currentEmail && currentEmail.ticket_id == linkTicketSourceId) selectEmail(currentEmail.id);
        } else showToast(data.error || 'Could not link', 'error');
    } catch (e) { showToast('Failed to link tickets', 'error'); }
}

async function unlinkTicketLink(linkId) {
    try {
        const res = await fetch('../api/tickets/delete_ticket_link.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ link_id: linkId })
        });
        const data = await res.json();
        if (data.success) { showToast('Unlinked', 'success'); if (currentEmail) selectEmail(currentEmail.id); }
        else showToast(data.error || 'Failed', 'error');
    } catch (e) { showToast('Failed', 'error'); }
}

function buildRecordingsStrip(recordings) {
    if (!recordings || !recordings.length) return '';
    const cards = recordings.map(r => {
        const url = `../api/self-service/get_recording.php?id=${r.id}`;
        const sizeMb = (r.file_size / 1048576).toFixed(1);
        const durLabel = r.duration_seconds ? formatRecordingDuration(r.duration_seconds) : '';
        const audioLabel = r.has_audio ? ' &middot; with audio' : '';
        return `
            <div class="recording-card">
                <video controls preload="metadata" src="${url}"></video>
                <div class="recording-meta">
                    ${escapeHtml(r.original_filename || 'recording')}
                    &middot; ${sizeMb} MB
                    ${durLabel ? '&middot; ' + durLabel : ''}
                    ${audioLabel}
                </div>
            </div>`;
    }).join('');
    return `
        <div class="recordings-strip">
            <div class="recordings-strip-header">🎥 Screen recordings (${recordings.length})</div>
            ${cards}
        </div>`;
}

function formatRecordingDuration(seconds) {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
}

// Load and display all correspondence for a ticket. isAuto=true marks a 15s
// background refresh (channel tickets) so we don't disturb the analyst's draft.
async function loadCorrespondenceThread(ticketId, isAuto = false) {
    const container = document.getElementById('threadContainer');
    if (!container) { if (isAuto) stopChannelAutoRefresh(); return; }

    try {
        const response = await fetch(`${API_BASE}get_ticket_thread.php?ticket_id=${ticketId}`);
        const data = await response.json();

        // Remember the channel so Reply composes over WhatsApp etc. rather than email.
        currentTicketChannel = data.channel || 'email';
        currentChannelWindowOpen = !!data.window_open;
        currentChannelProvider = data.channel_provider || '';

        // Render the composer on a fresh open, or when the 24h-window state flips —
        // but NOT on every auto-refresh, so an in-progress draft/template isn't wiped.
        if (currentTicketChannel === 'email' || !isAuto || currentChannelWindowOpen !== lastComposerWindowOpen) {
            renderChannelComposer(ticketId);
        }
        lastComposerWindowOpen = currentChannelWindowOpen;

        if (data.success && data.emails && data.emails.length > 0) {
            // Reverse so most recent email is at the top
            const emails = [...data.emails].reverse();
            container.innerHTML = emails.map((e, index) => {
                const isOutbound = e.direction === 'Outbound';
                return `
                    ${index > 0 ? '<div class="thread-separator"></div>' : ''}
                    <div class="thread-meta">
                        <span class="thread-direction-badge ${isOutbound ? 'outbound' : 'inbound'}">${escapeHtml(isOutbound ? t('tickets.reading_pane.badge_sent') : t('tickets.reading_pane.badge_received'))}</span>
                        <strong>${escapeHtml(e.from_name || e.from_address)}</strong>
                        &lt;${escapeHtml(e.from_address)}&gt; &mdash; ${formatFullDateTime(e.received_datetime)}
                    </div>
                    ${emailBodyHost(e.body_content, 'thread-message-body')}
                `;
            }).join('');
            // Isolate each thread body in a shadow root (see emailBodyHost).
            hydrateEmailBodies(container);
        }
    } catch (error) {
        console.error('Error loading thread:', error);
    }

    // Manage the 15s live refresh for channel tickets. Only (re)arm on a fresh open;
    // the timer itself calls back with isAuto=true.
    if (!isAuto) {
        stopChannelAutoRefresh();
        if (currentTicketChannel !== 'email') {
            channelRefreshTimer = setInterval(() => {
                // Stop if the analyst is no longer viewing this ticket's thread.
                if (!document.getElementById('threadContainer')) { stopChannelAutoRefresh(); return; }
                loadCorrespondenceThread(ticketId, true);
                loadTicketAttachments(ticketId); // keep the "N attachments" bar current
            }, 15000);
        }
    }
}

function stopChannelAutoRefresh() {
    if (channelRefreshTimer) {
        clearInterval(channelRefreshTimer);
        channelRefreshTimer = null;
    }
}

// Render (or remove) the inline channel reply composer for WhatsApp-style tickets.
// Email tickets use the existing email modal and get no composer here.
function renderChannelComposer(ticketId) {
    const existing = document.getElementById('channelComposer');
    if (currentTicketChannel === 'email') {
        if (existing) existing.remove();
        return;
    }

    const label = currentTicketChannel === 'whatsapp' ? 'WhatsApp' : currentTicketChannel;

    let inner;
    if (currentChannelWindowOpen) {
        // Inside the 24h window: free-text composer.
        inner = `
            <textarea id="channelComposerText" class="channel-composer-text" rows="3" placeholder="Type your reply…"></textarea>
            <div class="channel-composer-actions">
                <button class="action-btn" onclick="aiSuggestChannelReply(${ticketId})" title="Draft a reply with AI">
                    <span class="action-btn-icon">🤖</span><span>Suggest</span>
                </button>
                <button class="action-btn" onclick="aiSummariseChannel(${ticketId})" title="Summarise this conversation into the ticket">
                    <span class="action-btn-icon">📝</span><span>Summarise</span>
                </button>
                <button class="action-btn action-btn-primary" id="channelSendBtn" onclick="sendChannelMessage(${ticketId})">
                    <span class="action-btn-icon">📤</span><span>Send</span>
                </button>
            </div>`;
    } else {
        // Window closed: only a pre-approved template can re-open the conversation.
        inner = `
            <div class="channel-window-closed">⏳ The 24-hour reply window has closed. Free-text replies are blocked by WhatsApp — send a pre-approved template to re-open the conversation.</div>
            <label class="channel-tpl-label">Template</label>
            <select id="channelTemplateSelect" class="channel-composer-text" onchange="onChannelTemplatePick(${ticketId})">
                <option value="">Loading templates…</option>
            </select>
            <div id="channelTemplateVars"></div>
            <div class="channel-composer-actions">
                <button class="action-btn" onclick="aiSummariseChannel(${ticketId})" title="Summarise this conversation into the ticket">
                    <span class="action-btn-icon">📝</span><span>Summarise</span>
                </button>
                <button class="action-btn action-btn-primary" id="channelSendTplBtn" onclick="sendChannelTemplate(${ticketId})" disabled>
                    <span class="action-btn-icon">📤</span><span>Send template</span>
                </button>
            </div>`;
    }

    const html = `
        <div id="channelComposer" class="channel-composer">
            <div class="channel-composer-head">
                <span class="thread-direction-badge outbound">${escapeHtml(label)}</span>
                <span class="channel-composer-title">Reply to the customer over ${escapeHtml(label)}</span>
            </div>
            ${inner}
        </div>`;

    const body = document.querySelector('.email-body');
    if (!body) return;
    if (existing) {
        existing.outerHTML = html;
    } else {
        body.insertAdjacentHTML('afterbegin', html);
    }

    if (!currentChannelWindowOpen) {
        loadChannelTemplates();
    }
}

// Load the templates matching this channel's provider into the picker.
async function loadChannelTemplates() {
    const sel = document.getElementById('channelTemplateSelect');
    if (!sel) return;
    try {
        const q = currentChannelProvider ? ('?provider=' + encodeURIComponent(currentChannelProvider)) : '';
        const res = await fetch(API_BASE.replace('tickets/', 'messaging/') + 'get_templates.php' + q, { credentials: 'same-origin' });
        const data = await res.json();
        channelTemplates = (data.success && data.templates) ? data.templates : [];
        if (!channelTemplates.length) {
            sel.innerHTML = '<option value="">No templates set up — add them in Settings → Messaging</option>';
            return;
        }
        sel.innerHTML = '<option value="">— choose a template —</option>' +
            channelTemplates.map(t => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');
    } catch (e) {
        sel.innerHTML = '<option value="">Failed to load templates</option>';
    }
}

// When a template is chosen, render an input per {{n}} variable + a live preview.
function onChannelTemplatePick(ticketId) {
    const sel = document.getElementById('channelTemplateSelect');
    const varsEl = document.getElementById('channelTemplateVars');
    const sendBtn = document.getElementById('channelSendTplBtn');
    const tpl = channelTemplates.find(t => String(t.id) === String(sel.value));
    if (!tpl) { varsEl.innerHTML = ''; if (sendBtn) sendBtn.disabled = true; return; }

    let fields = '';
    for (let i = 1; i <= (tpl.var_count || 0); i++) {
        fields += `<input type="text" class="channel-composer-text channel-tpl-var" data-idx="${i}" placeholder="Value for {{${i}}}" oninput="updateChannelTemplatePreview()" style="margin-top:6px;">`;
    }
    varsEl.innerHTML = `
        ${fields}
        <div class="channel-tpl-preview" id="channelTemplatePreview"></div>`;
    if (sendBtn) sendBtn.disabled = false;
    updateChannelTemplatePreview();
}

// Live preview of the rendered template (placeholders filled in).
function updateChannelTemplatePreview() {
    const sel = document.getElementById('channelTemplateSelect');
    const tpl = channelTemplates.find(t => String(t.id) === String(sel && sel.value));
    const prev = document.getElementById('channelTemplatePreview');
    if (!tpl || !prev) return;
    const vals = Array.from(document.querySelectorAll('.channel-tpl-var')).map(i => i.value);
    let body = tpl.body.replace(/\{\{\s*(\d+)\s*\}\}/g, (m, n) => vals[parseInt(n, 10) - 1] || m);
    prev.textContent = body;
}

// Send the chosen template.
async function sendChannelTemplate(ticketId) {
    const sel = document.getElementById('channelTemplateSelect');
    const btn = document.getElementById('channelSendTplBtn');
    if (!sel || !sel.value) { showToast('Choose a template first', 'error'); return; }
    const vars = Array.from(document.querySelectorAll('.channel-tpl-var')).map(i => i.value.trim());
    if (vars.some(v => v === '')) { showToast('Fill in all template values', 'error'); return; }

    const original = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<span>Sending…</span>'; }
    try {
        const res = await fetch(API_BASE.replace('tickets/', 'messaging/') + 'send_template.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ ticket_id: ticketId, template_id: parseInt(sel.value, 10), vars })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Template sent', 'success');
            loadCorrespondenceThread(ticketId);
        } else {
            showToast('Could not send: ' + (data.error || 'unknown error'), 'error');
        }
    } catch (e) {
        showToast('Failed to send template', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = original; }
    }
}

// Send the analyst's reply out over the ticket's channel.
async function sendChannelMessage(ticketId) {
    const ta = document.getElementById('channelComposerText');
    const btn = document.getElementById('channelSendBtn');
    if (!ta) return;
    const body = ta.value.trim();
    if (!body) { showToast('Type a message first', 'error'); return; }

    const original = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<span>Sending…</span>'; }
    try {
        const res = await fetch(API_BASE.replace('tickets/', 'messaging/') + 'send_message.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ ticket_id: ticketId, body })
        });
        const data = await res.json();
        if (data.success) {
            ta.value = '';
            showToast('Message sent', 'success');
            loadCorrespondenceThread(ticketId);
        } else {
            showToast('Could not send: ' + (data.error || 'unknown error'), 'error');
        }
    } catch (e) {
        showToast('Failed to send message', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = original; }
    }
}

// AI: draft a suggested reply into the composer (analyst reviews before sending).
async function aiSuggestChannelReply(ticketId) {
    const ta = document.getElementById('channelComposerText');
    if (!ta || ta.disabled) return;
    showToast('Drafting a reply…', 'info');
    try {
        const res = await fetch(API_BASE.replace('tickets/', 'messaging/') + 'ai_suggest_reply.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ ticket_id: ticketId })
        });
        const data = await res.json();
        if (data.success && data.reply) {
            ta.value = data.reply;
            ta.focus();
        } else {
            showToast(data.error || 'Could not draft a reply', 'error');
        }
    } catch (e) {
        showToast('Failed to draft a reply', 'error');
    }
}

// AI: summarise the conversation and save it as an internal note on the ticket.
async function aiSummariseChannel(ticketId) {
    showToast('Summarising…', 'info');
    try {
        const res = await fetch(API_BASE.replace('tickets/', 'messaging/') + 'ai_summary.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ ticket_id: ticketId })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Summary added to ticket notes', 'success');
            if (typeof loadNotes === 'function') loadNotes(ticketId);
        } else {
            showToast(data.error || 'Could not summarise', 'error');
        }
    } catch (e) {
        showToast('Failed to summarise', 'error');
    }
}

// Assign department
async function assignDepartment() {
    const departmentId = document.getElementById('departmentSelect').value;
    const oldValue = getDisplayName('department', currentEmail.department_id);
    const newValue = getDisplayName('department', departmentId);

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                department_id: departmentId || null
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Department', oldValue, newValue);
            currentEmail.department_id = departmentId || null;
            updatePropertiesSummary();
            loadFolderCounts();
            loadEmails();
        } else {
            showToast('Error assigning department: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign department', 'error');
    }
}

// Assign ticket type
async function assignTicketType() {
    const ticketTypeId = document.getElementById('ticketTypeSelect').value;
    const oldValue = getDisplayName('ticket_type', currentEmail.ticket_type_id);
    const newValue = getDisplayName('ticket_type', ticketTypeId);

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                ticket_type_id: ticketTypeId || null
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Ticket Type', oldValue, newValue);
            currentEmail.ticket_type_id = ticketTypeId || null;
        } else {
            showToast('Error assigning ticket type: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign ticket type', 'error');
    }
}

// Assign category — clears subcategory (which belongs to the old category)
// and repopulates the subcategory picker for the new category.
async function assignCategory() {
    const categoryId = document.getElementById('categorySelect').value;
    const oldValue = getDisplayName('category', currentEmail.category_id);
    const newValue = getDisplayName('category', categoryId);
    const oldSubValue = getDisplayName('subcategory', currentEmail.subcategory_id);

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                category_id: categoryId || null,
                subcategory_id: null
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Category', oldValue, newValue);
            if (currentEmail.subcategory_id) {
                await logAudit(currentEmail.ticket_id, 'Subcategory', oldSubValue, null);
            }
            currentEmail.category_id = categoryId || null;
            currentEmail.subcategory_id = null;
            await populateSubcategorySelect(categoryId);
        } else {
            showToast('Error assigning category: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign category', 'error');
    }
}

// Assign subcategory
async function assignSubcategory() {
    const subcategoryId = document.getElementById('subcategorySelect').value;
    const oldValue = getDisplayName('subcategory', currentEmail.subcategory_id);
    const newValue = getDisplayName('subcategory', subcategoryId);

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                subcategory_id: subcategoryId || null
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Subcategory', oldValue, newValue);
            currentEmail.subcategory_id = subcategoryId || null;
        } else {
            showToast('Error assigning subcategory: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign subcategory', 'error');
    }
}

// (Re)populate the reading-pane subcategory select for the given category,
// preselecting currentEmail.subcategory_id if it's still valid for it.
async function populateSubcategorySelect(categoryId) {
    const sel = document.getElementById('subcategorySelect');
    if (!sel) return;
    if (!categoryId) {
        sel.innerHTML = '<option value=""></option>';
        sel.disabled = true;
        return;
    }
    const subs = await loadTicketSubcategories(categoryId);
    sel.disabled = false;
    sel.innerHTML = '<option value=""></option>' + subs.map(s =>
        `<option value="${s.id}" ${currentEmail && currentEmail.subcategory_id == s.id ? 'selected' : ''}>${escapeHtml(s.name)}</option>`
    ).join('');
}

// ===== Ticket custom fields (dynamically rendered on the reading pane) =====
// Fetch the tenant's custom-field definitions + this ticket's values in one call
// and render one type-appropriate input per field. Each input saves on change.
async function loadTicketCustomFieldsPane(ticketId) {
    const container = document.getElementById('customFieldsContainer');
    if (!container) return;
    try {
        const r = await fetch(API_BASE + 'get_ticket_custom_fields.php?ticket_id=' + encodeURIComponent(ticketId));
        const d = await r.json();
        if (!d.success || !d.custom_fields || !d.custom_fields.length) {
            container.innerHTML = '';
            return;
        }
        container.innerHTML = renderTicketCustomFields(d.custom_fields);
    } catch (e) {
        container.innerHTML = '';
    }
}

function renderTicketCustomFields(fields) {
    const rows = fields.map(f => {
        const reqStar = f.is_required ? ' <span style="color:#dc2626;">*</span>' : '';
        return `
            <div class="toolbar-field" style="margin-bottom:10px;">
                <label class="toolbar-label">${escapeHtml(f.label)}${reqStar}</label>
                ${renderCustomFieldInput(f)}
            </div>`;
    }).join('');
    return `
        <div class="ticket-custom-fields" style="margin-top:16px;padding:14px 16px;border:1px solid var(--border-soft,#e3e8ea);border-radius:8px;">
            <div style="font-weight:600;font-size:13px;color:#455a64;margin-bottom:12px;">${escapeHtml(t('tickets.custom_fields.section_pane_title'))}</div>
            ${rows}
        </div>`;
}

function renderCustomFieldInput(f) {
    const key = escapeHtml(f.field_key);
    const onchg = `onchange="saveTicketCustomField('${key}', this)"`;
    switch (f.field_type) {
        case 'number':
            return `<input type="number" class="toolbar-select" data-cf-key="${key}" value="${f.value !== null && f.value !== undefined ? escapeHtml(String(f.value)) : ''}" ${onchg}>`;
        case 'date': {
            const dateVal = f.value ? String(f.value).substring(0, 10) : '';
            return `<input type="date" class="toolbar-select" data-cf-key="${key}" value="${escapeHtml(dateVal)}" ${onchg}>`;
        }
        case 'boolean':
            return `<select class="toolbar-select" data-cf-key="${key}" ${onchg}>
                <option value="" ${f.value === null || f.value === undefined ? 'selected' : ''}>--</option>
                <option value="1" ${f.value === true ? 'selected' : ''}>${escapeHtml(t('tickets.reading_pane.opt_yes'))}</option>
                <option value="0" ${f.value === false ? 'selected' : ''}>${escapeHtml(t('tickets.reading_pane.opt_no'))}</option>
            </select>`;
        case 'dropdown': {
            const opts = (f.options || []).map(o =>
                `<option value="${escapeHtml(o.value)}" ${f.value === o.value ? 'selected' : ''}>${escapeHtml(o.value)}</option>`
            ).join('');
            return `<select class="toolbar-select" data-cf-key="${key}" ${onchg}><option value=""></option>${opts}</select>`;
        }
        case 'object_ref': {
            const hint = f.value_object ? ` <span style="color:#90a4ae;font-size:12px;">(${escapeHtml(f.value_object.name || '')})</span>` : '';
            return `<input type="number" class="toolbar-select" data-cf-key="${key}" placeholder="CMDB object id" value="${f.value !== null && f.value !== undefined ? escapeHtml(String(f.value)) : ''}" ${onchg}>${hint}`;
        }
        case 'text':
        default:
            return `<input type="text" class="toolbar-select" data-cf-key="${key}" value="${f.value !== null && f.value !== undefined ? escapeHtml(String(f.value)) : ''}" ${onchg}>`;
    }
}

// Save one custom-field value for the current ticket. Sends the raw value keyed
// by field_key; the server validates by type and audits the change.
async function saveTicketCustomField(fieldKey, inputEl) {
    if (!currentEmail) return;
    const value = inputEl.value === '' ? null : inputEl.value;
    try {
        const r = await fetch(API_BASE + 'save_ticket_custom_field_values.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: currentEmail.ticket_id, values: { [fieldKey]: value } })
        });
        const d = await r.json();
        if (!d.success) {
            showToast('Error saving field: ' + d.error, 'error');
            // Reload to reflect the true stored state after a rejected value.
            loadTicketCustomFieldsPane(currentEmail.ticket_id);
        }
    } catch (e) {
        showToast('Failed to save field', 'error');
    }
}

// Assign status
async function assignStatus() {
    const status = document.getElementById('statusSelect').value;
    const oldValue = currentEmail.status;

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                status: status
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Status', oldValue, status);
            currentEmail.status = status;
            updatePropertiesSummary();
            loadFolderCounts();
            loadEmails();
        } else {
            showToast('Error assigning status: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign status', 'error');
    }
}

// Assign priority. Sends priority_id (or null for the "no priority" blank
// option) to assign_ticket.php; the SLA engine recomputes lazily on next
// read, so we don't need a separate recompute call here.
async function assignPriority() {
    const priorityId = document.getElementById('prioritySelect').value;
    const oldPriority = ticketPriorities.find(p => p.id == currentEmail.priority_id);
    const newPriority = ticketPriorities.find(p => p.id == priorityId);
    const oldLabel = oldPriority ? oldPriority.name : '';
    const newLabel = newPriority ? newPriority.name : '';

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                priority_id: priorityId === '' ? null : priorityId,
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Priority', oldLabel, newLabel);
            currentEmail.priority_id = priorityId === '' ? null : Number(priorityId);
            currentEmail.priority    = newLabel;
            updatePropertiesSummary();
            loadEmails();
        } else {
            showToast('Error assigning priority: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign priority', 'error');
    }
}

// Assign origin
async function assignOrigin() {
    const originId = document.getElementById('originSelect').value;
    const oldValue = getDisplayName('origin', currentEmail.origin_id);
    const newValue = getDisplayName('origin', originId);

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                origin_id: originId || null
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Origin', oldValue, newValue);
            currentEmail.origin_id = originId || null;
        } else {
            showToast('Error assigning origin: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign origin', 'error');
    }
}

// Assign first time fix
async function assignFirstTimeFix() {
    const value = document.getElementById('firstTimeFixSelect').value;
    const oldValue = currentEmail.first_time_fix === null ? null : (currentEmail.first_time_fix ? 'Yes' : 'No');
    const newValue = value === '' ? null : (value === '1' ? 'Yes' : 'No');

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                first_time_fix: value === '' ? null : (value === '1' ? 1 : 0)
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'First Time Fix', oldValue, newValue);
            currentEmail.first_time_fix = value === '' ? null : (value === '1');
        } else {
            showToast('Error assigning first time fix: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign first time fix', 'error');
    }
}

// Assign IT training provided
async function assignItTraining() {
    const value = document.getElementById('itTrainingSelect').value;
    const oldValue = currentEmail.it_training_provided === null ? null : (currentEmail.it_training_provided ? 'Yes' : 'No');
    const newValue = value === '' ? null : (value === '1' ? 'Yes' : 'No');

    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                it_training_provided: value === '' ? null : (value === '1' ? 1 : 0)
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'IT Training', oldValue, newValue);
            currentEmail.it_training_provided = value === '' ? null : (value === '1');
        } else {
            showToast('Error assigning IT training: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign IT training', 'error');
    }
}

// Assign owner (analyst)
async function assignOwner() {
    const ownerId = document.getElementById('ownerSelect').value;
    const oldValue = getDisplayName('owner', currentEmail.owner_id);
    const newValue = getDisplayName('owner', ownerId);

    try {
        const response = await fetch(API_BASE + 'update_ticket_owner.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                owner_id: ownerId || null
            })
        });
        const data = await response.json();

        if (data.success) {
            await logAudit(currentEmail.ticket_id, 'Owner', oldValue, newValue);
            currentEmail.owner_id = ownerId || null;
            updatePropertiesSummary();
        } else {
            showToast('Error assigning owner: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to assign owner', 'error');
    }
}

// Delete ticket
async function requestCsatSurvey() {
    if (!currentEmail || !currentEmail.ticket_id) {
        showToast('No ticket selected', 'error');
        return;
    }
    if (!(await showConfirm({ title: 'Confirm', message: 'Send a satisfaction survey email to the requester?', okLabel: 'OK', okClass: 'primary' }))) return;
    try {
        const res = await fetch(`${API_BASE}request_csat.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: currentEmail.ticket_id })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Survey email sent.', 'error');
        } else {
            showToast('Could not send survey: ' + (data.error || 'unknown error'), 'error');
        }
    } catch (err) {
        showToast('Failed: ' + err.message, 'error');
    }
}

async function deleteTicket() {
    if (!currentEmail || !currentEmail.ticket_id) {
        showToast('No ticket selected', 'error');
        return;
    }

    if (!(await showConfirm({ title: 'Move to trash', message: 'Move this ticket to the trash? You can restore it from the Trash folder.', okLabel: 'Move to trash', okClass: 'danger' }))) return;

    try {
        const response = await fetch(API_BASE + 'delete_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id
            })
        });
        const data = await response.json();

        if (data.success) {
            // Clear current selection
            currentEmail = null;
            selectedEmailId = null;

            // Clear reading pane
            document.getElementById('readingPane').innerHTML = '<div class="reading-pane-empty">Select an email to read</div>';

            showToast('Moved to trash', 'success');
            // Refresh folder counts and email list
            loadFolderCounts();
            loadEmails();
        } else {
            showToast('Error moving ticket to trash: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to delete ticket', 'error');
    }
}

// Restore a ticket from the Trash folder.
async function restoreTicketFromTrash(ticketId) {
    try {
        const res = await fetch(API_BASE + 'restore_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ticketId })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Ticket restored', 'success');
            clearReadingPaneIfTicket(ticketId);
            loadFolderCounts();
            loadEmails();
        } else {
            showToast('Restore failed: ' + data.error, 'error');
        }
    } catch (e) { showToast('Restore failed', 'error'); }
}

// Clear the reading pane if it's showing the given ticket (it just left the trash).
function clearReadingPaneIfTicket(ticketId) {
    if (currentEmail && currentEmail.ticket_id == ticketId) {
        currentEmail = null;
        selectedEmailId = null;
        document.getElementById('readingPane').innerHTML = '<div class="reading-pane-empty">Select an email to read</div>';
    }
}

// Permanently delete a ticket from the Trash folder (irreversible).
async function permanentlyDeleteFromTrash(ticketId, ticketNumber) {
    if (!(await showConfirm({
        title: 'Delete permanently',
        message: `Permanently delete ticket ${ticketNumber || ''} and all its emails, attachments and notes? This cannot be undone.`,
        okLabel: 'Delete permanently', okClass: 'danger'
    }))) return;
    try {
        const res = await fetch(API_BASE + 'permanently_delete_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ticketId })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Ticket permanently deleted', 'success');
            clearReadingPaneIfTicket(ticketId);
            loadFolderCounts();
            loadEmails();
        } else {
            showToast('Delete failed: ' + data.error, 'error');
        }
    } catch (e) { showToast('Delete failed', 'error'); }
}

// Show audit history modal
async function showAuditHistory() {
    if (!currentEmail || !currentEmail.ticket_id) {
        showToast('No ticket selected', 'error');
        return;
    }

    try {
        const response = await fetch(`${API_BASE}get_ticket_audit.php?ticket_id=${currentEmail.ticket_id}`);
        const data = await response.json();

        if (data.success) {
            const auditHtml = data.audit.length === 0
                ? '<p style="text-align: center; color: #888;">No audit history for this ticket.</p>'
                : `<table class="audit-table">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Analyst</th>
                            <th>Field</th>
                            <th>Old Value</th>
                            <th>New Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.audit.map(entry => `
                            <tr>
                                <td>${formatFullDateTime(entry.created_datetime)}</td>
                                <td>${escapeHtml(entry.analyst_name || 'Unknown')}</td>
                                <td>${escapeHtml(entry.field_name)}</td>
                                <td>${escapeHtml(entry.old_value || '-')}</td>
                                <td>${escapeHtml(entry.new_value || '-')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>`;

            // Create modal
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.id = 'auditModal';
            modal.innerHTML = `
                <div class="modal-content audit-modal">
                    <div class="modal-header">
                        <h3>Audit History - ${escapeHtml(currentEmail.ticket_number)}</h3>
                        <button class="modal-close" onclick="closeAuditModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        ${auditHtml}
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
        } else {
            showToast('Error loading audit history: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to load audit history', 'error');
    }
}

// Close audit modal
function closeAuditModal() {
    const modal = document.getElementById('auditModal');
    if (modal) {
        modal.remove();
    }
}

// Refresh current view
function refreshCurrentView() {
    loadFolderCounts();
    if (currentFilter.type !== 'none') {
        loadEmails();
    }
}

// Utility: Escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Utility: Format date/time
// Parse a DB datetime string as UTC (append Z if no timezone indicator)
function parseUTCDate(dateStr) {
    if (!dateStr) return null;
    // If the string doesn't already end with Z or have a timezone offset, treat as UTC
    if (!/[Z+\-]\d{0,4}$/.test(dateStr)) {
        dateStr = dateStr.replace(' ', 'T') + 'Z';
    }
    return new Date(dateStr);
}

// Merge the analyst's chosen display timezone into an Intl options object.
// window.USER_TIMEZONE is published server-side (Tz::scriptTag). When it's
// unset the option is omitted, so dates fall back to the browser's own zone.
function tzOpts(extra) {
    const o = Object.assign({}, extra || {});
    if (window.USER_TIMEZONE) o.timeZone = window.USER_TIMEZONE;
    return o;
}

// 'YYYY-MM-DD' for a Date, evaluated in the analyst's display zone. Used to
// bucket into Today / Yesterday against the same zone the time is shown in.
function ymdInZone(date) {
    return date.toLocaleDateString('en-CA', tzOpts());
}

function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const date = parseUTCDate(dateStr);
    const now = new Date();
    const todayStr = ymdInZone(now);
    const yesterdayStr = ymdInZone(new Date(now.getTime() - 86400000));
    const dateYmd = ymdInZone(date);

    if (dateYmd === todayStr) {
        return date.toLocaleTimeString('en-US', tzOpts({ hour: '2-digit', minute: '2-digit' }));
    } else if (dateYmd === yesterdayStr) {
        return 'Yesterday ' + date.toLocaleTimeString('en-US', tzOpts({ hour: '2-digit', minute: '2-digit' }));
    } else {
        return date.toLocaleDateString('en-US', tzOpts({ month: 'short', day: 'numeric' })) + ' ' +
               date.toLocaleTimeString('en-US', tzOpts({ hour: '2-digit', minute: '2-digit' }));
    }
}

// Utility: Format full date/time (always shows date and time)
function formatFullDateTime(dateStr) {
    if (!dateStr) return '';
    const date = parseUTCDate(dateStr);
    return date.toLocaleDateString('en-GB', tzOpts({
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric'
    })) + ' ' + date.toLocaleTimeString('en-US', tzOpts({ hour: '2-digit', minute: '2-digit' }));
}

// Format a NAIVE wall-clock datetime (a user-entered scheduling value stored
// WITHOUT a zone, e.g. a ticket's scheduled work-start) exactly as typed — no
// timezone conversion, so "2pm" reads 2pm for every analyst. Scheduling times
// are naive; only server-stamped UTC timestamps (received/created/…) convert.
function formatNaiveFullDateTime(dateStr) {
    if (!dateStr) return '';
    const m = String(dateStr).replace('T', ' ').match(/(\d{4})-(\d{2})-(\d{2})[ ](\d{1,2}):(\d{2})/);
    if (!m) return dateStr;
    const d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
    return d.toLocaleDateString('en-GB', {
        weekday: 'short', day: 'numeric', month: 'short', year: 'numeric'
    }) + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}

// Toggle ticket properties panel
function toggleTicketProperties(event) {
    event.stopPropagation();
    const container = document.getElementById('ticketPropertiesContainer');
    if (container) {
        container.classList.toggle('expanded');
    }
}

// Close ticket properties panel when clicking outside
document.addEventListener('click', function(event) {
    const container = document.getElementById('ticketPropertiesContainer');
    if (container && container.classList.contains('expanded')) {
        // Check if click is outside the properties container
        if (!container.contains(event.target)) {
            container.classList.remove('expanded');
        }
    }
});

// Update summary values when properties change. Mirrors the collapsed-summary
// fields (Phase 4B set: Status, Priority, Type, Category, Customer, Owner) so an
// inline edit reflects immediately without reloading the ticket.
function updatePropertiesSummary() {
    if (!currentEmail) return;
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('summaryStatus',   currentEmail.status || t('tickets.reading_pane.summary_open'));
    set('summaryPriority', getDisplayName('priority', currentEmail.priority_id) || t('tickets.reading_pane.summary_none'));
    set('summaryType',     getDisplayName('ticket_type', currentEmail.ticket_type_id) || t('tickets.reading_pane.summary_none'));
    set('summaryCategory', getDisplayName('category', currentEmail.category_id) || t('tickets.reading_pane.summary_none'));
    set('summaryCustomer', currentEmail.company_name || t('tickets.reading_pane.summary_none'));
    set('summaryOwner',    getDisplayName('owner', currentEmail.owner_id) || t('tickets.reading_pane.summary_unassigned'));
}

// ===== Linked CMDB objects on a ticket =====
// Renders an "Affected CMDB" section in the reading pane below the email
// thread. Click + Link to add (autocomplete searches every CMDB object);
// X on a card removes the link.

let cmdbObjectsForTicket = [];
let cmdbAcTimer = null;
let cmdbAcHighlightedIdx = -1;

async function loadCmdbObjects(ticketId) {
    const container = document.getElementById('cmdbObjectsContainer');
    if (!container) return;
    container.innerHTML = '';
    try {
        const res = await fetch('../api/tickets/get_ticket_cmdb_objects.php?ticket_id=' + ticketId);
        const data = await res.json();
        if (!data.success) return;
        cmdbObjectsForTicket = data.links || [];
        renderCmdbObjects(ticketId);
    } catch (e) { /* silent — section will just stay empty */ }
}

function renderCmdbObjects(ticketId) {
    const container = document.getElementById('cmdbObjectsContainer');
    if (!container) return;
    const multiple = cmdbObjectsForTicket.length > 1;
    const cards = cmdbObjectsForTicket.map(link => `
        <a class="cmdb-link-card${link.is_primary ? ' is-primary' : ''}" href="../cmdb/object.php?id=${link.object_id}" title="Open in CMDB">
            <div class="cmdb-link-card-body">
                <div class="cmdb-link-card-name">${link.is_primary ? '<span class="cmdb-primary-star" title="Primary CI — drives this ticket\'s SLA">★</span>' : ''}${escapeHtml(link.name)}</div>
                <div class="cmdb-link-card-meta">
                    <span class="cmdb-class-badge">${escapeHtml(link.class_name)}</span>
                    ${link.parent_name ? `<span class="cmdb-parent">in <strong>${escapeHtml(link.parent_name)}</strong> (${escapeHtml(link.parent_class_name || '')})</span>` : ''}
                    ${link.sla_policy_name ? `<span class="cmdb-sla-badge" title="This device carries its own SLA policy">SLA: ${escapeHtml(link.sla_policy_name)}</span>` : ''}
                </div>
            </div>
            <div class="cmdb-link-actions">
                ${link.is_primary
                    ? '<span class="cmdb-primary-pill" title="Primary CI — its SLA policy drives this ticket">Primary</span>'
                    : (multiple ? `<button class="cmdb-set-primary" title="Make this the primary CI (drives the ticket's SLA)" onclick="setPrimaryCmdbObject(event, ${link.link_id}, ${ticketId})">Make primary</button>` : '')}
                <button class="cmdb-link-x" title="${escapeHtml(t('tickets.cmdb.unlink_title'))}" onclick="removeCmdbObject(event, ${link.link_id}, ${ticketId})">×</button>
            </div>
        </a>
    `).join('');

    container.innerHTML = `
        <div class="cmdb-section">
            <div class="cmdb-section-head">
                <h3>${escapeHtml(t('tickets.cmdb.section_title'))}</h3>
                <button class="btn-link" onclick="openLinkCmdbPicker(${ticketId})">${escapeHtml(t('tickets.cmdb.link_btn'))}</button>
            </div>
            ${cmdbObjectsForTicket.length === 0
                ? `<div class="cmdb-empty">${escapeHtml(t('tickets.cmdb.empty'))}</div>`
                : `<div class="cmdb-link-list">${cards}</div>`}
            <div class="cmdb-picker" id="cmdbPicker_${ticketId}" style="display:none;">
                <input type="text" id="cmdbPickerInput_${ticketId}" placeholder="${escapeHtml(t('tickets.cmdb.search_placeholder'))}" autocomplete="off">
                <div class="cmdb-picker-results" id="cmdbPickerResults_${ticketId}"></div>
            </div>
        </div>
    `;
}

function openLinkCmdbPicker(ticketId) {
    const picker = document.getElementById('cmdbPicker_' + ticketId);
    const input  = document.getElementById('cmdbPickerInput_' + ticketId);
    const results = document.getElementById('cmdbPickerResults_' + ticketId);
    if (!picker || !input) return;
    picker.style.display = 'block';
    input.value = '';
    results.classList.remove('active');
    input.focus();

    let current = [];
    cmdbAcHighlightedIdx = -1;

    const renderResults = () => {
        if (current.length === 0) {
            results.innerHTML = `<div class="cmdb-picker-empty">${escapeHtml(t('tickets.cmdb.no_matches'))}</div>`;
            results.classList.add('active');
            return;
        }
        results.innerHTML = current.map((r, i) => `
            <div class="cmdb-picker-result ${i === cmdbAcHighlightedIdx ? 'highlighted' : ''}" data-idx="${i}">
                <span>${escapeHtml(r.name)}</span>
                <span class="cmdb-picker-class">${escapeHtml(r.class_name)}</span>
            </div>`).join('');
        results.classList.add('active');
        results.querySelectorAll('.cmdb-picker-result').forEach(el => {
            el.addEventListener('mousedown', e => {
                e.preventDefault();
                pick(current[parseInt(el.dataset.idx, 10)]);
            });
        });
    };

    const pick = async (r) => {
        try {
            const res = await fetch('../api/tickets/save_ticket_cmdb_object.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ticket_id: ticketId, cmdb_object_id: r.id })
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Link failed');
            if (data.already_linked) {
                showToast(t('tickets.cmdb.already_linked', { name: r.name }), 'error');
            } else {
                showToast(t('tickets.cmdb.linked_toast', { name: r.name }), 'success');
            }
            picker.style.display = 'none';
            await loadCmdbObjects(ticketId);
            // A newly linked CI may become primary and pull the ticket onto a
            // device SLA — refresh the panel so the change is visible at once.
            if (typeof loadSlaState === 'function') loadSlaState(ticketId);
        } catch (err) {
            showToast('Error: ' + err.message, 'error');
        }
    };

    input.oninput = () => {
        const q = input.value.trim();
        if (cmdbAcTimer) clearTimeout(cmdbAcTimer);
        if (q === '') { results.classList.remove('active'); return; }
        cmdbAcTimer = setTimeout(async () => {
            try {
                const url = '../api/cmdb/search_objects.php?q=' + encodeURIComponent(q);
                const res = await fetch(url);
                const data = await res.json();
                current = data.success ? (data.results || []) : [];
                cmdbAcHighlightedIdx = -1;
                renderResults();
            } catch (e) { /* silent */ }
        }, 200);
    };

    input.onkeydown = e => {
        if (!results.classList.contains('active')) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); cmdbAcHighlightedIdx = Math.min(current.length - 1, cmdbAcHighlightedIdx + 1); renderResults(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); cmdbAcHighlightedIdx = Math.max(0, cmdbAcHighlightedIdx - 1); renderResults(); }
        else if (e.key === 'Enter' && cmdbAcHighlightedIdx >= 0) { e.preventDefault(); pick(current[cmdbAcHighlightedIdx]); }
        else if (e.key === 'Escape') { picker.style.display = 'none'; }
    };
}

async function removeCmdbObject(ev, linkId, ticketId) {
    ev.preventDefault();
    ev.stopPropagation();
    if (!(await showConfirm({ title: 'Confirm', message: t('tickets.cmdb.unlink_confirm'), okLabel: 'OK', okClass: 'primary' }))) return;
    try {
        const res = await fetch('../api/tickets/delete_ticket_cmdb_object.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ link_id: linkId })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Unlink failed');
        showToast(t('tickets.cmdb.unlinked_toast'), 'success');
        await loadCmdbObjects(ticketId);
        // Removing the primary CI may re-resolve the ticket's SLA — refresh it.
        if (typeof loadSlaState === 'function') loadSlaState(ticketId);
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    }
}

// Mark a linked CI as the ticket's primary — the CI whose SLA policy drives the
// ticket. Refreshes both the CI list (primary badge) and the SLA panel.
async function setPrimaryCmdbObject(ev, linkId, ticketId) {
    ev.preventDefault();
    ev.stopPropagation();
    try {
        const res = await fetch('../api/tickets/set_ticket_cmdb_primary.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ link_id: linkId })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to set primary');
        showToast('Primary CI updated', 'success');
        await loadCmdbObjects(ticketId);
        if (typeof loadSlaState === 'function') loadSlaState(ticketId);
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    }
}

// Load notes for a ticket
async function loadNotes(ticketId) {
    try {
        const response = await fetch(`${API_BASE}get_notes.php?ticket_id=${ticketId}`);
        const data = await response.json();

        if (data.success) {
            currentNotes = data.notes;
            renderNotes();
        }
    } catch (error) {
        console.error('Error loading notes:', error);
    }
}

// Load attachments for a ticket
async function loadTicketAttachments(ticketId) {
    try {
        const response = await fetch(`${API_BASE}get_ticket_attachments.php?ticket_id=${ticketId}`);
        const data = await response.json();

        if (data.success) {
            ticketAttachments = data.attachments;
            renderAttachmentInfoBar();
        }
    } catch (error) {
        console.error('Error loading attachments:', error);
    }
}

// Render the attachment info bar
function renderAttachmentInfoBar() {
    const infoBar = document.getElementById('attachmentInfoBar');
    if (!infoBar) return;

    if (ticketAttachments.length > 0) {
        const regularCount = ticketAttachments.filter(a => !a.is_inline).length;
        const inlineCount = ticketAttachments.filter(a => a.is_inline).length;

        const regularPhrase = t(regularCount === 1 ? 'tickets.reading_pane.attach_one' : 'tickets.reading_pane.attach_many', { count: regularCount });
        let message = '';
        if (regularCount > 0 && inlineCount > 0) {
            message = `${regularPhrase} ${t('tickets.reading_pane.attach_inline_suffix', { count: inlineCount })}`;
        } else if (regularCount > 0) {
            message = regularPhrase;
        } else {
            message = t(inlineCount === 1 ? 'tickets.reading_pane.attach_inline_one' : 'tickets.reading_pane.attach_inline_many', { count: inlineCount });
        }

        infoBar.style.display = 'block';
        infoBar.innerHTML = `
            <span>${escapeHtml(t('tickets.reading_pane.attach_bar', { message: message }))}</span>
        `;
    } else {
        infoBar.style.display = 'none';
    }
}

// Format date as dd/mm/yyyy hh:mm
function formatDateDMY(dateStr) {
    if (!dateStr) return '';
    const date = parseUTCDate(dateStr);
    if (!date) return '';
    // Parse the stored value as UTC and render DD/MM/YYYY HH:MM in the
    // analyst's display zone (previously read browser-local components off an
    // un-normalised Date, which mis-parsed UTC DB strings).
    const p = new Intl.DateTimeFormat('en-GB', tzOpts({
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', hour12: false
    })).formatToParts(date).reduce((a, part) => { a[part.type] = part.value; return a; }, {});
    return `${p.day}/${p.month}/${p.year} ${p.hour}:${p.minute}`;
}

// Show attachment list modal
function showAttachmentList() {
    if (ticketAttachments.length === 0) return;

    const tableHtml = `
        <table class="attachment-modal-table">
            <thead>
                <tr>
                    <th>${escapeHtml(t('tickets.reading_pane.attach_col_from'))}</th>
                    <th>${escapeHtml(t('tickets.reading_pane.attach_col_datetime'))}</th>
                    <th>${escapeHtml(t('tickets.reading_pane.attach_col_filename'))}</th>
                    <th>${escapeHtml(t('tickets.reading_pane.attach_col_size'))}</th>
                    <th>${escapeHtml(t('tickets.reading_pane.attach_col_type'))}</th>
                </tr>
            </thead>
            <tbody>
                ${ticketAttachments.map(att => `
                    <tr onclick="openAttachment(${att.id})" class="attachment-row" title="${escapeHtml(t('tickets.reading_pane.attach_click_download'))}">
                        <td>${escapeHtml(att.from_name || att.from_address || '')}</td>
                        <td>${formatDateDMY(att.received_datetime)}</td>
                        <td>
                            <span class="attachment-icon">${getFileIcon(att.filename)}</span>
                            ${escapeHtml(att.filename)}
                        </td>
                        <td>${formatFileSize(att.file_size || 0)}</td>
                        <td>${att.is_inline ? `<span class="inline-badge">${escapeHtml(t('tickets.reading_pane.attach_inline_badge'))}</span>` : ''}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;

    // Inline previews for media that browsers can render directly (images, audio,
    // video) — so the analyst doesn't have to download then open. Everything still
    // appears in the table below for download.
    const previewable = ticketAttachments.filter(a => /^(image|audio|video)\//i.test(a.content_type || ''));
    const previewsHtml = previewable.length ? `
        <div class="attachment-previews">
            ${previewable.map(att => {
                const url = `${API_BASE}get_attachment.php?id=${att.id}`;
                const ct = (att.content_type || '').toLowerCase();
                let media;
                if (ct.startsWith('image/')) {
                    media = `<img src="${url}" alt="${escapeHtml(att.filename)}" class="att-preview-media" loading="lazy" onclick="window.open('${url}','_blank')" title="${escapeHtml(t('tickets.reading_pane.attach_click_fullsize'))}">`;
                } else if (ct.startsWith('audio/')) {
                    media = `<audio controls preload="none" src="${url}" class="att-preview-audio"></audio>`;
                } else {
                    media = `<video controls preload="metadata" src="${url}" class="att-preview-media"></video>`;
                }
                return `<figure class="att-preview-card">${media}<figcaption>${escapeHtml(att.filename)}</figcaption></figure>`;
            }).join('')}
        </div>` : '';

    // Create modal
    const modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.id = 'attachmentListModal';
    modal.innerHTML = `
        <div class="modal-content attachment-list-modal">
            <button class="modal-close-top" onclick="closeAttachmentListModal()">&times;</button>
            <div class="modal-header">
                <h3>${escapeHtml(t('tickets.reading_pane.attach_modal_title', { ref: currentEmail.ticket_number }))}</h3>
            </div>
            <div class="modal-body">
                ${previewsHtml}
                ${tableHtml}
            </div>
        </div>
    `;

    document.body.appendChild(modal);
}

// Close attachment list modal
function closeAttachmentListModal() {
    const modal = document.getElementById('attachmentListModal');
    if (modal) {
        modal.remove();
    }
}

// Open/download an attachment
function openAttachment(attachmentId) {
    window.open(`${API_BASE}get_attachment.php?id=${attachmentId}`, '_blank');
}

// Render notes
function renderNotes() {
    const container = document.getElementById('notesContainer');

    if (!currentNotes || currentNotes.length === 0) {
        container.innerHTML = '';
        return;
    }

    let html = '<div class="notes-section"><div class="notes-header">Notes</div>';

    currentNotes.forEach(note => {
        html += `
            <div class="note-item">
                <div class="note-header">
                    <span class="note-author">${escapeHtml(note.analyst_name)}</span>
                    <span>${formatDateTime(note.created_datetime)}</span>
                </div>
                <div class="note-text">${escapeHtml(note.note_text)}</div>
            </div>
        `;
    });

    html += '</div>';
    container.innerHTML = html;
}

// Open note modal
function openNoteModal() {
    document.getElementById('noteText').value = '';
    document.getElementById('noteModal').classList.add('active');
}

// Close note modal
function closeNoteModal() {
    document.getElementById('noteModal').classList.remove('active');
}

// Save note
async function saveNote() {
    const noteText = document.getElementById('noteText').value.trim();

    if (!noteText) {
        showToast('Please enter a note', 'error');
        return;
    }

    try {
        const response = await fetch(API_BASE + 'save_note.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                note_text: noteText,
                is_internal: true
            })
        });
        const data = await response.json();

        if (data.success) {
            closeNoteModal();
            loadNotes(currentEmail.ticket_id);
        } else {
            showToast('Error saving note: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to save note', 'error');
    }
}

// Open reply modal
function openReplyModal() {
    // Channel tickets (WhatsApp etc.) reply via the inline composer, not email.
    if (currentTicketChannel && currentTicketChannel !== 'email') {
        // Channel ticket: jump to the inline composer (free-text box if the window is
        // open, otherwise the template picker).
        const composer = document.getElementById('channelComposer');
        const focusEl = document.getElementById('channelComposerText') || document.getElementById('channelTemplateSelect');
        if (composer) composer.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (focusEl) focusEl.focus();
        return;
    }
    composeMode = 'reply';
    document.getElementById('emailTo').value = currentEmail.from_address;
    document.getElementById('emailCc').value = '';
    // Add ticket reference to subject if not already present
    let subject = currentEmail.subject;
    const ticketRef = `[SDREF:${currentEmail.ticket_number}]`;
    if (!subject.includes(ticketRef)) {
        subject = `RE: ${subject} ${ticketRef}`;
    } else {
        subject = `RE: ${subject}`;
    }
    document.getElementById('emailSubject').value = subject;

    // Empty editor - server will assemble the full thread when sending
    if (emailEditor) {
        emailEditor.setContent('<p><br></p>');
    }

    setReplyCleanupVisibility('reply');
    document.getElementById('emailModal').classList.add('active');
}

// Open forward modal
function openForwardModal() {
    composeMode = 'forward';
    document.getElementById('emailTo').value = '';
    document.getElementById('emailCc').value = '';
    // Add ticket reference to subject if not already present
    let subject = currentEmail.subject;
    const ticketRef = `[SDREF:${currentEmail.ticket_number}]`;
    if (!subject.includes(ticketRef)) {
        subject = `FW: ${subject} ${ticketRef}`;
    } else {
        subject = `FW: ${subject}`;
    }
    document.getElementById('emailSubject').value = subject;

    // Empty editor - server will assemble the full thread when sending
    if (emailEditor) {
        emailEditor.setContent('<p><br></p>');
    }

    setReplyCleanupVisibility('forward');
    document.getElementById('emailModal').classList.add('active');
}

// Close email modal
function closeEmailModal() {
    document.getElementById('emailModal').classList.remove('active');
    composeMode = 'new';
    // Clear the TinyMCE content
    if (emailEditor) {
        emailEditor.setContent('');
    }
    // Clear attachments
    emailAttachments = [];
    renderAttachments();
    hideReplyCleanupUndoBar();
}

// ===== Phase 6d: watchers / followers =====
let ticketWatchers = [];
let youAreWatching = false;

async function loadTicketWatchers(ticketId) {
    const c = document.getElementById('ticketWatchersContainer');
    if (!c) return;
    try {
        const res = await fetch(API_BASE + 'get_ticket_watchers.php?ticket_id=' + ticketId);
        const data = await res.json();
        if (!data.success) { c.innerHTML = ''; return; }
        ticketWatchers = data.watchers || [];
        youAreWatching = !!data.you_are_watching;
        renderWatchersBar(ticketId);
    } catch (e) { c.innerHTML = ''; }
}

function renderWatchersBar(ticketId) {
    const c = document.getElementById('ticketWatchersContainer');
    if (!c) return;
    const chips = ticketWatchers.map(w =>
        `<span class="watcher-chip" title="${escapeHtml(w.email || '')}">${escapeHtml(w.name || 'Unknown')}<span class="watcher-x" title="Remove" onclick="removeWatcher(${ticketId}, ${w.analyst_id})">×</span></span>`
    ).join('');
    c.innerHTML = `
        <span class="tag-bar-label">Watchers</span>
        <button class="watch-toggle ${youAreWatching ? 'on' : ''}" onclick="toggleWatchSelf(${ticketId})">${youAreWatching ? '★ Watching' : '☆ Watch'}</button>
        <span class="watcher-chips">${chips || '<span class="tag-bar-empty">none</span>'}</span>
        <span class="watcher-add-wrap">
            <button class="tag-add-btn" onclick="openWatcherPicker(event, ${ticketId})">+ Add</button>
            <div class="watcher-picker" id="watcherPicker" hidden></div>
        </span>`;
}

async function toggleWatchSelf(ticketId) {
    const url = API_BASE + (youAreWatching ? 'remove_ticket_watcher.php' : 'add_ticket_watcher.php');
    try {
        const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ticket_id: ticketId }) });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed');
        await loadTicketWatchers(ticketId);
    } catch (err) { showToast('Error: ' + err.message, 'error'); }
}

function openWatcherPicker(ev, ticketId) {
    if (ev) ev.stopPropagation();
    const picker = document.getElementById('watcherPicker');
    if (!picker) return;
    if (!picker.hidden) { picker.hidden = true; return; }
    renderWatcherPicker(ticketId, '');
    picker.hidden = false;
    const s = document.getElementById('watcherSearch');
    if (s) s.focus();
    setTimeout(() => document.addEventListener('click', watcherPickerOutside), 0);
}
function watcherPickerOutside(e) {
    const picker = document.getElementById('watcherPicker');
    if (picker && !e.target.closest('.watcher-add-wrap')) { picker.hidden = true; document.removeEventListener('click', watcherPickerOutside); }
}

function renderWatcherPicker(ticketId, filter) {
    const picker = document.getElementById('watcherPicker');
    if (!picker) return;
    const q = (filter || '').toLowerCase();
    const watchingIds = ticketWatchers.map(w => w.analyst_id);
    const list = (analysts || []).filter(a => !watchingIds.includes(a.id) && (!q || (a.full_name || '').toLowerCase().includes(q)));
    const items = list.slice(0, 50).map(a =>
        `<div class="watcher-opt" onclick="addWatcher(${ticketId}, ${a.id})">${escapeHtml(a.full_name)}</div>`
    ).join('');
    picker.innerHTML = `
        <div class="tag-picker-head"><input type="text" id="watcherSearch" placeholder="Add analyst…" oninput="renderWatcherPicker(${ticketId}, this.value)" value="${escapeHtml(filter || '')}"></div>
        <div class="tag-picker-list">${items || '<div class="tag-picker-empty">No matching analysts</div>'}</div>`;
}

async function addWatcher(ticketId, analystId) {
    try {
        const res = await fetch(API_BASE + 'add_ticket_watcher.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ticket_id: ticketId, analyst_id: analystId }) });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed');
        await loadTicketWatchers(ticketId);
        renderWatcherPicker(ticketId, '');
    } catch (err) { showToast('Error: ' + err.message, 'error'); }
}

async function removeWatcher(ticketId, analystId) {
    try {
        const res = await fetch(API_BASE + 'remove_ticket_watcher.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ticket_id: ticketId, analyst_id: analystId }) });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed');
        await loadTicketWatchers(ticketId);
    } catch (err) { showToast('Error: ' + err.message, 'error'); }
}

// ===== Phase 6c: bulk list actions =====
let bulkSelected = new Set();

function toggleBulkSelect(ticketId, checked) {
    if (checked) bulkSelected.add(ticketId); else bulkSelected.delete(ticketId);
    updateBulkBar();
}
function bulkSelectAll(checked) {
    bulkSelected.clear();
    if (checked) emails.forEach(e => bulkSelected.add(e.ticket_id || e.id));
    document.querySelectorAll('.bulk-cb').forEach(cb => { cb.checked = checked; });
    updateBulkBar();
}
function clearBulkSelection() {
    bulkSelected.clear();
    document.querySelectorAll('.bulk-cb').forEach(cb => { cb.checked = false; });
    updateBulkBar();
}
function updateBulkBar() {
    const bar = document.getElementById('bulkBar');
    if (!bar) return;
    const n = bulkSelected.size;
    if (n === 0) { bar.hidden = true; bar.innerHTML = ''; return; }
    const allChecked = emails.length > 0 && emails.every(e => bulkSelected.has(e.ticket_id || e.id));
    bar.hidden = false;
    bar.innerHTML = `
        <label class="bulk-all"><input type="checkbox" ${allChecked ? 'checked' : ''} onchange="bulkSelectAll(this.checked)"> All</label>
        <span class="bulk-count">${n} selected</span>
        <select id="bulkAction" onchange="onBulkActionChange()">
            <option value="">Bulk action…</option>
            <option value="status">Set status</option>
            <option value="priority">Set priority</option>
            <option value="assignee">Assign to</option>
            <option value="add_tag">Add tag</option>
            <option value="trash">Move to trash</option>
        </select>
        <span id="bulkValueWrap"></span>
        <button class="btn btn-primary" onclick="applyBulk()">Apply</button>
        <button class="btn btn-secondary" onclick="clearBulkSelection()">Clear</button>`;
}
function onBulkActionChange() {
    const action = (document.getElementById('bulkAction') || {}).value;
    const wrap = document.getElementById('bulkValueWrap');
    if (!wrap) return;
    const opts = (arr, vk, lk) => (arr || []).map(o => `<option value="${escapeHtml(String(o[vk]))}">${escapeHtml(String(o[lk]))}</option>`).join('');
    if (action === 'status') wrap.innerHTML = `<select id="bulkValue">${opts(ticketStatuses, 'name', 'name')}</select>`;
    else if (action === 'priority') wrap.innerHTML = `<select id="bulkValue">${opts(ticketPriorities, 'id', 'name')}</select>`;
    else if (action === 'assignee') wrap.innerHTML = `<select id="bulkValue"><option value="">Unassigned</option>${opts(analysts, 'id', 'full_name')}</select>`;
    else if (action === 'add_tag') wrap.innerHTML = `<select id="bulkValue">${opts(ticketTagsAll, 'id', 'name')}</select>`;
    else wrap.innerHTML = '';
}
async function applyBulk() {
    const action = (document.getElementById('bulkAction') || {}).value;
    if (!action) { showToast('Choose a bulk action', 'error'); return; }
    let value = null;
    if (action !== 'trash') {
        const vEl = document.getElementById('bulkValue');
        if (!vEl) { showToast('Choose a value', 'error'); return; }
        value = vEl.value;
    }
    const ids = Array.from(bulkSelected);
    if (!ids.length) return;
    if (action === 'trash') {
        if (!(await showConfirm({ title: 'Move to trash', message: `Move ${ids.length} ticket(s) to trash?`, okLabel: 'Move', okClass: 'primary' }))) return;
    }
    try {
        const res = await fetch(API_BASE + 'bulk_update_tickets.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_ids: ids, action, value })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Bulk update failed');
        showToast(`Updated ${data.updated}${data.failed ? ` · ${data.failed} failed` : ''}`, data.failed ? 'error' : 'success');
        clearBulkSelection();
        await loadEmails();
        if (typeof loadFolderCounts === 'function') loadFolderCounts();
    } catch (err) { showToast('Error: ' + err.message, 'error'); }
}

// ===== Phase 6b: ticket tags =====
let ticketTagsAll = [];          // all tags (filter panel + picker), loaded at init
let currentTicketTagIds = [];    // the open ticket's tag ids

async function loadAllTicketTags() {
    try {
        const res = await fetch(API_BASE + 'get_ticket_tags.php');
        const data = await res.json();
        if (data.success) ticketTagsAll = data.tags || [];
    } catch (e) { /* filter Tags group just stays empty */ }
}

async function loadTicketTags(ticketId) {
    const c = document.getElementById('ticketTagsContainer');
    if (!c) return;
    try {
        const res = await fetch(API_BASE + 'get_ticket_tags.php?ticket_id=' + ticketId);
        const data = await res.json();
        if (!data.success) { c.innerHTML = ''; return; }
        ticketTagsAll = data.tags || [];
        currentTicketTagIds = data.ticket_tag_ids || [];
        renderTicketTagsBar(ticketId);
    } catch (e) { c.innerHTML = ''; }
}

function renderTicketTagsBar(ticketId) {
    const c = document.getElementById('ticketTagsContainer');
    if (!c) return;
    const byId = {};
    ticketTagsAll.forEach(t => { byId[t.id] = t; });
    const chips = currentTicketTagIds.map(id => {
        const t = byId[id];
        if (!t) return '';
        return `<span class="tag-chip" style="--tag-col:${escapeHtml(t.colour || '#6b7280')}">${escapeHtml(t.name)}<span class="tag-chip-x" title="Remove" onclick="toggleTicketTag(${ticketId}, ${id})">×</span></span>`;
    }).join('');
    c.innerHTML = `
        <span class="tag-bar-label">Tags</span>
        <span class="tag-bar-chips">${chips || '<span class="tag-bar-empty">none</span>'}</span>
        <span class="tag-add-wrap">
            <button class="tag-add-btn" onclick="openTagPicker(event, ${ticketId})">+ Tag</button>
            <div class="tag-picker" id="tagPicker" hidden></div>
        </span>`;
}

function openTagPicker(ev, ticketId) {
    if (ev) ev.stopPropagation();
    const picker = document.getElementById('tagPicker');
    if (!picker) return;
    if (!picker.hidden) { picker.hidden = true; return; }
    renderTagPicker(ticketId, '');
    picker.hidden = false;
    const s = document.getElementById('tagPickerSearch');
    if (s) s.focus();
    setTimeout(() => document.addEventListener('click', tagPickerOutside), 0);
}
function tagPickerOutside(e) {
    const picker = document.getElementById('tagPicker');
    if (picker && !e.target.closest('.tag-add-wrap')) {
        picker.hidden = true;
        document.removeEventListener('click', tagPickerOutside);
    }
}

function renderTagPicker(ticketId, filter) {
    const picker = document.getElementById('tagPicker');
    if (!picker) return;
    const q = (filter || '').toLowerCase();
    const list = ticketTagsAll.filter(t => !q || t.name.toLowerCase().includes(q));
    const items = list.map(t => {
        const on = currentTicketTagIds.includes(t.id);
        return `<label class="tag-opt"><input type="checkbox" ${on ? 'checked' : ''} onchange="toggleTicketTag(${ticketId}, ${t.id})"><span class="tag-dot" style="background:${escapeHtml(t.colour || '#6b7280')}"></span>${escapeHtml(t.name)}</label>`;
    }).join('');
    const exact = ticketTagsAll.some(t => t.name.toLowerCase() === q);
    const create = (filter && filter.trim() && !exact)
        ? `<div class="tag-picker-create" onclick="createAndAddTag(${ticketId})">+ Create &ldquo;${escapeHtml(filter.trim())}&rdquo;</div>` : '';
    picker.innerHTML = `
        <div class="tag-picker-head"><input type="text" id="tagPickerSearch" placeholder="Filter or create…" oninput="renderTagPicker(${ticketId}, this.value)" value="${escapeHtml(filter || '')}"></div>
        <div class="tag-picker-list">${items || '<div class="tag-picker-empty">No matching tags</div>'}</div>
        ${create}`;
}

async function toggleTicketTag(ticketId, tagId) {
    const idx = currentTicketTagIds.indexOf(tagId);
    if (idx >= 0) currentTicketTagIds.splice(idx, 1); else currentTicketTagIds.push(tagId);
    await saveTicketTags(ticketId);
}

async function createAndAddTag(ticketId) {
    const s = document.getElementById('tagPickerSearch');
    const name = s ? s.value.trim() : '';
    if (!name) return;
    try {
        const res = await fetch(API_BASE + 'save_ticket_tag.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to create tag');
        await loadAllTicketTags();
        if (!currentTicketTagIds.includes(data.id)) currentTicketTagIds.push(data.id);
        await saveTicketTags(ticketId);
        renderTagPicker(ticketId, '');
    } catch (err) { showToast('Error: ' + err.message, 'error'); }
}

async function saveTicketTags(ticketId) {
    try {
        const res = await fetch(API_BASE + 'set_ticket_tags.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ticketId, tag_ids: currentTicketTagIds })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to save tags');
        currentTicketTagIds = (data.tags || []).map(t => t.id);
        renderTicketTagsBar(ticketId);
        const picker = document.getElementById('tagPicker');
        if (picker && !picker.hidden) {
            const s = document.getElementById('tagPickerSearch');
            renderTagPicker(ticketId, s ? s.value : '');
        }
    } catch (err) { showToast('Error: ' + err.message, 'error'); }
}

// ===== Phase 6a: canned responses / reply macros =====
let cannedResponses = [];
let cannedCanManageShared = false;

async function toggleCannedPicker(ev) {
    if (ev) ev.stopPropagation();
    const picker = document.getElementById('cannedPicker');
    if (!picker) return;
    if (!picker.hidden) { picker.hidden = true; return; }
    await loadCannedResponses();
    renderCannedPicker('');
    picker.hidden = false;
    const search = document.getElementById('cannedSearch');
    if (search) search.focus();
    setTimeout(() => document.addEventListener('click', cannedOutsideClose), 0);
}
function cannedOutsideClose(e) {
    const picker = document.getElementById('cannedPicker');
    if (picker && !e.target.closest('.canned-wrap')) {
        picker.hidden = true;
        document.removeEventListener('click', cannedOutsideClose);
    }
}

async function loadCannedResponses() {
    try {
        const res = await fetch(API_BASE + 'get_canned_responses.php');
        const data = await res.json();
        if (data.success) {
            cannedResponses = data.responses || [];
            cannedCanManageShared = !!data.can_manage_shared;
        }
    } catch (e) { /* picker will show empty */ }
}

function renderCannedPicker(filter) {
    const picker = document.getElementById('cannedPicker');
    if (!picker) return;
    const q = (filter || '').toLowerCase();
    const list = cannedResponses.filter(r => !q || r.name.toLowerCase().includes(q) || (r.folder || '').toLowerCase().includes(q));
    const items = list.map(r => `
        <div class="canned-item" onclick="insertCanned(${r.id})">
            <span class="canned-item-name">${escapeHtml(r.name)}${r.folder ? ` <span class="canned-folder">${escapeHtml(r.folder)}</span>` : ''}</span>
            <span class="canned-item-right">
                <span class="canned-icon" title="${r.is_shared ? 'Shared' : 'Personal'}">${r.is_shared ? '👥' : '🔖'}</span>
                ${(r.is_own || (r.is_shared && cannedCanManageShared)) ? `<span class="canned-del" title="Delete" onclick="event.stopPropagation();deleteCanned(${r.id})">🗑</span>` : ''}
            </span>
        </div>`).join('');
    picker.innerHTML = `
        <div class="canned-head">
            <input type="text" id="cannedSearch" placeholder="Search responses…" oninput="renderCannedPicker(this.value)" value="${escapeHtml(filter || '')}">
        </div>
        <div class="canned-list">${items || '<div class="canned-empty">No responses yet — save the current message below.</div>'}</div>
        <div class="canned-save">
            <input type="text" id="cannedNewName" placeholder="Save current message as…">
            ${cannedCanManageShared ? '<label class="canned-shared"><input type="checkbox" id="cannedNewShared"> Shared</label>' : ''}
            <button class="btn btn-secondary" onclick="saveCannedFromEditor()">Save</button>
        </div>`;
}

// Insert the rendered body (placeholders resolved for the open ticket) at the cursor.
async function insertCanned(id) {
    try {
        const tid = (currentEmail && currentEmail.ticket_id) ? currentEmail.ticket_id : 0;
        const res = await fetch(API_BASE + 'render_canned_response.php?id=' + id + '&ticket_id=' + tid);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to load response');
        if (emailEditor) emailEditor.insertContent(data.body || '');
        const picker = document.getElementById('cannedPicker');
        if (picker) picker.hidden = true;
    } catch (err) { showToast('Error: ' + err.message, 'error'); }
}

// Save the current reply draft as a reusable canned response.
async function saveCannedFromEditor() {
    const nameEl = document.getElementById('cannedNewName');
    const name = nameEl ? nameEl.value.trim() : '';
    if (!name) { showToast('Enter a name for the response', 'error'); return; }
    const body = emailEditor ? emailEditor.getContent() : '';
    if (!body || !body.replace(/<[^>]*>/g, '').trim()) { showToast('The message is empty', 'error'); return; }
    const sharedEl = document.getElementById('cannedNewShared');
    try {
        const res = await fetch(API_BASE + 'save_canned_response.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, body, is_shared: sharedEl ? sharedEl.checked : false })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Save failed');
        showToast('Response saved', 'success');
        await loadCannedResponses();
        renderCannedPicker('');
    } catch (err) { showToast('Error: ' + err.message, 'error'); }
}

async function deleteCanned(id) {
    const r = cannedResponses.find(x => x.id === id);
    if (!(await showConfirm({ title: 'Delete response', message: `Delete "${r ? r.name : 'this response'}"?`, okLabel: 'Delete', okClass: 'primary' }))) return;
    try {
        const res = await fetch(API_BASE + 'delete_canned_response.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Delete failed');
        showToast('Response deleted', 'success');
        await loadCannedResponses();
        const s = document.getElementById('cannedSearch');
        renderCannedPicker(s ? s.value : '');
    } catch (err) { showToast('Error: ' + err.message, 'error'); }
}

// ===== Reply Cleanup AI =====

let replyCleanupOriginalDraft = null;
let replyCleanupUndoTimer = null;
let replyCleanupCountdownTimer = null;

function setReplyCleanupVisibility(mode) {
    const btn = document.getElementById('replyCleanupBtn');
    if (btn) btn.style.display = (mode === 'reply') ? '' : 'none';
    hideReplyCleanupUndoBar();
}

// Convert plain-text-with-blank-lines (Claude's output) to TinyMCE-friendly HTML
function replyCleanupTextToHtml(text) {
    if (!text) return '<p><br></p>';
    // Normalise newlines, split on 2+ newlines for paragraphs, single newlines become <br>
    const normalised = text.replace(/\r\n/g, '\n');
    const paragraphs = normalised.split(/\n{2,}/);
    return paragraphs.map(p => {
        const safe = escapeHtml(p).replace(/\n/g, '<br>');
        return `<p>${safe}</p>`;
    }).join('');
}

async function cleanupReplyDraft() {
    if (!emailEditor) return;
    if (!currentEmail || !currentEmail.ticket_id) {
        showToast('No ticket loaded', 'error');
        return;
    }

    const editorContent = emailEditor.getContent({ format: 'text' }).trim();
    if (editorContent === '') {
        showToast('Type something first, then click Cleanup', 'error');
        return;
    }

    // Stash original HTML so the undo link can restore it verbatim
    replyCleanupOriginalDraft = emailEditor.getContent();

    const cleanupBtn = document.getElementById('replyCleanupBtn');
    const sendBtn = document.getElementById('replySendBtn');
    cleanupBtn.disabled = true;
    sendBtn.disabled = true;
    cleanupBtn.classList.add('is-loading');
    cleanupBtn.innerHTML = '<span class="spinner-inline"></span> Cleaning up…';
    hideReplyCleanupUndoBar();

    // Clear the editor — we'll stream into it.
    emailEditor.setContent('<p><em style="color:#999;">Cleaning up…</em></p>');

    let buffer = '';
    let firstChunk = true;
    let streamFailed = false;

    try {
        const res = await fetch(API_BASE + 'ai_cleanup_reply.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                draft_text: editorContent,
            }),
        });

        if (!res.ok || !res.body) {
            throw new Error('HTTP ' + res.status);
        }

        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let sseBuffer = '';

        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            sseBuffer += decoder.decode(value, { stream: true });

            // Split on SSE event boundaries (\n\n)
            let idx;
            while ((idx = sseBuffer.indexOf('\n\n')) !== -1) {
                const block = sseBuffer.slice(0, idx);
                sseBuffer = sseBuffer.slice(idx + 2);

                let eventName = '';
                let dataLine = '';
                for (const line of block.split('\n')) {
                    if (line.startsWith('event: ')) eventName = line.slice(7).trim();
                    else if (line.startsWith('data: ')) dataLine += line.slice(6);
                }
                if (!dataLine) continue;
                let payload;
                try { payload = JSON.parse(dataLine); } catch { continue; }

                if (eventName === 'text') {
                    if (firstChunk) {
                        emailEditor.setContent('');
                        firstChunk = false;
                    }
                    buffer += payload.delta || '';
                    emailEditor.setContent(replyCleanupTextToHtml(buffer));
                } else if (eventName === 'error') {
                    streamFailed = true;
                    showToast(payload.message || 'Cleanup failed', 'error');
                    break;
                }
                // 'usage' / 'done' events are ignored for this UI
            }

            if (streamFailed) break;
        }
    } catch (err) {
        streamFailed = true;
        console.error('Cleanup error:', err);
        showToast('Cleanup failed: ' + err.message, 'error');
    }

    cleanupBtn.disabled = false;
    sendBtn.disabled = false;
    cleanupBtn.classList.remove('is-loading');
    cleanupBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><path d="M12 3l1.9 5.8L20 10l-5 4.5L16.5 21 12 17.8 7.5 21 9 14.5 4 10l6.1-1.2z"/></svg> Cleanup';

    if (streamFailed) {
        // Restore the user's original draft so they don't lose their typing
        if (replyCleanupOriginalDraft !== null) {
            emailEditor.setContent(replyCleanupOriginalDraft);
        }
        return;
    }

    showReplyCleanupUndoBar();
}

function showReplyCleanupUndoBar() {
    const bar = document.getElementById('replyCleanupUndoBar');
    const timer = document.getElementById('replyCleanupUndoTimer');
    if (!bar) return;
    bar.style.display = 'block';

    let secondsLeft = 30;
    timer.textContent = `(${secondsLeft}s)`;

    if (replyCleanupCountdownTimer) clearInterval(replyCleanupCountdownTimer);
    replyCleanupCountdownTimer = setInterval(() => {
        secondsLeft--;
        if (secondsLeft <= 0) {
            hideReplyCleanupUndoBar();
        } else {
            timer.textContent = `(${secondsLeft}s)`;
        }
    }, 1000);

    if (replyCleanupUndoTimer) clearTimeout(replyCleanupUndoTimer);
    replyCleanupUndoTimer = setTimeout(hideReplyCleanupUndoBar, 30000);
}

function hideReplyCleanupUndoBar() {
    const bar = document.getElementById('replyCleanupUndoBar');
    if (bar) bar.style.display = 'none';
    if (replyCleanupUndoTimer) {
        clearTimeout(replyCleanupUndoTimer);
        replyCleanupUndoTimer = null;
    }
    if (replyCleanupCountdownTimer) {
        clearInterval(replyCleanupCountdownTimer);
        replyCleanupCountdownTimer = null;
    }
}

// Wire the Undo link once on first inbox.js load
document.addEventListener('DOMContentLoaded', function() {
    const undoLink = document.getElementById('replyCleanupUndoLink');
    if (undoLink) {
        undoLink.addEventListener('click', function(e) {
            e.preventDefault();
            if (replyCleanupOriginalDraft !== null && emailEditor) {
                emailEditor.setContent(replyCleanupOriginalDraft);
                showToast('Restored your original draft', 'success');
            }
            hideReplyCleanupUndoBar();
        });
    }
});

// Send email via Microsoft Graph API
async function sendEmail() {
    // Get values from form
    const to = document.getElementById('emailTo').value.trim();
    const cc = document.getElementById('emailCc').value.trim();
    const subject = document.getElementById('emailSubject').value;
    const body = emailEditor ? emailEditor.getContent() : '';

    // Basic validation
    if (!to) {
        showToast('Please enter a recipient email address', 'error');
        return;
    }
    if (!subject) {
        showToast('Please enter a subject', 'error');
        return;
    }

    // Get send button and show loading state
    const sendBtn = document.querySelector('#emailModal .btn-primary');
    const originalText = sendBtn.textContent;
    sendBtn.disabled = true;
    sendBtn.textContent = 'Sending...';

    try {
        // Convert attachments to base64
        const attachmentData = await prepareAttachments();

        // Send the email
        const response = await fetch(API_BASE + 'send_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                to: to,
                cc: cc,
                subject: subject,
                body: body,
                ticket_id: currentEmail ? currentEmail.ticket_id : null,
                type: composeMode,
                attachments: attachmentData
            })
        });

        // Get raw response text first to handle non-JSON errors
        const responseText = await response.text();
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (parseError) {
            console.error('Raw response:', responseText);
            showToast('Server error: ' + responseText.substring(0, 200), 'error');
            return;
        }

        if (data.success) {
            showToast('Email sent successfully!', 'success');
            closeEmailModal();
            // Refresh the current view to show the sent email
            if (currentEmail) {
                selectEmail(selectedEmailId);
            }
        } else {
            showToast('Failed to send email: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error sending email:', error);
        showToast('Error sending email: ' + error.message, 'error');
    } finally {
        // Restore button state
        sendBtn.disabled = false;
        sendBtn.textContent = originalText;
    }
}

// Prepare attachments by converting to base64
async function prepareAttachments() {
    const attachments = [];

    for (const file of emailAttachments) {
        const base64 = await fileToBase64(file);
        attachments.push({
            name: file.name,
            type: file.type || 'application/octet-stream',
            content: base64
        });
    }

    return attachments;
}

// Convert file to base64
function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
            // Remove the data URL prefix (e.g., "data:application/pdf;base64,")
            const base64 = reader.result.split(',')[1];
            resolve(base64);
        };
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });
}

// Logout
function logout() {
    showConfirm({
        title: 'Logout',
        message: 'Are you sure you want to logout?',
        okLabel: 'Logout',
        okClass: 'primary',
        onConfirm: () => { window.location.href = 'analyst_logout.php'; }
    });
}

// New Ticket Modal Functions
function openNewTicketModal() {
    // Clear form
    document.getElementById('newTicketFromName').value = '';
    document.getElementById('newTicketFromEmail').value = '';
    document.getElementById('newTicketSubject').value = '';
    document.getElementById('newTicketBody').value = '';

    // Populate department dropdown
    const deptSelect = document.getElementById('newTicketDepartment');
    deptSelect.innerHTML = '<option value="">-- Select --</option>' +
        departments.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('');

    // Populate ticket type dropdown
    const typeSelect = document.getElementById('newTicketType');
    typeSelect.innerHTML = '<option value="">-- Select --</option>' +
        ticketTypes.map(t => `<option value="${t.id}">${escapeHtml(t.name)}</option>`).join('');

    // Populate category dropdown; subcategory stays empty/disabled until a category is chosen.
    const catSelect = document.getElementById('newTicketCategory');
    catSelect.innerHTML = '<option value="">-- Select --</option>' +
        ticketCategories.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
    const subcatSelect = document.getElementById('newTicketSubcategory');
    subcatSelect.innerHTML = '<option value="">-- Select --</option>';
    subcatSelect.disabled = true;

    // Populate priority dropdown from the CONFIGURED priorities (active only) so
    // custom ones like Urgent/Critical appear instead of a hardcoded Low/Normal/High
    // subset (#40). The value is the priority NAME — createTicket resolves name→id.
    const prioSelect = document.getElementById('newTicketPriority');
    if (ticketPriorities.length) {
        prioSelect.innerHTML = ticketPriorities
            .map(p => `<option value="${escapeHtml(p.name)}">${escapeHtml(p.name)}</option>`)
            .join('');
        const def = ticketPriorities.find(p => p.is_default) || ticketPriorities.find(p => p.name === 'Normal');
        if (def) prioSelect.value = def.name;
    } else {
        // Fallback if priorities haven't loaded yet — keeps the form usable.
        prioSelect.innerHTML = '<option value="Normal">Normal</option>';
    }

    // Populate the "Send replies from" mailbox dropdown for the active company.
    loadNewTicketMailboxes();

    document.getElementById('newTicketModal').classList.add('active');
}

// Load the mailboxes this ticket can be sent from (scoped to the active company)
// and populate the New Ticket modal's mailbox picker. Fires async; the modal can
// open before it returns.
async function loadNewTicketMailboxes() {
    const sel = document.getElementById('newTicketMailbox');
    const label = document.getElementById('newTicketCompanyLabel');
    const hint = document.getElementById('newTicketMailboxHint');
    if (!sel) return;
    sel.innerHTML = '<option value="">Loading…</option>';
    if (label) label.textContent = '';
    if (hint) hint.textContent = '';
    try {
        const r = await fetch(API_BASE + 'get_sendable_mailboxes.php', { credentials: 'same-origin' });
        const d = await r.json();
        if (!d.success) throw new Error(d.error || 'failed');

        // Show which company these mailboxes belong to (multi-company installs only).
        if (label && d.multi_tenant && d.tenant_name) label.textContent = ' — ' + d.tenant_name;

        if (!d.mailboxes || !d.mailboxes.length) {
            sel.innerHTML = '<option value="">(no sendable mailbox)</option>';
            if (hint) hint.textContent = d.multi_tenant
                ? "No active, signed-in mailbox for this company — you can still create the ticket, but replies can't be emailed until one is set up."
                : "No active, signed-in mailbox — you can still create the ticket, but replies can't be emailed until one is set up.";
            return;
        }
        // Server orders pinned-to-company first, so the first option is the sensible default.
        sel.innerHTML = d.mailboxes.map(m =>
            `<option value="${m.id}">${escapeHtml(m.name)}${m.pinned ? '' : ' (shared)'}</option>`
        ).join('');
    } catch (e) {
        sel.innerHTML = '<option value="">(could not load mailboxes)</option>';
    }
}

function closeNewTicketModal() {
    document.getElementById('newTicketModal').classList.remove('active');
}

// Repopulate the New Ticket modal's subcategory dropdown when its category changes.
async function onNewTicketCategoryChange() {
    const categoryId = document.getElementById('newTicketCategory').value;
    const subcatSelect = document.getElementById('newTicketSubcategory');
    if (!categoryId) {
        subcatSelect.innerHTML = '<option value="">-- Select --</option>';
        subcatSelect.disabled = true;
        return;
    }
    const subs = await loadTicketSubcategories(categoryId);
    subcatSelect.disabled = false;
    subcatSelect.innerHTML = '<option value="">-- Select --</option>' +
        subs.map(s => `<option value="${s.id}">${escapeHtml(s.name)}</option>`).join('');
}

async function createNewTicket() {
    const fromName = document.getElementById('newTicketFromName').value.trim();
    const fromEmail = document.getElementById('newTicketFromEmail').value.trim();
    const subject = document.getElementById('newTicketSubject').value.trim();
    const body = document.getElementById('newTicketBody').value.trim();
    const departmentId = document.getElementById('newTicketDepartment').value;
    const ticketTypeId = document.getElementById('newTicketType').value;
    const categoryId = document.getElementById('newTicketCategory').value;
    const subcategoryId = document.getElementById('newTicketSubcategory').value;
    const priority = document.getElementById('newTicketPriority').value;
    const mailboxId = document.getElementById('newTicketMailbox').value;

    // Validate required fields
    if (!fromName) {
        showToast('Please enter the requester name', 'error');
        return;
    }
    if (!fromEmail) {
        showToast('Please enter the requester email', 'error');
        return;
    }
    if (!subject) {
        showToast('Please enter a subject', 'error');
        return;
    }

    // Get the create button and show loading state
    const createBtn = document.querySelector('#newTicketModal .btn-primary');
    const originalText = createBtn.textContent;
    createBtn.disabled = true;
    createBtn.textContent = 'Creating...';

    try {
        const response = await fetch(API_BASE + 'create_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                from_name: fromName,
                from_email: fromEmail,
                subject: subject,
                body: body,
                department_id: departmentId || null,
                ticket_type_id: ticketTypeId || null,
                category_id: categoryId || null,
                subcategory_id: subcategoryId || null,
                priority: priority,
                mailbox_id: mailboxId || null
            })
        });

        const data = await response.json();

        if (data.success) {
            closeNewTicketModal();
            // Refresh the view
            loadFolderCounts();
            loadEmails();
            showToast('Ticket created successfully: ' + data.ticket_number, 'success');
        } else {
            showToast('Error creating ticket: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to create ticket', 'error');
    } finally {
        createBtn.disabled = false;
        createBtn.textContent = originalText;
    }
}

// ============================================
// Search Modal Functions
// ============================================

let searchModalDragging = false;
let searchModalOffsetX = 0;
let searchModalOffsetY = 0;

function openSearchModal() {
    const modal = document.getElementById('searchModal');
    modal.classList.add('active');

    // Position modal so right edge aligns with refresh button's right edge
    const refreshBtn = document.querySelector('.refresh-btn');
    if (refreshBtn) {
        const btnRect = refreshBtn.getBoundingClientRect();
        const modalWidth = 500; // matches CSS width
        const rightEdge = btnRect.right;
        const leftPos = rightEdge - modalWidth;

        modal.style.left = Math.max(10, leftPos) + 'px';
        modal.style.top = (btnRect.bottom + 10) + 'px';
        modal.style.transform = 'none';
    } else {
        // Fallback to center
        modal.style.left = '50%';
        modal.style.top = '100px';
        modal.style.transform = 'translateX(-50%)';
    }

    // Initialize dragging
    initSearchModalDrag();

    // Focus the first input
    document.getElementById('searchTicketNumber').focus();
}

function closeSearchModal() {
    document.getElementById('searchModal').classList.remove('active');
    // Don't clear search - user can reopen to see previous results
    // Use the Clear button to reset if needed
}

function initSearchModalDrag() {
    const modal = document.getElementById('searchModal');
    const header = document.getElementById('searchModalHeader');

    header.onmousedown = function(e) {
        if (e.target.classList.contains('search-modal-close')) return;

        searchModalDragging = true;

        // Remove the transform so we can use left/top directly
        const rect = modal.getBoundingClientRect();
        modal.style.transform = 'none';
        modal.style.left = rect.left + 'px';
        modal.style.top = rect.top + 'px';

        searchModalOffsetX = e.clientX - rect.left;
        searchModalOffsetY = e.clientY - rect.top;

        document.onmousemove = function(e) {
            if (!searchModalDragging) return;

            let newX = e.clientX - searchModalOffsetX;
            let newY = e.clientY - searchModalOffsetY;

            // Keep within viewport bounds
            newX = Math.max(0, Math.min(newX, window.innerWidth - modal.offsetWidth));
            newY = Math.max(0, Math.min(newY, window.innerHeight - modal.offsetHeight));

            modal.style.left = newX + 'px';
            modal.style.top = newY + 'px';
        };

        document.onmouseup = function() {
            searchModalDragging = false;
            document.onmousemove = null;
            document.onmouseup = null;
        };
    };
}

async function performSearch() {
    const ticketNumber = document.getElementById('searchTicketNumber').value.trim();
    const email = document.getElementById('searchEmail').value.trim();
    const subject = document.getElementById('searchSubject').value.trim();

    // Validate at least one field
    if (!ticketNumber && !email && !subject) {
        showToast('Please enter at least one search criterion', 'error');
        return;
    }

    const resultsContainer = document.getElementById('searchResults');
    resultsContainer.innerHTML = '<div class="search-loading"><div class="spinner"></div></div>';

    try {
        const response = await fetch(API_BASE + 'search_tickets.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_number: ticketNumber,
                email: email,
                subject: subject
            })
        });

        const data = await response.json();

        if (data.success) {
            renderSearchResults(data.results);
        } else {
            resultsContainer.innerHTML = `<div class="search-results-empty">Error: ${data.error}</div>`;
        }
    } catch (error) {
        console.error('Search error:', error);
        resultsContainer.innerHTML = '<div class="search-results-empty">Search failed. Please try again.</div>';
    }
}

function renderSearchResults(results) {
    const container = document.getElementById('searchResults');

    if (!results || results.length === 0) {
        container.innerHTML = '<div class="search-results-empty">No tickets found matching your criteria</div>';
        return;
    }

    let html = `<div class="search-results-count">${results.length} ticket${results.length === 1 ? '' : 's'} found</div>`;

    results.forEach(ticket => {
        html += `
            <div class="search-result-item" onclick="selectSearchResult(${ticket.email_id})">
                <div class="search-result-ticket">${escapeHtml(ticket.ticket_number)}</div>
                <div class="search-result-subject">${escapeHtml(ticket.subject)}</div>
                <div class="search-result-meta">
                    <span>${escapeHtml(ticket.from_name || ticket.from_address)}</span>
                    <span>${ticket.status}</span>
                    <span>${formatDateTime(ticket.received_datetime)}</span>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

function selectSearchResult(emailId) {
    // Keep the modal open so user can try another result if needed
    // Select the email in the reading pane
    selectEmail(emailId);
}

function clearSearch() {
    document.getElementById('searchTicketNumber').value = '';
    document.getElementById('searchEmail').value = '';
    document.getElementById('searchSubject').value = '';
    document.getElementById('searchResults').innerHTML = '<div class="search-results-empty">Enter search criteria above</div>';
}

// Allow Enter key to trigger search
document.addEventListener('DOMContentLoaded', function() {
    const searchInputs = ['searchTicketNumber', 'searchEmail', 'searchSubject'];
    searchInputs.forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    performSearch();
                }
            });
        }
    });
});

// ============================================
// Schedule Modal Functions
// ============================================

function openScheduleModal() {
    if (!currentEmail || !currentEmail.ticket_id) {
        showToast('No ticket selected', 'error');
        return;
    }

    // Set ticket info
    document.getElementById('scheduleTicketInfo').textContent =
        `${currentEmail.ticket_number} - ${currentEmail.subject}`;

    // Set default date to today and time to next hour
    const now = new Date();
    const dateStr = now.toISOString().split('T')[0];
    document.getElementById('scheduleDate').value = dateStr;

    // Round to next hour
    now.setHours(now.getHours() + 1, 0, 0, 0);
    const timeStr = now.toTimeString().slice(0, 5);
    document.getElementById('scheduleTime').value = timeStr;

    // Check if already scheduled
    if (currentEmail.work_start_datetime) {
        const scheduled = new Date(currentEmail.work_start_datetime);
        document.getElementById('currentSchedule').textContent = formatNaiveFullDateTime(currentEmail.work_start_datetime);
        document.getElementById('scheduleCurrent').style.display = 'block';

        // Pre-fill with existing schedule
        document.getElementById('scheduleDate').value = scheduled.toISOString().split('T')[0];
        document.getElementById('scheduleTime').value = scheduled.toTimeString().slice(0, 5);
    } else {
        document.getElementById('scheduleCurrent').style.display = 'none';
    }

    document.getElementById('scheduleModal').classList.add('active');
}

function closeScheduleModal() {
    document.getElementById('scheduleModal').classList.remove('active');
}

async function saveSchedule() {
    const date = document.getElementById('scheduleDate').value;
    const time = document.getElementById('scheduleTime').value;

    if (!date || !time) {
        showToast('Please select both date and time', 'error');
        return;
    }

    const workStart = `${date} ${time}:00`;

    try {
        const response = await fetch(API_BASE + 'schedule_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                work_start_datetime: workStart
            })
        });

        const data = await response.json();

        if (data.success) {
            currentEmail.work_start_datetime = workStart;
            closeScheduleModal();
            showToast('Work scheduled successfully', 'success');
        } else {
            showToast('Error scheduling: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to schedule work', 'error');
    }
}

async function clearSchedule() {
    if (!(await showConfirm({ title: 'Confirm', message: 'Are you sure you want to clear the scheduled work time?', okLabel: 'OK', okClass: 'primary' }))) return;

    try {
        const response = await fetch(API_BASE + 'schedule_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                work_start_datetime: null
            })
        });

        const data = await response.json();

        if (data.success) {
            currentEmail.work_start_datetime = null;
            closeScheduleModal();
            showToast('Schedule cleared', 'error');
        } else {
            showToast('Error clearing schedule: ' + data.error, 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Failed to clear schedule', 'error');
    }
}

// ===== AI Chat Functions (Ask AI) =====

let _ticketAiContextId = null; // Track which ticket has been auto-queried

function openTicketAiChat() {
    if (!currentEmail) return;

    const panel = document.getElementById('ticketAiPanel');
    const overlay = document.getElementById('ticketAiOverlay');
    panel.classList.add('active');
    overlay.classList.add('active');

    // If ticket changed, reset chat
    if (_ticketAiContextId !== currentEmail.ticket_id) {
        _ticketAiContextId = currentEmail.ticket_id;
        const messagesContainer = document.getElementById('ticketAiMessages');
        messagesContainer.innerHTML = '<div class="ai-chat-welcome">Ask a question about this ticket and the AI will search the knowledge base for relevant articles.</div>';

        // Auto-send initial context question
        const subject = currentEmail.subject || '';
        const bodyText = (currentEmail.body_content || '').replace(/<[^>]*>/g, '').substring(0, 1500);
        const autoQuestion = `I'm looking at ticket ${currentEmail.ticket_number || ''}: ${subject}.\n\nHere's the initial email:\n\n${bodyText}\n\nAre there any knowledge articles that might help resolve this?`;
        _sendTicketAiMessage(autoQuestion, true);
    }

    document.getElementById('ticketAiInput').focus();
}

function closeTicketAiChat() {
    document.getElementById('ticketAiPanel').classList.remove('active');
    document.getElementById('ticketAiOverlay').classList.remove('active');
}

function askTicketAi() {
    const input = document.getElementById('ticketAiInput');
    const question = input.value.trim();
    if (!question) return;
    input.value = '';
    _sendTicketAiMessage(question, false);
}

async function _sendTicketAiMessage(question, isAutoContext) {
    const messagesContainer = document.getElementById('ticketAiMessages');
    const input = document.getElementById('ticketAiInput');
    const sendBtn = document.getElementById('ticketAiSendBtn');

    // Clear welcome message
    const welcome = messagesContainer.querySelector('.ai-chat-welcome');
    if (welcome) welcome.remove();

    // Add user message bubble (show a shorter version for auto-context)
    const userMsg = document.createElement('div');
    userMsg.className = 'ai-chat-message user';
    const displayText = isAutoContext
        ? `Find knowledge articles relevant to: ${currentEmail.subject || 'this ticket'}`
        : question;
    userMsg.innerHTML = '<div class="ai-chat-bubble">' + escapeHtml(displayText) + '</div>';
    messagesContainer.appendChild(userMsg);

    // Disable input
    input.disabled = true;
    sendBtn.disabled = true;

    // Add thinking indicator
    const thinking = document.createElement('div');
    thinking.className = 'ai-chat-thinking';
    thinking.innerHTML = '<div class="dots"><span></span><span></span><span></span></div> Searching knowledge base...';
    messagesContainer.appendChild(thinking);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;

    try {
        const response = await fetch('../api/knowledge/ai_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: question, include_archived: false })
        });
        const data = await response.json();

        thinking.remove();

        if (data.success) {
            const assistantMsg = document.createElement('div');
            assistantMsg.className = 'ai-chat-message assistant';
            assistantMsg.innerHTML = '<div class="ai-chat-bubble">' + formatTicketAiResponse(data.answer, data.articles || []) + '</div>' +
                '<div class="ai-chat-meta">Searched ' + data.articles_searched + ' articles</div>';
            messagesContainer.appendChild(assistantMsg);
        } else {
            const errorMsg = document.createElement('div');
            errorMsg.className = 'ai-chat-error';
            errorMsg.textContent = data.error || 'Failed to get a response. Please check the AI API key in Knowledge Settings.';
            messagesContainer.appendChild(errorMsg);
        }
    } catch (error) {
        thinking.remove();
        const errorMsg = document.createElement('div');
        errorMsg.className = 'ai-chat-error';
        errorMsg.textContent = 'Network error: ' + error.message;
        messagesContainer.appendChild(errorMsg);
    }

    input.disabled = false;
    sendBtn.disabled = false;
    input.focus();
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

function formatTicketAiResponse(text, articlesList) {
    // Replace quoted article titles with hyperlinks
    if (articlesList && articlesList.length > 0) {
        const sorted = [...articlesList].sort((a, b) => b.title.length - a.title.length);
        sorted.forEach(article => {
            const escapedTitle = article.title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const regex = new RegExp('["\u201c]' + escapedTitle + '(\\s*\\(ID:\\s*\\d+\\))?["\u201d]', 'gi');
            const link = '<a href="../knowledge/?id=' + article.id + '" target="_blank" class="ai-article-link">\u201c' + escapeHtml(article.title) + '\u201d</a>';
            text = text.replace(regex, link);
        });
    }

    // Markdown-like formatting
    text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/__(.*?)__/g, '<strong>$1</strong>');
    text = text.replace(/\*([^*]+)\*/g, '<em>$1</em>');
    text = text.replace(/(?<!\w)_([^_]+)_(?!\w)/g, '<em>$1</em>');
    text = text.replace(/`([^`]+)`/g, '<code>$1</code>');

    // Paragraphs and lists
    const paragraphs = text.split(/\n\n+/);
    if (paragraphs.length > 1) {
        text = paragraphs.map(p => {
            p = p.trim();
            if (!p) return '';
            if (/^[-*]\s/.test(p) || /^\d+\.\s/.test(p)) {
                const items = p.split(/\n/).map(line => {
                    line = line.replace(/^[-*]\s+/, '').replace(/^\d+\.\s+/, '');
                    return '<li>' + line + '</li>';
                }).join('');
                return '<ul>' + items + '</ul>';
            }
            return '<p>' + p.replace(/\n/g, '<br>') + '</p>';
        }).join('');
    } else {
        if (/^[-*]\s/m.test(text) || /^\d+\.\s/m.test(text)) {
            const lines = text.split(/\n/);
            let html = '';
            let inList = false;
            lines.forEach(line => {
                const isListItem = /^[-*]\s/.test(line) || /^\d+\.\s/.test(line);
                if (isListItem) {
                    if (!inList) { html += '<ul>'; inList = true; }
                    line = line.replace(/^[-*]\s+/, '').replace(/^\d+\.\s+/, '');
                    html += '<li>' + line + '</li>';
                } else {
                    if (inList) { html += '</ul>'; inList = false; }
                    html += (line.trim() ? '<p>' + line + '</p>' : '');
                }
            });
            if (inList) html += '</ul>';
            text = html;
        } else {
            text = '<p>' + text.replace(/\n/g, '<br>') + '</p>';
        }
    }

    return text;
}

// Close AI chat on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const panel = document.getElementById('ticketAiPanel');
        if (panel && panel.classList.contains('active')) {
            closeTicketAiChat();
        }
    }
});

/* --- Pop-out (full-screen) ticket view ---
 * Toggles a body class that hides the folder list + email list and floats the
 * ticket properties container as a right-hand panel. Pure CSS — no DOM
 * restructuring. Preference persists in localStorage so the analyst's choice
 * sticks across reloads / ticket selections.
 */
function toggleTicketPopout() {
    const on = document.body.classList.toggle('ticket-popout');
    try { localStorage.setItem('tickets_popout', on ? '1' : '0'); } catch (e) {}
}

/* Double-click on an email row: open it AND pop out. Sets the popout pref
 * so the syncPopoutToTicketState call inside displayEmail applies the class
 * once the ticket renders. Goes through the same storage path as the toggle
 * button so the state is consistent (an F5 mid-popout will land you in 3-col
 * view, but as soon as you pick a ticket again you're back in popout). */
function selectEmailFullScreen(emailId) {
    try { localStorage.setItem('tickets_popout', '1'); } catch (e) {}
    selectEmail(emailId);
}

/* --- Time entries ----------------------------------------------------------
 * Per-ticket time logging. List + inline add form, soft-delete on own rows.
 * API lives at api/tickets/{get,save,delete}_time_entry.php.
 */
let currentTimeEntries = [];

async function loadTimeEntries(ticketId) {
    try {
        const response = await fetch(`${API_BASE}get_time_entries.php?ticket_id=${ticketId}`);
        const data = await response.json();
        currentTimeEntries = data.success ? data.entries : [];
        renderTimeEntries(data.success ? data.total_minutes : 0);
    } catch (e) {
        console.error('Time entries load failed:', e);
        currentTimeEntries = [];
        renderTimeEntries(0);
    }
}

// Convert minutes int to a short display string: 45 → "45m", 90 → "1h 30m".
function formatMinutes(mins) {
    mins = Math.max(0, parseInt(mins, 10) || 0);
    if (mins < 60) return mins + 'm';
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return m ? `${h}h ${m}m` : `${h}h`;
}

function renderTimeEntries(totalMinutes) {
    const container = document.getElementById('timeEntriesContainer');
    if (!container) return;

    const myAnalystId = window.CURRENT_ANALYST_ID || 0;
    const deleteTitle = t('tickets.time_entries.delete_title');
    const totalLabel = totalMinutes > 0
        ? ' &middot; ' + escapeHtml(t('tickets.time_entries.total_prefix', { amount: formatMinutes(totalMinutes) }))
        : '';

    let rowsHtml = '';
    if (currentTimeEntries.length === 0) {
        rowsHtml = `<div class="time-entry-empty">${escapeHtml(t('tickets.time_entries.empty'))}</div>`;
    } else {
        rowsHtml = currentTimeEntries.map(e => {
            const canDelete = parseInt(e.analyst_id, 10) === parseInt(myAnalystId, 10);
            const deleteBtn = canDelete
                ? `<button class="time-entry-delete" onclick="deleteTimeEntry(${e.id})" title="${escapeHtml(deleteTitle)}" aria-label="${escapeHtml(deleteTitle)}">&times;</button>`
                : '';
            const notesHtml = e.notes
                ? `<div class="time-entry-notes">${escapeHtml(e.notes)}</div>`
                : '';
            return `
                <div class="time-entry-item">
                    <div class="time-entry-row">
                        <span class="time-entry-spent">${escapeHtml(formatMinutes(e.time_spent_minutes))}</span>
                        <span class="time-entry-analyst">${escapeHtml(e.analyst_name)}</span>
                        <span class="time-entry-date">${formatDateTime(e.entry_datetime)}</span>
                        ${deleteBtn}
                    </div>
                    ${notesHtml}
                </div>
            `;
        }).join('');
    }

    container.innerHTML = `
        <div class="time-entries-section">
            <div class="time-entries-header">${escapeHtml(t('tickets.time_entries.section_title'))}${totalLabel}</div>
            <form class="time-entry-form" onsubmit="event.preventDefault(); saveTimeEntry();">
                <input type="number" id="timeEntryMinutes" class="time-entry-input-minutes"
                       min="1" step="1" placeholder="${escapeHtml(t('tickets.time_entries.minutes_placeholder'))}" required>
                <input type="text" id="timeEntryNotes" class="time-entry-input-notes"
                       placeholder="${escapeHtml(t('tickets.time_entries.notes_placeholder'))}">
                <button type="submit" class="time-entry-add-btn">${escapeHtml(t('tickets.time_entries.add_btn'))}</button>
            </form>
            <div class="time-entry-list">${rowsHtml}</div>
        </div>
    `;
}

async function saveTimeEntry() {
    if (!currentEmail) return;
    const minutes = parseInt(document.getElementById('timeEntryMinutes').value, 10);
    const notes   = document.getElementById('timeEntryNotes').value.trim();

    if (!minutes || minutes <= 0) {
        showToast(t('tickets.time_entries.minutes_required'), 'error');
        return;
    }

    try {
        const response = await fetch(API_BASE + 'save_time_entry.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: currentEmail.ticket_id,
                time_spent_minutes: minutes,
                notes: notes
            })
        });
        const data = await response.json();
        if (data.success) {
            loadTimeEntries(currentEmail.ticket_id);
        } else {
            showToast(t('tickets.time_entries.save_failed', { error: data.error || 'unknown error' }), 'error');
        }
    } catch (e) {
        console.error('Save time entry failed:', e);
        showToast(t('tickets.time_entries.save_failed', { error: 'network error' }), 'error');
    }
}

async function deleteTimeEntry(id) {
    if (!(await showConfirm({ title: 'Confirm', message: t('tickets.time_entries.delete_confirm'), okLabel: 'OK', okClass: 'primary' }))) return;
    try {
        const response = await fetch(API_BASE + 'delete_time_entry.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        });
        const data = await response.json();
        if (data.success) {
            if (currentEmail) loadTimeEntries(currentEmail.ticket_id);
        } else {
            showToast(t('tickets.time_entries.delete_failed', { error: data.error || 'unknown error' }), 'error');
        }
    } catch (e) {
        console.error('Delete time entry failed:', e);
        showToast(t('tickets.time_entries.delete_failed', { error: 'network error' }), 'error');
    }
}

// Sync body.ticket-popout to the actual reading-pane state. Called by the
// ticket-render path (hasTicket=true: apply class if pref says so) and by
// every empty / loading / error state in the reading pane (hasTicket=false:
// always strip the class). Tying popout to a rendered ticket avoids the
// trap where an F5 with the pref saved leaves the user with folder + list
// hidden and the empty "select a ticket" message in the reading pane.
function syncPopoutToTicketState(hasTicket) {
    if (!hasTicket) {
        document.body.classList.remove('ticket-popout');
        return;
    }
    let prefersPopout = false;
    try { prefersPopout = localStorage.getItem('tickets_popout') === '1'; } catch (e) {}
    if (prefersPopout) document.body.classList.add('ticket-popout');
}

/* --- Right-click context menu for email rows -------------------------------
 * Two actions to start: link CMDB object(s), and record time. Both operate
 * on the right-clicked ticket without changing the current reading-pane
 * selection — handy when you're reading ticket A but need to log time
 * against ticket B without losing your place.
 */
let ctxTargetTicketId = null;
let ctxTargetTicketRef = '';
let ctxCmdbAcTimer = null;
let ctxCmdbSessionCount = 0;

function openTicketContextMenu(event, ticketId, ticketRef) {
    event.preventDefault();
    ctxTargetTicketId = ticketId;
    ctxTargetTicketRef = ticketRef || ('Ticket ' + ticketId);
    const menu = document.getElementById('ticketContextMenu');
    if (!menu) return;

    document.getElementById('ticketContextMenuHeader').textContent = ctxTargetTicketRef;

    // Populate the Set-status / Set-priority / Assign-to submenus from
    // their lookups. Rebuilt each time so newly-added entries appear
    // without a page refresh, and so the current value gets a tick when
    // right-clicking the ticket that's already open in the reading pane.
    populateContextStatusSubmenu();
    populateContextPrioritySubmenu();
    populateContextDepartmentSubmenu();
    populateContextTypeSubmenu();
    populateContextAssigneeSubmenu();
    populateContextCompanySubmenu();

    // Position at cursor — flip if it would overflow the viewport
    menu.classList.add('active');
    const rect = menu.getBoundingClientRect();
    let x = event.clientX;
    let y = event.clientY;
    if (x + rect.width  > window.innerWidth)  x = window.innerWidth  - rect.width  - 4;
    if (y + rect.height > window.innerHeight) y = window.innerHeight - rect.height - 4;
    menu.style.left = x + 'px';
    menu.style.top  = y + 'px';

    // Submenu position — flip leftward when the parent menu lives close to
    // the right edge of the viewport and the submenu wouldn't fit on the right.
    const SUBMENU_W = 220;
    menu.classList.toggle('flip-sub', x + rect.width + SUBMENU_W > window.innerWidth);
}

// Build the Set-status submenu HTML from the active ticket_statuses lookup.
// Tick mark on the row matching the currently-open ticket's status (only
// shows when right-clicking the ticket that's in the reading pane, since
// context actions can target any ticket regardless of selection).
function populateContextStatusSubmenu() {
    const sub = document.getElementById('ctxStatusSubmenu');
    if (!sub) return;
    if (!ticketStatuses.length) {
        sub.innerHTML = '<div class="ticket-context-submenu-item" style="color:#999; font-style: italic; cursor: default;">' + escapeHtml(t('tickets.context.no_statuses')) + '</div>';
        return;
    }
    const currentStatus = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId)
        ? (currentEmail.status || '')
        : '';
    sub.innerHTML = ticketStatuses.map(s => {
        const isCurrent = (s.name === currentStatus);
        const swatch = s.colour
            ? `<span class="ctx-status-swatch" style="background: ${escapeHtml(s.colour)};"></span>`
            : '<span class="ctx-status-swatch" style="background:#ddd;"></span>';
        return `<div class="ticket-context-submenu-item" data-status-name="${escapeHtml(s.name)}" onclick="setStatusFromContext('${escapeHtml(s.name).replace(/'/g, "\\'")}')">
            ${swatch}<span class="ctx-status-name">${escapeHtml(s.name)}</span>${isCurrent ? '<span class="ctx-status-check">&#10003;</span>' : ''}
        </div>`;
    }).join('');
}

// Build the Set-priority submenu HTML from the active ticket_priorities
// lookup. Priority is nullable on tickets, so the first row is a "no priority"
// option that clears the assignment. Same chip + tick pattern as the status
// submenu — colour swatch from the priority's stored colour.
function populateContextPrioritySubmenu() {
    const sub = document.getElementById('ctxPrioritySubmenu');
    if (!sub) return;
    if (!ticketPriorities.length) {
        sub.innerHTML = '<div class="ticket-context-submenu-item" style="color:#999; font-style: italic; cursor: default;">' + escapeHtml(t('tickets.context.no_priorities')) + '</div>';
        return;
    }
    const currentPriorityId = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId)
        ? (currentEmail.priority_id ?? null)
        : undefined;
    // "No priority" row that clears the assignment (priority_id is nullable).
    const clearRow = `<div class="ticket-context-submenu-item" data-priority-id="" onclick="setPriorityFromContext('')">
        <span class="ctx-status-swatch" style="background: transparent; border-style: dashed;"></span>
        <span class="ctx-status-name" style="color:#888; font-style: italic;">${escapeHtml(t('tickets.context.clear_priority'))}</span>
        ${(currentPriorityId === null || currentPriorityId === undefined) && currentEmail && currentEmail.ticket_id == ctxTargetTicketId ? '<span class="ctx-status-check">&#10003;</span>' : ''}
    </div>`;
    sub.innerHTML = clearRow + ticketPriorities.map(p => {
        const isCurrent = (currentPriorityId != null && p.id == currentPriorityId);
        const swatch = p.colour
            ? `<span class="ctx-status-swatch" style="background: ${escapeHtml(p.colour)};"></span>`
            : '<span class="ctx-status-swatch" style="background:#ddd;"></span>';
        return `<div class="ticket-context-submenu-item" data-priority-id="${p.id}" onclick="setPriorityFromContext(${p.id})">
            ${swatch}<span class="ctx-status-name">${escapeHtml(p.name)}</span>${isCurrent ? '<span class="ctx-status-check">&#10003;</span>' : ''}
        </div>`;
    }).join('');
}

// Set a ticket's priority from the right-click menu. Empty string clears the
// priority (priority_id is nullable on tickets). SLA recomputes lazily on
// the next ticket fetch — no explicit recompute call needed.
async function setPriorityFromContext(priorityId) {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    const targetId = ctxTargetTicketId;
    const newRow   = priorityId !== '' ? ticketPriorities.find(p => p.id == priorityId) : null;
    const newLabel = newRow ? newRow.name : '';
    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: targetId,
                priority_id: priorityId === '' ? null : priorityId
            })
        });
        const data = await response.json();
        if (!data.success) {
            showToast('Error setting priority: ' + (data.error || 'unknown'), 'error');
            return;
        }
        try {
            const oldRow   = (currentEmail && currentEmail.ticket_id == targetId)
                ? ticketPriorities.find(p => p.id == currentEmail.priority_id)
                : null;
            const oldLabel = oldRow ? oldRow.name : '';
            await logAudit(targetId, 'Priority', oldLabel, newLabel);
        } catch (e) { /* audit is best-effort */ }
        // Keep the open ticket's toolbar in sync when the same ticket is in the reading pane.
        if (currentEmail && currentEmail.ticket_id == targetId) {
            currentEmail.priority_id = priorityId === '' ? null : Number(priorityId);
            currentEmail.priority    = newLabel;
            const sel = document.getElementById('prioritySelect');
            if (sel) sel.value = priorityId === '' ? '' : String(priorityId);
            updatePropertiesSummary();
        }
        loadEmails();
    } catch (error) {
        console.error('Error setting priority from context:', error);
        showToast('Failed to set priority', 'error');
    }
}

// Build the Set-department submenu HTML from the analyst's team departments
// (same `departments` lookup as the in-panel Department dropdown). department_id
// is nullable, so the first row is a "(no department)" option that clears it.
function populateContextDepartmentSubmenu() {
    const sub = document.getElementById('ctxDepartmentSubmenu');
    if (!sub) return;
    if (!departments.length) {
        sub.innerHTML = '<div class="ticket-context-submenu-item" style="color:#999; font-style: italic; cursor: default;">' + escapeHtml(t('tickets.context.no_departments')) + '</div>';
        return;
    }
    const currentDeptId = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId)
        ? (currentEmail.department_id ?? null)
        : undefined;
    const onOpenTicket = currentEmail && currentEmail.ticket_id == ctxTargetTicketId;
    const clearRow = `<div class="ticket-context-submenu-item" data-department-id="" onclick="setDepartmentFromContext('')">
        <span class="ctx-status-swatch" style="background: transparent; border-style: dashed;"></span>
        <span class="ctx-status-name" style="color:#888; font-style: italic;">${escapeHtml(t('tickets.context.clear_department'))}</span>
        ${(currentDeptId === null || currentDeptId === undefined) && onOpenTicket ? '<span class="ctx-status-check">&#10003;</span>' : ''}
    </div>`;
    sub.innerHTML = clearRow + departments.map(d => {
        const isCurrent = (currentDeptId != null && d.id == currentDeptId);
        return `<div class="ticket-context-submenu-item" data-department-id="${d.id}" onclick="setDepartmentFromContext(${d.id})">
            <span class="ctx-status-swatch" style="background:#e5e7eb; border:none;"></span><span class="ctx-status-name">${escapeHtml(d.name)}</span>${isCurrent ? '<span class="ctx-status-check">&#10003;</span>' : ''}
        </div>`;
    }).join('');
}

// Set a ticket's department from the right-click menu. Empty string clears it
// (department_id is nullable). Refreshes folder counts + the list because the
// inbox can be grouped by department.
async function setDepartmentFromContext(departmentId) {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    const targetId = ctxTargetTicketId;
    const newRow   = departmentId !== '' ? departments.find(d => d.id == departmentId) : null;
    const newLabel = newRow ? newRow.name : '';
    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: targetId,
                department_id: departmentId === '' ? null : departmentId
            })
        });
        const data = await response.json();
        if (!data.success) {
            showToast('Error setting department: ' + (data.error || 'unknown'), 'error');
            return;
        }
        try {
            const oldLabel = (currentEmail && currentEmail.ticket_id == targetId)
                ? (getDisplayName('department', currentEmail.department_id) || '')
                : '';
            await logAudit(targetId, 'Department', oldLabel, newLabel);
        } catch (e) { /* audit is best-effort */ }
        // Keep the open ticket's toolbar in sync when it's the same one.
        if (currentEmail && currentEmail.ticket_id == targetId) {
            currentEmail.department_id = departmentId === '' ? null : Number(departmentId);
            const sel = document.getElementById('departmentSelect');
            if (sel) sel.value = departmentId === '' ? '' : String(departmentId);
            updatePropertiesSummary();
        }
        loadFolderCounts();
        loadEmails();
    } catch (error) {
        console.error('Error setting department from context:', error);
        showToast('Failed to set department', 'error');
    }
}

// Build the Move-to-company submenu (multi-company installs only; hidden at N=1).
// Lists the companies this analyst can access; the current one is ticked when
// right-clicking the ticket open in the reading pane.
function populateContextCompanySubmenu() {
    const parent = document.getElementById('ctxCompanyParent');
    const sub = document.getElementById('ctxCompanySubmenu');
    if (!parent || !sub) return;
    if (!isMultiCompany || !moveCompanies.length) {
        parent.style.display = 'none';
        return;
    }
    parent.style.display = '';
    const currentTid = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId)
        ? (currentEmail.tenant_id ?? (moveCompanies.find(c => c.is_default) || {}).id)
        : undefined;
    sub.innerHTML = moveCompanies.map(c => {
        const isCurrent = (currentTid != null && String(c.id) === String(currentTid));
        return `<div class="ticket-context-submenu-item" data-tenant-id="${c.id}" onclick="moveToCompanyFromContext(${c.id})">
            <span class="ctx-status-swatch" style="background:#ede7f6; border:none;"></span><span class="ctx-status-name">${escapeHtml(c.name)}</span>${isCurrent ? '<span class="ctx-status-check">&#10003;</span>' : ''}
        </div>`;
    }).join('');
}

// Move a ticket to another company from the right-click menu. The endpoint writes
// the audit entry server-side, so (unlike the other context actions) there's no
// client-side logAudit here.
async function moveToCompanyFromContext(tenantId) {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    const targetId = ctxTargetTicketId;
    try {
        const res = await fetch(API_BASE + 'move_ticket_to_company.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: targetId, tenant_id: tenantId })
        });
        const data = await res.json();
        if (!data.success) {
            showToast('Could not move ticket: ' + (data.error || 'unknown error'), 'error');
            return;
        }
        showToast(data.message || 'Ticket moved', 'success');
        if (currentEmail && currentEmail.ticket_id == targetId) {
            currentEmail.tenant_id = tenantId;
            selectEmail(currentEmail.id); // refresh the open ticket's company field + banner
        }
        loadFolderCounts();
        loadEmails();
    } catch (e) {
        showToast('Failed to move ticket', 'error');
    }
}

// Build the Set-type submenu HTML from the active ticket_types lookup (same
// `ticketTypes` source as the in-panel Type dropdown). ticket_type_id is
// nullable, so the first row is a "(no type)" option that clears it.
function populateContextTypeSubmenu() {
    const sub = document.getElementById('ctxTypeSubmenu');
    if (!sub) return;
    if (!ticketTypes.length) {
        sub.innerHTML = '<div class="ticket-context-submenu-item" style="color:#999; font-style: italic; cursor: default;">' + escapeHtml(t('tickets.context.no_types')) + '</div>';
        return;
    }
    const currentTypeId = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId)
        ? (currentEmail.ticket_type_id ?? null)
        : undefined;
    const onOpenTicket = currentEmail && currentEmail.ticket_id == ctxTargetTicketId;
    const clearRow = `<div class="ticket-context-submenu-item" data-type-id="" onclick="setTypeFromContext('')">
        <span class="ctx-status-swatch" style="background: transparent; border-style: dashed;"></span>
        <span class="ctx-status-name" style="color:#888; font-style: italic;">${escapeHtml(t('tickets.context.clear_type'))}</span>
        ${(currentTypeId === null || currentTypeId === undefined) && onOpenTicket ? '<span class="ctx-status-check">&#10003;</span>' : ''}
    </div>`;
    sub.innerHTML = clearRow + ticketTypes.map(tt => {
        const isCurrent = (currentTypeId != null && tt.id == currentTypeId);
        return `<div class="ticket-context-submenu-item" data-type-id="${tt.id}" onclick="setTypeFromContext(${tt.id})">
            <span class="ctx-status-swatch" style="background:#e5e7eb; border:none;"></span><span class="ctx-status-name">${escapeHtml(tt.name)}</span>${isCurrent ? '<span class="ctx-status-check">&#10003;</span>' : ''}
        </div>`;
    }).join('');
}

// Set a ticket's type from the right-click menu. Empty string clears it
// (ticket_type_id is nullable).
async function setTypeFromContext(typeId) {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    const targetId = ctxTargetTicketId;
    const newRow   = typeId !== '' ? ticketTypes.find(t => t.id == typeId) : null;
    const newLabel = newRow ? newRow.name : '';
    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: targetId,
                ticket_type_id: typeId === '' ? null : typeId
            })
        });
        const data = await response.json();
        if (!data.success) {
            showToast('Error setting type: ' + (data.error || 'unknown'), 'error');
            return;
        }
        try {
            const oldLabel = (currentEmail && currentEmail.ticket_id == targetId)
                ? (getDisplayName('ticket_type', currentEmail.ticket_type_id) || '')
                : '';
            await logAudit(targetId, 'Ticket Type', oldLabel, newLabel);
        } catch (e) { /* audit is best-effort */ }
        // Keep the open ticket's toolbar in sync when it's the same one.
        if (currentEmail && currentEmail.ticket_id == targetId) {
            currentEmail.ticket_type_id = typeId === '' ? null : Number(typeId);
            const sel = document.getElementById('ticketTypeSelect');
            if (sel) sel.value = typeId === '' ? '' : String(typeId);
            updatePropertiesSummary();
        }
        loadEmails();
    } catch (error) {
        console.error('Error setting type from context:', error);
        showToast('Failed to set type', 'error');
    }
}

// Build the Assign-to submenu HTML from the loaded analysts list. Picking
// an analyst sets both assigned_analyst_id and owner_id (mirrors the
// drag-to-analyst-folder behaviour in assign_ticket.php). The first row
// is an "Unassigned" option that clears both fields.
function populateContextAssigneeSubmenu() {
    const sub = document.getElementById('ctxAssigneeSubmenu');
    if (!sub) return;
    if (!analysts.length) {
        sub.innerHTML = '<div class="ticket-context-submenu-item" style="color:#999; font-style: italic; cursor: default;">' + escapeHtml(t('tickets.context.no_analysts')) + '</div>';
        return;
    }
    // Use owner_id as the "currently assigned" indicator since drag-to-folder
    // keeps owner_id and assigned_analyst_id in sync; the in-panel Owner
    // dropdown is also the canonical assignment view.
    const currentOwnerId = (currentEmail && currentEmail.ticket_id == ctxTargetTicketId)
        ? (currentEmail.owner_id ?? null)
        : undefined;
    const clearRow = `<div class="ticket-context-submenu-item" data-analyst-id="" onclick="setAssigneeFromContext('')">
        <span class="ctx-status-swatch" style="background: transparent; border-style: dashed;"></span>
        <span class="ctx-status-name" style="color:#888; font-style: italic;">${escapeHtml(t('tickets.context.clear_assignee'))}</span>
        ${(currentOwnerId === null) ? '<span class="ctx-status-check">&#10003;</span>' : ''}
    </div>`;
    sub.innerHTML = clearRow + analysts.map(a => {
        const isCurrent = (currentOwnerId != null && a.id == currentOwnerId);
        // Use the first letter of the name as a colourless initial chip — keeps
        // the row visually aligned with status / priority swatches without
        // inventing a colour per analyst.
        const initial = (a.full_name || '').charAt(0).toUpperCase() || '?';
        const initialChip = `<span class="ctx-status-swatch" style="background:#e5e7eb; color:#374151; font-size:9px; font-weight:600; display:inline-flex; align-items:center; justify-content:center; border:none;">${escapeHtml(initial)}</span>`;
        return `<div class="ticket-context-submenu-item" data-analyst-id="${a.id}" onclick="setAssigneeFromContext(${a.id})">
            ${initialChip}<span class="ctx-status-name">${escapeHtml(a.full_name)}</span>${isCurrent ? '<span class="ctx-status-check">&#10003;</span>' : ''}
        </div>`;
    }).join('');
}

// Set a ticket's assignee from the right-click menu. Empty string = unassign.
// Sends assigned_analyst_id to assign_ticket.php, which sets both
// assigned_analyst_id and owner_id (same behaviour as drag-to-folder).
async function setAssigneeFromContext(analystId) {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    const targetId = ctxTargetTicketId;
    const newRow   = analystId !== '' ? analysts.find(a => a.id == analystId) : null;
    const newLabel = newRow ? newRow.full_name : '';
    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: targetId,
                assigned_analyst_id: analystId === '' ? null : analystId
            })
        });
        const data = await response.json();
        if (!data.success) {
            showToast('Error assigning ticket: ' + (data.error || 'unknown'), 'error');
            return;
        }
        try {
            const oldRow   = (currentEmail && currentEmail.ticket_id == targetId)
                ? analysts.find(a => a.id == currentEmail.owner_id)
                : null;
            const oldLabel = oldRow ? oldRow.full_name : '';
            await logAudit(targetId, 'Owner', oldLabel, newLabel);
        } catch (e) { /* audit best-effort */ }
        // Keep the open ticket's toolbar in sync when it's the same one.
        if (currentEmail && currentEmail.ticket_id == targetId) {
            currentEmail.owner_id = analystId === '' ? null : Number(analystId);
            currentEmail.assigned_analyst_id = analystId === '' ? null : Number(analystId);
            const sel = document.getElementById('ownerSelect');
            if (sel) sel.value = analystId === '' ? '' : String(analystId);
            updatePropertiesSummary();
        }
        loadFolderCounts();
        loadEmails();
    } catch (error) {
        console.error('Error assigning ticket from context:', error);
        showToast('Failed to assign ticket', 'error');
    }
}

// Set a ticket's status from the right-click menu — works on whichever ticket
// was right-clicked, even if a different ticket is open in the reading pane.
async function setStatusFromContext(statusName) {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    const targetId = ctxTargetTicketId;
    try {
        const response = await fetch(API_BASE + 'assign_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: targetId,
                status: statusName
            })
        });
        const data = await response.json();
        if (!data.success) {
            showToast('Error setting status: ' + (data.error || 'unknown'), 'error');
            return;
        }
        // Audit-trail entry mirrors the in-panel assignStatus() flow.
        try {
            const oldStatus = (currentEmail && currentEmail.ticket_id == targetId) ? (currentEmail.status || '') : '';
            await logAudit(targetId, 'Status', oldStatus, statusName);
        } catch (e) { /* audit is best-effort */ }
        // If the same ticket is open in the reading pane, keep its toolbar in sync.
        if (currentEmail && currentEmail.ticket_id == targetId) {
            currentEmail.status = statusName;
            const sel = document.getElementById('statusSelect');
            if (sel) sel.value = statusName;
            updatePropertiesSummary();
        }
        loadFolderCounts();
        loadEmails();
    } catch (error) {
        console.error('Error setting status from context:', error);
        showToast('Failed to set status', 'error');
    }
}

function closeTicketContextMenu() {
    const menu = document.getElementById('ticketContextMenu');
    if (menu) menu.classList.remove('active');
}

// Right-click a ticket -> Move to trash (soft-delete the context-menu target).
async function contextMoveToTrash() {
    const id = ctxTargetTicketId;
    const ref = ctxTargetTicketRef;
    closeTicketContextMenu();
    if (!id) return;
    try {
        const res = await fetch(API_BASE + 'delete_ticket.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: id })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'failed');
        showToast(`${ref || 'Ticket'} → Trash`, 'success');
        clearReadingPaneIfTicket(id);
        loadFolderCounts();
        loadEmails();
    } catch (e) { showToast('Move to trash failed: ' + e.message, 'error'); }
}

// ===== Trash folder context menu (Empty trash) =====
function openTrashContextMenu(event) {
    event.preventDefault();
    const menu = document.getElementById('trashContextMenu');
    if (!menu) return;
    menu.classList.add('active');
    const rect = menu.getBoundingClientRect();
    let x = event.clientX, y = event.clientY;
    if (x + rect.width  > window.innerWidth)  x = window.innerWidth  - rect.width  - 4;
    if (y + rect.height > window.innerHeight) y = window.innerHeight - rect.height - 4;
    menu.style.left = x + 'px';
    menu.style.top = y + 'px';
}
function closeTrashContextMenu() {
    const m = document.getElementById('trashContextMenu');
    if (m) m.classList.remove('active');
}
async function emptyTrash() {
    closeTrashContextMenu();
    const n = folderCounts.trash_count || 0;
    if (n === 0) { showToast('Trash is already empty', 'info'); return; }
    if (!(await showConfirm({
        title: 'Empty trash',
        message: `Permanently delete all ${n} ticket(s) in the trash, including their emails, attachments and notes? This cannot be undone.`,
        okLabel: 'Empty trash', okClass: 'danger'
    }))) return;
    try {
        const res = await fetch(API_BASE + 'empty_trash.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}'
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'failed');
        showToast(`Trash emptied — ${data.deleted} ticket(s) permanently deleted`, 'success');
        currentEmail = null;
        selectedEmailId = null;
        document.getElementById('readingPane').innerHTML = '<div class="reading-pane-empty">Select an email to read</div>';
        loadFolderCounts();
        if (currentFilter.type === 'trash') loadEmails();
    } catch (e) { showToast('Empty trash failed: ' + e.message, 'error'); }
}

// Outside click + Escape close the menus
document.addEventListener('mousedown', function (e) {
    const menu = document.getElementById('ticketContextMenu');
    if (menu && menu.classList.contains('active') && !menu.contains(e.target)) closeTicketContextMenu();
    const tmenu = document.getElementById('trashContextMenu');
    if (tmenu && tmenu.classList.contains('active') && !tmenu.contains(e.target)) closeTrashContextMenu();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeTicketContextMenu(); closeTrashContextMenu(); }
});
// Right-clicking a different row should reopen, not stack
window.addEventListener('blur', closeTicketContextMenu);
window.addEventListener('scroll', closeTicketContextMenu, true);

/* --- Context menu action: Link CMDB object --- */
function openContextLinkCmdb() {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    document.getElementById('ctxCmdbTicketRef').textContent = ctxTargetTicketRef;
    document.getElementById('ctxCmdbSearchInput').value = '';
    document.getElementById('ctxCmdbResults').innerHTML = '';
    ctxCmdbSessionCount = 0;
    document.getElementById('ctxCmdbSessionLog').textContent = 'None yet — pick from the search results above.';
    document.getElementById('ctxCmdbModal').classList.add('active');
    setTimeout(() => document.getElementById('ctxCmdbSearchInput').focus(), 50);
}

function closeContextCmdbModal() {
    document.getElementById('ctxCmdbModal').classList.remove('active');
    // If we linked anything and the affected ticket is the one currently open
    // in the reading pane, refresh its CMDB-objects list so the UI matches.
    if (ctxCmdbSessionCount > 0 && currentEmail && parseInt(currentEmail.ticket_id, 10) === parseInt(ctxTargetTicketId, 10)) {
        loadCmdbObjects(currentEmail.ticket_id);
    }
}

// Wire search-as-you-type once
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('ctxCmdbSearchInput');
    if (!input) return;
    input.addEventListener('input', function () {
        const q = input.value.trim();
        const results = document.getElementById('ctxCmdbResults');
        if (ctxCmdbAcTimer) clearTimeout(ctxCmdbAcTimer);
        if (q === '') { results.innerHTML = ''; return; }
        ctxCmdbAcTimer = setTimeout(async () => {
            try {
                const res = await fetch('../api/cmdb/search_objects.php?q=' + encodeURIComponent(q));
                const data = await res.json();
                const rows = data.success ? (data.results || []) : [];
                if (rows.length === 0) {
                    results.innerHTML = '<div class="ctx-cmdb-result" style="cursor:default;color:#999;font-style:italic;">No matches.</div>';
                    return;
                }
                results.innerHTML = rows.map(r => `
                    <div class="ctx-cmdb-result" data-id="${r.id}" data-name="${escapeHtml(r.name)}">
                        <span class="ctx-cmdb-result-name">${escapeHtml(r.name)}</span>
                        <span class="ctx-cmdb-result-class">${escapeHtml(r.class_name)}</span>
                    </div>
                `).join('');
                results.querySelectorAll('.ctx-cmdb-result[data-id]').forEach(el => {
                    el.addEventListener('click', () => linkContextCmdbObject(parseInt(el.dataset.id, 10), el.dataset.name));
                });
            } catch (e) {
                results.innerHTML = '<div class="ctx-cmdb-result" style="cursor:default;color:#c62828;">Search failed.</div>';
            }
        }, 200);
    });
});

async function linkContextCmdbObject(objectId, objectName) {
    if (!ctxTargetTicketId) return;
    try {
        const res = await fetch('../api/tickets/save_ticket_cmdb_object.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ctxTargetTicketId, cmdb_object_id: objectId })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Link failed');

        ctxCmdbSessionCount++;
        const logEl = document.getElementById('ctxCmdbSessionLog');
        if (data.already_linked) {
            showToast(objectName + ' is already linked', 'error');
        } else {
            showToast('Linked ' + objectName, 'success');
            const line = document.createElement('div');
            line.textContent = '✓ ' + objectName;
            line.style.color = '#16a34a';
            if (ctxCmdbSessionCount === 1) logEl.innerHTML = '';
            logEl.appendChild(line);
        }
        // Clear input for the next pick — keeps the modal open for multi-link
        const input = document.getElementById('ctxCmdbSearchInput');
        input.value = '';
        input.focus();
        document.getElementById('ctxCmdbResults').innerHTML = '';
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    }
}

/* --- Context menu action: Record time --- */
function openContextRecordTime() {
    closeTicketContextMenu();
    if (!ctxTargetTicketId) return;
    document.getElementById('ctxTimeTicketRef').textContent = ctxTargetTicketRef;
    document.getElementById('ctxTimeMinutes').value = '';
    document.getElementById('ctxTimeNotes').value = '';
    // Default the datetime-local field to "now" (local time, no seconds)
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    const localNow = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
    document.getElementById('ctxTimeWhen').value = localNow;
    document.getElementById('ctxTimeModal').classList.add('active');
    setTimeout(() => document.getElementById('ctxTimeMinutes').focus(), 50);
}

function closeContextTimeModal() {
    document.getElementById('ctxTimeModal').classList.remove('active');
}

async function saveContextTimeEntry() {
    if (!ctxTargetTicketId) return;
    const minutes = parseInt(document.getElementById('ctxTimeMinutes').value, 10);
    const notes   = document.getElementById('ctxTimeNotes').value.trim();
    const when    = document.getElementById('ctxTimeWhen').value;

    if (!minutes || minutes <= 0) {
        showToast('Enter the number of minutes spent', 'error');
        return;
    }

    try {
        const res = await fetch('../api/tickets/save_time_entry.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_id: ctxTargetTicketId,
                time_spent_minutes: minutes,
                notes: notes,
                entry_datetime: when || null
            })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Save failed');
        showToast('Logged ' + minutes + 'm on ' + ctxTargetTicketRef, 'success');
        closeContextTimeModal();
        // If the affected ticket is currently open in the reading pane,
        // refresh its time-entries list so the new row appears.
        if (currentEmail && parseInt(currentEmail.ticket_id, 10) === parseInt(ctxTargetTicketId, 10)) {
            loadTimeEntries(currentEmail.ticket_id);
        }
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    }
}

/* --- SLA panel in the reading pane ---------------------------------------
 * Fetches /api/tickets/get_ticket_sla.php and renders a small status panel
 * showing response + resolution targets, elapsed / remaining / percent, and
 * a green/amber/red colour-coded badge per target. Hides itself silently if
 * SLA is disabled for the ticket (no priority, no target set, ticket pre-dates
 * cutoff, SLA disabled globally) — so the section just doesn't appear and
 * doesn't bother the analyst with "SLA disabled for this ticket" noise.
 */
async function loadSlaState(ticketId) {
    const container = document.getElementById('slaContainer');
    if (!container) return;
    container.innerHTML = '';
    try {
        const res = await fetch(API_BASE + 'get_ticket_sla.php?ticket_id=' + ticketId);
        const data = await res.json();
        if (!data.success || !data.sla || !data.sla.enabled) return; // silent hide
        renderSlaPanel(data.sla);
    } catch (e) {
        console.error('SLA load failed:', e);
    }
}

function renderSlaPanel(sla) {
    const container = document.getElementById('slaContainer');
    if (!container) return;
    if (!sla.response && !sla.resolution) return;

    const fmt = (mins) => {
        if (mins === null || mins === undefined) return '—';
        const n = Math.abs(mins);
        const sign = mins < 0 ? '-' : '';
        if (n < 60) return sign + n + 'm';
        const h = Math.floor(n / 60), r = n % 60;
        return sign + (r ? `${h}h ${r}m` : `${h}h`);
    };

    const renderRow = (label, target) => {
        if (!target) return '';
        const achieved = target.achieved_at !== null;
        // Colour: green if achieved (clock stopped) or < 80%; amber 80-100%; red > 100%
        let cls = 'sla-ok';
        let badge = 'On track';
        if (achieved) {
            cls = target.breached ? 'sla-breached' : 'sla-achieved';
            badge = target.breached ? 'Breached on response' : 'Achieved';
        } else if (target.breached) {
            cls = 'sla-breached';
            badge = 'Breached';
        } else if (target.percent >= 80) {
            cls = 'sla-warning';
            badge = 'Approaching breach';
        }
        const remainingLabel = achieved
            ? `Achieved in ${fmt(target.achieved_minutes)}`
            : (target.breached
                ? `Over by ${fmt(Math.abs(target.remaining_minutes))}`
                : `${fmt(target.remaining_minutes)} remaining`);
        return `
            <div class="sla-row ${cls}">
                <div class="sla-row-head">
                    <span class="sla-row-label">${escapeHtml(label)}</span>
                    <span class="sla-row-badge">${escapeHtml(badge)}</span>
                </div>
                <div class="sla-bar"><div class="sla-bar-fill" style="width: ${Math.min(100, target.percent)}%;"></div></div>
                <div class="sla-row-meta">
                    Target ${fmt(target.target_minutes)} &middot; Elapsed ${fmt(target.elapsed_minutes)} &middot; ${remainingLabel}
                </div>
            </div>
        `;
    };

    // Which policy produced these targets, and where it came from — so an
    // analyst can see at a glance why a ticket is on a given tier (Phase 3b).
    let policyLine = '';
    if (sla.policy && sla.policy.name) {
        const src = sla.policy_source;
        let origin = '';
        if (src === 'device') {
            const dev = sla.policy_device && sla.policy_device.name ? sla.policy_device.name : 'primary device';
            origin = ` &middot; from device <strong>${escapeHtml(dev)}</strong>`;
        } else if (src === 'customer') {
            origin = ' &middot; customer SLA';
        } else if (src === 'default') {
            origin = ' &middot; default SLA';
        }
        policyLine = `<div class="sla-policy-line">Policy: <strong>${escapeHtml(sla.policy.name)}</strong>${origin}</div>`;
    }

    container.innerHTML = `
        <div class="sla-section">
            <div class="sla-section-header">SLA</div>
            ${policyLine}
            ${renderRow('Response', sla.response)}
            ${renderRow('Resolution', sla.resolution)}
        </div>
    `;

    // Phase 4B: mirror a compact status into the summary chip near the top.
    updateSlaSummaryChip(sla);
}

// Compact SLA status for the collapsed properties summary. Shows the most
// urgent target: "Breached" if any target is over, else "Met" if all are
// achieved, else the least remaining time. Hidden when there are no targets.
function updateSlaSummaryChip(sla) {
    const chip = document.getElementById('summarySla');
    if (!chip) return;

    const targets = [];
    if (sla && sla.response) targets.push(sla.response);
    if (sla && sla.resolution) targets.push(sla.resolution);
    if (!targets.length) { chip.style.display = 'none'; return; }

    const fmt = (mins) => {
        if (mins === null || mins === undefined) return '—';
        const n = Math.abs(mins), sign = mins < 0 ? '-' : '';
        if (n < 60) return sign + n + 'm';
        const h = Math.floor(n / 60), r = n % 60;
        return sign + (r ? `${h}h ${r}m` : `${h}h`);
    };

    const active = targets.filter(x => x.achieved_at === null);
    let cls, label;
    if (targets.some(x => x.breached)) {
        cls = 'sla-breached'; label = 'Breached';
    } else if (active.length === 0) {
        cls = 'sla-achieved'; label = 'Met';
    } else {
        let worst = active[0];
        active.forEach(x => { if (x.remaining_minutes < worst.remaining_minutes) worst = x; });
        cls = worst.percent >= 80 ? 'sla-warning' : 'sla-ok';
        label = fmt(worst.remaining_minutes) + ' left';
    }
    chip.className = 'sla-chip ' + cls;
    chip.textContent = 'SLA: ' + label;
    chip.style.display = '';
}
