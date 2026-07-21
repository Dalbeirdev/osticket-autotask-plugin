<?php
/**
 * Autotask Integration — documentation download (staff-only).
 *
 * COPY INTO osTicket scp/ AS: scp/autotask-docs.php
 *
 * Streams the bundled Administrator & Operations Guide PDF to authenticated
 * staff. The PDF itself lives inside include/plugins/ (not web-readable), so
 * this endpoint is the only way to fetch it.
 *
 * @package Autotask Integration
 */

require('staff.inc.php');
if (!defined('INCLUDE_DIR') || !isset($thisstaff) || !$thisstaff) {
    http_response_code(403);
    exit;
}

$pdf = null;
foreach (glob(INCLUDE_DIR . 'plugins/*/docs/Autotask-Integration-Guide.pdf') ?: array() as $p) {
    $pdf = $p;
    break;
}
if (!$pdf || !is_readable($pdf)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Documentation PDF not found. Rebuild/redeploy the plugin docs.\n";
    exit;
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($pdf));
header('Content-Disposition: inline; filename="Autotask-Integration-Guide.pdf"');
header('X-Content-Type-Options: nosniff');
readfile($pdf);
exit;
