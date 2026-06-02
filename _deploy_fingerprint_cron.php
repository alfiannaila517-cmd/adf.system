<?php

/**
 * Register / manage the Fingerspot auto-sync CRON job on cPanel.
 *
 * The cron calls the auto-sync endpoint every 10 minutes so attendance
 * keeps updating even if the Fingerspot.io real-time webhook push stops.
 *
 * USAGE (run locally / on server CLI):
 *   php _deploy_fingerprint_cron.php add      # create the cron (every 10 min)
 *   php _deploy_fingerprint_cron.php list     # list current cron jobs
 *   php _deploy_fingerprint_cron.php remove <linekey>
 *   php _deploy_fingerprint_cron.php log      # view last sync output
 */

$h = 'guangmao.iixcp.rumahweb.net';
$u = 'adfb2574';
$p = '@Nnoc2026';
$base = "https://{$h}:2083";

// Cron command: trigger the auto-sync endpoint, save output for monitoring.
$cronCmd = '/usr/bin/curl -s "https://adfsystem.online/api/fingerprint-cron-sync.php?token=adf-deploy-2025-secure&days=2" > /home/adfb2574/fingerprint_cron_log.txt 2>&1';

$action = $argv[1] ?? 'add';

if ($action === 'add') {
    // Every 10 minutes, all hours/days
    $url = $base . '/json-api/cpanel?cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Cron&cpanel_jsonapi_func=add_line'
        . '&command=' . urlencode($cronCmd)
        . '&minute=' . urlencode('*/10')
        . '&hour=*&day=*&month=*&weekday=*';
} elseif ($action === 'list') {
    $url = $base . '/json-api/cpanel?cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Cron&cpanel_jsonapi_func=listcron';
} elseif ($action === 'remove') {
    $linenum = $argv[2] ?? '';
    if (!$linenum) {
        echo "Usage: php _deploy_fingerprint_cron.php remove <linekey>\n";
        exit(1);
    }
    $url = $base . '/json-api/cpanel?cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Cron&cpanel_jsonapi_func=remove_line&linekey=' . $linenum;
} elseif ($action === 'log') {
    $url = $base . '/json-api/cpanel?cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Fileman&cpanel_jsonapi_func=viewfile&dir=%2Fhome%2Fadfb2574&file=fingerprint_cron_log.txt';
} else {
    echo "Usage: php _deploy_fingerprint_cron.php [add|list|remove|log]\n";
    exit(1);
}

$ctx = stream_context_create([
    'http' => [
        'header' => 'Authorization: Basic ' . base64_encode("{$u}:{$p}"),
        'timeout' => 15,
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ]
]);

$result = @file_get_contents($url, false, $ctx);
if ($result === false) {
    echo "ERROR: Request failed\n";
    exit(1);
}

$data = json_decode($result, true);
echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
