<?php
/**
 * Kembali dari Google: cocokkan email Google dengan user admin ADF Store, lalu masuk.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/google-oauth.php';

adf_admin_session_start();

[$email, $reason] = adf_google_handle_callback($_GET);
if ($email !== null) {
    $user = adf_users_find_by_email($email);
    if ($user === null) {
        // Tidak ada user dengan email ini → ditolak (dicatat sebagai percobaan gagal).
        adf_sec_record_fail($email);
        error_log('google login rejected for ' . $email . ' from ' . adf_sec_client_ip());
        $reason = 'Akun Google ' . $email . ' tidak terdaftar sebagai admin ADF Store.';
    } elseif (adf_sec_is_locked((string) $user['username'])) {
        $reason = 'Akun sedang dikunci karena terlalu banyak percobaan gagal. Coba lagi dalam 15 menit.';
    } else {
        adf_sec_clear_fails((string) $user['username']);
        adf_admin_complete_login($user);
        adf_sec_login_alert($user, 'akun Google');
        header('Location: index.php');
        exit;
    }
}
$_SESSION['adf_login_error'] = $reason;
header('Location: login.php');
exit;
