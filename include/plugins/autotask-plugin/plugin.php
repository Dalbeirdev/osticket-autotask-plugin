<?php
/**
 * Autotask Integration — osTicket Plugin Manifest
 *
 * This file is read by osTicket's PluginManager when the plugin folder is
 * scanned. It MUST return an associative array describing the plugin and
 * pointing at the bootstrap class that osTicket will instantiate.
 *
 * @package   Autotask Integration
 * @author    DPI / Senior PHP Engineering
 * @license   GPLv2
 * @link      https://github.com/your-org/osticket-autotask
 *
 * Compatible with osTicket 1.17.x / 1.18.x, PHP 8.0 – 8.3.
 *
 * PSR-12 compliant. No external Composer dependencies.
 */

return array(
    // Globally-unique identifier. Convention: vendor:plugin.
    'id'          => 'dpi:autotask',

    // Semantic version. Used by the migration runner to detect upgrades.
    'version'     => '1.0.0',

    // Human readable metadata shown in Admin Panel » Manage » Plugins.
    'name'        => 'Autotask Integration',
    'author'      => 'DPI',
    'description' => 'Two-way synchronization between osTicket and Autotask PSA '
                   . '(tickets, replies, notes, status & priority). Includes an '
                   . 'admin dashboard, retry queue, scheduler and full logging.',
    'url'         => 'https://github.com/your-org/osticket-autotask',

    // Entry point: "<file>:<ClassName>". osTicket includes the file and
    // instantiates the class (which must extend the core Plugin class).
    'plugin'      => 'bootstrap.php:Autotask\\AutotaskPlugin',

    // Minimum core/PHP requirements (informational; enforced in bootstrap()).
    'requires'    => array(
        'php'      => '8.0.0',
        'osticket' => '1.17',
    ),
);
