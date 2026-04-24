<?php
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$code = trim((string)($_GET['k'] ?? ''));
if ($code === '') {
    http_response_code(400);
    exit('Link tidak valid');
}

try {
    $db = Database::getInstance();
    $link = $db->fetchOne("SELECT token FROM breakfast_guest_links WHERE short_code = ? LIMIT 1", [$code]);
    if (!$link || empty($link['token'])) {
        http_response_code(404);
        exit('Link tidak ditemukan');
    }

    $target = rtrim(BASE_URL, '/') . '/modules/frontdesk/breakfast-guest.php?t=' . urlencode($link['token']);
    header('Location: ' . $target);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    exit('Terjadi kesalahan sistem');
}
