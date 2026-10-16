<?php

/**
 * PENAWARAN (quotation) kamar — belum memblok kamar.
 * Penawaran disimpan terpisah dari booking (tabel quotations + quotation_items), jadi:
 *  - kamar yang ditawarkan tetap bisa dibooking orang lain (tidak ikut pengecekan ketersediaan),
 *  - di kalender hanya tampil sebagai bar hijau transparan,
 *  - saat dikonfirmasi, penawaran dijadikan booking lewat form reservasi yang sama (ketersediaan dicek di sana).
 * Status: open | confirmed | cancelled. "Kedaluwarsa" = open yang valid_until-nya sudah lewat (dihitung, tidak disimpan).
 */
class Quotations
{
    public static function ensureTables($db): void
    {
        static $done = false;
        if ($done) return;
        $db->getConnection()->exec("CREATE TABLE IF NOT EXISTS quotations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            quote_code VARCHAR(30) NOT NULL,
            guest_name VARCHAR(150) NOT NULL,
            guest_phone VARCHAR(40) NULL,
            guest_email VARCHAR(120) NULL,
            notes TEXT NULL,
            check_in DATE NOT NULL,
            check_out DATE NOT NULL,
            nights INT NOT NULL DEFAULT 1,
            valid_until DATE NULL,
            status VARCHAR(15) NOT NULL DEFAULT 'open',
            subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
            discount_total DECIMAL(15,2) NOT NULL DEFAULT 0,
            total DECIMAL(15,2) NOT NULL DEFAULT 0,
            lang VARCHAR(2) NOT NULL DEFAULT 'id',
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME NULL,
            booking_ref VARCHAR(120) NULL,
            UNIQUE KEY uq_quote_code (quote_code),
            KEY idx_status_dates (status, check_in, check_out)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->getConnection()->exec("CREATE TABLE IF NOT EXISTS quotation_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            quote_id INT NOT NULL,
            room_id INT NOT NULL,
            room_number VARCHAR(30) NULL,
            room_type VARCHAR(80) NULL,
            base_price DECIMAL(15,2) NOT NULL DEFAULT 0,
            disc_type VARCHAR(10) NOT NULL DEFAULT '',
            disc_value DECIMAL(15,2) NOT NULL DEFAULT 0,
            net_price DECIMAL(15,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(15,2) NOT NULL DEFAULT 0,
            KEY idx_quote (quote_id),
            KEY idx_room (room_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }

    /** Harga bersih per malam setelah diskon (persen atau Rp per malam). */
    public static function netPrice(float $base, string $type, float $val): float
    {
        if ($val > 0 && $type === 'percent') return max(0, round($base * (1 - min(100, $val) / 100), 2));
        if ($val > 0 && $type === 'rp') return max(0, round($base - $val, 2));
        return $base;
    }

    /**
     * Buat penawaran. $items = [['room_id'=>int,'disc_type'=>'percent|rp|','disc_value'=>float], …] — harga dasar diambil dari tipe kamar (server).
     * @return array{ok:bool,msg?:string,id?:int,code?:string}
     */
    public static function create($db, array $d, int $userId = 0): array
    {
        self::ensureTables($db);
        $ci = (string)($d['check_in'] ?? '');
        $co = (string)($d['check_out'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ci) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $co) || $co <= $ci) return ['ok' => false, 'msg' => 'Tanggal tidak valid'];
        $name = trim((string)($d['guest_name'] ?? ''));
        if ($name === '') return ['ok' => false, 'msg' => 'Nama tamu wajib diisi'];
        $items = array_values(array_filter((array)($d['items'] ?? []), fn($i) => (int)($i['room_id'] ?? 0) > 0));
        if (!$items) return ['ok' => false, 'msg' => 'Pilih minimal satu kamar'];
        $nights = max(1, (int)round((strtotime($co) - strtotime($ci)) / 86400));
        $valid = (string)($d['valid_until'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid)) $valid = date('Y-m-d', strtotime('+7 days'));

        $rows = [];
        $subtotal = $total = 0.0;
        foreach ($items as $it) {
            $r = $db->fetchOne("SELECT r.id, r.room_number, rt.type_name, rt.base_price FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id WHERE r.id = ?", [(int)$it['room_id']]);
            if (!$r) continue;
            $type = in_array($it['disc_type'] ?? '', ['percent', 'rp'], true) ? $it['disc_type'] : '';
            $val = max(0, (float)($it['disc_value'] ?? 0));
            $base = (float)$r['base_price'];
            $net = self::netPrice($base, $type, $val);
            $rows[] = [$r, $type, $type === '' ? 0 : $val, $base, $net, round($net * $nights, 2)];
            $subtotal += $base * $nights;
            $total += $net * $nights;
        }
        if (!$rows) return ['ok' => false, 'msg' => 'Kamar tidak ditemukan'];

        $conn = $db->getConnection();
        $conn->beginTransaction();
        try {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code = 'QT-' . date('Ymd') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                if (!$db->fetchOne("SELECT id FROM quotations WHERE quote_code = ?", [$code])) break;
            }
            $db->query("INSERT INTO quotations (quote_code, guest_name, guest_phone, guest_email, notes, check_in, check_out, nights, valid_until, subtotal, discount_total, total, lang, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$code, mb_substr($name, 0, 150), mb_substr(trim((string)($d['guest_phone'] ?? '')), 0, 40), mb_substr(trim((string)($d['guest_email'] ?? '')), 0, 120),
                    trim((string)($d['notes'] ?? '')), $ci, $co, $nights, $valid, round($subtotal, 2), round($subtotal - $total, 2), round($total, 2),
                    ($d['lang'] ?? 'id') === 'en' ? 'en' : 'id', $userId ?: null]);
            $qid = (int)$conn->lastInsertId();
            foreach ($rows as [$r, $type, $val, $base, $net, $line]) {
                $db->query("INSERT INTO quotation_items (quote_id, room_id, room_number, room_type, base_price, disc_type, disc_value, net_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$qid, (int)$r['id'], $r['room_number'], $r['type_name'], $base, $type, $val, $net, $line]);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            return ['ok' => false, 'msg' => 'Gagal menyimpan: ' . $e->getMessage()];
        }
        return ['ok' => true, 'id' => $qid, 'code' => $code];
    }

    public static function get($db, int $id): ?array
    {
        self::ensureTables($db);
        $q = $db->fetchOne("SELECT * FROM quotations WHERE id = ?", [$id]);
        if (!$q) return null;
        $q['items'] = $db->fetchAll("SELECT * FROM quotation_items WHERE quote_id = ? ORDER BY room_number", [$id]) ?: [];
        $q['expired'] = $q['status'] === 'open' && !empty($q['valid_until']) && $q['valid_until'] < date('Y-m-d');
        return $q;
    }

    /** Daftar penawaran (terbaru dulu), dengan jumlah kamar dan penanda kedaluwarsa. */
    public static function listAll($db, string $status = 'open', int $limit = 100): array
    {
        self::ensureTables($db);
        $where = $status === 'all' ? '' : ' WHERE q.status = ' . $db->getConnection()->quote($status);
        $rows = $db->fetchAll("SELECT q.*, (SELECT COUNT(*) FROM quotation_items i WHERE i.quote_id = q.id) AS room_count,
                (SELECT GROUP_CONCAT(i.room_number ORDER BY i.room_number SEPARATOR ', ') FROM quotation_items i WHERE i.quote_id = q.id) AS rooms
            FROM quotations q" . $where . " ORDER BY q.id DESC LIMIT " . (int)$limit) ?: [];
        foreach ($rows as &$r) $r['expired'] = $r['status'] === 'open' && !empty($r['valid_until']) && $r['valid_until'] < date('Y-m-d');
        return $rows;
    }

    /** Penawaran terbuka (belum kedaluwarsa) per kamar yang beririsan dengan rentang — untuk bar hijau transparan di kalender. */
    public static function forCalendar($db, string $from, string $to): array
    {
        try {
            self::ensureTables($db);
            return $db->fetchAll("SELECT q.id, q.quote_code, q.guest_name, q.check_in, q.check_out, q.valid_until, i.room_id
                FROM quotations q JOIN quotation_items i ON i.quote_id = q.id
                WHERE q.status = 'open' AND (q.valid_until IS NULL OR q.valid_until >= CURDATE())
                  AND q.check_in < ? AND q.check_out > ?
                ORDER BY q.check_in, i.room_id", [$to, $from]) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function cancel($db, int $id): bool
    {
        self::ensureTables($db);
        $r = $db->query("UPDATE quotations SET status = 'cancelled' WHERE id = ? AND status = 'open'", [$id]);
        return $r !== false && $r->rowCount() === 1;
    }

    /** Tandai terkonfirmasi setelah booking dibuat dari penawaran ini. */
    public static function markConfirmed($db, int $id, string $bookingRef): bool
    {
        self::ensureTables($db);
        $r = $db->query("UPDATE quotations SET status = 'confirmed', confirmed_at = NOW(), booking_ref = ? WHERE id = ? AND status = 'open'", [mb_substr($bookingRef, 0, 120), $id]);
        return $r !== false && $r->rowCount() === 1;
    }

    /** Kamar penawaran yang bentrok dengan booking / blok aktif — [room_number, …]; kosong = semua masih kosong. */
    public static function conflicts($db, array $q): array
    {
        $out = [];
        foreach ($q['items'] ?? [] as $it) {
            $bk = $db->fetchOne("SELECT b.id FROM bookings b WHERE b.room_id = ? AND b.status IN ('pending','confirmed','checked_in')
                AND b.check_in_date < ? AND b.check_out_date > ? LIMIT 1", [(int)$it['room_id'], $q['check_out'], $q['check_in']]);
            $blk = null;
            try {
                $blk = $db->fetchOne("SELECT id FROM room_blocks WHERE room_id = ? AND status = 'active' AND block_start_date < ? AND block_end_date > ? LIMIT 1", [(int)$it['room_id'], $q['check_out'], $q['check_in']]);
            } catch (\Throwable $e) {
            }
            if ($bk || $blk) $out[] = (string)$it['room_number'];
        }
        return $out;
    }
}
