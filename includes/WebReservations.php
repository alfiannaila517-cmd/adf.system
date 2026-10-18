<?php

/**
 * Reservasi dari website (narayanakarimunjawa.com).
 *
 * Alur: tamu memilih tanggal & kamar di website → website menyimpan sebuah PENAWARAN (tabel quotations,
 * source='website', tidak memblok kamar) → ADF mengirim notifikasi ke admin (WhatsApp + email kantor) dan
 * menampilkan popup di Front Desk → admin follow-up via WhatsApp, mencatat DP → Konfirmasi (jadi booking
 * lewat form reservasi biasa) → tamu otomatis menerima email "reservasi berhasil" berisi rincian & pembayaran.
 *
 * Website memanggil api/web-reservation-notify.php dengan tanda tangan HMAC (rahasia di tabel settings,
 * dibaca website dari database yang sama), jadi tidak perlu menyimpan kunci di file.
 */
require_once __DIR__ . '/Quotations.php';

class WebReservations
{
    public const DP_PERCENT = 50;

    /** Rahasia bersama untuk tanda tangan website → ADF (dibuat otomatis sekali). */
    public static function secret($db): string
    {
        $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'web_reservation_secret'");
        $val = trim((string)($row['setting_value'] ?? ''));
        if ($val === '') {
            $val = bin2hex(random_bytes(24));
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('web_reservation_secret', ?)", [$val]);
        }
        return $val;
    }

    public static function verify($db, int $id, string $sig): bool
    {
        return $id > 0 && $sig !== '' && hash_equals(hash_hmac('sha256', 'webres:' . $id, self::secret($db)), $sig);
    }

    public static function dpAmount(float $total): float
    {
        return round($total * self::DP_PERCENT / 100, -3); // dibulatkan ke ribuan
    }

    private static function rp($n): string
    {
        return 'Rp ' . number_format((float)$n, 0, ',', '.');
    }

    private static function d($date): string
    {
        $t = strtotime((string)$date);
        return $t ? date('d M Y', $t) : '-';
    }

    private static function hotelName($db): string
    {
        $r = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
        return trim((string)($r['setting_value'] ?? '')) ?: 'Narayana Hotel Karimunjawa';
    }

    /** Ringkasan kamar: "2× Deluxe King (101, 102)". */
    private static function roomsSummary(array $q): string
    {
        $byType = [];
        foreach ($q['items'] ?? [] as $it) {
            $byType[$it['room_type'] ?: 'Room'][] = $it['room_number'];
        }
        $parts = [];
        foreach ($byType as $type => $nums) {
            $parts[] = count($nums) . '× ' . $type . ' (' . implode(', ', $nums) . ')';
        }
        return implode(', ', $parts);
    }

    /** Kirim email lewat SMTP "Email Kantor". Mengembalikan null bila berhasil, atau pesan galat. */
    private static function mail($db, string $to, string $subject, string $html): ?string
    {
        require_once __DIR__ . '/EmailHelper.php';
        require_once __DIR__ . '/SmtpMailer.php';
        $cfg = EmailHelper::resolveConfig($db);
        if ($cfg === null) {
            return 'Pengaturan Email Kantor belum diisi';
        }
        try {
            $mailer = new SmtpMailer($cfg['smtp_host'] ?? $cfg['host'], (int)($cfg['smtp_port'] ?? 465), $cfg['smtp_encryption'] ?? 'ssl', $cfg['user'], $cfg['pass']);
            $mailer->send($to, $subject, $html, self::hotelName($db));
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    private static function officeEmail($db): string
    {
        require_once __DIR__ . '/EmailHelper.php';
        $r = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_email'");
        $mail = trim((string)($r['setting_value'] ?? ''));
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            $cfg = EmailHelper::resolveConfig($db);
            $mail = (string)($cfg['user'] ?? '');
        }
        return filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : '';
    }

    /**
     * Notifikasi ke admin untuk reservasi website baru (WhatsApp ke nomor laporan + email kantor). Sekali saja per reservasi.
     * @return array{wa:int,email:bool,skipped?:bool}
     */
    public static function notifyNew($db, int $id): array
    {
        $q = Quotations::get($db, $id);
        if (!$q || ($q['source'] ?? '') !== 'website') {
            return ['wa' => 0, 'email' => false, 'skipped' => true];
        }
        if (!empty($q['notified_at'])) {
            return ['wa' => 0, 'email' => false, 'skipped' => true];
        }
        // tandai dulu supaya panggilan ganda tidak mengirim dua kali
        $db->query("UPDATE quotations SET notified_at = NOW() WHERE id = ? AND notified_at IS NULL", [$id]);

        $dp = self::dpAmount((float)$q['total']);
        $pax = (int)($q['adults'] ?? 0) . ' adult(s)' . ((int)($q['children'] ?? 0) > 0 ? ', ' . (int)$q['children'] . ' child(ren)' : '');
        $msg = "🛎️ *Reservasi baru dari website*\n"
            . "Kode: *{$q['quote_code']}*\n"
            . "Tamu: {$q['guest_name']}\n"
            . "WA: {$q['guest_phone']}\n"
            . ($q['guest_email'] ? "Email: {$q['guest_email']}\n" : '')
            . "Tanggal: " . self::d($q['check_in']) . " → " . self::d($q['check_out']) . " ({$q['nights']} malam)\n"
            . "Kamar: " . self::roomsSummary($q) . "\n"
            . "Tamu: {$pax}\n"
            . "Estimasi: " . self::rp($q['total']) . " · DP 50%: " . self::rp($dp) . "\n"
            . ($q['notes'] ? "Catatan: {$q['notes']}\n" : '')
            . "\nKamar BELUM dikunci. Follow-up tamu via WhatsApp, terima DP, lalu Konfirmasi di ADF (Reservasi → Penawaran).";

        $sentWa = 0;
        try {
            require_once __DIR__ . '/WhatsAppHelper.php';
            $wa = new WhatsAppHelper($db);
            if ($wa->isConfigured()) {
                foreach ($wa->reportTargets() as $t) {
                    $r = $wa->send($t, $msg, null, null, 'web_reservation', (string)$q['quote_code']);
                    if (!empty($r['ok'])) $sentWa++;
                }
            }
        } catch (\Throwable $e) {
            error_log('WebReservations WA: ' . $e->getMessage());
        }

        $emailOk = false;
        $office = self::officeEmail($db);
        if ($office !== '') {
            $html = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:560px;color:#1f2937">'
                . '<h2 style="margin:0 0 10px;font-size:18px">Reservasi baru dari website</h2>'
                . '<p style="margin:0 0 14px;color:#64748b">Kamar belum dikunci. Follow-up tamu via WhatsApp, terima DP, lalu Konfirmasi di ADF System.</p>'
                . '<table style="width:100%;border-collapse:collapse;font-size:14px">'
                . self::row('Kode', $q['quote_code']) . self::row('Tamu', $q['guest_name']) . self::row('WhatsApp', $q['guest_phone']) . self::row('Email', $q['guest_email'] ?: '-')
                . self::row('Tanggal', self::d($q['check_in']) . ' → ' . self::d($q['check_out']) . ' (' . (int)$q['nights'] . ' malam)')
                . self::row('Kamar', self::roomsSummary($q)) . self::row('Jumlah tamu', $pax)
                . self::row('Estimasi total', self::rp($q['total'])) . self::row('DP 50%', self::rp($dp)) . self::row('Catatan', $q['notes'] ?: '-')
                . '</table></div>';
            $emailOk = self::mail($db, $office, 'Reservasi website baru ' . $q['quote_code'] . ' — ' . $q['guest_name'], $html) === null;
        }
        return ['wa' => $sentWa, 'email' => $emailOk];
    }

    private static function row(string $k, $v): string
    {
        return '<tr><td style="padding:6px 0;color:#64748b;width:38%;vertical-align:top">' . htmlspecialchars($k) . '</td><td style="padding:6px 0;font-weight:600">' . htmlspecialchars((string)$v) . '</td></tr>';
    }

    /**
     * Email ke tamu setelah admin mengonfirmasi: reservasi berhasil + rincian pembayaran (invoice ringkas).
     * @return string|null null bila terkirim / tidak perlu, atau pesan galat
     */
    public static function sendConfirmed($db, int $id, string $bookingRef = ''): ?string
    {
        $q = Quotations::get($db, $id);
        if (!$q) return 'Penawaran tidak ditemukan';
        $to = trim((string)$q['guest_email']);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 'Email tamu kosong';
        $hotel = self::hotelName($db);
        $paid = (float)($q['dp_amount'] ?? 0);
        $total = (float)$q['total'];
        $balance = max(0, $total - $paid);
        $c = [];
        foreach ($db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_phone','company_address','company_email')") ?: [] as $r) $c[$r['setting_key']] = $r['setting_value'];

        $rows = '';
        foreach ($q['items'] as $it) {
            $rows .= '<tr><td style="padding:9px 10px;border-bottom:1px solid #eee">' . htmlspecialchars(($it['room_type'] ?: 'Room') . ' — Room ' . $it['room_number']) . '<div style="font-size:12px;color:#888">' . (int)$q['nights'] . ' night(s) × ' . self::rp($it['net_price']) . '</div></td><td style="padding:9px 10px;border-bottom:1px solid #eee;text-align:right;white-space:nowrap">' . self::rp($it['line_total']) . '</td></tr>';
        }
        foreach ($q['extras'] ?? [] as $x) {
            $rows .= '<tr><td style="padding:9px 10px;border-bottom:1px solid #eee">' . htmlspecialchars($x['item_name']) . ' × ' . (float)$x['qty'] . '</td><td style="padding:9px 10px;border-bottom:1px solid #eee;text-align:right">' . self::rp($x['line_total']) . '</td></tr>';
        }
        $html = '<div style="font-family:Georgia,\'Times New Roman\',serif;max-width:600px;margin:0 auto;color:#1f1b16;background:#fbf8f3">'
            . '<div style="background:#14110d;padding:26px 30px;border-bottom:3px solid #c9a548"><div style="color:#e5ad3e;font-size:12px;letter-spacing:4px;text-transform:uppercase;font-family:Arial,sans-serif">Reservation Confirmed</div><div style="color:#fff;font-size:24px;margin-top:6px">' . htmlspecialchars($hotel) . '</div></div>'
            . '<div style="padding:26px 30px;font-family:Arial,sans-serif;font-size:14px;line-height:1.6">'
            . '<p style="margin:0 0 14px">Dear <b>' . htmlspecialchars($q['guest_name']) . '</b>,</p>'
            . '<p style="margin:0 0 18px">Thank you — your reservation is <b>confirmed</b>. We look forward to welcoming you to Karimunjawa.</p>'
            . '<table style="width:100%;border-collapse:collapse;margin-bottom:16px">'
            . self::row('Reservation no.', $bookingRef !== '' ? $bookingRef : $q['quote_code'])
            . self::row('Check-in', self::d($q['check_in']) . ' (from 14:00)') . self::row('Check-out', self::d($q['check_out']) . ' (until 12:00)')
            . self::row('Guests', (int)($q['adults'] ?? 0) . ' adult(s)' . ((int)($q['children'] ?? 0) > 0 ? ', ' . (int)$q['children'] . ' child(ren)' : ''))
            . '</table>'
            . '<table style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #eee">' . $rows
            . '<tr><td style="padding:10px;font-weight:bold">Total</td><td style="padding:10px;text-align:right;font-weight:bold">' . self::rp($total) . '</td></tr>'
            . '<tr><td style="padding:6px 10px;color:#15803d">Deposit received</td><td style="padding:6px 10px;text-align:right;color:#15803d">' . self::rp($paid) . '</td></tr>'
            . '<tr><td style="padding:10px;background:#14110d;color:#fff;font-weight:bold">Balance due at the hotel</td><td style="padding:10px;background:#14110d;color:#e5ad3e;text-align:right;font-weight:bold">' . self::rp($balance) . '</td></tr>'
            . '</table>'
            . '<p style="margin:18px 0 0;color:#5b534a">Questions? Reply to this email or WhatsApp us at ' . htmlspecialchars((string)($c['company_phone'] ?? '')) . '.</p>'
            . '<p style="margin:14px 0 0;color:#8a8178;font-size:12px">' . htmlspecialchars($hotel) . ' · ' . htmlspecialchars((string)($c['company_address'] ?? 'Karimunjawa, Jepara')) . '</p>'
            . '</div></div>';
        return self::mail($db, $to, 'Reservation confirmed — ' . $hotel . ' (' . ($bookingRef !== '' ? $bookingRef : $q['quote_code']) . ')', $html);
    }
}
