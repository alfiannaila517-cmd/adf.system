<?php

// Impor CEPAT reservasi Cloudbeds baru (OTA / booking engine) → sistem, supaya booking baru cepat terlihat di ADF dan
// kamar tidak bisa terjual dua kali. Hanya membuat booking baru / menautkan yang sudah ada: tanpa cek harga, kamar,
// saldo, pembayaran, atau blok (itu tetap tugas cron/cloudbeds-sync.php tiap 5 menit).
//
// cPanel → Cron Jobs, TIAP MENIT:
//   * * * * *   /usr/local/bin/php /home/adfb2574/public_html/cron/cloudbeds-quick.php >> /home/adfb2574/cloudbeds_quick_log.txt 2>&1
//
// Hanya berjalan untuk bisnis yang "Sinkron otomatis"-nya aktif (Front Desk → Pengaturan → Cloudbeds).
// Memakai kunci yang sama dengan sinkron penuh: tidak pernah bersamaan, dan dilewati bila sinkron penuh sedang berjalan.
// Hanya bisa dijalankan dari command line.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ACCESS', true);
date_default_timezone_set('Asia/Jakarta');
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/CloudbedsSync.php';

$force = in_array('force', $argv, true);
$_SESSION = [];

$lock = fopen(sys_get_temp_dir() . '/adf-cloudbeds-sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // sinkron lain sedang berjalan: tiap menit akan dicoba lagi (tanpa mencetak apa pun agar log tidak penuh)
}
set_time_limit(100);

$from = date('Y-m-d', strtotime('-1 day'));
$to = date('Y-m-d', strtotime('+120 days'));

foreach (glob(dirname(__DIR__) . '/config/businesses/*.php') ?: [] as $bf) {
    $slug = basename($bf, '.php');
    try {
        $cfg = require $bf;
        if (empty($cfg['database'])) {
            continue;
        }
        $dbName = $cfg['database'];
        if (strpos($dbName, 'adfb2574_') !== 0 && strpos($dbName, 'adf_') === 0 && strpos((string)DB_NAME, 'adfb2574_') === 0) {
            $dbName = 'adfb2574_' . substr($dbName, 4);
        }
        try {
            new PDO('mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        } catch (PDOException $pe) {
            continue;
        }
        $bdb = Database::switchDatabase($cfg['database']);
        if (!$bdb->fetchOne("SHOW TABLES LIKE 'settings'") || !$bdb->fetchOne("SHOW TABLES LIKE 'bookings'")) {
            continue;
        }
        $cb = new CloudbedsClient($bdb);
        if (!$cb->isConfigured()) {
            continue;
        }
        $auto = $bdb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_auto_sync'");
        if (($auto['setting_value'] ?? '0') !== '1' && !$force) {
            continue;
        }
        $t0 = microtime(true);
        $res = (new CloudbedsSync($bdb, $cb))->importNewOnly($from, $to, 0);
        $d = $res['done'] ?? [];
        $created = (int)($d['create'] ?? 0);
        $linked = (int)($d['link'] ?? 0);
        $errs = $d['errors'] ?? [];
        $warns = $res['plan_warns'] ?? [];
        // Cetak hanya bila ada kejadian (baru / taut / galat / peringatan), supaya log tidak membengkak
        if (!$res['ok'] || $created || $linked || $errs || $warns) {
            echo date('Y-m-d H:i:s') . " [{$slug}] " . ($res['ok'] ? "baru {$created}, taut {$linked}" . ($errs ? ', GAGAL ' . count($errs) : '') : 'GAGAL: ' . ($res['detail'] ?? '')) . sprintf(' (%.1fs)', microtime(true) - $t0) . "\n";
            foreach (array_slice($warns, 0, 5) as $w) {
                echo '   ! ' . ($w['label'] ?? '') . ' — ' . ($w['msg'] ?? '') . "\n";
            }
            foreach (array_slice($errs, 0, 3) as $e) {
                echo '   x ' . $e . "\n";
            }
        }
    } catch (\Throwable $e) {
        echo date('Y-m-d H:i:s') . " [{$slug}] error: " . $e->getMessage() . "\n";
    }
}

flock($lock, LOCK_UN);
