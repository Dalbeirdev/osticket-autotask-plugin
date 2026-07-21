<?php
/**
 * Autotask Integration — Instance repository.
 *
 * All reads/writes of the `autotask_instance` table go through this class.
 * Secrets are encrypted before storage (see Instance::encryptSecret); a blank
 * secret on update keeps the stored value (same UX rule as the plugin config).
 *
 * @package Autotask Integration
 */

namespace Autotask;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * CRUD + seeding for client Autotask instances.
 */
class InstanceRepository
{
    /** Columns writable via create()/update(). Everything else is managed. */
    private const WRITABLE = array(
        'name', 'code', 'api_username', 'api_integration_code',
        'zone_url', 'web_base', 'department_id', 'config_json', 'enabled',
    );

    /* ----- Reads ---------------------------------------------------------- */

    /**
     * @return Instance[] All instances, enabled first, then by name.
     */
    public function all(): array
    {
        return $this->fetch('ORDER BY enabled DESC, name ASC');
    }

    /**
     * @return Instance[] Only enabled instances (sync loop input).
     */
    public function allEnabled(): array
    {
        return $this->fetch('WHERE enabled=1 ORDER BY id ASC');
    }

    public function find(int $id): ?Instance
    {
        $rows = $this->fetch('WHERE id=' . (int) $id . ' LIMIT 1');
        return $rows[0] ?? null;
    }

    public function findByCode(string $code): ?Instance
    {
        $c = db_input($code);
        $rows = $this->fetch("WHERE code=$c LIMIT 1");
        return $rows[0] ?? null;
    }

    public function count(): int
    {
        $prefix = Installer::prefix();
        $res = db_query("SELECT COUNT(*) c FROM `{$prefix}autotask_instance`");
        $row = $res ? db_fetch_array($res) : null;
        return (int) ($row['c'] ?? 0);
    }

    /* ----- Writes --------------------------------------------------------- */

    /**
     * Create an instance.
     *
     * @param array<string,mixed> $fields WRITABLE columns + optional 'api_secret'.
     * @return int New instance id (0 on failure).
     */
    public function create(array $fields): int
    {
        $prefix = Installer::prefix();
        $cols = array('created' => 'NOW()', 'updated' => 'NOW()');

        foreach (self::WRITABLE as $col) {
            if (array_key_exists($col, $fields)) {
                $cols[$col] = db_input($this->normalize($col, $fields[$col]));
            }
        }
        // Secret is encrypted; empty secret stored as ''.
        $secret = (string) ($fields['api_secret'] ?? '');
        $cols['api_secret'] = db_input(Instance::encryptSecret($secret));

        $names  = implode(',', array_map(function ($c) { return "`$c`"; }, array_keys($cols)));
        $values = implode(',', array_values($cols));
        if (!db_query("INSERT INTO `{$prefix}autotask_instance` ($names) VALUES ($values)")) {
            return 0;
        }
        return (int) db_insert_id();
    }

    /**
     * Update an instance. A blank/absent 'api_secret' keeps the stored secret.
     *
     * @param int                 $id
     * @param array<string,mixed> $fields
     * @return bool
     */
    public function update(int $id, array $fields): bool
    {
        $prefix = Installer::prefix();
        $sets = array('`updated`=NOW()');

        foreach (self::WRITABLE as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "`$col`=" . db_input($this->normalize($col, $fields[$col]));
            }
        }
        if (isset($fields['api_secret']) && (string) $fields['api_secret'] !== '') {
            $sets[] = '`api_secret`=' . db_input(Instance::encryptSecret((string) $fields['api_secret']));
        }

        $set = implode(',', $sets);
        return (bool) db_query(
            "UPDATE `{$prefix}autotask_instance` SET $set WHERE id=" . (int) $id
        );
    }

    public function setEnabled(int $id, bool $enabled): bool
    {
        return $this->update($id, array('enabled' => $enabled ? 1 : 0));
    }

    /**
     * Record the outcome of a sync/connection attempt (health cache used by
     * the Clients page cards).
     */
    public function touchSync(int $id, bool $ok): void
    {
        $prefix = Installer::prefix();
        db_query(
            "UPDATE `{$prefix}autotask_instance` "
            . 'SET last_sync_at=NOW(), last_ok=' . ($ok ? 1 : 0) . ', updated=NOW() '
            . 'WHERE id=' . (int) $id
        );
    }

    /**
     * Cache the resolved Autotask web-UI base (deep links) on the instance.
     */
    public function setWebBase(int $id, string $webBase): void
    {
        $this->update($id, array('web_base' => $webBase));
    }

    /**
     * Number of osTicket tickets mapped to this instance (guards delete).
     */
    public function mappedTickets(int $id): int
    {
        $prefix = Installer::prefix();
        $res = db_query(
            "SELECT COUNT(*) c FROM `{$prefix}autotask_ticket_map` WHERE instance_id=" . (int) $id
        );
        $row = $res ? db_fetch_array($res) : null;
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Delete an instance. Refused when any ticket is mapped to it — clients
     * with history must be disabled (offboarded), never deleted.
     *
     * @return bool True if the row was deleted.
     */
    public function delete(int $id): bool
    {
        if ($this->mappedTickets($id) > 0) {
            return false;
        }
        $prefix = Installer::prefix();
        return (bool) db_query(
            "DELETE FROM `{$prefix}autotask_instance` WHERE id=" . (int) $id . ' LIMIT 1'
        );
    }

    /* ----- Seeding (migration 0005 companion) ------------------------------ */

    /**
     * Seed instance #1 from the legacy single-tenant plugin config, exactly
     * once: only when the instance table is empty and credentials exist.
     * Keeps every pre-multi-tenant row (all defaulted to instance_id=1) working.
     *
     * @param \PluginConfig $config
     * @return bool True if a seed row was created.
     */
    public function seedFromConfig($config): bool
    {
        if (!Installer::tableExists('autotask_instance') || $this->count() > 0) {
            return false;
        }
        $username = (string) $config->get('api_username');
        if ($username === '') {
            return false; // nothing configured yet — nothing to seed
        }
        $id = $this->create(array(
            'name'                 => 'Primary Client',
            'code'                 => 'MAIN',
            'api_username'         => $username,
            'api_secret'           => (string) $config->get('api_secret'),
            'api_integration_code' => (string) $config->get('api_integration_code'),
            'zone_url'             => (string) $config->get('api_zone_url'),
            'department_id'        => 0, // chosen later in the Instance Manager UI
            'enabled'              => 1,
        ));
        return $id > 0;
    }

    /**
     * One-time backfill: copy the per-client option values from the legacy
     * single-tenant plugin config into instance #1's config_json. Runs only
     * while that config_json is still empty (i.e. seeded before options were
     * carried over), so admin edits are never overwritten.
     *
     * @param \PluginConfig $config
     * @return bool True if options were written.
     */
    public function backfillOptionsFromConfig($config): bool
    {
        $inst = $this->find(1);
        if (!$inst || $inst->configAll()) {
            return false; // no seed row, or options already present
        }
        $keys = array(
            'two_way_sync', 'auto_import_enabled', 'inbound_notes_enabled',
            'default_company_id', 'default_queue_id', 'default_priority',
            'default_status', 'default_ticket_type',
            'import_include_open', 'import_include_closed', 'import_status_ids',
            'import_company_ids', 'import_queue_ids', 'import_resource_ids',
            'import_since_days',
            'default_work_type_id', 'default_resource_id', 'default_role_id',
            'complete_status', 'require_time_before_close', 'close_osticket_on_complete',
        );
        $options = array();
        foreach ($keys as $k) {
            $v = $config->get($k);
            if ($v !== null && $v !== '') {
                $options[$k] = $v;
            }
        }
        if (!$options) {
            return false;
        }
        return $this->update(1, array('config_json' => $options));
    }

    /* ----- Internals -------------------------------------------------------- */

    /**
     * @param string $where SQL tail (WHERE/ORDER BY...), values pre-escaped.
     * @return Instance[]
     */
    private function fetch(string $where): array
    {
        $prefix = Installer::prefix();
        $out = array();
        $res = db_query("SELECT * FROM `{$prefix}autotask_instance` $where");
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $out[] = new Instance($row);
            }
        }
        return $out;
    }

    /**
     * Column-specific value normalisation before escaping.
     *
     * @param string $col
     * @param mixed  $value
     * @return mixed
     */
    private function normalize(string $col, $value)
    {
        switch ($col) {
            case 'enabled':
                return $value ? 1 : 0;
            case 'department_id':
                return (int) $value;
            case 'code':
                // Uppercased, alphanumeric, max 16 — used in badges/logs.
                return mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value)), 0, 16);
            case 'config_json':
                return is_array($value) ? json_encode($value) : (string) $value;
            default:
                return trim((string) $value);
        }
    }
}
