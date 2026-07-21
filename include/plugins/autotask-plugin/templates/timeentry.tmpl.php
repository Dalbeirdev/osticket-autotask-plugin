<?php
/**
 * Autotask Integration — Time Entry panel view.
 *
 * In scope: $ticket, $mapping, $picklists, $history, $csrfToken, $notice,
 *           $error, $fieldErrors
 *
 * @package Autotask Integration
 */
if (!defined('INCLUDE_DIR')) { die('Access denied'); }

$e = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
};
/** Render a <select> from picklist options. */
$opts = static function (array $options, $selected, string $placeholder) use ($e): string {
    $h = '<option value="">' . $e($placeholder) . '</option>';
    foreach ($options as $o) {
        $sel = ((string) $selected === (string) $o['value']) ? ' selected' : '';
        $h .= '<option value="' . $e($o['value']) . '"' . $sel . '>' . $e($o['label']) . '</option>';
    }
    return $h;
};
/** value => label map for history display. */
$labelMap = static function (array $options): array {
    $m = array();
    foreach ($options as $o) { $m[(string) $o['value']] = $o['label']; }
    return $m;
};
$workMap = $labelMap($picklists['work_types']);
$resMap  = $labelMap($picklists['resources']);

$atCss  = @file_get_contents(__DIR__ . '/../assets/css/autotask.css');
$isMapped = $mapping && !empty($mapping['autotask_ticket_id']);
$fe = static function (string $k) use ($fieldErrors, $e): string {
    return isset($fieldErrors[$k]) ? '<div class="at-fielderr">' . $e($fieldErrors[$k]) . '</div>' : '';
};
?>
<style><?= $atCss ?>
.at-form{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:14px;max-width:760px}
.at-form .full{grid-column:1/-1}
.at-form label{display:block;font-size:12px;font-weight:600;color:#51606e;margin-bottom:4px}
.at-form input,.at-form select,.at-form textarea{width:100%;padding:7px;border:1px solid #cfd6dc;border-radius:5px;font-size:13px;box-sizing:border-box}
.at-form textarea{min-height:64px}
.at-fielderr{color:#c0392b;font-size:12px;margin-top:3px}
.at-check{display:flex;align-items:center;gap:6px}
.at-check input{width:auto}
.badge-synced{color:#1f8f4d;font-weight:600}.badge-pending{color:#b8860b;font-weight:600}.badge-failed{color:#c0392b;font-weight:600}
</style>
<div class="at-wrap">
    <header class="at-header">
        <h1>Autotask Time Entry <span class="at-sub">Ticket #<?= $e($ticket->getNumber()) ?></span></h1>
    </header>

    <?php if (!empty($notice)): ?><div class="at-alert at-success"><?= $e($notice) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="at-alert at-error"><?= $e($error) ?></div><?php endif; ?>

    <?php if (!$isMapped): ?>
        <div class="at-alert at-error">
            This ticket isn't linked to an Autotask ticket yet, so time can't be logged.
            Sync/import it first.
        </div>
    <?php else: ?>
        <p class="at-muted">Linked Autotask ticket:
            <strong>#<?= $e($mapping['autotask_ticket_number'] ?: $mapping['autotask_ticket_id']) ?></strong></p>

        <section class="at-panel">
            <h2>Add Time Entry</h2>
            <form method="post">
                <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="action" value="add_time">
                <div class="at-form">
                    <div>
                        <label>Resource *</label>
                        <select name="resource_id"><?= $opts($picklists['resources'], $_POST['resource_id'] ?? '', '— Select resource —') ?></select>
                        <?= $fe('resource_id') ?>
                    </div>
                    <div>
                        <label>Work Type *</label>
                        <select name="billing_code_id"><?= $opts($picklists['work_types'], $_POST['billing_code_id'] ?? '', '— Select work type —') ?></select>
                        <?= $fe('billing_code_id') ?>
                    </div>
                    <div>
                        <label>Role</label>
                        <select name="role_id"><?= $opts($picklists['roles'], $_POST['role_id'] ?? '', '— Optional —') ?></select>
                        <?= $fe('role_id') ?>
                    </div>
                    <div>
                        <label>Date Worked</label>
                        <input type="date" name="date_worked" value="<?= $e($_POST['date_worked'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div>
                        <label>Hours Worked</label>
                        <input type="number" name="hours_worked" step="0.25" min="0" max="24"
                               value="<?= $e($_POST['hours_worked'] ?? '') ?>" placeholder="e.g. 1.5">
                        <?= $fe('hours_worked') ?>
                    </div>
                    <div>
                        <label>or Start / End time</label>
                        <div style="display:flex;gap:6px">
                            <input type="time" name="start_time" value="<?= $e($_POST['start_time'] ?? '') ?>">
                            <input type="time" name="end_time" value="<?= $e($_POST['end_time'] ?? '') ?>">
                        </div>
                        <?= $fe('time') ?>
                    </div>
                    <div class="full">
                        <label>Summary Notes *</label>
                        <textarea name="summary_notes" placeholder="What was done"><?= $e($_POST['summary_notes'] ?? '') ?></textarea>
                        <?= $fe('summary_notes') ?>
                    </div>
                    <div class="full">
                        <label>Internal Notes</label>
                        <textarea name="internal_notes"><?= $e($_POST['internal_notes'] ?? '') ?></textarea>
                    </div>
                    <div class="at-check full">
                        <input type="checkbox" name="billable" id="billable" value="1"
                            <?= (!isset($_POST['action']) || !empty($_POST['billable'])) ? 'checked' : '' ?>>
                        <label for="billable" style="margin:0">Billable</label>
                    </div>
                </div>
                <p><button type="submit" class="at-btn">Save Time Entry</button></p>
            </form>
        </section>
    <?php endif; ?>

    <section class="at-panel">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <h2 style="margin:0">Time Entry History</h2>
            <form method="post" style="margin:0">
                <input type="hidden" name="__CSRFToken__" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="action" value="refresh_picklists">
                <button type="submit" class="at-btn at-btn-ghost at-btn-sm">Refresh picklists</button>
            </form>
        </div>
        <table class="at-table">
            <thead><tr><th>Date</th><th>Resource</th><th>Work Type</th><th>Hours</th><th>Billable</th><th>Status</th><th>AT ID</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= $e(substr((string) $h['start_datetime'], 0, 10)) ?></td>
                    <td><?= $e($resMap[(string) $h['resource_id']] ?? $h['resource_id']) ?></td>
                    <td><?= $e($workMap[(string) $h['billing_code_id']] ?? $h['billing_code_id']) ?></td>
                    <td><?= $e($h['hours_worked']) ?></td>
                    <td><?= ((int) $h['billable'] === 1) ? 'Yes' : 'No' ?></td>
                    <td><span class="badge-<?= $e($h['status']) ?>"><?= $e($h['status']) ?></span></td>
                    <td><?= $e($h['autotask_time_entry_id']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$history): ?>
                <tr><td colspan="7" class="at-muted">No time entries yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</div>
