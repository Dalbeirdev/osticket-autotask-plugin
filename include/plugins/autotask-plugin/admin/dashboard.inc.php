<?php
/**
 * Autotask Integration — Dashboard controller (include).
 *
 * Assumes the osTicket STAFF context is already bootstrapped by the caller
 * (i.e. `$thisstaff` and `$ost` exist). This is included by:
 *   - scp/autotask.php          (recommended; web-accessible)
 *   - admin/dashboard.php       (self-bootstrapping fallback)
 *
 * Enforces admin auth + CSRF and renders the dashboard.
 *
 * @package Autotask Integration
 */

if (!defined('INCLUDE_DIR')) {
    http_response_code(500);
    die('osTicket context not loaded.');
}

// ---------------------------------------------------------------------------
// Authorisation: admins only (role-based access).
// ---------------------------------------------------------------------------
if (!isset($thisstaff) || !$thisstaff || !$thisstaff->isAdmin()) {
    http_response_code(403);
    die('Access denied: administrator privileges required.');
}

// ---------------------------------------------------------------------------
// Resolve the plugin + its service facade.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../bootstrap.php';

/**
 * Locate the installed Autotask plugin instance via osTicket's PluginManager.
 *
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
    if (method_exists('PluginManager', 'allActive')) {
        foreach (PluginManager::allActive() as $p) {
            if ($p instanceof \Autotask\AutotaskPlugin) {
                return $p;
            }
        }
    }
    return null;
}

$plugin = autotask_find_plugin();
if (!$plugin) {
    die('Autotask plugin is not installed/enabled.');
}
$facade = new \Autotask\Autotask($plugin->getContainer());

// Sub-view routing: the Clients (Instance Manager) screens live in their own
// controller and handle their own POSTs/rendering.
if (($_GET['view'] ?? '') === 'clients') {
    require __DIR__ . '/clients.inc.php';
    return;
}

// Lightweight endpoint: return a fresh CSRF token (GET) so the dashboard can
// refresh it right before a POST and avoid "Valid CSRF Token Required".
if (($_GET['action'] ?? '') === 'csrf') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('token' => $ost->getCSRF()->getToken()));
    exit;
}

// ---------------------------------------------------------------------------
// Per-client dashboard filter (?instance=N). Applied BEFORE the POST actions
// so Test Connection / Sync / Import act on the SELECTED client, and every
// read below (stats, queue, logs, audit) is scoped the same way.
// ---------------------------------------------------------------------------
$instFilter = (int) ($_GET['instance'] ?? 0);
$instances  = array();
try {
    $instances = $facade->container()->instanceRepository()->all();
} catch (\Throwable $e) {
    $instances = array();
}
if ($instFilter > 0 && method_exists($plugin, 'getContainerFor')) {
    $facade = new \Autotask\Autotask($plugin->getContainerFor($instFilter));
}

// ---------------------------------------------------------------------------
// Handle POST actions (with CSRF validation).
// ---------------------------------------------------------------------------
$notice = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$ost->checkCSRFToken()) {
        $error = 'Invalid CSRF token. Please reload and try again.';
    } else {
        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
        try {
            switch ($action) {
                case 'test_connection':
                    $res = $facade->testConnection();
                    if ($res['ok']) {
                        $notice = 'Connection successful. Zone: ' . htmlspecialchars($res['zone_url']);
                    } else {
                        $error = 'Connection failed: ' . htmlspecialchars($res['message']);
                    }
                    break;

                case 'sync_incremental':
                    $r = $facade->manualSync('incremental');
                    $notice = sprintf('Incremental sync complete. Processed %d, pulled %d.',
                        $r['queue']['processed'], $r['pulled']);
                    break;

                case 'sync_full':
                    $days = max(1, min(365, (int) ($_POST['lookback'] ?? 30)));
                    $r = $facade->manualSync('full', $days);
                    $notice = sprintf('Full sync complete (%d days). Processed %d, pulled %d.',
                        $days, $r['queue']['processed'], $r['pulled']);
                    break;

                case 'retry_job':
                    $jid = (int) ($_POST['job_id'] ?? 0);
                    if ($jid) {
                        $facade->container()->queue()->retryNow($jid);
                        $notice = "Job #$jid re-queued.";
                    }
                    break;

                case 'retry_all':
                    $n = $facade->container()->queue()->retryAllFailed();
                    $notice = "$n failed job(s) re-queued.";
                    break;

                case 'import_autotask':
                    $limit = max(1, min(200, (int) ($_POST['import_limit'] ?? 25)));
                    $r = $facade->importFromAutotask($limit);
                    $notice = sprintf(
                        'Import: fetched %d, queued %d, skipped %d; processed %d, failed %d.',
                        $r['fetched'], $r['queued'], $r['skipped'], $r['processed'], $r['failed']
                    );
                    break;

                case 'import_one':
                    $atId = (int) ($_POST['at_ticket_id'] ?? 0);
                    if (!$atId) {
                        $error = 'Enter an Autotask ticket ID.';
                    } else {
                        $r = $facade->importById($atId);
                        if ($r['ok']) { $notice = 'Import by ID #' . $atId . ': ' . $r['message']; }
                        else { $error = 'Import by ID #' . $atId . ': ' . $r['message']; }
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

// ---------------------------------------------------------------------------
// Log CSV export (GET, CSRF-protected via token query param).
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'logs') {
    if (!$ost->validateCSRFToken($_GET['csrf'] ?? '')) {
        http_response_code(403);
        die('Invalid token.');
    }
    $csv = $facade->container()->logger()->exportCsv();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="autotask-logs-' . date('Ymd-His') . '.csv"');
    echo $csv;
    exit;
}

// ---------------------------------------------------------------------------
// Gather data + render.
// ---------------------------------------------------------------------------
$stats      = $facade->stats();
$logs       = $facade->recentLogs(100, 0, $_GET['level'] ?? null);
$failedJobs = $facade->failedJobs(50);
$audit      = $facade->recentAudit(50);
$csrfToken  = $ost->getCSRF()->getToken();

// Render inside the standard osTicket staff chrome (header/nav + footer) so
// the dashboard looks native. All JSON/CSV endpoints above exit before this.
if (method_exists($ost, 'setPageTitle')) {
    $ost->setPageTitle('Autotask Integration');
}
if (isset($nav) && is_object($nav) && method_exists($nav, 'setTabActive')) {
    $nav->setTabActive('dashboard');
}
if (defined('STAFFINC_DIR') && is_file(STAFFINC_DIR . 'header.inc.php')) {
    require STAFFINC_DIR . 'header.inc.php';
    require __DIR__ . '/../templates/dashboard.tmpl.php';
    require STAFFINC_DIR . 'footer.inc.php';
} else {
    require __DIR__ . '/../templates/dashboard.tmpl.php';
}
