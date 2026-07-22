<?php
/**
 * Autotask Integration — Plugin configuration form.
 *
 * Renders the Admin Panel configuration page and validates Autotask
 * credentials before persisting them. Secrets are stored through osTicket's
 * encrypted plugin config store (PluginConfig uses the instance secret salt).
 *
 * @package Autotask Integration
 */

namespace Autotask;

use PluginConfig;

if (!defined('INCLUDE_DIR')) {
    die('Access denied');
}

/**
 * Configuration definition + validation for the Autotask plugin.
 */
class AutotaskPluginConfig extends PluginConfig
{
    /**
     * Translate helper. Wraps osTicket's global __() / _N() when present so the
     * config form is localisable, with safe passthrough fallbacks.
     *
     * @return array{0:callable,1:callable}
     */
    public static function translate()
    {
        return array(
            function ($x) { return function_exists('__') ? __($x) : $x; },
            function ($x, $y, $n) {
                return function_exists('_N') ? _N($x, $y, $n) : ($n != 1 ? $y : $x);
            },
        );
    }

    /**
     * Define every configurable option. Field keys map 1:1 to config storage.
     *
     * @return array<string,\FormField>
     */
    public function getOptions()
    {
        list($__, $_N) = self::translate();

        $options = array(
            /* ---- Master switch ------------------------------------------ */
            'enabled' => new \BooleanField(array(
                'id'            => 'enabled',
                'label'         => $__('Enable Integration'),
                'default'       => false,
                'configuration' => array(
                    'desc' => $__('Master switch. When off, no live or scheduled sync runs.'),
                ),
            )),

            /* ---- API credentials ---------------------------------------- */
            'api_section' => new \SectionBreakField(array(
                'label' => $__('Autotask API Credentials'),
                'hint'  => $__('Create a dedicated API user in Autotask (Admin » Resources/Users).'),
            )),
            'api_username' => new \TextboxField(array(
                'id'            => 'api_username',
                'label'         => $__('API Username'),
                'required'      => true,
                'configuration' => array('size' => 60, 'length' => 191,
                    'placeholder' => 'apiuser@example.com'),
            )),
            'api_secret' => new \TextboxField(array(
                'id'            => 'api_secret',
                'label'         => $__('Secret'),
                // Not required on edit: leave blank to keep the stored secret.
                'required'      => false,
                'widget'        => 'PasswordWidget',
                'hint'          => $__('Leave blank to keep the current secret. Enter a value only to change it.'),
                'configuration' => array(
                    'size' => 60, 'length' => 191,
                    // Discourage browser autofill clobbering the write-only field.
                    'autocomplete' => false,
                ),
            )),
            'api_integration_code' => new \TextboxField(array(
                'id'            => 'api_integration_code',
                'label'         => $__('Integration Code'),
                'required'      => true,
                'configuration' => array('size' => 60, 'length' => 191),
            )),
            'api_zone_url' => new \TextboxField(array(
                'id'            => 'api_zone_url',
                'label'         => $__('Zone URL'),
                'hint'          => $__('Leave blank to auto-detect from the API username.'),
                'required'      => false,
                'configuration' => array('size' => 60, 'length' => 255,
                    'placeholder' => 'https://webservices2.autotask.net/atservicesrest/'),
            )),

            /* ---- Default mappings (osTicket -> Autotask) ----------------- */
            'defaults_section' => new \SectionBreakField(array(
                'label' => $__('Autotask Ticket Defaults'),
                'hint'  => $__('Numeric IDs / picklist values from your Autotask instance. '
                            . 'Use the Test Connection button on the dashboard to look these up.'),
            )),
            'default_company_id' => new \TextboxField(array(
                'id'            => 'default_company_id',
                'label'         => $__('Default Company ID'),
                'hint'          => $__('Fallback Autotask companyID when a contact cannot be matched.'),
                'required'      => true,
                'configuration' => array('validator' => 'number', 'size' => 20),
            )),
            'default_queue_id' => new \TextboxField(array(
                'id'            => 'default_queue_id',
                'label'         => $__('Default Queue ID'),
                'required'      => false,
                'configuration' => array('validator' => 'number', 'size' => 20),
            )),
            'default_priority' => new \TextboxField(array(
                'id'            => 'default_priority',
                'label'         => $__('Default Priority (picklist value)'),
                'required'      => false,
                'configuration' => array('validator' => 'number', 'size' => 20),
            )),
            'default_status' => new \TextboxField(array(
                'id'            => 'default_status',
                'label'         => $__('Default Status (picklist value)'),
                'required'      => false,
                'configuration' => array('validator' => 'number', 'size' => 20),
            )),
            'default_ticket_type' => new \TextboxField(array(
                'id'            => 'default_ticket_type',
                'label'         => $__('Default Ticket Type (picklist value)'),
                'required'      => false,
                'configuration' => array('validator' => 'number', 'size' => 20),
            )),

            /* ---- Sync behaviour ----------------------------------------- */
            'sync_section' => new \SectionBreakField(array(
                'label' => $__('Synchronization Behaviour'),
            )),
            'sync_attachments' => new \BooleanField(array(
                'id'            => 'sync_attachments',
                'label'         => $__('Sync Attachments'),
                'default'       => false,
                'configuration' => array('desc' => $__('Upload osTicket attachments to Autotask.')),
            )),
            'two_way_sync' => new \BooleanField(array(
                'id'            => 'two_way_sync',
                'label'         => $__('Enable Two-Way Sync (Autotask -> osTicket)'),
                'default'       => true,
                'configuration' => array('desc' => $__('Pull status, notes and replies from Autotask on schedule.')),
            )),
            'inbound_notes_enabled' => new \BooleanField(array(
                'id'            => 'inbound_notes_enabled',
                'label'         => $__('Import Autotask notes into osTicket'),
                'default'       => true,
                'configuration' => array('desc' => $__('Pull notes added in Autotask into the osTicket ticket thread.')),
            )),
            'sync_interval' => new \ChoiceField(array(
                'id'            => 'sync_interval',
                'label'         => $__('Scheduled Sync Interval'),
                'default'       => '300',
                'choices'       => array(
                    '300'  => $__('Every 5 minutes'),
                    '600'  => $__('Every 10 minutes'),
                    '900'  => $__('Every 15 minutes'),
                    '1800' => $__('Every 30 minutes'),
                    '3600' => $__('Hourly'),
                ),
            )),
            'batch_size' => new \TextboxField(array(
                'id'            => 'batch_size',
                'label'         => $__('Batch Size'),
                'hint'          => $__('Records processed per scheduled run (pagination).'),
                'default'       => '50',
                'configuration' => array('validator' => 'number', 'size' => 10),
            )),
            'max_retries' => new \TextboxField(array(
                'id'            => 'max_retries',
                'label'         => $__('Max Retry Attempts'),
                'default'       => '6',
                'configuration' => array('validator' => 'number', 'size' => 10),
            )),

            /* ---- Inline time capture (reply/note form) ------------------ */
            'email_customer_replies' => new \BooleanField(array(
                'id'            => 'email_customer_replies',
                'label'         => $__('Direct customer email'),
                'default'       => false,
                'configuration' => array('desc' => $__('TICK = osTicket also emails the customer directly '
                                . '(standard osTicket behaviour). UNTICKED = recommended: the client reads '
                                . 'replies in Autotask, the From / Recipients / Reply-To block is hidden, and '
                                . 'replies post as "Do Not Email Reply" so no second email goes out.')),
            )),
            'collapse_ticket_info' => new \BooleanField(array(
                'id'            => 'collapse_ticket_info',
                'label'         => $__('Collapse osTicket\'s ticket header'),
                'default'       => true,
                'configuration' => array('desc' => $__('TICK = on synced tickets the Status / Priority / '
                                . 'Department / SLA header block starts collapsed behind a "Ticket details" '
                                . 'toggle, with a one-line summary kept visible (Autotask is the source of '
                                . 'truth for those fields). Each agent\'s show/hide choice is remembered.')),
            )),
            'default_internal_note' => new \BooleanField(array(
                'id'            => 'default_internal_note',
                'label'         => $__('Open "Post Internal Note" first'),
                'default'       => true,
                'configuration' => array('desc' => $__('TICK = the Internal Note tab is selected when a synced '
                                . 'ticket opens (agents mostly log internal work). UNTICKED = osTicket\'s '
                                . 'normal behaviour, Post Reply first. Replies are still one click away — and '
                                . 'they are what the client sees in Autotask.')),
            )),
            'hide_sla_view' => new \BooleanField(array(
                'id'            => 'hide_sla_view',
                'label'         => $__('Hide osTicket\'s "SLA Plan" row'),
                'default'       => true,
                'configuration' => array('desc' => $__('TICK = hide the SLA Plan row on synced tickets '
                                . '(recommended: the Autotask panel already shows the client\'s real SLA, and '
                                . 'two different SLAs on one page confuse agents).')),
            )),
            'timecapture_section' => new \SectionBreakField(array(
                'label' => $__('Inline Time Capture (Reply / Note form)'),
                'hint'  => $__('Create an Autotask time entry automatically when a reply/note is '
                            . 'posted with time fields. Field names below must match your reply form.'),
            )),
            'capture_time_enabled' => new \BooleanField(array(
                'id'            => 'capture_time_enabled',
                'label'         => $__('Capture time on reply/note'),
                'default'       => false,
                'configuration' => array('desc' => $__('Hook the staff reply/note submission for time entries.')),
            )),
            'field_time_spent' => new \TextboxField(array(
                'id'            => 'field_time_spent',
                'label'         => $__('Time Spent field name'),
                'hint'          => $__('POST field name from your reply form (e.g. time_spent).'),
                'default'       => 'time_spent',
                'configuration' => array('size' => 30, 'length' => 64),
            )),
            'time_spent_unit' => new \ChoiceField(array(
                'id'            => 'time_spent_unit',
                'label'         => $__('Time Spent unit'),
                'default'       => 'minutes',
                'choices'       => array('minutes' => $__('Minutes'), 'hours' => $__('Hours')),
            )),
            'field_time_type' => new \TextboxField(array(
                'id'            => 'field_time_type',
                'label'         => $__('Time Type field name'),
                'default'       => 'time_type',
                'configuration' => array('size' => 30, 'length' => 64),
            )),
            'field_billable' => new \TextboxField(array(
                'id'            => 'field_billable',
                'label'         => $__('Billable field name'),
                'default'       => 'billable',
                'configuration' => array('size' => 30, 'length' => 64),
            )),
            'timetype_map' => new \TextareaField(array(
                'id'            => 'timetype_map',
                'label'         => $__('Time Type → Autotask work type'),
                'hint'          => $__('One per line: TimeTypeLabel=AutotaskBillingCodeID (e.g. Telephone=29682885).'),
                'configuration' => array('rows' => 6, 'cols' => 40, 'html' => false),
            )),
            'default_work_type_id' => new \TextboxField(array(
                'id'            => 'default_work_type_id',
                'label'         => $__('Default work type (billing code ID)'),
                'hint'          => $__('Fallback when a Time Type has no mapping.'),
                'required'      => false,
                'configuration' => array('validator' => 'number', 'size' => 20),
            )),
            'default_resource_id' => new \TextboxField(array(
                'id'            => 'default_resource_id',
                'label'         => $__('Default resource ID'),
                'hint'          => $__('Fallback Autotask resource when the agent email cannot be matched.'),
                'required'      => false,
                'configuration' => array('validator' => 'number', 'size' => 20),
            )),
            'default_role_id' => new \TextboxField(array(
                'id'            => 'default_role_id',
                'label'         => $__('Default role ID'),
                'hint'          => $__('Autotask requires a role on ticket time entries. Used when none is selected (e.g. Help Desk Technician).'),
                'required'      => false,
                'configuration' => array('validator' => 'number', 'size' => 20),
            )),

            /* ---- Import filters ----------------------------------------- */
            'import_filters_section' => new \SectionBreakField(array(
                'label' => $__('Import Filters (Autotask → osTicket)'),
                'hint'  => $__('Control which Autotask tickets are imported. Leave ID lists blank for no restriction.'),
            )),
            'import_include_open' => new \BooleanField(array(
                'id'            => 'import_include_open',
                'label'         => $__('Import open / active tickets'),
                'default'       => true,
                'configuration' => array('desc' => $__('Tickets not in the Complete status.')),
            )),
            'import_include_closed' => new \BooleanField(array(
                'id'            => 'import_include_closed',
                'label'         => $__('Import completed / closed tickets'),
                'default'       => false,
            )),
            'import_status_ids' => new \TextboxField(array(
                'id'            => 'import_status_ids',
                'label'         => $__('Only these status values'),
                'hint'          => $__('Comma-separated Autotask status picklist values (e.g. In Progress, Waiting Customer). Overrides the open/closed toggles.'),
                'configuration' => array('size' => 40),
            )),
            'import_company_ids' => new \TextboxField(array(
                'id'            => 'import_company_ids',
                'label'         => $__('Limit to Company IDs'),
                'hint'          => $__('Comma-separated companyIDs (blank = all).'),
                'configuration' => array('size' => 40),
            )),
            'import_queue_ids' => new \TextboxField(array(
                'id'            => 'import_queue_ids',
                'label'         => $__('Limit to Queue IDs'),
                'configuration' => array('size' => 40),
            )),
            'import_resource_ids' => new \TextboxField(array(
                'id'            => 'import_resource_ids',
                'label'         => $__('Limit to assigned Resource IDs'),
                'configuration' => array('size' => 40),
            )),
            'import_since_days' => new \TextboxField(array(
                'id'            => 'import_since_days',
                'label'         => $__('Only tickets active in the last N days'),
                'hint'          => $__('0 = no date limit.'),
                'default'       => '0',
                'configuration' => array('validator' => 'number', 'size' => 10),
            )),
            'auto_import_enabled' => new \BooleanField(array(
                'id'            => 'auto_import_enabled',
                'label'         => $__('Auto-import new tickets on schedule'),
                'default'       => false,
                'configuration' => array('desc' => $__('Each scheduled sync imports new Autotask tickets matching the filters above (no manual click).')),
            )),

            /* ---- Ticket closure ----------------------------------------- */
            'closure_section' => new \SectionBreakField(array(
                'label' => $__('Ticket Closure'),
                'hint'  => $__('How completing a ticket maps to Autotask.'),
            )),
            'complete_status' => new \TextboxField(array(
                'id'            => 'complete_status',
                'label'         => $__('Autotask "Complete" status value'),
                'hint'          => $__('Picklist value used when completing a ticket (default 5).'),
                'default'       => '5',
                'configuration' => array('validator' => 'number', 'size' => 10),
            )),
            'require_time_before_close' => new \BooleanField(array(
                'id'            => 'require_time_before_close',
                'label'         => $__('Require a time entry before completing'),
                'default'       => false,
                'configuration' => array('desc' => $__('Block completion until at least one time entry is logged.')),
            )),
            'close_osticket_on_complete' => new \BooleanField(array(
                'id'            => 'close_osticket_on_complete',
                'label'         => $__('Allow closing the osTicket ticket on complete'),
                'default'       => true,
                'configuration' => array('desc' => $__('Enables the "also close in osTicket" option in the panel.')),
            )),

            /* ---- Logging / maintenance ---------------------------------- */
            'log_section' => new \SectionBreakField(array(
                'label' => $__('Logging & Maintenance'),
            )),
            'log_level' => new \ChoiceField(array(
                'id'            => 'log_level',
                'label'         => $__('Log Level'),
                'default'       => 'info',
                'choices'       => array(
                    'debug'   => $__('Debug (verbose, includes payloads)'),
                    'info'    => $__('Info'),
                    'warning' => $__('Warning'),
                    'error'   => $__('Error only'),
                ),
            )),
            'log_retention_days' => new \TextboxField(array(
                'id'            => 'log_retention_days',
                'label'         => $__('Log Retention (days)'),
                'default'       => '30',
                'configuration' => array('validator' => 'number', 'size' => 10),
            )),
            'drop_tables_on_uninstall' => new \BooleanField(array(
                'id'            => 'drop_tables_on_uninstall',
                'label'         => $__('Drop tables on uninstall'),
                'default'       => false,
                'configuration' => array('desc' =>
                    $__('DANGER: permanently deletes all mapping/log/queue data when uninstalling.')),
            )),
        );

        // Multi-tenant (v2): everything client-specific moved to the Clients
        // register form (Autotask tab » Clients). This page keeps only GLOBAL
        // engine settings; hide the superseded per-client fields.
        $perClient = array(
            'api_section', 'api_username', 'api_secret', 'api_integration_code', 'api_zone_url',
            'defaults_section', 'default_company_id', 'default_queue_id', 'default_priority',
            'default_status', 'default_ticket_type',
            'sync_attachments', 'two_way_sync', 'inbound_notes_enabled', 'auto_import_enabled',
            'default_work_type_id', 'default_resource_id', 'default_role_id',
            'import_filters_section', 'import_include_open', 'import_include_closed',
            'import_status_ids', 'import_company_ids', 'import_queue_ids',
            'import_resource_ids', 'import_since_days',
            'closure_section', 'complete_status', 'require_time_before_close',
            'close_osticket_on_complete',
        );
        foreach ($perClient as $k) {
            unset($options[$k]);
        }
        return $options;
    }

    /**
     * Validate before persisting. We trim inputs, sanity-check numerics, and —
     * when credentials are supplied — perform a live Test Connection so config
     * is only saved after successful validation (Feature #3).
     *
     * @param array  $config  Submitted values (by reference).
     * @param array  $errors  Validation errors (by reference).
     * @return bool  True to allow save.
     */
    public function pre_save(&$config, &$errors)
    {
        list($__) = self::translate();

        // Secret is a write-only field: osTicket never re-renders its stored
        // value, so a blank submission on edit means "unchanged" — keep the
        // existing stored secret rather than wiping it. Track whether the secret
        // actually changed so we only re-validate when needed.
        $secretChanged = false;
        if (empty($config['api_secret'])) {
            $existingSecret = $this->get('api_secret');
            if (!empty($existingSecret)) {
                $config['api_secret'] = $existingSecret;
            }
        } else {
            $secretChanged = ((string) $config['api_secret'] !== (string) $this->get('api_secret'));
        }

        // Normalise zone URL.
        if (!empty($config['api_zone_url'])) {
            $config['api_zone_url'] = rtrim(trim($config['api_zone_url']), '/') . '/';
            if (!filter_var($config['api_zone_url'], FILTER_VALIDATE_URL)) {
                $errors['api_zone_url'] = $__('Zone URL is not a valid URL.');
            }
        }

        // Bound the batch size to a sane window.
        if (isset($config['batch_size'])) {
            $config['batch_size'] = max(1, min(500, (int) $config['batch_size']));
        }
        if (isset($config['max_retries'])) {
            $config['max_retries'] = max(0, min(20, (int) $config['max_retries']));
        }

        // Multi-tenant (v2): credentials belong to each CLIENT, not to this
        // page — the legacy fields are not even rendered here any more. Only
        // block enabling when no client exists at all, and say where to go.
        $enabling = !empty($config['enabled']);
        $haveCreds = !empty($config['api_username'])
            && !empty($config['api_secret'])
            && !empty($config['api_integration_code']);

        if ($enabling && !$haveCreds) {
            $clients = 0;
            try {
                $clients = (new InstanceRepository())->count();
            } catch (\Throwable $e) {
                $clients = 0; // schema not ready yet — fall back to the old rule
            }
            if (!$clients) {
                $errors['err'] = $__('Register a client first: Admin Panel → Autotask → Clients → Add Client '
                    . '(API username, secret and integration code live there, one set per client).');
                return false;
            }
        }

        // NOTE: we deliberately do NOT make a live Autotask connection test here.
        // Running a network call on every save proved fragile (browser autofill
        // of the write-only Secret field caused spurious auth failures that
        // blocked unrelated config changes). Validate the connection explicitly
        // via the dashboard "Test Connection" button instead.
        $secretChanged; // (retained above; referenced to avoid "unused" notices)

        return true;
    }
}
