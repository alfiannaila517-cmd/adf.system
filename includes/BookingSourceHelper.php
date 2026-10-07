<?php

/**
 * Sumber booking: satu sumber kebenaran di kolom bookings.booking_source (source_key dari booking_sources,
 * mis. walk_in, agoda, tiket). Kolom ota_source_detail hanya cermin untuk kode lama.
 *
 * Dulu update-reservation menyimpan OTA sebagai 'ota' + nama aslinya di ota_source_detail, sehingga
 * kalender (membaca detail) dan Edit Booking / Reservasi (membaca booking_source) menampilkan sumber berbeda.
 */

if (!function_exists('bs_ensure_schema')) {
    /** Pastikan booking_source VARCHAR, ota_source_detail ada, dan data lama 'ota' + detail dinormalisasi. Sekali per sesi. */
    function bs_ensure_schema(PDO $pdo): void
    {
        $key = 'bs_schema_ok_' . (defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : 'x');
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION[$key])) {
            return;
        }
        try {
            $col = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'booking_source'")->fetch(PDO::FETCH_ASSOC);
            if ($col && stripos((string)$col['Type'], 'enum(') === 0) {
                // ENUM lama (walk_in/phone/online/ota) menolak source_key seperti 'tiket' / 'agoda'.
                $pdo->exec("ALTER TABLE bookings MODIFY booking_source VARCHAR(50) DEFAULT 'walk_in'");
            }
            $hasDetail = (bool)$pdo->query("SHOW COLUMNS FROM bookings LIKE 'ota_source_detail'")->fetch();
            if (!$hasDetail) {
                $pdo->exec("ALTER TABLE bookings ADD COLUMN ota_source_detail VARCHAR(50) NULL DEFAULT NULL AFTER booking_source");
            }
            // Data lama: 'ota' + detail -> source_key asli.
            $pdo->exec("UPDATE bookings SET booking_source = LOWER(TRIM(ota_source_detail))
                        WHERE booking_source IN ('ota', '') AND ota_source_detail IS NOT NULL AND TRIM(ota_source_detail) <> ''");
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION[$key] = 1;
            }
        } catch (\Throwable $e) {
            error_log('bs_ensure_schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('bs_is_ota')) {
    /** True bila source_key bertipe OTA (booking_sources.source_type != 'direct'), dengan cadangan nama umum. */
    function bs_is_ota(PDO $pdo, string $source): bool
    {
        $source = strtolower(trim($source));
        if ($source === '') return false;
        try {
            $st = $pdo->prepare("SELECT source_type FROM booking_sources WHERE source_key = ? LIMIT 1");
            $st->execute([$source]);
            $type = $st->fetchColumn();
            if ($type !== false) return $type !== 'direct';
        } catch (\Throwable $e) {
        }
        $n = preg_replace('/[^a-z0-9]/', '', str_replace(['.com', '.co.id'], '', $source));
        foreach (['agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'expedia', 'pegipegi', 'ctrip', 'trip', 'ota'] as $o) {
            if ($n !== '' && strpos($n, $o) !== false) return true;
        }
        return false;
    }
}

if (!function_exists('bs_set_source')) {
    /**
     * Simpan sumber booking untuk booking ini dan SEMUA kamar dalam grupnya (sumber berlaku per reservasi).
     * @return int jumlah baris yang diperbarui
     */
    function bs_set_source(PDO $pdo, int $bookingId, string $source): int
    {
        $source = strtolower(trim($source));
        if ($bookingId <= 0 || $source === '') return 0;
        $detail = bs_is_ota($pdo, $source) ? $source : null;
        $st = $pdo->prepare("SELECT group_id FROM bookings WHERE id = ?");
        $st->execute([$bookingId]);
        $groupId = (string)($st->fetchColumn() ?: '');
        if ($groupId !== '') {
            $up = $pdo->prepare("UPDATE bookings SET booking_source = ?, ota_source_detail = ? WHERE group_id = ? AND status <> 'cancelled'");
            $up->execute([$source, $detail, $groupId]);
        } else {
            $up = $pdo->prepare("UPDATE bookings SET booking_source = ?, ota_source_detail = ? WHERE id = ?");
            $up->execute([$source, $detail, $bookingId]);
        }
        return $up->rowCount();
    }
}

if (!function_exists('bs_ensure_direct_amount')) {
    /**
     * bookings.direct_amount = bagian tagihan booking OTA yang dibayar tamu LANGSUNG ke hotel
     * (selisih upgrade, malam extend). Bagian ini tidak dipotong fee OTA dan boleh dibayar tunai/transfer.
     */
    function bs_ensure_direct_amount(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        try {
            if (!$pdo->query("SHOW COLUMNS FROM bookings LIKE 'direct_amount'")->fetch()) {
                $pdo->exec("ALTER TABLE bookings ADD COLUMN direct_amount DECIMAL(12,2) NOT NULL DEFAULT 0");
            }
            $done = true;
        } catch (\Throwable $e) {
            error_log('bs_ensure_direct_amount: ' . $e->getMessage());
        }
    }
}

if (!function_exists('bs_ota_fee_percent')) {
    /** Persen fee OTA untuk source_key (0 bila bukan OTA) — sumber sama dengan potongan buku kas. */
    function bs_ota_fee_percent(PDO $pdo, string $source): float
    {
        if (!bs_is_ota($pdo, $source)) return 0.0;
        try {
            $st = $pdo->prepare("SELECT fee_percent FROM booking_sources WHERE source_key = ? AND is_active = 1 LIMIT 1");
            $st->execute([strtolower(trim($source))]);
            $fee = $st->fetchColumn();
            if ($fee !== false) return (float)$fee;
        } catch (\Throwable $e) {
        }
        try {
            require_once __DIR__ . '/CashbookHelper.php';
            $cb = new CashbookHelper(Database::getInstance());
            return (float)($cb->calculateOtaFee(100, $source)['fee_percent'] ?? 0);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }
}
