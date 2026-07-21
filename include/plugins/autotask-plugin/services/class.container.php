<?php
/**
 * Autotask Integration — service container.
 *
 * Lightweight lazy DI container. Each service is constructed on first use and
 * cached for the request, with dependencies wired explicitly (no magic).
 *
 * @package Autotask Integration
 */

namespace Autotask\Services;

use Autotask\Settings;
use Autotask\Logger;
use Autotask\Instance;
use Autotask\InstanceConfig;
use Autotask\InstanceRepository;
use Autotask\AutotaskApi;
use Autotask\Queue;
use Autotask\Ticket;
use Autotask\SyncEngine;
use Autotask\Scheduler;
use Autotask\Audit;
use Autotask\PicklistService;
use Autotask\TimeEntryService;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Builds and caches the plugin's collaborating services.
 */
class Container
{
    /** @var \Plugin */        private $plugin;
    /** @var \PluginConfig */  private $config;
    /** @var Instance|null Client tenant this container is bound to (null = legacy/global). */
    private $instance;

    /** @var array<string,object> */
    private $instances = array();

    /** @var array<int,Container> Per-request cache of instance-bound containers. */
    private static $registry = array();

    public function __construct($plugin, $config, ?Instance $instance = null)
    {
        $this->plugin   = $plugin;
        $this->config   = $config;
        $this->instance = $instance;
    }

    /**
     * Build (or reuse) a container bound to one client instance. Its services
     * read that client's credentials/options and tag all writes with its id.
     *
     * @param \Plugin       $plugin
     * @param \PluginConfig $globalConfig The real plugin config (shared keys).
     * @param Instance      $i
     */
    public static function forInstance($plugin, $globalConfig, Instance $i): Container
    {
        $id = $i->id();
        if (!isset(self::$registry[$id])) {
            self::$registry[$id] = new self($plugin, new InstanceConfig($i, $globalConfig), $i);
        }
        return self::$registry[$id];
    }

    /** Client instance id all writes are tagged with (legacy default = 1). */
    public function instanceId(): int
    {
        return $this->instance ? $this->instance->id() : 1;
    }

    /** @return Instance|null */
    public function instance(): ?Instance
    {
        return $this->instance;
    }

    /** @return \Plugin */
    public function plugin()
    {
        return $this->plugin;
    }

    /** @return \PluginConfig|InstanceConfig */
    public function config()
    {
        return $this->config;
    }

    public function settings(): Settings
    {
        if (!isset($this->instances['settings'])) {
            // Instance #1 keeps the legacy (unprefixed) state keys so existing
            // sync cursors survive the multi-tenant upgrade; other instances
            // get their own namespace.
            $prefix = ($this->instance && $this->instance->id() > 1)
                ? 'i' . $this->instance->id() . ':' : '';
            $this->instances['settings'] = new Settings($this->config, $prefix);
        }
        return $this->instances['settings'];
    }

    public function logger(): Logger
    {
        return $this->instances['logger']
            ?? ($this->instances['logger'] = new Logger(
                $this->settings()->logLevel(),
                $this->instance ? $this->instance->id() : null
            ));
    }

    public function api(): AutotaskApi
    {
        return $this->instances['api']
            ?? ($this->instances['api'] = new AutotaskApi($this->settings()->credentials(), $this->logger()));
    }

    public function queue(): Queue
    {
        // Instance-bound: claims/counters scoped to the tenant. Legacy/global
        // container: unscoped view (dashboard aggregate); writes tag as #1.
        return $this->instances['queue']
            ?? ($this->instances['queue'] = new Queue($this->instance ? $this->instance->id() : null));
    }

    public function mapper(): Ticket
    {
        return $this->instances['mapper']
            ?? ($this->instances['mapper'] = new Ticket(
                $this->settings(),
                $this->instance ? $this->instance->id() : null
            ));
    }

    public function sync(): SyncEngine
    {
        return $this->instances['sync']
            ?? ($this->instances['sync'] = new SyncEngine(
                $this->settings(),
                $this->queue(),
                $this->mapper(),
                $this->api(),
                $this->logger()
            ));
    }

    public function audit(): Audit
    {
        return $this->instances['audit']
            ?? ($this->instances['audit'] = new Audit($this->instance ? $this->instance->id() : null));
    }

    public function picklists(): PicklistService
    {
        return $this->instances['picklists']
            ?? ($this->instances['picklists'] = new PicklistService($this->api(), $this->logger(), $this->instanceId()));
    }

    public function timeEntry(): TimeEntryService
    {
        return $this->instances['timeEntry']
            ?? ($this->instances['timeEntry'] = new TimeEntryService(
                $this->settings(),
                $this->queue(),
                $this->api(),
                $this->picklists(),
                $this->mapper(),
                $this->logger(),
                $this->audit()
            ));
    }

    /**
     * Repository of client Autotask instances (multi-tenant, schema v2).
     */
    public function instanceRepository(): InstanceRepository
    {
        return $this->instances['instanceRepository']
            ?? ($this->instances['instanceRepository'] = new InstanceRepository());
    }

    public function scheduler(): Scheduler
    {
        return $this->instances['scheduler']
            ?? ($this->instances['scheduler'] = new Scheduler(
                $this->settings(),
                $this->queue(),
                $this->sync(),
                $this->logger(),
                $this->timeEntry()
            ));
    }
}
