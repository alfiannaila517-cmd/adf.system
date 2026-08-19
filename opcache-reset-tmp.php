<?php
// TEMPORARY — delete after use. Forces PHP opcode cache to drop stale compiled files.
header('Content-Type: text/plain; charset=utf-8');

if (!function_exists('opcache_reset')) {
    echo "opcache extension not available/enabled on this PHP.\n";
    exit;
}

$statusBefore = function_exists('opcache_get_status') ? opcache_get_status(false) : null;
if ($statusBefore) {
    echo "opcache enabled: " . ($statusBefore['opcache_enabled'] ? 'YES' : 'NO') . "\n";
    if (isset($statusBefore['memory_usage'])) {
        echo "cached scripts count: " . ($statusBefore['opcache_statistics']['num_cached_scripts'] ?? 'n/a') . "\n";
    }
}

$ok = opcache_reset();
echo "opcache_reset() result: " . ($ok ? 'TRUE (cleared)' : 'FALSE (failed)') . "\n";
