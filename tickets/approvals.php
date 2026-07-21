<?php
/**
 * Service-request approvals — approver queue (Phase 7d).
 *
 * Any analyst can see the requests waiting on them (approval-gated catalog
 * items name an approver). Admins can switch to the whole org's queue. Deciding
 * is part of the everyday job, so this is gated on plain module access — not a
 * settings capability.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
I18n::initFromSession();
requireModuleAccess('tickets');

$current_page = 'approvals';
$path_prefix = '../';
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Approvals</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        .ap-wrap { flex: 1; padding: 20px 24px; overflow: auto; background: var(--app-bg, #f5f7fa); }
        .ap-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
        .ap-head h2 { margin: 0; font-size: 20px; color: var(--text, #222); }
        .ap-tabs { display: flex; gap: 6px; }
        .ap-tab { padding: 6px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid var(--border, #e5e7eb); background: var(--surface, #fff); color: var(--text-dim, #6b7280); }
        .ap-tab.active { background: #0078d4; color: #fff; border-color: #0078d4; }
        .ap-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.05)); overflow: hidden; }
        table.ap-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .ap-table th, .ap-table td { text-align: left; padding: 11px 14px; border-bottom: 1px solid var(--border, #eee); color: var(--text, #222); vertical-align: middle; }
        .ap-table th { color: var(--text-dim, #6b7280); font-weight: 600; background: var(--surface-alt, #f9fafb); }
        .ap-table tr:last-child td { border-bottom: none; }
        .ap-item { font-weight: 600; }
        .ap-sub { color: var(--text-dim, #6b7280); font-size: 12px; margin-top: 2px; }
        .ap-tno a { color: #0f4c81; text-decoration: none; font-weight: 600; }
        .ap-tno a:hover { text-decoration: underline; }
        .ap-pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
        .ap-pill.approved { background: #dcfce7; color: #166534; }
        .ap-pill.rejected { background: #fee2e2; color: #991b1b; }
        .ap-pill.pending  { background: #fef3c7; color: #92400e; }
        .ap-actions { display: flex; gap: 8px; }
        .ap-note { padding: 40px; text-align: center; color: var(--text-dim, #888); }
        .btn-approve { background: #16a34a; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .btn-approve:hover { background: #15803d; }
        .btn-reject { background: #fff; color: #b91c1c; border: 1px solid #fca5a5; padding: 6px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .btn-reject:hover { background: #fef2f2; }

        .ap-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: none; align-items: center; justify-content: center; z-index: 1000; }
        .ap-modal-backdrop.open { display: flex; }
        .ap-modal { background: var(--surface, #fff); border-radius: 10px; width: 460px; max-width: calc(100vw - 40px); box-shadow: 0 12px 40px rgba(0,0,0,0.25); }
        .ap-modal-head { padding: 16px 20px; border-bottom: 1px solid var(--border, #eee); font-size: 16px; font-weight: 600; color: var(--text, #222); }
        .ap-modal-body { padding: 18px 20px; }
        .ap-modal-body label { display: block; font-size: 12px; font-weight: 600; color: var(--text-dim, #6b7280); margin-bottom: 6px; }
        .ap-modal-body textarea { width: 100%; min-height: 80px; padding: 9px 11px; border: 1px solid var(--border, #ddd); border-radius: 6px; font-size: 13px; font-family: inherit; box-sizing: border-box; resize: vertical; background: var(--surface, #fff); color: var(--text, #222); }
        .ap-modal-foot { padding: 14px 20px; border-top: 1px solid var(--border, #eee); display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .ap-modal-err { color: #b91c1c; font-size: 12.5px; min-height: 16px; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container">
        <div class="ap-wrap">
            <div class="ap-head">
                <h2>Approvals</h2>
                <div class="ap-tabs">
                    <div class="ap-tab active" id="apTabPending" onclick="apSetState('pending')">Pending</div>
                    <div class="ap-tab" id="apTabDecided" onclick="apSetState('decided')">Decided</div>
                    <div class="ap-tab" id="apTabScope" style="display:none;" onclick="apToggleScope()">Show: mine</div>
                </div>
            </div>
            <div class="ap-card">
                <table class="ap-table">
                    <thead>
                        <tr>
                            <th style="width:36%;">Request</th>
                            <th>Ticket</th>
                            <th>Requester</th>
                            <th id="apCol4">Requested</th>
                            <th style="width:180px;"></th>
                        </tr>
                    </thead>
                    <tbody id="apList"><tr><td colspan="5" class="ap-note">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="ap-modal-backdrop" id="apModal">
        <div class="ap-modal">
            <div class="ap-modal-head" id="apModalTitle">Decision</div>
            <div class="ap-modal-body">
                <input type="hidden" id="apDecisionId">
                <input type="hidden" id="apDecision">
                <label id="apNoteLabel" for="apNote">Note (optional)</label>
                <textarea id="apNote" placeholder="Add a note for the record…"></textarea>
            </div>
            <div class="ap-modal-foot">
                <span class="ap-modal-err" id="apErr"></span>
                <div class="ap-actions">
                    <button class="btn btn-secondary" onclick="apCloseModal()">Cancel</button>
                    <button class="btn btn-primary" id="apConfirmBtn" onclick="apConfirm()">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const AP_API = '../api/tickets/';
        const BASE = '<?php echo BASE_URL; ?>';
        let apState = 'pending';
        let apScope = 'mine';
        let apIsAdmin = false;

        function apEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]); }

        function apSetState(s) {
            apState = s;
            document.getElementById('apTabPending').classList.toggle('active', s === 'pending');
            document.getElementById('apTabDecided').classList.toggle('active', s === 'decided');
            document.getElementById('apCol4').textContent = s === 'pending' ? 'Requested' : 'Decided';
            apLoad();
        }
        function apToggleScope() {
            apScope = apScope === 'mine' ? 'all' : 'mine';
            document.getElementById('apTabScope').textContent = 'Show: ' + apScope;
            apLoad();
        }

        async function apLoad() {
            const tb = document.getElementById('apList');
            tb.innerHTML = '<tr><td colspan="5" class="ap-note">Loading…</td></tr>';
            let data;
            try { data = await (await fetch(AP_API + 'get_approvals.php?scope=' + apScope + '&state=' + apState)).json(); }
            catch (e) { tb.innerHTML = '<tr><td colspan="5" class="ap-note">Could not load approvals.</td></tr>'; return; }
            if (!data.success) { tb.innerHTML = '<tr><td colspan="5" class="ap-note">' + apEsc(data.error || 'Could not load approvals.') + '</td></tr>'; return; }

            apIsAdmin = !!data.is_admin;
            document.getElementById('apTabScope').style.display = apIsAdmin ? '' : 'none';

            const rows = data.approvals || [];
            if (!rows.length) {
                tb.innerHTML = '<tr><td colspan="5" class="ap-note">' + (apState === 'pending' ? 'Nothing waiting on you right now. 🎉' : 'No decided requests.') + '</td></tr>';
                return;
            }
            tb.innerHTML = rows.map(r => {
                const ticket = '<span class="ap-tno"><a href="' + BASE + 'tickets/?ticket_id=' + r.ticket_id + '" title="Open ticket">' + apEsc(r.ticket_number) + '</a></span>';
                const when = apState === 'pending' ? apEsc(r.requested || '') : apEsc(r.decided || '');
                let last;
                if (apState === 'pending') {
                    last = '<div class="ap-actions">' +
                        '<button class="btn-approve" onclick="apOpen(' + r.id + ',\'approve\')">Approve</button>' +
                        '<button class="btn-reject" onclick="apOpen(' + r.id + ',\'reject\')">Reject</button></div>';
                } else {
                    last = '<span class="ap-pill ' + apEsc(r.status) + '">' + apEsc(r.status) + '</span>' +
                        (r.note ? '<div class="ap-sub">' + apEsc(r.note) + '</div>' : '');
                }
                return '<tr>' +
                    '<td><div class="ap-item">' + apEsc(r.item_name) + '</div><div class="ap-sub">' + apEsc(r.subject) + '</div></td>' +
                    '<td>' + ticket + '</td>' +
                    '<td>' + apEsc(r.requester) + '</td>' +
                    '<td>' + when + '</td>' +
                    '<td>' + last + '</td>' +
                '</tr>';
            }).join('');
        }

        function apOpen(id, decision) {
            document.getElementById('apDecisionId').value = id;
            document.getElementById('apDecision').value = decision;
            document.getElementById('apModalTitle').textContent = decision === 'approve' ? 'Approve request' : 'Reject request';
            document.getElementById('apNoteLabel').textContent = decision === 'reject' ? 'Reason (recommended)' : 'Note (optional)';
            document.getElementById('apNote').value = '';
            document.getElementById('apErr').textContent = '';
            const btn = document.getElementById('apConfirmBtn');
            btn.textContent = decision === 'approve' ? 'Approve' : 'Reject';
            btn.className = 'btn ' + (decision === 'approve' ? 'btn-primary' : 'btn-primary');
            document.getElementById('apModal').classList.add('open');
        }
        function apCloseModal() { document.getElementById('apModal').classList.remove('open'); }

        async function apConfirm() {
            const btn = document.getElementById('apConfirmBtn');
            const errEl = document.getElementById('apErr');
            btn.disabled = true;
            const payload = {
                approval_id: parseInt(document.getElementById('apDecisionId').value, 10),
                decision: document.getElementById('apDecision').value,
                note: document.getElementById('apNote').value.trim()
            };
            try {
                const data = await (await fetch(AP_API + 'decide_approval.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
                })).json();
                if (!data.success) { errEl.textContent = data.error || 'Could not save.'; return; }
                apCloseModal();
                apLoad();
            } catch (e) {
                errEl.textContent = 'Could not save — please try again.';
            } finally {
                btn.disabled = false;
            }
        }

        document.getElementById('apModal').addEventListener('click', e => { if (e.target.id === 'apModal') apCloseModal(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') apCloseModal(); });
        apLoad();
    </script>
</body>
</html>
