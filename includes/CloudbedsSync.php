<?php

/**
 * Sinkron Cloudbeds → sistem (tahap 2). Selalu dua langkah:
 *   plan()  — hitung apa yang akan dilakukan (tanpa menulis apa pun)
 *   apply() — hitung ulang rencana di server lalu jalankan
 *
 * Aksi:
 *   link    reservasi Cloudbeds yang sudah ada di sistem (tanggal sama + nama cocok) ditautkan, booking tidak diubah
 *   create  reservasi aktif yang belum ada → booking baru (status confirmed, belum bayar, sumber sesuai pemetaan)
 *   cancel  reservasi yang dibatalkan / no-show di Cloudbeds → booking tertaut yang belum check-in & belum dibayar dibatalkan
 *   warn    hal yang perlu dicek manual (belum dapat kamar, kamar terisi, Hotel Collect, tanggal berubah, dll.)
 *
 * Tautan disimpan di cloudbeds_booking_links (satu reservasi Cloudbeds bisa ke beberapa booking/kamar).
 */
require_once __DIR__ . '/CloudbedsClient.php';

class CloudbedsSync
{
    private $db;
    private $cb;
    private const MAX_DETAIL = 30;

    public function __construct($db, CloudbedsClient $cb)
    {
        $this->db = $db;
        $this->cb = $cb;
    }

    public function ensureTables(): void
    {
        $this->db->getConnection()->exec("CREATE TABLE IF NOT EXISTS cloudbeds_booking_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cb_reservation_id VARCHAR(40) NOT NULL,
            booking_id INT NOT NULL,
            how VARCHAR(10) NOT NULL DEFAULT 'link',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_cb_booking (cb_reservation_id, booking_id),
            KEY idx_booking (booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->getConnection()->exec("CREATE TABLE IF NOT EXISTS cloudbeds_sync_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            range_from DATE NULL,
            range_to DATE NULL,
            linked INT NOT NULL DEFAULT 0,
            created INT NOT NULL DEFAULT 0,
            cancelled INT NOT NULL DEFAULT 0,
            warnings INT NOT NULL DEFAULT 0,
            detail TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private static function nameKey(string $s): array
    {
        $w = preg_split('/\s+/', strtolower(preg_replace('/[^a-zA-Z ]/', ' ', $s)));
        return array_values(array_filter($w, fn($x) => strlen($x) >= 3 && !in_array($x, ['mr', 'mrs', 'ms', 'pax'], true)));
    }

    private static function isCancelled(string $status): bool
    {
        return in_array(strtolower($status), ['canceled', 'cancelled', 'no_show'], true);
    }

    /** Kamar bebas? (tidak ada booking aktif yang tumpang tindih & tidak diblok) */
    private function roomFree(int $roomId, string $ci, string $co): bool
    {
        $b = $this->db->fetchOne(
            "SELECT id FROM bookings WHERE room_id = ? AND status NOT IN ('cancelled','checked_out')
             AND check_in_date < ? AND check_out_date > ? LIMIT 1",
            [$roomId, $co, $ci]
        );
        if ($b) return false;
        try {
            $blk = $this->db->fetchOne(
                "SELECT id FROM room_blocks WHERE room_id = ? AND status = 'active' AND block_start_date < ? AND block_end_date > ? LIMIT 1",
                [$roomId, $co, $ci]
            );
            if ($blk) return false;
        } catch (\Throwable $e) {
        }
        return true;
    }

    /**
     * Rencana sinkron untuk reservasi dengan check-in di [$from, $to]. Tidak menulis apa pun.
     * @return array{ok:bool, detail:string, actions:array, counts:array}
     */
    public function plan(string $from, string $to): array
    {
        $this->ensureTables();
        $list = $this->cb->previewReservations($from, $to);
        if (!$list['ok']) {
            return ['ok' => false, 'detail' => $list['detail'], 'actions' => [], 'counts' => []];
        }

        // Sumber Cloudbeds (nama & jenis collect) + pemetaan
        $srcRes = $this->cb->get('getSources', $this->cb->propertyId() !== '' ? ['propertyID' => $this->cb->propertyId()] : []);
        $srcById = [];
        $srcByName = [];
        if ($srcRes['ok']) {
            $walk = function ($d) use (&$walk, &$srcById, &$srcByName) {
                if (!is_array($d)) return;
                if (isset($d['sourceID']) && !is_array($d['sourceID'])) {
                    $srcById[(string)$d['sourceID']] = (string)($d['sourceName'] ?? '');
                    $srcByName[strtolower((string)($d['sourceName'] ?? ''))] = (string)$d['sourceID'];
                    return;
                }
                foreach ($d as $v) $walk($v);
            };
            $walk($srcRes['data']);
        }
        $sourceMap = $this->cb->sourceMap();
        $typeMap = $this->cb->roomTypeMap();

        $localRooms = $this->db->fetchAll("SELECT r.id, r.room_number, COALESCE(rt.type_name,'') type_name FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id") ?: [];
        $roomByNo = [];
        foreach ($localRooms as $r) {
            $roomByNo[(string)$r['room_number']] = $r;
            // juga kunci angka saja ("Q101" → "101"), tanpa menimpa nomor yang persis sama
            $digits = preg_replace('/\D+/', '', (string)$r['room_number']);
            if ($digits !== '' && !isset($roomByNo[$digits])) $roomByNo[$digits] = $r;
        }

        $links = [];
        foreach ($this->db->fetchAll("SELECT cb_reservation_id, booking_id FROM cloudbeds_booking_links") ?: [] as $l) {
            $links[(string)$l['cb_reservation_id']][] = (int)$l['booking_id'];
        }
        $linkedBookingIds = [];
        foreach ($links as $ids) foreach ($ids as $id) $linkedBookingIds[$id] = true;

        $localBk = $this->db->fetchAll(
            "SELECT b.id, b.booking_code, b.group_id, b.status, b.paid_amount, DATE(b.check_in_date) ci, DATE(b.check_out_date) co, g.guest_name, r.room_number
             FROM bookings b LEFT JOIN guests g ON g.id = b.guest_id LEFT JOIN rooms r ON r.id = b.room_id
             WHERE DATE(b.check_in_date) BETWEEN ? AND ?",
            [$from, $to]
        ) ?: [];

        $actions = [];
        $nDetail = 0;
        foreach ($list['items'] as $it) {
            $cbId = $it['id'];
            $label = $it['guest'] . ' · ' . $it['checkin'] . ' → ' . $it['checkout'] . ' · ' . $it['source'];

            // Sudah tertaut
            if (isset($links[$cbId])) {
                $bks = $this->db->fetchAll(
                    "SELECT id, booking_code, status, paid_amount, DATE(check_in_date) ci, DATE(check_out_date) co FROM bookings WHERE id IN (" .
                        implode(',', array_map('intval', $links[$cbId])) . ")"
                ) ?: [];
                foreach ($bks as $bk) {
                    if (self::isCancelled($it['status'])) {
                        if (in_array($bk['status'], ['confirmed', 'pending'], true)) {
                            if ((float)$bk['paid_amount'] > 0) {
                                $actions[] = ['type' => 'warn', 'cb' => $cbId, 'label' => $label, 'msg' => 'Dibatalkan di Cloudbeds, tetapi ' . $bk['booking_code'] . ' sudah ada pembayaran — batalkan manual (refund).'];
                            } else {
                                $actions[] = ['type' => 'cancel', 'cb' => $cbId, 'label' => $label, 'booking_id' => (int)$bk['id'], 'msg' => 'Batalkan ' . $bk['booking_code']];
                            }
                        }
                    } elseif (in_array($bk['status'], ['confirmed', 'pending'], true) && ($bk['ci'] !== $it['checkin'] || $bk['co'] !== $it['checkout'])) {
                        $actions[] = ['type' => 'warn', 'cb' => $cbId, 'label' => $label, 'msg' => 'Tanggal berubah di Cloudbeds; ' . $bk['booking_code'] . ' masih ' . $bk['ci'] . ' → ' . $bk['co'] . '. Sesuaikan manual.'];
                    }
                }
                continue;
            }
            if (self::isCancelled($it['status'])) {
                continue;
            }

            // Sudah ada di sistem (dicocokkan) → tautkan
            $k = self::nameKey($it['guest']);
            $match = null;
            foreach ($localBk as $lb) {
                if (isset($linkedBookingIds[(int)$lb['id']]) || $lb['status'] === 'cancelled') continue;
                if ($lb['ci'] === $it['checkin'] && $lb['co'] === $it['checkout'] && $k && array_intersect($k, self::nameKey((string)$lb['guest_name']))) {
                    $match = $lb;
                    break;
                }
            }
            if ($match) {
                $ids = [(int)$match['id']];
                if (!empty($match['group_id'])) {
                    foreach ($localBk as $lb) {
                        if ($lb['group_id'] === $match['group_id'] && $lb['ci'] === $match['ci'] && $lb['co'] === $match['co']) $ids[] = (int)$lb['id'];
                    }
                }
                $ids = array_values(array_unique($ids));
                foreach ($ids as $id) $linkedBookingIds[$id] = true;
                $actions[] = ['type' => 'link', 'cb' => $cbId, 'label' => $label, 'booking_ids' => $ids, 'msg' => 'Tautkan ke ' . $match['booking_code'] . (count($ids) > 1 ? ' (+' . (count($ids) - 1) . ' kamar grup)' : '')];
                continue;
            }

            // Belum ada → booking baru
            if (!in_array(strtolower($it['status']), ['confirmed', 'not_confirmed', 'checked_in'], true)) {
                $actions[] = ['type' => 'warn', 'cb' => $cbId, 'label' => $label, 'msg' => 'Status Cloudbeds "' . $it['status'] . '" — tidak dibuat otomatis.'];
                continue;
            }
            if ($nDetail >= self::MAX_DETAIL) {
                $actions[] = ['type' => 'warn', 'cb' => $cbId, 'label' => $label, 'msg' => 'Batas detail per sinkron tercapai — jalankan sinkron lagi.'];
                continue;
            }
            $nDetail++;
            $det = $this->cb->reservationDetail($cbId);
            if (!$det['ok']) {
                $actions[] = ['type' => 'warn', 'cb' => $cbId, 'label' => $label, 'msg' => 'Gagal ambil detail: ' . $det['detail']];
                continue;
            }

            // Sumber & jenis collect
            $raw = is_array($det['raw']) ? $det['raw'] : [];
            $srcId = (string)($raw['sourceID'] ?? ($srcByName[strtolower($it['source'])] ?? ''));
            $srcName = $srcById[$srcId] ?? ($raw['sourceName'] ?? $it['source']);
            $collect = CloudbedsClient::collectType((string)$srcName);
            $sourceKey = $sourceMap[$srcId] ?? '';
            if ($sourceKey === '') {
                // nama sumber di daftar ("Tiket.com") tanpa jenis collect: pakai pemetaan sumber yang namanya diawali sama
                foreach ($srcById as $sid => $sn) {
                    if (isset($sourceMap[$sid]) && stripos($sn, $it['source']) === 0) {
                        $sourceKey = $sourceMap[$sid];
                        break;
                    }
                }
            }
            // Hotel Collect: tamu bayar langsung ke hotel → booking dibuat dengan direct_amount = harga
            // (diperlakukan seperti booking langsung; komisi OTA ditagih OTA terpisah, dicatat di catatan booking).
            $hotelCollect = $collect === 'hotel';
            if ($sourceKey === '') {
                $actions[] = ['type' => 'warn', 'cb' => $cbId, 'label' => $label, 'msg' => 'Sumber "' . $srcName . '" belum dipasangkan di Pemetaan sumber booking.'];
                continue;
            }
            if (!$det['rooms']) {
                $actions[] = ['type' => 'warn', 'cb' => $cbId, 'label' => $label, 'msg' => 'Tidak ada kamar di reservasi Cloudbeds.'];
                continue;
            }

            $roomsPlan = [];
            $problem = '';
            $sumRoomTotals = 0.0;
            foreach ($det['rooms'] as $dr) {
                $sumRoomTotals += (float)($dr['total'] ?? 0);
            }
            foreach ($det['rooms'] as $dr) {
                if (!$dr['assigned']) {
                    $problem = 'Belum dapat nomor kamar di Cloudbeds (' . ($typeMap[$dr['type_id']] ?? $dr['type_name']) . ') — tempatkan kamar di Cloudbeds dulu.';
                    break;
                }
                $no = preg_match('/\d{2,4}/', $dr['room_name'], $m) ? $m[0] : '';
                $lr = $roomByNo[$no] ?? null;
                if (!$lr) {
                    $problem = 'Kamar "' . $dr['room_name'] . '" tidak ditemukan di sistem.';
                    break;
                }
                $ci = $dr['start'] ?: $it['checkin'];
                $co = $dr['end'] ?: $it['checkout'];
                if (!$this->roomFree((int)$lr['id'], $ci, $co)) {
                    $problem = 'Room ' . $no . ' sudah terisi/diblok pada ' . $ci . ' → ' . $co . ' di sistem — cek bentrok.';
                    break;
                }
                // Harga per kamar: roomTotal bila ada; selain itu total dibagi rata
                $price = $dr['total'] !== null && $sumRoomTotals > 0
                    ? (float)$dr['total'] * (($det['total'] ?? $sumRoomTotals) / $sumRoomTotals)
                    : (float)($det['total'] ?? 0) / count($det['rooms']);
                $roomsPlan[] = ['room_id' => (int)$lr['id'], 'room_no' => $no, 'ci' => $ci, 'co' => $co, 'price' => round($price),
                    'adults' => max(1, $dr['adults'] ?: (int)$det['adults']), 'children' => (int)$dr['children']];
            }
            if ($problem !== '') {
                $actions[] = ['type' => 'warn', 'cb' => $cbId, 'label' => $label, 'msg' => $problem];
                continue;
            }
            $actions[] = [
                'type' => 'create', 'cb' => $cbId, 'label' => $label,
                'guest' => $it['guest'], 'phone' => (string)($raw['guestPhone'] ?? $raw['guestCellPhone'] ?? ''), 'email' => (string)($raw['guestEmail'] ?? ''),
                'source_key' => $sourceKey, 'source_name' => (string)$srcName, 'third_party_id' => $it['third_party_id'],
                'rooms' => $roomsPlan,
                'hotel_collect' => $hotelCollect,
                'msg' => 'Buat booking: Room ' . implode(', ', array_column($roomsPlan, 'room_no')) . ' · Rp ' . number_format(array_sum(array_column($roomsPlan, 'price')), 0, ',', '.') . ' · ' . $sourceKey
                    . ($hotelCollect ? ' · Hotel Collect (tamu bayar di hotel)' : ''),
            ];
        }

        $counts = ['link' => 0, 'create' => 0, 'cancel' => 0, 'warn' => 0];
        foreach ($actions as $a) $counts[$a['type']]++;
        return ['ok' => true, 'detail' => 'OK', 'actions' => $actions, 'counts' => $counts];
    }

    /** Hitung ulang rencana lalu jalankan link / create / cancel. Peringatan tidak dieksekusi. */
    public function apply(string $from, string $to, int $userId): array
    {
        $plan = $this->plan($from, $to);
        if (!$plan['ok']) {
            return $plan + ['done' => []];
        }
        $done = ['link' => 0, 'create' => 0, 'cancel' => 0, 'errors' => []];
        // Kolom direct_amount (Hotel Collect) dibuat sebelum transaksi: ALTER di dalam transaksi = implicit commit
        try {
            require_once __DIR__ . '/BookingSourceHelper.php';
            if (function_exists('bs_ensure_direct_amount')) {
                bs_ensure_direct_amount($this->db->getConnection());
            }
        } catch (\Throwable $e) {
        }
        foreach ($plan['actions'] as $a) {
            try {
                if ($a['type'] === 'link') {
                    foreach ($a['booking_ids'] as $bid) {
                        $this->db->query("INSERT IGNORE INTO cloudbeds_booking_links (cb_reservation_id, booking_id, how) VALUES (?, ?, 'link')", [$a['cb'], $bid]);
                    }
                    $done['link']++;
                } elseif ($a['type'] === 'cancel') {
                    $this->db->query("UPDATE bookings SET status = 'cancelled', notes = TRIM(CONCAT(COALESCE(notes,''), ' [Dibatalkan via Cloudbeds ', NOW(), ']')), updated_at = NOW()
                        WHERE id = ? AND status IN ('confirmed','pending') AND COALESCE(paid_amount,0) = 0", [$a['booking_id']]);
                    $done['cancel']++;
                } elseif ($a['type'] === 'create') {
                    $this->createBooking($a, $userId);
                    $done['create']++;
                }
            } catch (\Throwable $e) {
                $done['errors'][] = $a['label'] . ': ' . $e->getMessage();
            }
        }
        try {
            $this->db->query(
                "INSERT INTO cloudbeds_sync_log (range_from, range_to, linked, created, cancelled, warnings, detail, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$from, $to, $done['link'], $done['create'], $done['cancel'], $plan['counts']['warn'] ?? 0, $done['errors'] ? implode("\n", $done['errors']) : null, $userId ?: null]
            );
        } catch (\Throwable $e) {
        }
        return $plan + ['done' => $done];
    }

    /** Booking baru dari reservasi Cloudbeds (satu baris per kamar; multi-kamar = satu group_id). */
    private function createBooking(array $a, int $userId): void
    {
        $conn = $this->db->getConnection();
        $conn->beginTransaction();
        try {
            // Cek ulang ketersediaan di dalam transaksi
            foreach ($a['rooms'] as $r) {
                if (!$this->roomFree($r['room_id'], $r['ci'], $r['co'])) {
                    throw new \RuntimeException('Room ' . $r['room_no'] . ' baru saja terisi');
                }
            }
            $conn->prepare("INSERT INTO guests (guest_name, phone, email, id_card_number, created_at) VALUES (?, ?, ?, ?, NOW())")
                ->execute([mb_substr($a['guest'] ?: 'Guest', 0, 200), mb_substr($a['phone'], 0, 20) ?: null, mb_substr($a['email'], 0, 100) ?: null, 'CB-' . $a['cb']]);
            $guestId = (int)$conn->lastInsertId();
            $groupId = count($a['rooms']) > 1 ? 'CB-' . $a['cb'] : null;
            $note = 'Cloudbeds #' . $a['cb'] . ($a['third_party_id'] ? ' · ' . $a['source_name'] . ' ' . $a['third_party_id'] : ' · ' . $a['source_name']);
            $hotelCollect = !empty($a['hotel_collect']);
            if ($hotelCollect) {
                $fee = 0.0;
                try {
                    $st = $conn->prepare("SELECT fee_percent FROM booking_sources WHERE source_key = ? LIMIT 1");
                    $st->execute([$a['source_key']]);
                    $fee = (float)($st->fetchColumn() ?: 0);
                } catch (\Throwable $e) {
                }
                $total = array_sum(array_column($a['rooms'], 'price'));
                $note .= "\nHOTEL COLLECT: tamu bayar langsung ke hotel (seperti booking langsung)."
                    . ($fee > 0 ? ' Komisi OTA ' . rtrim(rtrim(number_format($fee, 2, ',', ''), '0'), ',') . '% ≈ Rp ' . number_format($total * $fee / 100, 0, ',', '.') . ' ditagih OTA terpisah.' : '');
                // direct_amount = seluruh tagihan dibayar langsung (kolom dibuat bila belum ada; ALTER di luar transaksi)
            }
            foreach ($a['rooms'] as $r) {
                $nights = max(1, (int)round((strtotime($r['co']) - strtotime($r['ci'])) / 86400));
                $code = 'BK-' . date('Ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
                $st = $conn->prepare("SELECT id FROM bookings WHERE booking_code = ?");
                $st->execute([$code]);
                if ($st->fetch()) $code = 'BK-' . date('YmdHis') . '-' . random_int(100, 999);
                $conn->prepare("INSERT INTO bookings (booking_code, group_id, guest_id, room_id, check_in_date, check_out_date, total_nights,
                        adults, children, room_price, total_price, discount, final_price, booking_source, ota_source_detail,
                        status, payment_status, paid_amount, notes, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 'confirmed', 'unpaid', 0, ?, ?, NOW())")
                    ->execute([$code, $groupId, $guestId, $r['room_id'], $r['ci'], $r['co'], $nights, $r['adults'], $r['children'],
                        round($r['price'] / $nights), $r['price'], $r['price'], $a['source_key'], mb_substr($a['source_name'] . ($a['third_party_id'] ? ' #' . $a['third_party_id'] : ''), 0, 50),
                        $note, $userId ?: null]);
                $bid = (int)$conn->lastInsertId();
                if ($hotelCollect) {
                    $conn->prepare("UPDATE bookings SET direct_amount = ? WHERE id = ?")->execute([$r['price'], $bid]);
                }
                $conn->prepare("INSERT IGNORE INTO cloudbeds_booking_links (cb_reservation_id, booking_id, how) VALUES (?, ?, 'create')")->execute([$a['cb'], $bid]);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    public function recentLog(int $limit = 10): array
    {
        try {
            $this->ensureTables();
            return $this->db->fetchAll("SELECT * FROM cloudbeds_sync_log ORDER BY id DESC LIMIT " . (int)$limit) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
