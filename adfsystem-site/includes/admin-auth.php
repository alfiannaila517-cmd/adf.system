<?php
/**
 * Session-based admin auth helpers for the website admin panel.
 */

require_once __DIR__ . '/admin-config.php';

function adf_admin_session_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('adf_admin_sess');
        session_start();
    }
}

function adf_admin_is_logged_in(): bool
{
    adf_admin_session_start();
    return !empty($_SESSION['adf_admin_user']);
}

function adf_admin_require_login(): void
{
    adf_admin_session_start();
    if (empty($_SESSION['adf_admin_user'])) {
        header('Location: login.php');
        exit;
    }
}

function adf_admin_attempt_login(string $username, string $password): bool
{
    adf_admin_session_start();
    if (hash_equals(ADMIN_USERNAME, $username) && password_verify($password, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['adf_admin_user'] = $username;
        return true;
    }
    return false;
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
