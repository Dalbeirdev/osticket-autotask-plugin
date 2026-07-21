<?php
/**
 * Autotask Integration — synchronization engine.
 *
 * Bridges osTicket Signal events to the durable queue (outbound) and applies
 * Autotask changes back into osTicket (inbound). All public entry points are
 * exception-safe; failures are logged and, where appropriate, queued for retry.
 *
 * Loop prevention:
 *  - Outbound notes are tagged with a hidden marker; inbound import skips any
 *    note carrying that marker.
 *  - While applying inbound changes the $suppress flag short-circuits the
 *    model.updated handler so we never echo our own writes back to Autotask.
 *
 * @package Autotask Integration
 */

namespace Autotask;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Coordinates two-way ticket synchronization.
 */
class SyncEngine
{
    /** Hidden marker appended to notes we push, so we can ignore them on pull. */
    private const LOOP_MARKER = '[osticket-sync]';

    /** @var bool When true, inbound application suppresses outbound echo. */
    private static $suppress = false;

    /** @var Settings */    private $settings;
    /** @var Queue */       private $queue;
    /** @var Ticket */      private $mapper;
    /** @var AutotaskApi */ private $api;
    /** @var Logger */      private $logger;

    public function __construct(Settings $settings, Queue $queue, Ticket $mapper, AutotaskApi $api, Logger $logger)
    {
        $this->settings = $settings;
        $this->queue    = $queue;
        $this->mapper   = $mapper;
        $this->api      = $api;
        $this->logger   = $logger;
    }

    /* ================================================================== */
    /* Outbound: osTicket -> Autotask (enqueue only; fast, non-blocking)  */
    /* ================================================================== */

    /**
     * @param \Ticket $ticket
     */
    public function onOsticketTicketCreated($ticket): void
    {
        if (self::$suppress || !($ticket instanceof \Ticket)) {
            return;
        }
        $id = (int) $ticket->getId();
        $this->mapper->createMapping($id, null, $this->settings->twoWayEnabled() ? 'bidirectional' : 'to_autotask');
        $this->queue->enqueue('ticket', 'create', $id, array(), "ticket-create-$id");
        $this->logger->info("Queued Autotask ticket creation for osTicket #$id",
            array('category' => 'outbound', 'osticket_ticket_id' => $id));
    }

    /**
     * @param \ThreadEntry $entry
     */
    public function onOsticketThreadEntry($entry): void
    {
        if (self::$suppress || !is_object($entry)) {
            return;
        }
        // Resolve owning ticket.
        $ticketId = $this->threadEntryTicketId($entry);
        if (!$ticketId) {
            return;
        }
        $map = $this->mapper->findByOsticketId($ticketId);
        if (!$map) {
            return; // not a synced ticket
        }

        $class = get_class($entry);
        // Internal note vs. public reply.
        $isNote  = stripos($class, 'Note') !== false;
        $isReply = stripos($class, 'Response') !== false;
        $isMsg   = stripos($class, 'Message') !== false;

        // Skip the very first inbound user message (covered by ticket body).
        if ($isMsg) {
            return;
        }
        if (!$isNote && !$isReply) {
            return;
        }
        // SYSTEM noise (auto-assignment, alerts): no human author -> the
        // client's Autotask must not see osTicket housekeeping notes.
        try {
            $sysStaff = method_exists($entry, 'getStaffId') ? (int) $entry->getStaffId() : 0;
            $sysUser  = method_exists($entry, 'getUserId') ? (int) $entry->getUserId() : 0;
            $sysPost  = method_exists($entry, 'getPoster') ? trim((string) $entry->getPoster()) : '';
            if ($sysStaff <= 0 && $sysUser <= 0
                && ($sysPost === '' || stripos($sysPost, 'SYSTEM') !== false)) {
                return;
            }
        } catch (\Throwable $e) {
            // fall through — better a stray note than a lost human update
        }

        $body  = $this->entryBody($entry);
        if ($body === '' || strpos($body, self::LOOP_MARKER) !== false) {
            return; // empty or our own echoed content
        }
        // Identity masking: the client must see a normal human update, not an
        // integration artifact. Title = the staff member's name.
        $poster = '';
        $entryTitle = '';
        try {
            if (method_exists($entry, 'getPoster')) {
                $poster = trim((string) $entry->getPoster());
            }
            if (method_exists($entry, 'getTitle')) {
                $entryTitle = trim((string) $entry->getTitle());
            }
        } catch (\Throwable $e) {
            // ignore
        }
        // Field mapping: the osTicket note TITLE becomes the Autotask note
        // title (same field both sides); fall back to the staff name when the
        // optional title was left empty.
        $title = $entryTitle !== ''
            ? $entryTitle
            : ($poster !== '' ? $poster : ($isNote ? 'Internal Note' : 'Reply'));

        // Autotask-native behaviour (user policy): when this same submission
        // also carries inline time (Time Spent fields), the TIME ENTRY holds
        // the text as its summary — a separate note would duplicate the update.
        try {
            $f = $this->settings->timeFieldNames();
            $spent = $_POST[$f['spent']] ?? null;
            if ($this->settings->captureTimeEnabled() && is_numeric($spent) && (float) $spent > 0) {
                // Text rides the time entry — but the entry's FILES must still
                // reach Autotask: queue an attachments-only job when present.
                $hasFiles = false;
                try {
                    $hasFiles = method_exists($entry, 'getAttachments')
                        && count($entry->getAttachments()) > 0;
                } catch (\Throwable $e) {
                    // ignore
                }
                if ($hasFiles) {
                    $this->queue->enqueue($isNote ? 'note' : 'reply', 'append', $ticketId, array(
                        'attachments_only' => 1,
                        'entry_id' => method_exists($entry, 'getId') ? (int) $entry->getId() : 0,
                    ));
                }
                $this->logger->debug("Note suppressed for osTicket #$ticketId: text carried by the time entry",
                    array('category' => 'outbound', 'osticket_ticket_id' => $ticketId));
                return;
            }
        } catch (\Throwable $e) {
            // fall through — better a duplicate than a lost update
        }

        $payload = array(
            'title'    => $title,
            'body'     => $body,
            'publish'  => $isNote ? 2 : 1, // 2=internal, 1=all AT users
            'entry_id' => method_exists($entry, 'getId') ? (int) $entry->getId() : 0,
        );
        $this->queue->enqueue($isNote ? 'note' : 'reply', 'append', $ticketId, $payload);
        $this->logger->debug("Queued $title for osTicket #$ticketId",
            array('category' => 'outbound', 'osticket_ticket_id' => $ticketId));
    }

    /**
     * @param \Ticket $ticket
     * @param mixed   $data
     */
    public function onOsticketTicketUpdated($ticket, $data = null): void
    {
        if (self::$suppress || !($ticket instanceof \Ticket)) {
            return;
        }
        $id = (int) $ticket->getId();
        $map = $this->mapper->findByOsticketId($id);
        if (!$map) {
            return;
        }

        // Inspect dirty fields when available to avoid redundant updates.
        $dirty = array();
        if (is_array($data) && isset($data['dirty']) && is_array($data['dirty'])) {
            $dirty = $data['dirty'];
        }

        $touchesStatus   = !$dirty || isset($dirty['status_id']) || isset($dirty['isanswered']) || isset($dirty['closed']);
        $touchesPriority = !$dirty || isset($dirty['priority_id']);

        if ($touchesStatus) {
            // Collapse repeated status changes via dedupe key.
            $this->queue->enqueue('status', 'update', $id, array(), "status-$id");
        }
        if ($touchesPriority) {
            $this->queue->enqueue('priority', 'update', $id, array(), "priority-$id");
        }
    }

    /* ================================================================== */
    /* Queue worker: execute one job against the Autotask API             */
    /* ================================================================== */

    /**
     * @param array $job  Row from the queue (payload already decoded).
     * @throws ApiException on retryable/non-retryable failure.
     */
    public function processJob(array $job): void
    {
        $direction = (($job['direction'] ?? 'to_autotask') === 'to_osticket')
            ? 'to_osticket' : 'to_autotask';

        if ($direction === 'to_osticket') {
            $this->processInboundJob($job);
        } else {
            $this->processOutboundJob($job);
        }
    }

    /**
     * Apply an OUTBOUND job (osTicket -> Autotask).
     *
     * @param array $job
     */
    private function processOutboundJob(array $job): void
    {
        $type    = $job['entity_type'];
        $ostId   = (int) $job['osticket_ticket_id'];
        $payload = $job['payload'] ?? array();

        switch ($type) {
            case 'ticket':
                $this->processTicketCreate($ostId);
                break;
            case 'note':
            case 'reply':
                $this->processNoteAppend($ostId, $payload, $type);
                break;
            case 'status':
                $this->processStatusUpdate($ostId);
                break;
            case 'priority':
                $this->processPriorityUpdate($ostId);
                break;
            default:
                $this->logger->warning("Unknown outbound entity_type: $type");
        }
    }

    /**
     * Apply an INBOUND job (Autotask -> osTicket).
     *
     * @param array $job
     */
    private function processInboundJob(array $job): void
    {
        $type    = $job['entity_type'];
        $payload = $job['payload'] ?? array();

        switch ($type) {
            case 'ticket':
                $this->processTicketImport($payload);
                break;
            case 'note':
                $this->processNoteImport($payload);
                break;
            case 'status':
                $this->processStatusImport($payload);
                break;
            default:
                $this->logger->warning("Unknown inbound entity_type: $type");
        }
    }

    private function processTicketCreate(int $ostId): void
    {
        $map = $this->mapper->findByOsticketId($ostId);
        if (!$map) {
            return;
        }
        if (!empty($map['autotask_ticket_id'])) {
            return; // already created (idempotent)
        }
        $osTicket = \Ticket::lookup($ostId);
        if (!$osTicket) {
            throw new ApiException("osTicket #$ostId no longer exists", 404, false);
        }

        $fields = $this->mapper->buildAutotaskFields($osTicket, $this->api, $this->logger);
        $atId   = $this->api->createTicket($fields);

        // Fetch the human ticket number (T2026….NNNN) — create only returns the id.
        $atNumber = '';
        try {
            $created = $this->api->getTicket($atId);
            $atNumber = (string) ($created['ticketNumber'] ?? '');
        } catch (\Throwable $e) {
            // non-fatal; panel falls back to the id until next sync
        }

        $this->mapper->linkAutotaskTicket((int) $map['id'], $atId, $atNumber);
        $this->mapper->touch((int) $map['id'], 'osticket', $this->mapper->computeHash($osTicket));
        $this->recordHistory($map['id'], $ostId, $atId, 'to_autotask', 'ticket', 'success', "Created Autotask ticket #$atId");
        $this->logger->info("Created Autotask ticket #$atId from osTicket #$ostId",
            array('category' => 'outbound', 'osticket_ticket_id' => $ostId, 'autotask_ticket_id' => $atId));
    }

    private function processNoteAppend(int $ostId, array $payload, string $type): void
    {
        $atId = $this->requireAutotaskId($ostId);
        $map = $this->mapper->findByOsticketId($ostId);
        // attachments_only: the text already reached Autotask as a time-entry
        // summary; this job only carries the entry's files.
        if (empty($payload['attachments_only'])) {
            // No visible marker: loop prevention is ID-based via autotask_note_map,
            // so the client's Autotask shows a clean, human-looking note.
            $noteId = $this->api->createTicketNote(
                $atId,
                (string) ($payload['title'] ?? 'Update'),
                (string) ($payload['body'] ?? ''),
                1,
                (int) ($payload['publish'] ?? 1)
            );
            if ($noteId && $map) {
                $prefix = Installer::prefix();
                db_query("INSERT IGNORE INTO `{$prefix}autotask_note_map` "
                    . '(instance_id, ticket_map_id, autotask_note_id, osticket_entry_id, direction, created) VALUES ('
                    . (int) ($map['instance_id'] ?? 1) . ',' . (int) $map['id'] . ',' . (int) $noteId . ','
                    . (int) ($payload['entry_id'] ?? 0) . ", 'to_autotask', NOW())", false);
            }
            $this->recordHistory($map['id'] ?? null, $ostId, $atId, 'to_autotask', $type, 'success', 'Appended note');
        }

        // Module 8 (outbound attachments): upload the entry's files — including
        // inline pasted images — to the Autotask ticket. Best-effort per file;
        // Autotask caps attachment size (~6 MB), larger files are logged+skipped.
        // Per-client toggle (register form). Unset = ON (default behaviour).
        $sa = $this->settings->raw()->get('sync_attachments');
        if ($sa !== null && !(int) $sa) {
            return;
        }
        try {
            if (!empty($payload['entry_id']) && class_exists('ThreadEntry')) {
                $te = \ThreadEntry::lookup((int) $payload['entry_id']);
                if ($te && method_exists($te, 'getAttachments')) {
                    foreach ($te->getAttachments() as $a) {
                        $f = method_exists($a, 'getFile') ? $a->getFile() : null;
                        if (!$f) { continue; }
                        $name = method_exists($f, 'getName') ? (string) $f->getName() : 'attachment';
                        $data = method_exists($f, 'getData') ? $f->getData() : null;
                        if ($data === null || $data === '') { continue; }
                        if (strlen($data) > 6000000) {
                            $this->logger->warning("Attachment '$name' skipped (>6MB Autotask limit)",
                                array('category' => 'outbound', 'osticket_ticket_id' => $ostId));
                            continue;
                        }
                        $respU = $this->api->request('POST', "Tickets/$atId/Attachments", array(
                            'id'             => 0,
                            'attachmentType' => 'FILE_ATTACHMENT',
                            'fullPath'       => $name,
                            'title'          => mb_substr($name, 0, 200),
                            'publish'        => 1,
                            'data'           => base64_encode($data),
                        ));
                        // Echo protection: remember the created attachment id so
                        // inbound attachment sync never re-imports our own file.
                        $upId = (int) ($respU['itemId'] ?? 0);
                        if ($upId && $map) {
                            db_query('INSERT IGNORE INTO `' . Installer::prefix() . 'autotask_note_map` '
                                . '(instance_id, ticket_map_id, autotask_note_id, direction, note_type, created) VALUES ('
                                . (int) ($map['instance_id'] ?? 1) . ',' . (int) $map['id'] . ','
                                . $upId . ", 'to_autotask', 'attachment', NOW())", false);
                        }
                        $this->logger->info("Attachment '$name' uploaded to Autotask #$atId",
                            array('category' => 'outbound', 'osticket_ticket_id' => $ostId));
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Attachment sync failed: ' . $e->getMessage(),
                array('category' => 'outbound', 'osticket_ticket_id' => $ostId));
        }
    }

    private function processStatusUpdate(int $ostId): void
    {
        $atId = $this->requireAutotaskId($ostId);
        $osTicket = \Ticket::lookup($ostId);
        if (!$osTicket) {
            throw new ApiException("osTicket #$ostId not found", 404, false);
        }
        $closed = method_exists($osTicket, 'isClosed') ? $osTicket->isClosed() : false;

        // Single-dropdown design: translate the osTicket STATUS NAME through the
        // per-client status map first; fall back to the legacy open/closed rule.
        $atStatus = null;
        $statusName = $this->osticketStatusName($osTicket);
        $map0 = $this->settings->statusMap();
        if ($statusName !== '' && isset($map0[mb_strtolower($statusName)])) {
            $atStatus = $map0[mb_strtolower($statusName)];
        }
        $map = $this->mapper->findByOsticketId($ostId);
        // Name parity outbound: an osTicket status named exactly like one of
        // this tenant's Autotask statuses resolves automatically by label.
        if ($atStatus === null && $statusName !== '') {
            $prefix2 = Installer::prefix();
            $r0 = db_query("SELECT value FROM `{$prefix2}autotask_picklist_cache` "
                . 'WHERE instance_id=' . (int) ($map['instance_id'] ?? 1)
                . " AND entity='Tickets' AND field='status' AND LOWER(label)="
                . db_input(mb_strtolower(trim($statusName))) . ' AND is_active=1 LIMIT 1', false);
            if ($r0 && ($x0 = db_fetch_array($r0))) {
                $atStatus = (int) $x0['value'];
            }
        }
        if ($atStatus === null) {
            $atStatus = $closed
                ? $this->settings->completeStatus()
                : ($this->settings->defaults()['status'] ?: 1);
        }

        // CONFLICT RULE (user policy): a closure made on the AUTOTASK side has
        // priority. If Autotask completed the ticket and osTicket now tries to
        // push a non-complete status (e.g. an accidental reopen), skip it —
        // reopening must happen in Autotask.
        if (($map['last_updated_by'] ?? '') === 'autotask'
            && (string) ($map['autotask_status'] ?? '') === (string) $this->settings->completeStatus()
            && $atStatus !== $this->settings->completeStatus()) {
            $this->logger->warning("Status push skipped for osTicket #$ostId: Autotask-side closure has priority (reopen in Autotask).",
                array('category' => 'conflict', 'osticket_ticket_id' => $ostId, 'autotask_ticket_id' => $atId));
            return;
        }

        $fields = array('status' => $atStatus);

        // Native-close flow: when this change COMPLETES the Autotask ticket,
        // fill its resolution automatically (Autotask expects one on complete)
        // and advise — not block — on the require-time-before-close rule.
        $completing = ($atStatus === $this->settings->completeStatus())
            && (string) ($map['autotask_status'] ?? '') !== (string) $atStatus;
        if ($completing) {
            $fields['resolution'] = $this->buildResolution($osTicket);
            if ($this->settings->requireTimeBeforeClose() && !$this->ticketHasTimeEntry($ostId)) {
                $this->logger->warning(
                    "osTicket #$ostId completed WITHOUT a time entry (require-time rule is advisory on native close)",
                    array('category' => 'closure', 'osticket_ticket_id' => $ostId, 'autotask_ticket_id' => $atId)
                );
            }
        }
        $this->api->updateTicket($atId, $fields);
        $this->mapper->setAutotaskStatus((int) $map['id'], (string) $atStatus);
        $this->mapper->touch((int) $map['id'], 'osticket', $this->mapper->computeHash($osTicket));
        $this->recordHistory($map['id'] ?? null, $ostId, $atId, 'to_autotask', 'status', 'success',
            'Status ' . ($statusName !== '' ? "'$statusName'" : ($closed ? 'closed' : 'open')) . " -> AT $atStatus");
    }

    private function processPriorityUpdate(int $ostId): void
    {
        $atId = $this->requireAutotaskId($ostId);
        $osTicket = \Ticket::lookup($ostId);
        if (!$osTicket) {
            throw new ApiException("osTicket #$ostId not found", 404, false);
        }
        $priority = $this->mapper->mapPriority($osTicket);
        $this->api->updateTicket($atId, array('priority' => $priority));
        $map = $this->mapper->findByOsticketId($ostId);
        $this->recordHistory($map['id'] ?? null, $ostId, $atId, 'to_autotask', 'priority', 'success',
            "Priority -> $priority");
    }

    /**
     * Resolve the Autotask ticket id for an osTicket, requeueing (retryable) if
     * the ticket-create job has not completed yet.
     */
    private function requireAutotaskId(int $ostId): int
    {
        $map = $this->mapper->findByOsticketId($ostId);
        if (!$map || empty($map['autotask_ticket_id'])) {
            throw new ApiException(
                "Autotask ticket not yet created for osTicket #$ostId; will retry.",
                0, true
            );
        }
        return (int) $map['autotask_ticket_id'];
    }

    /* ================================================================== */
    /* Inbound: Autotask -> osTicket (scheduled)                          */
    /* ================================================================== */

    /**
     * PRODUCER: discover Autotask tickets modified since the cursor and ENQUEUE
     * inbound status + note import jobs for the ones we already map. Applying the
     * changes is done by the queue worker (processInboundJob).
     *
     * @return int Number of Autotask tickets examined.
     */
    public function inboundSync(): int
    {
        $twoWay     = $this->settings->twoWayEnabled();
        $autoImport = $this->settings->autoImportEnabled();
        if (!$twoWay && !$autoImport) {
            return 0;
        }
        $cursor = $this->settings->state('inbound_cursor_utc', gmdate('Y-m-d\TH:i:s\Z', time() - 3600));
        $batch  = $this->settings->batchSize();

        $tickets = $this->api->getTicketsModifiedSince($cursor, $batch);
        $maxSeen = $cursor;
        $queued  = 0;

        foreach ($tickets as $at) {
            if (empty($at['id'])) {
                continue;
            }
            $map = $this->mapper->findByAutotaskId((int) $at['id']);
            if ($map && $twoWay) {
                $ostId = (int) $map['osticket_ticket_id'];
                // Status import (one collapsed job per ticket).
                $this->queue->enqueue(
                    'status', 'import', $ostId,
                    array('autotask_ticket_id' => (int) $at['id']),
                    'import-status-' . (int) $at['id'],
                    'to_osticket'
                );
                $queued += 1;

                // Inbound note import (Autotask note -> osTicket).
                if ($this->settings->inboundNotesEnabled()) {
                    $this->queue->enqueue(
                        'note', 'import', $ostId,
                        array(
                            'autotask_ticket_id' => (int) $at['id'],
                            'lastactivity'       => $at['lastActivityDate'] ?? null,
                        ),
                        'import-notes-' . (int) $at['id'],
                        'to_osticket'
                    );
                    $queued += 1;
                }
            } elseif (!$map && $autoImport && $this->matchesImportFilter($at)) {
                // Auto-import: a new/unmapped ticket matching the import filters.
                $this->queue->enqueue(
                    'ticket', 'import', null,
                    array('autotask' => $at),
                    'import-ticket-' . (int) $at['id'],
                    'to_osticket'
                );
                $queued += 1;
            }
            if (!empty($at['lastActivityDate']) && $at['lastActivityDate'] > $maxSeen) {
                $maxSeen = $at['lastActivityDate'];
            }
        }

        // Advance the discovery cursor.
        $this->settings->setState('inbound_cursor_utc', $maxSeen);
        $this->settings->setState('last_inbound_sync', gmdate('c'));
        $this->logger->info('Inbound producer: examined ' . count($tickets) . ", queued $queued job(s)",
            array('category' => 'inbound'));
        return count($tickets);
    }

    /**
     * Client-side check of an Autotask ticket against the import filter spec
     * (used for auto-import, since getTicketsModifiedSince doesn't apply filters).
     *
     * @param array $at
     * @return bool
     */
    private function matchesImportFilter(array $at): bool
    {
        $spec = $this->settings->importFilterSpec();
        if (!empty($spec['status_op']) && isset($at['status'])) {
            $s = (int) $at['status'];
            $v = $spec['status_value'];
            if ($spec['status_op'] === 'noteq' && $s === (int) $v) { return false; }
            if ($spec['status_op'] === 'eq' && $s !== (int) $v) { return false; }
            if ($spec['status_op'] === 'in' && is_array($v)
                && !in_array($s, array_map('intval', $v), true)) { return false; }
        }
        if (!empty($spec['company_ids']) && isset($at['companyID'])
            && !in_array((int) $at['companyID'], $spec['company_ids'], true)) { return false; }
        if (!empty($spec['queue_ids']) && isset($at['queueID'])
            && !in_array((int) $at['queueID'], $spec['queue_ids'], true)) { return false; }
        if (!empty($spec['resource_ids']) && isset($at['assignedResourceID'])
            && !in_array((int) $at['assignedResourceID'], $spec['resource_ids'], true)) { return false; }
        return true;
    }

    /**
     * PRODUCER: fetch OPEN Autotask tickets not yet mapped and ENQUEUE a ticket
     * import job for each. The queue worker (processTicketImport) creates the
     * osTicket tickets. Already-mapped tickets are skipped (duplicate prevention).
     *
     * @param int $limit Max tickets to enqueue this run.
     * @return array{fetched:int,queued:int,skipped:int}
     */
    public function importFromAutotask(int $limit): array
    {
        // Use the admin-defined import filters (status/company/queue/resource/date).
        $tickets = $this->api->queryTicketsForImport($this->settings->importFilterSpec(), $limit);
        $queued  = 0;
        $skipped = 0;

        foreach ($tickets as $at) {
            if (empty($at['id'])) {
                continue;
            }
            // Duplicate prevention: skip ones we already track.
            if ($this->mapper->findByAutotaskId((int) $at['id'])) {
                $skipped++;
                continue;
            }
            // dedupe_key collapses repeated import requests for the same ticket.
            $this->queue->enqueue(
                'ticket', 'import', null,
                array('autotask' => $at),
                'import-ticket-' . (int) $at['id'],
                'to_osticket'
            );
            $queued++;
        }

        $this->settings->setState('last_import', gmdate('c'));
        $this->logger->info(
            sprintf('Import producer: fetched %d, queued %d, skipped %d', count($tickets), $queued, $skipped),
            array('category' => 'inbound')
        );
        return array('fetched' => count($tickets), 'queued' => $queued, 'skipped' => $skipped);
    }

    /**
     * Import ONE Autotask ticket by its id (any status), creating + mapping it.
     *
     * @param int $atId
     * @return array{ok:bool,osticket_id:?int,message:string}
     */
    public function importSingle(int $atId): array
    {
        if ($this->mapper->findByAutotaskId($atId)) {
            return array('ok' => true, 'osticket_id' => null, 'message' => 'Already imported/mapped.');
        }
        $at = $this->api->getTicket($atId);
        if (!$at || empty($at['id'])) {
            return array('ok' => false, 'osticket_id' => null, 'message' => 'Autotask ticket not found.');
        }
        try {
            $ostId = $this->createOsticketFromAutotask($at);
            return array('ok' => (bool) $ostId, 'osticket_id' => $ostId,
                'message' => $ostId ? 'Imported as osTicket #' . $ostId : 'Create failed.');
        } catch (\Throwable $e) {
            return array('ok' => false, 'osticket_id' => null, 'message' => $e->getMessage());
        }
    }

    /**
     * Create a single osTicket ticket from an Autotask ticket entity and map it.
     * Runs under the suppress flag so the resulting ticket.created signal does
     * not echo a brand-new ticket back out to Autotask.
     *
     * @param array $at Autotask ticket entity.
     * @return int|null New osTicket ticket id, or null on failure.
     */
    private function createOsticketFromAutotask(array $at): ?int
    {
        if (!class_exists('Ticket')) {
            throw new ApiException('osTicket Ticket class unavailable.');
        }

        // Resolve requester name/email from the Autotask contact when possible.
        $number = (string) ($at['ticketNumber'] ?? $at['id']);
        $name   = 'Autotask Ticket ' . $number;
        $email  = '';
        if (!empty($at['contactID'])) {
            try {
                $contact = $this->api->getContact((int) $at['contactID']);
                if ($contact) {
                    $cn = trim((string) ($contact['firstName'] ?? '') . ' ' . (string) ($contact['lastName'] ?? ''));
                    if ($cn !== '') {
                        $name = $cn;
                    }
                    if (!empty($contact['emailAddress'])) {
                        $email = (string) $contact['emailAddress'];
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Contact lookup failed during import: ' . $e->getMessage(),
                    array('category' => 'inbound', 'autotask_ticket_id' => (int) $at['id']));
            }
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Synthetic but format-valid address so osTicket accepts the ticket.
            $email = 'autotask-' . (int) $at['id'] . '@import.local';
        }

        $subject = (string) ($at['title'] ?? ('Autotask Ticket #' . $at['id']));
        $message = (string) ($at['description'] ?? '');
        if (trim($message) === '') {
            $message = $subject;
        }
        // No import trailer/marker: dedupe is ID-based via autotask_note_map,
        // and the thread must read like a native conversation.

        // Resolve (or create) the osTicket user up-front and pass its uid. This
        // bypasses Ticket::create()'s UserForm validation path, which rejects
        // our minimal vars with "Incomplete client information".
        $uid = 0;
        if (class_exists('User')) {
            try {
                $u = \User::fromVars(array('name' => $name, 'email' => $email), true);
                if ($u) {
                    $uid = (int) $u->getId();
                }
            } catch (\Throwable $e) {
                $this->logger->warning('User resolve failed during import: ' . $e->getMessage(),
                    array('category' => 'inbound', 'autotask_ticket_id' => (int) $at['id']));
            }
        }

        // Client identity: attach the imported user to the client's osTicket
        // Organization (auto-created from the client name) so queues can show
        // an Organization column answering "whose ticket is this".
        if ($uid) {
            $this->attachClientOrganization($uid);
        }

        $vars = array(
            'source'      => 'API',
            'name'        => mb_substr($name, 0, 128),
            'email'       => $email,
            'subject'     => mb_substr($subject, 0, 250),
            'message'     => $message,
            'ip'          => '',
            'autorespond' => false,
        );
        if ($uid) {
            $vars['uid'] = $uid;
        }
        // Routing: queue rules first (Autotask queue -> chosen department),
        // then the client's fallback department — never user/system default
        // when the client configured routing.
        $deptId = $this->settings->departmentForQueue((int) ($at['queueID'] ?? 0));
        if ($deptId > 0) {
            $vars['deptId'] = $deptId;
        }

        $errors = array();
        self::$suppress = true;
        try {
            // Ticket::create($vars, &$errors, $origin, $autorespond, $alert)
            $ticket = \Ticket::create($vars, $errors, 'API', false, false);
        } finally {
            self::$suppress = false;
        }

        if (!$ticket) {
            throw new ApiException('osTicket Ticket::create failed: '
                . implode('; ', array_map('strval', (array) $errors)));
        }
        $ostId = (int) $ticket->getId();

        // Date parity: keep the ORIGINAL Autotask creation time, so imported
        // tickets sort chronologically instead of clustering at import time.
        // FROM_UNIXTIME renders in the DB session tz, matching NOW() stamps.
        if (!empty($at['createDate'])) {
            $ts = $this->atEpoch((string) $at['createDate']);
            if ($ts) {
                db_query('UPDATE ' . TABLE_PREFIX
                    . "ticket SET created=FROM_UNIXTIME($ts) WHERE ticket_id=$ostId", false);
                // ...and on the initial MESSAGE thread entry, so the thread
                // itself opens at the real time the customer wrote in.
                db_query('UPDATE ' . TABLE_PREFIX . 'thread_entry e JOIN ' . TABLE_PREFIX
                    . "thread th ON e.thread_id=th.id SET e.created=FROM_UNIXTIME($ts) "
                    . "WHERE th.object_id=$ostId AND th.object_type='T' AND e.type='M'", false);
            }
        }
        // Due-date parity FROM IMPORT: the Autotask due date is the truth —
        // never osTicket's own SLA arithmetic (created + SLA period).
        if (!empty($at['dueDateTime'])) {
            $dueTs = $this->atEpoch((string) $at['dueDateTime']);
            if ($dueTs) {
                db_query('UPDATE ' . TABLE_PREFIX
                    . "ticket SET duedate=FROM_UNIXTIME($dueTs) WHERE ticket_id=$ostId", false);
            }
        }

        $mapId = $this->mapper->createMapping($ostId, (int) $at['id'], 'bidirectional');
        $this->mapper->linkAutotaskTicket($mapId, (int) $at['id'], $number);
        if (isset($at['status'])) {
            $this->mapper->setAutotaskStatus($mapId, (string) $at['status']);
            // Mirror the Autotask status IMMEDIATELY on import (reverse status
            // map) — an "In Progress" ticket must not arrive as plain Open.
            self::$suppress = true;
            try {
                $this->applyInboundStatus($ticket, $at);
                $this->applyInboundPriority($ticket, $at);
            } catch (\Throwable $e) {
                // non-fatal; ticket stays in its created state
            } finally {
                self::$suppress = false;
            }
        }
        // Files/notes/time entries present at import time must arrive WITH the
        // ticket, not one tick later: scan the thread once right away, from
        // the ticket's creation moment (ID-dedupe makes a later rescan safe).
        try {
            $mrow = $this->mapper->findByAutotaskId((int) $at['id']);
            if ($mrow) {
                $mrow['autotask_lastactivity'] = (string) ($at['createDate'] ?? '');
                self::$suppress = true;
                try {
                    $this->applyInboundNotes($ticket, (int) $at['id'], $mrow);
                } finally {
                    self::$suppress = false;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Initial thread scan failed: ' . $e->getMessage(),
                array('category' => 'inbound', 'osticket_ticket_id' => $ostId));
        }
        $this->mapper->touch($mapId, 'autotask', null, $at['lastActivityDate'] ?? null);

        $this->recordHistory($mapId, $ostId, (int) $at['id'], 'to_osticket', 'ticket', 'success',
            'Imported Autotask ticket #' . $number);
        $this->logger->info("Imported Autotask #{$at['id']} as osTicket #$ostId",
            array('category' => 'inbound', 'osticket_ticket_id' => $ostId, 'autotask_ticket_id' => (int) $at['id']));

        return $ostId;
    }

    /**
     * WORKER: create an osTicket ticket from a queued Autotask ticket payload.
     *
     * @param array $payload {autotask: <AT ticket entity>}
     */
    private function processTicketImport(array $payload): void
    {
        $at = $payload['autotask'] ?? null;
        if (!is_array($at) || empty($at['id'])) {
            throw new ApiException('Inbound ticket-import payload missing Autotask data.');
        }
        // Idempotent: another job may have created it already.
        if ($this->mapper->findByAutotaskId((int) $at['id'])) {
            return;
        }
        // createOsticketFromAutotask manages its own suppress window.
        $this->createOsticketFromAutotask($at);
    }

    /**
     * WORKER: apply Autotask status/closure to the mapped osTicket ticket.
     * Re-fetches the ticket for the current status, and skips if the change is
     * already accounted for (timestamp-based duplicate prevention).
     *
     * @param array $payload {autotask_ticket_id: int}
     */
    private function processStatusImport(array $payload): void
    {
        $atId = (int) ($payload['autotask_ticket_id'] ?? 0);
        if (!$atId) {
            return;
        }
        $map = $this->mapper->findByAutotaskId($atId);
        if (!$map) {
            return;
        }
        $osTicket = \Ticket::lookup((int) $map['osticket_ticket_id']);
        if (!$osTicket) {
            return;
        }
        // RACE GUARD: if an osTicket-side status change is still queued/being
        // pushed for this ticket, skip this inbound apply — otherwise stale
        // Autotask data would overwrite (e.g. reopen) the local change before
        // it reaches Autotask. The next cycle re-aligns both sides.
        $pfx = Installer::prefix();
        $rq = db_query("SELECT 1 FROM `{$pfx}autotask_sync_queue` "
            . 'WHERE osticket_ticket_id=' . (int) $map['osticket_ticket_id']
            . " AND entity_type='status' AND direction='to_autotask' "
            . "AND status IN ('pending','failed','processing') LIMIT 1", false);
        if ($rq && db_num_rows($rq)) {
            $this->logger->debug('Inbound status skipped for osTicket #' . $map['osticket_ticket_id']
                . ': outbound status change still in flight',
                array('category' => 'inbound', 'osticket_ticket_id' => (int) $map['osticket_ticket_id']));
            return;
        }

        $at = $this->api->getTicket($atId);
        if (!$at) {
            return;
        }
        // The ticket changed, so the panel's 24h detail cache (queue,
        // assigned resource, contract...) is stale — drop it and let the
        // next ticket view rebuild it.
        $this->settings->setState('at_tctx_' . $atId, null);

        self::$suppress = true;
        try {
            $this->applyInboundStatus($osTicket, $at);
            $this->applyInboundPriority($osTicket, $at);
            // Field parity: mirror the Autotask due date onto the osTicket
            // ticket (direct column update; no signals fired).
            if (!empty($at['dueDateTime'])) {
                $due = $this->atEpoch((string) $at['dueDateTime']);
                if ($due) {
                    // FROM_UNIXTIME renders in the DB session tz, matching NOW().
                    db_query('UPDATE ' . TABLE_PREFIX . "ticket SET duedate=FROM_UNIXTIME($due)"
                        . ' WHERE ticket_id=' . (int) $map['osticket_ticket_id'], false);
                }
            }
            if (isset($at['status'])) {
                $this->mapper->setAutotaskStatus((int) $map['id'], (string) $at['status']);
            }
            // Touch last_sync_time only (do NOT advance the note cursor here).
            $this->mapper->touch((int) $map['id'], 'autotask');
        } finally {
            self::$suppress = false;
        }
    }

    /**
     * WORKER: import new Autotask notes into the mapped osTicket ticket, then
     * advance the per-ticket note cursor (autotask_lastactivity).
     *
     * @param array $payload {autotask_ticket_id: int, lastactivity: ?string}
     */
    private function processNoteImport(array $payload): void
    {
        $atId = (int) ($payload['autotask_ticket_id'] ?? 0);
        if (!$atId) {
            return;
        }
        $map = $this->mapper->findByAutotaskId($atId);
        if (!$map) {
            return;
        }
        $osTicket = \Ticket::lookup((int) $map['osticket_ticket_id']);
        if (!$osTicket) {
            return;
        }

        self::$suppress = true;
        try {
            $this->applyInboundNotes($osTicket, $atId, $map);
            // Advance the note cursor so we don't re-scan the same window.
            $this->mapper->touch((int) $map['id'], 'autotask', null,
                $payload['lastactivity'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        } finally {
            self::$suppress = false;
        }
    }

    /**
     * @param \Ticket $osTicket
     * @param array   $at
     */
    private function applyInboundStatus(\Ticket $osTicket, array $at): void
    {
        if (!isset($at['status'])) {
            return;
        }
        $atStatus = (int) $at['status'];

        // Single-dropdown design: reverse-translate through the per-client map
        // and set the exact osTicket status when one is defined for this value.
        $reverse = $this->settings->statusMapReverse();
        if (isset($reverse[$atStatus])) {
            try {
                $this->setOsticketStatusByName($osTicket, $reverse[$atStatus]);
                return;
            } catch (\Throwable $e) {
                $this->logger->warning('Mapped status apply failed: ' . $e->getMessage(),
                    array('category' => 'inbound', 'osticket_ticket_id' => $osTicket->getId()));
                // fall through to the legacy open/closed rule
            }
        }

        // FULL NAME PARITY (user policy): no explicit map line -> mirror the
        // exact Autotask status NAME, creating the osTicket status on demand
        // (state 'closed' when it is the tenant's Complete value, else 'open').
        try {
            $mapRow = $this->mapper->findByOsticketId((int) $osTicket->getId());
            $iid = (int) ($mapRow['instance_id'] ?? 1);
            $prefix = Installer::prefix();
            $r = db_query("SELECT label FROM `{$prefix}autotask_picklist_cache` "
                . "WHERE instance_id=$iid AND entity='Tickets' AND field='status' AND value="
                . db_input((string) $atStatus) . ' LIMIT 1', false);
            if ($r && ($x = db_fetch_array($r)) && trim((string) $x['label']) !== '') {
                $label = trim((string) $x['label']);
                $state = ($atStatus === $this->settings->completeStatus()) ? 'closed' : 'open';
                db_query('INSERT INTO ' . TABLE_PREFIX . 'ticket_status (name, state, mode, flags, sort, properties, created, updated) '
                    . 'SELECT ' . db_input(mb_substr($label, 0, 60)) . ',' . db_input($state)
                    . ",1,0,0,'{\"description\":\"Created by Autotask integration\"}',NOW(),NOW() FROM DUAL "
                    . 'WHERE NOT EXISTS (SELECT 1 FROM ' . TABLE_PREFIX . 'ticket_status WHERE LOWER(name)='
                    . db_input(mb_strtolower($label)) . ')', false);
                $this->setOsticketStatusByName($osTicket, $label);
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Status name-parity apply failed: ' . $e->getMessage(),
                array('category' => 'inbound', 'osticket_ticket_id' => $osTicket->getId()));
        }

        $atComplete = ($atStatus === $this->settings->completeStatus());
        $osClosed   = method_exists($osTicket, 'isClosed') ? $osTicket->isClosed() : false;

        try {
            if ($atComplete && !$osClosed) {
                $this->setOsticketState($osTicket, 'closed');
                $this->logger->info('Closed osTicket #' . $osTicket->getId() . ' from Autotask',
                    array('category' => 'inbound', 'osticket_ticket_id' => $osTicket->getId()));
            } elseif (!$atComplete && $osClosed) {
                $this->setOsticketState($osTicket, 'open');
                $this->logger->info('Reopened osTicket #' . $osTicket->getId() . ' from Autotask',
                    array('category' => 'inbound', 'osticket_ticket_id' => $osTicket->getId()));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Status apply failed: ' . $e->getMessage(),
                array('category' => 'inbound', 'osticket_ticket_id' => $osTicket->getId()));
        }
    }

    /** @var array<int, array{name:string, email:string}|null> per-run contact cache */
    private $contactCache = array();

    /**
     * Real author name for an Autotask note/time entry: the resource label
     * from the picklist cache, else the contact's name, else 'Autotask'.
     *
     * @param array $row Autotask TicketNote or TimeEntry entity.
     */
    private function atAuthorName(array $row, int $instanceId): string
    {
        $rid = (int) ($row['creatorResourceID'] ?? $row['resourceID'] ?? 0);
        if ($rid) {
            $p = Installer::prefix();
            $r = db_query("SELECT label FROM `{$p}autotask_picklist_cache` "
                . "WHERE instance_id=$instanceId AND field='resourceID' AND value="
                . db_input((string) $rid) . ' LIMIT 1', false);
            if ($r && ($x = db_fetch_array($r)) && trim((string) $x['label']) !== '') {
                return trim((string) $x['label']);
            }
        }
        $cid = (int) ($row['createdByContactID'] ?? 0);
        if ($cid && ($c = $this->contactInfo($cid)) && $c['name'] !== '') {
            return $c['name'];
        }
        return 'Autotask';
    }

    /** @return array{name:string, email:string}|null */
    private function contactInfo(int $contactId): ?array
    {
        if (array_key_exists($contactId, $this->contactCache)) {
            return $this->contactCache[$contactId];
        }
        $out = null;
        try {
            if ($ct = $this->api->getContact($contactId)) {
                $out = array(
                    'name'  => trim((string) ($ct['firstName'] ?? '') . ' ' . (string) ($ct['lastName'] ?? '')),
                    'email' => trim((string) ($ct['emailAddress'] ?? '')),
                );
            }
        } catch (\Throwable $e) {
            // non-fatal: author falls back to defaults
        }
        return $this->contactCache[$contactId] = $out;
    }

    /**
     * osTicket user id for an Autotask contact (created on demand), so a
     * contact's public note lands as a message from that actual person.
     */
    private function osUserIdForContact(int $contactId): int
    {
        $c = $contactId ? $this->contactInfo($contactId) : null;
        if (!$c || $c['email'] === '' || !filter_var($c['email'], FILTER_VALIDATE_EMAIL)
            || !class_exists('User')) {
            return 0;
        }
        try {
            $u = \User::fromVars(array('name' => ($c['name'] ?: $c['email']), 'email' => $c['email']), true);
            return $u ? (int) $u->getId() : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Date parity: rewrite a freshly imported thread entry's created time to
     * the original Autotask timestamp so the thread reads chronologically.
     *
     * @param mixed  $entry ThreadEntry (or null/false when posting failed).
     * @param string $when  Autotask ISO timestamp; empty string = leave as-is.
     */
    private function backdateThreadEntry($entry, string $when): void
    {
        if (!$entry || !is_object($entry) || !method_exists($entry, 'getId') || $when === '') {
            return;
        }
        $ts = $this->atEpoch($when);
        if (!$ts) {
            return;
        }
        // FROM_UNIXTIME renders in the DB session timezone — the same
        // convention as the NOW() stamps on natively posted entries.
        db_query('UPDATE ' . TABLE_PREFIX . "thread_entry SET created=FROM_UNIXTIME($ts)"
            . ' WHERE id=' . (int) $entry->getId(), false);
    }

    /**
     * Autotask ISO timestamp -> unix epoch. Zone-less strings are UTC (the
     * REST API convention); an explicit Z/offset in the string always wins.
     */
    private function atEpoch(string $when): int
    {
        try {
            $d = new \DateTime($when, new \DateTimeZone('UTC'));
            return $d->getTimestamp();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Inbound priority parity: mirror the Autotask priority NAME onto the
     * osTicket priority (synonym map bridges the vocabularies:
     * Medium->Normal, Critical->Emergency, Information->Low).
     *
     * @param \Ticket $osTicket
     * @param array   $at Autotask ticket entity.
     */
    private function applyInboundPriority(\Ticket $osTicket, array $at): void
    {
        if (!isset($at['priority'])) {
            return;
        }
        try {
            $mapRow = $this->mapper->findByOsticketId((int) $osTicket->getId());
            $iid = (int) ($mapRow['instance_id'] ?? 1);
            $prefix = Installer::prefix();
            $r = db_query("SELECT label FROM `{$prefix}autotask_picklist_cache` "
                . "WHERE instance_id=$iid AND field='priority' AND value="
                . db_input((string) (int) $at['priority']) . ' LIMIT 1', false);
            $label = ($r && ($x = db_fetch_array($r))) ? mb_strtolower(trim((string) $x['label'])) : '';
            if ($label === '') {
                return;
            }
            $syn = array('medium' => 'normal', 'critical' => 'emergency',
                'urgent' => 'high', 'information' => 'low');
            $want = $syn[$label] ?? $label;
            $r = db_query('SELECT priority_id, priority_desc FROM ' . TABLE_PREFIX
                . 'ticket_priority WHERE LOWER(priority_desc)=' . db_input($want)
                . ' OR LOWER(priority)=' . db_input($want) . ' LIMIT 1', false);
            if (!$r || !($p = db_fetch_array($r))) {
                return;
            }
            $pid = (int) $p['priority_id'];
            $tid = (int) $osTicket->getId();
            // Already current? (cdata mirrors value_id)
            $r = db_query('SELECT priority FROM ' . TABLE_PREFIX
                . "ticket__cdata WHERE ticket_id=$tid", false);
            if ($r && ($cur = db_fetch_array($r)) && (int) $cur['priority'] === $pid) {
                return;
            }
            // Priority lives in the ticket's dynamic form entry.
            $r = db_query('SELECT v.entry_id, v.field_id FROM ' . TABLE_PREFIX . 'form_entry_values v JOIN '
                . TABLE_PREFIX . 'form_field f ON f.id=v.field_id JOIN ' . TABLE_PREFIX
                . "form_entry e ON e.id=v.entry_id WHERE f.name='priority' AND e.object_type='T' "
                . "AND e.object_id=$tid LIMIT 1", false);
            if ($r && ($fv = db_fetch_array($r))) {
                db_query('UPDATE ' . TABLE_PREFIX . 'form_entry_values SET value='
                    . db_input((string) $p['priority_desc']) . ", value_id=$pid WHERE entry_id="
                    . (int) $fv['entry_id'] . ' AND field_id=' . (int) $fv['field_id'], false);
            } else {
                // Ticket has no priority row yet (import-created): add one.
                $r2 = db_query('SELECT e.id eid, f.id fid FROM ' . TABLE_PREFIX . 'form_entry e JOIN '
                    . TABLE_PREFIX . 'form_field f ON f.form_id=e.form_id '
                    . "WHERE e.object_type='T' AND e.object_id=$tid AND f.name='priority' LIMIT 1", false);
                if ($r2 && ($fe = db_fetch_array($r2))) {
                    db_query('INSERT INTO ' . TABLE_PREFIX . 'form_entry_values (entry_id, field_id, value, value_id) VALUES ('
                        . (int) $fe['eid'] . ',' . (int) $fe['fid'] . ','
                        . db_input((string) $p['priority_desc']) . ",$pid)", false);
                }
            }
            db_query('UPDATE ' . TABLE_PREFIX . "ticket__cdata SET priority=$pid WHERE ticket_id=$tid", false);
            $this->logger->info('Priority mirrored from Autotask (' . $label . ' -> ' . $p['priority_desc'] . ')',
                array('category' => 'inbound', 'osticket_ticket_id' => $tid));
        } catch (\Throwable $e) {
            $this->logger->warning('Priority apply failed: ' . $e->getMessage(),
                array('category' => 'inbound', 'osticket_ticket_id' => (int) $osTicket->getId()));
        }
    }

    /**
     * Reflect Autotask-side EDITS of already-imported time entries onto the
     * local time-entry table (panel popup + totals). The thread entry keeps
     * the original record — billing history is never rewritten.
     *
     * @param int   $teId Autotask time entry id.
     * @param array $te   Current Autotask entity.
     */
    private function refreshLocalTimeEntry(int $teId, array $te, ?array $map = null, int $atTicketId = 0): void
    {
        $hours = (float) ($te['hoursWorked'] ?? 0);
        $bill  = empty($te['isNonBillable']) ? 1 : 0;
        $sEp = $this->atEpoch((string) ($te['startDateTime'] ?? $te['dateWorked'] ?? ''));
        $eEp = $this->atEpoch((string) ($te['endDateTime'] ?? ''));
        $p = Installer::prefix();

        // Self-heal: entries imported before the panel table existed have a
        // dedupe marker but no local row, so they never reach the popup.
        if ($map) {
            $r = db_query("SELECT 1 FROM `{$p}autotask_time_entry` "
                . 'WHERE autotask_time_entry_id=' . $teId . ' LIMIT 1', false);
            if (!$r || !db_num_rows($r)) {
                $this->insertLocalTimeEntry($te, $map, $atTicketId);
                return;
            }
        }

        db_query("UPDATE `{$p}autotask_time_entry` SET "
            . "hours_worked=$hours, billable=$bill"
            . ($sEp ? ", start_datetime=FROM_UNIXTIME($sEp)" : '')
            . ($eEp ? ", end_datetime=FROM_UNIXTIME($eEp)" : '')
            . ', updated=NOW() WHERE autotask_time_entry_id=' . $teId
            . " AND (hours_worked<>$hours OR billable<>$bill)", false);
    }

    /**
     * Record an Autotask time entry in the local table (panel popup +
     * totals). Rows are 'synced' — they are never pushed back out.
     *
     * @param array $te  Autotask TimeEntry entity.
     * @param array $map Ticket mapping row.
     */
    private function insertLocalTimeEntry(array $te, array $map, int $atTicketId): void
    {
        $p = Installer::prefix();
        $sEp = $this->atEpoch((string) ($te['startDateTime'] ?? $te['dateWorked'] ?? $te['createDateTime'] ?? ''));
        $eEp = $this->atEpoch((string) ($te['endDateTime'] ?? ''));
        db_query("INSERT INTO `{$p}autotask_time_entry` "
            . '(instance_id, ticket_map_id, osticket_ticket_id, autotask_ticket_id, autotask_time_entry_id, '
            . 'resource_id, start_datetime, end_datetime, hours_worked, billing_code_id, role_id, billable, '
            . 'summary_notes, internal_notes, status, created, updated) VALUES ('
            . (int) ($map['instance_id'] ?? 1) . ',' . (int) $map['id'] . ',' . (int) $map['osticket_ticket_id'] . ','
            . ($atTicketId ?: (int) ($map['autotask_ticket_id'] ?? 0)) . ',' . (int) ($te['id'] ?? 0) . ','
            . (int) ($te['resourceID'] ?? 0) . ','
            . ($sEp ? "FROM_UNIXTIME($sEp)" : 'NULL') . ',' . ($eEp ? "FROM_UNIXTIME($eEp)" : 'NULL') . ','
            . (float) ($te['hoursWorked'] ?? 0) . ',' . (int) ($te['billingCodeID'] ?? 0) . ','
            . (int) ($te['roleID'] ?? 0) . ',' . (empty($te['isNonBillable']) ? 1 : 0) . ','
            . db_input(trim((string) ($te['summaryNotes'] ?? ''))) . ','
            . db_input(trim((string) ($te['internalNotes'] ?? ''))) . ",'synced',"
            . ($sEp ? "FROM_UNIXTIME($sEp)" : 'NOW()') . ',NOW())', false);
    }

    /**
     * @param \Ticket $osTicket
     * @param int     $atTicketId
     * @param array   $map
     */
    private function applyInboundNotes(\Ticket $osTicket, int $atTicketId, array $map): void
    {
        $since = $map['autotask_lastactivity'] ?: gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
        $sinceUtc = $this->toUtc($since);

        // Our own exported notes, identified by id (invisible loop prevention).
        $known = array();
        $pfx = Installer::prefix();
        $rk = db_query("SELECT autotask_note_id FROM `{$pfx}autotask_note_map` WHERE ticket_map_id=" . (int) $map['id'], false);
        while ($rk && ($kk = db_fetch_array($rk))) { $known[(int) $kk['autotask_note_id']] = true; }

        // Autotask SYSTEM notes (workflow/forwarding/assignment noise) are
        // skipped unless the per-client option enables them: no human author
        // (neither resource nor contact) or a known system title pattern.
        $importSys = (int) ($this->settings->raw()->get('import_system_notes') ?? 0);

        $notes = $this->api->getTicketNotesSince($atTicketId, $sinceUtc);
        foreach ($notes as $note) {
            $desc = (string) ($note['description'] ?? '');
            if ($desc === '' || isset($known[(int) ($note['id'] ?? 0)])
                || strpos($desc, self::LOOP_MARKER) !== false) {
                continue; // skip our own exported notes (id match or legacy marker)
            }
            if (!$importSys) {
                $noHuman = empty($note['creatorResourceID']) && empty($note['createdByContactID']);
                $t0 = mb_strtolower((string) ($note['title'] ?? ''));
                $sysTitle = strpos($t0, 'forwarded ticket') !== false
                    || strpos($t0, 'workflow') !== false
                    || strpos($t0, 'ticket assigned') !== false
                    || strpos($t0, 'sla ') === 0;
                if ($noHuman || $sysTitle) {
                    continue; // workflow noise — not a human update
                }
            }
            // Field mapping: Autotask note title -> osTicket note title;
            // body stays the pure description (no duplicated title prefix).
            $title = (string) ($note['title'] ?? 'Autotask Note');
            $body  = $desc;

            try {
                // Thread realism: mirror WHO wrote it and WHAT it was.
                //   publish=2 + anyone      -> internal note, real author name
                //   public  + contact       -> customer MESSAGE from that user
                //   public  + resource      -> agent RESPONSE, technician name
                $iid      = (int) ($map['instance_id'] ?? 1);
                $who      = $this->atAuthorName($note, $iid);
                $internal = isset($note['publish']) && (int) $note['publish'] === 2;
                $byContact = empty($note['creatorResourceID']) && !empty($note['createdByContactID']);
                $entry = null;
                if ($internal && method_exists($osTicket, 'logNote')) {
                    $entry = $osTicket->logNote($title, $body, $who, false);
                } elseif ($byContact && method_exists($osTicket, 'postMessage')) {
                    // Customer-side note -> genuine user message (no alerts).
                    $uid = $this->osUserIdForContact((int) $note['createdByContactID']);
                    if (!$uid && method_exists($osTicket, 'getOwnerId')) {
                        $uid = (int) $osTicket->getOwnerId();
                    }
                    $mv = array('message' => $body, 'title' => $title,
                        'userId' => $uid, 'origin' => 'API');
                    $entry = $osTicket->postMessage($mv, 'API', false);
                } elseif (method_exists($osTicket, 'postReply')) {
                    // Technician reply (no email storm: alerts disabled).
                    $vars = array('response' => $body, 'title' => $title, 'poster' => $who);
                    $errors = array();
                    $entry = $osTicket->postReply($vars, $errors, false, false);
                } elseif (method_exists($osTicket, 'logNote')) {
                    $entry = $osTicket->logNote($title, $body, $who, false);
                }
                // Date parity: stamp the ORIGINAL Autotask time on the entry.
                $this->backdateThreadEntry($entry,
                    (string) ($note['createDateTime'] ?? $note['lastActivityDate'] ?? ''));
                // ID-based dedupe: remember this note so timestamp boundary
                // conditions can never re-import it on later ticks.
                db_query('INSERT IGNORE INTO `' . Installer::prefix() . 'autotask_note_map` '
                    . '(instance_id, ticket_map_id, autotask_note_id, direction, note_type, created) VALUES ('
                    . (int) ($map['instance_id'] ?? 1) . ',' . (int) $map['id'] . ','
                    . (int) ($note['id'] ?? 0) . ", 'to_osticket', 'note', NOW())", false);
                $this->recordHistory($map['id'], (int) $map['osticket_ticket_id'], $atTicketId,
                    'to_osticket', 'note', 'success', 'Imported Autotask note');
            } catch (\Throwable $e) {
                $this->logger->warning('Note import failed: ' . $e->getMessage(),
                    array('category' => 'inbound', 'osticket_ticket_id' => (int) $map['osticket_ticket_id']));
            }
        }

        // Autotask techs often reply via TIME ENTRIES (a separate entity from
        // TicketNotes). Import their summary notes too — skipping entries WE
        // pushed out (tracked in autotask_time_entry) to prevent echo loops.
        try {
            $seen = array();
            $p2 = Installer::prefix();
            $r0 = db_query("SELECT autotask_time_entry_id FROM `{$p2}autotask_time_entry` WHERE autotask_time_entry_id IS NOT NULL");
            while ($r0 && ($row0 = db_fetch_array($r0))) { $seen[(int) $row0['autotask_time_entry_id']] = true; }
            // Plus time entries already IMPORTED (ID-based dedupe, like notes).
            $r0 = db_query("SELECT autotask_note_id FROM `{$p2}autotask_note_map` "
                . 'WHERE ticket_map_id=' . (int) $map['id'] . " AND note_type='timeentry'", false);
            while ($r0 && ($row0 = db_fetch_array($r0))) { $seen[(int) $row0['autotask_note_id']] = true; }
            foreach ($this->api->getTimeEntries($atTicketId) as $te) {
                $teId = (int) ($te['id'] ?? 0);
                $txt  = trim((string) ($te['summaryNotes'] ?? ''));
                $ct   = (string) ($te['createDateTime'] ?? '');
                if ($teId && isset($seen[$teId])) {
                    // Already known — reflect AT-side EDITS onto the panel
                    // table (thread record stays: history preserved), and
                    // create the row when an old import never made one.
                    $this->refreshLocalTimeEntry($teId, $te, $map, $atTicketId);
                    continue;
                }
                if (!$teId || $txt === '' || strpos($txt, self::LOOP_MARKER) !== false) {
                    continue;
                }
                if ($ct !== '' && $this->toUtc($ct) <= $sinceUtc) {
                    continue; // already covered by a previous scan window
                }
                // Native representation: store the time on the thread entry via
                // the core time-tracking mod's own vars (time_spent minutes),
                // so it renders like any local entry — no hours written in text.
                $hours   = (float) ($te['hoursWorked'] ?? 0);
                $minutes = max(1, (int) round($hours * 60));
                $billable = empty($te['isNonBillable']) ? 1 : 0;
                $internal = trim((string) ($te['internalNotes'] ?? ''));
                $noteTxt  = $txt . ($internal !== '' ? "\n\n" . $internal : '');
                // Author parity: show the real Autotask resource as the poster.
                $who = $this->atAuthorName($te, (int) ($map['instance_id'] ?? 1));
                $entry = null;
                if (method_exists($osTicket, 'postNote')) {
                    $nv = array(
                        'title'      => 'Time Entry',
                        'note'       => $noteTxt,
                        'time_spent' => $minutes,
                        'time_type'  => $this->osTimeTypeFor((int) ($te['billingCodeID'] ?? 0), (int) ($map['instance_id'] ?? 1)),
                        'time_bill'  => $billable,
                    );
                    $nerr = array();
                    $entry = $osTicket->postNote($nv, $nerr, $who, false);
                }
                if (!$entry && method_exists($osTicket, 'logNote')) {
                    $entry = $osTicket->logNote('Time Entry', $noteTxt, $who, false);
                }
                // Date parity: stamp the actual work time, not the import time.
                $this->backdateThreadEntry($entry, (string) ($te['startDateTime']
                    ?? $te['dateWorked'] ?? $te['createDateTime'] ?? ''));
                // Panel parity: record the imported entry in the local
                // time-entry table so the panel popup / totals include
                // Autotask-side work ('synced' rows are never re-pushed).
                $this->insertLocalTimeEntry($te, $map, $atTicketId);
                db_query('INSERT IGNORE INTO `' . $p2 . 'autotask_note_map` '
                    . '(instance_id, ticket_map_id, autotask_note_id, direction, note_type, created) VALUES ('
                    . (int) ($map['instance_id'] ?? 1) . ',' . (int) $map['id'] . ','
                    . $teId . ", 'to_osticket', 'timeentry', NOW())", false);
                $this->recordHistory($map['id'], (int) $map['osticket_ticket_id'], $atTicketId,
                    'to_osticket', 'note', 'success', 'Imported Autotask time entry (' . $minutes . 'm)');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Time-entry note import failed: ' . $e->getMessage(),
                array('category' => 'inbound', 'osticket_ticket_id' => (int) $map['osticket_ticket_id']));
        }

        // Module 8 (inbound attachments): mirror new Autotask ticket files into
        // the osTicket thread. ID-tracked both ways — our own uploads and
        // already-imported files are never fetched twice.
        $sa = $this->settings->raw()->get('sync_attachments');
        if ($sa === null || (int) $sa) {
            try {
                $knownAtt = array();
                $rA = db_query("SELECT autotask_note_id FROM `{$p2}autotask_note_map` "
                    . 'WHERE ticket_map_id=' . (int) $map['id'] . " AND note_type='attachment'", false);
                while ($rA && ($xA = db_fetch_array($rA))) { $knownAtt[(int) $xA['autotask_note_id']] = true; }

                $respL = $this->api->request('GET', "Tickets/$atTicketId/Attachments");
                foreach (($respL['items'] ?? array()) as $ai) {
                    $aid = (int) ($ai['id'] ?? 0);
                    if (!$aid || isset($knownAtt[$aid])) {
                        continue;
                    }
                    // Mark seen first: a failed import logs, but never loops.
                    db_query("INSERT IGNORE INTO `{$p2}autotask_note_map` "
                        . '(instance_id, ticket_map_id, autotask_note_id, direction, note_type, created) VALUES ('
                        . (int) ($map['instance_id'] ?? 1) . ',' . (int) $map['id'] . ','
                        . $aid . ", 'to_osticket', 'attachment', NOW())", false);

                    $name = trim((string) ($ai['title'] ?? $ai['fullPath'] ?? '')) ?: ('attachment-' . $aid);
                    // Thread realism: real uploader name + original upload time.
                    $who = $this->atAuthorName(array(
                        'creatorResourceID'  => $ai['attachedByResourceID'] ?? 0,
                        'createdByContactID' => $ai['attachedByContactID'] ?? 0,
                    ), (int) ($map['instance_id'] ?? 1));
                    $when = (string) ($ai['attachDate'] ?? '');
                    // The LIST endpoint never carries the binary; the BY-ID
                    // endpoint does. (List first anyway — costs nothing.)
                    $bin = base64_decode((string) ($ai['data'] ?? ''), true);
                    if ($bin === false || $bin === '') {
                        try {
                            $one  = $this->api->request('GET', "Tickets/$atTicketId/Attachments/$aid");
                            $item = $one['item'] ?? ($one['items'][0] ?? array());
                            $bin  = base64_decode((string) ($item['data'] ?? ''), true);
                        } catch (\Throwable $e) {
                            $bin = false;
                        }
                    }

                    $fid = 0;
                    if ($bin !== false && $bin !== '' && strlen($bin) <= 6000000 && class_exists('AttachmentFile')) {
                        try {
                            // create() takes its arg by reference — literal
                            // arrays fatal on PHP 8.
                            $fSpec = array(
                                'name' => $name,
                                'type' => (string) ($ai['contentType'] ?? 'application/octet-stream'),
                                'data' => $bin,
                                'size' => strlen($bin),
                            );
                            $f = \AttachmentFile::create($fSpec);
                            $fid = is_object($f) ? (int) $f->getId() : (int) $f;
                        } catch (\Throwable $e) {
                            $fid = 0;
                        }
                    }

                    if ($fid && method_exists($osTicket, 'postNote')) {
                        // Full binary imported — the file lives in osTicket.
                        $nerr = array();
                        // ThreadEntry::create reads $vars['attachments'] items
                        // shaped array('id'=>fileId,'name'=>...) — there is no
                        // 'cannedattachments' var in this codebase.
                        $entryA = $osTicket->postNote(array(
                            'title'       => 'Attachment',
                            'note'        => $name,
                            'attachments' => array(array('id' => $fid, 'name' => $name)),
                        ), $nerr, $who, false);
                        $this->backdateThreadEntry($entryA, $when);
                        $this->logger->info("Imported Autotask attachment '$name'",
                            array('category' => 'inbound', 'osticket_ticket_id' => (int) $map['osticket_ticket_id']));
                    } elseif (method_exists($osTicket, 'logNote')) {
                        // Binary truly unavailable (oversize, or the tenant
                        // withheld data): post a note with a link so the tech
                        // still knows the file exists.
                        $link = '';
                        try {
                            $wb = $this->api->webBase();
                            if ($wb) { $link = rtrim($wb, '/') . '/Mvc/ServiceDesk/TicketDetail.mvc?ticketId=' . $atTicketId; }
                        } catch (\Throwable $e) {}
                        $body = 'A file is attached to this ticket in Autotask: ' . $name
                            . ($link !== '' ? "\n\nOpen in Autotask: " . $link : '');
                        $entryA = $osTicket->logNote('Attachment (in Autotask)', $body, $who, false);
                        $this->backdateThreadEntry(is_object($entryA) ? $entryA : null, $when);
                        $this->logger->info("Attachment '$name' referenced (binary not available)",
                            array('category' => 'inbound', 'osticket_ticket_id' => (int) $map['osticket_ticket_id']));
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Inbound attachment sync failed: ' . $e->getMessage(),
                    array('category' => 'inbound', 'osticket_ticket_id' => (int) $map['osticket_ticket_id']));
            }
        }
    }

    /**
     * Build Autotask resolution text for a completed ticket: the last agent
     * reply/message body when available, else a sensible default.
     *
     * @param \Ticket $osTicket
     */
    private function buildResolution(\Ticket $osTicket): string
    {
        $text = '';
        try {
            if (method_exists($osTicket, 'getLastMessage')) {
                $m = $osTicket->getLastMessage();
                if ($m && method_exists($m, 'getBody')) {
                    $b = $m->getBody();
                    if (is_object($b) && method_exists($b, 'getClean')) {
                        $b = $b->getClean();
                    }
                    $text = trim(html_entity_decode(strip_tags((string) $b), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        if ($text === '') {
            $text = 'Completed from osTicket #' . (method_exists($osTicket, 'getNumber') ? $osTicket->getNumber() : $osTicket->getId());
        }
        return mb_substr($text, 0, 3000);
    }

    /**
     * @param int $ostId
     * @return bool True if any time entry exists for the ticket.
     */
    private function ticketHasTimeEntry(int $ostId): bool
    {
        $prefix = Installer::prefix();
        $res = db_query("SELECT 1 FROM `{$prefix}autotask_time_entry` WHERE osticket_ticket_id=" . (int) $ostId . ' LIMIT 1');
        return (bool) ($res && db_num_rows($res) > 0);
    }

    /**
     * osTicket Time Type item id matching an Autotask billing code — resolved
     * by NAME through the per-instance picklist cache (Time Types are mirrored
     * from work types at client registration). Falls back to item 1.
     */
    private function osTimeTypeFor(int $billingCodeId, int $instanceId): int
    {
        try {
            $prefix = Installer::prefix();
            $r = db_query("SELECT label FROM `{$prefix}autotask_picklist_cache` "
                . "WHERE instance_id=$instanceId AND field='billingCodeID' AND value="
                . db_input((string) $billingCodeId) . ' LIMIT 1', false);
            if ($r && ($x = db_fetch_array($r))) {
                $r2 = db_query('SELECT li.id FROM ' . TABLE_PREFIX . 'list_items li JOIN '
                    . TABLE_PREFIX . "list l ON l.id=li.list_id WHERE l.type='time-type' AND LOWER(li.value)="
                    . db_input(mb_strtolower(trim((string) $x['label']))) . ' LIMIT 1', false);
                if ($r2 && ($y = db_fetch_array($r2))) {
                    return (int) $y['id'];
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return 1;
    }

    /**
     * Ensure the client's osTicket Organization exists and attach the imported
     * user to it (only when the user has no organization yet). Best-effort:
     * failures are logged, never fatal to the import.
     *
     * @param int $userId osTicket user id.
     */
    private function attachClientOrganization(int $userId): void
    {
        $orgName = trim($this->settings->clientName());
        if ($orgName === '' || !class_exists('Organization') || !class_exists('User')) {
            return;
        }
        try {
            $user = \User::lookup($userId);
            if (!$user) {
                return;
            }
            // Already in an organization? Leave it alone.
            if (method_exists($user, 'getOrgId') && (int) $user->getOrgId() > 0) {
                return;
            }
            $org = null;
            try {
                $q = \Organization::objects()->filter(array('name' => $orgName))->limit(1);
                foreach ($q as $o) { $org = $o; break; }
            } catch (\Throwable $e) {
                $org = null;
            }
            if (!$org && method_exists('Organization', 'fromVars')) {
                $org = \Organization::fromVars(array('name' => $orgName));
            }
            if ($org && method_exists($user, 'setOrganization')) {
                // Second arg is $save — false silently discarded the change.
                $user->setOrganization($org, true);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Organization attach failed: ' . $e->getMessage(),
                array('category' => 'inbound'));
        }
    }

    /* ================================================================== */
    /* Helpers                                                            */
    /* ================================================================== */

    /**
     * Close the osTicket ticket without echoing the change back to Autotask
     * (used by the completion workflow). Returns true if it acted.
     *
     * @param int $ostId
     * @return bool
     */
    public function closeOsticket(int $ostId): bool
    {
        $osTicket = \Ticket::lookup($ostId);
        if (!$osTicket) {
            return false;
        }
        self::$suppress = true;
        try {
            $this->setOsticketState($osTicket, 'closed');
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('closeOsticket failed: ' . $e->getMessage(),
                array('category' => 'closure', 'osticket_ticket_id' => $ostId));
            return false;
        } finally {
            self::$suppress = false;
        }
    }

    /**
     * Move an osTicket ticket to an open/closed state, resolving a concrete
     * TicketStatus where the core API requires one. Defensive across versions.
     *
     * @param \Ticket $osTicket
     * @param string  $state 'open' | 'closed'
     */
    private function setOsticketState(\Ticket $osTicket, string $state): void
    {
        if (!method_exists($osTicket, 'setStatus')) {
            return;
        }
        $status = null;
        if (class_exists('TicketStatus')) {
            // There can be MULTIPLE statuses per state (e.g. Resolved + Closed
            // are both "closed"); lookup() would throw "multiple matched", so
            // take the first match explicitly.
            try {
                $q = \TicketStatus::objects()->filter(array('state' => $state))->limit(1);
                foreach ($q as $s) { $status = $s; break; }
            } catch (\Throwable $e) {
                $status = null;
            }
        }
        // Fall back to the string; setStatus tolerates several input types.
        $osTicket->setStatus($status ?: $state);
    }

    /**
     * Set an osTicket ticket to a SPECIFIC status by (case-insensitive) name —
     * used by the per-client status map. No-op when already in that status.
     *
     * @param \Ticket $osTicket
     * @param string  $name osTicket status name, e.g. "in progress"
     */
    private function setOsticketStatusByName(\Ticket $osTicket, string $name): void
    {
        if (!method_exists($osTicket, 'setStatus') || !class_exists('TicketStatus')) {
            return;
        }
        $target = null;
        foreach (\TicketStatus::objects() as $s) {
            if (method_exists($s, 'getName')
                && mb_strtolower(trim((string) $s->getName())) === mb_strtolower(trim($name))) {
                $target = $s;
                break;
            }
        }
        if (!$target) {
            throw new \RuntimeException("No osTicket status named '$name' — create it under Manage > Lists > Ticket Statuses.");
        }
        // Skip when already there (prevents write churn / signal noise).
        if (method_exists($osTicket, 'getStatusId')
            && (int) $osTicket->getStatusId() === (int) $target->getId()) {
            return;
        }
        $osTicket->setStatus($target);
        $this->logger->info('osTicket #' . $osTicket->getId() . " status -> '$name' (from Autotask)",
            array('category' => 'inbound', 'osticket_ticket_id' => $osTicket->getId()));
    }

    /**
     * @param \Ticket $osTicket
     * @return string Current osTicket status display name ('' if unavailable).
     */
    private function osticketStatusName(\Ticket $osTicket): string
    {
        try {
            if (method_exists($osTicket, 'getStatus')) {
                $st = $osTicket->getStatus();
                if (is_object($st) && method_exists($st, 'getName')) {
                    return (string) $st->getName();
                }
                if (is_string($st)) {
                    return $st;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return '';
    }

    /**
     * @param int|null $mapId
     */
    private function recordHistory($mapId, ?int $ostId, ?int $atId, string $direction, string $entity, string $status, string $summary): void
    {
        $prefix = Installer::prefix();
        $mid = $mapId ? (int) $mapId : 'NULL';
        $ost = $ostId ? (int) $ostId : 'NULL';
        $at  = $atId ? (int) $atId : 'NULL';
        $dir = db_input($direction);
        $ent = db_input($entity);
        $st  = db_input($status);
        $sum = db_input(substr($summary, 0, 250));
        db_query(
            "INSERT INTO `{$prefix}autotask_sync_history` "
            . "(map_id, osticket_ticket_id, autotask_ticket_id, direction, entity_type, status, summary, created) "
            . "VALUES ($mid, $ost, $at, $dir, $ent, $st, $sum, NOW())"
        );
    }

    private function threadEntryTicketId($entry): ?int
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
            if (method_exists($entry, 'getTicket')) {
                $t = $entry->getTicket();
                if ($t) {
                    return (int) $t->getId();
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }

    private function entryBody($entry): string
    {
        try {
            if (method_exists($entry, 'getBody')) {
                $b = $entry->getBody();
                if (is_object($b) && method_exists($b, 'getClean')) {
                    $b = $b->getClean();
                }
                $text = is_string($b) ? $b : (string) $b;
                return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return '';
    }

    private function toUtc(string $dt): string
    {
        try {
            $t = new \DateTime($dt, new \DateTimeZone('UTC'));
            return $t->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable $e) {
            return gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
        }
    }
}
