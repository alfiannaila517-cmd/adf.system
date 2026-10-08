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
    /** roomBlockType yang dipakai blok Cloudbeds yang sudah ada (nilai sah untuk properti ini) */
    private $learnedBlockType = '';
    /** pushFor: rencana ringan — tanpa panggilan detail reservasi (booking baru & cek harga diurus sinkron berkala) */
    private $pushOnlyPlan = false;
    /** Maks. panggilan detail untuk cek harga per sinkron; tiap reservasi dicek ulang paling cepat tiap 3 jam */
    private const MAX_PRICE_DETAIL = 6;
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
        $this->db->getConnection()->exec("CREATE TABLE IF NOT EXISTS cloudbeds_payment_links (
            payment_id INT NOT NULL PRIMARY KEY,
            cb_reservation_id VARCHAR(40) NOT NULL,
            cb_payment_id VARCHAR(60) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->getConnection()->exec("CREATE TABLE IF NOT EXISTS cloudbeds_pending_edits (
            booking_id INT NOT NULL PRIMARY KEY,
            last_error VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->getConnection()->exec("CREATE TABLE IF NOT EXISTS cloudbeds_price_sync (
            cb_reservation_id VARCHAR(40) NOT NULL PRIMARY KEY,
            synced_total DECIMAL(14,2) NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Perpanjangan booking OTA: tanggal check-out Cloudbeds digeser (mode date) atau malam tambahan diblok (mode block)
        $this->db->getConnection()->exec("CREATE TABLE IF NOT EXISTS cloudbeds_extensions (
            cb_reservation_id VARCHAR(40) NOT NULL,
            booking_id INT NOT NULL,
            orig_end DATE NOT NULL,
            mode VARCHAR(8) NOT NULL DEFAULT 'date',
            cb_block_id VARCHAR(40) NULL,
            cb_room_id VARCHAR(40) NULL,
            block_start DATE NULL,
            block_end DATE NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (cb_reservation_id, booking_id),
            KEY idx_booking (booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->getConnection()->exec("CREATE TABLE IF NOT EXISTS cloudbeds_price_checks (
            cb_reservation_id VARCHAR(40) NOT NULL PRIMARY KEY,
            checked_at DATETIME NOT NULL
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
        // Booking OTA yang diperpanjang di sistem: beda tanggal dengan Cloudbeds memang disengaja
        $extended = [];
        try {
            foreach ($this->db->fetchAll("SELECT booking_id FROM cloudbeds_extensions") ?: [] as $x) $extended[(int)$x['booking_id']] = true;
        } catch (\Throwable $e) {
        }

        $localBk = $this->db->fetchAll(
            "SELECT b.id, b.booking_code, b.group_id, b.status, b.paid_amount, DATE(b.check_in_date) ci, DATE(b.check_out_date) co, g.guest_name, r.room_number
             FROM bookings b LEFT JOIN guests g ON g.id = b.guest_id LEFT JOIN rooms r ON r.id = b.room_id
             WHERE DATE(b.check_in_date) BETWEEN ? AND ?",
            [$from, $to]
        ) ?: [];

        $actions = [];
        $nDetail = 0;
        $nPriceDetail = 0;
        $recentPriceChecks = [];
        if (!$this->pushOnlyPlan) {
            try {
                foreach ($this->db->fetchAll("SELECT cb_reservation_id FROM cloudbeds_price_checks WHERE checked_at > NOW() - INTERVAL 3 HOUR") ?: [] as $pc) $recentPriceChecks[(string)$pc['cb_reservation_id']] = true;
            } catch (\Throwable $e) {
            }
        }
        // Booking yang sedang menunggu edit dari sistem → harganya jangan ditimpa harga Cloudbeds dulu
        $pendingEditIds = [];
        try {
            foreach ($this->db->fetchAll("SELECT booking_id FROM cloudbeds_pending_edits") ?: [] as $pe) $pendingEditIds[(int)$pe['booking_id']] = true;
        } catch (\Throwable $e) {
        }
        foreach ($list['items'] as $it) {
            $cbId = $it['id'];
            $label = $it['guest'] . ' · ' . $it['checkin'] . ' → ' . $it['checkout'] . ' · ' . $it['source'];

            // Sudah tertaut
            if (isset($links[$cbId])) {
                $bks = $this->db->fetchAll(
                    "SELECT id, booking_code, status, paid_amount, final_price, DATE(check_in_date) ci, DATE(check_out_date) co FROM bookings WHERE id IN (" .
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
                    } elseif (in_array($bk['status'], ['confirmed', 'pending'], true) && ($bk['ci'] !== $it['checkin'] || $bk['co'] !== $it['checkout'])
                        && !(isset($extended[(int)$bk['id']]) && $bk['ci'] === $it['checkin'])) {
                        $actions[] = ['type' => 'warn', 'cb' => $cbId, 'label' => $label, 'msg' => 'Tanggal berubah di Cloudbeds; ' . $bk['booking_code'] . ' masih ' . $bk['ci'] . ' → ' . $bk['co'] . '. Sesuaikan manual.'];
                    }
                }
                // Harga mengikuti Cloudbeds (sumber rate plan) selama booking belum ada pembayaran sama sekali
                // dan tidak sedang menunggu edit dari sistem; setelah dibayar harga dikunci.
                $live = array_values(array_filter($bks, fn($b) => in_array($b['status'], ['confirmed', 'pending', 'checked_in'], true)));
                if (!$this->pushOnlyPlan && !self::isCancelled($it['status']) && $live && count($live) === count(array_filter($bks, fn($b) => $b['status'] !== 'cancelled'))
                    && !array_filter($live, fn($b) => (float)$b['paid_amount'] > 0 || isset($pendingEditIds[(int)$b['id']]))) {
                    $liveIn = implode(',', array_map(fn($b) => (int)$b['id'], $live));
                    $hasPay = $this->db->fetchOne("SELECT id FROM booking_payments WHERE booking_id IN ($liveIn) AND amount > 0 LIMIT 1");
                    $cbTotal = $it['total'] ?? null;
                    // Total tidak ada di daftar reservasi → detail, dibatasi & bergiliran (dicek ulang paling cepat tiap 3 jam).
                    // Sebelum Payment harga tetap diambil langsung (refreshPriceFromCloudbeds), jadi ini hanya penyamaan berkala.
                    if (!$hasPay && $cbTotal === null && $nPriceDetail < self::MAX_PRICE_DETAIL && max(array_column($live, 'co')) >= date('Y-m-d')
                        && !isset($recentPriceChecks[$cbId])) {
                        $nPriceDetail++;
                        $pd = $this->cb->reservationDetail($cbId);
                        $cbTotal = $pd['ok'] ? $pd['total'] : null;
                        $this->db->query("INSERT INTO cloudbeds_price_checks (cb_reservation_id, checked_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE checked_at = NOW()", [$cbId]);
                    }
                    $localTotal = array_sum(array_map(fn($b) => (float)$b['final_price'], $live));
                    if (!$hasPay && $cbTotal !== null && (float)$cbTotal > 0 && abs((float)$cbTotal - $localTotal) >= 1) {
                        $actions[] = ['type' => 'price', 'cb' => $cbId, 'label' => $label, 'booking_ids' => array_map(fn($b) => (int)$b['id'], $live), 'cb_total' => (float)$cbTotal,
                            'msg' => 'Samakan harga dengan Cloudbeds: Rp ' . number_format($localTotal, 0, ',', '.') . ' → Rp ' . number_format((float)$cbTotal, 0, ',', '.') . ' (' . implode(', ', array_column($live, 'booking_code')) . ')'];
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
            if ($this->pushOnlyPlan) {
                continue; // pengiriman cepat (setelah Payment / check-in / blok): booking baru diurus sinkron berkala
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

        // ---- Pembayaran di sistem → folio reservasi Cloudbeds ----
        if ($this->payEnabled()) {
            $this->planPayments($actions);
        }

        // ---- Blok kamar Cloudbeds → room_blocks (kode CB-<blockID>-<roomID>) ----
        $this->planRoomBlocks($from, $to, $roomByNo, $actions);

        $counts = ['link' => 0, 'create' => 0, 'price' => 0, 'cancel' => 0, 'block' => 0, 'unblock' => 0, 'push_status' => 0, 'push_create' => 0, 'push_block' => 0, 'push_pay' => 0, 'warn' => 0];
        foreach ($actions as $a) {
            $t = in_array($a['type'], ['push_delblock', 'push_putblock', 'push_newblock'], true) ? 'push_block' : ($a['type'] === 'push_payment' ? 'push_pay' : ($a['type'] === 'adopt_block' ? 'link' : ($a['type'] === 'push_convert_block' ? 'push_create' : $a['type'])));
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

    /* ---------------- Edit reservasi: sistem → Cloudbeds ---------------- */

    /** Tandai booking yang diedit (harga, tanggal, kamar, extra) untuk disamakan ke Cloudbeds. */
    public function markEdited(array $bookingIds): void
    {
        $this->ensureTables();
        foreach (array_filter(array_map('intval', $bookingIds)) as $id) {
            $this->db->query("INSERT INTO cloudbeds_pending_edits (booking_id) VALUES (?) ON DUPLICATE KEY UPDATE created_at = NOW(), last_error = NULL", [$id]);
        }
    }

    /**
     * Samakan reservasi Cloudbeds dengan sistem untuk booking di antrean edit (atau hanya $onlyIds):
     * kamar (pindah kamar), tanggal check-out (reservasi buatan sistem), dan total harga (adjustment selisih
     * terhadap total yang terakhir disamakan). Berhasil → keluar dari antrean; gagal → tetap untuk dicoba lagi.
     * @return array{done:int, errors:array}
     */
    public function processEdits(array $onlyIds = [], int $limit = 20): array
    {
        $this->ensureTables();
        $where = $onlyIds ? ' WHERE pe.booking_id IN (' . implode(',', array_map('intval', $onlyIds)) . ')' : '';
        $pending = $this->db->fetchAll("SELECT pe.booking_id, l.cb_reservation_id FROM cloudbeds_pending_edits pe
            LEFT JOIN cloudbeds_booking_links l ON l.booking_id = pe.booking_id" . $where . " ORDER BY pe.created_at LIMIT " . (int)$limit) ?: [];
        $byCb = [];
        foreach ($pending as $p) {
            if (empty($p['cb_reservation_id'])) {
                continue; // belum tertaut (mis. menunggu dikirim) — tetap di antrean
            }
            $byCb[(string)$p['cb_reservation_id']][] = (int)$p['booking_id'];
        }
        $done = 0;
        $errors = [];
        foreach ($byCb as $cbId => $ids) {
            try {
                $changed = $this->syncReservationEdits($cbId);
                $in = implode(',', array_map('intval', $ids));
                $this->db->query("DELETE FROM cloudbeds_pending_edits WHERE booking_id IN ($in)");
                if ($changed) $done++;
            } catch (\Throwable $e) {
                $msg = 'Edit reservasi #' . $cbId . ': ' . $e->getMessage();
                $errors[] = $msg;
                $in = implode(',', array_map('intval', $ids));
                $this->db->query("UPDATE cloudbeds_pending_edits SET last_error = ? WHERE booking_id IN ($in)", [mb_substr($msg, 0, 255)]);
            }
        }
        return ['done' => $done, 'errors' => $errors];
    }

    /** Satu reservasi Cloudbeds: kamar, check-out, harga. true bila ada yang dikirim. */
    private function syncReservationEdits(string $cbId): bool
    {
        $links = $this->db->fetchAll("SELECT booking_id, how FROM cloudbeds_booking_links WHERE cb_reservation_id = ?", [$cbId]) ?: [];
        if (!$links) return false;
        $in = implode(',', array_map(fn($l) => (int)$l['booking_id'], $links));
        $bks = $this->db->fetchAll("SELECT b.id, b.status, b.final_price, DATE(b.check_in_date) ci, DATE(b.check_out_date) co, r.room_number, g.guest_name
            FROM bookings b JOIN rooms r ON r.id = b.room_id LEFT JOIN guests g ON g.id = b.guest_id WHERE b.id IN ($in) AND b.status <> 'cancelled'") ?: [];
        if (!$bks) {
            // Semua dibatalkan → blok perpanjangan (bila ada) tidak diperlukan lagi
            return $this->dropExtensions($cbId);
        }
        $allPush = !array_filter($links, fn($l) => $l['how'] !== 'push');

        $det = $this->cb->reservationDetail($cbId);
        if (!$det['ok']) throw new \RuntimeException('detail tidak terbaca: ' . $det['detail']);
        $raw = is_array($det['raw']) ? $det['raw'] : [];
        $sent = false;
        $reshaped = false; // kamar/tanggal berubah di Cloudbeds → total Cloudbeds ikut berubah
        $totalBefore = $det['total'];

        // 1) Kamar: kamar sistem yang belum ada di reservasi Cloudbeds menggantikan kamar Cloudbeds yang tidak dipakai lagi
        $cbRooms = $this->cbRoomsByNo();
        $want = [];
        foreach ($bks as $b) {
            $no = preg_match('/\d{2,4}/', (string)$b['room_number'], $m) ? $m[0] : (string)$b['room_number'];
            if (isset($cbRooms[$no])) $want[$cbRooms[$no]['room_id']] = $cbRooms[$no];
        }
        $have = [];
        foreach ((array)($det['rooms'] ?? []) as $r) {
            if ($r['assigned'] && $r['room_id'] !== '') $have[$r['room_id']] = $r;
        }
        $toAssign = array_values(array_diff_key($want, $have));
        $toFree = array_values(array_diff_key($have, $want));
        foreach ($toAssign as $i => $new) {
            $p = ['reservationID' => $cbId, 'newRoomID' => $new['room_id'], 'roomTypeID' => $new['type_id']];
            if (isset($toFree[$i])) $p['oldRoomID'] = $toFree[$i]['room_id'];
            $r = $this->cb->send('POST', 'postRoomAssign', $p);
            if (!$r['ok']) throw new \RuntimeException('pindah kamar ditolak: ' . $r['detail']);
            $sent = $reshaped = true;
        }

        // Dasar harga diambil SEBELUM tanggal diubah (Cloudbeds menambah sendiri harga malam tambahan)
        $localTotal = round(array_sum(array_map(fn($b) => (float)$b['final_price'], $bks)), 2);
        $row = $this->db->fetchOne("SELECT synced_total FROM cloudbeds_price_sync WHERE cb_reservation_id = ?", [$cbId]);
        if ($row) {
            $base = (float)$row['synced_total'];
        } else {
            $bd = is_array($raw['balanceDetailed'] ?? null) ? $raw['balanceDetailed'] : [];
            $base = isset($bd['subTotal']) && is_numeric($bd['subTotal']) ? (float)$bd['subTotal'] : (float)($det['total'] ?? $localTotal);
        }

        // 2) Check-out
        $cbEnd = substr((string)($raw['endDate'] ?? ''), 0, 10);
        $localEnd = max(array_column($bks, 'co'));
        if ($allPush) {
            // Reservasi buatan sistem: tanggal Cloudbeds selalu mengikuti sistem
            if ($cbEnd !== '' && $localEnd !== $cbEnd) {
                $r = $this->cb->send('PUT', 'putReservation', ['reservationID' => $cbId, 'checkoutDate' => $localEnd]);
                if (!$r['ok']) throw new \RuntimeException('ubah check-out ditolak: ' . $r['detail']);
                $sent = $reshaped = true;
            }
        } elseif ($cbEnd !== '') {
            // Reservasi OTA: hanya PERPANJANGAN yang dikirim (tidak pernah dipendekkan dari tanggal OTA)
            [$s2, $r2] = $this->syncExtensions($cbId, $bks, $cbEnd, $det, $cbRooms);
            $sent = $sent || $s2;
            $reshaped = $reshaped || $r2;
        }

        // 3) Harga: adjustment sebesar selisih terhadap total yang terakhir disamakan
        if ($reshaped && $totalBefore !== null) {
            $after = $this->cb->reservationDetail($cbId);
            if ($after['ok'] && $after['total'] !== null) {
                $base += (float)$after['total'] - (float)$totalBefore;
            }
        }
        $delta = round($localTotal - $base, 2);
        if (abs($delta) >= 1) {
            $r = $this->cb->send('POST', 'postAdjustment', [
                'reservationID' => $cbId,
                'type' => 'rate',
                'amount' => $delta,
                'notes' => mb_substr('ADF: total sistem Rp ' . number_format($localTotal, 0, ',', '.') . ' (sebelumnya Rp ' . number_format($base, 0, ',', '.') . ')', 0, 250),
            ]);
            if (!$r['ok']) throw new \RuntimeException('adjustment harga ditolak: ' . $r['detail'] . (in_array((int)$r['http'], [401, 403], true) ? ' (perlu scope "Adjustment: Write")' : ''));
            $sent = true;
        }
        $this->db->query("INSERT INTO cloudbeds_price_sync (cb_reservation_id, synced_total) VALUES (?, ?) ON DUPLICATE KEY UPDATE synced_total = VALUES(synced_total)", [$cbId, $localTotal]);
        return $sent;
    }

    /**
     * Reservasi OTA yang diperpanjang di sistem: malam tambahan harus tertutup juga di Cloudbeds agar kamar
     * tidak terjual lagi lewat OTA. Satu kamar → tanggal check-out Cloudbeds digeser; bila ditolak atau
     * multi-kamar → kamar diblok untuk malam tambahan saja (alasan "ADF:" agar tidak ditarik balik sebagai blok).
     * Tanggal OTA tidak pernah dipendekkan. Perpanjangan dikurangi / dibatalkan / tamu check-out → disesuaikan.
     * @return array{0:bool,1:bool} [ada yang dikirim, tanggal reservasi Cloudbeds berubah]
     */
    private function syncExtensions(string $cbId, array $bks, string $cbEnd, array $det, array $cbRooms): array
    {
        $rows = [];
        foreach ($this->db->fetchAll("SELECT * FROM cloudbeds_extensions WHERE cb_reservation_id = ?", [$cbId]) ?: [] as $x) {
            $rows[(int)$x['booking_id']] = $x;
        }
        $roomEnd = [];
        foreach ((array)($det['rooms'] ?? []) as $r) {
            if ($r['assigned'] && $r['room_id'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$r['end'])) $roomEnd[$r['room_id']] = substr((string)$r['end'], 0, 10);
        }
        $single = count($bks) === 1;
        $sent = $reshaped = false;
        foreach ($bks as $b) {
            $id = (int)$b['id'];
            $row = $rows[$id] ?? null;
            $no = preg_match('/\d{2,4}/', (string)$b['room_number'], $m) ? $m[0] : (string)$b['room_number'];
            $cr = $cbRooms[$no] ?? null;
            $orig = $row['orig_end'] ?? (!$single && $cr && isset($roomEnd[$cr['room_id']]) ? $roomEnd[$cr['room_id']] : $cbEnd);
            $active = in_array($b['status'], ['pending', 'confirmed', 'checked_in'], true);

            // Tidak (lagi) diperpanjang
            if (!$active || $b['co'] <= $orig) {
                if ($row) {
                    if ($row['mode'] === 'block') {
                        $this->deleteExtensionBlock($row);
                    } elseif ($active && $single && $cbEnd !== $orig) {
                        // Perpanjangan dibatalkan: kembalikan tanggal OTA semula (tamu yang sudah check-out tidak diubah)
                        $r = $this->cb->send('PUT', 'putReservation', ['reservationID' => $cbId, 'checkoutDate' => $orig]);
                        if (!$r['ok']) throw new \RuntimeException('kembalikan check-out ditolak: ' . $r['detail']);
                        $reshaped = true;
                    }
                    $this->db->query("DELETE FROM cloudbeds_extensions WHERE cb_reservation_id = ? AND booking_id = ?", [$cbId, $id]);
                    $sent = true;
                }
                continue;
            }

            // Satu kamar: geser tanggal check-out di Cloudbeds
            if ($single && (!$row || $row['mode'] === 'date')) {
                if ($cbEnd === $b['co']) {
                    if (!$row) $this->saveExtension($cbId, $id, $orig, 'date');
                    continue;
                }
                $r = $this->cb->send('PUT', 'putReservation', ['reservationID' => $cbId, 'checkoutDate' => $b['co']]);
                if ($r['ok']) {
                    $this->saveExtension($cbId, $id, $orig, 'date');
                    $sent = $reshaped = true;
                    continue;
                }
                error_log('Cloudbeds perpanjangan #' . $cbId . ': ubah check-out ditolak (' . $r['detail'] . ') → blok malam tambahan');
            }

            // Blok kamar untuk malam tambahan
            if (!$cr) throw new \RuntimeException('Room ' . $b['room_number'] . ' tidak ditemukan di Cloudbeds — malam perpanjangan tidak bisa diblok');
            $start = $single ? max($orig, $cbEnd) : $orig;
            if ($start >= $b['co']) continue;
            if ($row && $row['mode'] === 'block' && $row['cb_block_id'] && $row['cb_room_id'] === $cr['room_id'] && $row['block_start'] === $start && $row['block_end'] === $b['co']) {
                continue;
            }
            $params = ['startDate' => $start, 'endDate' => $b['co'], 'roomBlockReason' => mb_substr('ADF: perpanjangan ' . trim((string)($b['guest_name'] ?? '')) . ' (Cloudbeds #' . $cbId . ')', 0, 100), 'rooms' => [['roomID' => $cr['room_id']]]];
            if ($row && $row['mode'] === 'block' && $row['cb_block_id']) {
                $r = $this->sendBlock('PUT', 'putRoomBlock', ['roomBlockID' => $row['cb_block_id']] + $params, '');
                $blockId = (string)$row['cb_block_id'];
            } else {
                $r = $this->sendBlock('POST', 'postRoomBlock', $params, '');
                $blockId = $r['ok'] ? self::findValue($r['raw'], 'roomblockid') : '';
                if ($r['ok'] && $blockId === '') $blockId = $this->findBlockId($cr['room_id'], $start, $b['co']);
            }
            if (!$r['ok']) throw new \RuntimeException('blok malam perpanjangan Room ' . $b['room_number'] . ' ditolak: ' . $r['detail']);
            $this->saveExtension($cbId, $id, $orig, 'block', $blockId ?: null, $cr['room_id'], $start, $b['co']);
            $sent = true;
        }
        // Kamar grup yang dibatalkan (tidak ada di $bks) → lepas bloknya
        $present = array_map(fn($b) => (int)$b['id'], $bks);
        foreach ($rows as $id => $row) {
            if (in_array($id, $present, true)) continue;
            if ($row['mode'] === 'block') $this->deleteExtensionBlock($row);
            $this->db->query("DELETE FROM cloudbeds_extensions WHERE cb_reservation_id = ? AND booking_id = ?", [$cbId, $id]);
            $sent = true;
        }
        return [$sent, $reshaped];
    }

    private function saveExtension(string $cbId, int $bookingId, string $orig, string $mode, ?string $blockId = null, ?string $roomId = null, ?string $start = null, ?string $end = null): void
    {
        $this->db->query(
            "INSERT INTO cloudbeds_extensions (cb_reservation_id, booking_id, orig_end, mode, cb_block_id, cb_room_id, block_start, block_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE mode = VALUES(mode), cb_block_id = VALUES(cb_block_id), cb_room_id = VALUES(cb_room_id), block_start = VALUES(block_start), block_end = VALUES(block_end)",
            [$cbId, $bookingId, $orig, $mode, $blockId, $roomId, $start, $end]
        );
    }

    /** Hapus blok perpanjangan di Cloudbeds (sudah terhapus di sana = dianggap berhasil). */
    private function deleteExtensionBlock(array $row): void
    {
        $bid = (string)($row['cb_block_id'] ?? '');
        if ($bid === '' && $row['cb_room_id'] && $row['block_start'] && $row['block_end']) {
            $bid = $this->findBlockId((string)$row['cb_room_id'], (string)$row['block_start'], (string)$row['block_end']);
        }
        if ($bid === '') return;
        $r = $this->deleteCbBlock($bid, $row['block_start'] ?? null, $row['block_end'] ?? null);
        if (!$r['ok']) {
            throw new \RuntimeException('hapus blok perpanjangan ditolak: ' . $r['detail'] . (in_array((int)$r['http'], [401, 403], true) ? ' (centang scope "Roomblock: Delete" di API key)' : ''));
        }
    }

    /** Semua booking reservasi ini batal → hapus blok perpanjangannya. */
    private function dropExtensions(string $cbId): bool
    {
        $rows = $this->db->fetchAll("SELECT * FROM cloudbeds_extensions WHERE cb_reservation_id = ?", [$cbId]) ?: [];
        foreach ($rows as $row) {
            if ($row['mode'] === 'block') $this->deleteExtensionBlock($row);
        }
        if ($rows) $this->db->query("DELETE FROM cloudbeds_extensions WHERE cb_reservation_id = ?", [$cbId]);
        return (bool)$rows;
    }

    /** ID blok "ADF: perpanjangan" di Cloudbeds untuk kamar & tanggal tertentu (bila jawaban POST tidak memuat ID). */
    private function findBlockId(string $roomId, string $start, string $end): string
    {
        $q = ['startDate' => $start, 'endDate' => min($end, date('Y-m-d', strtotime($start . ' +30 days')))];
        if ($this->cb->propertyId() !== '') $q['propertyID'] = $this->cb->propertyId();
        $res = $this->cb->get('getRoomBlocks', $q);
        $found = '';
        $walk = function ($d) use (&$walk, &$found, $roomId, $start, $end) {
            if (!is_array($d) || $found !== '') return;
            if (isset($d['roomBlockID']) && !is_array($d['roomBlockID'])) {
                $rooms = array_map(fn($r) => is_array($r) ? (string)($r['roomID'] ?? '') : (string)$r, (array)($d['rooms'] ?? []));
                if (substr((string)($d['startDate'] ?? ''), 0, 10) === $start && substr((string)($d['endDate'] ?? ''), 0, 10) === $end
                    && in_array($roomId, $rooms, true) && stripos((string)($d['roomBlockReason'] ?? ''), 'ADF: perpanjangan') === 0) {
                    $found = (string)$d['roomBlockID'];
                }
                return;
            }
            foreach ($d as $v) $walk($v);
        };
        if ($res['ok']) $walk($res['data']);
        return $found;
    }

    /** Booking dengan perpanjangan di Cloudbeds yang batal / sudah check-out → masuk antrean edit (blok dilepas). */
    private function queueEndedExtensions(array $onlyBookingIds = []): void
    {
        $where = $onlyBookingIds ? ' AND e.booking_id IN (' . implode(',', array_map('intval', $onlyBookingIds)) . ')' : '';
        $ids = $this->db->fetchAll("SELECT e.booking_id FROM cloudbeds_extensions e LEFT JOIN bookings b ON b.id = e.booking_id
            WHERE (b.id IS NULL OR b.status NOT IN ('pending','confirmed','checked_in') OR DATE(b.check_out_date) <= e.orig_end)" . $where) ?: [];
        if ($ids) $this->markEdited(array_column($ids, 'booking_id'));
    }

    /**
     * Total booking (satu reservasi Cloudbeds, bisa beberapa kamar) disamakan dengan total Cloudbeds. Dibagi ke kamar
     * sesuai porsi harga lama. Hanya untuk booking yang belum ada pembayaran (dicek ulang di sini).
     */
    private function applyCloudbedsPrice(string $cbId, array $ids, float $cbTotal): void
    {
        $in = implode(',', array_map('intval', $ids));
        if ($in === '') return;
        if ($this->db->fetchOne("SELECT id FROM booking_payments WHERE booking_id IN ($in) AND amount > 0 LIMIT 1")) return;
        $rows = $this->db->fetchAll("SELECT id, final_price, total_price, COALESCE(discount, 0) discount, total_nights, paid_amount FROM bookings WHERE id IN ($in)") ?: [];
        if (!$rows || array_filter($rows, fn($r) => (float)$r['paid_amount'] > 0)) return;
        $old = array_sum(array_map(fn($r) => (float)$r['final_price'], $rows));
        $left = round($cbTotal, 2);
        $hasDirect = true;
        try {
            $this->db->getConnection()->query("SELECT direct_amount FROM bookings LIMIT 0");
        } catch (\Throwable $e) {
            $hasDirect = false;
        }
        foreach (array_values($rows) as $i => $r) {
            $share = $i === count($rows) - 1 ? $left : round($cbTotal * ($old > 0 ? (float)$r['final_price'] / $old : 1 / count($rows)), 2);
            $left -= $share;
            $nights = max(1, (int)$r['total_nights']);
            $gross = $share + (float)$r['discount'];
            $this->db->query("UPDATE bookings SET final_price = ?, total_price = ?, room_price = ?, updated_at = NOW() WHERE id = ?", [$share, $gross, round($gross / $nights, 2), (int)$r['id']]);
            // Hotel Collect: bagian bayar langsung = seluruh harga → ikut harga baru
            if ($hasDirect) {
                $this->db->query("UPDATE bookings SET direct_amount = ? WHERE id = ? AND COALESCE(direct_amount, 0) > 0 AND direct_amount + 0.01 >= ?", [$share, (int)$r['id'], (float)$r['final_price']]);
            }
        }
        // Dasar penyamaan harga sistem → Cloudbeds (adjustment) ikut total baru
        $this->db->query("INSERT INTO cloudbeds_price_sync (cb_reservation_id, synced_total) VALUES (?, ?) ON DUPLICATE KEY UPDATE synced_total = VALUES(synced_total)", [$cbId, round($cbTotal, 2)]);
    }

    /**
     * Blok Cloudbeds yang dulu dibuat untuk menahan kamar seorang tamu (sebelum integrasi) → diganti reservasi
     * Cloudbeds untuk booking sistem tamu itu. Kamar dikeluarkan dari blok dulu (agar bisa ditempati reservasi);
     * bila reservasi gagal dibuat, blok dipasang lagi supaya kamar tidak sempat terbuka untuk OTA.
     */
    private function convertBlockToReservation(array $a): void
    {
        $blk = $a['block'];
        $others = array_values(array_diff($blk['rooms'], [$a['rid']]));
        $base = ['startDate' => $blk['start'], 'endDate' => $blk['end'], 'roomBlockReason' => $blk['reason']];
        if ($others) {
            $r = $this->sendBlock('PUT', 'putRoomBlock', ['roomBlockID' => $a['cb']] + $base + ['rooms' => array_map(fn($x) => ['roomID' => $x], $others)], $blk['type']);
        } else {
            $r = $this->deleteCbBlock($a['cb'], $blk['start'], $blk['end']);
        }
        if (!$r['ok']) {
            throw new \RuntimeException('blok Cloudbeds tidak bisa dilepas: ' . $r['detail'] . (in_array((int)$r['http'], [401, 403], true) ? ' (centang scope "Roomblock: Delete")' : ''));
        }
        try {
            $this->pushCreate($a);
        } catch (\Throwable $e) {
            // Kembalikan blok seperti semula
            if ($others) {
                $this->sendBlock('PUT', 'putRoomBlock', ['roomBlockID' => $a['cb']] + $base + ['rooms' => array_map(fn($x) => ['roomID' => $x], $blk['rooms'])], $blk['type']);
            } else {
                $this->sendBlock('POST', 'postRoomBlock', $base + ['rooms' => [['roomID' => $a['rid']]]], $blk['type']);
            }
            throw new \RuntimeException($e->getMessage() . ' — blok dipasang kembali');
        }
    }

    /**
     * Hapus blok di Cloudbeds. Format permintaan dicoba bergantian (DELETE parameter di URL / di body / POST) dan yang
     * berhasil diingat. Bila semua ditolak tetapi blok memang sudah tidak ada di Cloudbeds → dianggap berhasil.
     */
    private function deleteCbBlock(string $bid, ?string $start = null, ?string $end = null): array
    {
        $savedRow = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_delblock_mode'");
        $modes = array_values(array_unique(array_filter([(string)($savedRow['setting_value'] ?? ''), 'DELETE', 'DELETE:body', 'POST'])));
        $last = ['ok' => false, 'http' => 0, 'detail' => 'tidak dicoba', 'data' => null, 'raw' => null];
        $tried = [];
        foreach ($modes as $mode) {
            $r = $this->cb->send($mode, 'deleteRoomBlock', ['roomBlockID' => $bid]);
            if ($r['ok']) {
                $this->db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('cloudbeds_delblock_mode', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$mode]);
                return $r;
            }
            $last = $r;
            $tried[] = $mode . ' → ' . $r['detail'];
            if (in_array((int)$r['http'], [401, 403], true)) return $r; // scope kurang: format lain tidak membantu
        }
        if ($start && $end && !$this->cbBlockExists($bid, $start, $end)) {
            return ['ok' => true, 'http' => 200, 'detail' => 'sudah tidak ada', 'data' => null, 'raw' => null];
        }
        $last['detail'] = implode(' | ', $tried);
        return $last;
    }

    /** Apakah blok Cloudbeds ini masih ada (dibaca dari getRoomBlocks pada tanggalnya). Gagal baca → dianggap ada. */
    private function cbBlockExists(string $bid, string $start, string $end): bool
    {
        $q = ['startDate' => $start, 'endDate' => min($end, date('Y-m-d', strtotime($start . ' +29 days')))];
        if ($this->cb->propertyId() !== '') $q['propertyID'] = $this->cb->propertyId();
        $res = $this->cb->get('getRoomBlocks', $q);
        if (!$res['ok']) return true;
        $found = false;
        $walk = function ($d) use (&$walk, &$found, $bid) {
            if (!is_array($d) || $found) return;
            if (isset($d['roomBlockID']) && !is_array($d['roomBlockID'])) {
                if ((string)$d['roomBlockID'] === $bid) $found = true;
                return;
            }
            foreach ($d as $v) $walk($v);
        };
        $walk($res['data']);
        return $found;
    }

    /** Kirim blok ke Cloudbeds dengan roomBlockType yang sah: jenis blok itu sendiri / yang tersimpan / yang
     * dipakai blok lain di properti, lalu tanpa jenis, lalu jenis umum. Jenis yang berhasil disimpan.
     */
    private function sendBlock(string $method, string $endpoint, array $params, string $prefer): array
    {
        $savedRow = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_block_type'");
        $cands = array_values(array_unique(array_filter([$prefer, (string)($savedRow['setting_value'] ?? ''), $this->learnedBlockType], fn($x) => $x !== '')));
        $cands[] = null; // tanpa roomBlockType
        foreach (['blocked_dates', 'out_of_service', 'courtesy_hold', 'block'] as $c) {
            if (!in_array($c, $cands, true)) $cands[] = $c;
        }
        $last = ['ok' => false, 'http' => 0, 'detail' => 'tidak ada jenis blok yang diterima', 'data' => null, 'raw' => null];
        foreach ($cands as $type) {
            $p = $params;
            if ($type !== null) $p['roomBlockType'] = $type;
            $r = $this->cb->send($method, $endpoint, $p);
            if ($r['ok']) {
                if ($type !== null) {
                    $this->db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('cloudbeds_block_type', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$type]);
                }
                return $r;
            }
            $last = $r;
            // Hanya coba jenis lain bila yang ditolak memang jenis bloknya
            if (stripos($r['detail'], 'roomBlockType') === false && stripos($r['detail'], 'block type') === false) {
                return $r;
            }
        }
        return $last;
    }

    /** Nilai pertama dengan nama kunci (tanpa beda huruf besar) di seluruh struktur jawaban. */
    private static function findValue($data, string $keyLower): string
    {
        if (!is_array($data)) return '';
        foreach ($data as $k => $v) {
            if (strtolower((string)$k) === $keyLower && !is_array($v) && (string)$v !== '') return (string)$v;
        }
        foreach ($data as $v) {
            $x = self::findValue($v, $keyLower);
            if ($x !== '') return $x;
        }
        return '';
    }

    /** Catat hasil kiriman ke Cloudbeds agar terlihat di halaman (galat terakhir / berhasil terakhir). */
    private function rememberPushError(array $errors): void
    {
        $this->db->query(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('cloudbeds_last_push_error', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [json_encode(['at' => date('Y-m-d H:i:s'), 'errors' => array_slice($errors, 0, 5)], JSON_UNESCAPED_UNICODE)]
        );
    }

    private function rememberPushResult(array $done): void
    {
        if (!empty($done['errors'])) {
            $pushErrors = array_values(array_filter($done['errors'], fn($e) => stripos($e, 'Cloudbeds menolak') !== false || stripos($e, 'Cloudbeds') !== false));
            if ($pushErrors) $this->rememberPushError($pushErrors);
        }
        $sent = ($done['push_status'] ?? 0) + ($done['push_create'] ?? 0) + ($done['push_block'] ?? 0) + ($done['push_pay'] ?? 0) + ($done['push_edit'] ?? 0);
        if ($sent > 0) {
            $this->db->query(
                "INSERT INTO settings (setting_key, setting_value) VALUES ('cloudbeds_last_push_ok', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                [date('Y-m-d H:i:s')]
            );
        }
    }

    public function payEnabled(): bool
    {
        $r = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_pay_enabled'");
        return ($r['setting_value'] ?? '0') === '1';
    }

    /**
     * Pembayaran yang dicatat di sistem SETELAH fitur kirim pembayaran aktif, untuk booking yang tertaut ke
     * Cloudbeds (atau dikirim di sinkron yang sama). Pembayaran OTA otomatis (ota_…) tidak dikirim.
     */
    private function planPayments(array &$actions): void
    {
        $sinceRow = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_pay_since'");
        $since = (string)($sinceRow['setting_value'] ?? '');
        if ($since === '') return;
        $pending = [];
        foreach ($actions as $a) {
            if ($a['type'] === 'push_create') $pending[(int)$a['booking_id']] = true;
        }
        $rows = $this->db->fetchAll(
            "SELECT bp.id, bp.booking_id, bp.amount, bp.payment_method, bp.notes, b.booking_code, g.guest_name,
                    (SELECT l.cb_reservation_id FROM cloudbeds_booking_links l WHERE l.booking_id = bp.booking_id LIMIT 1) cb_id
             FROM booking_payments bp
             JOIN bookings b ON b.id = bp.booking_id
             LEFT JOIN guests g ON g.id = b.guest_id
             LEFT JOIN cloudbeds_payment_links pl ON pl.payment_id = bp.id
             WHERE pl.payment_id IS NULL AND bp.amount > 0
               AND COALESCE(bp.created_at, bp.payment_date) >= ?
             ORDER BY bp.id",
            [$since]
        ) ?: [];
        foreach ($rows as $p) {
            if (empty($p['cb_id']) && !isset($pending[(int)$p['booking_id']])) continue;
            $actions[] = ['type' => 'push_payment', 'cb' => (string)($p['cb_id'] ?: '-'), 'booking_id' => (int)$p['booking_id'], 'payment_id' => (int)$p['id'],
                'amount' => (float)$p['amount'], 'method' => (string)$p['payment_method'], 'code' => (string)$p['booking_code'],
                'label' => ($p['guest_name'] ?: 'Guest') . ' (' . $p['booking_code'] . ')',
                'msg' => 'Kirim pembayaran ke Cloudbeds: Rp ' . number_format((float)$p['amount'], 0, ',', '.') . ' · ' . $p['payment_method']];
        }
    }

    /** Metode pembayaran Cloudbeds yang paling cocok untuk metode di sistem (cash, transfer, card, qris, …). */
    private function cbPaymentType(string $local): string
    {
        static $methods = null;
        if ($methods === null) {
            $methods = [];
            $r = $this->cb->get('getPaymentMethods', $this->cb->propertyId() !== '' ? ['propertyID' => $this->cb->propertyId()] : []);
            $walk = function ($d) use (&$walk, &$methods) {
                if (!is_array($d)) return;
                $code = $d['method'] ?? $d['type'] ?? $d['code'] ?? null;
                if ($code !== null && !is_array($code)) {
                    $methods[] = ['code' => (string)$code, 'name' => strtolower((string)($d['name'] ?? $code))];
                    return;
                }
                foreach ($d as $v) $walk($v);
            };
            $walk($r['data'] ?? []);
        }
        $l = strtolower($local);
        $want = strpos($l, 'ota') === 0 ? ['channel', 'ota', 'prepaid', 'virtual', 'collect', 'transfer', 'bank', 'other']
            : (in_array($l, ['cash'], true) ? ['cash']
            : (in_array($l, ['transfer', 'bank_transfer'], true) ? ['transfer', 'bank', 'ebanking', 'wire']
            : (in_array($l, ['card', 'debit', 'edc', 'credit_card'], true) ? ['debit', 'card', 'credit', 'edc']
            : (in_array($l, ['qris', 'qr'], true) ? ['qris', 'qr', 'transfer', 'bank'] : ['other', 'cash']))));
        foreach ($want as $w) {
            foreach ($methods as $m) {
                if (strpos(strtolower($m['code']), $w) !== false || strpos($m['name'], $w) !== false) return $m['code'];
            }
        }
        return $l === 'cash' || !$methods ? 'cash' : $methods[0]['code'];
    }

    /** Catat satu pembayaran ke folio reservasi Cloudbeds; false bila reservasi belum tertaut (dicoba lagi nanti). */
    private function pushPayment(array $a, bool $capAll = false): bool
    {
        $link = $this->db->fetchOne("SELECT cb_reservation_id FROM cloudbeds_booking_links WHERE booking_id = ? LIMIT 1", [$a['booking_id']]);
        $cbId = (string)($link['cb_reservation_id'] ?? '');
        if ($cbId === '') return false;
        if ($this->db->fetchOne("SELECT payment_id FROM cloudbeds_payment_links WHERE payment_id = ?", [$a['payment_id']])) return false;
        $amount = round($a['amount'], 2);
        // Pembayaran OTA (dibayar platform): kirim paling banyak sisa saldo Cloudbeds — bila Cloudbeds sudah lunas
        // (pembayaran OTA sudah tercatat di sana), cukup ditandai tanpa mengirim agar tidak dobel.
        if ($capAll || strpos(strtolower($a['method']), 'ota') === 0) {
            $det = $this->cb->reservationDetail($cbId);
            if (!$det['ok']) throw new \RuntimeException('saldo Cloudbeds tidak terbaca: ' . $det['detail']);
            if ($det['balance'] !== null) {
                if ((float)$det['balance'] < 1) {
                    $this->db->query("INSERT IGNORE INTO cloudbeds_payment_links (payment_id, cb_reservation_id, cb_payment_id) VALUES (?, ?, 'sudah-lunas')", [$a['payment_id'], $cbId]);
                    return false;
                }
                $amount = min($amount, round((float)$det['balance'], 2));
            }
        }
        $r = $this->cb->send('POST', 'postPayment', [
            'reservationID' => $cbId,
            'type' => $this->cbPaymentType($a['method']),
            'amount' => $amount,
            'description' => mb_substr('ADF ' . $a['code'] . ' · ' . $a['method'] . ' #' . $a['payment_id'], 0, 100),
        ]);
        if (!$r['ok']) {
            throw new \RuntimeException('Cloudbeds menolak pembayaran: ' . $r['detail'] . (in_array((int)$r['http'], [401, 403], true) ? ' (perlu scope "Payment: Write")' : ''));
        }
        $this->db->query("INSERT IGNORE INTO cloudbeds_payment_links (payment_id, cb_reservation_id, cb_payment_id) VALUES (?, ?, ?)",
            [$a['payment_id'], $cbId, (string)($r['raw']['paymentID'] ?? ($r['data']['paymentID'] ?? ''))]);
        return true;
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
        // Reservasi kiriman sistem yang masih "Not Confirmed" di Cloudbeds → dikonfirmasi
        if (in_array($local, ['confirmed', 'pending'], true) && $how === 'push' && $cb === 'not_confirmed') return ['confirmed'];
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
            "SELECT b.id, b.booking_code, b.group_id, b.status, b.booking_source, DATE(b.check_in_date) ci, DATE(b.check_out_date) co,
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
        // Booking grup (multi-kamar) dikirim sebagai SATU reservasi Cloudbeds berisi semua kamarnya
        $groups = [];
        foreach ($rows as $b) {
            if (isset($linkedBookingIds[(int)$b['id']])) continue;
            $isDirect = $b['source_type'] === 'direct' || ($b['source_type'] === null && in_array($b['booking_source'], ['walk_in', 'phone', 'online', 'direct', 'website', 'email'], true));
            if (!$isDirect) continue;
            $key = !empty($b['group_id']) ? 'g:' . $b['group_id'] . ':' . $b['ci'] . ':' . $b['co'] : 'b:' . $b['id'];
            $groups[$key][] = $b;
        }
        foreach ($groups as $members) {
            $b = $members[0];
            $codes = implode(', ', array_column($members, 'booking_code'));
            $label = ($b['guest_name'] ?: 'Guest') . ' · ' . $b['ci'] . ' → ' . $b['co'] . ' · ' . $b['booking_source'] . ' (' . $codes . ')';
            if ($cbRooms === null) $cbRooms = $this->cbRoomsByNo();
            $items = [];
            $missing = [];
            foreach ($members as $mb) {
                $no = preg_match('/\d{2,4}/', (string)$mb['room_number'], $m) ? $m[0] : (string)$mb['room_number'];
                $cr = $cbRooms[$no] ?? null;
                if (!$cr || $cr['type_id'] === '') { $missing[] = $mb['room_number']; continue; }
                $items[] = ['booking' => $mb, 'cb_room' => $cr];
            }
            if ($missing) {
                $actions[] = ['type' => 'warn', 'cb' => '-', 'label' => $label, 'msg' => 'Room ' . implode(', ', $missing) . ' tidak ditemukan di Cloudbeds — tidak dikirim.'];
                continue;
            }
            $roomsTxt = implode(', ', array_map(fn($x) => $x['booking']['room_number'], $items));
            $actions[] = ['type' => 'push_create', 'cb' => '-', 'label' => $label, 'booking_id' => (int)$b['id'], 'booking_ids' => array_map(fn($x) => (int)$x['booking']['id'], $items),
                'booking' => $b, 'cb_room' => $items[0]['cb_room'], 'items' => $items,
                'msg' => 'Kirim ke Cloudbeds: reservasi baru ' . (count($items) > 1 ? count($items) . ' kamar (' . $roomsTxt . ')' : 'Room ' . $roomsTxt) . ($b['status'] === 'checked_in' ? ' (lalu status checked-in)' : '')];
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
        // Satu atau beberapa kamar (booking grup) dalam satu reservasi Cloudbeds
        $items = $a['items'] ?? [['booking' => $b, 'cb_room' => $cr]];
        $perType = [];
        foreach ($items as $it) {
            $tid = $it['cb_room']['type_id'];
            $perType[$tid] = $perType[$tid] ?? ['rooms' => 0, 'adults' => 0, 'children' => 0];
            $perType[$tid]['rooms']++;
            $perType[$tid]['adults'] += max(1, (int)$it['booking']['adults']);
            $perType[$tid]['children'] += max(0, (int)$it['booking']['children']);
        }
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
            'rooms' => array_map(fn($t, $v) => ['roomTypeID' => $t, 'quantity' => $v['rooms']], array_keys($perType), $perType),
            'adults' => array_map(fn($t, $v) => ['roomTypeID' => $t, 'quantity' => $v['adults']], array_keys($perType), $perType),
            'children' => array_map(fn($t, $v) => ['roomTypeID' => $t, 'quantity' => $v['children']], array_keys($perType), $perType),
        ];
        $r = $this->cb->send('POST', 'postReservation', $params);
        // Tamu yang sudah menginap sejak kemarin: Cloudbeds bisa menolak tanggal mulai di masa lalu → mulai hari ini
        $today = date('Y-m-d');
        if (!$r['ok'] && $b['ci'] < $today && $b['co'] > $today) {
            $r = $this->cb->send('POST', 'postReservation', ['startDate' => $today] + $params);
        }
        if (!$r['ok']) {
            throw new \RuntimeException('Cloudbeds menolak reservasi: ' . $r['detail']);
        }
        $resId = (string)($r['raw']['reservationID'] ?? ($r['data']['reservationID'] ?? ''));
        if ($resId === '') {
            throw new \RuntimeException('Reservasi dibuat tetapi ID tidak terbaca dari jawaban Cloudbeds');
        }
        // Tautkan dulu (reservasi sudah ada di Cloudbeds), baru tempatkan kamar
        foreach ($items as $it) {
            $bid = (int)$it['booking']['id'];
            $this->db->query("INSERT IGNORE INTO cloudbeds_booking_links (cb_reservation_id, booking_id, how) VALUES (?, ?, 'push')", [$resId, $bid]);
            // Cloudbeds memakai harga rate plan-nya; harga sistem disamakan lewat antrean edit (adjustment)
            $this->db->query("INSERT IGNORE INTO cloudbeds_pending_edits (booking_id) VALUES (?)", [$bid]);
        }
        foreach ($items as $it) {
            $as = $this->cb->send('POST', 'postRoomAssign', ['reservationID' => $resId, 'newRoomID' => $it['cb_room']['room_id'], 'roomTypeID' => $it['cb_room']['type_id']]);
            $this->db->query("UPDATE bookings SET notes = TRIM(CONCAT(COALESCE(notes,''), ?)) WHERE id = ?", [
                "\n[Dikirim ke Cloudbeds #" . $resId . ($as['ok'] ? '' : ' — kamar belum ditempatkan: ' . mb_substr($as['detail'], 0, 120)) . ']', (int)$it['booking']['id'],
            ]);
        }
        // Reservasi lewat API masuk "Not Confirmed" → langsung dikonfirmasi (booking di sistem sudah pasti)
        $this->cb->send('PUT', 'putReservation', ['reservationID' => $resId, 'status' => 'confirmed']);
        if (array_filter($items, fn($it) => $it['booking']['status'] === 'checked_in')) {
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
        $adfBlocks = [];
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
            if ($this->learnedBlockType === '' && !empty($b['roomBlockType']) && !is_array($b['roomBlockType'])) $this->learnedBlockType = (string)$b['roomBlockType'];
            $roomIds = [];
            foreach ((array)($b['rooms'] ?? []) as $r) {
                if (is_array($r) && isset($r['roomID'])) $roomIds[] = (string)$r['roomID'];
                elseif (!is_array($r)) $roomIds[] = (string)$r;
            }
            if (stripos($reason, 'ADF:') === 0) {
                // Blok kiriman sistem: bila di sistem sudah dibatalkan (bukan dicabut oleh sinkron) → hapus juga di Cloudbeds
                $adfRemoved = [];
                $adfLocalIds = [];
                foreach ($roomIds as $rid) {
                    $code = substr('CB-' . $bid . '-' . $rid, 0, 40);
                    $seen[$code] = true;
                    $adfBlocks[] = ['bid' => $bid, 'rid' => $rid, 'start' => $start, 'end' => $end, 'rooms' => $roomIds, 'reason' => $reason, 'type' => (string)($b['roomBlockType'] ?? '')];
                    if ($push && !$this->db->fetchOne("SELECT id FROM room_blocks WHERE block_code = ? AND status = 'active' LIMIT 1", [$code])) {
                        $gone = $this->db->fetchOne("SELECT id FROM room_blocks WHERE block_code = ? AND status = 'cancelled' AND COALESCE(notes,'') NOT LIKE '%[Dicabut via Cloudbeds]%' LIMIT 1", [$code]);
                        if ($gone) { $adfRemoved[] = $rid; $adfLocalIds[] = (int)$gone['id']; }
                    }
                }
                if ($adfRemoved) {
                    $blkLabel = 'Blok sistem · ' . $start . ' → ' . $end;
                    $roomsTxt = implode(', ', array_map(fn($x) => $cbRoomName[$x] ?? $x, $adfRemoved));
                    if (count($adfRemoved) >= count($roomIds)) {
                        $actions[] = ['type' => 'push_delblock', 'cb' => $bid, 'label' => $blkLabel, 'start' => $start, 'end' => $end, 'block_ids' => $adfLocalIds, 'msg' => 'Hapus blok di Cloudbeds (dibatalkan di sistem: ' . $roomsTxt . ')'];
                    } else {
                        $actions[] = ['type' => 'push_putblock', 'cb' => $bid, 'label' => $blkLabel, 'start' => $start, 'end' => $end, 'reason' => $reason, 'block_type' => (string)($b['roomBlockType'] ?? ''),
                            'rooms' => array_values(array_diff($roomIds, $adfRemoved)), 'block_ids' => $adfLocalIds, 'msg' => 'Keluarkan ' . $roomsTxt . ' dari blok di Cloudbeds'];
                    }
                }
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
                    "SELECT b.id, b.booking_code, b.status, b.booking_source, DATE(b.check_in_date) ci, DATE(b.check_out_date) co, b.adults, b.children, b.notes,
                            g.guest_name, g.phone, g.email, g.nationality, r.room_number
                     FROM bookings b LEFT JOIN guests g ON g.id = b.guest_id LEFT JOIN rooms r ON r.id = b.room_id
                     WHERE b.room_id = ? AND b.status IN ('pending','confirmed','checked_in')
                     AND b.check_in_date < ? AND b.check_out_date > ? LIMIT 1",
                    [(int)$lr['id'], $end, $start]
                );
                // Blok Cloudbeds yang menahan kamar untuk tamu yang sudah ada di sistem (nama blok = nama tamu):
                // bila booking itu belum punya reservasi Cloudbeds → blok diganti reservasi (kirim aktif); lainnya sudah terwakili
                if ($conf && $reason !== '' && array_intersect(self::nameKey($reason), self::nameKey((string)$conf['guest_name']))) {
                    $linkedConf = $this->db->fetchOne("SELECT booking_id FROM cloudbeds_booking_links WHERE booking_id = ? LIMIT 1", [(int)$conf['id']]);
                    if ($push && !$linkedConf && $conf['co'] >= date('Y-m-d')) {
                        $crConv = $this->cbRoomsByNo()[$no] ?? null;
                        if ($crConv && $crConv['type_id'] !== '') {
                            $actions[] = ['type' => 'push_convert_block', 'cb' => $bid, 'label' => $label, 'booking_id' => (int)$conf['id'], 'booking' => $conf, 'cb_room' => $crConv, 'rid' => $rid,
                                'block' => ['start' => $start, 'end' => $end, 'reason' => $reason, 'rooms' => $roomIds, 'type' => (string)($b['roomBlockType'] ?? '')],
                                'msg' => 'Ganti blok Cloudbeds Room ' . $lr['room_number'] . ' dengan reservasi ' . $conf['booking_code'] . ($conf['status'] === 'checked_in' ? ' (lalu check-in)' : '')];
                        }
                    }
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
                    $actions[] = ['type' => 'push_delblock', 'cb' => $bid, 'label' => $blkLabel, 'start' => $start, 'end' => $end, 'msg' => 'Hapus blok di Cloudbeds (dibatalkan di sistem: ' . $roomsTxt . ')'];
                } else {
                    $actions[] = ['type' => 'push_putblock', 'cb' => $bid, 'label' => $blkLabel, 'start' => $start, 'end' => $end, 'reason' => $reason, 'block_type' => (string)($b['roomBlockType'] ?? ''),
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

        // Blok yang dikirim dari sistem tetapi ID Cloudbeds-nya tidak terbaca (kode ADFCB-)
        $noId = $this->db->fetchAll(
            "SELECT rb.id, rb.block_start_date s, rb.block_end_date e, r.room_number
             FROM room_blocks rb JOIN rooms r ON r.id = rb.room_id
             WHERE rb.status = 'active' AND rb.block_code LIKE 'ADFCB-%' AND rb.block_start_date <= ? AND rb.block_end_date > ?",
            [$to, $from]
        ) ?: [];
        if ($noId) {
            $ridByNo = [];
            foreach ($cbRoomName as $rid => $nm) {
                if (preg_match('/\d{2,4}/', $nm, $mm)) $ridByNo[$mm[0]] = (string)$rid;
            }
            foreach ($noId as $lb) {
                $no = preg_match('/\d{2,4}/', (string)$lb['room_number'], $mm) ? $mm[0] : (string)$lb['room_number'];
                $rid = $ridByNo[$no] ?? '';
                $match = null;
                foreach ($adfBlocks as $ab) {
                    if ($ab['rid'] === $rid && $ab['start'] === $lb['s'] && $ab['end'] === $lb['e']) { $match = $ab; break; }
                }
                $lbl = 'Blok sistem Room ' . $lb['room_number'] . ' · ' . $lb['s'] . ' → ' . $lb['e'];
                if ($match) {
                    $actions[] = ['type' => 'adopt_block', 'cb' => $match['bid'], 'label' => $lbl, 'block_id' => (int)$lb['id'], 'code' => substr('CB-' . $match['bid'] . '-' . $rid, 0, 40), 'msg' => 'Pasangkan dengan blok Cloudbeds #' . $match['bid']];
                } elseif ($rid !== '') {
                    $actions[] = ['type' => 'unblock', 'cb' => '-', 'label' => $lbl, 'block_id' => (int)$lb['id'], 'msg' => 'Sudah dihapus di Cloudbeds — blok dicabut'];
                }
            }
        }

        // Blok sistem ber-kode ADFCB- (ID Cloudbeds tak terbaca) yang dibatalkan di sistem: cari pasangannya di Cloudbeds
        // (kamar + tanggal sama, alasan "ADF:") lalu hapus di sana juga
        if ($push && $adfBlocks) {
            $gone = $this->db->fetchAll(
                "SELECT rb.id, rb.block_start_date s, rb.block_end_date e, r.room_number
                 FROM room_blocks rb JOIN rooms r ON r.id = rb.room_id
                 WHERE rb.status = 'cancelled' AND rb.block_code LIKE 'ADFCB-%' AND COALESCE(rb.notes,'') NOT LIKE '%[Dicabut via Cloudbeds]%'
                   AND rb.block_end_date > CURDATE() AND rb.block_start_date <= ? AND rb.block_end_date > ?",
                [$to, $from]
            ) ?: [];
            $ridByNo2 = [];
            foreach ($cbRoomName as $rid => $nm) {
                if (preg_match('/\d{2,4}/', $nm, $mm)) $ridByNo2[$mm[0]] = (string)$rid;
            }
            foreach ($gone as $lb) {
                $no = preg_match('/\d{2,4}/', (string)$lb['room_number'], $mm) ? $mm[0] : (string)$lb['room_number'];
                $rid = $ridByNo2[$no] ?? '';
                foreach ($adfBlocks as $ab) {
                    if ($rid === '' || $ab['rid'] !== $rid || $ab['start'] !== $lb['s'] || $ab['end'] !== $lb['e'] || stripos($ab['reason'], 'ADF: perpanjangan') === 0) continue;
                    $lbl = 'Blok sistem Room ' . $lb['room_number'] . ' · ' . $lb['s'] . ' → ' . $lb['e'];
                    if (count($ab['rooms']) <= 1) {
                        $actions[] = ['type' => 'push_delblock', 'cb' => $ab['bid'], 'label' => $lbl, 'start' => $ab['start'], 'end' => $ab['end'], 'block_ids' => [(int)$lb['id']], 'msg' => 'Hapus blok di Cloudbeds (dibatalkan di sistem)'];
                    } else {
                        $actions[] = ['type' => 'push_putblock', 'cb' => $ab['bid'], 'label' => $lbl, 'start' => $ab['start'], 'end' => $ab['end'], 'reason' => $ab['reason'], 'block_type' => $ab['type'],
                            'rooms' => array_values(array_diff($ab['rooms'], [$rid])), 'block_ids' => [(int)$lb['id']], 'msg' => 'Keluarkan Room ' . $lb['room_number'] . ' dari blok di Cloudbeds'];
                    }
                    break;
                }
            }
        }
    }

    /** Jalankan aksi rencana (link/create/cancel/blok/kirim). Peringatan dilewati. */
    private function executeActions(array $actions, int $userId): array
    {
        $done = ['link' => 0, 'create' => 0, 'price' => 0, 'cancel' => 0, 'block' => 0, 'unblock' => 0, 'push_status' => 0, 'push_create' => 0, 'push_block' => 0, 'push_pay' => 0, 'errors' => []];
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
                } elseif ($a['type'] === 'price') {
                    $this->applyCloudbedsPrice($a['cb'], $a['booking_ids'], $a['cb_total']);
                    $done['price']++;
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
                } elseif ($a['type'] === 'push_payment') {
                    if ($this->pushPayment($a)) $done['push_pay']++;
                } elseif ($a['type'] === 'push_delblock') {
                    $r = $this->deleteCbBlock($a['cb'], $a['start'] ?? null, $a['end'] ?? null);
                    if (!$r['ok']) throw new \RuntimeException('Cloudbeds menolak hapus blok: ' . $r['detail'] . (in_array($r['http'], [401, 403], true) ? ' (centang scope "Roomblock: Delete" di API key)' : ''));
                    $done['push_block']++;
                } elseif ($a['type'] === 'push_putblock') {
                    $r = $this->sendBlock('PUT', 'putRoomBlock', ['roomBlockID' => $a['cb'], 'startDate' => $a['start'], 'endDate' => $a['end'], 'roomBlockReason' => $a['reason'], 'rooms' => array_map(fn($x) => ['roomID' => $x], $a['rooms'])], $a['block_type'] ?? '');
                    if (!$r['ok']) throw new \RuntimeException('Cloudbeds menolak ubah blok: ' . $r['detail']);
                    $done['push_block']++;
                } elseif ($a['type'] === 'push_newblock') {
                    $r = $this->sendBlock('POST', 'postRoomBlock', ['startDate' => $a['start'], 'endDate' => $a['end'], 'roomBlockReason' => $a['reason'], 'rooms' => [['roomID' => $a['cb_room']['room_id']]]], '');
                    if (!$r['ok']) throw new \RuntimeException('Cloudbeds menolak blok baru: ' . $r['detail']);
                    $newId = self::findValue($r['raw'], 'roomblockid');
                    // Kode CB- agar sinkron masuk mengenali blok ini sebagai pasangan; bila ID tidak terbaca, ADFCB- agar tidak dikirim ulang
                    $this->db->query("UPDATE room_blocks SET block_code = ? WHERE id = ?", [$newId !== '' ? substr('CB-' . $newId . '-' . $a['cb_room']['room_id'], 0, 40) : 'ADFCB-' . $a['block_id'], $a['block_id']]);
                    $done['push_block']++;
                } elseif ($a['type'] === 'push_convert_block') {
                    $this->convertBlockToReservation($a);
                    $done['push_create']++;
                } elseif ($a['type'] === 'push_create') {
                    $this->pushCreate($a);
                    $done['push_create']++;
                } elseif ($a['type'] === 'adopt_block') {
                    $this->db->query("UPDATE room_blocks SET block_code = ? WHERE id = ? AND block_code LIKE 'ADFCB-%'", [$a['code'], $a['block_id']]);
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
        if (!$this->pushEnabled() && !$this->payEnabled()) {
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
        $this->pushOnlyPlan = true;
        try {
            $plan = $this->plan($from, $to);
        } finally {
            $this->pushOnlyPlan = false;
        }
        if (!$plan['ok']) {
            return ['ok' => false, 'detail' => $plan['detail']];
        }
        $mine = array_values(array_filter($plan['actions'], function ($a) use ($bookingIds, $blockIds, $cbIds, $cbBlockIds) {
            switch ($a['type']) {
                case 'push_create': return (bool)array_intersect($a['booking_ids'] ?? [(int)$a['booking_id']], $bookingIds);
                case 'push_convert_block':
                case 'push_payment': return in_array((int)$a['booking_id'], $bookingIds, true);
                case 'push_status': return isset($cbIds[(string)$a['cb']]);
                case 'push_newblock': return in_array((int)$a['block_id'], $blockIds, true);
                case 'push_delblock':
                case 'push_putblock': return isset($cbBlockIds[(string)$a['cb']]) || array_intersect($a['block_ids'] ?? [], $blockIds);
            }
            return false;
        }));
        $done = $mine ? $this->executeActions($mine, $userId) : ['errors' => []];
        if ($this->pushEnabled() && $bookingIds) {
            $this->queueEndedExtensions($bookingIds);
            $ed = $this->processEdits($bookingIds, 20);
            $done['push_edit'] = $ed['done'];
            $done['errors'] = array_merge($done['errors'], $ed['errors']);
        }
        if (!$mine && empty($done['push_edit'])) {
            return ['ok' => true, 'skipped' => 'no_push_action'];
        }
        if ($done['errors']) {
            error_log('Cloudbeds pushFor: ' . implode(' | ', $done['errors']));
        }
        $this->rememberPushResult($done);
        return ['ok' => !$done['errors'], 'done' => $done];
    }
    /**
     * Cek kenapa pembayaran sebuah booking belum sama di Cloudbeds (hanya membaca, tidak mengirim apa pun).
     * @return array{ok:bool, msg?:string, booking?:array, rooms?:array, link?:?array, payments?:array, cb?:?array, issues?:array, pay_enabled?:bool, pay_since?:string}
     */
    public function diagnosePayment(string $code): array
    {
        $this->ensureTables();
        $bk = $this->db->fetchOne("SELECT b.id, b.booking_code, b.group_id, b.status, b.booking_source, b.final_price, b.paid_amount, b.payment_status, g.guest_name
            FROM bookings b LEFT JOIN guests g ON g.id = b.guest_id WHERE b.booking_code = ? LIMIT 1", [$code]);
        if (!$bk) return ['ok' => false, 'msg' => 'Kode booking "' . $code . '" tidak ditemukan.'];
        $rooms = $bk['group_id']
            ? ($this->db->fetchAll("SELECT b.id, b.booking_code, b.final_price, b.paid_amount, b.status, r.room_number FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.group_id = ? AND b.status <> 'cancelled'", [$bk['group_id']]) ?: [])
            : ($this->db->fetchAll("SELECT b.id, b.booking_code, b.final_price, b.paid_amount, b.status, r.room_number FROM bookings b LEFT JOIN rooms r ON r.id = b.room_id WHERE b.id = ?", [$bk['id']]) ?: []);
        $ids = array_map(fn($r) => (int)$r['id'], $rooms) ?: [(int)$bk['id']];
        $in = implode(',', $ids);
        $link = $this->db->fetchOne("SELECT cb_reservation_id, how FROM cloudbeds_booking_links WHERE booking_id IN ($in) LIMIT 1");
        $sinceRow = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_pay_since'");
        $since = (string)($sinceRow['setting_value'] ?? '');
        $payEnabled = $this->payEnabled();
        $payments = $this->db->fetchAll("SELECT bp.id, bp.booking_id, bp.amount, bp.payment_method, COALESCE(bp.created_at, bp.payment_date) at, pl.cb_payment_id, pl.cb_reservation_id pl_cb
            FROM booking_payments bp LEFT JOIN cloudbeds_payment_links pl ON pl.payment_id = bp.id
            WHERE bp.booking_id IN ($in) ORDER BY bp.id") ?: [];
        foreach ($payments as &$p) {
            if ($p['pl_cb'] !== null) {
                $p['state'] = $p['cb_payment_id'] === 'sudah-lunas' ? 'skip_paid' : 'sent';
            } elseif ($since === '' || (string)$p['at'] < $since) {
                $p['state'] = 'before';
            } else {
                $p['state'] = 'pending';
            }
        }
        unset($p);
        $localTotal = array_sum(array_map(fn($r) => (float)$r['final_price'], $rooms));
        $localPaid = array_sum(array_map(fn($p) => (float)$p['amount'], $payments));

        $cbInfo = null;
        $issues = [];
        if (!$payEnabled) $issues[] = ['bad', 'Saklar "Kirim pembayaran" MATI — pembayaran tidak dikirim ke Cloudbeds. Nyalakan di bagian Sinkron.'];
        if (!$link) {
            $issues[] = ['bad', 'Booking ini belum tertaut ke reservasi Cloudbeds, jadi pembayarannya tidak punya tujuan. Jalankan sinkron (untuk OTA) atau pastikan "Kirim ke Cloudbeds" aktif (untuk booking sistem).'];
        } else {
            $det = $this->cb->reservationDetail((string)$link['cb_reservation_id']);
            if ($det['ok']) {
                $raw = is_array($det['raw']) ? $det['raw'] : [];
                $bd = is_array($raw['balanceDetailed'] ?? null) ? $raw['balanceDetailed'] : [];
                $cbInfo = ['id' => (string)$link['cb_reservation_id'], 'status' => (string)($raw['status'] ?? ''), 'total' => $det['total'], 'balance' => $det['balance'],
                    'paid' => isset($bd['paid']) && is_numeric($bd['paid']) ? (float)$bd['paid'] : null,
                    'tax' => isset($bd['taxesFees']) && is_numeric($bd['taxesFees']) ? (float)$bd['taxesFees'] : null];
                if ($det['total'] !== null && abs((float)$det['total'] - $localTotal) >= 1) {
                    $issues[] = ['warn', 'Total di Cloudbeds (Rp ' . number_format((float)$det['total'], 0, ',', '.') . ') berbeda dengan total di sistem (Rp ' . number_format($localTotal, 0, ',', '.') . ')' .
                        ($cbInfo['tax'] ? ', termasuk pajak/biaya Rp ' . number_format($cbInfo['tax'], 0, ',', '.') : '') . '. Walau semua pembayaran sistem terkirim, Cloudbeds tetap menunjukkan sisa saldo (dot merah) sebesar selisih ini.'];
                }
            } else {
                $issues[] = ['bad', 'Reservasi Cloudbeds #' . $link['cb_reservation_id'] . ' tidak terbaca: ' . $det['detail']];
            }
        }
        // Booking tertulis lunas/DP tanpa catatan pembayaran (dulu: akun pusat ditolak FK saat bayar)
        $markedPaid = array_sum(array_map(fn($r) => (float)$r['paid_amount'], $rooms));
        $ghostPaid = $markedPaid - $localPaid >= 1;
        if ($ghostPaid) $issues[] = ['bad', 'Booking tertulis sudah dibayar Rp ' . number_format($markedPaid, 0, ',', '.') . ', tetapi catatan pembayarannya hanya Rp ' . number_format($localPaid, 0, ',', '.') . ' — pembayaran dulu gagal tersimpan, jadi tidak masuk buku kas maupun Cloudbeds. Klik "Perbaiki status bayar", lalu lakukan Payment ulang.'];
        // Terlanjur dibayar dengan harga lama (sebelum disamakan dengan Cloudbeds) → bisa disamakan otomatis
        $canAlign = $cbInfo && $cbInfo['total'] !== null && abs((float)$cbInfo['total'] - $localTotal) >= 1
            && count($payments) === 1 && abs((float)$payments[0]['amount'] - $localTotal) < 1;
        if ($canAlign) $issues[] = ['bad', 'Booking ini dibayar dengan harga lama sistem (Rp ' . number_format($localTotal, 0, ',', '.') . '), bukan harga Cloudbeds (Rp ' . number_format((float)$cbInfo['total'], 0, ',', '.') . '). Klik "Samakan dengan Cloudbeds" — harga, pembayaran & buku kas ikut disamakan.'];
        $pending = array_filter($payments, fn($p) => $p['state'] === 'pending');
        $before = array_filter($payments, fn($p) => $p['state'] === 'before');
        if ($pending) $issues[] = ['bad', count($pending) . ' pembayaran belum terkirim. Klik "Kirim ulang sekarang"; bila gagal, pesan penolakan Cloudbeds tampil di bawah.'];
        if ($before) $issues[] = ['warn', count($before) . ' pembayaran dicatat sebelum "Kirim pembayaran" dinyalakan (' . ($since ?: 'belum pernah') . '), jadi tidak dikirim otomatis. Klik "Kirim juga pembayaran lama booking ini" (dibatasi sisa saldo Cloudbeds, tidak dobel).'];
        if (!$payments) $issues[] = ['warn', 'Belum ada pembayaran tercatat di sistem untuk booking ini.'];
        if (!$issues) $issues[] = ['ok', 'Semua pembayaran sudah terkirim dan total sama. Bila Cloudbeds masih merah, muat ulang halaman Cloudbeds.'];
        return ['ok' => true, 'booking' => $bk, 'rooms' => $rooms, 'link' => $link ?: null, 'payments' => $payments, 'cb' => $cbInfo,
            'issues' => $issues, 'ghost_paid' => $ghostPaid, 'can_align' => $canAlign, 'marked_paid' => $markedPaid, 'pay_enabled' => $payEnabled, 'pay_since' => $since, 'local_total' => $localTotal, 'local_paid' => $localPaid, 'ids' => $ids];
    }

    /**
     * Kirim pembayaran LAMA (dicatat sebelum "Kirim pembayaran" aktif) untuk booking tertentu — dipicu manual dari
     * alat Cek pembayaran. Setiap pembayaran dibatasi sisa saldo Cloudbeds; bila Cloudbeds sudah lunas (mis. sudah
     * diketik manual di sana) hanya ditandai, tidak dikirim, jadi tidak dobel.
     * @return array{sent:int, skipped:int, errors:array}
     */
    public function pushOldPayments(array $bookingIds): array
    {
        $this->ensureTables();
        $ids = array_values(array_filter(array_map('intval', $bookingIds)));
        $out = ['sent' => 0, 'skipped' => 0, 'errors' => []];
        if (!$ids) return $out;
        $in = implode(',', $ids);
        $rows = $this->db->fetchAll("SELECT bp.id, bp.booking_id, bp.amount, bp.payment_method, b.booking_code FROM booking_payments bp JOIN bookings b ON b.id = bp.booking_id
            LEFT JOIN cloudbeds_payment_links pl ON pl.payment_id = bp.id WHERE pl.payment_id IS NULL AND bp.amount > 0 AND bp.booking_id IN ($in) ORDER BY bp.id") ?: [];
        foreach ($rows as $p) {
            try {
                $ok = $this->pushPayment(['booking_id' => (int)$p['booking_id'], 'payment_id' => (int)$p['id'], 'amount' => (float)$p['amount'], 'method' => (string)$p['payment_method'], 'code' => (string)$p['booking_code']], true);
                $ok ? $out['sent']++ : $out['skipped']++;
            } catch (\Throwable $e) {
                $out['errors'][] = $p['booking_code'] . ': ' . $e->getMessage();
            }
        }
        return $out;
    }

    /**
     * Ambil total Cloudbeds SEKARANG untuk booking yang belum ada pembayaran (dipanggil tepat sebelum Payment),
     * supaya nominal yang dibayar & dicatat di buku kas sama dengan Cloudbeds tanpa menunggu sinkron berkala.
     * @return array{old:float,new:float}|null  null bila tidak tertaut / sudah ada pembayaran / sudah sama / gagal baca
     */
    public function refreshPriceFromCloudbeds(int $bookingId): ?array
    {
        $this->ensureTables();
        $link = $this->db->fetchOne("SELECT cb_reservation_id FROM cloudbeds_booking_links WHERE booking_id = ? LIMIT 1", [$bookingId]);
        if (!$link) return null;
        $cbId = (string)$link['cb_reservation_id'];
        $ids = array_map(fn($r) => (int)$r['booking_id'], $this->db->fetchAll("SELECT l.booking_id FROM cloudbeds_booking_links l JOIN bookings b ON b.id = l.booking_id
            WHERE l.cb_reservation_id = ? AND b.status IN ('confirmed','pending','checked_in')", [$cbId]) ?: []);
        if (!$ids) return null;
        $in = implode(',', $ids);
        if ($this->db->fetchOne("SELECT id FROM booking_payments WHERE booking_id IN ($in) AND amount > 0 LIMIT 1")) return null;
        if ($this->db->fetchOne("SELECT booking_id FROM cloudbeds_pending_edits WHERE booking_id IN ($in) LIMIT 1")) return null;
        $old = (float)($this->db->fetchOne("SELECT COALESCE(SUM(final_price), 0) s FROM bookings WHERE id IN ($in)")['s'] ?? 0);
        $det = $this->cb->reservationDetail($cbId);
        if (!$det['ok'] || $det['total'] === null || (float)$det['total'] <= 0) return null;
        $new = round((float)$det['total'], 2);
        if (abs($new - $old) < 1) return null;
        $this->applyCloudbedsPrice($cbId, $ids, $new);
        return ['old' => $old, 'new' => $new];
    }

    /**
     * Booking OTA yang terlanjur dibayar dengan harga lama (sebelum disamakan dengan Cloudbeds): harga, pembayaran
     * dan baris buku kasnya disamakan dengan total Cloudbeds. Hanya bila ada tepat SATU pembayaran sebesar total lama.
     * @return array{ok:bool,msg:string}
     */
    public function alignPaidToCloudbeds(string $code): array
    {
        $d = $this->diagnosePayment($code);
        if (empty($d['ok'])) return ['ok' => false, 'msg' => $d['msg'] ?? 'Booking tidak ditemukan.'];
        if (!$d['cb'] || $d['cb']['total'] === null) return ['ok' => false, 'msg' => 'Total Cloudbeds tidak terbaca.'];
        $cbTotal = round((float)$d['cb']['total'], 2);
        $pays = $d['payments'];
        if (count($pays) !== 1 || abs((float)$pays[0]['amount'] - $d['local_total']) >= 1) {
            return ['ok' => false, 'msg' => 'Hanya bisa otomatis bila ada tepat satu pembayaran sebesar total booking. Ubah manual di buku kas.'];
        }
        if (abs($cbTotal - $d['local_total']) < 1) return ['ok' => false, 'msg' => 'Total sudah sama dengan Cloudbeds.'];
        $p = $pays[0];
        $conn = $this->db->getConnection();
        $conn->beginTransaction();
        try {
            // Harga booking (grup dibagi sesuai porsi lama)
            $rows = $this->db->fetchAll("SELECT id, final_price, COALESCE(discount, 0) discount, total_nights FROM bookings WHERE id IN (" . implode(',', $d['ids']) . ")") ?: [];
            $old = array_sum(array_map(fn($r) => (float)$r['final_price'], $rows));
            $left = $cbTotal;
            foreach (array_values($rows) as $i => $r) {
                $share = $i === count($rows) - 1 ? $left : round($cbTotal * ($old > 0 ? (float)$r['final_price'] / $old : 1 / count($rows)), 2);
                $left -= $share;
                $gross = $share + (float)$r['discount'];
                $this->db->query("UPDATE bookings SET final_price = ?, total_price = ?, room_price = ?, updated_at = NOW() WHERE id = ?", [$share, $gross, round($gross / max(1, (int)$r['total_nights']), 2), (int)$r['id']]);
            }
            $this->db->query("UPDATE booking_payments SET amount = ? WHERE id = ?", [$cbTotal, (int)$p['id']]);
            $cbk = $this->db->fetchOne("SELECT cashbook_id FROM booking_payments WHERE id = ?", [(int)$p['id']]);
            $cashUpdated = false;
            if (!empty($cbk['cashbook_id'])) {
                $cashUpdated = (bool)$this->db->query("UPDATE cash_book SET amount = ? WHERE id = ?", [$cbTotal, (int)$cbk['cashbook_id']]);
            } else {
                // Tanpa tautan: baris pemasukan buku kas booking ini sebesar total lama (satu-satunya)
                $cands = $this->db->fetchAll("SELECT id FROM cash_book WHERE transaction_type = 'income' AND ABS(amount - ?) < 1 AND (booking_id = ? OR description LIKE ?)",
                    [(float)$p['amount'], (int)$p['booking_id'], '%' . $d['booking']['booking_code'] . '%']) ?: [];
                if (count($cands) === 1) {
                    $cashUpdated = (bool)$this->db->query("UPDATE cash_book SET amount = ? WHERE id = ?", [$cbTotal, (int)$cands[0]['id']]);
                    $this->db->query("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE id = ?", [(int)$cands[0]['id'], (int)$p['id']]);
                }
            }
            $this->resetPaidFromPayments($d['ids']);
            $this->db->query("INSERT INTO cloudbeds_price_sync (cb_reservation_id, synced_total) VALUES (?, ?) ON DUPLICATE KEY UPDATE synced_total = VALUES(synced_total)", [$d['cb']['id'], $cbTotal]);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            return ['ok' => false, 'msg' => 'Gagal: ' . $e->getMessage()];
        }
        return ['ok' => true, 'msg' => 'Harga & pembayaran disamakan dengan Cloudbeds: Rp ' . number_format($cbTotal, 0, ',', '.') . ($cashUpdated ? ' (buku kas ikut diperbarui)' : ' — baris buku kas tidak tertaut, ubah manual di Buku Kas')];
    }

    /** Samakan paid_amount / payment_status booking dengan catatan pembayaran (booking_payments) yang benar-benar ada. */
    public function resetPaidFromPayments(array $bookingIds): int
    {
        $n = 0;
        foreach (array_filter(array_map('intval', $bookingIds)) as $id) {
            $b = $this->db->fetchOne("SELECT final_price FROM bookings WHERE id = ?", [$id]);
            if (!$b) continue;
            $paid = (float)($this->db->fetchOne("SELECT COALESCE(SUM(amount), 0) s FROM booking_payments WHERE booking_id = ?", [$id])['s'] ?? 0);
            $st = $paid <= 0 ? 'unpaid' : ($paid + 0.01 >= (float)$b['final_price'] ? 'paid' : 'partial');
            $this->db->query("UPDATE bookings SET paid_amount = ?, payment_status = ?, updated_at = NOW() WHERE id = ?", [$paid, $st, $id]);
            $n++;
        }
        return $n;
    }

    /** Peringatan ("perlu dicek") & error dari hasil apply(), ringkas untuk disimpan bersama status sinkron. */
    public static function issueList(array $res, int $max = 8): array
    {
        $warns = [];
        foreach ((array)($res['actions'] ?? []) as $a) {
            if (($a['type'] ?? '') === 'warn' && count($warns) < $max) {
                $warns[] = ['label' => mb_substr((string)$a['label'], 0, 120), 'msg' => mb_substr((string)$a['msg'], 0, 200)];
            }
        }
        $errors = array_map(fn($e) => mb_substr((string)$e, 0, 200), array_slice((array)($res['done']['errors'] ?? []), 0, 3));
        return ['warns' => $warns, 'errors' => $errors];
    }

    /** Hitung ulang rencana lalu jalankan link / create / cancel / block / unblock. Peringatan tidak dieksekusi. */
    public function apply(string $from, string $to, int $userId): array
    {
        $plan = $this->plan($from, $to);
        if (!$plan['ok']) {
            return $plan + ['done' => []];
        }
        $done = $this->executeActions($plan['actions'], $userId);
        if ($this->pushEnabled()) {
            $this->queueEndedExtensions();
            $ed = $this->processEdits([], 20);
            $done['push_edit'] = $ed['done'];
            $done['errors'] = array_merge($done['errors'], $ed['errors']);
        }
        $this->rememberPushResult($done);
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
