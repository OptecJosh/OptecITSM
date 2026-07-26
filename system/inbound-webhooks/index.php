<?php
/**
 * System — Inbound webhooks.
 *
 * The receiving half of the integration story: a monitoring tool, alerting
 * platform, form or script POSTs here and gets a ticket. Each source is a row —
 * name it, choose how it proves who it is, map a few payload paths onto ticket
 * fields, hand over the URL and secret.
 *
 * Admin-gated by the system header (and again by api/system/inbound_webhooks.php).
 * The receiver itself is public by necessity; see includes/inbound_webhook.php
 * for how a request is authenticated before its payload is read for meaning.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';

$current_page = 'inbound-webhooks';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'system'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System - Inbound webhooks</title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=49">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        .iw-wrap { flex: 1; display: flex; flex-direction: column; gap: 16px; padding: 20px 24px; overflow: auto; background: var(--app-bg, #f5f7fa); }
        .iw-wrap h2 { margin: 0; font-size: 20px; color: var(--text, #222); }
        .iw-lead { margin: 0; font-size: 13px; color: var(--text-dim, #6b7280); max-width: 900px; line-height: 1.6; }
        .iw-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 18px; box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,.05)); max-width: 1000px; }
        .iw-card h3 { margin: 0 0 10px; font-size: 15px; color: var(--text, #222); }
        .iw-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .iw-field { display: flex; flex-direction: column; gap: 4px; }
        .iw-field label { font-size: 12px; font-weight: 600; color: var(--text-dim, #6b7280); }
        .iw-field input, .iw-field select, .iw-field textarea {
            padding: 7px 9px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 13px;
            background: var(--surface, #fff); color: var(--text, #222); min-width: 190px; box-sizing: border-box;
        }
        .iw-field.wide input, .iw-field.wide textarea { min-width: 420px; }
        table.iw { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.iw th, table.iw td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--border, #f0f1f3); vertical-align: top; }
        table.iw th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-dim, #6b7280); }
        .iw-url { font-family: ui-monospace, monospace; font-size: 11.5px; word-break: break-all; color: var(--text, #333); }
        .iw-pill { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .iw-pill.on { background: #dcfce7; color: #166534; }
        .iw-pill.off { background: #fee2e2; color: #b91c1c; }
        .iw-out-created { color: #166534; font-weight: 600; }
        .iw-out-appended, .iw-out-resolved { color: #075985; font-weight: 600; }
        .iw-out-auth_failed, .iw-out-invalid, .iw-out-error { color: #b91c1c; font-weight: 600; }
        .iw-out-ignored { color: var(--text-dim, #6b7280); }
        .iw-map { display: grid; grid-template-columns: 170px 1fr; gap: 8px 12px; align-items: center; margin-top: 8px; }
        .iw-map label { font-size: 12.5px; color: var(--text-dim, #6b7280); }
        .iw-map input { width: 100%; padding: 6px 8px; border: 1px solid var(--border, #e5e7eb); border-radius: 6px; font-size: 12.5px; font-family: ui-monospace, monospace; background: var(--surface, #fff); color: var(--text, #222); box-sizing: border-box; }
        .iw-hint { font-size: 12px; color: var(--text-dim, #6b7280); margin-top: 6px; line-height: 1.55; }
        .iw-hint code { background: var(--surface-2, #eceff3); padding: 1px 5px; border-radius: 4px; font-size: 11.5px; }
        .iw-secret { background: #fffbeb; border: 1px solid #fde68a; color: #78350f; border-radius: 6px; padding: 10px 12px; font-size: 12.5px; margin-top: 10px; }
        .iw-secret code { font-family: ui-monospace, monospace; word-break: break-all; }
        .iw-err { color: #b91c1c; font-size: 13px; margin-top: 8px; }
        .iw-payload { font-family: ui-monospace, monospace; font-size: 11px; max-height: 140px; overflow: auto; background: var(--surface-2, #f7f8fa); padding: 6px 8px; border-radius: 4px; white-space: pre-wrap; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="iw-wrap">
            <h2>Inbound webhooks</h2>
            <p class="iw-lead">Let another system raise tickets here. Create a webhook, copy its URL and secret into the
               sending tool, and map the parts of its payload you care about onto ticket fields. Every delivery is logged
               with its payload &mdash; accepted or rejected &mdash; so a misbehaving integration can be diagnosed from
               this end. For the other direction (us calling out when something happens here) see
               <a href="../webhooks/">Webhooks queue</a>.</p>

            <div class="iw-card">
                <div class="iw-row" style="justify-content:space-between;">
                    <h3 style="margin:0;">Configured sources</h3>
                    <button class="btn btn-primary btn-sm" onclick="iwEdit(null)">New webhook</button>
                </div>
                <div id="iwList" style="margin-top:10px;">Loading&hellip;</div>
            </div>

            <div class="iw-card" id="iwEditor" style="display:none;">
                <h3 id="iwEditorTitle">New webhook</h3>
                <input type="hidden" id="iwId">
                <div class="iw-row">
                    <div class="iw-field"><label for="iwName">Name *</label><input type="text" id="iwName" maxlength="120" placeholder="e.g. Grafana alerts"></div>
                    <div class="iw-field"><label for="iwCompany">Company</label><select id="iwCompany"></select></div>
                    <div class="iw-field"><label for="iwActive">Active</label><select id="iwActive"><option value="1">Yes</option><option value="0">No</option></select></div>
                </div>

                <div class="iw-row" style="margin-top:12px;">
                    <div class="iw-field"><label for="iwAuth">Authentication</label>
                        <select id="iwAuth" onchange="iwAuthChanged()">
                            <option value="header_secret">Shared secret in a header</option>
                            <option value="hmac_sha256">HMAC SHA-256 signature</option>
                            <option value="token">Token in the URL</option>
                        </select>
                    </div>
                    <div class="iw-field" id="iwHeaderWrap"><label for="iwHeader">Header name</label><input type="text" id="iwHeader" placeholder="X-Webhook-Secret"></div>
                    <div class="iw-field" id="iwPrefixWrap" style="display:none;"><label for="iwPrefix">Signature prefix</label><input type="text" id="iwPrefix" placeholder="sha256="></div>
                    <div class="iw-field" id="iwEncWrap" style="display:none;"><label for="iwEnc">Encoding</label><select id="iwEnc"><option value="hex">hex</option><option value="base64">base64</option></select></div>
                </div>
                <div class="iw-hint" id="iwAuthHint"></div>

                <h3 style="margin-top:18px;">Field mapping</h3>
                <div class="iw-hint">Each box takes literal text, <code>{{dot.path}}</code> placeholders into the payload, or both.
                   Array indices are numbers: <code>{{alerts.0.labels.alertname}}</code>. A path that is not in the payload
                   resolves to nothing and the field is left unset. Status, priority, type, category, department, origin and
                   customer are matched by <strong>name</strong>; an unknown name is skipped rather than guessed.</div>
                <div class="iw-map" id="iwMap"></div>

                <h3 style="margin-top:18px;">Correlation</h3>
                <div class="iw-hint">Without this, a check that flaps makes a ticket per delivery. Point <strong>dedupe path</strong>
                   at whatever the sender calls its alert id: repeat deliveries then append a note to the open ticket instead.
                   The resolve rule closes it when the sender says the condition cleared.</div>
                <div class="iw-row" style="margin-top:8px;">
                    <div class="iw-field"><label for="iwDedupe">Dedupe path</label><input type="text" id="iwDedupe" placeholder="alerts.0.fingerprint"></div>
                    <div class="iw-field"><label for="iwResolvePath">Resolve path</label><input type="text" id="iwResolvePath" placeholder="status"></div>
                    <div class="iw-field"><label for="iwResolveValue">equals</label><input type="text" id="iwResolveValue" placeholder="resolved"></div>
                    <div class="iw-field"><label for="iwResolveStatus">then set status to</label><select id="iwResolveStatus"></select></div>
                </div>

                <div class="iw-row" style="margin-top:16px;">
                    <button class="btn btn-primary" onclick="iwSave()">Save</button>
                    <button class="btn btn-secondary" onclick="iwCancel()">Cancel</button>
                </div>
                <div class="iw-err" id="iwErr"></div>
                <div id="iwSecretBox"></div>
            </div>

            <div class="iw-card">
                <div class="iw-row" style="justify-content:space-between;">
                    <h3 style="margin:0;">Recent deliveries</h3>
                    <button class="btn btn-secondary btn-sm" onclick="iwLoadEvents()">Refresh</button>
                </div>
                <div id="iwEvents" style="margin-top:10px;">&mdash;</div>
            </div>
        </div>
    </div>

    <script src="inbound-webhooks.js?v=1"></script>
</body>
</html>
