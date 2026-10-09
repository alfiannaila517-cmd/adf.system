<?php

/**
 * Kirim ke Cloudbeds SEGERA setelah aksi di sistem (reservasi dibuat, check-in/out, batal, blok kamar),
 * dijalankan SETELAH respons dikirim ke browser sehingga staf tidak menunggu. Gagal di sini tidak
 * memengaruhi aksi utama; sinkron berkala (cron / halaman dibuka) mencoba lagi.
 */
if (!function_exists('cloudbedsPushAfterResponse')) {
    function cloudbedsPushAfterResponse($db, array $bookingIds, array $blockIds = [], bool $edited = false): void
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

        // Tandai edit SEKARANG (sebelum respons dikirim): bila halaman langsung di-reload, sinkron dari Cloudbeds
        // melihat antrean ini dan TIDAK menimpa kamar/tanggal/harga yang baru diubah di sistem.
        if ($edited) {
            try {
                require_once __DIR__ . '/CloudbedsSync.php';
                (new CloudbedsSync($db, new CloudbedsClient($db)))->markEdited($bookingIds);
            } catch (\Throwable $e) {
                error_log('Cloudbeds markEdited: ' . $e->getMessage());
            }
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

        // Reservasi multi-kamar dibuat satu per satu: beri waktu kamar lain grup ini tercipta, lalu semuanya dikirim
        // sebagai SATU reservasi Cloudbeds (kiriman kamar berikutnya mendapati grup sudah tertaut)
        if ($bookingIds) {
            try {
                $in = implode(',', $bookingIds);
                $fresh = $db->fetchOne("SELECT COUNT(*) c FROM bookings WHERE id IN ($in) AND group_id IS NOT NULL AND group_id <> '' AND created_at > NOW() - INTERVAL 30 SECOND");
                if ((int)($fresh['c'] ?? 0) > 0) sleep(8);
            } catch (\Throwable $e) {
            }
        }

        // Tunggu sebentar bila sinkron lain sedang berjalan (maks. ±30 detik); selebihnya diurus sinkron berkala
        $lock = @fopen(sys_get_temp_dir() . '/adf-cloudbeds-sync.lock', 'c');
        $got = false;
        for ($i = 0; $lock && $i < 60; $i++) {
            if (flock($lock, LOCK_EX | LOCK_NB)) { $got = true; break; }
            usleep(500000);
        }
        if (!$got) {
            error_log('Cloudbeds push: sinkron lain masih berjalan, dilewati (akan dikirim sinkron berikutnya)');
            return;
        }
        try {
            require_once __DIR__ . '/CloudbedsSync.php';
            $sync = new CloudbedsSync($db, new CloudbedsClient($db));
            if ($edited) {
                $sync->markEdited($bookingIds);
            }
            $sync->pushFor($bookingIds, $blockIds, $userId);
        } catch (\Throwable $e) {
            error_log('Cloudbeds push: ' . $e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
        }
    }
}
