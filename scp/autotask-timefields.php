<?php
/**
 * Autotask Integration — staff reply/note time-field injector.
 *
 * COPY THIS FILE INTO osTicket scp/ AS:  scp/autotask-timefields.php
 *   sudo cp include/plugins/autotask/scp-timefields.php scp/autotask-timefields.php
 *   sudo chown www-data:www-data scp/autotask-timefields.php
 *
 * Then add ONE line to include/staff/footer.inc.php (before </body>):
 *   <script src="autotask-timefields.php"></script>
 *
 * Outputs JavaScript that injects Time Spent / Time Type / Billable fields into
 * the staff reply and note forms — but ONLY when "Capture time on reply/note"
 * is enabled in the plugin config. Field names come from config so they match
 * what the server-side capture reads. Use this on installs that do NOT already
 * have their own time fields (e.g. the dev box); leave it out where custom code
 * already provides them (production).
 *
 * @package Autotask Integration
 */

require('staff.inc.php');
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');

if (!defined('INCLUDE_DIR') || !isset($thisstaff) || !$thisstaff) {
    exit; // no JS for unauthenticated context
}

$boot = glob(INCLUDE_DIR . 'plugins/*/bootstrap.php');
if (!$boot) {
    exit;
}
require_once $boot[0];

$plugin = null;
if (class_exists('PluginManager')) {
    foreach (PluginManager::allInstalled() as $p) {
        if ($p instanceof \Autotask\AutotaskPlugin) {
            $plugin = $p;
            break;
        }
    }
}
if (!$plugin) {
    exit;
}

$cfg = $plugin->getConfig();
if (!$cfg->get('capture_time_enabled')) {
    exit; // feature off -> emit nothing
}

$settings = new \Autotask\Settings($cfg);
$fields = $settings->timeFieldNames();

// Time Type labels: derive from the config map (preserve order), else defaults.
$labels = array();
foreach (preg_split('/\r\n|\r|\n/', (string) $cfg->get('timetype_map')) as $line) {
    $line = trim($line);
    if ($line !== '' && strpos($line, '=') !== false) {
        $labels[] = trim(explode('=', $line, 2)[0]);
    }
}
if (!$labels) {
    $labels = array('Telephone', 'Email', 'Remote', 'Workshop', 'Onsite');
}

// Hand config to JS safely.
$cfgJs = json_encode(array(
    'fields' => $fields,
    'types'  => $labels,
), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
(function () {
    "use strict";
    var CFG = <?= $cfgJs ?>;

    // Only on the staff ticket view.
    if (location.pathname.indexOf('tickets.php') === -1) {
        return;
    }

    function fieldHtml() {
        var opts = CFG.types.map(function (t) {
            return '<option value="' + t + '">' + t + '</option>';
        }).join('');
        return ''
            + '<div class="at-timefields" style="margin:10px 0;padding:8px 0;border-top:1px solid #e4e8ec;font-size:13px">'
            + '<label style="margin-right:14px;font-weight:600">Time Spent (min) '
            + '<input type="number" name="' + CFG.fields.spent + '" min="0" step="1" '
            + 'style="width:80px;padding:4px;margin-left:4px"></label>'
            + '<label style="margin-right:14px;font-weight:600">Time Type '
            + '<select name="' + CFG.fields.type + '" style="padding:4px;margin-left:4px">' + opts + '</select></label>'
            + '<label style="font-weight:600"><input type="checkbox" name="' + CFG.fields.billable + '" '
            + 'value="1" checked style="margin-right:4px">Billable</label>'
            + '</div>';
    }

    function inject(textareaName) {
        var ta = document.querySelector('textarea[name="' + textareaName + '"]');
        if (!ta) { return; }
        var form = ta.closest ? ta.closest('form') : null;
        if (!form || form.querySelector('.at-timefields')) { return; }
        var btn = form.querySelector('[type="submit"]');
        var node = document.createElement('div');
        node.innerHTML = fieldHtml();
        node = node.firstChild;
        if (btn && btn.parentNode) {
            btn.parentNode.insertBefore(node, btn);
        } else {
            form.appendChild(node);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Reply uses textarea[name=response]; note uses textarea[name=note].
        inject('response');
        inject('note');
        // Re-check shortly after, in case osTicket builds a tab lazily.
        setTimeout(function () { inject('response'); inject('note'); }, 800);
    });
})();
