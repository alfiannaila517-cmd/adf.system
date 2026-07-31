<?php

header('Content-Type: application/json');
header('Cache-Control: no-cache');

// GitHub webhook auto-deploy
// Secret token for security verification
define('WEBHOOK_SECRET', 'adf-webhook-2026-secure');

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

// Verify GitHub signature
if (!empty($signature)) {
    $expected = 'sha256=' . hash_hmac('sha256', $payload, WEBHOOK_SECRET);
    if (!hash_equals($expected, $signature)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
        exit;
    }
}

// Also allow manual trigger with token
$manualToken = $_GET['token'] ?? '';
$isManual = ($manualToken === WEBHOOK_SECRET);

if (empty($signature) && !$isManual) {
    echo json_encode(['status' => 'disabled', 'message' => 'Use GitHub webhook or ?token=', 'time' => date('Y-m-d H:i:s')]);
    exit;
}

$deployPath = '/home/adfb2574/public_html';
$repoPath   = dirname(__FILE__);

// Copy all files from repo to deploy path
$output = [];
$result = 0;

exec("cp -R " . escapeshellarg($repoPath) . "/. " . escapeshellarg($deployPath) . "/ 2>&1", $output, $result);

echo json_encode([
    'status'    => $result === 0 ? 'success' : 'error',
    'message'   => $result === 0 ? 'Deploy completed' : 'Deploy failed',
    'output'    => implode("\n", $output),
    'time'      => date('Y-m-d H:i:s'),
    'triggered' => $isManual ? 'manual' : 'github-webhook',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
