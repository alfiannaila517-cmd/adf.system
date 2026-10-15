<?php
/**
 * Gambar pratinjau link Portal Sarapan (1200x630) untuk WhatsApp — kartu tema sama dengan Layanan Tamu,
 * bertema pemilihan menu sarapan. Selalu berbahasa Inggris.
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/WhatsAppHelper.php';
require_once __DIR__ . '/includes/GuestServices.php';
require_once __DIR__ . '/includes/OgCard.php';

$db = Database::getInstance();
$hotel = '';
$logo = '';
try {
    $hotel = (string)($db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'")['setting_value'] ?? '');
    $pl = trim((string)($db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'breakfast_portal_logo_path'")['setting_value'] ?? ''));
    if ($pl !== '') {
        $logo = strpos($pl, 'http') === 0 ? $pl : GuestServices::baseUrl() . '/' . ltrim($pl, '/');
    } else {
        require_once __DIR__ . '/includes/business_helper.php';
        if (function_exists('getBusinessLogo')) $logo = (string)getBusinessLogo();
    }
} catch (\Throwable $e) {
}
if ($hotel === '' && defined('BUSINESS_NAME')) $hotel = BUSINESS_NAME;

$pills = ['Main Course', 'Drinks', 'Kids Menu', '07:00 – 10:00'];
OgCard::output([
    'eyebrow' => 'BREAKFAST AT',
    'title' => $hotel,
    'tagline' => 'Choose your menu & serving time',
    'pills' => $pills,
    'logo' => $logo,
    'initial' => $hotel,
    'deco' => 'breakfast',
    'cacheDir' => __DIR__ . '/uploads/guest-services',
    'cacheKey' => json_encode(['bf', $hotel, $logo, $pills, 'v1']),
]);
