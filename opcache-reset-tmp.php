<?php
// TEMPORARY — delete after use. Forces PHP opcode cache to drop stale compiled files.
header('Content-Type: text/plain; charset=utf-8');
if (!function_exists('opcache_reset')) {
    echo "opcache extension not available/enabled on this PHP.\n";
    exit;
}
$ok = opcache_reset();
echo "opcache_reset() result: " . ($ok ? 'TRUE (cleared)' : 'FALSE (failed)') . "\n";
