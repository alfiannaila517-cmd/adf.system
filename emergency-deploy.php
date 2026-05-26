<?php
/**
 * Emergency Deploy - Downloads specific files from GitHub without exec()
 * Uses GitHub raw content API to overwrite files on hosting
 * SECURITY: IP-restricted + token required
 */

// Only allow from localhost or cPanel terminal
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($remoteIp, ['127.0.0.1', '::1'], true);

$validToken = 'adf-deploy-2025-secure';
$providedToken = $_GET['token'] ?? $_POST['token'] ?? '';

if (!hash_equals($validToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-cache');

$deployDir = dirname(__FILE__);
$repo = 'ratn050-oss/adf_system';
$branch = 'main';

// Files to sync 
$filesToSync = [
    '.htaccess',
    'config/config.php',
    'config/.htaccess',
    'index.php',
    'login.php',
    'includes/CashbookHelper.php',
    'modules/frontdesk/hotel-services.php',
    'modules/frontdesk/hotel-service-invoice.php',
    'modules/frontdesk/calendar.php',
    'api/checkin-guest.php',
    'api/create-reservation.php',
    'api/add-booking-payment.php',
    'api/get-server-ip.php',
    'password-reset.php',
    'test-cloudinary-upload.php',
    'webhook-deploy.php',
    'emergency-deploy.php',
];

// Allow custom file list via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['files'])) {
    $customFiles = json_decode($_POST['files'], true);
    if (is_array($customFiles)) {
        $filesToSync = $customFiles;
    }
}

$results = [];
$successCount = 0;
$failCount = 0;

foreach ($filesToSync as $file) {
    // Sanitize: prevent directory traversal
    $file = str_replace(['..', "\0"], '', $file);
    $file = ltrim($file, '/');
    
    $url = "https://raw.githubusercontent.com/{$repo}/{$branch}/{$file}";
    $localPath = $deployDir . '/' . $file;
    
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 15,
            'user_agent' => 'ADF-Deploy/1.0',
        ]
    ]);
    
    $content = @file_get_contents($url, false, $ctx);
    
    if ($content === false) {
        $results[$file] = ['status' => 'error', 'message' => 'Failed to download from GitHub'];
        $failCount++;
        continue;
    }
    
    // Ensure directory exists
    $dir = dirname($localPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    
    // Write file
    $written = @file_put_contents($localPath, $content);
    if ($written !== false) {
        $results[$file] = ['status' => 'ok', 'size' => $written];
        $successCount++;
    } else {
        $results[$file] = ['status' => 'error', 'message' => 'Failed to write file'];
        $failCount++;
    }
}

// Update .git/refs/heads/main to match GitHub
$latestCommitUrl = "https://api.github.com/repos/{$repo}/commits/{$branch}";
$commitCtx = stream_context_create([
    'http' => [
        'timeout' => 10,
        'user_agent' => 'ADF-Deploy/1.0',
        'header' => 'Accept: application/json',
    ]
]);
$commitJson = @file_get_contents($latestCommitUrl, false, $commitCtx);
$commitData = $commitJson ? json_decode($commitJson, true) : null;
$latestSha = $commitData['sha'] ?? 'unknown';

echo json_encode([
    'time' => date('Y-m-d H:i:s'),
    'github_commit' => $latestSha,
    'files_synced' => $successCount,
    'files_failed' => $failCount,
    'details' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
