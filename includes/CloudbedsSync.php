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
        $linkHow = [];
        foreach ($this->db->fetchAll("SELECT cb_reservation_id, booking_id, how FROM cloudbeds_booking_links") ?: [] as $l) {
            $links[(string)$l['cb_reservation_id']][] = (int)$l['booking_id'];
            $linkHow[(string)$l['cb_reservation_id']][(int)$l['booking_id']] = (string)$l['how'];
        }
        $push = $this->pushEnabled();
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
                    } elseif ($push && ($st = $this->outboundStatus($bk['status'], $it['status'], $linkHow[$cbId][(int)$bk['id']] ?? 'link'))) {
                        $actions[] = ['type' => 'push_status', 'cb' => $cbId, 'label' => $label, 'steps' => $st, 'msg' => 'Kirim ke Cloudbeds: status ' . implode(' → ', $st) . ' (' . $bk['booking_code'] . ')'];
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

            // Belum ada → booking baru. Tamu yang sudah check-out (riwayat) tidak dibuat & tidak diperingatkan.
            if (strtolower($it['status']) === 'checked_out') {
                continue;
            }
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

        // ---- Booking direct baru di sistem → reservasi Cloudbeds ----
        if ($push) {
            $this->planPushCreate($from, $to, $linkedBookingIds, $actions);
        }

        // ---- Blok kamar Cloudbeds → room_blocks (kode CB-<blockID>-<roomID>) ----
        $this->planRoomBlocks($from, $to, $roomByNo, $actions);

        $counts = ['link' => 0, 'create' => 0, 'cancel' => 0, 'block' => 0, 'unblock' => 0, 'push_status' => 0, 'push_create' => 0, 'push_block' => 0, 'warn' => 0];
        foreach ($actions as $a) {
            $t = in_array($a['type'], ['push_delblock', 'push_putblock', 'push_newblock'], true) ? 'push_block' : $a['type'];
            $counts[$t]++;
        }
        return ['ok' => true, 'detail' => 'OK', 'actions' => $actions, 'counts' => $counts];
    }

    private static function nightsLabel(string $start, string $end): string
    {
        $last = date('Y-m-d', strtotime($end . ' -1 day'));
        return $last === $start ? 'malam ' . $start : 'malam ' . $start . ' s/d ' . $last;
    }

    /* ---------------- Sistem → Cloudbeds ---------------- */

    public function pushEnabled(): bool
    {
        $r = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_push_enabled'");
        return ($r['setting_value'] ?? '0') === '1';
    }

    /**
     * Status yang perlu dikirim ke Cloudbeds agar sama dengan sistem (urutan langkah), atau [] bila sudah sama.
     * Check-in/out sistem → Cloudbeds; batal hanya untuk reservasi yang dibuat dari sistem (OTA dibatalkan lewat OTA).
     */
    private function outboundStatus(string $local, string $cbStatus, string $how): array
    {
        $cb = strtolower($cbStatus);
        $active = in_array($cb, ['confirmed', 'not_confirmed'], true);
        if ($local === 'checked_in' && $active) return ['checked_in'];
        if ($local === 'checked_out' && $active) return ['checked_in', 'checked_out'];
        if ($local === 'checked_out' && $cb === 'checked_in') return ['checked_out'];
        if ($local === 'cancelled' && $how === 'push' && ($active)) return ['canceled'];
        return [];
    }

    /** Kamar Cloudbeds per nomor kamar sistem: roomID & roomTypeID (dari getRooms). */
    private function cbRoomsByNo(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        $rm = $this->cb->get('getRooms', $this->cb->propertyId() !== '' ? ['propertyID' => $this->cb->propertyId()] : []);
        $walk = function ($d) use (&$walk, &$cache) {
            if (!is_array($d)) return;
            if (isset($d['roomID']) && !is_array($d['roomID']) && isset($d['roomName'])) {
                if (preg_match('/\d{2,4}/', (string)$d['roomName'], $m)) {
                    $cache[$m[0]] = ['room_id' => (string)$d['roomID'], 'type_id' => (string)($d['roomTypeID'] ?? ''), 'name' => (string)$d['roomName']];
                }
                return;
            }
            foreach ($d as $v) $walk($v);
        };
        $walk($rm['data'] ?? []);
        return $cache;
    }

    /**
     * Booking DIRECT yang dibuat di sistem setelah fitur kirim diaktifkan & belum ada di Cloudbeds → reservasi baru.
     * Booking lama tidak dikirim (staf selama ini juga mengetiknya manual di Cloudbeds — mencegah dobel).
     */
    private function planPushCreate(string $from, string $to, array $linkedBookingIds, array &$actions): void
    {
        $sinceRow = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_push_since'");
        $since = (string)($sinceRow['setting_value'] ?? '');
        if ($since === '') return;
        $rows = $this->db->fetchAll(
            "SELECT b.id, b.booking_code, b.status, b.booking_source, DATE(b.check_in_date) ci, DATE(b.check_out_date) co,
                    b.adults, b.children, b.created_at, b.notes, r.room_number, g.guest_name, g.phone, g.email, g.nationality,
                    bs.source_type
             FROM bookings b
             JOIN rooms r ON r.id = b.room_id
             LEFT JOIN guests g ON g.id = b.guest_id
             LEFT JOIN booking_sources bs ON bs.source_key = b.booking_source
             WHERE b.status IN ('confirmed','pending','checked_in')
               AND DATE(b.check_out_date) >= CURDATE()
               AND DATE(b.check_in_date) BETWEEN ? AND ?
               AND b.created_at >= ?
               AND COALESCE(b.notes, '') NOT LIKE 'Cloudbeds #%'",
            [$from, $to, $since]
        ) ?: [];
        $cbRooms = null;
        foreach ($rows as $b) {
            if (isset($linkedBookingIds[(int)$b['id']])) continue;
            $isDirect = $b['source_type'] === 'direct' || ($b['source_type'] === null && in_array($b['booking_source'], ['walk_in', 'phone', 'online', 'direct', 'website', 'email'], true));
            if (!$isDirect) continue;
            $label = ($b['guest_name'] ?: 'Guest') . ' · ' . $b['ci'] . ' → ' . $b['co'] . ' · ' . $b['booking_source'] . ' (' . $b['booking_code'] . ')';
            if ($cbRooms === null) $cbRooms = $this->cbRoomsByNo();
            $no = preg_match('/\d{2,4}/', (string)$b['room_number'], $m) ? $m[0] : (string)$b['room_number'];
            $cr = $cbRooms[$no] ?? null;
            if (!$cr || $cr['type_id'] === '') {
                $actions[] = ['type' => 'warn', 'cb' => '-', 'label' => $label, 'msg' => 'Room ' . $b['room_number'] . ' tidak ditemukan di Cloudbeds — tidak dikirim.'];
                continue;
            }
            $actions[] = ['type' => 'push_create', 'cb' => '-', 'label' => $label, 'booking_id' => (int)$b['id'], 'booking' => $b, 'cb_room' => $cr,
                'msg' => 'Kirim ke Cloudbeds: reservasi baru Room ' . $b['room_number'] . ($b['status'] === 'checked_in' ? ' (lalu status checked-in)' : '')];
        }
    }

    /** Buat reservasi di Cloudbeds untuk booking direct, tempatkan di kamar yang sama, lalu tautkan. */
    private function pushCreate(array $a): void
    {
        $b = $a['booking'];
        $cr = $a['cb_room'];
        $name = trim((string)($b['guest_name'] ?: 'Guest'));
        $parts = preg_split('/\s+/', $name);
        $first = count($parts) > 1 ? implode(' ', array_slice($parts, 0, -1)) : $name;
        $last = count($parts) > 1 ? end($parts) : '-';
        $nat = strtolower(trim((string)($b['nationality'] ?? '')));
        $country = ($nat === '' || strpos($nat, 'indo') !== false) ? 'ID' : (strlen($nat) === 2 ? strtoupper($nat) : 'ID');
        $email = filter_var((string)$b['email'], FILTER_VALIDATE_EMAIL) ? (string)$b['email'] : 'noemail+' . strtolower($b['booking_code']) . '@adfsystem.online';
        $adults = max(1, (int)$b['adults']);
        $children = max(0, (int)$b['children']);
        $params = [
            'startDate' => $b['ci'],
            'endDate' => $b['co'],
            'guestFirstName' => $first,
            'guestLastName' => $last,
            'guestCountry' => $country,
            'guestZip' => '59455',
            'guestEmail' => $email,
            'guestPhone' => (string)($b['phone'] ?? ''),
            'paymentMethod' => 'cash',
            'sendEmailConfirmation' => 'false',
            'rooms' => [['roomTypeID' => $cr['type_id'], 'quantity' => 1]],
            'adults' => [['roomTypeID' => $cr['type_id'], 'quantity' => $adults]],
            'children' => [['roomTypeID' => $cr['type_id'], 'quantity' => $children]],
        ];
        $r = $this->cb->send('POST', 'postReservation', $params);
        if (!$r['ok']) {
            throw new \RuntimeException('Cloudbeds menolak reservasi: ' . $r['detail']);
        }
        $resId = (string)($r['raw']['reservationID'] ?? ($r['data']['reservationID'] ?? ''));
        if ($resId === '') {
            throw new \RuntimeException('Reservasi dibuat tetapi ID tidak terbaca dari jawaban Cloudbeds');
        }
        // Tautkan dulu (reservasi sudah ada di Cloudbeds), baru tempatkan kamar
        $this->db->query("INSERT IGNORE INTO cloudbeds_booking_links (cb_reservation_id, booking_id, how) VALUES (?, ?, 'push')", [$resId, $a['booking_id']]);
        $as = $this->cb->send('POST', 'postRoomAssign', ['reservationID' => $resId, 'newRoomID' => $cr['room_id'], 'roomTypeID' => $cr['type_id']]);
        $this->db->query("UPDATE bookings SET notes = TRIM(CONCAT(COALESCE(notes,''), ?)) WHERE id = ?", [
            "\n[Dikirim ke Cloudbeds #" . $resId . ($as['ok'] ? '' : ' — kamar belum ditempatkan: ' . mb_substr($as['detail'], 0, 120)) . ']', $a['booking_id'],
        ]);
        if ($b['status'] === 'checked_in') {
            $this->cb->send('PUT', 'putReservation', ['reservationID' => $resId, 'status' => 'checked_in']);
        }
    }

    /**
     * Blok kamar dari Cloudbeds (getRoomBlocks, scope Roomblock: Read). Tanggal selesai diperlakukan seperti
     * check-out (malam terakhir = sehari sebelumnya), sama dengan room_blocks di sistem.
     */
    private function planRoomBlocks(string $from, string $to, array $roomByNo, array &$actions): void
    {
        // Cloudbeds membatasi rentang getRoomBlocks maks. 35 hari → ambil per potongan 30 hari
        $chunks = [];
        for ($cs = $from; $cs <= $to; $cs = date('Y-m-d', strtotime($cs . ' +30 days'))) {
            $ce = min($to, date('Y-m-d', strtotime($cs . ' +29 days')));
            $q = ['startDate' => $cs, 'endDate' => $ce];
            if ($this->cb->propertyId() !== '') {
                $q['propertyID'] = $this->cb->propertyId();
            }
            $res = $this->cb->get('getRoomBlocks', $q);
            if (!$res['ok']) {
                $denied = in_array((int)($res['http'] ?? 0), [401, 403], true) || stripos($res['detail'], 'scope') !== false || stripos($res['detail'], 'permission') !== false;
                $actions[] = ['type' => 'warn', 'cb' => '-', 'label' => 'Blok kamar Cloudbeds', 'msg' => 'Tidak bisa dibaca: ' . $res['detail']
                    . ($denied ? ' (aktifkan scope "Roomblock: Read" di API key Cloudbeds).' : '')];
                return;
            }
            $chunks[] = $res['data'];
        }
        $res = ['data' => $chunks];
        // roomID Cloudbeds → nama kamar (dari getRooms)
        $cbRoomName = [];
        $rm = $this->cb->get('getRooms', $this->cb->propertyId() !== '' ? ['propertyID' => $this->cb->propertyId()] : []);
        $walkRooms = function ($d) use (&$walkRooms, &$cbRoomName) {
            if (!is_array($d)) return;
            if (isset($d['roomID']) && !is_array($d['roomID']) && isset($d['roomName'])) {
                $cbRoomName[(string)$d['roomID']] = (string)$d['roomName'];
                return;
            }
            foreach ($d as $v) $walkRooms($v);
        };
        $walkRooms($rm['data'] ?? []);

        $blocks = [];
        $walkBlocks = function ($d) use (&$walkBlocks, &$blocks) {
            if (!is_array($d)) return;
            if (isset($d['roomBlockID']) && !is_array($d['roomBlockID'])) {
                $blocks[] = $d;
                return;
            }
            foreach ($d as $v) $walkBlocks($v);
        };
        $walkBlocks($res['data']);

        $seen = [];
        $push = $this->pushEnabled();
        $seenBlockIds = [];
        foreach ($blocks as $b) {
            $bid = (string)$b['roomBlockID'];
            // Blok yang melintasi dua potongan tanggal muncul dua kali
            if (isset($seenBlockIds[$bid])) continue;
            $seenBlockIds[$bid] = true;
            $start = substr((string)($b['startDate'] ?? ''), 0, 10);
            $end = substr((string)($b['endDate'] ?? ''), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) continue;
            if ($end <= $start) $end = date('Y-m-d', strtotime($start . ' +1 day'));
            $reason = trim((string)($b['roomBlockReason'] ?? $b['roomBlockName'] ?? $b['roomBlockType'] ?? ''));
            $roomIds = [];
            foreach ((array)($b['rooms'] ?? []) as $r) {
                if (is_array($r) && isset($r['roomID'])) $roomIds[] = (string)$r['roomID'];
                elseif (!is_array($r)) $roomIds[] = (string)$r;
            }
            if (stripos($reason, 'ADF:') === 0) {
                foreach ($roomIds as $rid) $seen[substr('CB-' . $bid . '-' . $rid, 0, 40)] = true;
                continue;
            }
            $removedHere = [];
            foreach ($roomIds as $rid) {
                $name = $cbRoomName[$rid] ?? $rid;
                $no = preg_match('/\d{2,4}/', $name, $m) ? $m[0] : '';
                $lr = $roomByNo[$no] ?? null;
                $code = substr('CB-' . $bid . '-' . $rid, 0, 40);
                $seen[$code] = true;
                $label = 'Blok ' . ($reason !== '' ? '"' . $reason . '" ' : '') . '· ' . $start . ' → ' . $end . ' · ' . $name;
                if (!$lr) {
                    $actions[] = ['type' => 'warn', 'cb' => $bid, 'label' => $label, 'msg' => 'Kamar "' . $name . '" tidak ditemukan di sistem.'];
                    continue;
                }
                $existing = $this->db->fetchOne("SELECT id, block_start_date s, block_end_date e FROM room_blocks WHERE block_code = ? AND status = 'active' LIMIT 1", [$code]);
                // Dibatalkan di sistem (bukan dicabut oleh sinkron) → jangan dibuat ulang; hapus juga di Cloudbeds bila kirim aktif
                if (!$existing) {
                    $userCancelled = $this->db->fetchOne("SELECT id FROM room_blocks WHERE block_code = ? AND status = 'cancelled' AND COALESCE(notes,'') NOT LIKE '%[Dicabut via Cloudbeds]%' LIMIT 1", [$code]);
                    if ($userCancelled) {
                        if ($push) {
                            $removedHere[] = $rid;
                        } else {
                            $actions[] = ['type' => 'warn', 'cb' => $bid, 'label' => $label, 'msg' => 'Blok Room ' . $lr['room_number'] . ' dibatalkan di sistem tetapi masih ada di Cloudbeds — aktifkan "Kirim ke Cloudbeds" atau hapus di Cloudbeds.'];
                        }
                        continue;
                    }
                }
                if ($existing) {
                    if ($existing['s'] !== $start || $existing['e'] !== $end) {
                        $actions[] = ['type' => 'unblock', 'cb' => $bid, 'label' => $label, 'block_id' => (int)$existing['id'], 'msg' => 'Tanggal blok berubah — blok lama Room ' . $lr['room_number'] . ' dicabut'];
                        $actions[] = ['type' => 'block', 'cb' => $bid, 'label' => $label, 'room_id' => (int)$lr['id'], 'room_no' => $lr['room_number'], 'code' => $code, 'start' => $start, 'end' => $end, 'reason' => $reason, 'msg' => 'Blok ulang Room ' . $lr['room_number'] . ' (' . self::nightsLabel($start, $end) . ')'];
                    }
                    continue;
                }
                if ($this->db->fetchOne("SELECT id FROM room_blocks WHERE room_id = ? AND status = 'active' AND block_start_date < ? AND block_end_date > ? LIMIT 1", [(int)$lr['id'], $end, $start])) {
                    continue;
                }
                $conf = $this->db->fetchOne(
                    "SELECT b.booking_code, g.guest_name FROM bookings b LEFT JOIN guests g ON g.id = b.guest_id
                     WHERE b.room_id = ? AND b.status IN ('pending','confirmed','checked_in')
                     AND b.check_in_date < ? AND b.check_out_date > ? LIMIT 1",
                    [(int)$lr['id'], $end, $start]
                );
                // Blok Cloudbeds yang menahan kamar untuk tamu yang sudah ada di sistem (nama blok = nama tamu) → sudah terwakili
                if ($conf && $reason !== '' && array_intersect(self::nameKey($reason), self::nameKey((string)$conf['guest_name']))) {
                    continue;
                }
                if ($conf) {
                    $actions[] = ['type' => 'warn', 'cb' => $bid, 'label' => $label, 'msg' => 'Room ' . $lr['room_number'] . ' ada booking ' . $conf['booking_code'] . ' di tanggal ini — blok tidak dibuat.'];
                    continue;
                }
                $actions[] = ['type' => 'block', 'cb' => $bid, 'label' => $label, 'room_id' => (int)$lr['id'], 'room_no' => $lr['room_number'], 'code' => $code, 'start' => $start, 'end' => $end, 'reason' => $reason,
                    'msg' => 'Blok Room ' . $lr['room_number'] . ' (' . self::nightsLabel($start, $end) . ')'];
            }
            if ($removedHere) {
                $blkLabel = 'Blok ' . ($reason !== '' ? '"' . $reason . '" ' : '') . '· ' . $start . ' → ' . $end;
                $roomsTxt = implode(', ', array_map(fn($x) => $cbRoomName[$x] ?? $x, $removedHere));
                if (count($removedHere) >= count($roomIds)) {
                    $actions[] = ['type' => 'push_delblock', 'cb' => $bid, 'label' => $blkLabel, 'msg' => 'Hapus blok di Cloudbeds (dibatalkan di sistem: ' . $roomsTxt . ')'];
                } else {
                    $actions[] = ['type' => 'push_putblock', 'cb' => $bid, 'label' => $blkLabel, 'start' => $start, 'end' => $end, 'reason' => $reason,
                        'rooms' => array_values(array_diff($roomIds, $removedHere)), 'msg' => 'Keluarkan ' . $roomsTxt . ' dari blok di Cloudbeds'];
                }
            }
        }

        // Blok baru yang dibuat di sistem (setelah kirim diaktifkan) → blok di Cloudbeds
        if ($push) {
            $sinceRow = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_push_since'");
            $since = (string)($sinceRow['setting_value'] ?? '');
            if ($since !== '') {
                $newLocal = $this->db->fetchAll(
                    "SELECT rb.id, rb.block_start_date s, rb.block_end_date e, rb.block_reason, rb.notes, r.room_number
                     FROM room_blocks rb JOIN rooms r ON r.id = rb.room_id
                     WHERE rb.status = 'active' AND COALESCE(rb.block_code,'') NOT LIKE 'CB-%' AND COALESCE(rb.block_code,'') NOT LIKE 'ADFCB-%' AND rb.created_at >= ?
                       AND rb.block_end_date > CURDATE() AND rb.block_start_date <= ? AND rb.block_end_date > ?",
                    [$since, $to, $from]
                ) ?: [];
                $cbRooms = $newLocal ? $this->cbRoomsByNo() : [];
                foreach ($newLocal as $nb) {
                    $no = preg_match('/\d{2,4}/', (string)$nb['room_number'], $mm) ? $mm[0] : (string)$nb['room_number'];
                    $cr = $cbRooms[$no] ?? null;
                    $lbl = 'Blok sistem Room ' . $nb['room_number'] . ' · ' . $nb['s'] . ' → ' . $nb['e'];
                    if (!$cr) {
                        $actions[] = ['type' => 'warn', 'cb' => '-', 'label' => $lbl, 'msg' => 'Room ' . $nb['room_number'] . ' tidak ditemukan di Cloudbeds — blok tidak dikirim.'];
                        continue;
                    }
                    $why = trim(str_replace('_', ' ', (string)$nb['block_reason']) . ($nb['notes'] ? ' - ' . $nb['notes'] : ''));
                    $actions[] = ['type' => 'push_newblock', 'cb' => '-', 'label' => $lbl, 'block_id' => (int)$nb['id'], 'cb_room' => $cr, 'start' => $nb['s'], 'end' => $nb['e'],
                        'reason' => mb_substr('ADF: ' . ($why ?: 'block'), 0, 100), 'msg' => 'Kirim blok ke Cloudbeds: Room ' . $nb['room_number'] . ' (' . self::nightsLabel($nb['s'], $nb['e']) . ')'];
                }
            }
        }

        // Blok dari Cloudbeds yang sudah dihapus di sana → dicabut di sistem
        $local = $this->db->fetchAll(
            "SELECT rb.id, rb.block_code, rb.block_start_date s, rb.block_end_date e, r.room_number
             FROM room_blocks rb JOIN rooms r ON r.id = rb.room_id
             WHERE rb.status = 'active' AND rb.block_code LIKE 'CB-%' AND rb.block_start_date <= ? AND rb.block_end_date > ?",
            [$to, $from]
        ) ?: [];
        foreach ($local as $lb) {
            if (!isset($seen[$lb['block_code']])) {
                $actions[] = ['type' => 'unblock', 'cb' => '-', 'label' => 'Blok Room ' . $lb['room_number'] . ' · ' . $lb['s'] . ' → ' . $lb['e'], 'block_id' => (int)$lb['id'], 'msg' => 'Sudah dihapus di Cloudbeds — blok dicabut'];
            }
        }
    }

    /** Jalankan aksi rencana (link/create/cancel/blok/kirim). Peringatan dilewati. */
    private function executeActions(array $actions, int $userId): array
    {
        $done = ['link' => 0, 'create' => 0, 'cancel' => 0, 'block' => 0, 'unblock' => 0, 'push_status' => 0, 'push_create' => 0, 'push_block' => 0, 'errors' => []];
        // Kolom direct_amount (Hotel Collect) dibuat sebelum transaksi: ALTER di dalam transaksi = implicit commit
        try {
            require_once __DIR__ . '/BookingSourceHelper.php';
            if (function_exists('bs_ensure_direct_amount')) {
                bs_ensure_direct_amount($this->db->getConnection());
            }
        } catch (\Throwable $e) {
        }
        foreach ($actions as $a) {
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
                } elseif ($a['type'] === 'push_status') {
                    foreach ($a['steps'] as $stp) {
                        $r = $this->cb->send('PUT', 'putReservation', ['reservationID' => $a['cb'], 'status' => $stp]);
                        if (!$r['ok']) throw new \RuntimeException('Cloudbeds menolak status ' . $stp . ': ' . $r['detail']);
                    }
                    $done['push_status']++;
                } elseif ($a['type'] === 'push_delblock') {
                    $r = $this->cb->send('DELETE', 'deleteRoomBlock', ['roomBlockID' => $a['cb']]);
                    if (!$r['ok']) throw new \RuntimeException('Cloudbeds menolak hapus blok: ' . $r['detail'] . (in_array($r['http'], [401, 403], true) ? ' (centang scope "Roomblock: Delete" di API key)' : ''));
                    $done['push_block']++;
                } elseif ($a['type'] === 'push_putblock') {
                    $r = $this->cb->send('PUT', 'putRoomBlock', ['roomBlockID' => $a['cb'], 'startDate' => $a['start'], 'endDate' => $a['end'], 'roomBlockReason' => $a['reason'], 'rooms' => $a['rooms']]);
                    if (!$r['ok']) throw new \RuntimeException('Cloudbeds menolak ubah blok: ' . $r['detail']);
                    $done['push_block']++;
                } elseif ($a['type'] === 'push_newblock') {
                    $r = $this->cb->send('POST', 'postRoomBlock', ['startDate' => $a['start'], 'endDate' => $a['end'], 'roomBlockReason' => $a['reason'], 'rooms' => [$a['cb_room']['room_id']]]);
                    if (!$r['ok']) throw new \RuntimeException('Cloudbeds menolak blok baru: ' . $r['detail']);
                    $newId = (string)($r['raw']['roomBlockID'] ?? ($r['data']['roomBlockID'] ?? ''));
                    // Kode CB- agar sinkron masuk mengenali blok ini sebagai pasangan; bila ID tidak terbaca, ADFCB- agar tidak dikirim ulang
                    $this->db->query("UPDATE room_blocks SET block_code = ? WHERE id = ?", [$newId !== '' ? substr('CB-' . $newId . '-' . $a['cb_room']['room_id'], 0, 40) : 'ADFCB-' . $a['block_id'], $a['block_id']]);
                    $done['push_block']++;
                } elseif ($a['type'] === 'push_create') {
                    $this->pushCreate($a);
                    $done['push_create']++;
                } elseif ($a['type'] === 'unblock') {
                    $this->db->query("UPDATE room_blocks SET status = 'cancelled', notes = TRIM(CONCAT(COALESCE(notes,''), ' [Dicabut via Cloudbeds]')) WHERE id = ? AND status = 'active'", [$a['block_id']]);
                    $done['unblock']++;
                } elseif ($a['type'] === 'block') {
                    $uid = $userId > 0 && $this->db->fetchOne("SELECT id FROM users WHERE id = ?", [$userId]) ? $userId : null;
                    $this->db->query(
                        "INSERT INTO room_blocks (block_code, room_id, block_start_date, block_end_date, block_reason, notes, status, created_by)
                         VALUES (?, ?, ?, ?, 'other', ?, 'active', ?)",
                        [$a['code'], $a['room_id'], $a['start'], $a['end'], 'Cloudbeds' . ($a['reason'] !== '' ? ': ' . $a['reason'] : ''), $uid]
                    );
                    $done['block']++;
                } elseif ($a['type'] === 'create') {
                    $this->createBooking($a, $userId);
                    $done['create']++;
                }
            } catch (\Throwable $e) {
                $done['errors'][] = $a['label'] . ': ' . $e->getMessage();
            }
        }
        return $done;
    }

    /**
     * Kirim SEGERA ke Cloudbeds hanya untuk booking/blok tertentu (dipanggil setelah reservasi dibuat,
     * check-in/out, atau blok kamar dibuat/dibatalkan). Hanya aksi "kirim" milik id tersebut yang dijalankan;
     * sisanya tetap diurus sinkron berkala.
     */
    public function pushFor(array $bookingIds, array $blockIds = [], int $userId = 0): array
    {
        if (!$this->pushEnabled()) {
            return ['ok' => true, 'skipped' => 'push_off'];
        }
        $this->ensureTables();
        $bookingIds = array_values(array_filter(array_map('intval', $bookingIds)));
        $blockIds = array_values(array_filter(array_map('intval', $blockIds)));
        $dates = [];
        $cbIds = [];
        if ($bookingIds) {
            $in = implode(',', $bookingIds);
            foreach ($this->db->fetchAll("SELECT DATE(check_in_date) ci, DATE(check_out_date) co FROM bookings WHERE id IN ($in)") ?: [] as $r) {
                $dates[] = $r['ci'];
                $dates[] = $r['co'];
            }
            foreach ($this->db->fetchAll("SELECT cb_reservation_id FROM cloudbeds_booking_links WHERE booking_id IN ($in)") ?: [] as $r) {
                $cbIds[(string)$r['cb_reservation_id']] = true;
            }
        }
        $cbBlockIds = [];
        if ($blockIds) {
            $in = implode(',', $blockIds);
            foreach ($this->db->fetchAll("SELECT block_code, block_start_date s, block_end_date e FROM room_blocks WHERE id IN ($in)") ?: [] as $r) {
                $dates[] = $r['s'];
                $dates[] = $r['e'];
                if (preg_match('/^CB-([^-]+)-/', (string)$r['block_code'], $m)) $cbBlockIds[$m[1]] = true;
            }
        }
        if (!$dates) {
            return ['ok' => true, 'skipped' => 'nothing'];
        }
        $from = min($dates);
        $to = max($dates);
        $plan = $this->plan($from, $to);
        if (!$plan['ok']) {
            return ['ok' => false, 'detail' => $plan['detail']];
        }
        $mine = array_values(array_filter($plan['actions'], function ($a) use ($bookingIds, $blockIds, $cbIds, $cbBlockIds) {
            switch ($a['type']) {
                case 'push_create': return in_array((int)$a['booking_id'], $bookingIds, true);
                case 'push_status': return isset($cbIds[(string)$a['cb']]);
                case 'push_newblock': return in_array((int)$a['block_id'], $blockIds, true);
                case 'push_delblock':
                case 'push_putblock': return isset($cbBlockIds[(string)$a['cb']]);
            }
            return false;
        }));
        if (!$mine) {
            return ['ok' => true, 'skipped' => 'no_push_action'];
        }
        $done = $this->executeActions($mine, $userId);
        if ($done['errors']) {
            error_log('Cloudbeds pushFor: ' . implode(' | ', $done['errors']));
        }
        return ['ok' => !$done['errors'], 'done' => $done];
    }
    /** Hitung ulang rencana lalu jalankan link / create / cancel / block / unblock. Peringatan tidak dieksekusi. */
    public function apply(string $from, string $to, int $userId): array
    {
        $plan = $this->plan($from, $to);
        if (!$plan['ok']) {
            return $plan + ['done' => []];
        }
        $done = $this->executeActions($plan['actions'], $userId);
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
        // created_by merujuk users di database bisnis; user dari database pusat (mis. Developer) tidak ada di sana
        if ($userId > 0) {
            $chk = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $chk->execute([$userId]);
            if (!$chk->fetchColumn()) {
                $userId = 0;
            }
        }
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
