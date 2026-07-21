<?php
/**
 * Autotask Integration — Time Entry panel controller + view (standalone).
 *
 * Included by scp/autotask-ticket.php after staff bootstrap. Renders the
 * technician time-entry form + history for one osTicket ticket and handles
 * submissions (CSRF + staff access + Autotask validation).
 *
 * In scope from caller: $thisstaff, $ost.
 *
 * @package Autotask Integration
 */

if (!defined('INCLUDE_DIR')) {
    http_response_code(500);
    die('osTicket context not loaded.');
}
if (!isset($thisstaff) || !$thisstaff) {
    http_response_code(403);
    die('Staff authentication required.');
}

require_once __DIR__ . '/../bootstrap.php';

if (!function_exists('autotask_find_plugin')) {
    /**
     * @return \Autotask\AutotaskPlugin|null
     */
    function autotask_find_plugin()
    {
        if (!class_exists('PluginManager')) {
            return null;
        }
        foreach (PluginManager::allInstalled() as $p) {
            if ($p instanceof \Autotask\AutotaskPlugin) {
                return $p;
            }
        }
        return null;
    }
}

$ticketId = (int) ($_GET['id'] ?? 0);
if (!$ticketId) {
    die('Missing ticket id.');
}

$ticket = \Ticket::lookup($ticketId);
if (!$ticket) {
    http_response_code(404);
    die('Ticket not found.');
}

// Access control: admins, or staff with access to this ticket.
$canAccess = $thisstaff->isAdmin();
if (!$canAccess && method_exists($ticket, 'checkStaffPerm')) {
    $canAccess = $ticket->checkStaffPerm($thisstaff);
} elseif (!$canAccess && method_exists($ticket, 'checkStaffAccess')) {
    $canAccess = $ticket->checkStaffAccess($thisstaff);
} elseif (!$canAccess) {
    $canAccess = true; // fall back to authenticated staff
}
if (!$canAccess) {
    http_response_code(403);
    die('You do not have access to this ticket.');
}

$plugin = autotask_find_plugin();
if (!$plugin) {
    die('Autotask plugin not available.');
}
$facade = new \Autotask\Autotask($plugin->getContainer());

$notice = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$ost->checkCSRFToken()) {
        $error = 'Invalid CSRF token. Reload and try again.';
    } elseif (($_POST['action'] ?? '') === 'add_time') {
        $staff = array(
            'id'   => $thisstaff->getId(),
            'name' => (string) $thisstaff->getName(),
            'ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
        );
        $res = $facade->addTimeEntry($ticketId, $_POST, $staff);
        if (!$res['ok']) {
            $fieldErrors = $res['errors'];
            $error = $fieldErrors['err'] ?? 'Please correct the highlighted fields.';
        } elseif ($res['posted']) {
            $notice = 'Time entry saved and posted to Autotask.';
        } elseif (!empty($res['error'])) {
            $error = 'Saved locally but Autotask rejected it: ' . $res['error'] . ' (will retry).';
        } else {
            $notice = 'Time entry queued; it will post to Autotask shortly.';
        }
    } elseif (($_POST['action'] ?? '') === 'refresh_picklists') {
        $facade->refreshPicklists();
        $notice = 'Picklists refreshed from Autotask.';
    }
}

$mapping = $facade->mappingFor($ticketId);
$picklists = $facade->timeEntryPicklists();
$history = $facade->listTimeEntries($ticketId);
$csrfToken = $ost->getCSRF()->getToken();
$fieldErrors = $fieldErrors ?? array();

require __DIR__ . '/../templates/timeentry.tmpl.php';
