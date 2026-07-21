<?php
/**
 * Autotask Integration — scheduler.
 *
 * Invoked from the osTicket `cron` signal. Drains the outbound retry queue on
 * every tick (gated per-job by next_attempt_at) and runs the inbound pull on
 * the configured interval. Also performs housekeeping (log pruning, reaping
 * stuck jobs).
 *
 * @package Autotask Integration
 */

namespace Autotask;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Time-throttled coordinator for scheduled synchronization work.
 */
class Scheduler
{
    /** @var Settings */         private $settings;
    /** @var Queue */            private $queue;
    /** @var SyncEngine */       private $sync;
    /** @var Logger */           private $logger;
    /** @var TimeEntryService */ private $timeEntry;

    public function __construct(Settings $settings, Queue $queue, SyncEngine $sync, Logger $logger, TimeEntryService $timeEntry)
    {
        $this->settings  = $settings;
        $this->queue     = $queue;
        $this->sync      = $sync;
        $this->logger    = $logger;
        $this->timeEntry = $timeEntry;
    }

    /**
     * Main cron tick. Safe to call frequently.
     */
    public function tick(): void
    {
        if (!$this->settings->isEnabled()) {
            return;
        }

        // Reap jobs orphaned in 'processing' by a previous crash/timeout.
        $this->queue->reapStuck();

        // Always attempt to drain due outbound jobs (retry backoff is per-job).
        $this->processQueue($this->settings->batchSize());

        // Inbound pull is throttled to the configured interval.
        $interval = $this->settings->syncIntervalSeconds();
        $last     = (int) $this->settings->state('last_tick_ts', 0);
        $now      = time();
        if (($now - $last) >= $interval) {
            $this->settings->setState('last_tick_ts', $now);
            $this->runIncremental();
            $this->housekeeping();
        }
    }

    /**
     * Claim and execute a batch of outbound jobs.
     *
     * @return array{processed:int,failed:int}
     */
    public function processQueue(int $limit): array
    {
        $jobs = $this->queue->claimBatch($limit);
        $processed = 0;
        $failed = 0;
        $maxRetries = $this->settings->maxRetries();

        foreach ($jobs as $job) {
            try {
                // Time-entry jobs go to the TimeEntry service; everything else
                // to the sync engine.
                if (($job['entity_type'] ?? '') === 'timeentry') {
                    $this->timeEntry->processQueuedTimeEntry($job['payload'] ?? array());
                } else {
                    $this->sync->processJob($job);
                }
                $this->queue->markDone((int) $job['id']);
                $processed++;
            } catch (ApiException $e) {
                // Permanent errors (404/401/validation) should not retry-loop —
                // mark them dead immediately. Only transient errors back off.
                if (!$e->isRetryable()) {
                    $this->queue->markDead((int) $job['id'], $e->getMessage());
                } else {
                    $this->queue->markFailed((int) $job['id'], $e->getMessage(), $maxRetries);
                }
                $failed++;
                $this->logger->warning(
                    'Queue job #' . $job['id'] . ' failed: ' . $e->getMessage(),
                    array('category' => 'queue', 'osticket_ticket_id' => (int) $job['osticket_ticket_id'])
                );
            } catch (\Throwable $e) {
                $this->queue->markFailed((int) $job['id'], $e->getMessage(), $maxRetries);
                $failed++;
                $this->logger->error(
                    'Queue job #' . $job['id'] . ' error: ' . $e->getMessage(),
                    array('category' => 'queue')
                );
            }
        }
        return array('processed' => $processed, 'failed' => $failed);
    }

    /**
     * Incremental run: drain queue + pull changes since last cursor.
     *
     * @return array Summary counts.
     */
    public function runIncremental(): array
    {
        // Produce inbound jobs first, then drain the whole queue (both directions).
        $pulled = $this->sync->inboundSync();
        $q = $this->processQueue($this->settings->batchSize());
        $this->settings->setState('last_sync_summary', array(
            'type'   => 'incremental',
            'queue'  => $q,
            'pulled' => $pulled,
            'at'     => gmdate('c'),
        ));
        $this->logger->info("Incremental sync: processed {$q['processed']}, failed {$q['failed']}, pulled $pulled",
            array('category' => 'scheduler'));
        return array('queue' => $q, 'pulled' => $pulled);
    }

    /**
     * Full run: reset the inbound cursor far back then pull. Triggered manually
     * from the dashboard. Use sparingly on large instances.
     *
     * @param int $lookbackDays
     * @return array Summary counts.
     */
    public function runFull(int $lookbackDays = 30): array
    {
        $this->settings->setState('inbound_cursor_utc',
            gmdate('Y-m-d\TH:i:s\Z', time() - ($lookbackDays * 86400)));
        $pulled = $this->sync->inboundSync();
        $q = $this->processQueue($this->settings->batchSize());
        $this->settings->setState('last_sync_summary', array(
            'type'   => 'full',
            'queue'  => $q,
            'pulled' => $pulled,
            'at'     => gmdate('c'),
        ));
        $this->logger->info("Full sync (lookback {$lookbackDays}d): pulled $pulled",
            array('category' => 'scheduler'));
        return array('queue' => $q, 'pulled' => $pulled);
    }

    /**
     * Periodic maintenance: prune old logs.
     */
    private function housekeeping(): void
    {
        try {
            $deleted = $this->logger->prune($this->settings->logRetentionDays());
            if ($deleted > 0) {
                $this->logger->debug("Pruned $deleted old log rows", array('category' => 'maintenance'));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Housekeeping failed: ' . $e->getMessage(),
                array('category' => 'maintenance'));
        }
    }
}
