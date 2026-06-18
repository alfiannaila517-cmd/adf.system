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

$repo = 'arifnarayana88-collab/adf.system';
$branch = 'main';

function githubRawUrl(string $repo, string $ref, string $path): string
{
    return "https://raw.githubusercontent.com/{$repo}/{$ref}/{$path}";
}

function resolveLatestRef(string $repo, string $fallbackRef = 'main'): string
{
    $api = "https://api.github.com/repos/{$repo}/git/ref/heads/{$fallbackRef}";
    $ctx = stream_context_create(['http' => [
        'timeout' => 12,
        'user_agent' => 'ADF-Force-Deploy/1.0',
        'header' => "Accept: application/vnd.github.v3+json\r\n",
    ]]);
    $json = @file_get_contents($api, false, $ctx);
    if ($json === false) {
        return $fallbackRef;
    }
    $data = json_decode($json, true);
    $sha = $data['object']['sha'] ?? '';
    return $sha !== '' ? $sha : $fallbackRef;
}

function fetchLatestContent(string $repo, string $ref, string $path): ?string
{
    $url = githubRawUrl($repo, $ref, $path);
    $ctx = stream_context_create(['http' => [
        'timeout' => 20,
        'user_agent' => 'ADF-Force-Deploy/1.0',
    ]]);
    $content = @file_get_contents($url, false, $ctx);
    return $content === false ? null : $content;
}

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

echo "Source (local fallback): {$sourcePublic}\n";
echo "Target: {$targetBase}\n\n";

$latestRef = resolveLatestRef($repo, $branch);
echo "GitHub ref: {$latestRef}\n\n";

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

function writeContentSafe(string $content, string $dst, int &$ok, int &$fail): void
{
    $dir = dirname($dst);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        echo "FAILED mkdir: {$dir}\n";
        $fail++;
        return;
    }
    if (@file_put_contents($dst, $content) === false) {
        echo "FAILED write: {$dst}\n";
        $fail++;
        return;
    }
    @chmod($dst, 0644);
    echo "OK: {$dst}\n";
    $ok++;
}

function deployFromRepoOrLocal(
    string $repo,
    string $ref,
    string $repoPath,
    string $localPath,
    string $destPath,
    int &$ok,
    int &$fail
): void {
    $content = fetchLatestContent($repo, $ref, $repoPath);
    if ($content !== null) {
        writeContentSafe($content, $destPath, $ok, $fail);
        return;
    }
    copyFileSafe($localPath, $destPath, $ok, $fail);
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
    deployFromRepoOrLocal(
        $repo,
        $latestRef,
        'website/public/' . $f,
        $sourcePublic . '/' . $f,
        $targetBase . '/' . $f,
        $ok,
        $fail
    );
}

deployFromRepoOrLocal(
    $repo,
    $latestRef,
    'website/public/.htaccess.production',
    $sourcePublic . '/.htaccess.production',
    $targetBase . '/.htaccess',
    $ok,
    $fail
);
deployFromRepoOrLocal(
    $repo,
    $latestRef,
    'website/config/config.php',
    $sourceConfig,
    $targetBase . '/config/config.php',
    $ok,
    $fail
);

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

        $relative = str_replace('\\', '/', $rel);
        deployFromRepoOrLocal(
            $repo,
            $latestRef,
            'website/public/' . $srcRel . '/' . $relative,
            $item->getPathname(),
            $dst,
            $ok,
            $fail
        );
    }
}

// Ensure a public/ mirror exists as fallback for legacy structure.
$publicMirror = $targetBase . '/public';
@mkdir($publicMirror, 0755, true);
foreach ($rootFiles as $f) {
    deployFromRepoOrLocal(
        $repo,
        $latestRef,
        'website/public/' . $f,
        $sourcePublic . '/' . $f,
        $publicMirror . '/' . $f,
        $ok,
        $fail
    );
}
deployFromRepoOrLocal(
    $repo,
    $latestRef,
    'website/public/.htaccess.production',
    $sourcePublic . '/.htaccess.production',
    $publicMirror . '/.htaccess',
    $ok,
    $fail
);

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
