<?php
/**
 * Autotask Integration — SCP Time Entry panel loader.
 *
 * COPY THIS FILE INTO YOUR osTicket scp/ DIRECTORY AS: scp/autotask-ticket.php
 *
 *   sudo cp include/plugins/autotask/scp-autotask-ticket.php scp/autotask-ticket.php
 *   sudo chown www-data:www-data scp/autotask-ticket.php
 *
 * Access:  http://<helpdesk>/scp/autotask-ticket.php?id=<osticket-ticket-id>
 * (must be logged in as staff with access to the ticket).
 *
 * @package Autotask Integration
 */

require('staff.inc.php');

if (!defined('INCLUDE_DIR')) {
    http_response_code(500);
    die('osTicket context not loaded.');
}

$matches = glob(INCLUDE_DIR . 'plugins/*/admin/timeentry.inc.php');
if (!$matches) {
    http_response_code(404);
    die('Autotask Time Entry controller not found under include/plugins/.');
}

require $matches[0];
