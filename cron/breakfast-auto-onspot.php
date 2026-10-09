<?php

// Sarapan: kamar yang belum memilih menu sampai pukul 05:00 pagi sarapan otomatis menjadi "on the spot"
// (tamu memilih langsung di restoran). Berlaku juga untuk tiap kamar pada booking grup.
//
// cPanel → Cron Jobs: jalankan tiap 10 menit antara pukul 05:00 dan 10:00 (WIB), mis.:
//   */10 5-9 * * *   /usr/local/bin/php /home/adfb2574/public_html/cron/breakfast-auto-onspot.php >> /home/adfb2574/breakfast_auto_log.txt 2>&1
// (Jam cron mengikuti zona waktu server; sesuaikan bila server tidak memakai WIB.)
// Hanya bisa dijalankan dari command line.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ACCESS', true);
date_default_timezone_set('Asia/Jakarta');
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/BreakfastAutoSpot.php';

$_SESSION = [];
$total = 0;

foreach (glob(dirname(__DIR__) . '/config/businesses/*.php') ?: [] as $bf) {
    $slug = basename($bf, '.php');
    try {
        $cfg = require $bf;
        if (empty($cfg['database']) || ($cfg['business_type'] ?? '') !== 'hotel') {
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
        if (!$bdb->fetchOne("SHOW TABLES LIKE 'breakfast_guest_links'")) {
            continue;
        }
        $n = bf_auto_on_the_spot_run($bdb, $bdb->getConnection());
        $total += $n;
        echo date('Y-m-d H:i:s') . " {$slug}: {$n} kamar otomatis on-the-spot\n";
    } catch (\Throwable $e) {
        echo date('Y-m-d H:i:s') . " {$slug}: ERROR " . $e->getMessage() . "\n";
    }
}
echo date('Y-m-d H:i:s') . " selesai, total {$total}\n";
