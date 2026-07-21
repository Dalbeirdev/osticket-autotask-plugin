<?php
/**
 * Autotask Integration — structured logger.
 *
 * Writes to the autotask_log table (queryable from the dashboard) and mirrors
 * warnings/errors into osTicket's system log. Request/response payloads are
 * captured at debug level and redacted of secrets.
 *
 * @package Autotask Integration
 */

namespace Autotask;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Level-aware logger with payload redaction and retention pruning.
 */
class Logger
{
    /** Numeric ordering for level threshold comparisons. */
    private const LEVELS = array('debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40);

    /** @var string Minimum level to persist. */
    private $threshold;

    /** @var int|null Client instance rows are tagged with / filtered by. */
    private $instanceId;

    /**
     * @param string   $threshold  Minimum level to persist.
     * @param int|null $instanceId Bind to one client (null = global view;
     *                             writes then tag as legacy instance #1).
     */
    public function __construct(string $threshold = 'info', ?int $instanceId = null)
    {
        $this->threshold = isset(self::LEVELS[$threshold]) ? $threshold : 'info';
        $this->instanceId = $instanceId;
    }

    public function debug(string $msg, array $ctx = array()): void   { $this->log('debug', $msg, $ctx); }
    public function info(string $msg, array $ctx = array()): void    { $this->log('info', $msg, $ctx); }
    public function warning(string $msg, array $ctx = array()): void { $this->log('warning', $msg, $ctx); }
    public function error(string $msg, array $ctx = array()): void   { $this->log('error', $msg, $ctx); }

    /**
     * Core write. Never throws — logging must not break the caller.
     *
     * @param string $level
     * @param string $message
     * @param array  $ctx  Optional keys: category, osticket_ticket_id,
     *                      autotask_ticket_id, request, response.
     */
    public function log(string $level, string $message, array $ctx = array()): void
    {
        try {
            if (self::LEVELS[$level] < self::LEVELS[$this->threshold]) {
                return;
            }
            $prefix = Installer::prefix();

            $category = db_input((string) ($ctx['category'] ?? 'general'));
            $level_in = db_input($level);
            $msg      = db_input($this->truncate($message, 4000));
            $ostId    = isset($ctx['osticket_ticket_id']) ? (int) $ctx['osticket_ticket_id'] : 'NULL';
            $atId     = isset($ctx['autotask_ticket_id']) ? (int) $ctx['autotask_ticket_id'] : 'NULL';

            // Only persist payloads at debug level to control table growth.
            $request  = 'NULL';
            $response = 'NULL';
            if ($this->threshold === 'debug') {
                if (isset($ctx['request'])) {
                    $request = db_input($this->truncate($this->redact($ctx['request']), 60000));
                }
                if (isset($ctx['response'])) {
                    $response = db_input($this->truncate($this->redact($ctx['response']), 60000));
                }
            }

            $iid = (int) ($this->instanceId ?: 1);
            db_query(
                "INSERT INTO `{$prefix}autotask_log` "
                . "(instance_id, level, category, osticket_ticket_id, autotask_ticket_id, message, request, response, created) "
                . "VALUES ($iid, $level_in, $category, $ostId, $atId, $msg, $request, $response, NOW())"
            );

            // Mirror serious events into osTicket's own log for admins.
            if (isset($GLOBALS['ost']) && self::LEVELS[$level] >= self::LEVELS['warning']) {
                if ($level === 'error') {
                    $GLOBALS['ost']->logError('Autotask', $message, false);
                } else {
                    $GLOBALS['ost']->logWarning('Autotask', $message, false);
                }
            }
        } catch (\Throwable $e) {
            error_log('[Autotask][Logger] ' . $e->getMessage());
        }
    }

    /**
     * Delete log rows older than the retention window.
     *
     * @return int Rows deleted.
     */
    public function prune(int $retentionDays): int
    {
        $prefix = Installer::prefix();
        $days = max(1, $retentionDays);
        db_query("DELETE FROM `{$prefix}autotask_log` WHERE created < (NOW() - INTERVAL $days DAY)");
        return (int) db_affected_rows();
    }

    /**
     * Fetch recent log rows for the dashboard (paginated).
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 100, int $offset = 0, ?string $level = null): array
    {
        $prefix = Installer::prefix();
        $limit  = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $conds = array();
        if ($level && isset(self::LEVELS[$level])) {
            $conds[] = 'level=' . db_input($level);
        }
        if ($this->instanceId) {
            $conds[] = 'instance_id=' . (int) $this->instanceId;
        }
        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $rows = array();
        $res = db_query(
            "SELECT id, level, category, osticket_ticket_id, autotask_ticket_id, message, created "
            . "FROM `{$prefix}autotask_log` $where ORDER BY id DESC LIMIT $limit OFFSET $offset"
        );
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Stream the full log as a downloadable CSV. Caller is responsible for the
     * surrounding admin auth/CSRF check.
     */
    public function exportCsv(): string
    {
        $prefix = Installer::prefix();
        $out = "id,created,level,category,osticket_ticket_id,autotask_ticket_id,message\n";
        $res = db_query(
            "SELECT id, created, level, category, osticket_ticket_id, autotask_ticket_id, message "
            . "FROM `{$prefix}autotask_log` ORDER BY id DESC LIMIT 50000"
        );
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $out .= sprintf(
                    "%d,%s,%s,%s,%s,%s,\"%s\"\n",
                    $row['id'], $row['created'], $row['level'], $row['category'],
                    $row['osticket_ticket_id'], $row['autotask_ticket_id'],
                    str_replace('"', '""', (string) $row['message'])
                );
            }
        }
        return $out;
    }

    /**
     * Redact secrets from a payload before persisting.
     *
     * @param mixed $payload
     */
    private function redact($payload): string
    {
        $str = is_string($payload) ? $payload : json_encode($payload);
        $patterns = array(
            '/("?Secret"?\s*[:=]\s*")[^"]*(")/i'           => '$1***$2',
            '/("?ApiIntegrationcode"?\s*[:=]\s*")[^"]*(")/i' => '$1***$2',
            '/(Secret:\s*)\S+/i'                            => '$1***',
        );
        return preg_replace(array_keys($patterns), array_values($patterns), (string) $str);
    }

    private function truncate(string $s, int $max): string
    {
        return strlen($s) > $max ? substr($s, 0, $max) . '…[truncated]' : $s;
    }
}
