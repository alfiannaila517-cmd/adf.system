<?php

/**
 * Kirim ke Cloudbeds SEGERA setelah aksi di sistem (reservasi dibuat, check-in/out, batal, blok kamar),
 * dijalankan SETELAH respons dikirim ke browser sehingga staf tidak menunggu. Gagal di sini tidak
 * memengaruhi aksi utama; sinkron berkala (cron / halaman dibuka) mencoba lagi.
 */
if (!function_exists('cloudbedsPushAfterResponse')) {
    function cloudbedsPushAfterResponse($db, array $bookingIds, array $blockIds = []): void
    {
        $bookingIds = array_values(array_filter(array_map('intval', $bookingIds)));
        $blockIds = array_values(array_filter(array_map('intval', $blockIds)));
        if (!$bookingIds && !$blockIds) return;
        try {
            $key = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_api_key'");
            $push = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_push_enabled'");
            $pay = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_pay_enabled'");
            if (empty($key['setting_value']) || (($push['setting_value'] ?? '0') !== '1' && ($pay['setting_value'] ?? '0') !== '1')) return;
        } catch (\Throwable $e) {
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        ignore_user_abort(true);
        @set_time_limit(90);

        // Tunggu sebentar bila sinkron lain sedang berjalan (maks. ±15 detik); selebihnya diurus sinkron berkala
        $lock = @fopen(sys_get_temp_dir() . '/adf-cloudbeds-sync.lock', 'c');
        $got = false;
        for ($i = 0; $lock && $i < 30; $i++) {
            if (flock($lock, LOCK_EX | LOCK_NB)) { $got = true; break; }
            usleep(500000);
        }
        if (!$got) {
            error_log('Cloudbeds push: sinkron lain masih berjalan, dilewati (akan dikirim sinkron berikutnya)');
            return;
        }
        try {
            require_once __DIR__ . '/CloudbedsSync.php';
            (new CloudbedsSync($db, new CloudbedsClient($db)))->pushFor($bookingIds, $blockIds, $userId);
        } catch (\Throwable $e) {
            error_log('Cloudbeds push: ' . $e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
        }
    }
}
