<?php
/**
 * Autotask Integration — Time Entry service.
 *
 * Validates technician input against cached Autotask picklists, persists a local
 * time-entry record, queues the outbound create, and (via the queue worker)
 * posts it to Autotask. Every successful entry is audited.
 *
 * @package Autotask Integration
 */

namespace Autotask;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Time-entry lifecycle: validate -> persist -> enqueue -> post -> audit.
 */
class TimeEntryService
{
    /** @var Settings */        private $settings;
    /** @var Queue */           private $queue;
    /** @var AutotaskApi */     private $api;
    /** @var PicklistService */ private $picklists;
    /** @var Ticket */          private $mapper;
    /** @var Logger */          private $logger;
    /** @var Audit */           private $audit;

    public function __construct(Settings $settings, Queue $queue, AutotaskApi $api,
        PicklistService $picklists, Ticket $mapper, Logger $logger, Audit $audit)
    {
        $this->settings  = $settings;
        $this->queue     = $queue;
        $this->api       = $api;
        $this->picklists = $picklists;
        $this->mapper    = $mapper;
        $this->logger    = $logger;
        $this->audit     = $audit;
    }

    /**
     * Validate + persist + queue a new time entry for an osTicket ticket.
     *
     * @param int   $ostId   osTicket ticket id.
     * @param array $vars    Submitted form values.
     * @param array $staff   {id, name, ip}
     * @return array{ok:bool, errors:array, id:?int}
     */
    public function add(int $ostId, array $vars, array $staff): array
    {
        $map = $this->mapper->findByOsticketId($ostId);
        if (!$map || empty($map['autotask_ticket_id'])) {
            return array('ok' => false, 'errors' => array('err' =>
                'This ticket is not linked to an Autotask ticket yet.'), 'id' => null);
        }

        // Make sure picklists are cached so validation has reference data.
        $this->picklists->ensureFresh();

        $errors = array();
        $clean  = $this->validate($vars, $errors);
        if ($errors) {
            return array('ok' => false, 'errors' => $errors, 'id' => null);
        }

        // Persist local record (pending) then enqueue the outbound create.
        $teId = $this->insert($map, $ostId, $clean, $staff);
        $this->queue->enqueue('timeentry', 'create', $ostId,
            array('time_entry_id' => $teId), 'timeentry-' . $teId, 'to_autotask');

        return array('ok' => true, 'errors' => array(), 'id' => $teId);
    }

    /**
     * Queue worker: post a pending local time entry to Autotask.
     *
     * @param array $payload {time_entry_id:int}
     * @throws ApiException on failure (so the queue can retry/backoff).
     */
    public function processQueuedTimeEntry(array $payload): void
    {
        $teId = (int) ($payload['time_entry_id'] ?? 0);
        if (!$teId) {
            return;
        }
        $row = $this->getById($teId);
        if (!$row) {
            return;
        }
        if ($row['status'] === 'synced') {
            return; // idempotent
        }

        // NOTE: the "Autotask time entry within 2h has priority" conflict rule
        // was removed at the user's request (2026-07-10) — every osTicket time
        // entry now posts unconditionally. Reinstate here if policy changes.

        $fields = array(
            'ticketID'    => (int) $row['autotask_ticket_id'],
            'resourceID'  => (int) $row['resource_id'],
            'dateWorked'  => $this->isoDate($row['start_datetime'] ?: gmdate('Y-m-d')),
            'hoursWorked' => (float) $row['hours_worked'],
            'billingCodeID' => (int) $row['billing_code_id'],
            'summaryNotes'  => (string) $row['summary_notes'],
            'isNonBillable' => !((int) $row['billable'] === 1),
        );
        if (!empty($row['role_id'])) {
            $fields['roleID'] = (int) $row['role_id'];
        }
        if (!empty($row['internal_notes'])) {
            $fields['internalNotes'] = (string) $row['internal_notes'];
        }
        if (!empty($row['start_datetime']) && !empty($row['end_datetime'])) {
            $fields['startDateTime'] = $this->isoDateTime($row['start_datetime']);
            $fields['endDateTime']   = $this->isoDateTime($row['end_datetime']);
        } else {
            // Some tenants enforce "Service tickets require a start and stop
            // time" — derive a window ending at the entry's creation moment.
            $secs  = max(60, (int) round(((float) $row['hours_worked']) * 3600));
            $endTs = strtotime((string) ($row['created'] ?? '')) ?: time();
            $fields['endDateTime']   = gmdate('Y-m-d\TH:i:s', $endTs);
            $fields['startDateTime'] = gmdate('Y-m-d\TH:i:s', $endTs - $secs);
            $fields['dateWorked']    = gmdate('Y-m-d', $endTs - $secs);
        }

        try {
            $atId = $this->api->createTimeEntry($fields);
            $this->updateStatus($teId, 'synced', $atId, null);
            $this->audit->log('time.add', array(
                'staff_id' => $row['created_by'],
                'entity' => 'timeentry',
                'osticket_ticket_id' => (int) $row['osticket_ticket_id'],
                'autotask_ticket_id' => (int) $row['autotask_ticket_id'],
                'detail' => array('autotask_time_entry_id' => $atId, 'hours' => $row['hours_worked']),
            ));
            $this->logger->info("Time entry #$teId posted to Autotask (#$atId)",
                array('category' => 'timeentry', 'osticket_ticket_id' => (int) $row['osticket_ticket_id']));
        } catch (\Throwable $e) {
            $this->updateStatus($teId, 'failed', null, $e->getMessage());
            throw ($e instanceof ApiException) ? $e : new ApiException($e->getMessage());
        }
    }

    /**
     * @param int $ostId
     * @return array<int,array<string,mixed>> Local time entries for a ticket.
     */
    public function listForTicket(int $ostId): array
    {
        $prefix = Installer::prefix();
        $id = (int) $ostId;
        $rows = array();
        $res = db_query(
            "SELECT * FROM `{$prefix}autotask_time_entry` WHERE osticket_ticket_id=$id ORDER BY id DESC LIMIT 100"
        );
        if ($res) {
            while ($row = db_fetch_array($res)) {
                // Who worked, and from which side: rows captured from the
                // osTicket reply form carry created_by (staff id); imported
                // Autotask entries carry only the AT resource id.
                if (!empty($row['created_by'])) {
                    $row['source'] = 'osTicket';
                    $row['tech']   = $this->staffName((int) $row['created_by']) ?: 'Agent';
                } else {
                    $row['source'] = 'Autotask';
                    $row['tech']   = 'Autotask';
                    if (!empty($row['resource_id'])) {
                        $r2 = db_query("SELECT label FROM `{$prefix}autotask_picklist_cache` "
                            . 'WHERE instance_id=' . (int) ($row['instance_id'] ?? 1)
                            . " AND field='resourceID' AND value="
                            . db_input((string) (int) $row['resource_id']) . ' LIMIT 1', false);
                        if ($r2 && ($x2 = db_fetch_array($r2)) && trim((string) $x2['label']) !== '') {
                            $row['tech'] = trim((string) $x2['label']);
                        }
                    }
                }
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /* Inline capture from the staff reply/note form                       */
    /* ------------------------------------------------------------------ */

    /**
     * Inspect the current submission ($_POST) for the configured time fields and,
     * if present, create a time entry for the entry's ticket. Non-blocking: logs
     * and returns on any problem so it never disrupts posting the note/reply.
     *
     * @param mixed $entry \ThreadEntry just created.
     */
    public function captureFromThreadEntry($entry): void
    {
        if (!$this->settings->captureTimeEnabled() || !is_object($entry)) {
            return;
        }
        // Only staff-authored entries carry the time fields.
        $staffId = method_exists($entry, 'getStaffId') ? (int) $entry->getStaffId() : 0;
        if ($staffId <= 0) {
            return;
        }

        $f = $this->settings->timeFieldNames();
        $spentRaw = $_POST[$f['spent']] ?? null;
        if ($spentRaw === null || $spentRaw === '' || !is_numeric($spentRaw)) {
            return; // no time entered
        }
        $spent = (float) $spentRaw;
        // 4-decimal precision so 20 minutes lands as 0.3333h, not 0.33h —
        // Autotask shows exactly what osTicket recorded.
        $hours = $this->settings->timeSpentInMinutes() ? round($spent / 60, 4) : round($spent, 4);
        if ($hours <= 0) {
            return;
        }

        $ostId = $this->ticketIdFromEntry($entry);
        if (!$ostId) {
            return;
        }
        $map = $this->mapper->findByOsticketId($ostId);
        if (!$map || empty($map['autotask_ticket_id'])) {
            $this->logger->warning("Time capture skipped: osTicket #$ostId not linked to Autotask",
                array('category' => 'timeentry', 'osticket_ticket_id' => $ostId));
            return;
        }

        // ATTRIBUTION (client-facing policy): work done by ANY osTicket
        // technician is submitted to Autotask under the ticket's ASSIGNED
        // resource — the client sees their own technician, never our
        // internal team structure. The real worker remains recorded on the
        // osTicket side (thread poster + Technician column in the panel).
        // Fallbacks: the agent's email if it matches an Autotask resource,
        // then the configured default resource.
        $resourceId = null;
        $roleId = null;
        try {
            $atT = $this->api->getTicket((int) $map['autotask_ticket_id']);
            if ($atT && !empty($atT['assignedResourceID'])) {
                $resourceId = (int) $atT['assignedResourceID'];
                $roleId = (int) ($atT['assignedResourceRoleID'] ?? 0) ?: null;
            }
        } catch (\Throwable $e) {
            // unassigned or unreachable — fall through to email/default
        }
        if (!$resourceId) {
            $resourceId = $this->resolveResourceId($staffId);
        }
        if (!$resourceId) {
            $this->logger->warning("Time capture skipped: no Autotask resource for staff #$staffId",
                array('category' => 'timeentry', 'osticket_ticket_id' => $ostId));
            return;
        }

        $typeLabel  = isset($_POST[$f['type']]) ? (string) $_POST[$f['type']] : '';
        $workTypeId = $this->resolveWorkTypeId($typeLabel);
        if (!$workTypeId) {
            $this->logger->warning("Time capture skipped: no work-type mapping for '$typeLabel'",
                array('category' => 'timeentry', 'osticket_ticket_id' => $ostId));
            return;
        }
        // FIELD PARITY: when the agent's chosen Time Type has no Autotask
        // equivalent and the DEFAULT work type was used, mirror that actual
        // work type back onto the thread entry — both sides must read the
        // same label, and the agent's local pick would silently disagree.
        $this->mirrorWorkTypeOnEntry($entry, $workTypeId, $typeLabel);

        $vars = array(
            'resource_id'     => $resourceId,
            'billing_code_id' => $workTypeId,
            'hours_worked'    => $hours,
            'date_worked'     => gmdate('Y-m-d'),
            'summary_notes'   => $this->entrySummary($entry, $ostId),
            'billable'        => !empty($_POST[$f['billable']]) ? 1 : 0,
        );
        if ($roleId) {
            // Assigned resource's role: a guaranteed-valid pairing in Autotask.
            $vars['role_id'] = $roleId;
        }
        // (Name-prefix feature removed at user request 2026-07-11: entry text
        // stays pure; attribution comes from resource email matching only.)
        $staff = array(
            'id'   => $staffId,
            'name' => $this->staffName($staffId),
            'ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
        );

        $res = $this->add($ostId, $vars, $staff);
        if (!$res['ok']) {
            $this->logger->warning('Time capture validation failed: ' . json_encode($res['errors']),
                array('category' => 'timeentry', 'osticket_ticket_id' => $ostId));
        } else {
            $this->logger->info("Captured {$hours}h from reply/note for osTicket #$ostId (queued)",
                array('category' => 'timeentry', 'osticket_ticket_id' => $ostId));
        }
    }

    /**
     * Both-sides parity for the Time Type label: if the pushed Autotask work
     * type's name differs from the agent's chosen list item, point the thread
     * entry at the mirrored list item with that exact name (created at client
     * registration by $ensureTimeTypes; ensured here as a fallback).
     *
     * @param mixed  $entry      \ThreadEntry just created.
     * @param int    $workTypeId Autotask billingCodeID actually pushed.
     * @param string $chosen     Raw posted time_type (list item id or label).
     */
    private function mirrorWorkTypeOnEntry($entry, int $workTypeId, string $chosen): void
    {
        try {
            $atLabel = trim((string) $this->picklists->labelByValue('billingCodeID', (string) $workTypeId));
            if ($atLabel === '' || !is_object($entry) || !method_exists($entry, 'getId')) {
                return;
            }
            // Already the same label? Nothing to do.
            $chosenLabel = $chosen;
            if (ctype_digit(trim($chosen)) && class_exists('DynamicListItem')) {
                $it = \DynamicListItem::lookup((int) $chosen);
                if ($it && method_exists($it, 'getValue')) {
                    $chosenLabel = (string) $it->getValue();
                }
            }
            if (mb_strtolower(trim($chosenLabel)) === mb_strtolower($atLabel)) {
                return;
            }
            // Find (or create) the mirrored list item named like the AT type.
            $r = db_query('SELECT li.id FROM ' . TABLE_PREFIX . 'list_items li JOIN '
                . TABLE_PREFIX . "list l ON li.list_id=l.id WHERE l.type='time-type' AND LOWER(li.value)="
                . db_input(mb_strtolower($atLabel)) . ' LIMIT 1', false);
            $itemId = ($r && ($x = db_fetch_array($r))) ? (int) $x['id'] : 0;
            if (!$itemId) {
                $rl = db_query('SELECT id FROM ' . TABLE_PREFIX . "list WHERE type='time-type' LIMIT 1", false);
                $listId = ($rl && ($xl = db_fetch_array($rl))) ? (int) $xl['id'] : 0;
                if ($listId) {
                    db_query('INSERT INTO ' . TABLE_PREFIX . 'list_items (list_id, status, value, sort) VALUES ('
                        . $listId . ', 1, ' . db_input(mb_substr($atLabel, 0, 255)) . ', 0)', false);
                    $itemId = (int) db_insert_id();
                }
            }
            if ($itemId) {
                db_query('UPDATE ' . TABLE_PREFIX . 'thread_entry SET time_type=' . $itemId
                    . ' WHERE id=' . (int) $entry->getId(), false);
                $this->logger->info("Time Type '$chosenLabel' has no Autotask equivalent — entry shows the pushed type '$atLabel' on both sides",
                    array('category' => 'timeentry'));
            }
        } catch (\Throwable $e) {
            // display-parity only; never block the capture
        }
    }

    /**
     * Resolve the Autotask resource id for an osTicket staff member, caching the
     * email->id lookup. Falls back to the configured default resource.
     */
    private function resolveResourceId(int $staffId): ?int
    {
        $email = $this->staffEmail($staffId);
        if ($email !== '') {
            $key = 'res_email:' . mb_strtolower($email);
            $cached = $this->settings->state($key);
            if ($cached) {
                return (int) $cached;
            }
            try {
                $r = $this->api->getResourceByEmail($email);
                if ($r && !empty($r['id'])) {
                    $this->settings->setState($key, (int) $r['id']);
                    return (int) $r['id'];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Resource lookup failed: ' . $e->getMessage(),
                    array('category' => 'timeentry'));
            }
        }
        // AUTHOR PARITY warning: entries pushed under the default resource
        // show the wrong technician in Autotask. Fix = create an Autotask
        // resource whose email matches this agent's osTicket email.
        $this->logger->warning('No Autotask resource matches staff #' . $staffId
            . ($email !== '' ? " ($email)" : '') . ' — time entry will be attributed to the DEFAULT resource in Autotask',
            array('category' => 'timeentry'));
        return $this->settings->defaultResourceId();
    }

    /**
     * Map a Time Type label to an Autotask billing code id (config map, then default).
     */
    private function resolveWorkTypeId(string $label): ?int
    {
        $map = $this->settings->timeTypeMap();
        $key = mb_strtolower(trim($label));
        if ($key !== '' && isset($map[$key])) {
            return $map[$key];
        }
        // The core time-tracking mod posts the DynamicList ITEM ID, not the
        // label. Resolve the item's label so admins can map human names
        // ("Telephone=123456") instead of internal list ids.
        $itemLabel = '';
        if ($key !== '' && ctype_digit($key) && class_exists('DynamicListItem')) {
            try {
                $item = \DynamicListItem::lookup((int) $key);
                if ($item && method_exists($item, 'getValue')) {
                    $itemLabel = mb_strtolower(trim((string) $item->getValue()));
                    if ($itemLabel !== '' && isset($map[$itemLabel])) {
                        return $map[$itemLabel];
                    }
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }
        // Automatic name-equality mapping: Time Types are mirrored from the
        // client's Autotask work types at registration, so identical names
        // resolve without any manual map.
        foreach (array($itemLabel, $key) as $cand) {
            if ($cand !== '') {
                $v = $this->picklists->valueByLabel('billingCodeID', $cand);
                if ($v !== null) {
                    return (int) $v;
                }
            }
        }
        return $this->settings->defaultWorkTypeId();
    }

    private function ticketIdFromEntry($entry): ?int
    {
        try {
            if (method_exists($entry, 'getThread')) {
                $thread = $entry->getThread();
                if ($thread && method_exists($thread, 'getObjectId')
                    && method_exists($thread, 'getObjectType')
                    && $thread->getObjectType() === 'T') {
                    return (int) $thread->getObjectId();
                }
            }
            if (method_exists($entry, 'getTicket') && $entry->getTicket()) {
                return (int) $entry->getTicket()->getId();
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }

    private function entrySummary($entry, int $ostId): string
    {
        // Full-text parity: the COMPLETE reply/note body becomes the Autotask
        // summary (its field allows 8000 chars); the title only tops it up.
        try {
            $title = '';
            if (method_exists($entry, 'getTitle')) {
                $title = trim((string) $entry->getTitle());
            }
            if (method_exists($entry, 'getBody')) {
                $b = $entry->getBody();
                if (is_object($b) && method_exists($b, 'getClean')) {
                    $b = $b->getClean();
                }
                $text = trim(html_entity_decode(strip_tags((string) $b), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text !== '') {
                    if ($title !== '' && mb_stripos($text, $title) === false) {
                        $text = $title . "\n\n" . $text;
                    }
                    return mb_substr($text, 0, 8000);
                }
            }
            if ($title !== '') {
                return mb_substr($title, 0, 8000);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return 'Time logged from osTicket #' . $ostId;
    }

    private function staffEmail(int $staffId): string
    {
        try {
            if (class_exists('Staff') && ($s = \Staff::lookup($staffId)) && method_exists($s, 'getEmail')) {
                return (string) $s->getEmail();
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return '';
    }

    private function staffName(int $staffId): string
    {
        try {
            if (class_exists('Staff') && ($s = \Staff::lookup($staffId)) && method_exists($s, 'getName')) {
                return (string) $s->getName();
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return 'Staff #' . $staffId;
    }

    /* ------------------------------------------------------------------ */
    /* Validation                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Validate submitted vars; fill $errors with per-field messages.
     *
     * @param array $vars
     * @param array $errors  (by reference)
     * @return array Cleaned values.
     */
    public function validate(array $vars, array &$errors): array
    {
        $clean = array();

        // Resource (required, must be a known active resource).
        $resource = trim((string) ($vars['resource_id'] ?? ''));
        if ($resource === '' || !$this->picklists->isValid('resourceID', $resource)) {
            $errors['resource_id'] = 'Select a valid resource.';
        }
        $clean['resource_id'] = (int) $resource;

        // Work type / billing code (required).
        $work = trim((string) ($vars['billing_code_id'] ?? ''));
        if ($work === '' || !$this->picklists->isValid('billingCodeID', $work)) {
            $errors['billing_code_id'] = 'Select a valid work type.';
        }
        $clean['billing_code_id'] = (int) $work;

        // Role — Autotask requires a roleID on ticket time entries. Use the
        // selected role, else fall back to the configured default role.
        $role = trim((string) ($vars['role_id'] ?? ''));
        if ($role === '') {
            $role = (string) ($this->settings->defaultRoleId() ?: '');
        }
        if ($role !== '' && !$this->picklists->isValid('roleID', $role)) {
            $errors['role_id'] = 'Select a valid role.';
        }
        if ($role === '') {
            $errors['role_id'] = 'A role is required (select one or set a Default Role in config).';
        }
        $clean['role_id'] = $role !== '' ? (int) $role : null;

        // Date worked (default today).
        $date = trim((string) ($vars['date_worked'] ?? ''));
        if ($date === '' || !strtotime($date)) {
            $date = gmdate('Y-m-d');
        }
        $clean['date_worked'] = $date;

        // Hours: either hours_worked > 0, or start+end times.
        $hours = (float) ($vars['hours_worked'] ?? 0);
        $start = trim((string) ($vars['start_time'] ?? ''));
        $end   = trim((string) ($vars['end_time'] ?? ''));
        $clean['start_datetime'] = null;
        $clean['end_datetime']   = null;

        if ($start !== '' && $end !== '') {
            $s = strtotime("$date $start");
            $e = strtotime("$date $end");
            if (!$s || !$e || $e <= $s) {
                $errors['time'] = 'End time must be after start time.';
            } else {
                $hours = round(($e - $s) / 3600, 2);
                $clean['start_datetime'] = gmdate('Y-m-d H:i:s', $s);
                $clean['end_datetime']   = gmdate('Y-m-d H:i:s', $e);
            }
        }
        if ($hours <= 0) {
            $errors['hours_worked'] = 'Enter hours worked, or both start and end times.';
        }
        if ($hours > 24) {
            $errors['hours_worked'] = 'Hours worked cannot exceed 24.';
        }
        $clean['hours_worked'] = $hours;
        // If no explicit start datetime, store the date for dateWorked.
        if ($clean['start_datetime'] === null) {
            $clean['start_datetime'] = $date . ' 00:00:00';
        }

        // Summary notes (required by Autotask).
        $summary = trim((string) ($vars['summary_notes'] ?? ''));
        if ($summary === '') {
            $errors['summary_notes'] = 'Summary notes are required.';
        }
        $clean['summary_notes'] = mb_substr($summary, 0, 8000);

        $clean['internal_notes'] = mb_substr(trim((string) ($vars['internal_notes'] ?? '')), 0, 8000);
        $clean['billable'] = !empty($vars['billable']) ? 1 : 0;

        return $clean;
    }

    /* ------------------------------------------------------------------ */
    /* Persistence                                                        */
    /* ------------------------------------------------------------------ */

    private function insert(array $map, int $ostId, array $c, array $staff): int
    {
        $prefix = Installer::prefix();
        $mapId = (int) $map['id'];
        $atId  = (int) $map['autotask_ticket_id'];
        $res   = (int) $c['resource_id'];
        $start = db_input($c['start_datetime']);
        $end   = $c['end_datetime'] ? db_input($c['end_datetime']) : 'NULL';
        $hours = (float) $c['hours_worked'];
        $bc    = (int) $c['billing_code_id'];
        $role  = $c['role_id'] ? (int) $c['role_id'] : 'NULL';
        $bill  = (int) $c['billable'];
        $sum   = db_input($c['summary_notes']);
        $int   = db_input($c['internal_notes']);
        $by    = isset($staff['id']) ? (int) $staff['id'] : 'NULL';

        db_query(
            "INSERT INTO `{$prefix}autotask_time_entry` "
            . "(ticket_map_id, osticket_ticket_id, autotask_ticket_id, resource_id, start_datetime, end_datetime, "
            . "hours_worked, billing_code_id, role_id, billable, summary_notes, internal_notes, status, created_by, created, updated) "
            . "VALUES ($mapId, $ostId, $atId, $res, $start, $end, $hours, $bc, $role, $bill, $sum, $int, 'pending', $by, NOW(), NOW())"
        );
        return (int) db_insert_id();
    }

    private function getById(int $id): ?array
    {
        $prefix = Installer::prefix();
        $id = (int) $id;
        $res = db_query("SELECT * FROM `{$prefix}autotask_time_entry` WHERE id=$id LIMIT 1");
        return ($res && ($row = db_fetch_array($res))) ? $row : null;
    }

    private function updateStatus(int $id, string $status, ?int $atId, ?string $error): void
    {
        $prefix = Installer::prefix();
        $id = (int) $id;
        $st = db_input($status);
        $sets = array("status=$st", 'updated=NOW()');
        if ($atId !== null) {
            $sets[] = 'autotask_time_entry_id=' . (int) $atId;
        }
        $sets[] = 'last_error=' . ($error !== null ? db_input(substr($error, 0, 2000)) : 'NULL');
        db_query("UPDATE `{$prefix}autotask_time_entry` SET " . implode(',', $sets) . " WHERE id=$id");
    }

    private function isoDate(string $dt): string
    {
        $t = strtotime($dt) ?: time();
        return gmdate('Y-m-d\T00:00:00\Z', $t);
    }

    private function isoDateTime(string $dt): string
    {
        $t = strtotime($dt) ?: time();
        return gmdate('Y-m-d\TH:i:s\Z', $t);
    }
}
