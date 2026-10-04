<?php

/**
 * Session-based admin auth helpers for the website admin panel.
 */

require_once __DIR__ . '/admin-config.php';
require_once __DIR__ . '/users-store.php';

const ADF_ADMIN_IDLE_TIMEOUT = 7200; // keluar otomatis setelah 2 jam tidak aktif

function adf_admin_session_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('adf_admin_sess');
        // Cookie sesi tidak bisa dibaca JavaScript, hanya lewat HTTPS, dan tidak dikirim dari situs lain.
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function adf_admin_is_logged_in(): bool
{
    adf_admin_session_start();
    // Hanya sesi yang sudah lolos verifikasi email (mfa) yang dianggap login.
    if (empty($_SESSION['adf_admin']['id']) || empty($_SESSION['adf_admin']['mfa'])) {
        return false;
    }
    if (time() - (int) ($_SESSION['adf_admin']['last_seen'] ?? 0) > ADF_ADMIN_IDLE_TIMEOUT) {
        adf_admin_logout();
        return false;
    }
    $_SESSION['adf_admin']['last_seen'] = time();
    return true;
}

function adf_admin_require_login(): void
{
    if (!adf_admin_is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Redirects to the dashboard with an error if the current user isn't an admin.
 * Use on pages that only role 'admin' may access (Pengguna, Pembayaran).
 */
function adf_admin_require_role(string $role): void
{
    adf_admin_require_login();
    if (($_SESSION['adf_admin']['role'] ?? '') !== $role) {
        header('Location: index.php?denied=1');
        exit;
    }
}

function adf_admin_current_user(): ?array
{
    adf_admin_session_start();
    return $_SESSION['adf_admin'] ?? null;
}

/** Langkah 1: cek username/email + password. Return user kalau benar (belum login). */
function adf_admin_check_password(string $usernameOrEmail, string $password): ?array
{
    $user = adf_users_find_by_username($usernameOrEmail) ?? adf_users_find_by_email($usernameOrEmail);
    if ($user !== null && password_verify($password, $user['password_hash'])) {
        return $user;
    }
    // Tetap hitung hash walau user tidak ada, supaya waktu respons tidak membocorkan username yang terdaftar.
    password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
    return null;
}

/** Langkah terakhir: buat sesi login (setelah lolos verifikasi email / perangkat tepercaya). */
function adf_admin_complete_login(array $user): void
{
    adf_admin_session_start();
    session_regenerate_id(true);
    unset($_SESSION['adf_pending_login']);
    $_SESSION['adf_admin'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role'],
        'mfa' => true,
        'login_at' => time(),
        'last_seen' => time(),
    ];
}

function adf_admin_logout(): void
{
    adf_admin_session_start();
    $_SESSION = [];
    session_destroy();
}

function adf_admin_csrf_token(): string
{
    adf_admin_session_start();
    if (empty($_SESSION['adf_csrf'])) {
        $_SESSION['adf_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['adf_csrf'];
}

function adf_admin_csrf_check(?string $token): bool
{
    adf_admin_session_start();
    return !empty($_SESSION['adf_csrf']) && is_string($token) && hash_equals($_SESSION['adf_csrf'], $token);
}

function adf_admin_reset_token_path(): string
{
    return __DIR__ . '/../data/password-reset.json';
}

/**
 * Generates a one-time password-reset token (valid 30 minutes) for a specific
 * user id and stores only its hash on disk. Returns the plaintext token to
 * embed in the email link.
 */
function adf_admin_create_reset_token(string $userId): string
{
    $token = bin2hex(random_bytes(32));
    $data = [
        'user_id' => $userId,
        'token_hash' => hash('sha256', $token),
        'expires_at' => time() + 1800,
    ];
    file_put_contents(adf_admin_reset_token_path(), json_encode($data), LOCK_EX);
    return $token;
}

/**
 * Returns the user id tied to a valid, unexpired token, or null if invalid/expired.
 */
function adf_admin_verify_reset_token(string $token): ?string
{
    $path = adf_admin_reset_token_path();
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || empty($data['token_hash']) || empty($data['expires_at']) || empty($data['user_id'])) {
        return null;
    }
    if (time() > (int) $data['expires_at']) {
        return null;
    }
    return hash_equals($data['token_hash'], hash('sha256', $token)) ? (string) $data['user_id'] : null;
}

function adf_admin_clear_reset_token(): void
{
    $path = adf_admin_reset_token_path();
    if (is_file($path)) {
        unlink($path);
    }
}
