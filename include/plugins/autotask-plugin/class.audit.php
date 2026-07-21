<?php
/**
 * Autotask Integration — audit trail.
 *
 * Records privileged actions (time entries, closures, config changes) to the
 * autotask_audit table for accountability and change tracking.
 *
 * @package Autotask Integration
 */

namespace Autotask;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Writes immutable audit records. Never throws (audit must not break actions).
 */
class Audit
{
    /** @var int|null Client instance rows are tagged with / filtered by. */
    private $instanceId;

    /**
     * @param int|null $instanceId Bind to one client (null = global view;
     *                             writes then tag as legacy instance #1).
     */
    public function __construct(?int $instanceId = null)
    {
        $this->instanceId = $instanceId;
    }

    /**
     * @param string   $action  e.g. time.add, ticket.close, note.add
     * @param array    $ctx     Optional: staff_id, staff_name, entity,
     *                          osticket_ticket_id, autotask_ticket_id, detail, ip
     */
    public function log(string $action, array $ctx = array()): void
    {
        try {
            $prefix = Installer::prefix();

            $staffId   = isset($ctx['staff_id']) ? (int) $ctx['staff_id'] : 'NULL';
            $staffName = isset($ctx['staff_name']) ? db_input((string) $ctx['staff_name']) : 'NULL';
            $act       = db_input(substr($action, 0, 64));
            $entity    = isset($ctx['entity']) ? db_input((string) $ctx['entity']) : 'NULL';
            $ostId     = isset($ctx['osticket_ticket_id']) ? (int) $ctx['osticket_ticket_id'] : 'NULL';
            $atId      = isset($ctx['autotask_ticket_id']) ? (int) $ctx['autotask_ticket_id'] : 'NULL';
            $detail    = isset($ctx['detail'])
                ? db_input(is_string($ctx['detail']) ? $ctx['detail'] : json_encode($ctx['detail']))
                : 'NULL';
            $ip        = db_input((string) ($ctx['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')));

            $iid = (int) ($this->instanceId ?: 1);
            db_query(
                "INSERT INTO `{$prefix}autotask_audit` "
                . "(instance_id, staff_id, staff_name, action, entity, osticket_ticket_id, autotask_ticket_id, detail, ip, created) "
                . "VALUES ($iid, $staffId, $staffName, $act, $entity, $ostId, $atId, $detail, $ip, NOW())"
            );
        } catch (\Throwable $e) {
            error_log('[Autotask][Audit] ' . $e->getMessage());
        }
    }

    /**
     * @return array<int,array<string,mixed>> Recent audit rows.
     */
    public function recent(int $limit = 100): array
    {
        $prefix = Installer::prefix();
        $limit = max(1, min(1000, $limit));
        $rows = array();
        $where = $this->instanceId ? ('WHERE instance_id=' . (int) $this->instanceId . ' ') : '';
        $res = db_query(
            "SELECT created, staff_name, action, entity, osticket_ticket_id, autotask_ticket_id "
            . "FROM `{$prefix}autotask_audit` $where ORDER BY id DESC LIMIT $limit"
        );
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
