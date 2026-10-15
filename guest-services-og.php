<?php
/**
 * Gambar pratinjau link Layanan Tamu (1200x630) — tampil di WhatsApp saat link dikirim.
 * Bukan flyer: kartu sambutan hotel (logo, nama hotel, daftar layanan aktif). Selalu berbahasa Inggris.
 * Gambar dibuat oleh OgCard (cache di uploads/guest-services; berubah otomatis bila nama hotel / logo / layanan berubah).
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/WhatsAppHelper.php';
require_once __DIR__ . '/includes/GuestServices.php';
require_once __DIR__ . '/includes/OgCard.php';

$db = Database::getInstance();
$s = GuestServices::settings($db);

$hotel = '';
try {
    $hotel = (string)($db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'")['setting_value'] ?? '');
} catch (\Throwable $e) {
}
if ($hotel === '' && defined('BUSINESS_NAME')) $hotel = BUSINESS_NAME;

// Layanan aktif -> label
$pills = [];
if (trim($s['gs_wifi_ssid']) !== '') $pills[] = 'Free WiFi';
if (array_filter(GuestServices::flyers($s), fn($f) => $f['cat'] === 'trip')) $pills[] = 'Island Trips';
if ($s['gs_motor_enabled'] === '1') $pills[] = 'Motorbike';
if ($s['gs_taxi_enabled'] === '1') $pills[] = 'Taxi';
foreach (GuestServices::customs($s) as $c) {
    $nm = trim($c['name_en']) !== '' ? $c['name_en'] : $c['name'];
    if ($c['enabled'] === '1' && trim($nm) !== '') $pills[] = $nm;
}

// Logo (logo portal sarapan bila ada, kalau tidak logo bisnis)
$logo = '';
try {
    $pl = trim((string)($db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'breakfast_portal_logo_path'")['setting_value'] ?? ''));
    if ($pl !== '') {
        $logo = strpos($pl, 'http') === 0 ? $pl : GuestServices::baseUrl() . '/' . ltrim($pl, '/');
    } else {
        require_once __DIR__ . '/includes/business_helper.php';
        if (function_exists('getBusinessLogo')) $logo = (string)getBusinessLogo();
    }
} catch (\Throwable $e) {
}

OgCard::output([
    'eyebrow' => 'WELCOME TO',
    'title' => $hotel,
    'tagline' => 'Services & information for your stay',
    'pills' => $pills,
    'logo' => $logo,
    'initial' => $hotel,
    'deco' => 'circles',
    'cacheDir' => __DIR__ . '/uploads/guest-services',
    'cacheKey' => json_encode(['gs', $hotel, $logo, $pills, 'v7']),
]);
