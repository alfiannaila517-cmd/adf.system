<?php

// Sarapan: pengingat otomatis jam 22.00 WIB untuk tamu in-house yang BELUM memilih menu (lewat WhatsApp).
// Hanya berjalan bila saklar "Otomatis jam 22.00" di halaman Breakfast menyala, dan hanya di jam 22.xx WIB (jam diperiksa di
// PHP, jadi zona waktu server tidak berpengaruh). Satu tamu tidak diingatkan dua kali malam itu (log WA).
//
// TIDAK PERLU cron khusus bila cron sinkron Cloudbeds (cron/cloudbeds-sync.php, tiap 5 menit) sudah aktif: pengingat ikut
// dijalankan dari sana. Cron ini hanya untuk bisnis yang tidak memakai Cloudbeds, mis. tiap 10 menit:
//   */10 * * * *   /usr/local/bin/php /home/adfb2574/public_html/cron/breakfast-reminder.php >> /home/adfb2574/breakfast_reminder_log.txt 2>&1
// Tambah argumen "force" untuk menjalankan di luar jam 22 (uji coba, tetap hanya bila saklar menyala).
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

$_SESSION = [];
foreach (bf_rm_run_all(in_array('force', $argv ?? [], true)) as $line) {
    echo $line . "\n";
}
