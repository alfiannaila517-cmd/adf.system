<?php
/** Kembali dari Google: cocokkan email Google dengan user ADF System, lalu masuk. */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/google-login.php';

$auth = new Auth();
$auth->startSession();

[$email, $reason] = adfGoogleHandleCallback($_GET);
if ($email !== null) {
    $user = adfGoogleFindUser($email);
    if ($user === null) {
        error_log('google login rejected for ' . $email . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
        $reason = 'Akun Google ' . $email . ' tidak terdaftar di ADF System. Gunakan email yang sama dengan email user Anda.';
    } else {
        adfGoogleCompleteLogin($user); // redirect + exit
    }
}
$_SESSION['adf_login_error'] = $reason;
header('Location: login.php');
exit;
