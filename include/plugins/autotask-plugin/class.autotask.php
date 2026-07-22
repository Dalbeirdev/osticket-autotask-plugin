<?php
/**
 * Autotask Integration — high-level facade.
 *
 * Aggregates data for the admin dashboard and exposes coarse operations
 * (connection test, manual sync, stats). Admin pages talk to this class rather
 * than reaching into individual services.
 *
 * @package Autotask Integration
 */

namespace Autotask;

use Autotask\Services\Container;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Facade over the service container for admin-facing operations.
 */
class Autotask
{
    /** @var Container */
    private $c;

    public function __construct(Container $container)
    {
        $this->c = $container;
    }

    /**
     * Run a live connection test and cache the result for the dashboard badge.
     *
     * @return array{ok:bool,message:string,zone_url:string}
     */
    public function testConnection(): array
    {
        $result = $this->c->api()->testConnection();
        $this->c->settings()->setState('last_connection_test', array(
            'ok'      => $result['ok'],
            'message' => $result['message'],
            'at'      => gmdate('c'),
        ));
        return $result;
    }

    /**
     * Trigger a manual incremental or full sync (dashboard buttons).
     *
     * @param string $mode incremental|full
     * @param int    $lookbackDays for full sync
     * @return array Summary.
     */
    public function manualSync(string $mode = 'incremental', int $lookbackDays = 30): array
    {
        // Multi-tenant: run for every enabled client and aggregate the counts.
        $total = array('queue' => array('processed' => 0, 'failed' => 0), 'pulled' => 0);
        foreach ($this->tenantContainers() as $c) {
            $r = $mode === 'full'
                ? $c->scheduler()->runFull($lookbackDays)
                : $c->scheduler()->runIncremental();
            $total['queue']['processed'] += (int) $r['queue']['processed'];
            $total['queue']['failed']    += (int) $r['queue']['failed'];
            $total['pulled']             += (int) $r['pulled'];
        }
        return $total;
    }

    /**
     * Containers for all enabled client instances (falls back to the legacy
     * single container pre-upgrade). See AutotaskPlugin::instanceContainers().
     *
     * @return \Autotask\Services\Container[]
     */
    private function tenantContainers(): array
    {
        $plugin = $this->c->plugin();
        if ($plugin && method_exists($plugin, 'instanceContainers')) {
            return $plugin->instanceContainers();
        }
        return array($this->c);
    }

    /**
     * Import open Autotask tickets into osTicket. Enqueues import jobs (producer)
     * then drains the queue so the admin gets immediate results.
     *
     * @param int $limit Max tickets to enqueue this run.
     * @return array{fetched:int,queued:int,skipped:int,processed:int,failed:int}
     */
    public function importFromAutotask(int $limit = 25): array
    {
        // Multi-tenant: each enabled client imports through its own filters
        // and credentials; results are aggregated for the dashboard notice.
        $total = array('fetched' => 0, 'queued' => 0, 'skipped' => 0, 'processed' => 0, 'failed' => 0);
        foreach ($this->tenantContainers() as $c) {
            $prod  = $c->sync()->importFromAutotask($limit);
            // Drain enough to cover what we just queued plus any other due jobs.
            $drain = $c->scheduler()->processQueue(max($limit, 50));
            $total['fetched']   += (int) $prod['fetched'];
            $total['queued']    += (int) $prod['queued'];
            $total['skipped']   += (int) $prod['skipped'];
            $total['processed'] += (int) $drain['processed'];
            $total['failed']    += (int) $drain['failed'];
        }
        return $total;
    }

    /* ------------------------------------------------------------------ */
    /* Time Entry (technician panel)                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Add a time entry for a ticket, then drain the queue so it posts to
     * Autotask immediately and the technician gets instant feedback.
     *
     * @param int   $ostId
     * @param array $vars
     * @param array $staff {id,name,ip}
     * @return array{ok:bool,errors:array,id:?int,posted:bool,error:?string}
     */
    public function addTimeEntry(int $ostId, array $vars, array $staff): array
    {
        $res = $this->c->timeEntry()->add($ostId, $vars, $staff);
        if (!$res['ok']) {
            return $res + array('posted' => false, 'error' => null);
        }
        // Drain just this kind of work immediately.
        $drain = $this->c->scheduler()->processQueue(10);
        $row = null;
        foreach ($this->c->timeEntry()->listForTicket($ostId) as $r) {
            if ((int) $r['id'] === (int) $res['id']) { $row = $r; break; }
        }
        $posted = $row && $row['status'] === 'synced';
        return $res + array(
            'posted' => $posted,
            'error'  => ($row && $row['status'] === 'failed') ? (string) $row['last_error'] : null,
        );
    }

    /**
     * Import a specific Autotask ticket by id.
     *
     * @param int $atId
     * @return array{ok:bool,osticket_id:?int,message:string}
     */
    public function importById(int $atId): array
    {
        // The same Autotask id can exist in several tenants; try each enabled
        // client in order and return the first successful import.
        $last = array('ok' => false, 'osticket_id' => null, 'message' => 'No enabled clients.');
        foreach ($this->tenantContainers() as $c) {
            $last = $c->sync()->importSingle($atId);
            if (!empty($last['ok'])) {
                return $last;
            }
        }
        return $last;
    }

    /**
     * @param int $ostId
     * @return array<int,array<string,mixed>>
     */
    public function listTimeEntries(int $ostId): array
    {
        return $this->c->timeEntry()->listForTicket($ostId);
    }

    /**
     * Context for the embedded Autotask ticket panel (timer + status).
     *
     * @param int $ostId
     * @return array<string,mixed>
     */
    public function panelContext(int $ostId): array
    {
        $map = $this->c->mapper()->findByOsticketId($ostId);
        if (!$map || empty($map['autotask_ticket_id'])) {
            // Unmapped ticket: offer the Client picker so an agent can push it
            // to a chosen tenant's Autotask (manual-ticket flow, Module 4b).
            $clients = array();
            try {
                foreach ($this->c->instanceRepository()->allEnabled() as $i) {
                    $clients[] = array('id' => $i->id(), 'code' => $i->code(), 'name' => $i->name());
                }
            } catch (\Throwable $e) {
                // no register yet — legacy mode, no picker
            }
            return array('mapped' => false, 'clients' => $clients);
        }
        $this->c->picklists()->ensureFresh();
        $atId = (int) $map['autotask_ticket_id'];

        // Perf: use the cached status; only hit the API once to back-fill a
        // mapping that has no cached status yet.
        $current = isset($map['autotask_status']) && $map['autotask_status'] !== ''
            ? (string) $map['autotask_status'] : null;
        if ($current === null) {
            try {
                $t = $this->c->api()->getTicket($atId);
                if (isset($t['status'])) {
                    $current = (string) $t['status'];
                    $this->c->mapper()->setAutotaskStatus((int) $map['id'], $current);
                }
            } catch (\Throwable $e) {
                // leave current null; panel still renders
            }
        }

        $atCtx = $this->autotaskContext($atId);
        $inst  = $this->c->instance();

        // Auto-select: the AT ticket's Work Type -> the matching osTicket
        // Time Type list item (mirrored by name), so the reply form defaults
        // to what the ticket was created with in Autotask.
        $wtItem = 0;
        if (!empty($atCtx['work_type'])) {
            try {
                $lbl = $this->c->picklists()->labelByValue('billingCodeID', (string) (int) $atCtx['work_type']);
                if ($lbl !== null && trim($lbl) !== '') {
                    $r = db_query('SELECT li.id FROM ' . TABLE_PREFIX . 'list_items li JOIN '
                        . TABLE_PREFIX . "list l ON li.list_id=l.id WHERE l.type='time-type' AND LOWER(li.value)="
                        . db_input(mb_strtolower(trim($lbl))) . ' LIMIT 1', false);
                    if ($r && ($x = db_fetch_array($r))) {
                        $wtItem = (int) $x['id'];
                    }
                }
            } catch (\Throwable $e) {
                // panel renders without a preselect
            }
        }

        // Time Summary: worked = every synced entry (both sides), live.
        $worked = 0.0;
        $rW = db_query('SELECT SUM(hours_worked) h FROM ' . TABLE_PREFIX
            . "autotask_time_entry WHERE osticket_ticket_id=$ostId", false);
        if ($rW && ($xW = db_fetch_array($rW))) {
            $worked = round((float) $xW['h'], 2);
        }

        return array(
            'time_type_item'         => $wtItem,
            'details'                => $atCtx['details'] ?? array(),
            'worked_hours'           => $worked,
            // Autotask forces time to NON-billable on a ticket with no
            // contract — surface that instead of leaving techs guessing.
            'has_contract'           => !empty($atCtx['details']['contract']),
            'hide_sla'               => $this->c->settings()->hideSlaView(),
            'email_customer'         => $this->c->settings()->emailCustomerReplies(),
            'default_note_tab'       => $this->c->settings()->defaultInternalNote(),
            'mapped'                 => true,
            'client_code'            => $inst ? $inst->code() : '',
            'client_name'            => $inst ? $inst->name() : '',
            'time_entries'           => $this->c->timeEntry()->listForTicket($ostId),
            'autotask_ticket_id'     => $atId,
            'autotask_ticket_number' => (string) $map['autotask_ticket_number'],
            'company'                => $atCtx['company'],
            'contact'                => $atCtx['contact'],
            'contact_email'          => $atCtx['contact_email'],
            'deep_link'              => $this->deepLink($atId, (string) $map['autotask_ticket_number']),
            'current_status'         => $current,
            'statuses'               => $this->c->picklists()->options('status', 'Tickets'),
            'work_types'             => $this->c->picklists()->options('billingCodeID'),
            'resources'              => $this->c->picklists()->options('resourceID'),
            'roles'                  => $this->c->picklists()->options('roleID'),
            'default_work_type'      => $this->c->settings()->defaultWorkTypeId(),
            'default_resource'       => $this->c->settings()->defaultResourceId(),
            'inline_capture'         => $this->c->settings()->captureTimeEnabled(),
            'require_time_close'     => $this->c->settings()->requireTimeBeforeClose(),
            'allow_close_osticket'   => $this->c->settings()->closeOsticketOnComplete(),
            'has_time'               => $this->hasTimeEntry($ostId),
        );
    }

    /**
     * Deep link to open the ticket in the Autotask web UI (ExecuteCommand API).
     * The web base is resolved once and cached in the KV store; built entirely
     * from trusted zone data + an integer id (no user input).
     *
     * @param int    $atId
     * @param string $ticketNumber Autotask ticket number (preferred parameter).
     * @return string|null
     */
    private function deepLink(int $atId, string $ticketNumber = ''): ?string
    {
        $settings = $this->c->settings();
        $base = $settings->state('at_web_base');
        if (!is_string($base) || $base === '') {
            try {
                $base = $this->c->api()->webBase();
            } catch (\Throwable $e) {
                $base = null;
            }
            if ($base) {
                $settings->setState('at_web_base', $base);
            }
        }
        if (!$base) {
            return null;
        }
        // Direct ticket-detail route (the URL Autotask's own UI uses) — the
        // ExecuteCommand.aspx deep-link API proved unreliable on some tenants,
        // while this form verifiably opens the ticket.
        return rtrim($base, '/') . '/Mvc/ServiceDesk/TicketDetail.mvc?ticketId=' . $atId;
    }

    /**
     * Company/contact context for a mapped ticket, cached in the KV store for
     * 24h so normal ticket views cost zero extra API calls.
     *
     * @param int $atId
     * @return array{company:?string,contact:?string,contact_email:?string}
     */
    private function autotaskContext(int $atId): array
    {
        $out = array('company' => null, 'contact' => null, 'contact_email' => null,
            'work_type' => 0, 'details' => array());
        $settings = $this->c->settings();
        $key = 'at_tctx_' . $atId;

        $cached = $settings->state($key);
        if (is_array($cached) && !empty($cached['ts']) && (time() - (int) $cached['ts']) < 86400
            && array_key_exists('details', $cached)) {
            $out['company']       = $cached['company'] ?? null;
            $out['contact']       = $cached['contact'] ?? null;
            $out['contact_email'] = $cached['contact_email'] ?? null;
            $out['work_type']     = (int) ($cached['work_type'] ?? 0);
            $out['details']       = is_array($cached['details'] ?? null) ? $cached['details'] : array();
            return $out;
        }

        try {
            $t = $this->c->api()->getTicket($atId);
        } catch (\Throwable $e) {
            return $out; // panel still renders without context
        }
        if (!$t) {
            return $out;
        }

        if (!empty($t['companyID'])) {
            try {
                $co = $this->c->api()->getCompany((int) $t['companyID']);
                $out['company'] = isset($co['companyName']) ? (string) $co['companyName'] : null;
            } catch (\Throwable $e) {
                // non-fatal
            }
        }
        if (!empty($t['contactID'])) {
            try {
                $ct = $this->c->api()->getContact((int) $t['contactID']);
                if ($ct) {
                    $name = trim(($ct['firstName'] ?? '') . ' ' . ($ct['lastName'] ?? ''));
                    $out['contact']       = $name !== '' ? $name : null;
                    $out['contact_email'] = isset($ct['emailAddress']) ? (string) $ct['emailAddress'] : null;
                }
            } catch (\Throwable $e) {
                // non-fatal
            }
        }
        // The ticket's Work Type — pre-selects the reply form's Time Type.
        $out['work_type'] = (int) ($t['billingCodeID'] ?? 0);

        // Full detail block for the ticket-view side cards (labels resolved
        // through the picklist cache, entity metadata, or a lookup call —
        // all inside this 24h-cached blob, so views stay API-free).
        $pk = $this->c->picklists();
        $lbl = function (string $field, $v) use ($pk): ?string {
            $v = (int) $v;
            return $v ? ($pk->labelByValue($field, (string) $v) ?: $this->atMetaLabel($field, $v)) : null;
        };
        $contract = null;
        if (!empty($t['contractID'])) {
            try {
                $co = $this->c->api()->request('GET', 'Contracts/' . (int) $t['contractID']);
                $ci = $co['item'] ?? ($co['items'][0] ?? array());
                $contract = trim((string) ($ci['contractName'] ?? '')) ?: null;
            } catch (\Throwable $e) {
                // optional
            }
        }
        $out['details'] = array(
            'status'      => $lbl('status', $t['status'] ?? 0),
            'priority'    => $lbl('priority', $t['priority'] ?? 0),
            'queue'       => $lbl('queueID', $t['queueID'] ?? 0),
            'issue_type'  => $this->atMetaLabel('issueType', (int) ($t['issueType'] ?? 0)),
            'sub_issue'   => $this->atMetaLabel('subIssueType', (int) ($t['subIssueType'] ?? 0)),
            'source'      => $this->atMetaLabel('source', (int) ($t['source'] ?? 0)),
            'sla'         => $this->atMetaLabel('serviceLevelAgreementID', (int) ($t['serviceLevelAgreementID'] ?? 0)),
            'ticket_type' => $lbl('ticketType', $t['ticketType'] ?? 0),
            'resource'    => $lbl('resourceID', $t['assignedResourceID'] ?? 0),
            'role'        => $lbl('roleID', $t['assignedResourceRoleID'] ?? 0),
            'work_type'   => $lbl('billingCodeID', $t['billingCodeID'] ?? 0),
            'contract'    => $contract,
            'created'     => (string) ($t['createDate'] ?? ''),
            'due'         => (string) ($t['dueDateTime'] ?? ''),
            'estimated'   => round((float) ($t['estimatedHours'] ?? 0), 2),
        );

        $settings->setState($key, $out + array('ts' => time()));
        return $out;
    }

    /**
     * Label for an Autotask Tickets field value that is NOT in the picklist
     * cache (issueType, subIssueType, source, SLA...): resolved from the
     * Tickets entity metadata, cached in the KV store for 7 days.
     */
    private function atMetaLabel(string $field, int $value): ?string
    {
        if (!$value) {
            return null;
        }
        $settings = $this->c->settings();
        $meta = $settings->state('at_field_meta');
        if (!is_array($meta) || empty($meta['ts']) || (time() - (int) $meta['ts']) > 604800) {
            $meta = array('ts' => time(), 'fields' => array());
            try {
                $info = $this->c->api()->request('GET', 'Tickets/entityInformation/fields');
                foreach (($info['fields'] ?? array()) as $f) {
                    $name = (string) ($f['name'] ?? '');
                    if ($name === '' || empty($f['picklistValues'])) {
                        continue;
                    }
                    foreach ($f['picklistValues'] as $pv) {
                        $meta['fields'][$name][(string) $pv['value']] = (string) $pv['label'];
                    }
                }
                $settings->setState('at_field_meta', $meta);
            } catch (\Throwable $e) {
                return null;
            }
        }
        $v = $meta['fields'][$field][(string) $value] ?? null;
        return ($v !== null && trim($v) !== '') ? trim($v) : null;
    }

    /**
     * Push a manually-created (unmapped) osTicket ticket to a chosen client's
     * Autotask: map it under that tenant, queue the create, drain immediately.
     *
     * @param int   $ostId
     * @param array $staff {id,name,ip}
     * @return array{ok:bool,error:?string,autotask_number:?string}
     */
    public function pushTicket(int $ostId, array $staff): array
    {
        if ($this->c->mapper()->findByOsticketId($ostId)) {
            return array('ok' => false, 'error' => 'Ticket is already linked.', 'autotask_number' => null);
        }
        $ticket = \Ticket::lookup($ostId);
        if (!$ticket) {
            return array('ok' => false, 'error' => 'Ticket not found.', 'autotask_number' => null);
        }
        try {
            $this->c->sync()->onOsticketTicketCreated($ticket);
            $this->c->scheduler()->processQueue(10); // immediate feedback
            $map = $this->c->mapper()->findByOsticketId($ostId);
            $num = $map ? (string) ($map['autotask_ticket_number'] ?? '') : '';
            $this->c->audit()->log('ticket.push', array(
                'staff_id' => $staff['id'] ?? null, 'staff_name' => $staff['name'] ?? null,
                'entity' => 'ticket', 'osticket_ticket_id' => $ostId,
                'autotask_ticket_id' => $map ? (int) ($map['autotask_ticket_id'] ?? 0) : null,
            ));
            $linked = $map && !empty($map['autotask_ticket_id']);
            return array(
                'ok' => true,
                'error' => $linked ? null : 'Queued — will link on the next sync run.',
                'autotask_number' => $num !== '' ? $num : null,
            );
        } catch (\Throwable $e) {
            return array('ok' => false, 'error' => $e->getMessage(), 'autotask_number' => null);
        }
    }

    /**
     * Complete (close) the Autotask ticket with required fields validated.
     *
     * @param int   $ostId
     * @param array $vars  {resolution, complete_date, close_osticket}
     * @param array $staff {id,name,ip}
     * @return array{ok:bool,error:?string,closed_osticket:bool}
     */
    public function completeTicket(int $ostId, array $vars, array $staff): array
    {
        $map = $this->c->mapper()->findByOsticketId($ostId);
        if (!$map || empty($map['autotask_ticket_id'])) {
            return array('ok' => false, 'error' => 'Ticket is not linked to Autotask.', 'closed_osticket' => false);
        }
        $atId = (int) $map['autotask_ticket_id'];

        $resolution = trim((string) ($vars['resolution'] ?? ''));
        if ($resolution === '') {
            return array('ok' => false, 'error' => 'Resolution notes are required to complete.', 'closed_osticket' => false);
        }
        if ($this->c->settings()->requireTimeBeforeClose() && !$this->hasTimeEntry($ostId)) {
            return array('ok' => false, 'error' => 'Log a time entry before completing this ticket.', 'closed_osticket' => false);
        }

        try {
            $completeStatus = $this->c->settings()->completeStatus();
            $this->c->api()->updateTicket($atId, array(
                'status'     => $completeStatus,
                'resolution' => mb_substr($resolution, 0, 32000),
            ));
            $this->c->mapper()->setAutotaskStatus((int) $map['id'], (string) $completeStatus);

            $closed = false;
            if (!empty($vars['close_osticket']) && $this->c->settings()->closeOsticketOnComplete()) {
                $closed = $this->c->sync()->closeOsticket($ostId);
            }

            $this->c->mapper()->touch((int) $map['id'], 'osticket');
            $this->c->audit()->log('ticket.complete', array(
                'staff_id' => $staff['id'] ?? null,
                'staff_name' => $staff['name'] ?? null,
                'entity' => 'ticket',
                'osticket_ticket_id' => $ostId,
                'autotask_ticket_id' => $atId,
                'detail' => array('resolution' => mb_substr($resolution, 0, 200), 'closed_osticket' => $closed),
            ));
            return array('ok' => true, 'error' => null, 'closed_osticket' => $closed);
        } catch (\Throwable $e) {
            return array('ok' => false, 'error' => $e->getMessage(), 'closed_osticket' => false);
        }
    }

    /**
     * @param int $ostId
     * @return bool True if any time entry exists for the ticket.
     */
    private function hasTimeEntry(int $ostId): bool
    {
        $prefix = Installer::prefix();
        $id = (int) $ostId;
        $res = db_query("SELECT 1 FROM `{$prefix}autotask_time_entry` WHERE osticket_ticket_id=$id LIMIT 1");
        return (bool) ($res && db_num_rows($res) > 0);
    }

    /**
     * Set the Autotask ticket status from osTicket.
     *
     * @param int   $ostId
     * @param int   $status
     * @param array $staff {id,name,ip}
     * @return array{ok:bool,error:?string}
     */
    public function setAutotaskStatus(int $ostId, int $status, array $staff): array
    {
        $map = $this->c->mapper()->findByOsticketId($ostId);
        if (!$map || empty($map['autotask_ticket_id'])) {
            return array('ok' => false, 'error' => 'Ticket is not linked to Autotask.');
        }
        $atId = (int) $map['autotask_ticket_id'];
        try {
            $this->c->api()->updateTicket($atId, array('status' => $status));
            $this->c->mapper()->setAutotaskStatus((int) $map['id'], (string) $status);
            $this->c->mapper()->touch((int) $map['id'], 'osticket');
            $this->c->audit()->log('status.set', array(
                'staff_id' => $staff['id'] ?? null,
                'staff_name' => $staff['name'] ?? null,
                'entity' => 'ticket',
                'osticket_ticket_id' => $ostId,
                'autotask_ticket_id' => $atId,
                'detail' => array('status' => $status),
            ));
            return array('ok' => true, 'error' => null);
        } catch (\Throwable $e) {
            return array('ok' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Picklist options for the time-entry form (refreshes cache if stale).
     *
     * @return array{resources:array,work_types:array,roles:array}
     */
    public function timeEntryPicklists(): array
    {
        $this->c->picklists()->ensureFresh();
        return array(
            'resources'  => $this->c->picklists()->options('resourceID'),
            'work_types' => $this->c->picklists()->options('billingCodeID'),
            'roles'      => $this->c->picklists()->options('roleID'),
        );
    }

    /**
     * Force a picklist cache refresh.
     */
    public function refreshPicklists(): bool
    {
        return $this->c->picklists()->ensureFresh(true);
    }

    /**
     * @return array|null Mapping row for an osTicket ticket.
     */
    public function mappingFor(int $ostId): ?array
    {
        return $this->c->mapper()->findByOsticketId($ostId);
    }

    /**
     * Assemble all numbers shown on the dashboard.
     *
     * @return array<string,mixed>
     */
    public function stats(): array
    {
        $settings = $this->c->settings();
        $prefix   = Installer::prefix();

        $queue = $this->c->queue()->statusCounts();

        // Sync history success/fail totals.
        $hist = array('success' => 0, 'failed' => 0, 'skipped' => 0);
        $res = db_query("SELECT status, COUNT(*) c FROM `{$prefix}autotask_sync_history` GROUP BY status");
        if ($res) {
            while ($r = db_fetch_array($res)) {
                $hist[$r['status']] = (int) $r['c'];
            }
        }

        $lastTest = $settings->state('last_connection_test', null);
        $lastSync = $settings->state('last_sync_summary', null);

        return array(
            'enabled'         => $settings->isEnabled(),
            'configured'      => Settings::isConfigured($settings->raw()),
            'connection'      => $lastTest,
            'total_mapped'    => $this->c->mapper()->countMapped(),
            'queue'           => $queue,
            'failed_syncs'    => $queue['failed'] + $queue['dead'] + $hist['failed'],
            'history'         => $hist,
            'last_sync'       => $lastSync,
            'last_inbound'    => $settings->state('last_inbound_sync', null),
            'two_way'         => $settings->twoWayEnabled(),
            'interval'        => $settings->syncIntervalSeconds(),
        );
    }

    /**
     * @return array<int,array<string,mixed>> Recent log entries.
     */
    public function recentLogs(int $limit = 100, int $offset = 0, ?string $level = null): array
    {
        return $this->c->logger()->recent($limit, $offset, $level);
    }

    /**
     * @return array<int,array<string,mixed>> Failed/dead queue jobs.
     */
    public function failedJobs(int $limit = 50): array
    {
        return $this->c->queue()->recentFailures($limit);
    }

    /**
     * @return array<int,array<string,mixed>> Recent audit-trail rows.
     */
    public function recentAudit(int $limit = 50): array
    {
        return $this->c->audit()->recent($limit);
    }

    public function container(): Container
    {
        return $this->c;
    }
}
