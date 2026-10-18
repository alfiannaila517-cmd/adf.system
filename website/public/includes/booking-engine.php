<?php

/**
 * Mesin pemesanan website: cek ketersediaan kamar langsung dari database ADF (bookings + room_blocks)
 * dan menyimpan permintaan reservasi sebagai PENAWARAN ADF (tabel quotations, source='website').
 * Kamar TIDAK dikunci sampai admin mengonfirmasi di ADF setelah DP diterima.
 */

// Jam hold (hold_until) harus sama dengan ADF (Asia/Jakarta), apa pun zona waktu bawaan server
date_default_timezone_set('Asia/Jakarta');

if (!function_exists('nrbNormalizeKey')) {
    function nrbNormalizeKey(string $value): string
    {
        $n = trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($value))), '_');
        return str_ends_with($n, '_room') ? substr($n, 0, -5) : $n;
    }

    function nrbValidDate(string $d): bool
    {
        $o = DateTime::createFromFormat('Y-m-d', $d);
        return $o && $o->format('Y-m-d') === $d;
    }

    /** Kamar yang bentrok pada rentang [ci, co): booking aktif atau blok kamar aktif. */
    function nrbBusyRoomIds(string $ci, string $co): array
    {
        $busy = [];
        foreach (dbFetchAll("SELECT DISTINCT room_id FROM bookings WHERE status IN ('pending','confirmed','checked_in') AND check_in_date < ? AND check_out_date > ?", [$co, $ci]) as $r) {
            $busy[(int)$r['room_id']] = true;
        }
        try {
            foreach (dbFetchAll("SELECT DISTINCT room_id FROM room_blocks WHERE status = 'active' AND block_start_date < ? AND block_end_date > ?", [$co, $ci]) as $r) {
                $busy[(int)$r['room_id']] = true;
            }
        } catch (Throwable $e) {
        }
        return $busy;
    }

    /**
     * Tipe kamar beserta jumlah kamar kosong, harga & foto untuk rentang tanggal.
     * @return array<int,array{id:int,name:string,price:float,max:int,available:int,room_ids:int[],amenities:string[],photos:string[],description:string}>
     */
    function nrbAvailability(string $ci, string $co): array
    {
        $busy = nrbBusyRoomIds($ci, $co);
        $photos = [];
        foreach (dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_room_gallery_%' OR setting_key LIKE 'web_room_primary_%'") as $s) {
            $isPrimary = strpos($s['setting_key'], 'web_room_primary_') === 0;
            $key = nrbNormalizeKey(str_replace(['web_room_gallery_', 'web_room_primary_'], '', $s['setting_key']));
            if ($isPrimary) {
                $photos[$key]['primary'] = (string)$s['setting_value'];
            } else {
                $photos[$key]['gallery'] = json_decode((string)$s['setting_value'], true) ?: [];
            }
        }
        $url = static fn($p) => preg_match('#^https?://#i', (string)$p) ? (string)$p : BASE_URL . '/' . ltrim((string)$p, '/');

        $out = [];
        $types = dbFetchAll("SELECT * FROM room_types ORDER BY base_price DESC");
        foreach ($types as $t) {
            $rooms = dbFetchAll("SELECT id, room_number FROM rooms WHERE room_type_id = ? ORDER BY room_number", [(int)$t['id']]);
            if (!$rooms) continue; // tipe tanpa kamar fisik tidak ditampilkan
            $free = array_values(array_filter(array_map(fn($r) => (int)$r['id'], $rooms), fn($id) => !isset($busy[$id])));
            $key = nrbNormalizeKey((string)$t['type_name']);
            $gal = $photos[$key]['gallery'] ?? [];
            $pri = $photos[$key]['primary'] ?? '';
            if ($pri !== '') {
                $gal = array_values(array_unique(array_merge([$pri], $gal)));
            }
            $out[] = [
                'id' => (int)$t['id'],
                'name' => (string)$t['type_name'],
                'price' => (float)$t['base_price'],
                'max' => max(1, (int)($t['max_occupancy'] ?? 2)),
                'available' => count($free),
                'room_ids' => $free,
                'amenities' => array_values(array_filter(array_map('trim', explode(',', (string)($t['amenities'] ?? ''))))),
                'photos' => array_map($url, array_slice($gal, 0, 6)),
                'description' => (string)($t['description'] ?? ''),
            ];
        }
        return $out;
    }

    function nrbAmenity(string $a): string
    {
        $t = ucwords(str_replace('watter', 'water', strtolower(trim($a))));
        return (string)preg_replace_callback('/\b(Ac|Tv|Wifi)\b/', fn($m) => ['Ac' => 'AC', 'Tv' => 'TV', 'Wifi' => 'WiFi'][$m[1]], $t);
    }

    /** Kolom tambahan quotations untuk reservasi website (sama dengan Quotations::ensureTables di ADF). */
    function nrbEnsureQuoteColumns(PDO $pdo): void
    {
        $have = array_column($pdo->query('SHOW COLUMNS FROM quotations')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $cols = ['source' => "VARCHAR(20) NOT NULL DEFAULT 'admin'", 'adults' => 'INT NULL', 'children' => 'INT NULL', 'dp_amount' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'dp_method' => 'VARCHAR(30) NULL', 'dp_paid_at' => 'DATETIME NULL', 'followup_at' => 'DATETIME NULL', 'seen_at' => 'DATETIME NULL', 'notified_at' => 'DATETIME NULL', 'client_ip' => 'VARCHAR(45) NULL', 'extras_total' => 'DECIMAL(15,2) NOT NULL DEFAULT 0'];
        $cols['hold_until'] = 'DATETIME NULL';
        foreach ($cols as $c => $def) {
            if (!in_array($c, $have, true)) {
                $pdo->exec("ALTER TABLE quotations ADD COLUMN `$c` $def");
            }
        }
        try {
            $rb = array_column($pdo->query('SHOW COLUMNS FROM room_blocks')->fetchAll(PDO::FETCH_ASSOC), 'Field');
            if (!in_array('quote_id', $rb, true)) {
                $pdo->exec("ALTER TABLE room_blocks ADD COLUMN quote_id INT NULL, ADD INDEX idx_quote (quote_id)");
            }
        } catch (Throwable $e) {
        }
    }

    /** Sama dengan Quotations::releaseExpired di ADF: hold > 24 jam tanpa konfirmasi → blok dilepas, penawaran 'expired'. */
    function nrbReleaseExpired(PDO $pdo): void
    {
        try {
            $q = $pdo->prepare("SELECT id FROM quotations WHERE status = 'open' AND hold_until IS NOT NULL AND hold_until < ?");
            $q->execute([date('Y-m-d H:i:s')]);
            $ids = $q->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $qid) {
                $pdo->prepare("UPDATE room_blocks SET status = 'cancelled', notes = TRIM(CONCAT(COALESCE(notes,''), ' [Hold habis 24 jam]')), updated_at = NOW() WHERE quote_id = ? AND status = 'active'")->execute([(int)$qid]);
                $pdo->prepare("UPDATE quotations SET status = 'expired' WHERE id = ? AND status = 'open'")->execute([(int)$qid]);
            }
        } catch (Throwable $e) {
            error_log('nrbReleaseExpired: ' . $e->getMessage());
        }
    }

    /** Blok kamar 24 jam untuk reservasi website (dipanggil di dalam transaksi reserve.php). */
    function nrbPlaceHold(PDO $pdo, int $qid, string $code, string $ci, string $co, array $roomIds, string $holdUntil): void
    {
        $st = $pdo->prepare("INSERT INTO room_blocks (block_code, room_id, block_start_date, block_end_date, block_reason, notes, status, quote_id)
            VALUES (?, ?, ?, ?, 'quotation_hold', ?, 'active', ?)");
        foreach ($roomIds as $rid) {
            $rn = dbFetch("SELECT room_number FROM rooms WHERE id = ?", [(int)$rid]);
            $st->execute([substr('QH-' . $code . '-' . ($rn['room_number'] ?? $rid), 0, 40), (int)$rid, $ci, $co, 'Reservasi website ' . $code . ' · hold s/d ' . date('d M H:i', strtotime($holdUntil)), $qid]);
        }
        $pdo->prepare("UPDATE quotations SET hold_until = ? WHERE id = ?")->execute([$holdUntil, $qid]);
    }

    /** Beri tahu ADF (WhatsApp + email admin). Gagal tidak menggagalkan reservasi; ADF tetap menampilkan popup. */
    function nrbNotifyAdf(int $quoteId): void
    {
        try {
            $sec = dbFetch("SELECT setting_value FROM settings WHERE setting_key = 'web_reservation_secret'");
            $secret = trim((string)($sec['setting_value'] ?? ''));
            if ($secret === '') {
                $secret = bin2hex(random_bytes(24));
                dbQuery("INSERT INTO settings (setting_key, setting_value) VALUES ('web_reservation_secret', ?)", [$secret]);
            }
            $ch = curl_init(rtrim(ADF_SYSTEM_URL, '/') . '/api/web-reservation-notify.php');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['id' => $quoteId, 'sig' => hash_hmac('sha256', 'webres:' . $quoteId, $secret)]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (Throwable $e) {
            error_log('nrbNotifyAdf: ' . $e->getMessage());
        }
    }
}
