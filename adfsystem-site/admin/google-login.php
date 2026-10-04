<?php
/** Mulai login dengan Google (redirect ke halaman pilih akun Google). */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/google-oauth.php';

adf_admin_session_start();
if (adf_admin_is_logged_in()) {
    header('Location: index.php');
    exit;
}
$url = adf_google_auth_url();
header('Location: ' . ($url ?: 'login.php'));
exit;
