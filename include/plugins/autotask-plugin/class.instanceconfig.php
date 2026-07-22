<?php
/**
 * Autotask Integration — per-instance config adapter.
 *
 * Presents a client Instance row through the same get()/set() interface as
 * osTicket's PluginConfig, so Settings (and every service built on it) works
 * unchanged whether it is driven by the legacy single-tenant plugin config or
 * by a registered client instance.
 *
 * Key resolution order:
 *   1. Credential keys        -> instance columns (secret decrypted).
 *   2. Engine-global keys     -> the real plugin config (shared by all clients:
 *                                master switch, intervals, logging, inline
 *                                time-capture field names).
 *   3. Everything else        -> the instance's config_json options.
 *
 * @package Autotask Integration
 */

namespace Autotask;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * PluginConfig-compatible view over one client instance.
 */
class InstanceConfig
{
    /** Keys that always come from the SHARED plugin config, never per client. */
    private const GLOBAL_KEYS = array(
        'enabled', 'sync_interval', 'batch_size', 'max_retries',
        'log_level', 'log_retention_days', 'drop_tables_on_uninstall',
        'hide_sla_view', 'email_customer_replies', 'default_internal_note',
        'collapse_ticket_info',
        'capture_time_enabled', 'field_time_spent', 'field_time_type',
        'field_billable', 'time_spent_unit', 'timetype_map',
        'schema_version',
    );

    /** @var Instance */       private $instance;
    /** @var \PluginConfig */  private $global;
    /** @var array<string,mixed> Decoded per-client options. */
    private $options;

    public function __construct(Instance $instance, $globalConfig)
    {
        $this->instance = $instance;
        $this->global   = $globalConfig;
        $this->options  = $instance->configAll();
    }

    /**
     * PluginConfig-compatible reader.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        switch ($key) {
            case 'api_username':
                return $this->instance->credentials()['username'];
            case 'api_secret':
                return $this->instance->credentials()['secret'];
            case 'api_integration_code':
                return $this->instance->credentials()['integration_code'];
            case 'api_zone_url':
                return $this->instance->credentials()['zone_url'];
            case 'department_id':
                return $this->instance->departmentId();
            case 'client_name':
                return $this->instance->name();
            case 'client_code':
                return $this->instance->code();
        }
        if (in_array($key, self::GLOBAL_KEYS, true)) {
            return $this->global ? $this->global->get($key, $default) : $default;
        }
        return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
    }

    /**
     * PluginConfig-compatible writer — delegates to the shared config (only
     * engine-level code writes config, e.g. the installer's schema_version).
     *
     * @param string $key
     * @param mixed  $value
     * @return bool
     */
    public function set($key, $value)
    {
        return $this->global ? (bool) $this->global->set($key, $value) : false;
    }

    public function instance(): Instance
    {
        return $this->instance;
    }
}
