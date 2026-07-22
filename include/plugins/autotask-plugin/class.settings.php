<?php
/**
 * Autotask Integration — settings facade.
 *
 * Two distinct stores are bridged here:
 *  1. PluginConfig  — encrypted admin configuration (credentials, defaults).
 *  2. KV table      — runtime state (sync cursors, last-run timestamps).
 *
 * @package Autotask Integration
 */

namespace Autotask;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Read/normalise plugin configuration and persist lightweight runtime state.
 */
class Settings
{
    /** @var \PluginConfig */
    private $config;

    /** @var string Namespace prefix for runtime KV state keys ('' = legacy/global). */
    private $statePrefix;

    /**
     * @param \PluginConfig|InstanceConfig $config
     * @param string                       $statePrefix e.g. "i2:" to isolate a
     *                                     client instance's sync cursors.
     */
    public function __construct($config, string $statePrefix = '')
    {
        $this->config = $config;
        $this->statePrefix = $statePrefix;
    }

    /* ----- Static credential checks (used during bootstrap) -------------- */

    /**
     * True when the minimum credentials needed to talk to Autotask are present.
     */
    public static function isConfigured($config): bool
    {
        return $config->get('api_username')
            && $config->get('api_secret')
            && $config->get('api_integration_code');
    }

    /* ----- Typed accessors over PluginConfig ----------------------------- */

    public function credentials(): array
    {
        return array(
            'username'         => (string) $this->config->get('api_username'),
            'secret'           => (string) $this->config->get('api_secret'),
            'integration_code' => (string) $this->config->get('api_integration_code'),
            'zone_url'         => (string) $this->config->get('api_zone_url'),
        );
    }

    public function defaults(): array
    {
        return array(
            'company_id'     => (int) $this->config->get('default_company_id'),
            'queue_id'       => $this->intOrNull($this->config->get('default_queue_id')),
            'priority'       => $this->intOrNull($this->config->get('default_priority')),
            'status'         => $this->intOrNull($this->config->get('default_status')),
            'ticket_type'    => $this->intOrNull($this->config->get('default_ticket_type')),
            // Some tenants mark these REQUIRED on ticket creation.
            'issue_type'     => $this->intOrNull($this->config->get('default_issue_type_id')),
            'sub_issue_type' => $this->intOrNull($this->config->get('default_sub_issue_type_id')),
        );
    }

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('enabled');
    }

    public function twoWayEnabled(): bool
    {
        return (bool) $this->config->get('two_way_sync');
    }

    /**
     * Inbound note import (Autotask -> osTicket notes). On by default now that
     * the TicketNotes query endpoint is verified; admins can disable it.
     */
    public function inboundNotesEnabled(): bool
    {
        $v = $this->config->get('inbound_notes_enabled');
        return $v === null ? true : (bool) $v;
    }

    /**
     * Auto-import new Autotask tickets on each scheduled sync (opt-in).
     */
    public function autoImportEnabled(): bool
    {
        return (bool) $this->config->get('auto_import_enabled');
    }

    public function syncAttachments(): bool
    {
        return (bool) $this->config->get('sync_attachments');
    }

    public function syncIntervalSeconds(): int
    {
        return max(60, (int) ($this->config->get('sync_interval') ?: 300));
    }

    public function batchSize(): int
    {
        return max(1, min(500, (int) ($this->config->get('batch_size') ?: 50)));
    }

    public function maxRetries(): int
    {
        return max(0, (int) ($this->config->get('max_retries') ?: 6));
    }

    public function logLevel(): string
    {
        return (string) ($this->config->get('log_level') ?: 'info');
    }

    /* ----- Inline time capture ------------------------------------------ */

    public function captureTimeEnabled(): bool
    {
        return (bool) $this->config->get('capture_time_enabled');
    }

    /** Hide osTicket's own "SLA Plan" row on synced tickets (default: yes). */
    public function hideSlaView(): bool
    {
        $v = $this->config->get('hide_sla_view');
        return $v === null ? true : (bool) $v;
    }

    /**
     * May osTicket email replies straight to the customer? Default NO: the
     * client follows the ticket in Autotask, so a second email from osTicket
     * would break the "one conversation" rule (and reveal the helpdesk).
     */
    public function emailCustomerReplies(): bool
    {
        return (bool) $this->config->get('email_customer_replies');
    }

    /** Start osTicket's ticket header collapsed on synced tickets (default: yes). */
    public function collapseTicketInfo(): bool
    {
        $v = $this->config->get('collapse_ticket_info');
        return $v === null ? true : (bool) $v;
    }

    /** Select the Internal Note tab when a synced ticket opens (default: yes). */
    public function defaultInternalNote(): bool
    {
        $v = $this->config->get('default_internal_note');
        return $v === null ? true : (bool) $v;
    }

    public function timeFieldNames(): array
    {
        return array(
            'spent'    => (string) ($this->config->get('field_time_spent') ?: 'time_spent'),
            'type'     => (string) ($this->config->get('field_time_type') ?: 'time_type'),
            // The core time-tracking mod's reply form posts the checkbox as
            // "time_bill" — 'billable' as a default silently made every
            // captured entry non-billable in Autotask.
            'billable' => (string) ($this->config->get('field_billable') ?: 'time_bill'),
        );
    }

    public function timeSpentInMinutes(): bool
    {
        // osTicket choice fields store their value as {"key":"Label"} JSON
        // (or give an array) — normalize to the bare key before comparing,
        // otherwise "minutes" never matches and entries silently become HOURS.
        $v = $this->config->get('time_spent_unit') ?: 'minutes';
        if (is_array($v)) {
            $v = (string) key($v);
        } elseif (is_string($v) && $v !== '' && $v[0] === '{') {
            $d = json_decode($v, true);
            if (is_array($d) && $d) {
                $v = (string) key($d);
            }
        }
        return mb_strtolower(trim((string) $v)) === 'minutes';
    }

    /**
     * Parse the "Label=ID" time-type map into [lowercased label => billing code id].
     *
     * @return array<string,int>
     */
    public function timeTypeMap(): array
    {
        $raw = (string) $this->config->get('timetype_map');
        $map = array();
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) {
                continue;
            }
            list($label, $id) = array_map('trim', explode('=', $line, 2));
            if ($label !== '' && is_numeric($id)) {
                $map[mb_strtolower($label)] = (int) $id;
            }
        }
        return $map;
    }

    public function defaultWorkTypeId(): ?int
    {
        return $this->intOrNull($this->config->get('default_work_type_id'));
    }

    public function defaultResourceId(): ?int
    {
        return $this->intOrNull($this->config->get('default_resource_id'));
    }

    public function defaultRoleId(): ?int
    {
        return $this->intOrNull($this->config->get('default_role_id'));
    }

    /* ----- Import filters ----------------------------------------------- */

    /**
     * Build the Autotask ticket import filter spec from config.
     *
     * @return array<string,mixed>
     */
    public function importFilterSpec(): array
    {
        $spec = array();
        $complete = $this->completeStatus();

        $statusIds = $this->csvInts('import_status_ids');
        if ($statusIds) {
            $spec['status_op'] = 'in';
            $spec['status_value'] = $statusIds;
        } else {
            $open   = (bool) $this->config->get('import_include_open');
            $closed = (bool) $this->config->get('import_include_closed');
            if ($open && !$closed) {
                $spec['status_op'] = 'noteq'; $spec['status_value'] = $complete;
            } elseif ($closed && !$open) {
                $spec['status_op'] = 'eq'; $spec['status_value'] = $complete;
            } elseif (!$open && !$closed) {
                // Neither selected -> default to open/active.
                $spec['status_op'] = 'noteq'; $spec['status_value'] = $complete;
            }
            // both selected -> no status filter (all)
        }

        $spec['company_ids']  = $this->csvInts('import_company_ids');
        $spec['queue_ids']    = $this->csvInts('import_queue_ids');
        $spec['resource_ids'] = $this->csvInts('import_resource_ids');

        $days = (int) $this->config->get('import_since_days');
        if ($days > 0) {
            $spec['since_utc'] = gmdate('Y-m-d\TH:i:s\Z', time() - ($days * 86400));
        }
        return $spec;
    }

    /**
     * Parse a comma-separated config value into an array of ints.
     *
     * @param string $key
     * @return int[]
     */
    private function csvInts(string $key): array
    {
        $out = array();
        foreach (explode(',', (string) $this->config->get($key)) as $p) {
            $p = trim($p);
            if ($p !== '' && is_numeric($p)) {
                $out[] = (int) $p;
            }
        }
        return $out;
    }

    /* ----- Status mapping (single-dropdown design) ------------------------ */

    /**
     * Parse the per-client "osTicket Status Name=Autotask status id" map.
     *
     * @return array<string,int> lowercased osTicket status name => AT status id
     */
    public function statusMap(): array
    {
        $raw = (string) $this->config->get('status_map');
        $map = array();
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) {
                continue;
            }
            list($label, $id) = array_map('trim', explode('=', $line, 2));
            if ($label !== '' && is_numeric($id)) {
                $map[mb_strtolower($label)] = (int) $id;
            }
        }
        return $map;
    }

    /**
     * Reverse map for inbound sync. When several osTicket statuses share one
     * Autotask value, the FIRST line in the map wins.
     *
     * @return array<int,string> AT status id => lowercased osTicket status name
     */
    public function statusMapReverse(): array
    {
        $out = array();
        foreach ($this->statusMap() as $name => $atId) {
            if (!isset($out[$atId])) {
                $out[$atId] = $name;
            }
        }
        return $out;
    }

    /** Client display name (set for instance-bound settings; '' for legacy). */
    public function clientName(): string
    {
        return (string) $this->config->get('client_name');
    }

    /** Department chosen at client registration (0 = not set). */
    public function departmentId(): int
    {
        return (int) $this->config->get('department_id');
    }

    /**
     * Queue->department routing rules ("queueId=deptId" lines).
     *
     * @return array<int,int> Autotask queue id => osTicket department id.
     */
    public function deptMap(): array
    {
        $out = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) $this->config->get('dept_map')) as $line) {
            if (strpos($line, '=') === false) {
                continue;
            }
            list($q, $d) = array_map('trim', explode('=', $line, 2));
            if ($q !== '' && $d !== '' && ctype_digit($q) && ctype_digit($d)) {
                $out[(int) $q] = (int) $d;
            }
        }
        return $out;
    }

    /**
     * Department for a ticket from the given Autotask queue: the mapped
     * department when a routing rule exists (and the department is real),
     * else the registration fallback department.
     */
    public function departmentForQueue(int $queueId): int
    {
        if ($queueId > 0) {
            $map = $this->deptMap();
            if (isset($map[$queueId])) {
                $r = db_query('SELECT id FROM ' . TABLE_PREFIX . 'department WHERE id='
                    . (int) $map[$queueId] . ' LIMIT 1', false);
                if ($r && db_num_rows($r)) {
                    return $map[$queueId];
                }
            }
        }
        return $this->departmentId();
    }

    /* ----- Ticket closure ----------------------------------------------- */

    public function completeStatus(): int
    {
        return (int) ($this->config->get('complete_status') ?: 5);
    }

    public function requireTimeBeforeClose(): bool
    {
        return (bool) $this->config->get('require_time_before_close');
    }

    public function closeOsticketOnComplete(): bool
    {
        return (bool) $this->config->get('close_osticket_on_complete');
    }

    public function logRetentionDays(): int
    {
        return max(1, (int) ($this->config->get('log_retention_days') ?: 30));
    }

    /** @return \PluginConfig */
    public function raw()
    {
        return $this->config;
    }

    /* ----- Runtime KV store (autotask_settings table) -------------------- */

    /**
     * @param string $key
     * @param mixed  $default
     * @return mixed Decoded JSON value, or $default if absent.
     */
    public function state(string $key, $default = null)
    {
        $prefix = Installer::prefix();
        $k = db_input($this->statePrefix . $key);
        $res = db_query("SELECT svalue FROM `{$prefix}autotask_settings` WHERE skey=$k LIMIT 1");
        if ($res && ($row = db_fetch_array($res))) {
            $decoded = json_decode((string) $row['svalue'], true);
            return $decoded === null && $row['svalue'] !== 'null' ? $row['svalue'] : $decoded;
        }
        return $default;
    }

    /**
     * Upsert a runtime state value (stored as JSON).
     *
     * @param string $key
     * @param mixed  $value
     */
    public function setState(string $key, $value): void
    {
        $prefix = Installer::prefix();
        $k = db_input($this->statePrefix . $key);
        $v = db_input(json_encode($value));
        db_query(
            "INSERT INTO `{$prefix}autotask_settings` (skey, svalue, updated) "
            . "VALUES ($k, $v, NOW()) "
            . "ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated=NOW()"
        );
    }

    private function intOrNull($v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }
}
