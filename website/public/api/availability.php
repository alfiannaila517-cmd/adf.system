<?php

/**
 * API publik: ketersediaan kamar untuk tanggal tertentu (dibaca langsung dari sistem ADF).
 * GET check_in=Y-m-d&check_out=Y-m-d&adults=2
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$_cfg = dirname(__DIR__) . '/config/config.php';
if (!file_exists($_cfg)) $_cfg = dirname(__DIR__, 2) . '/config/config.php';
require_once $_cfg;
require_once dirname(__DIR__) . '/includes/booking-engine.php';

try {
    $ci = (string)($_GET['check_in'] ?? '');
    $co = (string)($_GET['check_out'] ?? '');
    if (!nrbValidDate($ci) || !nrbValidDate($co) || $co <= $ci) {
        throw new Exception('Please choose valid check-in and check-out dates.');
    }
    if ($ci < date('Y-m-d')) {
        throw new Exception('Check-in date cannot be in the past.');
    }
    $nights = (int)round((strtotime($co) - strtotime($ci)) / 86400);
    if ($nights > 30) {
        throw new Exception('For stays longer than 30 nights, please contact us via WhatsApp.');
    }
    nrbEnsureQuoteColumns($pdo);
    nrbReleaseExpired($pdo); // hold 24 jam yang habis → kamar tampil tersedia lagi
    $types = nrbAvailability($ci, $co);
    $rows = [];
    foreach ($types as $t) {
        $rows[] = [
            'id' => $t['id'], 'name' => $t['name'], 'price' => $t['price'], 'max' => $t['max'], 'available' => $t['available'],
            'total' => $t['price'] * $nights, 'photos' => $t['photos'], 'amenities' => array_map('nrbAmenity', $t['amenities']), 'description' => $t['description'],
        ];
    }
    echo json_encode(['success' => true, 'nights' => $nights, 'check_in' => $ci, 'check_out' => $co, 'rooms' => $rows]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
