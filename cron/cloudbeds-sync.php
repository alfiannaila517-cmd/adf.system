<?php

// Sinkron otomatis Cloudbeds → sistem (booking OTA baru, pembatalan, blok kamar) untuk semua bisnis
// yang punya API key Cloudbeds DAN "Sinkron otomatis" diaktifkan di Front Desk → Pengaturan → Cloudbeds.
//
// cPanel → Cron Jobs, tiap 5 menit:
//   */5 * * * *   /usr/local/bin/php /home/adfb2574/public_html/cron/cloudbeds-sync.php >> /home/adfb2574/cloudbeds_sync_log.txt 2>&1
//
// Rentang: check-in 14 hari lalu s/d 120 hari ke depan. Aturannya sama dengan tombol "Jalankan sinkron"
// (tautkan / buat booking / batalkan / blok kamar); yang "Perlu dicek" tidak dijalankan.
// Tambah argumen "force" untuk menjalankan walau sinkron otomatis belum diaktifkan (tes).
// Hanya bisa dijalankan dari command line, tidak dari browser.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ACCESS', true);
date_default_timezone_set('Asia/Jakarta');
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/CloudbedsSync.php';
require_once dirname(__DIR__) . '/includes/CloudbedsRates.php';

$force = in_array('force', $argv, true);
$_SESSION = [];

// Satu proses saja: lewati bila sinkron sebelumnya masih berjalan
$lock = fopen(sys_get_temp_dir() . '/adf-cloudbeds-sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " skip: sinkron sebelumnya masih berjalan\n";
    exit(0);
}
set_time_limit(0);

$from = date('Y-m-d', strtotime('-14 days'));
$to = date('Y-m-d', strtotime('+120 days'));

foreach (glob(dirname(__DIR__) . '/config/businesses/*.php') ?: [] as $bf) {
    $slug = basename($bf, '.php');
    try {
        $cfg = require $bf;
        if (empty($cfg['database'])) {
            continue;
        }
        // Cek koneksi dulu (switchDatabase() berhenti total bila database tidak ada)
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

        $res = (new CloudbedsSync($bdb, $cb))->apply($from, $to, 0);
        $d = $res['done'] ?? [];
        $summary = $res['ok']
            ? sprintf('baru %d, harga %d, kamar %d, taut %d, batal %d, blok %d, cabut %d, kirim status %d, kirim baru %d, kirim blok %d, kirim bayar %d, dicek %d%s',
                $d['create'] ?? 0, $d['price'] ?? 0, $d['room'] ?? 0, $d['link'] ?? 0, $d['cancel'] ?? 0, $d['block'] ?? 0, $d['unblock'] ?? 0, $d['push_status'] ?? 0, $d['push_create'] ?? 0, $d['push_block'] ?? 0, $d['push_pay'] ?? 0,
                $res['counts']['warn'] ?? 0, !empty($d['errors']) ? ', GAGAL ' . count($d['errors']) : '')
            : 'GAGAL: ' . $res['detail'];
        $cb->saveSetting('cloudbeds_last_auto_sync', json_encode([
            'at' => date('Y-m-d H:i:s'),
            'ok' => (bool)$res['ok'] && empty($d['errors']),
            'summary' => $summary,
        ] + CloudbedsSync::issueList($res), JSON_UNESCAPED_UNICODE));
        echo date('Y-m-d H:i:s') . " [{$slug}] {$summary}\n";

        // Harga & ketersediaan (90 hari ke depan) diperbarui maks. sekali per jam
        $rates = new CloudbedsRates($bdb, $cb);
        $lastRates = $rates->lastFetched();
        if (!$lastRates || time() - strtotime($lastRates) > 3300 || $force) {
            $rr = $rates->refresh(date('Y-m-d'), date('Y-m-d', strtotime('+90 days')));
            echo date('Y-m-d H:i:s') . " [{$slug}] harga: " . ($rr['ok'] ? $rr['rows'] . ' baris' : 'GAGAL ' . $rr['detail']) . "\n";
        }
    } catch (\Throwable $e) {
        echo date('Y-m-d H:i:s') . " [{$slug}] error: " . $e->getMessage() . "\n";
    }
}

flock($lock, LOCK_UN);
