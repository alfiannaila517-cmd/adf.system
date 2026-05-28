<?php
if (!defined('APP_ACCESS')) define('APP_ACCESS', true);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/business_helper.php';

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    header('Location: ' . BASE_URL . '/pwf-login.php');
    exit;
}

if (function_exists('setActiveBusinessId') && (getActiveBusinessId() ?? '') !== 'pwf-furniture') {
    @setActiveBusinessId('pwf-furniture');
}

$currentUser = $auth->getCurrentUser();
