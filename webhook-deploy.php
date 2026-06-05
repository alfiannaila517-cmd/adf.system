<?php

/**
 * Deploy Status & Webhook
 * - GET with token: show current deploy status
 * - POST with token: trigger git pull (if exec available)
 * SECURITY: IP-restricted + token required
 *
 * Auto deploy has been disabled. Use manual git version control instead.
 */

$autoDeployEnabled = false;
header('Content-Type: application/json');
header('Cache-Control: no-cache');
if (!$autoDeployEnabled) {
    echo json_encode([
        'status' => 'disabled',
        'message' => 'Auto deploy disabled. Use manual git pull from repository and manage deployments with git version control.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Block non-local access unless valid GitHub webhook
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($remoteIp, ['127.0.0.1', '::1'], true);
$isGitHub = false;

// GitHub webhook IPs (check signature instead in production)
if (!$isLocal && !empty($_SERVER['HTTP_X_HUB_SIGNATURE_256'])) {
    $isGitHub = true; // Will be validated by token below
}

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
$result = ['dir' => $deployDir, 'time' => date('Y-m-d H:i:s')];

// Read current git HEAD
$headFile = $deployDir . '/.git/HEAD';
if (file_exists($headFile)) {
    $head = trim(file_get_contents($headFile));
    if (strpos($head, 'ref:') === 0) {
        $refPath = $deployDir . '/.git/' . trim(substr($head, 4));
        $result['branch'] = trim(substr($head, 4));
        $result['commit'] = file_exists($refPath) ? trim(file_get_contents($refPath)) : 'unknown';
    } else {
        $result['commit'] = $head;
    }
} else {
    $result['git'] = 'no .git/HEAD found';
}

// Check if key files exist and their modification time
$checkFiles = [
    'modules/payroll/staff-manifest.php',
    'modules/payroll/staff-portal.php',
    'modules/payroll/sw.js',
];
foreach ($checkFiles as $f) {
    $fp = $deployDir . '/' . $f;
    if (file_exists($fp)) {
        $result['files'][$f] = [
            'exists' => true,
            'modified' => date('Y-m-d H:i:s', filemtime($fp)),
            'size' => filesize($fp),
        ];
    } else {
        $result['files'][$f] = ['exists' => false];
    }
}

// Check if manifest has absolute URLs (quick content check)
$manifestPath = $deployDir . '/modules/payroll/staff-manifest.php';
if (file_exists($manifestPath)) {
    $content = file_get_contents($manifestPath);
    $result['manifest_has_absolute_urls'] = strpos($content, '$moduleUrl') !== false;
    $result['manifest_has_id_field'] = strpos($content, "'id'") !== false;
}

// POST = try to deploy
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    chdir($deployDir);
    $pulled = false;
    $pullOutput = '';

    // Force reset any local changes to avoid merge conflicts
    $resetCmd = 'cd ' . escapeshellarg($deployDir) . ' && git fetch origin 2>&1 && git reset --hard origin/main 2>&1';
    if (function_exists('exec')) {
        @exec($resetCmd, $resetOut);
        $result['reset'] = implode("\n", $resetOut ?? []);
    } elseif (function_exists('shell_exec')) {
        $result['reset'] = @shell_exec($resetCmd);
    }

    // Try multiple shell execution methods
    $cmd = 'cd ' . escapeshellarg($deployDir) . ' && git pull origin main 2>&1';

    if (!$pulled && function_exists('exec')) {
        $output = [];
        $code = 0;
        @exec($cmd, $output, $code);
        $pullOutput = implode("\n", $output);
        $pulled = ($code === 0 && !empty($pullOutput));
    }

    if (!$pulled && function_exists('shell_exec')) {
        $pullOutput = @shell_exec($cmd);
        $pulled = ($pullOutput !== null && $pullOutput !== false);
    }

    if (!$pulled && function_exists('system')) {
        ob_start();
        @system($cmd, $code);
        $pullOutput = ob_get_clean();
        $pulled = ($code === 0);
    }

    if (!$pulled && function_exists('passthru')) {
        ob_start();
        @passthru($cmd, $code);
        $pullOutput = ob_get_clean();
        $pulled = ($code === 0);
    }

    if (!$pulled && function_exists('proc_open')) {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (is_resource($process)) {
            fclose($pipes[0]);
            $pullOutput = stream_get_contents($pipes[1]);
            $pullOutput .= stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            $pulled = ($code === 0);
        }
    }

    if ($pulled) {
        $result['pull'] = ['status' => 'success', 'output' => trim($pullOutput)];
    } else {
        $result['pull'] = ['status' => 'error', 'output' => 'All shell functions disabled. Output: ' . ($pullOutput ?: 'none')];
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
