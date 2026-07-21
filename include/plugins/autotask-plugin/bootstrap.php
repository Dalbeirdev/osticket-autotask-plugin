<?php
/**
 * Autotask Integration — Bootstrap / Plugin entry class.
 *
 * Responsibilities:
 *  - Register a lightweight class autoloader for the plugin namespace.
 *  - Run idempotent database migrations on install / upgrade.
 *  - Connect osTicket Signals to the synchronization engine.
 *  - Drive the scheduler from the core `cron` signal.
 *  - Provide enable/disable/uninstall lifecycle hooks.
 *
 * @package Autotask Integration
 */

namespace Autotask;

// Core osTicket classes live in the global namespace.
use Plugin;
use Signal;

if (!defined('INCLUDE_DIR')) {
    // Guard: never executable outside of osTicket.
    die('Access denied');
}

require_once __DIR__ . '/config.php';

/**
 * Main plugin class. osTicket instantiates exactly one of these per enabled
 * plugin instance and calls bootstrap() once per request.
 */
class AutotaskPlugin extends Plugin
{
    /** @var string Fully-qualified config class (see config.php). */
    public $config_class = 'Autotask\\AutotaskPluginConfig';

    /** @var Services\Container|null Lazily-built service container. */
    private $container = null;

    /**
     * Register the PSR-style autoloader for the Autotask\ namespace.
     * Files are mapped class.<lower>.php to match osTicket conventions, with a
     * secondary lookup for sub-namespaced classes under their own folders.
     */
    private static function registerAutoloader(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        // Explicit overrides where the class name does not match the required
        // osTicket file name (class.<short>.php) one-to-one.
        $aliases = array(
            'Autotask\\SyncEngine'       => 'class.sync.php',
            'Autotask\\AutotaskApi'      => 'class.api.php',
            'Autotask\\PicklistService'  => 'class.picklist.php',
            'Autotask\\TimeEntryService' => 'class.timeentry.php',
        );

        spl_autoload_register(function (string $class) use ($aliases): void {
            if (strpos($class, 'Autotask\\') !== 0) {
                return;
            }
            $base = __DIR__;

            if (isset($aliases[$class])) {
                $file = $base . '/' . $aliases[$class];
                if (is_file($file)) {
                    require_once $file;
                }
                return;
            }

            $relative = substr($class, strlen('Autotask\\'));
            $parts = explode('\\', $relative);
            $short = array_pop($parts);

            // Candidate file names, in priority order.
            $candidates = array();

            // 1) class.<short>.php in the plugin root (osTicket idiom).
            $candidates[] = $base . '/class.' . strtolower($short) . '.php';

            // 2) Sub-namespaced: Autotask\Services\Container ->
            //    services/class.container.php
            if ($parts) {
                $dir = strtolower(implode('/', $parts));
                $candidates[] = $base . '/' . $dir . '/class.' . strtolower($short) . '.php';
            }

            foreach ($candidates as $file) {
                if (is_file($file)) {
                    require_once $file;
                    return;
                }
            }
        });
    }

    /**
     * Called once per request after the plugin is loaded and enabled.
     * Wires all integration points. Must never throw — a failure here would
     * break the host application.
     */
    /**
     * The plugin's REAL config, resilient to side-loaded contexts.
     *
     * osTicket's Plugin::getConfig() returns an EMPTY, namespace-less config
     * when called without a PluginInstance (as happens from our scp loaders,
     * cron.php and CLI scripts). Detect that and re-resolve through the
     * plugin's instance row so global settings always read correctly.
     *
     * @return \PluginConfig
     */
    public function globalConfig()
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }
        $c = $this->getConfig();
        // A populated namespace always has schema_version (set by installer).
        if ($c && $c->get('schema_version')) {
            return $resolved = $c;
        }
        try {
            if (class_exists('PluginInstance')) {
                $inst = \PluginInstance::objects()
                    ->filter(array('plugin_id' => $this->getId()))
                    ->first();
                if ($inst) {
                    $ic = $this->getConfig($inst);
                    if ($ic) {
                        return $resolved = $ic;
                    }
                }
            }
        } catch (\Throwable $e) {
            // fall through to whatever getConfig returned
        }
        return $resolved = $c;
    }

    public function bootstrap(): void
    {
        try {
            self::registerAutoloader();

            $config = $this->globalConfig();

            // Ensure schema is present / up to date (cheap version compare).
            Installer::ensureSchema($config);

            // Register custom agent permissions (Roles UI).
            Rbac::register();

            // Build the service container bound to this plugin instance.
            $this->container = new Services\Container($this, $config);

            // Only wire live synchronization if the integration is enabled and
            // appears configured (legacy credentials OR at least one enabled
            // client instance). Keeps a half-configured plugin inert.
            if ($config->get('enabled')
                && (Settings::isConfigured($config) || $this->hasEnabledInstances())) {
                $this->connectSignals();
            }

            // The cron signal must always be connected so the retry queue and
            // scheduler keep running even while live sync is paused.
            Signal::connect('cron', array($this, 'onCron'));
        } catch (\Throwable $e) {
            // Last-resort guard: log and swallow so osTicket keeps working.
            $this->safeLog('Bootstrap failure: ' . $e->getMessage());
        }
    }

    /**
     * Connect osTicket domain Signals to handler methods.
     */
    private function connectSignals(): void
    {
        // New ticket created in osTicket -> create Autotask ticket.
        Signal::connect('ticket.created', array($this, 'onTicketCreated'));

        // Thread entries: replies (response) and internal notes.
        Signal::connect('threadentry.created', array($this, 'onThreadEntry'));

        // ORM model updates: status / priority / closure / reopen.
        Signal::connect('model.updated', array($this, 'onModelUpdated'));
    }

    /* -------------------------------------------------------------------- */
    /* Signal handlers — all delegate to the sync engine and never throw.    */
    /* -------------------------------------------------------------------- */

    /**
     * @param mixed $ticket  \Ticket instance.
     */
    public function onTicketCreated($ticket): void
    {
        $this->guard(function () use ($ticket) {
            // A brand-new osTicket ticket has no mapping yet, so the tenant
            // cannot be derived. With exactly one enabled client we route to
            // it (legacy behaviour); with several, outbound creation waits for
            // an explicit Client selection (Module 4).
            $containers = $this->instanceContainers();
            if (count($containers) === 1) {
                $containers[0]->sync()->onOsticketTicketCreated($ticket);
            } elseif (count($containers) > 1) {
                $this->safeLog('Ticket create not auto-synced: multiple clients registered '
                    . 'and no client selected (Module 4 adds the Client field).');
            }
        }, 'onTicketCreated');
    }

    /**
     * @param mixed $entry  \ThreadEntry instance (MessageThreadEntry,
     *                      ResponseThreadEntry or NoteThreadEntry).
     */
    public function onThreadEntry($entry): void
    {
        $this->guard(function () use ($entry) {
            // Route to the client instance the ticket is mapped to — replies
            // and captured time must reach the EXACT tenant, never another.
            $c = $this->containerForTicket($this->ticketIdFromEntry($entry)) ?: $this->container;
            // Sync the note/reply to Autotask...
            $c->sync()->onOsticketThreadEntry($entry);
            // ...and capture any time logged on the reply/note form.
            $c->timeEntry()->captureFromThreadEntry($entry);
        }, 'onThreadEntry');
    }

    /**
     * @param mixed $model  Updated \VerySimpleModel (filtered to \Ticket).
     * @param mixed $data   Signal payload; typically contains 'dirty'.
     */
    public function onModelUpdated($model, $data = null): void
    {
        if (!($model instanceof \Ticket)) {
            return;
        }
        $this->guard(function () use ($model, $data) {
            $c = $this->containerForTicket((int) $model->getId()) ?: $this->container;
            $c->sync()->onOsticketTicketUpdated($model, $data);
        }, 'onModelUpdated');
    }

    /**
     * Core cron tick. osTicket fires this from api/cron.php / scheduled cron.
     * We throttle internally so it is safe to call frequently.
     *
     * @param mixed $ignored
     * @param mixed $data
     */
    /** Max seconds one cron tick may spend across ALL clients (hardening). */
    private const CRON_BUDGET_SECONDS = 55;

    public function onCron($ignored = null, $data = null): void
    {
        // Every enabled client instance gets its own tick; one failing tenant
        // must never stop the others, and a shared time budget stops one slow
        // tenant from starving the rest (skipped tenants catch up next tick).
        $started = time();
        foreach ($this->instanceContainers() as $c) {
            if ((time() - $started) > self::CRON_BUDGET_SECONDS) {
                $this->safeLog('Cron budget reached; remaining clients deferred to next tick.');
                break;
            }
            $this->guard(function () use ($c) {
                $c->scheduler()->tick();
                // Refresh the health cache shown on the Clients page cards.
                if ($c->instance()) {
                    $c->instanceRepository()->touchSync($c->instanceId(), true);
                }
            }, 'onCron[i' . $c->instanceId() . ']');
        }
    }

    /* -------------------------------------------------------------------- */
    /* Multi-tenant routing                                                  */
    /* -------------------------------------------------------------------- */

    /**
     * Containers for every enabled client instance. Falls back to the legacy
     * single container when the instance register is absent/empty (pre-v2 or
     * mid-upgrade), preserving v1 behaviour exactly.
     *
     * @return Services\Container[]
     */
    public function instanceContainers(): array
    {
        try {
            $repo = $this->getContainer()->instanceRepository();
            $out = array();
            foreach ($repo->allEnabled() as $i) {
                $out[] = Services\Container::forInstance($this, $this->globalConfig(), $i);
            }
            if ($out) {
                return $out;
            }
        } catch (\Throwable $e) {
            // fall through to legacy container
        }
        return array($this->getContainer());
    }

    /**
     * Container bound to the client instance a ticket is mapped to.
     *
     * @param int|null $ticketId osTicket ticket id.
     * @return Services\Container|null null when unmapped/unknown.
     */
    public function containerForTicket(?int $ticketId): ?Services\Container
    {
        if (!$ticketId) {
            return null;
        }
        try {
            $map = $this->getContainer()->mapper()->findByOsticketId($ticketId);
            if (!$map) {
                return null;
            }
            return $this->getContainerFor((int) ($map['instance_id'] ?? 1));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Container for an instance id; legacy/default container when the id is
     * unknown or the register is unavailable.
     */
    public function getContainerFor(?int $instanceId): Services\Container
    {
        if ($instanceId) {
            try {
                $i = $this->getContainer()->instanceRepository()->find($instanceId);
                if ($i) {
                    return Services\Container::forInstance($this, $this->globalConfig(), $i);
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }
        return $this->getContainer();
    }

    /**
     * True when at least one client instance is registered and enabled.
     */
    private function hasEnabledInstances(): bool
    {
        try {
            return count($this->getContainer()->instanceRepository()->allEnabled()) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param mixed $entry ThreadEntry.
     * @return int|null osTicket ticket id the entry belongs to.
     */
    private function ticketIdFromEntry($entry): ?int
    {
        try {
            if (is_object($entry) && method_exists($entry, 'getThread')) {
                $thread = $entry->getThread();
                if ($thread && method_exists($thread, 'getObjectId')
                    && method_exists($thread, 'getObjectType')
                    && $thread->getObjectType() === 'T') {
                    return (int) $thread->getObjectId();
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }

    /* -------------------------------------------------------------------- */
    /* Lifecycle                                                            */
    /* -------------------------------------------------------------------- */

    /**
     * Called by osTicket when the admin enables the plugin instance.
     */
    public function enable()
    {
        self::registerAutoloader();
        Installer::ensureSchema($this->getConfig());
        return parent::enable();
    }

    /**
     * Called by osTicket when the plugin is uninstalled. We DO NOT drop data by
     * default to avoid catastrophic accidental loss; dropping is opt-in via the
     * config flag `drop_tables_on_uninstall`.
     */
    public function uninstall(&$errors)
    {
        self::registerAutoloader();
        try {
            if ($this->getConfig()->get('drop_tables_on_uninstall')) {
                Installer::dropSchema();
            }
        } catch (\Throwable $e) {
            $this->safeLog('Uninstall cleanup failed: ' . $e->getMessage());
        }
        return parent::uninstall($errors);
    }

    /**
     * Single-instance plugin (one Autotask tenant per osTicket install).
     */
    public function isMultiInstance()
    {
        return false;
    }

    /**
     * Expose the container to admin pages that include bootstrap.php.
     */
    public function getContainer(): Services\Container
    {
        if ($this->container === null) {
            self::registerAutoloader();
            $this->container = new Services\Container($this, $this->globalConfig());
        }
        return $this->container;
    }

    /* -------------------------------------------------------------------- */
    /* Helpers                                                              */
    /* -------------------------------------------------------------------- */

    /**
     * Run a callable, catching every Throwable so a sync failure can never
     * surface as a fatal error inside osTicket request handling.
     */
    private function guard(callable $fn, string $where): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->safeLog(sprintf('%s failed: %s', $where, $e->getMessage()));
        }
    }

    /**
     * Best-effort logging that works even before the container is built.
     */
    private function safeLog(string $message): void
    {
        try {
            if ($this->container) {
                $this->container->logger()->error($message);
                return;
            }
        } catch (\Throwable $e) {
            // fall through
        }
        // Fallback to PHP error log.
        error_log('[Autotask] ' . $message);
    }
}
