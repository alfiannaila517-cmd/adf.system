<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$token = isset($_GET['token']) ? $_GET['token'] : '';
if ($token !== 'adf-deploy-2025-secure') {
    http_response_code(403);
    die('Forbidden');
}
header('Content-Type: text/plain');
echo "=== DIRECT DEPLOY - Bypass Git ===\n";
echo "PHP: " . PHP_VERSION . "\n\n";

$dir = '/home/adfb2574/public_html';
$repo = 'ratn050-oss/adf_system';
$branch = 'main';

// All files that need to be updated
$files = array(
    'modules/payroll/process.php',
    'modules/payroll/attendance.php',
    'api/fingerprint-webhook.php',
    'includes/header.php',
    'sync.php',
    'test-payroll-debug.php'
);

$success = 0;
$fail = 0;

foreach ($files as $f) {
    echo "--- $f ---\n";
    $url = "https://raw.githubusercontent.com/$repo/$branch/$f";

    $content = false;
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PHP/1.0');
        $content = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http != 200) {
            echo "FAIL download: HTTP $http\n\n";
            $content = false;
        }
    }

    if ($content === false) {
        $ctx = stream_context_create(array('http' => array('timeout' => 30, 'user_agent' => 'PHP/1.0')));
        $content = @file_get_contents($url, false, $ctx);
    }

    if ($content === false) {
        echo "FAIL: download\n\n";
        $fail++;
        continue;
    }

    echo "Downloaded: " . strlen($content) . " bytes\n";

    $target = $dir . '/' . $f;
    $targetDir = dirname($target);
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
    }

    $written = @file_put_contents($target, $content);
    if ($written !== false) {
        echo "OK: written $written bytes\n\n";
        $success++;
    } else {
        echo "FAIL: write error\n\n";
        $fail++;
    }
}

echo "=== RESULT: $success OK, $fail FAIL ===\n";
if ($fail == 0) {
    echo "\nALL FILES DEPLOYED SUCCESSFULLY!\n";
    echo "DELETE this deploy-fix.php from File Manager now.\n";
} else {
    echo "\nSome files failed. Check permissions.\n";
}
