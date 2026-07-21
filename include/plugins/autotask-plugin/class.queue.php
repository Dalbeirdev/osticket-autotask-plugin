<?php
/**
 * Autotask Integration — outbound sync / retry queue.
 *
 * Durable queue of osTicket->Autotask operations. Failed jobs are retried with
 * exponential backoff up to a configurable cap, after which they are marked
 * "dead" for manual intervention from the dashboard.
 *
 * @package Autotask Integration
 */

namespace Autotask;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Repository + lifecycle for queued synchronization jobs.
 */
class Queue
{
    /** Base backoff seconds; doubled each attempt, capped at one hour. */
    private const BACKOFF_BASE = 60;
    private const BACKOFF_CAP  = 3600;

    /** @var int|null Client instance this queue view is bound to (null = all). */
    private $instanceId;

    /**
     * @param int|null $instanceId Bind reads/writes to one client instance.
     *                             null = global view (dashboard aggregate,
     *                             legacy single-tenant path writes as #1).
     */
    public function __construct(?int $instanceId = null)
    {
        $this->instanceId = $instanceId;
    }

    /** WHERE fragment scoping a query to the bound instance ('' when global). */
    private function scope(string $andOrWhere = 'AND'): string
    {
        return $this->instanceId ? " $andOrWhere instance_id=" . (int) $this->instanceId . ' ' : '';
    }

    /**
     * Enqueue a job. A dedupe key prevents piling up identical pending work
     * (e.g. repeated status changes collapse to one pending update).
     *
     * @param string      $entityType ticket|note|reply|status|priority
     * @param string      $action     create|update|append|import
     * @param int|null    $osticketTicketId
     * @param array       $payload
     * @param string|null $dedupeKey
     * @param string      $direction  to_autotask|to_osticket
     * @return int Job id (0 if deduped onto an existing pending row).
     */
    public function enqueue(string $entityType, string $action, ?int $osticketTicketId, array $payload, ?string $dedupeKey = null, string $direction = 'to_autotask'): int
    {
        $prefix = Installer::prefix();
        $iid    = (int) ($this->instanceId ?: 1);
        $etype  = db_input($entityType);
        $dir    = db_input($direction === 'to_osticket' ? 'to_osticket' : 'to_autotask');
        $act    = db_input($action);
        $tid    = $osticketTicketId ? (int) $osticketTicketId : 'NULL';
        $pl     = db_input(json_encode($payload));
        // Dedupe keys are namespaced per instance: the same Autotask entity id
        // can legitimately exist in two different tenants.
        $dk     = $dedupeKey ? db_input('i' . $iid . ':' . $dedupeKey) : 'NULL';

        // INSERT ... ON DUPLICATE KEY: collapse duplicate pending jobs and reset
        // them to pending so the latest intent is retried promptly.
        db_query(
            "INSERT INTO `{$prefix}autotask_sync_queue` "
            . "(instance_id, osticket_ticket_id, entity_type, direction, action, payload, status, attempts, next_attempt_at, dedupe_key, created, updated) "
            . "VALUES ($iid, $tid, $etype, $dir, $act, $pl, 'pending', 0, NOW(), $dk, NOW(), NOW()) "
            . "ON DUPLICATE KEY UPDATE payload=VALUES(payload), status='pending', "
            . "next_attempt_at=NOW(), last_error=NULL, updated=NOW()"
        );
        return (int) db_insert_id();
    }

    /**
     * Atomically claim a batch of due jobs by flipping them to 'processing'.
     * Uses a short-lived marker update to reduce double-processing across
     * overlapping cron runs.
     *
     * @param int $limit
     * @return array<int,array<string,mixed>>
     */
    public function claimBatch(int $limit): array
    {
        $prefix = Installer::prefix();
        $limit  = max(1, min(500, $limit));

        // Select candidate ids first (avoids UPDATE...ORDER BY...LIMIT quirks).
        $ids = array();
        $res = db_query(
            "SELECT id FROM `{$prefix}autotask_sync_queue` "
            . "WHERE status IN ('pending','failed') AND next_attempt_at <= NOW() "
            . $this->scope()
            . "ORDER BY next_attempt_at ASC, id ASC LIMIT $limit"
        );
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $ids[] = (int) $row['id'];
            }
        }
        if (!$ids) {
            return array();
        }
        $idList = implode(',', array_map('intval', $ids));
        db_query("UPDATE `{$prefix}autotask_sync_queue` SET status='processing', updated=NOW() WHERE id IN ($idList)");

        $jobs = array();
        $res = db_query("SELECT * FROM `{$prefix}autotask_sync_queue` WHERE id IN ($idList)");
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $row['payload'] = json_decode((string) $row['payload'], true) ?: array();
                $jobs[] = $row;
            }
        }
        return $jobs;
    }

    /**
     * Mark a job completed.
     */
    public function markDone(int $id): void
    {
        $prefix = Installer::prefix();
        $id = (int) $id;
        db_query("UPDATE `{$prefix}autotask_sync_queue` SET status='done', updated=NOW() WHERE id=$id");
    }

    /**
     * Mark a job failed, scheduling the next attempt with exponential backoff,
     * or marking it dead once the retry ceiling is reached.
     */
    public function markFailed(int $id, string $error, int $maxRetries): void
    {
        $prefix = Installer::prefix();
        $id  = (int) $id;
        $err = db_input(substr($error, 0, 2000));

        // Read current attempt count.
        $attempts = 0;
        $res = db_query("SELECT attempts FROM `{$prefix}autotask_sync_queue` WHERE id=$id LIMIT 1");
        if ($res && ($r = db_fetch_array($res))) {
            $attempts = (int) $r['attempts'] + 1;
        }

        if ($attempts > $maxRetries) {
            db_query(
                "UPDATE `{$prefix}autotask_sync_queue` "
                . "SET status='dead', attempts=$attempts, last_error=$err, updated=NOW() WHERE id=$id"
            );
            return;
        }

        $delay = (int) min(self::BACKOFF_CAP, self::BACKOFF_BASE * (2 ** ($attempts - 1)));
        db_query(
            "UPDATE `{$prefix}autotask_sync_queue` "
            . "SET status='failed', attempts=$attempts, last_error=$err, "
            . "next_attempt_at=(NOW() + INTERVAL $delay SECOND), updated=NOW() WHERE id=$id"
        );
    }

    /**
     * Mark a job permanently dead (non-retryable error) — no backoff retries.
     */
    public function markDead(int $id, string $error): void
    {
        $prefix = Installer::prefix();
        $id  = (int) $id;
        $err = db_input(substr($error, 0, 2000));
        db_query(
            "UPDATE `{$prefix}autotask_sync_queue` "
            . "SET status='dead', attempts=attempts+1, last_error=$err, updated=NOW() WHERE id=$id"
        );
    }

    /**
     * Manually re-queue a job immediately (dashboard "Retry" button).
     */
    public function retryNow(int $id): void
    {
        $prefix = Installer::prefix();
        $id = (int) $id;
        db_query(
            "UPDATE `{$prefix}autotask_sync_queue` "
            . "SET status='pending', next_attempt_at=NOW(), last_error=NULL, updated=NOW() WHERE id=$id"
        );
    }

    /**
     * Requeue every dead/failed job (bulk manual retry).
     *
     * @return int Rows affected.
     */
    public function retryAllFailed(): int
    {
        $prefix = Installer::prefix();
        db_query(
            "UPDATE `{$prefix}autotask_sync_queue` "
            . "SET status='pending', next_attempt_at=NOW(), attempts=0, last_error=NULL, updated=NOW() "
            . "WHERE status IN ('failed','dead')"
        );
        return (int) db_affected_rows();
    }

    /**
     * @return array<string,int> Counts keyed by status.
     */
    public function statusCounts(): array
    {
        $prefix = Installer::prefix();
        $out = array('pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0, 'dead' => 0);
        $res = db_query("SELECT status, COUNT(*) AS c FROM `{$prefix}autotask_sync_queue` "
            . ($this->instanceId ? 'WHERE instance_id=' . (int) $this->instanceId . ' ' : '')
            . 'GROUP BY status');
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $out[$row['status']] = (int) $row['c'];
            }
        }
        return $out;
    }

    /**
     * @return array<int,array<string,mixed>> Recent failed/dead jobs.
     */
    public function recentFailures(int $limit = 50): array
    {
        $prefix = Installer::prefix();
        $limit = max(1, min(500, $limit));
        $rows = array();
        $res = db_query(
            "SELECT id, osticket_ticket_id, entity_type, action, status, attempts, last_error, next_attempt_at, updated "
            . "FROM `{$prefix}autotask_sync_queue` WHERE status IN ('failed','dead') "
            . "ORDER BY updated DESC LIMIT $limit"
        );
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Release jobs stuck in 'processing' (e.g. a crash mid-run) back to pending.
     */
    public function reapStuck(int $olderThanSeconds = 600): void
    {
        $prefix = Installer::prefix();
        $secs = max(60, $olderThanSeconds);
        db_query(
            "UPDATE `{$prefix}autotask_sync_queue` SET status='pending', updated=NOW() "
            . "WHERE status='processing' AND updated < (NOW() - INTERVAL $secs SECOND)"
        );
    }
}
