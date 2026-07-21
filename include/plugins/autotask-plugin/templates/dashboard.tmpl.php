<?php
/**
 * Autotask Integration — Dashboard view template.
 *
 * Variables in scope (from admin/dashboard.php):
 *   $stats, $logs, $failedJobs, $csrfToken, $notice, $error
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

$conn = $stats['connection'] ?? null;
$connOk = $conn['ok'] ?? null;
?>
<?php
/* Inline the stylesheet — /include/ is web-blocked, so external <link> to the
   plugin assets would 403. Reading from disk server-side is fine. */
$atCss = @file_get_contents(__DIR__ . '/../assets/css/autotask.css');
$atJs  = @file_get_contents(__DIR__ . '/../assets/js/autotask.js');
?>
<style><?= $atCss ?></style>
<div class="at-wrap">
    <header class="at-header">
        <h1>Autotask Integration <span class="at-sub">Dashboard</span></h1>
        <div class="at-badges">
            <?php if (!empty($instances) && count($instances) > 1): $instFilter = (int) ($instFilter ?? 0); ?>
            <form method="get" class="at-inline" action="autotask.php">
                <select name="instance" onchange="this.form.submit()" class="at-fld" style="width:auto;padding:6px 8px">
                    <option value="0">All clients</option>
                    <?php foreach ($instances as $ins): ?>
                        <option value="<?= $ins->id() ?>" <?= $instFilter === $ins->id() ? 'selected' : '' ?>>
                            <?= $e($ins->code() . ' — ' . $ins->name()) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
            <a class="at-btn" href="autotask.php?view=clients">Clients</a>
            <span class="at-badge <?= $stats['enabled'] ? 'on' : 'off' ?>">
                <?= $stats['enabled'] ? 'Enabled' : 'Disabled' ?>
            </span>
            <span class="at-badge <?= $connOk === true ? 'ok' : ($connOk === false ? 'bad' : 'unk') ?>">
                Connection: <?= $connOk === true ? 'OK' : ($connOk === false ? 'FAILED' : 'Unknown') ?>
            </span>
            <span class="at-badge <?= $stats['two_way'] ? 'on' : 'off' ?>">
                Two-way: <?= $stats['two_way'] ? 'On' : 'Off' ?>
            </span>
        </div>
    </header>

    <?php if (!empty($notice)): ?>
        <div class="at-alert at-success"><?= $e($notice) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="at-alert at-error"><?= $e($error) ?></div>
    <?php endif; ?>

    <!-- KPI cards ----------------------------------------------------- -->
    <section class="at-cards">
        <div class="at-card">
            <div class="at-card-num"><?= (int) $stats['total_mapped'] ?></div>
            <div class="at-card-lbl">Synced Tickets</div>
        </div>
        <div class="at-card">
            <div class="at-card-num"><?= (int) $stats['queue']['pending'] ?></div>
            <div class="at-card-lbl">Queue Pending</div>
        </div>
        <div class="at-card warn">
            <div class="at-card-num"><?= (int) $stats['failed_syncs'] ?></div>
            <div class="at-card-lbl">Failed Syncs</div>
        </div>
        <div class="at-card">
            <div class="at-card-num"><?= (int) $stats['queue']['dead'] ?></div>
            <div class="at-card-lbl">Dead Jobs</div>
        </div>
        <div class="at-card">
            <div class="at-card-num"><?= $e($stats['last_inbound'] ? date('Y-m-d H:i', strtotime($stats['last_inbound'])) : '—') ?></div>
            <div class="at-card-lbl">Last Inbound Sync</div>
        </div>
    </section>

    <!-- Actions ------------------------------------------------------- -->
    <section class="at-actions">
        <form method="post" class="at-inline">
            <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="action" value="test_connection">
            <button type="submit" class="at-btn">Test Connection</button>
        </form>
        <form method="post" class="at-inline">
            <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="action" value="sync_incremental">
            <button type="submit" class="at-btn">Run Incremental Sync</button>
        </form>
        <form method="post" class="at-inline">
            <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="action" value="sync_full">
            <label>Full sync lookback (days)
                <input type="number" name="lookback" value="30" min="1" max="365" class="at-num">
            </label>
            <button type="submit" class="at-btn at-btn-alt"
                onclick="return confirm('Run a full sync? This may take a while.');">Run Full Sync</button>
        </form>
        <form method="post" class="at-inline">
            <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="action" value="import_autotask">
            <label>Import open AT tickets (max)
                <input type="number" name="import_limit" value="25" min="1" max="200" class="at-num">
            </label>
            <button type="submit" class="at-btn at-btn-alt"
                onclick="return confirm('Import open Autotask tickets into osTicket as new tickets?');">Import from Autotask</button>
        </form>
        <form method="post" class="at-inline">
            <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="action" value="import_one">
            <label>Import AT ticket by ID
                <input type="number" name="at_ticket_id" class="at-num" style="width:120px" placeholder="e.g. 300957">
            </label>
            <button type="submit" class="at-btn at-btn-alt">Import by ID</button>
        </form>
        <form method="post" class="at-inline">
            <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="action" value="retry_all">
            <button type="submit" class="at-btn at-btn-warn">Retry All Failed</button>
        </form>
        <a class="at-btn at-btn-ghost"
           href="?export=logs&amp;csrf=<?= $e(urlencode($csrfToken)) ?>">Download Logs (CSV)</a>
    </section>

    <!-- Queue statistics --------------------------------------------- -->
    <section class="at-box">
        <h2>Queue Statistics</h2>
        <table class="at-table">
            <thead><tr><th>Pending</th><th>Processing</th><th>Done</th><th>Failed</th><th>Dead</th></tr></thead>
            <tbody>
                <tr>
                    <td><?= (int) $stats['queue']['pending'] ?></td>
                    <td><?= (int) $stats['queue']['processing'] ?></td>
                    <td><?= (int) $stats['queue']['done'] ?></td>
                    <td class="bad"><?= (int) $stats['queue']['failed'] ?></td>
                    <td class="bad"><?= (int) $stats['queue']['dead'] ?></td>
                </tr>
            </tbody>
        </table>
    </section>

    <!-- Failed jobs / retry queue ------------------------------------ -->
    <section class="at-box">
        <h2>Failed &amp; Dead Jobs</h2>
        <?php if (!$failedJobs): ?>
            <p class="at-muted">No failed jobs. 🎉</p>
        <?php else: ?>
        <table class="at-table">
            <thead><tr>
                <th>ID</th><th>osTicket #</th><th>Type</th><th>Action</th>
                <th>Status</th><th>Attempts</th><th>Next Attempt</th><th>Error</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($failedJobs as $j): ?>
                <tr>
                    <td><?= (int) $j['id'] ?></td>
                    <td><?= $e($j['osticket_ticket_id']) ?></td>
                    <td><?= $e($j['entity_type']) ?></td>
                    <td><?= $e($j['action']) ?></td>
                    <td class="badge-<?= $e($j['status']) ?>"><?= $e($j['status']) ?></td>
                    <td><?= (int) $j['attempts'] ?></td>
                    <td><?= $e($j['next_attempt_at']) ?></td>
                    <td class="at-err"><?= $e(mb_strimwidth((string) $j['last_error'], 0, 120, '…')) ?></td>
                    <td>
                        <form method="post" class="at-inline">
                            <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="action" value="retry_job">
                            <input type="hidden" name="job_id" value="<?= (int) $j['id'] ?>">
                            <button type="submit" class="at-btn at-btn-sm">Retry</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <!-- Audit trail --------------------------------------------------- -->
    <section class="at-box">
        <h2 class="at-collapsible" data-key="audit" data-default="open">Audit Trail
            <span class="at-count">(<?= count($audit) ?>)</span><span class="at-chev">&#9662;</span></h2>
        <div class="at-body">
        <table class="at-table">
            <thead><tr><th>Time</th><th>Agent</th><th>Action</th><th>osTicket#</th><th>AT#</th></tr></thead>
            <tbody>
            <?php foreach ($audit as $a): ?>
                <tr>
                    <td><?= $e($a['created']) ?></td>
                    <td><?= $e($a['staff_name']) ?></td>
                    <td><?= $e($a['action']) ?></td>
                    <td><?= $e($a['osticket_ticket_id']) ?></td>
                    <td><?= $e($a['autotask_ticket_id']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$audit): ?>
                <tr><td colspan="5" class="at-muted">No audit entries yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>

    <!-- Error logs ---------------------------------------------------- -->
    <section class="at-box">
        <?php /* Expand automatically when a level filter is being used. */ ?>
        <h2 class="at-collapsible" data-key="logs"
            data-default="<?= empty($_GET['level']) ? 'collapsed' : 'open' ?>">Recent Logs
            <span class="at-count">(<?= count($logs) ?>)</span><span class="at-chev">&#9662;</span></h2>
        <div class="at-body">
        <?php $qsInst = !empty($instFilter) ? '&amp;instance=' . (int) $instFilter : ''; ?>
        <div class="at-filter">
            <a href="?<?= ltrim($qsInst, '&amp;') ?>" class="<?= empty($_GET['level']) ? 'active' : '' ?>">All</a>
            <a href="?level=error<?= $qsInst ?>" class="<?= ($_GET['level'] ?? '') === 'error' ? 'active' : '' ?>">Errors</a>
            <a href="?level=warning<?= $qsInst ?>" class="<?= ($_GET['level'] ?? '') === 'warning' ? 'active' : '' ?>">Warnings</a>
            <a href="?level=info<?= $qsInst ?>" class="<?= ($_GET['level'] ?? '') === 'info' ? 'active' : '' ?>">Info</a>
        </div>
        <table class="at-table at-logs">
            <thead><tr><th>Time</th><th>Level</th><th>Category</th><th>osTicket#</th><th>AT#</th><th>Message</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr class="lvl-<?= $e($log['level']) ?>">
                    <td><?= $e($log['created']) ?></td>
                    <td><span class="lvl-pill lvl-<?= $e($log['level']) ?>"><?= $e($log['level']) ?></span></td>
                    <td><?= $e($log['category']) ?></td>
                    <td><?= $e($log['osticket_ticket_id']) ?></td>
                    <td><?= $e($log['autotask_ticket_id']) ?></td>
                    <td><?= $e($log['message']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?>
                <tr><td colspan="6" class="at-muted">No log entries.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>

    <footer class="at-footer">
        Autotask Integration v1.0.0 &middot; Interval: <?= (int) $stats['interval'] ?>s
    </footer>
</div>
<script><?= $atJs ?></script>
