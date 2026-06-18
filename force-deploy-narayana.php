<?php
/**
 * Force deploy website files to addon domain docroot.
 * Usage:
 *   https://adfsystem.online/force-deploy-narayana.php?token=adf-deploy-2025-secure
 */

declare(strict_types=1);

$token = $_GET['token'] ?? '';
if (!hash_equals('adf-deploy-2025-secure', (string)$token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$sourceBase = __DIR__ . '/website';
$sourcePublic = $sourceBase . '/public';
$sourceConfig = $sourceBase . '/config/config.php';

$targets = [
    '/home/adfb2574/public_html/narayanakarimunjawa.com',
    '/home/adfb2574/narayanakarimunjawa.com',
    __DIR__ . '/narayanakarimunjawa.com',
];

$targetBase = null;
foreach ($targets as $candidate) {
    if (is_dir($candidate)) {
        $targetBase = $candidate;
        break;
    }
}

if ($targetBase === null) {
    $targetBase = $targets[0];
    @mkdir($targetBase, 0755, true);
}

if (!is_dir($sourcePublic)) {
    echo "ERROR: Source not found: {$sourcePublic}\n";
    exit;
}

echo "Source: {$sourcePublic}\n";
echo "Target: {$targetBase}\n\n";

$ok = 0;
$fail = 0;

function copyFileSafe(string $src, string $dst, int &$ok, int &$fail): void
{
    $dir = dirname($dst);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        echo "FAILED mkdir: {$dir}\n";
        $fail++;
        return;
    }

    if (!is_file($src)) {
        echo "SKIP missing src: {$src}\n";
        return;
    }

    if (@copy($src, $dst)) {
        @chmod($dst, 0644);
        echo "OK: {$dst}\n";
        $ok++;
    } else {
        echo "FAILED copy: {$src} -> {$dst}\n";
        $fail++;
    }
}

$rootFiles = [
    'index.php',
    'rooms.php',
    'activities.php',
    'destinations.php',
    'booking.php',
    'contact.php',
    'confirmation.php',
    'robots.txt',
    'sitemap.xml',
];

foreach ($rootFiles as $f) {
    copyFileSafe($sourcePublic . '/' . $f, $targetBase . '/' . $f, $ok, $fail);
}

copyFileSafe($sourcePublic . '/.htaccess.production', $targetBase . '/.htaccess', $ok, $fail);
copyFileSafe($sourceConfig, $targetBase . '/config/config.php', $ok, $fail);

$dirMap = [
    'includes' => 'includes',
    'assets/css' => 'assets/css',
    'api' => 'api',
];

foreach ($dirMap as $srcRel => $dstRel) {
    $srcDir = $sourcePublic . '/' . $srcRel;
    $dstDir = $targetBase . '/' . $dstRel;

    if (!is_dir($srcDir)) {
        echo "SKIP missing dir: {$srcDir}\n";
        continue;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $item) {
        $rel = substr($item->getPathname(), strlen($srcDir) + 1);
        $dst = $dstDir . '/' . $rel;

        if ($item->isDir()) {
            if (!is_dir($dst)) {
                @mkdir($dst, 0755, true);
            }
            continue;
        }

        copyFileSafe($item->getPathname(), $dst, $ok, $fail);
    }
}

// Ensure a public/ mirror exists as fallback for legacy structure.
$publicMirror = $targetBase . '/public';
@mkdir($publicMirror, 0755, true);
foreach ($rootFiles as $f) {
    copyFileSafe($sourcePublic . '/' . $f, $publicMirror . '/' . $f, $ok, $fail);
}
copyFileSafe($sourcePublic . '/.htaccess.production', $publicMirror . '/.htaccess', $ok, $fail);

@mkdir($targetBase . '/logs', 0755, true);
@mkdir($targetBase . '/uploads', 0755, true);
@mkdir($targetBase . '/uploads/hero', 0755, true);
@mkdir($targetBase . '/uploads/rooms', 0755, true);

if (function_exists('opcache_reset')) {
    @opcache_reset();
    echo "\nOPcache reset done.\n";
}

echo "\nDONE: {$ok} copied, {$fail} failed\n";
echo "Try now: https://narayanakarimunjawa.com/ and https://narayanakarimunjawa.com/index.php\n";
