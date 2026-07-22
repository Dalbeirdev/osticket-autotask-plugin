<?php
/**
 * Autotask Integration — automated self-test harness (CLI).
 *
 * Layered like a professional QA suite:
 *   L1 Environment   — runtime, schema, migrations (read-only)
 *   L2 Configuration — per-client credentials, API, picklists, mappings (read-only)
 *   L3 Data integrity — orphans, duplicates, queue health (read-only)
 *   L4 Live round-trip — OPT-IN (--live=<instanceId>): creates a ticket in that
 *      tenant, imports, replies, logs time, closes, verifies, and labels all
 *      artifacts "SELFTEST" for easy cleanup.
 *
 * Usage:
 *   php selftest.php                 # L1-L3 (safe, read-only)
 *   php selftest.php --live=2       # + L4 against instance 2
 *
 * Exit code 0 = all pass; 1 = failures found.
 *
 * @package Autotask Integration
 */

if (PHP_SAPI !== 'cli') { die("CLI only (this PHP reports SAPI '" . PHP_SAPI . "').\n"); }

// Progress markers on STDERR: if a host's PHP dies inside osTicket's bootstrap
// it can swallow STDOUT (an empty "<html></html>" is the usual symptom), and
// these lines still show exactly how far the run got.
$step = function (string $s): void { fwrite(STDERR, "[selftest] $s\n"); };
$step('php ' . PHP_VERSION . ' (' . PHP_SAPI . ')');

$root = realpath(__DIR__ . '/../../../');
$mainInc = $root . '/main.inc.php';
if (!is_file($mainInc)) {
    fwrite(STDERR, "[selftest] FATAL: osTicket main.inc.php not found at $mainInc\n");
    exit(1);
}
$step("booting osTicket from $mainInc");
require_once $mainInc;
if (!defined('INCLUDE_DIR')) {
    fwrite(STDERR, "[selftest] FATAL: osTicket bootstrap did not define INCLUDE_DIR (config/DB problem?)\n");
    exit(1);
}
$step('osTicket booted; loading plugin');
// The plugin is normally already loaded by osTicket's PluginManager; only pull
// the bootstrap in when its classes are genuinely absent, so a second include
// can never redeclare them.
if (!class_exists('\\Autotask\\AutotaskPlugin', false)) {
    require_once __DIR__ . '/bootstrap.php';
}
$step('plugin ready; running checks');

/* ----- micro test-runner -------------------------------------------------- */
$RESULTS = array('pass' => 0, 'fail' => 0, 'skip' => 0);
function t_section(string $s): void { echo "\n== $s ==\n"; }
function t_pass(string $m): void { global $RESULTS; $RESULTS['pass']++; echo "  [PASS] $m\n"; }
function t_fail(string $m): void { global $RESULTS; $RESULTS['fail']++; echo "  [FAIL] $m\n"; }
function t_skip(string $m): void { global $RESULTS; $RESULTS['skip']++; echo "  [SKIP] $m\n"; }
function t_check(bool $ok, string $m, string $why = ''): void { $ok ? t_pass($m) : t_fail($m . ($why !== '' ? " — $why" : '')); }

$liveId = 0;
foreach ($argv as $a) { if (preg_match('/^--live=(\d+)$/', $a, $m)) { $liveId = (int) $m[1]; } }

/* ----- L1 Environment ------------------------------------------------------ */
t_section('L1 Environment');
t_check(version_compare(PHP_VERSION, '8.0', '>='), 'PHP >= 8.0 (' . PHP_VERSION . ')');
t_check(function_exists('curl_init'), 'cURL extension present');
$plugin = null;
foreach (PluginManager::allInstalled() as $p) { if ($p instanceof \Autotask\AutotaskPlugin) { $plugin = $p; break; } }
t_check($plugin !== null, 'Plugin installed & bootstrapped');
if (!$plugin) { goto summary; }
$gcfg = $plugin->globalConfig();
t_check((string) $gcfg->get('schema_version') !== '', 'Config namespace resolved (schema_version=' . $gcfg->get('schema_version') . ')');
foreach (array('autotask_instance', 'autotask_ticket_map', 'autotask_sync_queue', 'autotask_note_map', 'autotask_time_entry') as $tbl) {
    t_check(\Autotask\Installer::tableExists($tbl), "Table $tbl exists");
}
$res = db_query('SELECT COUNT(*) c FROM ' . TABLE_PREFIX . 'autotask_migrations');
$mig = ($res && ($r = db_fetch_array($res))) ? (int) $r['c'] : 0;
t_check($mig >= 6, "Migrations applied ($mig >= 6)");

// Core osTicket tables the integration leans on. A restore that loses one of
// these fails in confusing ways (a missing help_topic_form fatals every
// ticket page through Ticket::isCloseable()), so name them explicitly.
$missingCore = array();
foreach (array('ticket', 'thread', 'thread_entry', 'help_topic', 'help_topic_form',
               'ticket_status', 'ticket_priority', 'department', 'staff', 'list', 'list_items') as $core) {
    $r0 = db_query("SHOW TABLES LIKE " . db_input(TABLE_PREFIX . $core), false);
    if (!$r0 || !db_num_rows($r0)) { $missingCore[] = TABLE_PREFIX . $core; }
}
t_check(!$missingCore, 'Core osTicket tables present'
    . ($missingCore ? ' — MISSING: ' . implode(', ', $missingCore) : ''));

// Reply-form time fields (core time-tracking mod) — without them inline time
// capture silently records nothing.
$timeCols = array();
$r0 = db_query('SHOW COLUMNS FROM ' . TABLE_PREFIX . 'thread_entry', false);
while ($r0 && ($x0 = db_fetch_array($r0))) { $timeCols[$x0['Field']] = true; }
t_check(isset($timeCols['time_spent']) && isset($timeCols['time_type']),
    'thread_entry has the time-tracking columns (core mod installed)');

/* ----- L2 Configuration (per client) --------------------------------------- */
$repo = new \Autotask\InstanceRepository();
$instances = $repo->all();
t_section('L2 Configuration — ' . count($instances) . ' client(s)');
t_check(count($instances) > 0, 'At least one client registered');
foreach ($instances as $inst) {
    $tag = '[' . $inst->code() . ']';
    if (!$inst->enabled()) { t_skip("$tag disabled — connection checks skipped"); continue; }
    $creds = $inst->credentials();
    t_check($creds['secret'] !== '', "$tag secret decrypts (not empty)");
    $c = $plugin->getContainerFor($inst->id());
    $conn = $c->api()->testConnection();
    t_check((bool) $conn['ok'], "$tag live API connection", (string) $conn['message']);
    if (!$conn['ok']) { continue; }
    t_check((bool) $c->api()->webBase(), "$tag web base resolved (" . $c->api()->webBase() . ')');
    $c->picklists()->ensureFresh();
    t_check(count($c->picklists()->options('status', 'Tickets')) > 0, "$tag status picklist cached");
    t_check(count($c->picklists()->options('billingCodeID')) > 0, "$tag work types cached");
    // Defaults sanity.
    $s = $c->settings();
    t_check($s->defaults()['company_id'] > 0, "$tag default company set");
    t_check((int) $s->defaultWorkTypeId() > 0, "$tag default work type set");
    $rid = (int) $s->defaultResourceId(); $rol = (int) $s->defaultRoleId();
    if ($rid && $rol) {
        try {
            $rr = $c->api()->request('GET', 'ResourceRoles/query?search=' . rawurlencode(json_encode(array(
                'filter' => array(
                    array('op' => 'eq', 'field' => 'resourceID', 'value' => $rid),
                    array('op' => 'eq', 'field' => 'roleID', 'value' => $rol),
                ), 'MaxRecords' => 1))));
            t_check(!empty($rr['items']), "$tag default resource+role pairing valid in tenant");
        } catch (\Throwable $e) { t_fail("$tag pairing check errored — " . $e->getMessage()); }
    } else {
        t_fail("$tag default resource/role missing (time entries may fail)");
    }
    // Status map lines resolve to real osTicket statuses.
    $bad = array();
    foreach (array_keys($s->statusMap()) as $name) {
        $found = false;
        foreach (\TicketStatus::objects() as $st) {
            if (mb_strtolower(trim((string) $st->getName())) === $name) { $found = true; break; }
        }
        if (!$found) { $bad[] = $name; }
    }
    t_check(!$bad, "$tag status map names all exist in osTicket", implode(', ', $bad));
    // Department exists when set.
    $dep = $inst->departmentId();
    if ($dep > 0) {
        $rd = db_query('SELECT 1 FROM ' . TABLE_PREFIX . 'department WHERE id=' . $dep);
        t_check((bool) ($rd && db_num_rows($rd)), "$tag fallback department #$dep exists");
    } else { t_skip("$tag no fallback department (system default will be used)"); }

    // Only ONE installation may sync a client: a dev copy restored from the
    // live database would push its stale state into the client's Autotask.
    $set = $c->settings();
    t_check(!$set->syncOwnedElsewhere(),
        "$tag synced by this installation only",
        'owned by install ' . $set->syncOwner() . ', this one is ' . \Autotask\Settings::installFingerprint());

    // Imported tickets need a help topic when osTicket requires one to close.
    global $cfg;
    if ($cfg && $cfg->requireTopicToClose()) {
        t_check($set->importHelpTopicId() > 0,
            "$tag help topic set for imports (osTicket requires one to close)");
    }
}

/* ----- L3 Data integrity ---------------------------------------------------- */
t_section('L3 Data integrity');
$q = function (string $sql): int {
    $r = db_query($sql); $x = $r ? db_fetch_array($r) : null; return (int) ($x['c'] ?? 0);
};
$orphans = $q('SELECT COUNT(*) c FROM ' . TABLE_PREFIX . 'autotask_ticket_map m LEFT JOIN '
    . TABLE_PREFIX . 'ticket t ON t.ticket_id=m.osticket_ticket_id WHERE t.ticket_id IS NULL');
t_check($orphans === 0, "No orphan mappings (map -> missing osTicket ticket)", "$orphans found");
$dupes = $q('SELECT COUNT(*) c FROM (SELECT instance_id, autotask_ticket_id FROM ' . TABLE_PREFIX
    . 'autotask_ticket_map WHERE autotask_ticket_id IS NOT NULL GROUP BY instance_id, autotask_ticket_id HAVING COUNT(*)>1) d');
t_check($dupes === 0, 'No duplicate AT-ticket mappings within a tenant', "$dupes found");
$unknownInst = $q('SELECT COUNT(*) c FROM ' . TABLE_PREFIX . 'autotask_ticket_map m LEFT JOIN '
    . TABLE_PREFIX . 'autotask_instance i ON i.id=m.instance_id WHERE i.id IS NULL');
t_check($unknownInst === 0, 'Every mapping points to a registered client', "$unknownInst stray");
$dead = $q("SELECT COUNT(*) c FROM " . TABLE_PREFIX . "autotask_sync_queue WHERE status='dead'");
$dead === 0 ? t_pass('Queue has no dead jobs') : t_fail("Queue has $dead dead job(s) — inspect dashboard Failed & Dead");
$stuck = $q("SELECT COUNT(*) c FROM " . TABLE_PREFIX . "autotask_sync_queue WHERE status='processing' AND updated < (NOW() - INTERVAL 30 MINUTE)");
t_check($stuck === 0, 'No jobs stuck in processing >30min', "$stuck stuck");
$teFail = $q("SELECT COUNT(*) c FROM " . TABLE_PREFIX . "autotask_time_entry WHERE status='failed'");
$teFail === 0 ? t_pass('No failed time entries') : t_fail("$teFail failed time entrie(s) — see history popup / logs");

/* ----- L4 Live round-trip (opt-in) ------------------------------------------ */
if ($liveId > 0) {
    t_section("L4 Live round-trip — instance #$liveId (creates SELFTEST artifacts)");
    $inst = $repo->find($liveId);
    if (!$inst || !$inst->enabled()) { t_fail('instance missing/disabled'); goto summary; }
    $c = $plugin->getContainerFor($liveId);
    $api = $c->api(); $s = $c->settings();
    try {
        $fields = array(
            'companyID' => (int) $s->defaults()['company_id'],
            'title' => 'SELFTEST ' . date('His') . ' - automated round-trip (safe to delete)',
            'description' => 'Created by the integration self-test.',
            'status' => 1, 'priority' => 2,
        );
        foreach (array('queue_id' => 'queueID', 'issue_type' => 'issueType', 'ticket_type' => 'ticketType') as $k => $f) {
            if (!empty($s->defaults()[$k])) { $fields[$f] = $s->defaults()[$k]; }
        }
        $atId = $api->createTicket($fields);
        t_pass("created AT ticket #$atId");
        $imp = $c->sync()->importSingle($atId);
        t_check(!empty($imp['ok']) && !empty($imp['osticket_id']), 'imported into osTicket', (string) ($imp['message'] ?? ''));
        $ostId = (int) $imp['osticket_id'];
        $t = Ticket::lookup($ostId);
        $dep = $inst->departmentId();
        if ($dep > 0) { t_check((int) $t->getDeptId() === $dep, 'landed in registered department'); }
        // Reply -> AT note.
        $errors = array();
        $entry = $t->postReply(array('response' => 'SELFTEST reply body', 'title' => 'SELFTEST'), $errors, false, false);
        t_check((bool) $entry, 'osTicket reply posted');
        if ($entry) { $c->sync()->onOsticketThreadEntry($entry); }
        $c->scheduler()->processQueue(10);
        $found = false;
        foreach ($api->getTicketNotesSince($atId, gmdate('Y-m-d\TH:i:s\Z', time() - 3600)) as $n) {
            if (strpos((string) ($n['description'] ?? ''), 'SELFTEST reply body') !== false) { $found = true; break; }
        }
        t_check($found, 'reply arrived in Autotask as note');
        // Close -> Complete + resolution.
        $closed = null;
        foreach (TicketStatus::objects()->filter(array('state' => 'closed'))->limit(1) as $st) { $closed = $st; break; }
        $t->setStatus($closed);
        $c->sync()->onOsticketTicketUpdated(Ticket::lookup($ostId), array('dirty' => array('status_id' => true)));
        $c->scheduler()->processQueue(10);
        $at = $api->getTicket($atId);
        t_check((int) ($at['status'] ?? 0) === (int) $s->completeStatus(), 'Autotask reached Complete on close');
        t_check(trim((string) ($at['resolution'] ?? '')) !== '', 'resolution auto-filled');
        echo "  [INFO] artifacts: AT #$atId / osTicket #$ostId (titled SELFTEST — delete at will)\n";
    } catch (\Throwable $e) {
        t_fail('round-trip aborted — ' . $e->getMessage());
    }
} else {
    t_section('L4 Live round-trip');
    t_skip('not requested (run with --live=<instanceId> to enable)');
}

summary:
echo "\n================ SUMMARY ================\n";
printf("PASS: %d   FAIL: %d   SKIP: %d\n", $RESULTS['pass'], $RESULTS['fail'], $RESULTS['skip']);
echo $RESULTS['fail'] === 0 ? "RESULT: ALL CHECKS PASSED\n" : "RESULT: FAILURES FOUND — see [FAIL] lines above\n";
exit($RESULTS['fail'] === 0 ? 0 : 1);
