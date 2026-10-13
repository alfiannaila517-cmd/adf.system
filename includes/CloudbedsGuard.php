<?php

/**
 * Pengaman double booking: sebelum reservasi BARU dibuat di sistem, cek langsung ke Cloudbeds apakah ada reservasi aktif
 * (mis. dari OTA) pada tanggal itu yang belum masuk ke sistem. Bila ada, coba impor cepat dulu; bila masih belum masuk
 * (umumnya karena belum ditempatkan nomor kamar di Cloudbeds), pembuatan reservasi DITOLAK dengan penjelasan.
 *
 * - Hanya aktif bila integrasi Cloudbeds terhubung; dapat dimatikan dengan setting cloudbeds_guard = 0.
 * - Bila Cloudbeds tidak bisa dihubungi: tidak menghalangi kerja resepsionis (dicatat di log error).
 * - Ditempatkan SEBELUM cek ketersediaan kamar lokal, supaya booking yang baru terimpor ikut terhitung.
 */
require_once __DIR__ . '/CloudbedsSync.php';

/**
 * @return array{ok:bool, message?:string, imported?:int, checked?:bool}
 */
function cloudbedsGuardCheck($db, string $ci, string $co, bool $override = false, ?CloudbedsClient $client = null): array
{
    try {
        if ($override) {
            error_log('Cloudbeds guard: dilewati oleh pengguna berwenang (' . $ci . ' → ' . $co . ')');
            return ['ok' => true, 'checked' => false];
        }
        $flag = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_guard'");
        if (($flag['setting_value'] ?? '1') === '0') {
            return ['ok' => true, 'checked' => false];
        }
        $cb = $client ?: new CloudbedsClient($db); // $client: hanya untuk pengujian
        if (!$cb->isConfigured()) {
            return ['ok' => true, 'checked' => false];
        }
        // Reservasi beruntun untuk tanggal yang sama (booking grup per kamar) cukup dicek sekali per menit
        $cacheKey = $ci . '|' . $co;
        if (!empty($_SESSION['cb_guard_ok'][$cacheKey]) && time() - (int)$_SESSION['cb_guard_ok'][$cacheKey] < 60) {
            return ['ok' => true, 'checked' => true];
        }
        @set_time_limit(90);

        $sync = new CloudbedsSync($db, $cb);
        $res = $sync->liveConflicts($ci, $co);
        if (!$res['ok']) {
            error_log('Cloudbeds guard: tidak bisa mengecek Cloudbeds — ' . $res['detail']);
            return ['ok' => true, 'checked' => false];
        }
        $imported = 0;
        if ($res['items']) {
            // Coba masukkan dulu (reservasi yang kamarnya sudah ditempatkan akan langsung menjadi booking di sistem)
            $lock = @fopen(sys_get_temp_dir() . '/adf-cloudbeds-sync.lock', 'c');
            $got = $lock && flock($lock, LOCK_EX | LOCK_NB);
            if ($got) {
                try {
                    $imp = $sync->importNewOnly(date('Y-m-d', strtotime($ci . ' -45 days')), date('Y-m-d', strtotime($co . ' -1 day')), 0);
                    $imported = (int)($imp['done']['create'] ?? 0) + (int)($imp['done']['link'] ?? 0);
                } finally {
                    flock($lock, LOCK_UN);
                }
            }
            $res = $sync->liveConflicts($ci, $co);
            if (!$res['ok']) {
                error_log('Cloudbeds guard: cek ulang gagal — ' . $res['detail']);
                return ['ok' => true, 'checked' => false, 'imported' => $imported];
            }
        }
        if (!$res['items']) {
            $_SESSION['cb_guard_ok'][$cacheKey] = time();
            return ['ok' => true, 'checked' => true, 'imported' => $imported];
        }

        $parts = [];
        foreach (array_slice($res['items'], 0, 3) as $it) {
            $parts[] = trim($it['guest'] ?: 'Guest') . ($it['source'] !== '' ? ' (' . $it['source'] . ')' : '') . ' ' . $it['checkin'] . ' → ' . $it['checkout'];
        }
        $more = count($res['items']) > 3 ? ' dan ' . (count($res['items']) - 3) . ' lainnya' : '';
        return [
            'ok' => false,
            'checked' => true,
            'message' => 'Reservasi dari Cloudbeds belum masuk ke sistem pada tanggal ini: ' . implode('; ', $parts) . $more . '. '
                . 'Reservasi OTA baru masuk bila nomor kamarnya sudah ditempatkan di Cloudbeds. '
                . 'Tempatkan kamar di Cloudbeds lalu jalankan Sinkron, kemudian coba lagi (untuk mencegah double booking). '
                . 'Cloudbeds reservation(s) not yet imported for these dates: assign a room in Cloudbeds, run Sync, then retry.',
        ];
    } catch (\Throwable $e) {
        // Jangan pernah menghalangi kerja resepsionis karena galat pengaman itu sendiri
        error_log('Cloudbeds guard error: ' . $e->getMessage());
        return ['ok' => true, 'checked' => false];
    }
}
