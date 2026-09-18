<?php

/**
 * Reversible password storage for Developer "view password" feature.
 * Login itself always verifies against the separate one-way bcrypt hash in users.password;
 * this is only an additional, developer-only readable copy.
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

if (!defined('DEV_PW_ENC_KEY')) {
    define('DEV_PW_ENC_KEY', 'ADF-System-DevPasswordView-2026-DoNotShare-Key');
}

function encryptDevPassword(string $plain): string
{
    $iv = openssl_random_pseudo_bytes(16);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', hash('sha256', DEV_PW_ENC_KEY, true), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

function decryptDevPassword(?string $encoded): ?string
{
    if (!$encoded) {
        return null;
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) <= 16) {
        return null;
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', hash('sha256', DEV_PW_ENC_KEY, true), OPENSSL_RAW_DATA, $iv);
    return $plain !== false ? $plain : null;
}

/**
 * Ensure users.password_view column exists (self-heal, safe to call repeatedly).
 */
function ensurePasswordViewColumn(PDO $pdo): void
{
    try {
        $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'password_view'");
        if ($check->rowCount() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN password_view VARCHAR(255) NULL AFTER password");
        }
    } catch (Exception $e) {
        error_log('ensurePasswordViewColumn: ' . $e->getMessage());
    }
}
