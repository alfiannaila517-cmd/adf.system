<?php

/**
 * AJAX endpoint: reveal a developer-readable copy of a user's current password.
 * Only available for passwords set/changed after the password_view feature was added.
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';
require_once __DIR__ . '/includes/password-crypto.php';

header('Content-Type: application/json');

$auth = new DevAuth();
if (!$auth->isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Akses ditolak.']);
    exit;
}

$pdo = $auth->getConnection();
$userId = (int)($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['error' => 'User tidak valid.']);
    exit;
}

$stmt = $pdo->prepare("SELECT password_view FROM users WHERE id = ?");
$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$plain = $row ? decryptDevPassword($row['password_view']) : null;

if ($plain === null) {
    echo json_encode(['error' => 'Password lama belum tersedia untuk dilihat. Silakan set password baru untuk user ini.']);
    exit;
}

echo json_encode(['password' => $plain]);
