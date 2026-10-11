<?php
/** Mulai login dengan Google (redirect ke halaman pilih akun Google). */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google-login.php';

$auth = new Auth();
$auth->startSession();
$url = adfGoogleAuthUrl();
header('Location: ' . ($url ?: 'login.php'));
exit;
