<?php
if (($_GET['token'] ?? '') !== 'adf-deploy-2025-secure') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain');
$dir = __DIR__ . '/config/businesses/';
$files = glob($dir . '*eat*') ?: [];
$files[] = $dir . 'eaat-meet.php';
$files = array_unique(array_filter($files, 'file_exists'));
if (!$files) { echo "no files found\n"; print_r(scandir($dir)); exit; }
foreach ($files as $f) {
    echo "=== $f ===\n";
    echo file_get_contents($f);
    echo "\n\n";
}
