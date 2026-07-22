<?php
/**
 * Autotask Integration — embedded technician panel (timer + status + time entry).
 * Resource auto-resolves to the logged-in tech; summary comes from the note text.
 *
 * COPY INTO osTicket scp/ AS: scp/autotask-panel.php
 *
 * @package Autotask Integration
 */

require('staff.inc.php');
if (!defined('INCLUDE_DIR') || !isset($thisstaff) || !$thisstaff) { http_response_code(403); exit; }
$boot = glob(INCLUDE_DIR . 'plugins/*/bootstrap.php');
if (!$boot) { http_response_code(500); exit; }
require_once $boot[0];
$plugin = null;
foreach (PluginManager::allInstalled() as $p) { if ($p instanceof \Autotask\AutotaskPlugin) { $plugin = $p; break; } }
if (!$plugin) { http_response_code(500); exit; }
$facade = new \Autotask\Autotask($plugin->getContainer());
// Rebind the facade to the client instance a ticket is mapped to, so panel
// actions (status, time, complete) always hit the EXACT tenant.
$facadeFor = function ($tid) use ($plugin, $facade) {
    if ($tid && method_exists($plugin, 'containerForTicket')) {
        $c = $plugin->containerForTicket((int) $tid);
        if ($c) { return new \Autotask\Autotask($c); }
    }
    return $facade;
};
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$staff = array('id' => $thisstaff->getId(), 'name' => (string) $thisstaff->getName(), 'ip' => $_SERVER['REMOTE_ADDR'] ?? '');
$json = function ($d) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($d); exit; };
$canAccess = function ($tid) use ($thisstaff) {
    $t = \Ticket::lookup((int) $tid); if (!$t) return false;
    if ($thisstaff->isAdmin()) return true;
    if (method_exists($t, 'checkStaffPerm')) return (bool) $t->checkStaffPerm($thisstaff);
    return true;
};

if ($action === 'context') {
    $tid = (int) ($_GET['ticket'] ?? 0);
    if (!$tid || !$canAccess($tid)) $json(array('mapped' => false));
    $json($facadeFor($tid)->panelContext($tid));
}

if ($action === 'log_time') {
    if (!$ost->checkCSRFToken()) $json(array('ok' => false, 'error' => 'Invalid CSRF token.'));
    $tid = (int) ($_POST['ticket'] ?? 0);
    if (!$tid || !$canAccess($tid)) $json(array('ok' => false, 'error' => 'Access denied.'));
    if (!\Autotask\Rbac::can($thisstaff, \Autotask\Rbac::PERM_TIME)) $json(array('ok' => false, 'error' => 'You do not have permission to log time.'));
    $facade = $facadeFor($tid);
    $settings = $facade->container()->settings();

    // Resource ALWAYS = logged-in tech (by email); fallback to default resource.
    $resourceId = '';
    $email = method_exists($thisstaff, 'getEmail') ? (string) $thisstaff->getEmail() : '';
    if ($email) {
        try { $r = $facade->container()->api()->getResourceByEmail($email); if ($r && !empty($r['id'])) $resourceId = (int) $r['id']; }
        catch (\Throwable $e) {}
    }
    if ($resourceId === '') $resourceId = $settings->defaultResourceId();

    $summary = trim((string) ($_POST['summary'] ?? ''));
    if ($summary === '') $summary = 'Work on ticket #' . $tid;

    $vars = array(
        'resource_id'     => $resourceId,
        'billing_code_id' => ($_POST['work_type'] ?? '') ?: $settings->defaultWorkTypeId(),
        'role_id'         => $_POST['role_id'] ?? '',
        'summary_notes'   => $summary,
        'billable'        => !empty($_POST['billable']) ? 1 : 0,
        'date_worked'     => $_POST['date_worked'] ?? gmdate('Y-m-d'),
        'start_time'      => $_POST['start_time'] ?? '',
        'end_time'        => $_POST['end_time'] ?? '',
    );
    if (isset($_POST['minutes']) && $_POST['minutes'] !== '') {
        $vars['hours_worked'] = round(((float) $_POST['minutes']) / 60, 2);
    } elseif (isset($_POST['hours']) && $_POST['hours'] !== '') {
        $vars['hours_worked'] = (float) $_POST['hours'];
    }

    $res = $facade->addTimeEntry($tid, $vars, $staff);
    if (empty($res['ok']) && empty($res['error']) && !empty($res['errors'])) $res['error'] = implode('; ', array_map('strval', (array) $res['errors']));
    $json($res);
}

if ($action === 'push_ticket') {
    // Manual-ticket flow: push an unmapped osTicket ticket to a chosen client.
    if (!$ost->checkCSRFToken()) $json(array('ok' => false, 'error' => 'Invalid CSRF token.'));
    $tid = (int) ($_POST['ticket'] ?? 0);
    if (!$tid || !$canAccess($tid)) $json(array('ok' => false, 'error' => 'Access denied.'));
    if (!\Autotask\Rbac::can($thisstaff, \Autotask\Rbac::PERM_STATUS)) $json(array('ok' => false, 'error' => 'You do not have permission to push tickets.'));
    $iid = (int) ($_POST['client'] ?? 0);
    if (!$iid) $json(array('ok' => false, 'error' => 'Choose a client.'));
    $c = $plugin->getContainerFor($iid);
    if (!$c->instance() || $c->instanceId() !== $iid) $json(array('ok' => false, 'error' => 'Unknown client.'));
    $json((new \Autotask\Autotask($c))->pushTicket($tid, $staff));
}

if ($action === 'set_status') {
    if (!$ost->checkCSRFToken()) $json(array('ok' => false, 'error' => 'Invalid CSRF token.'));
    $tid = (int) ($_POST['ticket'] ?? 0);
    if (!$tid || !$canAccess($tid)) $json(array('ok' => false, 'error' => 'Access denied.'));
    if (!\Autotask\Rbac::can($thisstaff, \Autotask\Rbac::PERM_STATUS)) $json(array('ok' => false, 'error' => 'You do not have permission to change status.'));
    $json($facadeFor($tid)->setAutotaskStatus($tid, (int) ($_POST['status'] ?? 0), $staff));
}

if ($action === 'complete') {
    if (!$ost->checkCSRFToken()) $json(array('ok' => false, 'error' => 'Invalid CSRF token.'));
    $tid = (int) ($_POST['ticket'] ?? 0);
    if (!$tid || !$canAccess($tid)) $json(array('ok' => false, 'error' => 'Access denied.'));
    if (!\Autotask\Rbac::can($thisstaff, \Autotask\Rbac::PERM_COMPLETE)) $json(array('ok' => false, 'error' => 'You do not have permission to complete tickets.'));
    $json($facadeFor($tid)->completeTicket($tid, array(
        'resolution'     => $_POST['resolution'] ?? '',
        'close_osticket' => !empty($_POST['close_osticket']),
    ), $staff));
}

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
?>
(function () {
    "use strict";
    var BASE = 'autotask-panel.php';
    var IS_ADMIN = <?php echo $thisstaff->isAdmin() ? 'true' : 'false'; ?>;

    /* Admin-only "Autotask" tab in the staff nav bar -> integration dashboard. */
    function addNav() {
        if (!IS_ADMIN) return;
        var ul = document.querySelector('ul#nav, #nav ul, nav#nav ul');
        if (!ul || ul.querySelector('.at-nav-tab')) return;
        var li = document.createElement('li');
        // Match osTicket's own tab markup (active/inactive) so it inherits the
        // exact tab styling instead of default list-item rendering.
        li.className = 'at-nav-tab ' + (location.pathname.indexOf('autotask.php') !== -1 ? 'active' : 'inactive');
        li.style.listStyle = 'none';
        var a = document.createElement('a');
        a.href = 'autotask.php';
        a.className = 'no-pjax';
        a.textContent = 'Autotask';
        li.appendChild(a);
        ul.appendChild(li);
    }
    function csrf() { var el = document.querySelector('[name="__CSRFToken__"]'); return el ? el.value : ''; }
    function post(a, params) {
        params.action = a; params.__CSRFToken__ = csrf();
        var body = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
        return fetch(BASE, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
    }
    function fmt(s) { function p(n) { return (n < 10 ? '0' : '') + n; } return p(Math.floor(s / 3600)) + ':' + p(Math.floor((s % 3600) / 60)) + ':' + p(s % 60); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    /* Hours -> Autotask's own display style: 1.1001 h => "1h 06m".
       Autotask ROUNDS to the nearest minute (0.3833 h = 22.998 min -> 23m),
       so we must round too or totals read one minute short. */
    function hm(h) {
        var mins = Math.round((parseFloat(h) || 0) * 60);
        var H = Math.floor(mins / 60), M = mins % 60;
        return H > 0 ? (H + 'h ' + (M < 10 ? '0' : '') + M + 'm') : (M + 'm');
    }
    function opts(list, sel, ph) {
        var h = ph ? ('<option value="">' + ph + '</option>') : '';
        return h + (list || []).map(function (o) { return '<option value="' + o.value + '"' + (String(sel) === String(o.value) ? ' selected' : '') + '>' + o.label + '</option>'; }).join('');
    }
    function statusLabel(ctx) {
        var hit = (ctx.statuses || []).filter(function (o) { return String(o.value) === String(ctx.current_status); })[0];
        return hit ? hit.label : (ctx.current_status || '—');
    }
    /* Single-dropdown design: Autotask status is DISPLAY-ONLY here; techs drive
       everything from osTicket's own Ticket Status (translated per client). */
    function statusRo(ctx) {
        return '<div style="display:flex;align-items:center;gap:8px;margin-left:auto;white-space:nowrap">'
            + '<span style="font-size:11px;font-weight:600;color:#8a949e;text-transform:uppercase;letter-spacing:.04em">Autotask Status</span>'
            + '<span class="at-status-ro" style="display:inline-block;padding:4px 12px;background:#e8f0fe;border-radius:12px;font-weight:700;font-size:12px;color:#1f6feb">' + esc(statusLabel(ctx)) + '</span></div>';
    }
    function removeAll() { var xs = document.querySelectorAll('.at-panel'); for (var i = 0; i < xs.length; i++) if (xs[i].parentNode) xs[i].parentNode.removeChild(xs[i]); }
    function init() {
        addNav();
        removeAll();
        if (location.pathname.indexOf('tickets.php') === -1) return;
        var m = location.search.match(/[?&]id=(\d+)/); if (!m) return;
        var TID = m[1];
        fetch(BASE + '?action=context&ticket=' + TID + '&_=' + Date.now(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (ctx) {
                if (!ctx) return;
                var forms = [];
                var re = document.querySelector('textarea[name="response"]'); if (re && re.closest) forms.push(re.closest('form'));
                var no = document.querySelector('textarea[name="note"]'); if (no && no.closest) forms.push(no.closest('form'));
                if (ctx.mapped) {
                    forms.forEach(function (f) { if (f && !f.querySelector('.at-panel')) buildWidget(TID, ctx, f); });
                } else if (ctx.clients && ctx.clients.length) {
                    // Unmapped ticket: offer the push-to-client strip (once).
                    var f0 = forms[0];
                    if (f0 && !f0.querySelector('.at-panel')) buildPushStrip(TID, ctx, f0);
                }
            }).catch(function () {});
    }
    document.addEventListener('DOMContentLoaded', init);
    if (window.jQuery) window.jQuery(document).on('pjax:end pjax:success', init);
    window.addEventListener('popstate', init);

    var LBL = 'display:block;font-size:11px;font-weight:600;color:#51606e;margin-bottom:2px';
    var FLD = 'width:100%;padding:5px;border:1px solid #cfd6dc;border-radius:4px;box-sizing:border-box';

    /* Unmapped ticket: "push to client's Autotask" strip (manual-ticket flow). */
    function buildPushStrip(TID, ctx, form) {
        var box = document.createElement('div');
        box.className = 'at-panel';
        box.style.cssText = 'margin:12px 0;width:100%;border:1px dashed #cfd6dc;border-radius:8px;padding:10px 14px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;box-sizing:border-box;font-size:13px;color:#51606e;text-align:left';
        box.innerHTML = '<span>&#128268; Not linked to Autotask.</span>'
            + '<select class="at-push-client" style="' + FLD + ';width:auto;min-width:200px">'
            + ctx.clients.map(function (c) { return '<option value="' + esc(c.id) + '">' + esc(c.code + ' — ' + c.name) + '</option>'; }).join('')
            + '</select>'
            + '<button type="button" class="at-push" style="padding:6px 14px;border:none;border-radius:5px;background:#1f6feb;color:#fff;font-weight:600;cursor:pointer">Push to Autotask</button>'
            + '<span class="at-push-msg" style="font-size:12px"></span>';
        var btn = form.querySelector('[type="submit"]');
        if (btn && btn.parentNode) btn.parentNode.insertBefore(box, btn); else form.appendChild(box);
        var msg = box.querySelector('.at-push-msg');
        box.querySelector('.at-push').onclick = function () {
            if (!confirm('Create this ticket in the selected client\'s Autotask?')) return;
            msg.textContent = 'Pushing...'; msg.style.color = '#51606e';
            post('push_ticket', { ticket: TID, client: box.querySelector('.at-push-client').value })
                .then(function (r) {
                    msg.textContent = r.ok ? ('Pushed' + (r.autotask_number ? ' — Autotask #' + r.autotask_number : '') + (r.error ? ' (' + r.error + ')' : '') + ' — reload to see the panel.') : (r.error || 'Failed');
                    msg.style.color = r.ok ? '#1f8f4d' : '#c0392b';
                }).catch(function () { msg.textContent = 'Network error.'; msg.style.color = '#c0392b'; });
        };
    }

    /* Time-entry history popup (opened from the panel header). */
    function showHistory(ctx) {
        var rows = ctx.time_entries || [];
        var total = 0, billable = 0;
        rows.forEach(function (r) { var h = parseFloat(r.hours_worked || 0) || 0; total += h; if (String(r.billable) === '1') billable += h; });
        var ov = document.createElement('div');
        ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center';
        var srcChip = function (s) {
            var os = String(s) === 'osTicket';
            return '<span style="display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;font-weight:600;'
                + (os ? 'background:#fdf1dc;color:#9a6a00' : 'background:#e3edfb;color:#1d5aa8')
                + '">' + (os ? 'osTicket' : 'Autotask') + '</span>';
        };
        var body = rows.length ? rows.map(function (r) {
            var rh = parseFloat(r.hours_worked || 0) || 0;
            return '<tr><td style="padding:6px 10px;border-bottom:1px solid #eef1f4;white-space:nowrap">' + esc(r.date_worked || r.created || '—') + '</td>'
                + '<td style="padding:6px 10px;border-bottom:1px solid #eef1f4">' + esc(r.tech || '—') + '</td>'
                + '<td style="padding:6px 10px;border-bottom:1px solid #eef1f4;text-align:right;white-space:nowrap">'
                + '<strong>' + esc(hm(rh)) + '</strong>'
                + '<span style="color:#8a949e;font-size:11px"> (' + esc(rh.toFixed(4).replace(/0+$/, '').replace(/\.$/, '')) + ' h)</span></td>'
                + '<td style="padding:6px 10px;border-bottom:1px solid #eef1f4;text-align:center">' + (String(r.billable) === '1' ? '&#10004;' : '&mdash;') + '</td>'
                + '<td style="padding:6px 10px;border-bottom:1px solid #eef1f4;text-align:center">' + srcChip(r.source) + '</td>'
                + '<td style="padding:6px 10px;border-bottom:1px solid #eef1f4">' + esc(r.status || '') + (r.autotask_time_entry_id ? ' (AT #' + esc(r.autotask_time_entry_id) + ')' : '') + '</td></tr>';
        }).join('') : '<tr><td colspan="6" style="padding:12px;color:#8a949e">No time entries yet.</td></tr>';
        ov.innerHTML = '<div style="background:#fff;border-radius:10px;max-width:720px;width:94%;max-height:80vh;overflow:auto;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:13px;color:#2b2f33">'
            + '<div style="display:flex;align-items:center;padding:12px 16px;border-bottom:1px solid #eef1f4"><strong>Time Entries &mdash; Autotask #' + esc(ctx.autotask_ticket_number || '') + '</strong>'
            + '<button type="button" class="at-hx" style="margin-left:auto;border:none;background:none;font-size:18px;cursor:pointer;color:#8a949e">&#10005;</button></div>'
            + '<table style="width:100%;border-collapse:collapse"><thead><tr>'
            + '<th style="text-align:left;padding:8px 10px;color:#8a949e;font-size:11px;text-transform:uppercase">Date</th>'
            + '<th style="text-align:left;padding:8px 10px;color:#8a949e;font-size:11px;text-transform:uppercase">Technician</th>'
            + '<th style="text-align:right;padding:8px 10px;color:#8a949e;font-size:11px;text-transform:uppercase">Hours</th>'
            + '<th style="text-align:center;padding:8px 10px;color:#8a949e;font-size:11px;text-transform:uppercase">Billable</th>'
            + '<th style="text-align:center;padding:8px 10px;color:#8a949e;font-size:11px;text-transform:uppercase">Source</th>'
            + '<th style="text-align:left;padding:8px 10px;color:#8a949e;font-size:11px;text-transform:uppercase">Status</th></tr></thead>'
            + '<tbody>' + body + '</tbody></table>'
            + '<div style="padding:12px 16px;border-top:1px solid #eef1f4;font-weight:600">Total: ' + esc(hm(total))
            + '<span style="color:#8a949e;font-weight:400"> (' + total.toFixed(4).replace(/0+$/, '').replace(/\.$/, '')
            + ' h) &nbsp;&middot;&nbsp; billable ' + esc(hm(billable)) + '</span></div>'
            + (billable === 0 && total > 0 && !ctx.has_contract
                ? '<div style="padding:8px 16px;background:#fff8e6;border-top:1px solid #f2e3bd;color:#8a6d1f;font-size:11.5px">'
                  + '&#9888; No contract on this Autotask ticket &mdash; Autotask forces every time entry to '
                  + '<strong>non-billable</strong>, whatever osTicket sends. Attach a contract to the ticket in Autotask to bill this work.</div>'
                : '')
            + '</div>';
        function close() { if (ov.parentNode) ov.parentNode.removeChild(ov); document.removeEventListener('keydown', onKey); }
        function onKey(e) { if (e.key === 'Escape') close(); }
        ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
        ov.querySelector('.at-hx').onclick = close;
        document.addEventListener('keydown', onKey);
        document.body.appendChild(ov);
    }

    /* Autotask ticket details popup (opened from the panel's Details button).
       Everything the Autotask ticket screen shows, without stealing page space. */
    function showDetails(ctx) {
        var d = ctx.details || {};
        var SECT = 'padding:9px 14px 4px;color:#9aa4ae;font-size:9.5px;font-weight:700;letter-spacing:.1em;'
            + 'text-transform:uppercase;background:#fbfcfd;border-top:1px solid #eef2f6;border-bottom:1px solid #eef2f6';
        var fmtDate = function (s) { if (!s) return null; var t = new Date(s); return isNaN(t) ? s : t.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }); };
        var row = function (k, v, html) {
            if (v === null || v === undefined || v === '' || v === 0) return '';
            return '<div style="display:flex;gap:12px;align-items:baseline;padding:6px 14px;border-bottom:1px solid #f4f7fa">'
                + '<div style="color:#8a949e;font-size:11px;flex:0 0 34%">' + esc(k) + '</div>'
                + '<div style="flex:1;font-weight:600;overflow-wrap:anywhere">' + (html ? v : esc(String(v))) + '</div></div>';
        };
        var chip = function (t, bg, fg) {
            return '<span style="display:inline-block;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:700;background:' + bg + ';color:' + fg + '">' + esc(t) + '</span>';
        };
        var prioChip = function (p) {
            var k = String(p || '').toLowerCase();
            var c = (k.indexOf('critical') === 0 || k.indexOf('emergency') === 0) ? ['#fdecea', '#b3261e']
                  : k.indexOf('high') === 0 ? ['#fff1e3', '#a8560a']
                  : k.indexOf('low') === 0  ? ['#eef7ee', '#2f6b34'] : ['#eef3fb', '#1a4f9c'];
            return chip(p, c[0], c[1]);
        };
        var worked = parseFloat(ctx.worked_hours || 0) || 0;
        var est = parseFloat(d.estimated || 0) || 0;
        var over = est > 0 && worked > est;
        var pct = est > 0 ? Math.min(100, Math.round(worked / est * 100)) : 0;
        var body =
              '<div style="display:flex;text-align:center;padding:14px 6px 12px;background:#fbfcfd;border-bottom:1px solid #eef2f6">'
            + '<div style="flex:1;border-right:1px solid #eef2f6">'
            +   '<div style="font-size:22px;font-weight:800;color:' + (over ? '#b3261e' : '#1f6feb') + '">' + esc(hm(worked)) + '</div>'
            +   '<div style="color:#9aa4ae;font-size:9.5px;text-transform:uppercase;letter-spacing:.08em;margin-top:3px">Worked</div></div>'
            + '<div style="flex:1">'
            +   '<div style="font-size:22px;font-weight:800;color:#5b6773">' + esc(hm(est)) + '</div>'
            +   '<div style="color:#9aa4ae;font-size:9.5px;text-transform:uppercase;letter-spacing:.08em;margin-top:3px">Estimated</div></div>'
            + '</div>'
            + (est > 0 ? '<div style="padding:8px 14px;border-bottom:1px solid #eef2f6"><div style="height:6px;border-radius:3px;background:#eef2f6;overflow:hidden">'
                + '<div style="height:100%;width:' + pct + '%;background:' + (over ? '#b3261e' : '#1f6feb') + '"></div></div>'
                + '<div style="text-align:right;color:#9aa4ae;font-size:10px;margin-top:3px">' + pct + '% of estimate</div></div>' : '')
            + (!ctx.has_contract ? '<div style="padding:9px 14px;background:#fff8e6;color:#8a6d1f;font-size:11.5px;border-bottom:1px solid #f4e6c0;line-height:1.45">'
                + '&#9888; <strong>No contract on this Autotask ticket</strong> &mdash; Autotask bills every time entry as non-billable, whatever osTicket sends.</div>' : '')
            + '<div style="' + SECT + '">Client</div>'
            + row('Organization', ctx.company) + row('Contact', ctx.contact) + row('Email', ctx.contact_email)
            + '<div style="' + SECT + '">Ticket</div>'
            + row('Status', d.status ? chip(d.status, '#eaf1fd', '#1a4f9c') : null, true)
            + row('Priority', d.priority ? prioChip(d.priority) : null, true)
            + row('Issue', [d.issue_type, d.sub_issue].filter(Boolean).join(' › '))
            + row('Source', d.source) + row('SLA', d.sla) + row('Type', d.ticket_type)
            + row('Created', fmtDate(d.created)) + row('Due date', fmtDate(d.due))
            + '<div style="' + SECT + '">Assignment &amp; billing</div>'
            + row('Queue', d.queue) + row('Primary resource', d.resource) + row('Role', d.role)
            + row('Work type', d.work_type) + row('Contract', d.contract || '— none —');
        var ov = document.createElement('div');
        ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center';
        ov.innerHTML = '<div style="background:#fff;border-radius:12px;max-width:560px;width:94%;max-height:86vh;overflow:auto;'
            + 'font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:12.5px;color:#28313a;box-shadow:0 18px 50px rgba(0,0,0,.3)">'
            + '<div style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:linear-gradient(135deg,#1f6feb,#1858c0);color:#fff">'
            +   '<strong style="font-size:13px">Autotask #' + esc(ctx.autotask_ticket_number || '') + '</strong>'
            +   (ctx.client_code ? '<span style="background:rgba(255,255,255,.22);border-radius:5px;padding:1px 8px;font-size:10px;font-weight:700;letter-spacing:.05em">' + esc(ctx.client_code) + '</span>' : '')
            +   '<button type="button" class="at-dx" style="margin-left:auto;border:none;background:none;font-size:19px;cursor:pointer;color:#fff;opacity:.85;line-height:1">&#10005;</button>'
            + '</div>'
            + body
            + '<div style="display:flex;gap:10px;padding:11px 14px;background:#fbfcfd;border-top:1px solid #eef2f6">'
            +   '<a href="#" class="at-dhx" style="color:#1f6feb;font-weight:600;text-decoration:none;font-size:11.5px">&#9201; Time entries</a>'
            +   (ctx.deep_link ? '<a href="' + esc(ctx.deep_link) + '" target="_blank" rel="noopener" class="no-pjax at-open" style="margin-left:auto;color:#1f6feb;font-weight:600;text-decoration:none;font-size:11.5px">Open in Autotask &#8599;</a>' : '')
            + '</div></div>';
        function close() { if (ov.parentNode) ov.parentNode.removeChild(ov); document.removeEventListener('keydown', onKey); }
        function onKey(e) { if (e.key === 'Escape') close(); }
        ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
        ov.querySelector('.at-dx').onclick = close;
        var toTime = ov.querySelector('.at-dhx');
        if (toTime) { toTime.onclick = function (e) { e.preventDefault(); close(); showHistory(ctx); }; }
        document.addEventListener('keydown', onKey);
        document.body.appendChild(ov);
    }

    function buildWidget(TID, ctx, form) {
        var box = document.createElement('div');
        box.className = 'at-panel';
        box.style.cssText = 'margin:12px 0;width:100%;max-width:none;border:1px solid #cfd6dc;border-radius:8px;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:13px;color:#2b2f33;overflow:hidden;box-sizing:border-box';
        var totalH = 0; (ctx.time_entries || []).forEach(function (r) { totalH += parseFloat(r.hours_worked || 0) || 0; });
        box.innerHTML =
            '<div style="background:#1f6feb;color:#fff;padding:8px 12px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;line-height:1.4">'
            + (ctx.client_code ? '<span style="background:rgba(255,255,255,.22);border-radius:5px;padding:2px 8px;font-weight:700;font-size:11px;letter-spacing:.05em" title="' + esc(ctx.client_name || '') + '">' + esc(ctx.client_code) + '</span>' : '')
            + '<span style="font-weight:700;white-space:nowrap">Autotask #' + esc(ctx.autotask_ticket_number || ctx.autotask_ticket_id) + '</span>'
            + '<a href="#" class="at-history" style="color:#fff;opacity:.92;text-decoration:underline;white-space:nowrap;font-size:12px" title="View time entry history">&#9201; ' + esc(hm(totalH)) + '</a>'
            + '<a href="#" class="at-details" style="color:#fff;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);border-radius:5px;padding:2px 9px;font-size:11.5px;font-weight:600;text-decoration:none;white-space:nowrap" title="Autotask ticket details">&#8505; Details</a>'
            + (!ctx.has_contract ? '<span style="background:#ffe9a8;color:#7a5c00;border-radius:5px;padding:2px 8px;font-size:11px;font-weight:700;white-space:nowrap" title="Autotask bills time on this ticket as non-billable">&#9888; No contract</span>' : '')
            + (ctx.company ? '<span style="opacity:.92;white-space:nowrap" title="Autotask company">&#127970; ' + esc(ctx.company) + '</span>' : '')
            + (ctx.contact ? '<span style="opacity:.92;white-space:nowrap" title="Autotask contact">&#128100; ' + esc(ctx.contact) + (ctx.contact_email ? ' <span style="opacity:.75">(' + esc(ctx.contact_email) + ')</span>' : '') + '</span>' : '')
            + (ctx.deep_link ? '<a href="' + esc(ctx.deep_link) + '" target="_blank" rel="noopener" class="no-pjax at-open" style="margin-left:auto;color:#fff;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.45);border-radius:5px;padding:3px 10px;font-weight:600;text-decoration:none;white-space:nowrap">Open in Autotask &#8599;</a>' : '')
            + '</div>'
            + '<div style="padding:10px 14px;text-align:left">'
            + (ctx.inline_capture
                ? '<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">'
                    + '<div style="font-size:12px;color:#51606e;flex:1;min-width:240px;line-height:1.5">'
                    + '&#9201; Time &rarr; <strong>Time Spent</strong> fields &nbsp;&middot;&nbsp; Status &rarr; <strong>Ticket Status</strong> dropdown &nbsp;&middot;&nbsp; Close &rarr; ticket <strong>Close</strong> dialog'
                    + (ctx.email_customer ? '' : '<br>&#9993; Replies reach the client <strong>through Autotask</strong> &mdash; osTicket sends no separate email.')
                    + '</div>'
                    + statusRo(ctx)
                    + '</div>'
                    + '<span class="at-msg" style="font-size:12px"></span>'
                : '<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">'
                    + '<div class="at-clock" style="font-size:24px;font-weight:700;font-variant-numeric:tabular-nums">00:00:00</div>'
                    + '<button type="button" class="at-start" style="padding:6px 12px;border:none;border-radius:5px;background:#1f8f4d;color:#fff;font-weight:600;cursor:pointer">Start</button>'
                    + '<button type="button" class="at-stop" style="padding:6px 12px;border:none;border-radius:5px;background:#e67e22;color:#fff;font-weight:600;cursor:pointer">Stop &amp; Log</button>'
                    + '<button type="button" class="at-reset" style="padding:6px 9px;border:1px solid #cfd6dc;border-radius:5px;background:#fff;cursor:pointer">&#8635;</button>'
                    + statusRo(ctx)
                    + '</div>'
                    + '<hr style="border:none;border-top:1px solid #eef1f4;margin:12px 0">'
                    + '<div style="font-weight:700;margin-bottom:2px">Add Time Entry</div>'
                    + '<div style="font-size:11px;color:#8a949e;margin-bottom:8px">Logged as you; summary uses your reply/note text.</div>'
                    + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">'
                    + '<div><label style="' + LBL + '">Work Type *</label><select class="at-wt" style="' + FLD + '">' + opts(ctx.work_types, ctx.default_work_type, '&mdash; Select work type &mdash;') + '</select></div>'
                    + '<div><label style="' + LBL + '">Role</label><select class="at-role" style="' + FLD + '">' + opts(ctx.roles, '', '&mdash; Optional &mdash;') + '</select></div>'
                    + '<div><label style="' + LBL + '">Hours Worked</label><input type="number" step="0.25" min="0" class="at-hours" placeholder="e.g. 1.5" style="' + FLD + '"></div>'
                    + '<div><label style="' + LBL + '">or Start / End time</label><div style="display:flex;gap:6px"><input type="time" class="at-start-t" style="' + FLD + '"><input type="time" class="at-end-t" style="' + FLD + '"></div></div>'
                    + '<div><label style="' + LBL + '">Date Worked</label><input type="date" class="at-date" style="' + FLD + '"></div>'
                    + '</div>'
                    + '<div style="display:flex;align-items:center;gap:12px;margin-top:10px"><label><input type="checkbox" class="at-bill" checked> Billable</label>'
                    + '<button type="button" class="at-add" style="padding:7px 16px;border:none;border-radius:5px;background:#1f6feb;color:#fff;font-weight:600;cursor:pointer">Add Time</button>'
                    + '<span class="at-msg" style="font-size:12px"></span></div>')
            /* Complete/Close removed: closing happens through osTicket's native
               Close dialog; the status map + auto-resolution complete Autotask. */
            + (ctx.require_time_close ? '<div style="font-size:11px;color:#b8860b;margin-top:8px;padding-top:8px;border-top:1px solid #eef1f4">&#9888; Log time (Time Spent) before closing &mdash; closures without time are flagged in the audit log.</div>' : '')
            + '</div>';
        var btn = form.querySelector('[type="submit"]');
        if (btn && btn.parentNode) btn.parentNode.insertBefore(box, btn); else form.appendChild(box);
        var msg = box.querySelector('.at-msg');
        function setMsg(t, ok) { if (msg) { msg.textContent = t; msg.style.color = ok ? '#1f8f4d' : '#c0392b'; } }
        function noteText() {
            var ed = form.querySelector('.redactor-editor');
            var t = ed ? (ed.innerText || ed.textContent || '') : '';
            if (!t.trim()) { var ta = form.querySelector('textarea[name="response"],textarea[name="note"]'); if (ta) t = ta.value || ''; }
            return (t || '').replace(/\s+/g, ' ').trim();
        }

        // No direct email to the customer (config toggle, OFF by default):
        // the client follows the ticket in Autotask, so osTicket must not send
        // its own copy. Force osTicket's own "Do Not Email Reply" mode and
        // hide the From / Recipients / Reply-To block that goes with it.
        if (!ctx.email_customer) {
            var rt = (form.querySelector('select[name="reply-to"]') || document.querySelector('select#reply-to'));
            if (rt) {
                var hasNone = false;
                for (var i = 0; i < rt.options.length; i++) { if (rt.options[i].value === 'none') { hasNone = true; } }
                if (hasNone) { rt.value = 'none'; }   // core: $emailReply = false
                var rtr = rt.closest ? rt.closest('tr') : null;
                if (rtr) { rtr.style.display = 'none'; }
            }
            var fromSel = document.querySelector('select#emailreply');
            if (fromSel && fromSel.closest) {
                var ftr = fromSel.closest('tr');
                if (ftr) { ftr.style.display = 'none'; }
            }
            var recip = document.getElementById('recipients');
            if (recip) { recip.style.display = 'none'; }
        }

        // Hide osTicket's own "SLA Plan" row (config toggle): the Autotask
        // card shows the client's real SLA — two SLAs on one page confuse.
        if (ctx.hide_sla) {
            document.querySelectorAll('th').forEach(function (th) {
                if (/^\s*SLA Plan\s*:?\s*$/i.test(th.textContent || '')) {
                    var tr = th.closest('tr');
                    if (tr) { tr.style.display = 'none'; }
                }
            });
        }

        // Autotask details live behind the "Details" button in the strip —
        // a popup like the time-entry history. No floating cards: they sat in
        // dead space, fought PJAX re-renders and could duplicate.
        var dets = box.querySelector('.at-details');
        if (dets) { dets.onclick = function (e) { e.preventDefault(); showDetails(ctx); }; }

        // Auto-select the reply form's Time Type from the Autotask ticket's
        // Work Type (set at ticket creation) — both sides default the same.
        if (ctx.time_type_item) {
            var v = String(ctx.time_type_item);
            form.querySelectorAll('select[name="time_type"]').forEach(function (tt) {
                for (var i = 0; i < tt.options.length; i++) {
                    if (tt.options[i].value === v) { tt.value = v; break; }
                }
            });
            // Notes tab has its own form with the same field.
            document.querySelectorAll('form select[name="time_type"]').forEach(function (tt) {
                for (var i = 0; i < tt.options.length; i++) {
                    if (tt.options[i].value === v) { tt.value = v; break; }
                }
            });
        }

        // Timer + manual time entry exist only when inline capture is OFF —
        // with capture ON, time comes from the core reply-form fields.
        if (!ctx.inline_capture) {
            var d = new Date();
            box.querySelector('.at-date').value = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);

            var sec = 0, timer = null;
            var clock = box.querySelector('.at-clock'), startBtn = box.querySelector('.at-start');
            var tick = function () { sec++; clock.textContent = fmt(sec); };
            var common = function () { return { ticket: TID, work_type: box.querySelector('.at-wt').value, role_id: box.querySelector('.at-role').value, summary: noteText(), date_worked: box.querySelector('.at-date').value, billable: box.querySelector('.at-bill').checked ? 1 : 0 }; };
            var handle = function (p, label) { setMsg(label + '...', true); post('log_time', p).then(function (r) { setMsg(r.ok ? (r.posted ? 'Logged to Autotask.' : 'Queued.') : (r.error || 'Failed'), r.ok); }).catch(function () { setMsg('Network error.', false); }); };

            startBtn.onclick = function () { if (timer) { clearInterval(timer); timer = null; startBtn.textContent = 'Start'; startBtn.style.background = '#1f8f4d'; } else { timer = setInterval(tick, 1000); startBtn.textContent = 'Pause'; startBtn.style.background = '#b8860b'; } };
            box.querySelector('.at-reset').onclick = function () { if (timer) { clearInterval(timer); timer = null; } sec = 0; clock.textContent = '00:00:00'; startBtn.textContent = 'Start'; startBtn.style.background = '#1f8f4d'; };
            box.querySelector('.at-stop').onclick = function () { if (timer) { clearInterval(timer); timer = null; startBtn.textContent = 'Start'; startBtn.style.background = '#1f8f4d'; } var mins = Math.max(1, Math.round(sec / 60)); var p = common(); p.minutes = mins; handle(p, 'Logging ' + mins + ' min'); sec = 0; clock.textContent = '00:00:00'; };
            box.querySelector('.at-add').onclick = function () { var p = common(); p.hours = box.querySelector('.at-hours').value; p.start_time = box.querySelector('.at-start-t').value; p.end_time = box.querySelector('.at-end-t').value; if (!p.hours && !(p.start_time && p.end_time)) { setMsg('Enter hours, or start and end times.', false); return; } handle(p, 'Adding time'); };
        }
        var op = box.querySelector('.at-open');
        if (op) op.addEventListener('click', function (e) { e.stopPropagation(); });
        var hx = box.querySelector('.at-history');
        if (hx) hx.onclick = function (e) { e.preventDefault(); showHistory(ctx); };
        // Status is read-only in the panel (single-dropdown design); the guard
        // keeps compatibility if an older cached render still has the select.
        var stSel = box.querySelector('select.at-status');
        if (stSel) stSel.onchange = function () { setMsg('Updating status...', true); post('set_status', { ticket: TID, status: this.value }).then(function (r) { setMsg(r.ok ? 'Status updated in Autotask.' : (r.error || 'Failed'), r.ok); }).catch(function () { setMsg('Network error.', false); }); };
        // Complete/Close UI removed (native osTicket close drives completion);
        // guard kept for any cached render still carrying the old button.
        var cBtn = box.querySelector('.at-complete');
        if (cBtn) {
            cBtn.onclick = function () {
                var res0 = (box.querySelector('.at-resolution') || { value: '' }).value.trim();
                if (!res0) { return; }
                if (!confirm('Complete this ticket in Autotask?')) { return; }
                var co = box.querySelector('.at-close-os');
                post('complete', { ticket: TID, resolution: res0, close_osticket: (co && co.checked) ? 1 : 0 });
            };
        }
    }
})();
