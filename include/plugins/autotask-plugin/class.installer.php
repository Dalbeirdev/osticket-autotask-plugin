<?php
/**
 * Autotask Integration — schema installer / migration runner.
 *
 * Applies numbered SQL migrations from /migrations idempotently. Applied
 * migrations are tracked in an internal table so upgrades are incremental.
 *
 * @package Autotask Integration
 */

namespace Autotask;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Static helpers for installing, upgrading and removing the plugin schema.
 */
class Installer
{
    /** Bump when shipping new migration files (cheap upgrade detector). */
    const SCHEMA_VERSION = '2.1.3';

    /**
     * Ensure the schema exists and all migrations are applied.
     * Guarded by a config flag compare so the (cheap) fast-path is just a
     * string comparison on most requests.
     *
     * @param \PluginConfig $config
     */
    public static function ensureSchema($config): void
    {
        // Fast path: version matches AND a core table is actually present.
        // (Checking table existence guards against a config version that is set
        // while the tables were dropped or never created correctly.)
        if ($config->get('schema_version') === self::SCHEMA_VERSION
            && self::tableExists('autotask_ticket_map')) {
            return;
        }

        self::ensureMigrationsTable();
        self::runPending();

        // Multi-tenant (v2): seed instance #1 from the legacy single-tenant
        // config exactly once, so existing installs keep working untouched;
        // then backfill its per-client options from the same config.
        try {
            $repo = new InstanceRepository();
            $repo->seedFromConfig($config);
            $repo->backfillOptionsFromConfig($config);
        } catch (\Throwable $e) {
            error_log('[Autotask] Instance seed failed: ' . $e->getMessage());
        }

        // Record version on the plugin config to skip the work next request.
        $config->set('schema_version', self::SCHEMA_VERSION);
    }

    /**
     * @param string $unprefixed Table name without the osTicket prefix.
     * @return bool True if the table exists in the current database.
     */
    public static function tableExists(string $unprefixed): bool
    {
        $prefix = self::prefix();
        $name = db_input($prefix . $unprefixed);
        $res = db_query("SHOW TABLES LIKE $name");
        return (bool) ($res && db_num_rows($res) > 0);
    }

    /**
     * Create the bookkeeping table that records which migrations have run.
     */
    private static function ensureMigrationsTable(): void
    {
        $prefix = self::prefix();
        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}autotask_migrations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `filename` VARCHAR(191) NOT NULL,
            `applied_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_filename` (`filename`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        db_query($sql);
    }

    /**
     * Apply any migration file in /migrations that has not yet been recorded.
     */
    private static function runPending(): void
    {
        $applied = self::appliedMigrations();
        foreach (self::migrationFiles() as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            self::applyFile($file);
            self::recordMigration($name);
        }
    }

    /**
     * @return string[] Absolute paths to *.sql migration files, sorted.
     */
    private static function migrationFiles(): array
    {
        $files = glob(__DIR__ . '/migrations/*.sql') ?: array();
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * @return string[] Filenames already applied.
     */
    private static function appliedMigrations(): array
    {
        $prefix = self::prefix();
        $out = array();
        $res = db_query("SELECT filename FROM `{$prefix}autotask_migrations`");
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $out[] = $row['filename'];
            }
        }
        return $out;
    }

    /**
     * Execute every statement in a migration file. Statements are split on
     * semicolons at end-of-line — adequate for our DDL (no procedures/triggers).
     */
    private static function applyFile(string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException('Cannot read migration: ' . $file);
        }
        $sql = str_replace('%PREFIX%', self::prefix(), $sql);

        foreach (self::splitStatements($sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || strpos($stmt, '--') === 0) {
                continue;
            }
            if (!db_query($stmt, false)) {
                throw new \RuntimeException(
                    'Migration statement failed in ' . basename($file) . ': ' . db_error()
                );
            }
        }
    }

    /**
     * Split a SQL script into individual statements, ignoring comment lines.
     *
     * @return string[]
     */
    private static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $sql);
        $clean = array();
        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line)) {
                continue; // drop comment lines
            }
            $clean[] = $line;
        }
        return array_filter(explode(';', implode("\n", $clean)), function ($s) {
            return trim($s) !== '';
        });
    }

    /**
     * @param string $name Migration filename.
     */
    private static function recordMigration(string $name): void
    {
        $prefix = self::prefix();
        $name = db_input($name);
        db_query("INSERT IGNORE INTO `{$prefix}autotask_migrations` "
            . "(`filename`, `applied_at`) VALUES ($name, NOW())");
    }

    /**
     * Drop every plugin table (destructive). Only called from uninstall when
     * the admin has explicitly opted in.
     */
    public static function dropSchema(): void
    {
        $prefix = self::prefix();
        $tables = array(
            'autotask_instance',
            'autotask_conflict',
            'autotask_import_filter',
            'autotask_audit',
            'autotask_picklist_cache',
            'autotask_time_entry',
            'autotask_note_map',
            'autotask_sync_history',
            'autotask_log',
            'autotask_sync_queue',
            'autotask_ticket_map',
            'autotask_settings',
            'autotask_migrations',
        );
        // Disable FK checks defensively in case of future relations.
        db_query('SET FOREIGN_KEY_CHECKS=0', false);
        foreach ($tables as $t) {
            db_query("DROP TABLE IF EXISTS `{$prefix}{$t}`", false);
        }
        db_query('SET FOREIGN_KEY_CHECKS=1', false);
    }

    /**
     * @return string osTicket table prefix (e.g. "ost_").
     */
    public static function prefix(): string
    {
        return defined('TABLE_PREFIX') ? TABLE_PREFIX : 'ost_';
    }
}
