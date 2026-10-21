<?php

/**
 * PENAWARAN (quotation) kamar — MEMBLOK kamar selama 24 jam (hold).
 * Penawaran disimpan terpisah dari booking (tabel quotations + quotation_items). Saat dibuat (dari admin maupun website),
 * setiap kamarnya mendapat baris room_blocks (block_reason 'quotation_hold', kolom quote_id) sehingga:
 *  - kamar tidak bisa dibooking orang lain di ADF, website, maupun OTA (blok ikut terkirim ke Cloudbeds),
 *  - di kalender tetap tampil sebagai bar penawaran transparan (bukan bar blok biasa),
 *  - setelah HOLD_HOURS tanpa konfirmasi, blok otomatis dilepas (releaseExpired) dan penawaran berstatus 'expired',
 *  - saat dikonfirmasi, form reservasi membawa quote_id → blok milik penawaran ini tidak dianggap bentrok, lalu dilepas.
 * Status: open | confirmed | cancelled | expired. Penawaran lama tanpa hold_until tetap memakai valid_until (tanpa blok).
 */
class Quotations
{
    public const HOLD_HOURS = 24;

    /** ID room_blocks yang baru dibuat / dilepas dalam request ini — dikirim ke Cloudbeds oleh pemanggil (API). */
    public static array $pushBlocks = [];

    /** Kolom & tabel untuk hold: quotations.hold_until, room_blocks.quote_id. */
    private static function ensureHoldSupport($db): void
    {
        $conn = $db->getConnection();
        try {
            if (!$db->fetchOne("SHOW COLUMNS FROM quotations LIKE 'hold_until'")) {
                $conn->exec("ALTER TABLE quotations ADD COLUMN hold_until DATETIME NULL");
            }
            // hold_until yang peringatannya sudah dijawab "Biarkan" (perpanjang → hold_until baru → peringatan muncul lagi nanti)
            if (!$db->fetchOne("SHOW COLUMNS FROM quotations LIKE 'hold_warned_until'")) {
                $conn->exec("ALTER TABLE quotations ADD COLUMN hold_warned_until DATETIME NULL");
            }
        } catch (\Throwable $e) {
        }
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS room_blocks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                block_code VARCHAR(40) NULL,
                room_id INT NOT NULL,
                block_start_date DATE NOT NULL,
                block_end_date DATE NOT NULL,
                block_reason VARCHAR(50) NOT NULL DEFAULT 'maintenance',
                notes TEXT NULL,
                status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_room_dates (room_id, block_start_date, block_end_date),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            if (!$db->fetchOne("SHOW COLUMNS FROM room_blocks LIKE 'quote_id'")) {
                $conn->exec("ALTER TABLE room_blocks ADD COLUMN quote_id INT NULL, ADD INDEX idx_quote (quote_id)");
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Kamar yang bentrok dengan booking aktif / blok aktif (blok milik penawaran $ownQuoteId diabaikan).
     * @return string[] nomor kamar
     */
    private static function busyRooms($db, array $roomIds, string $ci, string $co, int $ownQuoteId = 0): array
    {
        $out = [];
        foreach ($roomIds as $rid) {
            $bk = $db->fetchOne("SELECT id FROM bookings WHERE room_id = ? AND status IN ('pending','confirmed','checked_in') AND check_in_date < ? AND check_out_date > ? LIMIT 1", [(int)$rid, $co, $ci]);
            $blk = $db->fetchOne("SELECT id FROM room_blocks WHERE room_id = ? AND status = 'active' AND block_start_date < ? AND block_end_date > ? AND (quote_id IS NULL OR quote_id <> ?) LIMIT 1", [(int)$rid, $co, $ci, $ownQuoteId]);
            if ($bk || $blk) {
                $r = $db->fetchOne("SELECT room_number FROM rooms WHERE id = ?", [(int)$rid]);
                $out[] = (string)($r['room_number'] ?? $rid);
            }
        }
        return $out;
    }

    /** Pasang blok hold untuk semua kamar penawaran (dipanggil di dalam transaksi). */
    private static function placeHold($db, int $qid, string $code, string $ci, string $co, array $roomIds, string $holdUntil, int $userId = 0): void
    {
        foreach ($roomIds as $rid) {
            $rn = $db->fetchOne("SELECT room_number FROM rooms WHERE id = ?", [(int)$rid]);
            $db->query("INSERT INTO room_blocks (block_code, room_id, block_start_date, block_end_date, block_reason, notes, status, created_by, quote_id)
                VALUES (?, ?, ?, ?, 'quotation_hold', ?, 'active', ?, ?)",
                [substr('QH-' . $code . '-' . ($rn['room_number'] ?? $rid), 0, 40), (int)$rid, $ci, $co, 'Penawaran ' . $code . ' · hold s/d ' . date('d M H:i', strtotime($holdUntil)), $userId ?: null, $qid]);
            self::$pushBlocks[] = (int)$db->getConnection()->lastInsertId();
        }
    }

    /** Lepas blok hold penawaran (status cancelled) — kamar langsung bisa dibooking lagi. */
    public static function releaseHold($db, int $qid, string $why = 'dilepas', ?int $roomId = null): int
    {
        self::ensureTables($db);
        $sql = "SELECT id FROM room_blocks WHERE quote_id = ? AND status = 'active'" . ($roomId ? ' AND room_id = ' . (int)$roomId : '');
        $ids = array_map('intval', array_column($db->fetchAll($sql, [$qid]) ?: [], 'id'));
        if ($ids) {
            $db->query("UPDATE room_blocks SET status = 'cancelled', notes = TRIM(CONCAT(COALESCE(notes,''), ' [Hold " . addslashes($why) . "]')), updated_at = NOW() WHERE id IN (" . implode(',', $ids) . ")");
            self::$pushBlocks = array_merge(self::$pushBlocks, $ids);
        }
        return count($ids);
    }

    /** Lepas semua hold yang sudah lewat 24 jam tanpa konfirmasi; penawarannya menjadi 'expired'. Murah dipanggil berulang. */
    public static function releaseExpired($db): int
    {
        static $ran = []; // sekali per database per request (cron melooping beberapa bisnis)
        try {
            $dbName = (string)($db->fetchOne("SELECT DATABASE() d")['d'] ?? '');
            if (isset($ran[$dbName])) return 0;
            $ran[$dbName] = true;
            self::ensureTables($db);
            $rows = $db->fetchAll("SELECT id FROM quotations WHERE status = 'open' AND hold_until IS NOT NULL AND hold_until < ?", [date('Y-m-d H:i:s')]) ?: []; // jam PHP (Asia/Jakarta), bukan NOW() MySQL yang zona waktunya bisa beda
            foreach ($rows as $r) {
                self::releaseHold($db, (int)$r['id'], 'habis 24 jam');
                $db->query("UPDATE quotations SET status = 'expired' WHERE id = ? AND status = 'open'", [(int)$r['id']]);
            }
            return count($rows);
        } catch (\Throwable $e) {
            error_log('Quotations::releaseExpired: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Perpanjang hold 24 jam dari sekarang. Untuk penawaran yang sudah 'expired', kamar diblok ulang bila masih kosong.
     * @return array{ok:bool,msg?:string,hold_until?:string}
     */
    public static function extendHold($db, int $id, int $userId = 0): array
    {
        self::ensureTables($db);
        $q = self::get($db, $id);
        if (!$q || !in_array($q['status'], ['open', 'expired'], true)) return ['ok' => false, 'msg' => 'Penawaran tidak bisa diperpanjang'];
        if ($q['check_out'] <= date('Y-m-d')) return ['ok' => false, 'msg' => 'Tanggal menginap sudah lewat'];
        $until = date('Y-m-d H:i:s', time() + self::HOLD_HOURS * 3600);
        $roomIds = array_map(fn($i) => (int)$i['room_id'], $q['items']);
        $conn = $db->getConnection();
        $conn->beginTransaction();
        try {
            $busy = self::busyRooms($db, $roomIds, $q['check_in'], $q['check_out'], $id);
            if ($busy) throw new \RuntimeException('Kamar sudah terisi / diblok: ' . implode(', ', $busy));
            self::releaseHold($db, $id, 'diperpanjang');
            self::placeHold($db, $id, $q['quote_code'], $q['check_in'], $q['check_out'], $roomIds, $until, $userId);
            $db->query("UPDATE quotations SET status = 'open', hold_until = ?, valid_until = ? WHERE id = ?", [$until, substr($until, 0, 10), $id]);
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            self::$pushBlocks = [];
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
        return ['ok' => true, 'hold_until' => $until];
    }
    public static function ensureTables($db): void
    {
        static $done = [];
        $dbKey = spl_object_id($db->getConnection());
        if (isset($done[$dbKey])) return;
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
        $db->getConnection()->exec("CREATE TABLE IF NOT EXISTS quotation_extras (
            id INT AUTO_INCREMENT PRIMARY KEY,
            quote_id INT NOT NULL,
            type_label VARCHAR(80) NULL,
            item_name VARCHAR(160) NOT NULL,
            unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
            qty DECIMAL(10,2) NOT NULL DEFAULT 1,
            unit VARCHAR(30) NULL,
            line_total DECIMAL(15,2) NOT NULL DEFAULT 0,
            KEY idx_quote (quote_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // total layanan tambahan (Hotel Service) di penawaran; total = kamar (setelah diskon) + tambahan
        try {
            if (!$db->fetchOne("SHOW COLUMNS FROM quotations LIKE 'extras_total'")) {
                $db->getConnection()->exec("ALTER TABLE quotations ADD COLUMN extras_total DECIMAL(15,2) NOT NULL DEFAULT 0");
            }
        } catch (\Throwable $e) {
        }
        // Reservasi dari website: sumber, jumlah tamu, DP yang sudah diterima, status follow-up & notifikasi
        $webCols = [
            'source' => "VARCHAR(20) NOT NULL DEFAULT 'admin'",
            'rate_plan' => 'VARCHAR(80) NULL', // nama daftar Harga Agent yang dipakai (kosong = harga normal)
            'adults' => 'INT NULL',
            'children' => 'INT NULL',
            'dp_amount' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
            'dp_method' => 'VARCHAR(30) NULL',
            'dp_paid_at' => 'DATETIME NULL',
            'followup_at' => 'DATETIME NULL',
            'seen_at' => 'DATETIME NULL',
            'notified_at' => 'DATETIME NULL',
            'client_ip' => 'VARCHAR(45) NULL',
        ];
        try {
            $have = array_column($db->fetchAll('SHOW COLUMNS FROM quotations') ?: [], 'Field');
            foreach ($webCols as $col => $def) {
                if (!in_array($col, $have, true)) {
                    $db->getConnection()->exec("ALTER TABLE quotations ADD COLUMN `$col` $def");
                }
            }
        } catch (\Throwable $e) {
        }
        self::ensureHoldSupport($db);
        $done[$dbKey] = true;
    }

    /** Katalog Hotel Service aktif (untuk memilih fasilitas tambahan di penawaran), dikelompokkan per tipe. */
    public static function catalog($db, int $businessId): array
    {
        try {
            $labels = [];
            foreach ($db->fetchAll("SELECT type_key, type_label, type_icon FROM hotel_service_types WHERE business_id = ? AND is_active = 1", [$businessId]) ?: [] as $t) {
                $labels[$t['type_key']] = [$t['type_label'], $t['type_icon']];
            }
            $out = [];
            foreach ($db->fetchAll("SELECT id, service_type, item_name, default_price, unit FROM hotel_service_catalog WHERE business_id = ? AND is_active = 1 ORDER BY service_type, sort_order, item_name", [$businessId]) ?: [] as $r) {
                $lb = $labels[$r['service_type']] ?? [ucfirst(str_replace('_', ' ', (string)$r['service_type'])), ''];
                $out[] = ['id' => (int)$r['id'], 'type' => $lb[0], 'icon' => (string)$lb[1], 'name' => $r['item_name'], 'price' => (float)$r['default_price'], 'unit' => (string)($r['unit'] ?? '')];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Harga bersih per malam setelah diskon (persen atau Rp per malam). */
    /** Nama daftar Harga Agent yang dipilih di penawaran (null = harga normal). */
    private static function ratePlan(array $d): ?string
    {
        $p = trim((string)($d['rate_plan'] ?? ''));
        return $p === '' ? null : mb_substr($p, 0, 80);
    }

    public static function netPrice(float $base, string $type, float $val): float
    {
        if ($val > 0 && $type === 'percent') return max(0, round($base * (1 - min(100, $val) / 100), 2));
        if ($val > 0 && $type === 'rp') return max(0, round($base - $val, 2));
        return $base;
    }

    /** Validasi + hitung ulang penawaran (harga kamar dari server). Dipakai create() dan update(). */
    private static function prepare($db, array $d): array
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

        // Layanan tambahan: dari katalog Hotel Service (catalog_id → harga diambil server) atau baris manual (name + price)
        $extraRows = [];
        $extrasTotal = 0.0;
        foreach ((array)($d['extras'] ?? []) as $ex) {
            $qty = max(0.01, min(9999, (float)($ex['qty'] ?? 1)));
            $exName = trim((string)($ex['name'] ?? ''));
            $exPrice = (float)($ex['price'] ?? 0);
            $exUnit = trim((string)($ex['unit'] ?? ''));
            $typeLabel = trim((string)($ex['type'] ?? ''));
            if (!empty($ex['catalog_id'])) {
                $c = $db->fetchOne("SELECT c.item_name, c.default_price, c.unit, t.type_label FROM hotel_service_catalog c LEFT JOIN hotel_service_types t ON t.type_key = c.service_type AND t.business_id = c.business_id WHERE c.id = ?", [(int)$ex['catalog_id']]);
                if ($c) { $exName = $c['item_name']; $exPrice = (float)$c['default_price']; $exUnit = (string)$c['unit']; $typeLabel = (string)($c['type_label'] ?? $typeLabel); }
            }
            if ($exName === '' || $exPrice < 0) continue;
            $line = round($exPrice * $qty, 2);
            $extraRows[] = [$typeLabel, mb_substr($exName, 0, 160), $exPrice, $qty, mb_substr($exUnit, 0, 30), $line];
            $extrasTotal += $line;
        }

        $rows = [];
        $subtotal = $total = 0.0;
        foreach ($items as $it) {
            $r = $db->fetchOne("SELECT r.id, r.room_number, rt.type_name, rt.base_price FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id WHERE r.id = ?", [(int)$it['room_id']]);
            if (!$r) continue;
            $type = in_array($it['disc_type'] ?? '', ['percent', 'rp'], true) ? $it['disc_type'] : '';
            $val = max(0, (float)($it['disc_value'] ?? 0));
            // Harga /malam boleh diketik manual di penawaran; tanpa itu ikut harga tipe kamar
            $base = isset($it['base']) && is_numeric($it['base']) && (float)$it['base'] >= 0 ? round((float)$it['base'], 2) : (float)$r['base_price'];
            $net = self::netPrice($base, $type, $val);
            $rows[] = [$r, $type, $type === '' ? 0 : $val, $base, $net, round($net * $nights, 2)];
            $subtotal += $base * $nights;
            $total += $net * $nights;
        }
        if (!$rows) return ['ok' => false, 'msg' => 'Kamar tidak ditemukan'];

        return ['ok' => true, 'ci' => $ci, 'co' => $co, 'name' => $name, 'nights' => $nights, 'valid' => $valid, 'rows' => $rows, 'extraRows' => $extraRows, 'extrasTotal' => $extrasTotal, 'subtotal' => $subtotal, 'total' => $total];
    }

    /**
     * Buat penawaran. $items = [['room_id'=>int,'disc_type'=>'percent|rp|','disc_value'=>float,'base'=>?float], …] — harga dasar dari
     * tipe kamar (server), kecuali 'base' diisi (harga /malam yang diketik manual di penawaran).
     * @return array{ok:bool,msg?:string,id?:int,code?:string}
     */
    public static function create($db, array $d, int $userId = 0): array
    {
        $p = self::prepare($db, $d);
        if (!$p['ok']) return $p;
        extract($p);

        $roomIds = array_map(fn($row) => (int)$row[0]['id'], $rows);
        $busy = self::busyRooms($db, $roomIds, $ci, $co);
        if ($busy) return ['ok' => false, 'msg' => 'Kamar sudah terisi / diblok pada tanggal ini: ' . implode(', ', $busy)];
        $holdUntil = date('Y-m-d H:i:s', time() + self::HOLD_HOURS * 3600);
        $valid = substr($holdUntil, 0, 10);

        $conn = $db->getConnection();
        $conn->beginTransaction();
        try {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code = 'QT-' . date('Ymd') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                if (!$db->fetchOne("SELECT id FROM quotations WHERE quote_code = ?", [$code])) break;
            }
            $db->query("INSERT INTO quotations (quote_code, guest_name, guest_phone, guest_email, notes, check_in, check_out, nights, valid_until, subtotal, discount_total, total, extras_total, lang, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$code, mb_substr($name, 0, 150), mb_substr(trim((string)($d['guest_phone'] ?? '')), 0, 40), mb_substr(trim((string)($d['guest_email'] ?? '')), 0, 120),
                    trim((string)($d['notes'] ?? '')), $ci, $co, $nights, $valid, round($subtotal, 2), round($subtotal - $total, 2), round($total + $extrasTotal, 2), round($extrasTotal, 2),
                    ($d['lang'] ?? 'id') === 'en' ? 'en' : 'id', $userId ?: null]);
            $qid = (int)$conn->lastInsertId();
            foreach ($rows as [$r, $type, $val, $base, $net, $line]) {
                $db->query("INSERT INTO quotation_items (quote_id, room_id, room_number, room_type, base_price, disc_type, disc_value, net_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$qid, (int)$r['id'], $r['room_number'], $r['type_name'], $base, $type, $val, $net, $line]);
            }
            foreach ($extraRows as [$tl, $nm, $pr, $qt, $un, $ln]) {
                $db->query("INSERT INTO quotation_extras (quote_id, type_label, item_name, unit_price, qty, unit, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)", [$qid, $tl, $nm, $pr, $qt, $un, $ln]);
            }
            $db->query("UPDATE quotations SET hold_until = ?, rate_plan = ? WHERE id = ?", [$holdUntil, self::ratePlan($d), $qid]);
            self::placeHold($db, $qid, $code, $ci, $co, $roomIds, $holdUntil, $userId);
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            self::$pushBlocks = [];
            return ['ok' => false, 'msg' => 'Gagal menyimpan: ' . $e->getMessage()];
        }
        return ['ok' => true, 'id' => $qid, 'code' => $code, 'hold_until' => $holdUntil];
    }

    /** Ubah penawaran yang masih terbuka: data tamu, tanggal, masa berlaku, catatan, kamar+diskon dan layanan tambahan (harga dihitung ulang server). */
    public static function update($db, int $id, array $d): array
    {
        self::ensureTables($db);
        $q = $db->fetchOne("SELECT id, status, quote_code, hold_until FROM quotations WHERE id = ?", [$id]);
        if (!$q) return ['ok' => false, 'msg' => 'Penawaran tidak ditemukan'];
        if ($q['status'] !== 'open') return ['ok' => false, 'msg' => 'Hanya penawaran terbuka yang bisa diubah'];
        $p = self::prepare($db, $d);
        if (!$p['ok']) return $p;
        extract($p);

        $conn = $db->getConnection();
        $conn->beginTransaction();
        try {
            $db->query("UPDATE quotations SET guest_name = ?, guest_phone = ?, guest_email = ?, notes = ?, check_in = ?, check_out = ?, nights = ?, valid_until = ?,
                    subtotal = ?, discount_total = ?, total = ?, extras_total = ?, rate_plan = ? WHERE id = ? AND status = 'open'",
                [mb_substr($name, 0, 150), mb_substr(trim((string)($d['guest_phone'] ?? '')), 0, 40), mb_substr(trim((string)($d['guest_email'] ?? '')), 0, 120),
                    trim((string)($d['notes'] ?? '')), $ci, $co, $nights, $valid, round($subtotal, 2), round($subtotal - $total, 2), round($total + $extrasTotal, 2), round($extrasTotal, 2), self::ratePlan($d), $id]);
            // Hold ikut perubahan kamar/tanggal; batas waktunya tidak bertambah (penawaran lama tanpa hold mendapat 24 jam)
            $roomIds = array_map(fn($row) => (int)$row[0]['id'], $rows);
            $busy = self::busyRooms($db, $roomIds, $ci, $co, $id);
            if ($busy) throw new \RuntimeException('Kamar sudah terisi / diblok pada tanggal ini: ' . implode(', ', $busy));
            $holdUntil = $q['hold_until'] ?: date('Y-m-d H:i:s', time() + self::HOLD_HOURS * 3600);
            self::releaseHold($db, $id, 'diubah');
            self::placeHold($db, $id, (string)$q['quote_code'], $ci, $co, $roomIds, $holdUntil);
            $db->query("UPDATE quotations SET hold_until = ?, valid_until = ? WHERE id = ?", [$holdUntil, substr($holdUntil, 0, 10), $id]);
            $db->query("DELETE FROM quotation_items WHERE quote_id = ?", [$id]);
            $db->query("DELETE FROM quotation_extras WHERE quote_id = ?", [$id]);
            foreach ($rows as [$r, $type, $val, $base, $net, $line]) {
                $db->query("INSERT INTO quotation_items (quote_id, room_id, room_number, room_type, base_price, disc_type, disc_value, net_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$id, (int)$r['id'], $r['room_number'], $r['type_name'], $base, $type, $val, $net, $line]);
            }
            foreach ($extraRows as [$tl, $nm, $pr, $qt, $un, $ln]) {
                $db->query("INSERT INTO quotation_extras (quote_id, type_label, item_name, unit_price, qty, unit, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)", [$id, $tl, $nm, $pr, $qt, $un, $ln]);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            self::$pushBlocks = [];
            return ['ok' => false, 'msg' => 'Gagal menyimpan: ' . $e->getMessage()];
        }
        return ['ok' => true, 'id' => $id];
    }

    /** Daftar kamar + harga dasar tipe (untuk menambah kamar saat mengubah penawaran). */
    public static function roomList($db): array
    {
        return $db->fetchAll("SELECT r.id, r.room_number, rt.type_name, rt.base_price FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id ORDER BY r.room_number") ?: [];
    }

    public static function get($db, int $id): ?array
    {
        self::ensureTables($db);
        $q = $db->fetchOne("SELECT * FROM quotations WHERE id = ?", [$id]);
        if (!$q) return null;
        $q['items'] = $db->fetchAll("SELECT * FROM quotation_items WHERE quote_id = ? ORDER BY room_number", [$id]) ?: [];
        $q['extras'] = $db->fetchAll("SELECT * FROM quotation_extras WHERE quote_id = ? ORDER BY id", [$id]) ?: [];
        $q['expired'] = self::isExpired($q);
        return $q;
    }

    /** Daftar penawaran (terbaru dulu), dengan jumlah kamar dan penanda kedaluwarsa. */
    public static function listAll($db, string $status = 'open', int $limit = 100): array
    {
        self::ensureTables($db);
        // 'open' juga menampilkan hold yang baru habis (≤ 7 hari) agar bisa diperpanjang
        $where = $status === 'all' ? '' : ($status === 'open'
            ? " WHERE (q.status = 'open' OR (q.status = 'expired' AND q.hold_until > NOW() - INTERVAL 7 DAY))"
            : ' WHERE q.status = ' . $db->getConnection()->quote($status));
        $rows = $db->fetchAll("SELECT q.*, (SELECT COUNT(*) FROM quotation_items i WHERE i.quote_id = q.id) AS room_count,
                (SELECT GROUP_CONCAT(i.room_number ORDER BY i.room_number SEPARATOR ', ') FROM quotation_items i WHERE i.quote_id = q.id) AS rooms
            FROM quotations q" . $where . " ORDER BY q.id DESC LIMIT " . (int)$limit) ?: [];
        foreach ($rows as &$r) $r['expired'] = self::isExpired($r);
        return $rows;
    }

    /** Penawaran terbuka (belum kedaluwarsa) per kamar yang beririsan dengan rentang — untuk bar hijau transparan di kalender. */
    public static function forCalendar($db, string $from, string $to): array
    {
        try {
            self::ensureTables($db);
            return $db->fetchAll("SELECT q.id, q.quote_code, q.guest_name, q.check_in, q.check_out, q.valid_until, q.hold_until, i.room_id
                FROM quotations q JOIN quotation_items i ON i.quote_id = q.id
                WHERE q.status = 'open' AND (q.hold_until IS NOT NULL OR q.valid_until IS NULL OR q.valid_until >= CURDATE())
                  AND q.check_in < ? AND q.check_out > ?
                ORDER BY q.check_in, i.room_id", [$to, $from]) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function cancel($db, int $id): bool
    {
        self::ensureTables($db);
        $r = $db->query("UPDATE quotations SET status = 'cancelled' WHERE id = ? AND status IN ('open','expired')", [$id]);
        $ok = $r !== false && $r->rowCount() === 1;
        if ($ok) self::releaseHold($db, $id, 'dibatalkan');
        return $ok;
    }

    /** Tandai terkonfirmasi setelah booking dibuat dari penawaran ini. */
    public static function markConfirmed($db, int $id, string $bookingRef): bool
    {
        self::ensureTables($db);
        $r = $db->query("UPDATE quotations SET status = 'confirmed', confirmed_at = NOW(), booking_ref = ? WHERE id = ? AND status = 'open'", [mb_substr($bookingRef, 0, 120), $id]);
        $ok = $r !== false && $r->rowCount() === 1;
        if ($ok) self::releaseHold($db, $id, 'jadi booking'); // sisa blok (bila ada) dilepas; kamar kini dipegang booking
        return $ok;
    }

    public const WARN_MINUTES = 60;

    /** Hold yang tinggal ≤ WARN_MINUTES menit dan belum dijawab admin — untuk popup peringatan. */
    public static function expiringSoon($db): array
    {
        self::ensureTables($db);
        $now = date('Y-m-d H:i:s');
        return $db->fetchAll("SELECT q.id, q.quote_code, q.guest_name, q.check_in, q.check_out, q.nights, q.hold_until, q.source,
                (SELECT GROUP_CONCAT(i.room_number ORDER BY i.room_number SEPARATOR ', ') FROM quotation_items i WHERE i.quote_id = q.id) AS rooms
            FROM quotations q
            WHERE q.status = 'open' AND q.hold_until IS NOT NULL AND q.hold_until > ? AND q.hold_until <= ?
              AND (q.hold_warned_until IS NULL OR q.hold_warned_until <> q.hold_until)
            ORDER BY q.hold_until LIMIT 10", [$now, date('Y-m-d H:i:s', time() + self::WARN_MINUTES * 60)]) ?: [];
    }

    /** Admin memilih "Biarkan": peringatan untuk hold ini tidak muncul lagi; blok tetap lepas otomatis saat waktunya habis. */
    public static function dismissHoldWarning($db, int $id): bool
    {
        self::ensureTables($db);
        $r = $db->query("UPDATE quotations SET hold_warned_until = hold_until WHERE id = ? AND status = 'open'", [$id]);
        return $r !== false && $r->rowCount() === 1;
    }

    /** Kedaluwarsa: status 'expired', hold sudah lewat, atau (penawaran lama tanpa hold) valid_until terlewati. */
    public static function isExpired(array $q): bool
    {
        if (($q['status'] ?? '') === 'expired') return true;
        if (($q['status'] ?? '') !== 'open') return false;
        if (!empty($q['hold_until'])) return strtotime((string)$q['hold_until']) < time();
        return !empty($q['valid_until']) && $q['valid_until'] < date('Y-m-d');
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
                $blk = $db->fetchOne("SELECT id FROM room_blocks WHERE room_id = ? AND status = 'active' AND block_start_date < ? AND block_end_date > ? AND (quote_id IS NULL OR quote_id <> ?) LIMIT 1", [(int)$it['room_id'], $q['check_out'], $q['check_in'], (int)($q['id'] ?? 0)]);
            } catch (\Throwable $e) {
            }
            if ($bk || $blk) $out[] = (string)$it['room_number'];
        }
        return $out;
    }
}
