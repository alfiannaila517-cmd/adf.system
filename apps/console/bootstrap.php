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
require_once __DIR__ . '/src/Contacts.php';
require_once __DIR__ . '/src/StoreSite.php';
require_once __DIR__ . '/src/Provision.php';
require_once __DIR__ . '/src/Branding.php';
require_once __DIR__ . '/src/Invoice.php';
require_once __DIR__ . '/src/Website.php';
require_once __DIR__ . '/src/SubSync.php';
require_once __DIR__ . '/src/Google.php';
require_once ADF_CORE . '/ServerHealth.php';

const CONSOLE_DB = 'adf_console';

if (PHP_SAPI !== 'cli') {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    // Gambar https boleh tampil (pratinjau branding sistem lama, foto website di Cloudinary); skrip/gaya tetap hanya dari Console
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; img-src 'self' data: https:; form-action 'self'; frame-ancestors 'none'");

    // API mesin-ke-mesin (sinkron langganan sistem bisnis) tidak memakai sesi login
    if (!defined('CONSOLE_API')) {
        // Folder sesi sendiri (bila disiapkan di server): pembersih sesi bawaan Ubuntu menghapus sesi diam > 24 menit,
        // padahal Console mengizinkan diam 2 jam (Auth::IDLE_TIMEOUT). Tanpa folder ini tetap memakai bawaan PHP.
        $sessDir = '/srv/adf/storage/console-sessions';
        if (is_dir($sessDir) && is_writable($sessDir)) {
            session_save_path($sessDir);
            ini_set('session.gc_maxlifetime', (string)Auth::IDLE_TIMEOUT);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }
        session_name('adf_console');
        // Cookie bertahan selama batas diam (bukan cookie sementara): di iPhone, aplikasi yang dipasang di layar utama
        // membuang cookie sementara saat ditinggal ke Gmail mengambil kode → "Sesi verifikasi berakhir".
        $cookieOpts = ['path' => '/', 'secure' => Auth::https(), 'httponly' => true, 'samesite' => 'Lax'];
        session_set_cookie_params(['lifetime' => Auth::IDLE_TIMEOUT] + $cookieOpts);
        session_start();
        Auth::followMovedSession();
        // Perpanjang masa berlaku cookie setiap kali Console dibuka (batas 2 jam dihitung dari aktivitas terakhir, bukan dari login)
        if (isset($_COOKIE[session_name()]) && !headers_sent()) {
            setcookie(session_name(), session_id(), ['expires' => time() + Auth::IDLE_TIMEOUT] + $cookieOpts);
        }
    }
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
