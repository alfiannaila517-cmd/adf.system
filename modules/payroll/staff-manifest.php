<?php
/**
 * Staff Portal PWA Manifest
 * Makes the staff portal installable as mobile app
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=300');

$bizSlug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['b'] ?? '')));
$bizName = 'Staff Portal';

if ($bizSlug) {
    $bizFile = __DIR__ . '/../../config/businesses/' . $bizSlug . '.php';
    if (file_exists($bizFile)) {
        $cfg = require $bizFile;
        $bizName = ($cfg['name'] ?? 'Staff Portal');
    }
}

// Ikon ADF Staff Portal (staff-app-icon.php): PNG persegi berukuran persis — syarat Chrome agar tombol Install muncul.
// Ikon lama (pwa_app_icon / login_logo di DB utama) sama untuk semua bisnis dan sering bukan PNG persegi → tidak dipakai lagi.
// Semua alamat ABSOLUT (dibutuhkan Android untuk membuat WebAPK)
$baseHttpUrl = defined('BASE_URL') ? BASE_URL : '';
$moduleUrl = $baseHttpUrl . '/modules/payroll';
$iconBase = $moduleUrl . '/staff-app-icon.php?v=4&size=';
$startUrl = $moduleUrl . '/staff-portal.php' . ($bizSlug ? '?b=' . urlencode($bizSlug) : '');
$scopeUrl = $moduleUrl . '/';
$manifestId = '/modules/payroll/staff-portal' . ($bizSlug ? '?b=' . $bizSlug : '');

while (ob_get_level()) ob_end_clean();
echo json_encode([
    'id'               => $manifestId,
    'name'             => $bizName . ' — Staff',
    // nama di bawah ikon HP
    'short_name'       => 'Staff Portal',
    'description'      => 'Portal karyawan: absensi, monitoring, cuti, occupancy, breakfast',
    'start_url'        => $startUrl,
    'scope'            => $scopeUrl,
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'theme_color'      => '#0b0e14',
    'background_color' => '#0b0e14',
    'lang'             => 'id',
    'categories'       => ['business', 'productivity'],
    'prefer_related_applications' => false,
    'icons'            => [
        ['src' => $iconBase . '192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $iconBase . '512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        // isi ikon berada di zona aman 80% → aman dipotong bulat/kotak oleh Android
        ['src' => $iconBase . '192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => $iconBase . '512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
