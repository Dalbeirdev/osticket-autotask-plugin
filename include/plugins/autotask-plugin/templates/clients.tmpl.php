<?php
/**
 * Autotask Integration — Clients (Instance Manager) view template.
 *
 * Variables in scope (from admin/clients.inc.php):
 *   $clients      Instance[]           all registered client Autotasks
 *   $mappedCounts array<int,int>       instance id => synced ticket count
 *   $mode         string               list | add | edit
 *   $editing      \Autotask\Instance|null  instance being edited
 *   $departments  array<int,string>    osTicket departments (id => name)
 *   $csrfToken    string
 *   $notice/$error string|null
 *
 * Output is HTML-escaped at the point of echo. No business logic here.
 *
 * @package Autotask Integration
 */
if (!defined('INCLUDE_DIR')) { die('Access denied'); }

/** Small escape helper. */
$e = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
};

/* Inline assets (include/ is web-blocked; see dashboard template). */
$atCss = @file_get_contents(__DIR__ . '/../assets/css/autotask.css');
$atJs  = @file_get_contents(__DIR__ . '/../assets/js/autotask.js');

/* Form value helpers: current row values (edit) or sensible defaults (add). */
$row = $editing ? $editing->raw() : array();
$icfg = $editing ? $editing->configAll() : array(
    // Recommended defaults for a NEW client (small first import, safe toggles).
    'two_way_sync' => 1, 'auto_import_enabled' => 0, 'inbound_notes_enabled' => 1,
    'import_include_open' => 1, 'import_include_closed' => 0, 'import_since_days' => 7,
    'require_time_before_close' => 1, 'close_osticket_on_complete' => 1, 'complete_status' => 5,
);
$val = static function (string $key, $default = '') use ($row) {
    return isset($row[$key]) ? $row[$key] : $default;
};
$opt = static function (string $key, $default = '') use ($icfg) {
    return array_key_exists($key, $icfg) && $icfg[$key] !== null ? $icfg[$key] : $default;
};
$chk = static function (string $key, bool $default = false) use ($icfg): string {
    $v = array_key_exists($key, $icfg) ? (bool) $icfg[$key] : $default;
    return $v ? 'checked' : '';
};
?>
<style><?= $atCss ?></style>
<div class="at-wrap">
    <header class="at-header">
        <h1>Autotask Clients <span class="at-sub"><?=
            $mode === 'list' ? 'Instance Manager'
            : ($mode === 'edit' ? 'Edit Client'
            : ($mode === 'tickets' ? ('Tickets — ' . $e($editing ? $editing->name() : '')) : 'Register Client')) ?></span></h1>
        <div class="at-badges">
            <a class="at-btn at-btn-ghost" href="autotask.php">&larr; Dashboard</a>
            <?php if ($mode === 'list'): ?>
                <a class="at-btn" href="autotask.php?view=clients&amp;mode=add">+ Add Client</a>
            <?php else: ?>
                <a class="at-btn at-btn-ghost" href="autotask.php?view=clients">&larr; All Clients</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!empty($notice)): ?><div class="at-alert at-success"><?= $notice ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="at-alert at-error"><?= $error ?></div><?php endif; ?>

<?php if ($mode === 'list'): ?>

    <!-- ================= Client list ================= -->
    <?php if (!$clients): ?>
        <section class="at-box">
            <p class="at-muted">No clients registered yet. Click <strong>+ Add Client</strong> to register the first Autotask.</p>
        </section>
    <?php endif; ?>

    <?php foreach ($clients as $c): $r = $c->raw(); ?>
        <section class="at-box at-client<?= $c->enabled() ? '' : ' at-client-off' ?>">
            <div class="at-client-row">
                <div class="at-client-id">
                    <span class="at-code"><?= $e($c->code()) ?></span>
                    <div>
                        <div class="at-client-name"><?= $e($c->name()) ?></div>
                        <div class="at-muted" style="font-size:12px">
                            API user: <?= $e($c->credentials()['username'] ?: '—') ?>
                            &middot; Department: <?= $e($departments[$c->departmentId()] ?? ($c->departmentId() ? ('#' . $c->departmentId()) : 'not set')) ?>
                        </div>
                    </div>
                </div>
                <div class="at-client-stats">
                    <span class="at-badge <?= $c->enabled() ? 'on' : 'off' ?>"><?= $c->enabled() ? 'Enabled' : 'Disabled' ?></span>
                    <span class="at-badge <?= $c->lastOk() === true ? 'ok' : ($c->lastOk() === false ? 'bad' : 'unk') ?>">
                        Connection: <?= $c->lastOk() === true ? 'OK' : ($c->lastOk() === false ? 'FAILED' : 'Untested') ?>
                    </span>
                    <span class="at-badge"><?= (int) ($mappedCounts[$c->id()] ?? 0) ?> synced tickets</span>
                    <span class="at-badge unk">Last sync: <?= $e($c->lastSyncAt() ?: 'never') ?></span>
                </div>
                <div class="at-client-actions">
                    <a class="at-btn at-btn-sm at-btn-ghost" href="autotask.php?view=clients&amp;mode=tickets&amp;id=<?= $c->id() ?>">Tickets</a>
                    <a class="at-btn at-btn-sm at-btn-ghost" href="autotask.php?instance=<?= $c->id() ?>">Dashboard</a>
                    <?php
                    // Sync ownership: warn when another install (e.g. a dev copy
                    // restored from this database) already syncs this client.
                    $ownedElsewhere = false;
                    try {
                        $ownedElsewhere = $facade->container()->plugin()->getContainerFor($c->id())
                            ->settings()->syncOwnedElsewhere();
                    } catch (\Throwable $e0) { $ownedElsewhere = false; }
                    if ($ownedElsewhere): ?>
                        <span class="at-muted" style="color:#8a6d1f;background:#fff8e6;border:1px solid #f4e6c0;border-radius:5px;padding:2px 8px">
                            &#9888; synced by another install</span>
                        <form method="post" class="at-inline">
                            <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="action" value="claim_sync">
                            <input type="hidden" name="client_id" value="<?= $c->id() ?>">
                            <button type="submit" class="at-btn at-btn-sm at-btn-warn"
                                onclick="return confirm('Take over syncing for this client on THIS server? The other installation will stop syncing it.');">Take over sync</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" class="at-inline">
                        <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="action" value="sync_client">
                        <input type="hidden" name="client_id" value="<?= $c->id() ?>">
                        <button type="submit" class="at-btn at-btn-sm">Sync Now</button>
                    </form>
                    <a class="at-btn at-btn-sm" href="autotask.php?view=clients&amp;mode=edit&amp;id=<?= $c->id() ?>">Edit</a>
                    <form method="post" class="at-inline">
                        <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="action" value="test_client">
                        <input type="hidden" name="client_id" value="<?= $c->id() ?>">
                        <input type="hidden" name="c_api_username" value="<?= $e($c->credentials()['username']) ?>">
                        <input type="hidden" name="c_api_integration_code" value="<?= $e($r['api_integration_code']) ?>">
                        <input type="hidden" name="c_zone_url" value="<?= $e($r['zone_url']) ?>">
                        <button type="submit" class="at-btn at-btn-sm at-btn-alt">Test Connection</button>
                    </form>
                    <form method="post" class="at-inline">
                        <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="action" value="toggle_client">
                        <input type="hidden" name="client_id" value="<?= $c->id() ?>">
                        <button type="submit" class="at-btn at-btn-sm <?= $c->enabled() ? 'at-btn-warn' : '' ?>"
                            onclick="return confirm('<?= $c->enabled() ? 'Disable this client? Sync pauses; data is kept.' : 'Enable this client?' ?>');">
                            <?= $c->enabled() ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <?php if (($mappedCounts[$c->id()] ?? 0) === 0): ?>
                    <form method="post" class="at-inline">
                        <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete_client">
                        <input type="hidden" name="client_id" value="<?= $c->id() ?>">
                        <button type="submit" class="at-btn at-btn-sm at-btn-ghost"
                            onclick="return confirm('Delete client <?= $e($c->code()) ?>? Only possible because it has no synced tickets.');">Delete</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endforeach; ?>

<?php elseif ($mode === 'tickets'): ?>

    <!-- ================= Client tickets sub-view ================= -->
    <section class="at-box">
        <div class="at-box-h">
            <span class="at-ico" style="background:#1f6feb"><i class="icon-list"></i></span>
            <div><h2><?= $e($editing->name()) ?> <span class="at-code" style="font-size:11px;padding:3px 6px"><?= $e($editing->code()) ?></span></h2>
                <div class="at-box-sub">Last 50 synced tickets, newest activity first &mdash; click a number to open it</div></div>
        </div>
        <table class="at-table">
            <thead><tr><th>osTicket #</th><th>Subject</th><th>osTicket Status</th><th>Autotask #</th><th>AT Status</th><th>Last Sync</th></tr></thead>
            <tbody>
            <?php foreach ($clientTickets as $ct): ?>
                <tr>
                    <td><a href="tickets.php?id=<?= (int) $ct['osticket_ticket_id'] ?>">#<?= $e($ct['number']) ?></a></td>
                    <td><?= $e(mb_strimwidth((string) $ct['subject'], 0, 70, '…')) ?></td>
                    <td><?= $e($ct['status']) ?></td>
                    <td><?= $e($ct['autotask_ticket_number']) ?></td>
                    <td><?= $e($ct['autotask_status']) ?></td>
                    <td><?= $e($ct['last_sync_time']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$clientTickets): ?>
                <tr><td colspan="6" class="at-muted">No synced tickets for this client yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>

<?php else: ?>

    <!-- Standalone form for the ID-reference refresh (cannot nest forms). -->
    <?php if ($editing): ?>
    <form method="post" id="at-refresh-ref" action="autotask.php?view=clients&amp;mode=edit&amp;id=<?= $editing->id() ?>">
        <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="action" value="refresh_ref">
        <input type="hidden" name="client_id" value="<?= $editing->id() ?>">
    </form>
    <?php endif; ?>

    <!-- ================= Add / Edit form ================= -->
    <form method="post" action="autotask.php?view=clients<?= $editing ? '&amp;mode=edit&amp;id=' . $editing->id() : '&amp;mode=add' ?>">
        <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="client_id" value="<?= $editing ? $editing->id() : 0 ?>">

        <section class="at-box">
            <div class="at-box-h">
                <span class="at-ico" style="background:#1f6feb"><i class="icon-briefcase"></i></span>
                <div><h2>Client Identity</h2>
                    <div class="at-box-sub">Who this client is &mdash; per-ticket team routing is done by osTicket Ticket Filters (Admin &rarr; Manage &rarr; Filters)</div></div>
            </div>
            <div class="at-grid">
                <div><label class="at-lbl">Client Name *</label>
                    <input type="text" name="c_name" required maxlength="120" class="at-fld" value="<?= $e($val('name')) ?>" placeholder="e.g. Satellite Networks"></div>
                <div><label class="at-lbl">Client Code * <span class="at-muted">(3–16 letters/digits, unique — shown as badge)</span></label>
                    <input type="text" name="c_code" required maxlength="16" class="at-fld" value="<?= $e($val('code')) ?>" placeholder="e.g. SAT" style="text-transform:uppercase"></div>
                <div><label class="at-lbl">Fallback Department <span class="at-muted">(optional &mdash; used when no Ticket Filter routes the ticket; empty = system default)</span></label>
                    <select name="c_department_id" class="at-fld">
                        <option value="">&mdash; System default department &mdash;</option>
                        <?php foreach ($departments as $dId => $dName): ?>
                            <option value="<?= (int) $dId ?>" <?= (int) $val('department_id') === (int) $dId ? 'selected' : '' ?>><?= $e($dName) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div style="display:flex;align-items:flex-end">
                    <label><input type="checkbox" name="c_enabled" <?= $editing ? ($editing->enabled() ? 'checked' : '') : 'checked' ?>> Enabled (sync runs)</label></div>
            </div>

            <!-- Per-queue department routing: Autotask queue -> osTicket dept.
                 No rows = every ticket uses the Fallback Department above. -->
            <div style="margin-top:14px;padding-top:12px;border-top:1px dashed #dbe3ea">
                <label class="at-lbl">Department routing by Autotask Queue
                    <span class="at-muted">(optional &mdash; tickets from a mapped queue land in that department; all other queues use the Fallback Department)</span></label>
                <div id="at-dept-rows"></div>
                <button type="button" id="at-dept-add" class="at-btn" style="margin-top:6px">+ Add queue rule</button>
                <datalist id="at-queue-list">
                    <?php foreach (($refQueues ?? array()) as $q0): ?>
                        <option value="<?= $e($q0['value']) ?>"><?= $e($q0['label']) ?></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <script>
            (function () {
                var rows = document.getElementById('at-dept-rows');
                var depts = <?php $dj = array(); foreach ($departments as $dId => $dName) { $dj[] = array('id' => (int) $dId, 'name' => (string) $dName); } echo json_encode($dj); ?>;
                function addRow(queue, dept) {
                    var div = document.createElement('div');
                    div.style.cssText = 'display:flex;gap:8px;align-items:center;margin-top:6px';
                    var qi = document.createElement('input');
                    qi.type = 'text'; qi.name = 'o_dept_map_queue[]'; qi.className = 'at-fld';
                    qi.placeholder = 'Queue ID (see ID reference below)'; qi.setAttribute('list', 'at-queue-list');
                    qi.style.maxWidth = '260px'; qi.value = queue || '';
                    var arrow = document.createElement('span'); arrow.innerHTML = '&rarr;'; arrow.style.color = '#8a949e';
                    var ds = document.createElement('select');
                    ds.name = 'o_dept_map_dept[]'; ds.className = 'at-fld'; ds.style.maxWidth = '260px';
                    var o0 = document.createElement('option'); o0.value = ''; o0.textContent = '— choose department —'; ds.appendChild(o0);
                    depts.forEach(function (d) {
                        var o = document.createElement('option'); o.value = String(d.id); o.textContent = d.name;
                        if (dept && String(dept) === String(d.id)) { o.selected = true; }
                        ds.appendChild(o);
                    });
                    var x = document.createElement('button');
                    x.type = 'button'; x.textContent = '✕'; x.title = 'Remove rule';
                    x.style.cssText = 'border:1px solid #cfd6dc;background:#fff;border-radius:5px;padding:4px 9px;cursor:pointer;color:#c0392b';
                    x.onclick = function () { div.parentNode.removeChild(div); };
                    div.appendChild(qi); div.appendChild(arrow); div.appendChild(ds); div.appendChild(x);
                    rows.appendChild(div);
                }
                document.getElementById('at-dept-add').onclick = function () { addRow('', ''); };
                // Prefill saved rules ("queueId=deptId" per line).
                <?php foreach (preg_split('/\r\n|\r|\n/', (string) $opt('dept_map')) as $ln0) {
                    if (strpos($ln0, '=') === false) { continue; }
                    list($q1, $d1) = array_map('trim', explode('=', $ln0, 2));
                    if ($q1 !== '' && $d1 !== '') {
                        echo 'addRow(' . json_encode($q1) . ',' . json_encode($d1) . ");\n";
                    }
                } ?>
            })();
            </script>
        </section>

        <section class="at-box">
            <div class="at-box-h">
                <span class="at-ico" style="background:#6f42c1"><i class="icon-key"></i></span>
                <div><h2>Autotask API Credentials</h2>
                    <div class="at-box-sub">The API user this client issued for your integration &mdash; stored encrypted</div></div>
            </div>
            <div class="at-grid">
                <div><label class="at-lbl">API Username *</label>
                    <input type="text" name="c_api_username" required class="at-fld" autocomplete="off" value="<?= $e($val('api_username')) ?>"></div>
                <div><label class="at-lbl">API Secret <?= $editing ? '<span class="at-muted">(leave blank to keep stored secret)</span>' : '*' ?></label>
                    <input type="password" name="c_api_secret" class="at-fld" autocomplete="new-password" value="" <?= $editing ? '' : 'required' ?>></div>
                <div><label class="at-lbl">Integration Code *</label>
                    <input type="text" name="c_api_integration_code" required class="at-fld" autocomplete="off" value="<?= $e($val('api_integration_code')) ?>"></div>
                <div><label class="at-lbl">Zone URL <span class="at-muted">(optional — auto-detected)</span></label>
                    <input type="text" name="c_zone_url" class="at-fld" value="<?= $e($val('zone_url')) ?>" placeholder="https://webservicesN.autotask.net/ATServicesRest/V1.0/"></div>
            </div>
            <div style="margin-top:10px">
                <button type="submit" name="action" value="test_client" class="at-btn at-btn-alt">Test Connection</button>
                <span class="at-muted" style="font-size:12px">Tests the credentials above without saving.</span>
            </div>
        </section>

        <section class="at-box">
            <div class="at-box-h">
                <span class="at-ico" style="background:#1f8f4d"><i class="icon-refresh"></i></span>
                <div><h2>Synchronisation</h2>
                    <div class="at-box-sub">What flows automatically between osTicket and this client's Autotask</div></div>
            </div>
            <div class="at-checks at-checks-desc">
                <label><input type="checkbox" name="o_two_way_sync" <?= $chk('two_way_sync', true) ?>>
                    <span><strong>Two-way sync</strong>
                    <small>Pull changes made in the client's Autotask back into osTicket: status changes move your ticket, their notes/time entries appear in the thread. Off = push-only (osTicket &rarr; Autotask still works, nothing comes back).</small></span></label>
                <label><input type="checkbox" name="o_auto_import_enabled" <?= $chk('auto_import_enabled') ?>>
                    <span><strong>Auto-import new tickets</strong>
                    <small>Every sync cycle, brand-new Autotask tickets that pass the Import Filters below are created in osTicket automatically &mdash; hands-free intake. Keep OFF during onboarding; enable after a verified manual import.</small></span></label>
                <label><input type="checkbox" name="o_inbound_notes_enabled" <?= $chk('inbound_notes_enabled', true) ?>>
                    <span><strong>Import Autotask notes</strong>
                    <small>Notes written on the Autotask side show in the osTicket thread (client-visible notes as replies, their internal notes as internal). Off = you would only see status changes, never their words. Needs Two-way sync.</small></span></label>
                <label><input type="checkbox" name="o_sync_attachments" <?= $chk('sync_attachments', true) ?>>
                    <span><strong>Sync attachments</strong>
                    <small>Files and pasted screenshots on osTicket replies upload to the Autotask ticket's Attachments tab (up to ~6&nbsp;MB per file; larger are logged and skipped). Off = text syncs, files stay in osTicket only.</small></span></label>
                <label><input type="checkbox" name="o_import_system_notes" <?= $chk('import_system_notes') ?>>
                    <span><strong>Import Autotask system notes</strong>
                    <small>Machine-generated notes (workflow rules, "Forwarded Ticket", assignment/SLA noise &mdash; anything with no human author). OFF recommended: keeps your threads human-only; a runaway Autotask workflow can't flood osTicket.</small></span></label>
            </div>
            <div style="margin-top:12px">
                <label class="at-lbl">Status Map &mdash; one per line: <code>osTicket Status Name=Autotask status ID</code>
                    <span class="at-muted">(drives the single Ticket Status dropdown; unmapped statuses fall back to open/complete)</span></label>
                <textarea name="o_status_map" class="at-fld" rows="4"
                    placeholder="Open=1&#10;In Progress=5&#10;Resolved=5&#10;Closed=5"><?= $e($opt('status_map')) ?></textarea>
            </div>
        </section>

        <section class="at-box">
            <div class="at-box-h">
                <span class="at-ico" style="background:#e67e22"><i class="icon-filter"></i></span>
                <div><h2>Import Filters</h2>
                    <div class="at-box-sub">Which of this client's Autotask tickets are yours to import &mdash; queue and resource lists combine as OR</div></div>
            </div>
            <div class="at-checks" style="margin-bottom:12px">
                <label><input type="checkbox" name="o_import_include_open" <?= $chk('import_include_open', true) ?>> Open / active tickets</label>
                <label><input type="checkbox" name="o_import_include_closed" <?= $chk('import_include_closed') ?>> Completed / closed tickets</label>
            </div>
            <div class="at-grid">
                <div><label class="at-lbl">Only these status values <span class="at-muted">(comma-separated IDs; empty = by checkboxes above)</span></label>
                    <input type="text" name="o_import_status_ids" class="at-fld" value="<?= $e($opt('import_status_ids')) ?>"></div>
                <div><label class="at-lbl">Limit to Company IDs</label>
                    <input type="text" name="o_import_company_ids" class="at-fld" value="<?= $e($opt('import_company_ids')) ?>"></div>
                <div><label class="at-lbl">Limit to Queue IDs</label>
                    <input type="text" name="o_import_queue_ids" class="at-fld" value="<?= $e($opt('import_queue_ids')) ?>"></div>
                <div><label class="at-lbl">Limit to assigned Resource IDs</label>
                    <input type="text" name="o_import_resource_ids" class="at-fld" value="<?= $e($opt('import_resource_ids')) ?>"></div>
                <div><label class="at-lbl">Only tickets active in the last N days <span class="at-muted">(recommend 7 for a new client)</span></label>
                    <input type="number" name="o_import_since_days" min="0" max="3650" class="at-fld" value="<?= $e($opt('import_since_days', 7)) ?>"></div>
                <div><label class="at-lbl">Help Topic for imported tickets
                        <span class="at-muted">(Autotask has no Help Topic; osTicket can refuse to close a ticket without one)</span></label>
                    <select name="o_import_help_topic" class="at-fld">
                        <option value="">&mdash; None &mdash;</option>
                        <?php foreach (($helpTopics ?? array()) as $tId => $tName): ?>
                            <option value="<?= (int) $tId ?>" <?= (int) $opt('import_help_topic') === (int) $tId ? 'selected' : '' ?>><?= $e($tName) ?></option>
                        <?php endforeach; ?>
                    </select></div>
            </div>
            <?php if (!empty($refQueues) || !empty($refResources) || !empty($refCompanies)): ?>
            <div style="margin-top:14px;padding-top:12px;border-top:1px dashed #e4e8ec">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
                    <span class="at-lbl" style="margin:0">&#128269; ID reference &mdash; live from this client's Autotask (copy the number you need)</span>
                    <button type="submit" form="at-refresh-ref" class="at-btn at-btn-sm at-btn-ghost">&#8635; Refresh now</button>
                </div>
                <div class="at-grid">
                    <?php $refBlocks = array('Queues' => $refQueues, 'Resources (your techs)' => $refResources, 'Companies (first 30)' => $refCompanies);
                    foreach ($refBlocks as $refTitle => $refList): if (!$refList) continue; ?>
                    <div>
                        <div style="font-size:11px;font-weight:700;color:#8a949e;text-transform:uppercase;margin-bottom:4px"><?= $e($refTitle) ?></div>
                        <div style="max-height:150px;overflow:auto;border:1px solid #e8edf2;border-radius:8px;background:#fbfcfe;font-size:12px">
                            <?php foreach ($refList as $ri): ?>
                                <div style="padding:4px 10px;border-bottom:1px solid #f1f4f8;display:flex;gap:8px">
                                    <code style="color:#1f6feb;min-width:80px"><?= $e($ri['value']) ?></code>
                                    <span><?= $e($ri['label']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>

        <section class="at-box">
            <div class="at-box-h">
                <span class="at-ico" style="background:#51606e"><i class="icon-cog"></i></span>
                <div><h2>Autotask Ticket Defaults</h2>
                    <div class="at-box-sub">Numeric picklist IDs from THIS client's Autotask &mdash; used when creating tickets there</div></div>
            </div>
            <div class="at-grid">
                <div><label class="at-lbl">Default Company ID</label>
                    <input type="number" name="o_default_company_id" class="at-fld" value="<?= $e($opt('default_company_id')) ?>"></div>
                <div><label class="at-lbl">Default Queue ID</label>
                    <input type="number" name="o_default_queue_id" class="at-fld" value="<?= $e($opt('default_queue_id')) ?>"></div>
                <div><label class="at-lbl">Default Priority</label>
                    <input type="number" name="o_default_priority" class="at-fld" value="<?= $e($opt('default_priority')) ?>"></div>
                <div><label class="at-lbl">Default Status</label>
                    <input type="number" name="o_default_status" class="at-fld" value="<?= $e($opt('default_status')) ?>"></div>
                <div><label class="at-lbl">Default Ticket Type</label>
                    <input type="number" name="o_default_ticket_type" class="at-fld" value="<?= $e($opt('default_ticket_type')) ?>"></div>
                <div><label class="at-lbl">Default Issue Type <span class="at-muted">(required by some tenants)</span></label>
                    <input type="number" name="o_default_issue_type_id" class="at-fld" value="<?= $e($opt('default_issue_type_id')) ?>"></div>
                <div><label class="at-lbl">Default Sub-Issue Type</label>
                    <input type="number" name="o_default_sub_issue_type_id" class="at-fld" value="<?= $e($opt('default_sub_issue_type_id')) ?>"></div>
            </div>
        </section>

        <section class="at-box">
            <div class="at-box-h">
                <span class="at-ico" style="background:#b8860b"><i class="icon-time"></i></span>
                <div><h2>Time Entry Defaults</h2>
                    <div class="at-box-sub">Fallbacks for billable time entries when a tech's email has no Autotask resource match</div></div>
            </div>
            <div class="at-grid">
                <div><label class="at-lbl">Default Work Type (billing code ID)</label>
                    <input type="number" name="o_default_work_type_id" class="at-fld" value="<?= $e($opt('default_work_type_id')) ?>"></div>
                <div><label class="at-lbl">Default Resource ID</label>
                    <input type="number" name="o_default_resource_id" class="at-fld" value="<?= $e($opt('default_resource_id')) ?>"></div>
                <div><label class="at-lbl">Default Role ID</label>
                    <input type="number" name="o_default_role_id" class="at-fld" value="<?= $e($opt('default_role_id')) ?>"></div>
            </div>
        </section>

        <section class="at-box">
            <div class="at-box-h">
                <span class="at-ico" style="background:#c0392b"><i class="icon-ok"></i></span>
                <div><h2>Ticket Closure</h2>
                    <div class="at-box-sub">What "finished" means in this client's Autotask when a ticket is closed here</div></div>
            </div>
            <div class="at-grid">
                <div><label class="at-lbl">Autotask "Complete" status value</label>
                    <input type="number" name="o_complete_status" class="at-fld" value="<?= $e($opt('complete_status', 5)) ?>"></div>
                <div style="display:flex;flex-direction:column;justify-content:flex-end;gap:6px">
                    <label><input type="checkbox" name="o_require_time_before_close" <?= $chk('require_time_before_close', true) ?>> Require a time entry before completing</label>
                    <label><input type="checkbox" name="o_close_osticket_on_complete" <?= $chk('close_osticket_on_complete', true) ?>> Allow closing the osTicket ticket on complete</label>
                </div>
            </div>
        </section>

        <div class="at-savebar">
            <button type="submit" name="action" value="save_client" class="at-btn"><i class="icon-save"></i> <?= $editing ? 'Save Changes' : 'Register Client' ?></button>
            <a class="at-btn at-btn-ghost" href="autotask.php?view=clients">Cancel</a>
            <span class="at-muted" style="font-size:12px;margin-left:auto"><?= $editing ? 'Changes apply from the next sync cycle.' : 'After registering: Test Connection, import a small date range, verify, then enable auto-import.' ?></span>
        </div>
    </form>

<?php endif; ?>

    <footer class="at-footer">Autotask Integration &middot; Clients</footer>
</div>
<script><?= $atJs ?></script>
