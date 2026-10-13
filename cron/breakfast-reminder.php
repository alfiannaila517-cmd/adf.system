<?php

// Sarapan: pengingat otomatis jam 22:00 untuk tamu in-house yang BELUM memilih menu (lewat WhatsApp).
// Hanya berjalan bila saklar "Otomatis" di halaman Breakfast menyala (setting breakfast_reminder_auto = 1) dan
// hanya di jendela 22:00-22:59 WIB, jadi aman bila cron terpanggil di jam lain. Satu tamu tidak diingatkan dua kali (log WA).
//
// cPanel → Cron Jobs: sekali sehari pukul 22:00 (WIB), mis.:
//   0 22 * * *   /usr/local/bin/php /home/adfb2574/public_html/cron/breakfast-reminder.php >> /home/adfb2574/breakfast_reminder_log.txt 2>&1
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
require_once dirname(__DIR__) . '/includes/BreakfastReminder.php';

$hour = (int)date('H');
if ($hour !== 22 && !in_array('--force', $argv ?? [], true)) {
    echo date('Y-m-d H:i:s') . " di luar jendela 22:00-22:59, dilewati\n";
    exit(0);
}

$_SESSION = [];
$sent = 0;
$skipped = 0;

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
        if (!bf_rm_auto_enabled($bdb)) {
            echo date('Y-m-d H:i:s') . " {$slug}: pengingat otomatis mati, dilewati\n";
            continue;
        }
        $wa = new WhatsAppHelper($bdb);
        if (!$wa->isConfigured()) {
            echo date('Y-m-d H:i:s') . " {$slug}: WhatsApp gateway belum diatur\n";
            continue;
        }
        foreach (bf_rm_targets($bdb, bf_rm_date()) as $t) {
            if ($t['status'] !== 'ready') {
                $skipped++;
                echo date('Y-m-d H:i:s') . " {$slug}: kamar " . implode(',', $t['rooms']) . " dilewati ({$t['status']})\n";
                continue;
            }
            $res = bf_rm_send_one($wa, $bdb, $t);
            $res['ok'] ? $sent++ : $skipped++;
            echo date('Y-m-d H:i:s') . " {$slug}: kamar " . implode(',', $t['rooms']) . ' ' . ($res['ok'] ? 'terkirim' : 'GAGAL: ' . $res['detail']) . "\n";
            sleep(3); // jeda antar pesan agar nomor WA tidak dianggap spam
        }
    } catch (\Throwable $e) {
        echo date('Y-m-d H:i:s') . " {$slug}: ERROR " . $e->getMessage() . "\n";
    }
}
echo date('Y-m-d H:i:s') . " selesai: {$sent} terkirim, {$skipped} dilewati\n";
