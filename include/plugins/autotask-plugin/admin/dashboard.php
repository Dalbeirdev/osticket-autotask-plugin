<?php
/**
 * Autotask Integration — Dashboard (self-bootstrapping fallback entry).
 *
 * NOTE: osTicket blocks web access to the entire /include/ directory, so this
 * file is normally NOT reachable over HTTP. Use scp/autotask.php instead (copy
 * the provided scp-autotask.php into your osTicket scp/ directory).
 *
 * This wrapper remains useful for CLI rendering/testing or installs that have
 * relaxed the /include/ restriction.
 *
 * @package Autotask Integration
 */

$root = realpath(__DIR__ . '/../../../../');           // helpdesk web root
$staffInc = $root . '/scp/staff.inc.php';
if (!is_file($staffInc)) {
    $staffInc = $root . '/staff.inc.php';
}
if (!is_file($staffInc)) {
    http_response_code(500);
    die('Unable to locate osTicket staff bootstrap.');
}
require_once $staffInc;

require __DIR__ . '/dashboard.inc.php';
