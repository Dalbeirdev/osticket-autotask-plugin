<?php
/**
 * Autotask Integration — picklist cache service.
 *
 * Caches Autotask reference data used by the technician panel and validation:
 *  - TimeEntries: billingCodeID (work types), roleID, resourceID
 *  - Tickets:     status, priority, queueID, ticketType
 *
 * @package Autotask Integration
 */

namespace Autotask;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Read-through cache over Autotask picklist sources (table: picklist_cache).
 */
class PicklistService
{
    private const TTL_HOURS = 24;

    /** Ticket fields whose picklist values we cache from entityInformation. */
    private const TICKET_FIELDS = array('status', 'priority', 'queueID', 'ticketType');

    /** @var AutotaskApi */ private $api;
    /** @var Logger */      private $logger;
    /** @var int Client instance whose picklists this cache slice holds. */
    private $instanceId;

    public function __construct(AutotaskApi $api, Logger $logger, int $instanceId = 1)
    {
        $this->api = $api;
        $this->logger = $logger;
        $this->instanceId = max(1, $instanceId);
    }

    /**
     * Ensure picklists exist; refresh if empty or stale.
     *
     * @param bool $force
     * @return bool
     */
    public function ensureFresh(bool $force = false): bool
    {
        if (!$force && !$this->isStale()) {
            return true;
        }
        try {
            $this->refresh();
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Picklist refresh failed: ' . $e->getMessage(),
                array('category' => 'picklist'));
            return $this->count() > 0;
        }
    }

    /**
     * Pull fresh data from Autotask and upsert into the cache.
     */
    public function refresh(): void
    {
        // TimeEntries reference data (queried entities).
        $this->upsertRecords('TimeEntries', 'billingCodeID', $this->api->getBillingCodes(), 'name');
        $this->upsertRecords('TimeEntries', 'roleID',        $this->api->getRoles(),        'name');
        $this->upsertRecords('TimeEntries', 'resourceID',    $this->api->getResources(),    null);

        // Tickets picklists (status/priority/queue/type) from field metadata.
        try {
            $fields = $this->api->getFieldInfo('Tickets');
            foreach ($fields as $f) {
                $name = $f['name'] ?? '';
                if (in_array($name, self::TICKET_FIELDS, true) && !empty($f['picklistValues'])) {
                    $this->upsertPicklist('Tickets', $name, $f['picklistValues']);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Ticket field info refresh failed: ' . $e->getMessage(),
                array('category' => 'picklist'));
        }

        $this->logger->info('Picklist cache refreshed', array('category' => 'picklist'));
    }

    /**
     * @param string $field
     * @param string $entity
     * @return array<int,array{value:string,label:string}>
     */
    public function options(string $field, string $entity = 'TimeEntries'): array
    {
        $prefix = Installer::prefix();
        $e = db_input($entity);
        $f = db_input($field);
        $out = array();
        $res = db_query(
            "SELECT value, label FROM `{$prefix}autotask_picklist_cache` "
            . "WHERE instance_id=" . (int) $this->instanceId . " AND entity=$e AND field=$f AND is_active=1 "
            . "ORDER BY sort_order ASC, label ASC"
        );
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $out[] = array('value' => $row['value'], 'label' => $row['label']);
            }
        }
        return $out;
    }

    /**
     * @param string $field
     * @param string $value
     * @param string $entity
     * @return bool
     */
    public function isValid(string $field, string $value, string $entity = 'TimeEntries'): bool
    {
        if ($value === '') {
            return false;
        }
        $prefix = Installer::prefix();
        $e = db_input($entity);
        $f = db_input($field);
        $v = db_input($value);
        $res = db_query(
            "SELECT 1 FROM `{$prefix}autotask_picklist_cache` "
            . "WHERE instance_id=" . (int) $this->instanceId . " AND entity=$e AND field=$f AND value=$v AND is_active=1 LIMIT 1"
        );
        return (bool) ($res && db_num_rows($res) > 0);
    }

    /**
     * Reverse lookup: cached Autotask picklist VALUE for a label (this
     * instance) — powers automatic name-equality mapping of work types.
     */
    public function valueByLabel(string $field, string $label): ?string
    {
        $label = mb_strtolower(trim($label));
        if ($label === '') {
            return null;
        }
        $prefix = Installer::prefix();
        $res = db_query(
            "SELECT value FROM `{$prefix}autotask_picklist_cache` "
            . 'WHERE instance_id=' . (int) $this->instanceId
            . " AND field=" . db_input($field)
            . ' AND LOWER(label)=' . db_input($label) . ' AND is_active=1 LIMIT 1', false
        );
        return ($res && ($r = db_fetch_array($res))) ? (string) $r['value'] : null;
    }

    /**
     * Cached Autotask picklist LABEL for a value (this instance) — used to
     * mirror the actually-pushed work type back onto the osTicket entry.
     */
    public function labelByValue(string $field, string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }
        $prefix = Installer::prefix();
        $res = db_query(
            "SELECT label FROM `{$prefix}autotask_picklist_cache` "
            . 'WHERE instance_id=' . (int) $this->instanceId
            . ' AND field=' . db_input($field)
            . ' AND value=' . db_input(trim($value)) . ' LIMIT 1', false
        );
        return ($res && ($r = db_fetch_array($res))) ? (string) $r['label'] : null;
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Upsert records returned from an entity query (id + label field).
     *
     * @param string      $entity
     * @param string      $field
     * @param array       $records
     * @param string|null $labelKey null = build "First Last" (resources)
     */
    private function upsertRecords(string $entity, string $field, array $records, ?string $labelKey): void
    {
        foreach ($records as $r) {
            if (!isset($r['id'])) {
                continue;
            }
            $value = (string) $r['id'];
            if ($labelKey !== null) {
                $label = (string) ($r[$labelKey] ?? $value);
            } else {
                $label = trim((string) ($r['firstName'] ?? '') . ' ' . (string) ($r['lastName'] ?? ''));
                if ($label === '') {
                    $label = (string) ($r['email'] ?? $value);
                }
            }
            $active = !isset($r['isActive']) || $r['isActive'] ? 1 : 0;
            $this->store($entity, $field, $value, $label, $active, 0, 0);
        }
    }

    /**
     * Upsert picklistValues from entityInformation field metadata.
     *
     * @param string $entity
     * @param string $field
     * @param array  $values  [{value,label,isActive,isDefaultValue,sortOrder}]
     */
    private function upsertPicklist(string $entity, string $field, array $values): void
    {
        foreach ($values as $v) {
            if (!isset($v['value'])) {
                continue;
            }
            $active  = !isset($v['isActive']) || $v['isActive'] ? 1 : 0;
            $default = !empty($v['isDefaultValue']) ? 1 : 0;
            $sort    = (int) ($v['sortOrder'] ?? 0);
            $label   = (string) ($v['label'] ?? $v['value']);
            $this->store($entity, $field, (string) $v['value'], $label, $active, $default, $sort);
        }
    }

    private function store(string $entity, string $field, string $value, string $label, int $active, int $default, int $sort): void
    {
        $prefix = Installer::prefix();
        $e  = db_input($entity);
        $f  = db_input($field);
        $v  = db_input($value);
        $lb = db_input(mb_substr($label, 0, 190));
        db_query(
            "INSERT INTO `{$prefix}autotask_picklist_cache` "
            . "(instance_id, entity, field, value, label, is_active, is_default, sort_order, fetched_at) "
            . "VALUES (" . (int) $this->instanceId . ", $e, $f, $v, $lb, $active, $default, $sort, NOW()) "
            . "ON DUPLICATE KEY UPDATE label=VALUES(label), is_active=VALUES(is_active), "
            . "is_default=VALUES(is_default), sort_order=VALUES(sort_order), fetched_at=NOW()"
        );
    }

    private function isStale(): bool
    {
        $prefix = Installer::prefix();
        $res = db_query("SELECT MAX(fetched_at) AS f, COUNT(*) AS c FROM `{$prefix}autotask_picklist_cache` "
            . 'WHERE instance_id=' . (int) $this->instanceId);
        if (!$res || !($row = db_fetch_array($res)) || (int) $row['c'] === 0) {
            return true;
        }
        return (time() - strtotime((string) $row['f'])) > (self::TTL_HOURS * 3600);
    }

    private function count(): int
    {
        $prefix = Installer::prefix();
        $res = db_query("SELECT COUNT(*) AS c FROM `{$prefix}autotask_picklist_cache` "
            . 'WHERE instance_id=' . (int) $this->instanceId);
        return ($res && ($r = db_fetch_array($res))) ? (int) $r['c'] : 0;
    }
}
