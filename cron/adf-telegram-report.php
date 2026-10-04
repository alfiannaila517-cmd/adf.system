<?php

// Tugas terjadwal ADF (jalankan dari cPanel → Cron Jobs):
//
//   Tiap 30 menit : /usr/local/bin/php /home/adfb2574/public_html/cron/adf-telegram-report.php billing
//   Jam 08:00     : /usr/local/bin/php /home/adfb2574/public_html/cron/adf-telegram-report.php due
//   Jam 21:00     : /usr/local/bin/php /home/adfb2574/public_html/cron/adf-telegram-report.php summary
//
// billing = penagihan otomatis semua bisnis: terbitkan tagihan bulanan, kirim email tagihan baru,
//           cek pembayaran & kirim email lunas — tanpa menunggu sistem bisnis dibuka.
// due     = pengingat Telegram tagihan jatuh tempo hari ini / besok / sudah lewat.
// summary = ringkasan harian Telegram.
// Tambah argumen "force" untuk menjalankan ulang walau baru/hari ini sudah dijalankan (tes).
// Hanya bisa dijalankan dari command line, tidak dari browser.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ACCESS', true);
date_default_timezone_set('Asia/Jakarta');
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/adf_report.php';

$mode = $argv[1] ?? 'summary';
if (!in_array($mode, ['summary', 'due', 'billing'], true)) {
    fwrite(STDERR, "Pakai: php adf-telegram-report.php summary|due|billing [force]\n");
    exit(1);
}
$force = ($argv[2] ?? '') === 'force';
$_SESSION = [];
echo date('Y-m-d H:i:s') . " {$mode}: " . ($mode === 'billing' ? adf_billing_sweep($force) : adf_report_run($mode, $force)) . "\n";
