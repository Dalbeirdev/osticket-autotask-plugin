<?php
/**
 * Autotask Integration — SCP dashboard loader.
 *
 * COPY THIS FILE INTO YOUR osTicket scp/ DIRECTORY AS:  scp/autotask.php
 *
 *   cp include/plugins/autotask/scp-autotask.php scp/autotask.php
 *
 * It is web-accessible (scp/ is not blocked like include/), reuses osTicket's
 * staff authentication, then hands off to the plugin's dashboard controller.
 *
 * Access:  http://<your-helpdesk>/scp/autotask.php   (must be logged in as Admin)
 *
 * @package Autotask Integration
 */

// staff.inc.php lives in the same scp/ directory and sets up $thisstaff / $ost.
require('staff.inc.php');

if (!defined('INCLUDE_DIR')) {
    http_response_code(500);
    die('osTicket context not loaded.');
}

// Auto-discover the plugin folder (works whether it is named autotask,
// autotask-plugin, etc.) by locating its dashboard controller.
$matches = glob(INCLUDE_DIR . 'plugins/*/admin/dashboard.inc.php');
if (!$matches) {
    http_response_code(404);
    die('Autotask plugin dashboard controller not found under include/plugins/.');
}

require $matches[0];
