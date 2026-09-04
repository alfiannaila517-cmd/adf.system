<?php

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/EmailHelper.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['unread' => 0]);
    exit;
}

$activeBizRaw = (string)($_SESSION['active_business_id'] ?? (defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : ''));
$activeBizNorm = strtolower((string)preg_replace('/[^a-z0-9]/', '', $activeBizRaw));
$isDeveloperRole = (($_SESSION['role'] ?? '') === 'developer');
if ($activeBizNorm !== 'narayanahotel' || (!$isDeveloperRole && !$auth->hasPermission('email'))) {
    echo json_encode(['unread' => 0]);
    exit;
}

$db = Database::getInstance();
$emailConfig = EmailHelper::resolveConfig($db);
if ($emailConfig === null) {
    echo json_encode(['unread' => 0]);
    exit;
}

try {
    $emailHelper = new EmailHelper($emailConfig);
    echo json_encode(['unread' => $emailHelper->countUnread()]);
} catch (Throwable $e) {
    echo json_encode(['unread' => 0]);
}
