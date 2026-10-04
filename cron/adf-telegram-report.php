<?php

/**
 * Laporan Telegram terjadwal (jalankan dari cPanel → Cron Jobs):
 *
 *   0 21 * * *  /usr/local/bin/php /home/adfb2574/public_html/cron/adf-telegram-report.php summary
 *   0 8  * * *  /usr/local/bin/php /home/adfb2574/public_html/cron/adf-telegram-report.php due
 *
 * Tambah argumen "force" untuk mengirim ulang walau hari ini sudah terkirim (tes).
 * Hanya bisa dijalankan dari command line, tidak dari browser.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('APP_ACCESS', true);
date_default_timezone_set('Asia/Jakarta');
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/adf_report.php';

$mode = $argv[1] ?? 'summary';
if (!in_array($mode, ['summary', 'due'], true)) {
    fwrite(STDERR, "Pakai: php adf-telegram-report.php summary|due [force]\n");
    exit(1);
}
echo date('Y-m-d H:i:s') . " {$mode}: " . adf_report_run($mode, ($argv[2] ?? '') === 'force') . "\n";
