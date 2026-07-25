<?php
if (($_GET['token'] ?? '') !== 'adf-deploy-2025-secure') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain');
$dir = __DIR__ . '/config/businesses/';
$files = glob($dir . '*eat*');
if (!$files) { echo "no files found matching *eat*\n"; print_r(scandir($dir)); exit; }
foreach ($files as $f) {
    echo "=== $f ===\n";
    echo file_get_contents($f);
    echo "\n\n";
}
