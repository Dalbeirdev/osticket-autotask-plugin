<?php
/**
 * Autotask Integration — Clients (Instance Manager) controller.
 *
 * Included from admin/dashboard.inc.php when ?view=clients. At that point the
 * caller has already verified: staff context, admin privileges, and built
 * $facade (plugin service container). This file handles the client register:
 * list / add / edit / enable-disable / delete / per-client connection test.
 *
 * Variables available from the caller: $thisstaff, $ost, $facade.
 *
 * @package Autotask Integration
 */

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

$repo = $facade->container()->instanceRepository();

$notice = null;
$error  = null;

/**
 * Ensure every osTicket status named in a client's Status Map exists, so the
 * single-dropdown translation works out of the box. Only map-referenced names
 * are created (never the tenant's whole status list). Names mapping to the
 * client's "Complete" value are created with state=closed, others state=open.
 *
 * @param string $mapRaw         Status Map lines "Name=AT id".
 * @param int    $completeStatus The client's Autotask Complete value.
 * @return string[] Names created.
 */
$ensureStatuses = static function (string $mapRaw, int $completeStatus): array {
    if (!class_exists('TicketStatus')) {
        return array();
    }
    $existing = array();
    foreach (\TicketStatus::objects() as $s) {
        if (method_exists($s, 'getName')) {
            $existing[mb_strtolower(trim((string) $s->getName()))] = true;
        }
    }
    $created = array();
    foreach (preg_split('/\r\n|\r|\n/', $mapRaw) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '=') === false) {
            continue;
        }
        list($name, $atId) = array_map('trim', explode('=', $line, 2));
        if ($name === '' || !is_numeric($atId) || isset($existing[mb_strtolower($name)])) {
            continue;
        }
        $state = ((int) $atId === $completeStatus) ? 'closed' : 'open';
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'ost_';
        $n = db_input(mb_substr($name, 0, 60));
        $st = db_input($state);
        $props = db_input(json_encode(array('description' => 'Created by Autotask integration')));
        if (db_query("INSERT INTO `{$prefix}ticket_status` (name, state, mode, flags, sort, properties, created, updated) "
            . "VALUES ($n, $st, 1, 0, 0, $props, NOW(), NOW())", false)) {
            $created[] = $name;
            $existing[mb_strtolower($name)] = true;
        }
    }
    return $created;
};

/**
 * Per-client option keys stored in config_json, with their input type.
 * bool = checkbox, int = numeric, csv = comma-separated id list, str = text.
 */
$optionTypes = array(
    'two_way_sync'               => 'bool',
    'auto_import_enabled'        => 'bool',
    'inbound_notes_enabled'      => 'bool',
    'default_company_id'         => 'int',
    'default_queue_id'           => 'int',
    'default_priority'           => 'int',
    'default_status'             => 'int',
    'default_ticket_type'        => 'int',
    'default_issue_type_id'      => 'int',
    'default_sub_issue_type_id'  => 'int',
    'import_include_open'        => 'bool',
    'import_include_closed'      => 'bool',
    'import_status_ids'          => 'csv',
    'import_company_ids'         => 'csv',
    'import_queue_ids'           => 'csv',
    'import_resource_ids'        => 'csv',
    'import_since_days'          => 'int',
    'import_help_topic'          => 'int',
    'default_work_type_id'       => 'int',
    'default_resource_id'        => 'int',
    'default_role_id'            => 'int',
    'complete_status'            => 'int',
    'require_time_before_close'  => 'bool',
    'close_osticket_on_complete' => 'bool',
    'status_map'                 => 'str',
    'sync_attachments'           => 'bool',
    'import_system_notes'        => 'bool',
    'dept_map'                   => 'deptmap',
);

/**
 * Collect + normalise the per-client options from POST into an array
 * suitable for config_json.
 *
 * @param array<string,string> $types Option key => type map.
 * @return array<string,mixed>
 */
$collectOptions = static function (array $types): array {
    $out = array();
    foreach ($types as $key => $type) {
        switch ($type) {
            case 'bool':
                $out[$key] = !empty($_POST['o_' . $key]) ? 1 : 0;
                break;
            case 'int':
                $v = trim((string) ($_POST['o_' . $key] ?? ''));
                $out[$key] = ($v === '' ? null : (int) $v);
                break;
            case 'csv':
                // Keep as entered (validated later by the engine); strip spaces.
                $out[$key] = preg_replace('/\s+/', '', (string) ($_POST['o_' . $key] ?? ''));
                break;
            case 'deptmap':
                // Queue->department routing rows (parallel arrays from the
                // dynamic form) -> "queueId=deptId" lines. Incomplete rows
                // are dropped silently.
                $qs = (array) ($_POST['o_' . $key . '_queue'] ?? array());
                $ds = (array) ($_POST['o_' . $key . '_dept'] ?? array());
                $lines = array();
                foreach ($qs as $i0 => $q0) {
                    $q0 = trim((string) $q0);
                    $d0 = trim((string) ($ds[$i0] ?? ''));
                    if ($q0 !== '' && ctype_digit($q0) && $d0 !== '' && ctype_digit($d0)) {
                        $lines[(int) $q0] = (int) $q0 . '=' . (int) $d0; // last rule per queue wins
                    }
                }
                $out[$key] = implode("\n", $lines);
                break;
            default:
                $out[$key] = trim((string) ($_POST['o_' . $key] ?? ''));
        }
    }
    return $out;
};

/**
 * Field parity on registration: create osTicket Time Types matching the
 * client's Autotask WORK TYPES (billing codes, useType=1) by name, so time
 * entries map 1-to-1 in both directions with zero manual configuration.
 *
 * @param int $instanceId Saved client instance id.
 * @return string[] Names created.
 */
$ensureTimeTypes = static function (int $instanceId) use ($facade): array {
    $created = array();
    try {
        $pluginRef = $facade->container()->plugin();
        $api = $pluginRef->getContainerFor($instanceId)->api();
        $listId = 0;
        $r = db_query("SELECT id FROM " . TABLE_PREFIX . "list WHERE type='time-type' LIMIT 1");
        if ($r && ($x = db_fetch_array($r))) { $listId = (int) $x['id']; }
        if (!$listId) { return array(); }
        $existing = array();
        $r = db_query('SELECT value FROM ' . TABLE_PREFIX . 'list_items WHERE list_id=' . $listId);
        while ($r && ($x = db_fetch_array($r))) { $existing[mb_strtolower(trim($x['value']))] = true; }
        foreach ($api->getBillingCodes() as $b) {
            // Only true work types; skip expense/material codes when typed.
            if (isset($b['useType']) && (int) $b['useType'] !== 1) { continue; }
            $name = trim((string) ($b['name'] ?? ''));
            if ($name === '' || isset($existing[mb_strtolower($name)])) { continue; }
            if (db_query('INSERT INTO ' . TABLE_PREFIX . 'list_items (list_id, status, value, sort) VALUES ('
                . $listId . ', 1, ' . db_input(mb_substr($name, 0, 120)) . ', 1)', false)) {
                $created[] = $name;
                $existing[mb_strtolower($name)] = true;
            }
        }
    } catch (\Throwable $e) {
        // best-effort; connection may not be testable yet
    }
    return $created;
};

/* ---------------------------------------------------------------------------
 * POST actions (CSRF-checked).
 * ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$ost->checkCSRFToken()) {
        $error = 'Invalid CSRF token. Please reload and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $id     = (int) ($_POST['client_id'] ?? 0);
        try {
            switch ($action) {

                case 'save_client':
                    $fields = array(
                        'name'                 => trim((string) ($_POST['c_name'] ?? '')),
                        'code'                 => trim((string) ($_POST['c_code'] ?? '')),
                        'api_username'         => trim((string) ($_POST['c_api_username'] ?? '')),
                        'api_secret'           => (string) ($_POST['c_api_secret'] ?? ''),
                        'api_integration_code' => trim((string) ($_POST['c_api_integration_code'] ?? '')),
                        'zone_url'             => trim((string) ($_POST['c_zone_url'] ?? '')),
                        'department_id'        => (int) ($_POST['c_department_id'] ?? 0),
                        'enabled'              => !empty($_POST['c_enabled']) ? 1 : 0,
                        'config_json'          => $collectOptions($optionTypes),
                    );

                    // Server-side validation.
                    if ($fields['name'] === '' || $fields['code'] === '') {
                        $error = 'Client name and code are required.';
                    } elseif ($fields['api_username'] === '' || $fields['api_integration_code'] === '') {
                        $error = 'API username and integration code are required.';
                    } elseif ($id === 0 && $fields['api_secret'] === '') {
                        $error = 'API secret is required for a new client.';
                    } else {
                        // Code must be unique (other than this row).
                        $existing = $repo->findByCode($fields['code']);
                        if ($existing && $existing->id() !== $id) {
                            $error = 'Client code "' . htmlspecialchars($fields['code'])
                                . '" is already used by ' . htmlspecialchars($existing->name()) . '.';
                        } elseif ($id > 0) {
                            $repo->update($id, $fields);
                            $notice = 'Client updated.';
                        } else {
                            $newId = $repo->create($fields);
                            $notice = $newId
                                ? 'Client registered. Use Test Connection to verify, then import a small date range first.'
                                : 'Could not create the client (see logs).';
                        }
                        // Auto-create any osTicket statuses the Status Map references.
                        if (!$error) {
                            $made = $ensureStatuses(
                                (string) ($fields['config_json']['status_map'] ?? ''),
                                (int) ($fields['config_json']['complete_status'] ?? 5)
                            );
                            if ($made) {
                                $notice .= ' Created osTicket status(es): ' . htmlspecialchars(implode(', ', $made)) . '.';
                            }
                            // Mirror the client's Autotask work types as Time Types.
                            $tt = $ensureTimeTypes($id > 0 ? $id : (int) ($newId ?? 0));
                            if ($tt) {
                                $notice .= ' Created Time Type(s): ' . htmlspecialchars(implode(', ', $tt)) . '.';
                            }
                        }
                    }
                    break;

                case 'toggle_client':
                    if ($id && ($inst = $repo->find($id))) {
                        $repo->setEnabled($id, !$inst->enabled());
                        $notice = $inst->enabled()
                            ? 'Client "' . htmlspecialchars($inst->name()) . '" disabled (sync paused, data kept).'
                            : 'Client "' . htmlspecialchars($inst->name()) . '" enabled.';
                    }
                    break;

                case 'delete_client':
                    if ($id && ($inst = $repo->find($id))) {
                        if ($repo->delete($id)) {
                            $notice = 'Client "' . htmlspecialchars($inst->name()) . '" deleted.';
                        } else {
                            $error = 'This client has synced tickets and cannot be deleted — disable it instead.';
                        }
                    }
                    break;

                case 'refresh_ref':
                    // Force-refresh the picklist cache behind the ID reference.
                    if ($id && $repo->find($id)) {
                        $facade->container()->plugin()->getContainerFor($id)->picklists()->ensureFresh(true);
                        $notice = 'ID reference refreshed from Autotask.';
                    }
                    break;

                case 'sync_client':
                    // Per-client "Sync Now": incremental run for this tenant only.
                    if ($id && ($inst = $repo->find($id))) {
                        $pluginRef = $facade->container()->plugin();
                        $c = $pluginRef->getContainerFor($id);
                        $r = $c->scheduler()->runIncremental();
                        $repo->touchSync($id, ((int) $r['queue']['failed']) === 0);
                        $notice = sprintf('Sync for %s: processed %d, failed %d, pulled %d.',
                            htmlspecialchars($inst->code()),
                            $r['queue']['processed'], $r['queue']['failed'], $r['pulled']);
                    }
                    break;

                case 'test_client':
                    // Test with the posted credentials; blank secret on an
                    // existing client falls back to the stored one.
                    $creds = array(
                        'username'         => trim((string) ($_POST['c_api_username'] ?? '')),
                        'secret'           => (string) ($_POST['c_api_secret'] ?? ''),
                        'integration_code' => trim((string) ($_POST['c_api_integration_code'] ?? '')),
                        'zone_url'         => trim((string) ($_POST['c_zone_url'] ?? '')),
                    );
                    if ($creds['secret'] === '' && $id > 0 && ($inst = $repo->find($id))) {
                        $stored = $inst->credentials();
                        $creds['secret'] = $stored['secret'];
                    }
                    $api = new \Autotask\AutotaskApi($creds);
                    $result = $api->testConnection();
                    if ($result['ok']) {
                        $notice = 'Connection successful. Zone: ' . htmlspecialchars($result['zone_url']);
                        if ($id > 0) {
                            $repo->touchSync($id, true);
                            $web = $api->webBase();
                            if ($web) {
                                $repo->setWebBase($id, $web);
                            }
                        }
                    } else {
                        $error = 'Connection failed: ' . htmlspecialchars($result['message']);
                        if ($id > 0) {
                            $repo->touchSync($id, false);
                        }
                    }
                    break;

                default:
                    $error = 'Unknown action.';
            }
        } catch (\Throwable $e) {
            $error = 'Action failed: ' . htmlspecialchars($e->getMessage());
        }
    }
}

/* ---------------------------------------------------------------------------
 * Gather view data + render inside the osTicket staff chrome.
 * ------------------------------------------------------------------------- */
$mode    = (string) ($_GET['mode'] ?? 'list');           // list | add | edit | tickets
$editing = null;                                          // Instance|null for the form
if ($mode === 'edit') {
    $editing = $repo->find((int) ($_GET['id'] ?? 0));
    if (!$editing) {
        $mode = 'list';
        $error = $error ?: 'Client not found.';
    }
}

// "Tickets" sub-view: the client's mapped tickets, newest activity first.
$clientTickets = array();
if ($mode === 'tickets') {
    $editing = $repo->find((int) ($_GET['id'] ?? 0));
    if (!$editing) {
        $mode = 'list';
        $error = $error ?: 'Client not found.';
    } else {
        $prefix = \Autotask\Installer::prefix();
        $res = db_query(
            'SELECT osticket_ticket_id, autotask_ticket_number, autotask_status, last_sync_time '
            . "FROM `{$prefix}autotask_ticket_map` WHERE instance_id=" . $editing->id()
            . ' ORDER BY updated DESC LIMIT 50'
        );
        while ($res && ($row = db_fetch_array($res))) {
            $t = \Ticket::lookup((int) $row['osticket_ticket_id']);
            $row['number']  = $t ? (string) $t->getNumber() : (string) $row['osticket_ticket_id'];
            $row['subject'] = ($t && method_exists($t, 'getSubject')) ? (string) $t->getSubject() : '';
            $row['status']  = ($t && is_object($t->getStatus())) ? (string) $t->getStatus() : '';
            $clientTickets[] = $row;
        }
    }
}

$clients    = $repo->all();
$csrfToken  = $ost->getCSRF()->getToken();

// Edit mode: live ID reference lists from THIS tenant (cache + API) so admins
// can fill the numeric filter/default fields without hunting inside Autotask.
$refQueues = $refResources = $refCompanies = array();
if ($mode === 'edit' && $editing) {
    try {
        $c = $facade->container()->plugin()->getContainerFor($editing->id());
        $c->picklists()->ensureFresh();
        $refQueues    = $c->picklists()->options('queueID', 'Tickets');
        $refResources = $c->picklists()->options('resourceID');
        $resp = $c->api()->request('GET', 'Companies/query?search=' . rawurlencode(json_encode(array(
            'filter' => array(array('op' => 'eq', 'field' => 'isActive', 'value' => true)),
            'MaxRecords' => 30,
        ))));
        foreach (($resp['items'] ?? array()) as $co) {
            $refCompanies[] = array('value' => (string) $co['id'], 'label' => (string) ($co['companyName'] ?? $co['id']));
        }
    } catch (\Throwable $e) {
        // reference lists are a convenience; the form works without them
    }
}

// osTicket departments for the routing dropdown (id => name).
$departments = array();
if (class_exists('Dept') && method_exists('Dept', 'getDepartments')) {
    $departments = \Dept::getDepartments();
}

// Help topics for imported tickets (id => name). osTicket refuses to close a
// ticket without a topic when "Require Help Topic to Close" is on, and
// Autotask has no equivalent field — so imports get one from here.
$helpTopics = array();
if (class_exists('Topic') && method_exists('Topic', 'getHelpTopics')) {
    try {
        $helpTopics = \Topic::getHelpTopics(false, true);
    } catch (\Throwable $e) {
        $helpTopics = array();
    }
}

// Mapped-ticket counts per instance for the list cards.
$mappedCounts = array();
foreach ($clients as $c) {
    $mappedCounts[$c->id()] = $repo->mappedTickets($c->id());
}

if (method_exists($ost, 'setPageTitle')) {
    $ost->setPageTitle('Autotask Clients');
}
if (isset($nav) && is_object($nav) && method_exists($nav, 'setTabActive')) {
    $nav->setTabActive('dashboard');
}
if (defined('STAFFINC_DIR') && is_file(STAFFINC_DIR . 'header.inc.php')) {
    require STAFFINC_DIR . 'header.inc.php';
    require __DIR__ . '/../templates/clients.tmpl.php';
    require STAFFINC_DIR . 'footer.inc.php';
} else {
    require __DIR__ . '/../templates/clients.tmpl.php';
}
