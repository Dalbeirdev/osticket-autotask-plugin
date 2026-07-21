<?php
/**
 * Autotask Integration — role-based access control.
 *
 * Registers custom osTicket agent permissions and checks them for the
 * technician actions (log time, change status, complete). Admins always pass.
 * Falls back gracefully if the core RBAC API differs across versions.
 *
 * Grant these under: Admin Panel » Agents » Roles » (role) » Permissions » Autotask.
 *
 * @package Autotask Integration
 */

namespace Autotask;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Custom permission registration + checks.
 */
class Rbac
{
    const PERM_TIME     = 'autotask.time';
    const PERM_STATUS   = 'autotask.status';
    const PERM_COMPLETE = 'autotask.complete';

    /** key => [title, description] */
    const PERMS = array(
        self::PERM_TIME     => array('Autotask: Log Time', 'Log time entries to Autotask from a ticket.'),
        self::PERM_STATUS   => array('Autotask: Change Status', 'Change the Autotask ticket status.'),
        self::PERM_COMPLETE => array('Autotask: Complete Ticket', 'Complete/close the Autotask ticket with a resolution.'),
    );

    /**
     * Register the permissions so they appear in the Roles UI. Idempotent.
     */
    public static function register(): void
    {
        if (!class_exists('RolePermission')) {
            return;
        }
        try {
            \RolePermission::register('Autotask', self::PERMS);
        } catch (\Throwable $e) {
            error_log('[Autotask][Rbac] register failed: ' . $e->getMessage());
        }
    }

    /**
     * @param mixed  $staff \Staff instance.
     * @param string $perm  One of the PERM_* constants.
     * @return bool
     */
    public static function can($staff, string $perm): bool
    {
        if (!$staff) {
            return false;
        }
        // Admins always pass.
        if (method_exists($staff, 'isAdmin') && $staff->isAdmin()) {
            return true;
        }
        // Enforce the specific permission when the RBAC API is available.
        if (method_exists($staff, 'hasPerm')) {
            try {
                return (bool) $staff->hasPerm($perm);
            } catch (\Throwable $e) {
                // fall through to permissive default
            }
        }
        // Graceful fallback (older cores without hasPerm): allow authenticated staff.
        return true;
    }
}
