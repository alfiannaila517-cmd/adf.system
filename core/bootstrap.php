<?php

/**
 * ADF Platform — bootstrap inti. Dimuat oleh setiap titik masuk (public/*.php, cron, tools).
 *
 * Struktur:
 *   core/        mesin bersama (sama untuk semua bisnis)
 *   tenants/     satu folder per bisnis: tenant.php (konfigurasi & fitur), hooks.php (logika khusus), modules/, website/ …
 *   public/      satu-satunya folder yang bisa dibuka dari internet
 *   storage/     file per bisnis (di VPS: /srv/adf/storage, di luar git)
 *   secrets      password & API key (di VPS: /srv/adf/secrets, di luar git)
 */

declare(strict_types=1);

define('ADF_ROOT', dirname(__DIR__));
define('ADF_CORE', ADF_ROOT . '/core');
define('ADF_TENANTS', ADF_ROOT . '/tenants');

date_default_timezone_set('Asia/Jakarta');

/** Folder di luar git: pakai variabel lingkungan bila ada, lalu lokasi VPS, lalu folder lokal (pengembangan). */
function adf_resolve_dir(string $env, string $vpsPath, string $localPath): string
{
    $fromEnv = getenv($env);
    if (is_string($fromEnv) && $fromEnv !== '') {
        return rtrim($fromEnv, '/');
    }
    return is_dir($vpsPath) ? $vpsPath : $localPath;
}

define('ADF_STORAGE', adf_resolve_dir('ADF_STORAGE_DIR', '/srv/adf/storage', ADF_ROOT . '/storage'));
define('ADF_SECRETS', adf_resolve_dir('ADF_SECRETS_DIR', '/srv/adf/secrets', ADF_ROOT . '/.secrets'));

require_once ADF_CORE . '/Secrets.php';
require_once ADF_CORE . '/Hooks.php';
require_once ADF_CORE . '/Tenant.php';
