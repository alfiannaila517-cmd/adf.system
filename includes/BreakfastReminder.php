<?php

/**
 * Pengingat memilih sarapan (WhatsApp) untuk tamu in-house yang BELUM memilih menu.
 * Dipakai oleh tombol "Ingatkan" di halaman Breakfast (api/breakfast-guest-portal.php) dan cron/breakfast-reminder.php.
 *
 * Aturan:
 *  - Hari sarapan D = tanggal hotel (sebelum jam 10:00 masih kemarin); sarapan D disajikan pagi D+1, batas pilih 05:00 D+1.
 *  - Sasaran: booking checked_in yang menginap sampai besok pagi (check-out > D) dan kamarnya belum punya pesanan tanggal D.
 *  - Memakai link yang SUDAH ada (link baru membuat link lama hangus). Tamu tanpa link / tanpa nomor tidak dikirim, hanya ditandai.
 *  - Satu tamu tidak menerima dua pengingat dalam semalam (dicek dari wa_message_log, tipe breakfast_reminder).
 */
require_once __DIR__ . '/WhatsAppHelper.php';

const BF_RM_TYPE = 'breakfast_reminder';
const BF_RM_AUTO_KEY = 'breakfast_reminder_auto';

/** Tanggal sarapan yang berlaku sekarang (sama dengan halaman Breakfast). */
function bf_rm_date(): string
{
    return (int)date('H') < 10 ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
}

/** URL dasar situs (CLI tidak mendefinisikan BASE_URL). */
function bf_rm_base_url(): string
{
    if (defined('BASE_URL') && BASE_URL !== '') {
        return rtrim((string)BASE_URL, '/');
    }
    return 'https://' . (defined('MASTER_DOMAIN') ? MASTER_DOMAIN : 'adfsystem.online');
}

function bf_rm_auto_enabled($db): bool
{
    try {
        $r = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [BF_RM_AUTO_KEY]);
        return ($r['setting_value'] ?? '0') === '1';
    } catch (\Throwable $e) {
        return false;
    }
}

function bf_rm_set_auto($db, bool $on): void
{
    $val = $on ? '1' : '0';
    if ($db->fetchOne("SELECT setting_key FROM settings WHERE setting_key = ?", [BF_RM_AUTO_KEY])) {
        $db->query("UPDATE settings SET setting_value = ? WHERE setting_key = ?", [$val, BF_RM_AUTO_KEY]);
    } else {
        $db->query("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)", [BF_RM_AUTO_KEY, $val]);
    }
}

/** Bahasa pesan: tamu Indonesia → id; lainnya → en (kebangsaan, atau awalan nomor 62 bila kebangsaan kosong). */
function bf_rm_lang(string $nationality, string $phone): string
{
    $n = strtolower(trim($nationality));
    if ($n !== '') {
        return (strpos($n, 'indonesia') !== false || $n === 'id' || $n === 'wni') ? 'id' : 'en';
    }
    return strpos(WhatsAppHelper::normalizeTarget($phone), '62') === 0 ? 'id' : 'en';
}

function bf_rm_hotel_name($db): string
{
    try {
        $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
        if (!empty($row['setting_value'])) return (string)$row['setting_value'];
    } catch (\Throwable $e) {
    }
    return defined('BUSINESS_NAME') ? (string)BUSINESS_NAME : 'Hotel';
}

/** Isi pesan pengingat — singkat, sopan, natural. */
function bf_rm_message(string $guestName, array $rooms, string $link, string $lang, string $hotel): string
{
    $name = trim($guestName);
    if ($lang === 'id') {
        return implode("\n", [
            'Selamat malam, ' . ($name !== '' ? $name : 'Bapak/Ibu') . '.',
            '',
            'Menu sarapan untuk besok pagi belum dipilih. Berkenan memilih sebelum pukul 05.00 melalui:',
            $link,
            '',
            'Jika belum sempat, Anda tetap bisa memesan langsung di restoran. Terima kasih.',
            $hotel,
        ]);
    }
    return implode("\n", [
        'Good evening, ' . ($name !== '' ? $name : 'Guest') . '.',
        '',
        'Your breakfast menu for tomorrow has not been selected yet. Please choose before 5:00 AM here:',
        $link,
        '',
        'If you miss it, you are welcome to order at the restaurant. Thank you.',
        $hotel,
    ]);
}

/**
 * Daftar sasaran pengingat untuk tanggal sarapan $date.
 * Tiap elemen: guest_id, guest_name, phone, rooms[], booking_ids[], lang, link, reminded, status
 * status: ready | no_phone | no_link | reminded
 * @param int[] $onlyBookingIds bila diisi, hanya grup yang memuat booking tersebut
 */
function bf_rm_targets($db, string $date, array $onlyBookingIds = []): array
{
    try {
        $rows = $db->fetchAll("
            SELECT MIN(g.id) AS guest_id,
                   GROUP_CONCAT(DISTINCT g.guest_name ORDER BY b.id SEPARATOR '||') AS guest_names,
                   COALESCE(MAX(NULLIF(g.phone, '')), '') AS phone,
                   COALESCE(MAX(NULLIF(g.nationality, '')), '') AS nationality,
                   GROUP_CONCAT(DISTINCT r.room_number ORDER BY r.room_number SEPARATOR ',') AS rooms,
                   GROUP_CONCAT(DISTINCT b.id ORDER BY b.id SEPARATOR ',') AS booking_ids
            FROM bookings b
            JOIN guests g ON b.guest_id = g.id
            JOIN rooms r ON b.room_id = r.id
            WHERE b.status = 'checked_in'
              AND DATE(b.check_out_date) > ?
              AND NOT EXISTS (
                  SELECT 1 FROM breakfast_orders bo
                  WHERE bo.breakfast_date = ? AND bo.room_number LIKE CONCAT('%\"', r.room_number, '\"%')
              )
            GROUP BY COALESCE(NULLIF(b.group_id, ''), CONCAT('G', g.id))
            ORDER BY MIN(r.room_number) ASC
        ", [$date, $date]) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    // Kamar yang sudah diingatkan malam ini (ref "bf-remind 201,204")
    $reminded = [];
    try {
        // Tabel log dibuat saat pesan WA pertama dikirim; sebelum itu belum ada yang diingatkan
        if (!$db->fetchOne("SHOW TABLES LIKE 'wa_message_log'")) throw new \RuntimeException('no log');
        foreach ($db->fetchAll("SELECT ref FROM wa_message_log WHERE type = ? AND status = 'sent' AND created_at >= (NOW() - INTERVAL 8 HOUR)", [BF_RM_TYPE]) ?: [] as $lr) {
            foreach (array_filter(array_map('trim', explode(',', preg_replace('/^bf-remind\s*/i', '', (string)$lr['ref'])))) as $rn) {
                $reminded[$rn] = true;
            }
        }
    } catch (\Throwable $e) {
    }

    $out = [];
    foreach ($rows as $r) {
        $bookingIds = array_values(array_filter(array_map('intval', explode(',', (string)$r['booking_ids']))));
        if ($onlyBookingIds && !array_intersect($bookingIds, $onlyBookingIds)) continue;
        $rooms = array_values(array_filter(array_map('trim', explode(',', (string)$r['rooms']))));
        $names = implode(', ', array_unique(array_filter(explode('||', (string)$r['guest_names']))));
        $phone = WhatsAppHelper::normalizeTarget((string)$r['phone']);

        $link = '';
        if ($bookingIds) {
            try {
                $ph = implode(',', array_fill(0, count($bookingIds), '?'));
                $lk = $db->fetchOne("SELECT short_code, token FROM breakfast_guest_links
                    WHERE breakfast_date = ? AND booking_id IN ($ph) AND parent_token IS NULL AND link_status <> 'expired' AND submitted_at IS NULL
                    ORDER BY id DESC LIMIT 1", array_merge([$date], $bookingIds));
                if ($lk) {
                    $link = !empty($lk['short_code'])
                        ? bf_rm_base_url() . '/go-breakfast.php?k=' . urlencode((string)$lk['short_code']) . '&np=1'
                        : bf_rm_base_url() . '/modules/frontdesk/breakfast-guest.php?t=' . urlencode((string)$lk['token']);
                }
            } catch (\Throwable $e) {
            }
        }

        $isReminded = false;
        foreach ($rooms as $rn) {
            if (isset($reminded[$rn])) $isReminded = true;
        }
        $status = $isReminded ? 'reminded' : ($phone === '' ? 'no_phone' : ($link === '' ? 'no_link' : 'ready'));

        $out[] = [
            'guest_id' => (int)$r['guest_id'],
            'guest_name' => $names,
            'phone' => $phone,
            'rooms' => $rooms,
            'booking_ids' => $bookingIds,
            'lang' => bf_rm_lang((string)$r['nationality'], (string)$r['phone']),
            'link' => $link,
            'reminded' => $isReminded,
            'status' => $status,
        ];
    }
    return $out;
}

/** Kirim satu pengingat. @return array{ok:bool, detail:string} */
function bf_rm_send_one(WhatsAppHelper $wa, $db, array $t): array
{
    if ($t['status'] !== 'ready') {
        return ['ok' => false, 'detail' => 'Dilewati: ' . $t['status']];
    }
    $msg = bf_rm_message($t['guest_name'], $t['rooms'], $t['link'], $t['lang'], bf_rm_hotel_name($db));
    $ref = mb_substr('bf-remind ' . implode(',', $t['rooms']), 0, 80);
    $res = $wa->send($t['phone'], $msg, null, null, BF_RM_TYPE, $ref);
    return ['ok' => (bool)$res['ok'], 'detail' => (string)$res['detail']];
}
