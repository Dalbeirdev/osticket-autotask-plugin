<?php
/**
 * Autotask Integration — standalone CLI cron runner.
 *
 * Preferred method: use osTicket's built-in cron (it fires the `cron` Signal,
 * which this plugin already handles). This script is an ALTERNATIVE for setups
 * that want a dedicated 5-minute job pointed straight at the integration.
 *
 * Usage (system crontab, every 5 minutes):
 *   * /5 * * * * php /path/to/osticket/include/plugins/autotask-plugin/cron.php
 *
 * Optional argument:  full   -> run a full sync instead of incremental.
 *
 * @package Autotask Integration
 */

if (PHP_SAPI !== 'cli') {
    // Allow web invocation only with a shared secret to avoid abuse.
    $secret = getenv('AUTOTASK_CRON_SECRET');
    if (!$secret || ($_GET['key'] ?? '') !== $secret) {
        http_response_code(403);
        die('Forbidden');
    }
}

// ---------------------------------------------------------------------------
// Bootstrap osTicket core (provides db_*, Signal, PluginManager, constants).
// ---------------------------------------------------------------------------
// osTicket's main.inc.php is at the WEB ROOT (…/osticket), not include/.
$root = realpath(__DIR__ . '/../../../');               // web root
$mainInc = $root . '/main.inc.php';

if (!is_file($mainInc)) {
    fwrite(STDERR, "Cannot locate osTicket main.inc.php at $mainInc\n");
    exit(1);
}
require_once $mainInc;

if (!defined('INCLUDE_DIR')) {
    fwrite(STDERR, "osTicket bootstrap did not define INCLUDE_DIR.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Locate the enabled plugin instance and run the scheduler.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/bootstrap.php';

$plugin = null;
if (class_exists('PluginManager')) {
    foreach (PluginManager::allInstalled(true) as $p) {
        if ($p instanceof \Autotask\AutotaskPlugin) {
            $plugin = $p;
            break;
        }
    }
}

if (!$plugin) {
    fwrite(STDERR, "Autotask plugin not installed/enabled.\n");
    exit(1);
}

try {
    // Import a specific Autotask ticket by id:  php cron.php import <atId>
    // Tries each enabled client instance until one owns that ticket id.
    if (($argv[1] ?? '') === 'import' && !empty($argv[2])) {
        $res = array('ok' => false, 'message' => 'No enabled clients.');
        foreach ($plugin->instanceContainers() as $c) {
            $res = $c->sync()->importSingle((int) $argv[2]);
            if (!empty($res['ok'])) {
                break;
            }
        }
        fwrite(STDOUT, json_encode($res) . "\n");
        exit($res['ok'] ? 0 : 1);
    }

    // Multi-tenant: run the requested mode for every enabled client instance.
    $mode = ($argv[1] ?? '') === 'full' ? 'full' : 'incremental';
    foreach ($plugin->instanceContainers() as $c) {
        $label = 'i' . $c->instanceId();
        try {
            $r = $mode === 'full'
                ? $c->scheduler()->runFull(30)
                : $c->scheduler()->runIncremental();
            fwrite(STDOUT, sprintf(
                "[%s] [%s] Autotask %s sync: processed=%d failed=%d pulled=%d\n",
                date('c'), $label, $mode, $r['queue']['processed'], $r['queue']['failed'], $r['pulled']
            ));
        } catch (\Throwable $e) {
            // One failing tenant must not stop the others.
            fwrite(STDERR, "[$label] sync failed: " . $e->getMessage() . "\n");
        }
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Autotask cron failed: ' . $e->getMessage() . "\n");
    exit(1);
}
