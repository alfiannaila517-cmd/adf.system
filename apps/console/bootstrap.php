<?php

/**
 * Pemuat ADF Console: core platform + sesi + CSRF + tampilan. Dipakai semua halaman di apps/console/public.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/core/bootstrap.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/View.php';
require_once __DIR__ . '/src/Biz.php';
require_once __DIR__ . '/src/Access.php';
require_once __DIR__ . '/src/Integration.php';
require_once __DIR__ . '/src/Legacy.php';
require_once __DIR__ . '/src/StaffPortal.php';
require_once __DIR__ . '/src/Provision.php';
require_once __DIR__ . '/src/Branding.php';
require_once __DIR__ . '/src/Invoice.php';
require_once ADF_CORE . '/ServerHealth.php';

const CONSOLE_DB = 'adf_console';

if (PHP_SAPI !== 'cli') {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    // Gambar dari sistem lama (pratinjau branding login) boleh tampil; selebihnya tetap hanya dari Console sendiri
    $legacyOrigin = (string)preg_replace('#^(https?://[^/]+).*$#', '$1', (string)Secrets::get('LEGACY_BASE_URL', 'https://staging.adfsystem.online'));
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; img-src 'self' data: {$legacyOrigin}; form-action 'self'; frame-ancestors 'none'");

    session_name('adf_console');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => Auth::https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(): bool
{
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function e(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Pesan sekali tampil setelah redirect. */
function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}
