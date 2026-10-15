<?php

/**
 * WhatsApp gateway (Fonnte) — kirim teks / file PDF, cek perangkat, daftar grup, dan log pengiriman.
 *
 * Pengaturan disimpan di tabel settings bisnis aktif:
 *   wa_token             token perangkat Fonnte (rahasia; tidak pernah dikirim ke browser)
 *   wa_report_targets    tujuan laporan harian: nomor dan/atau ID grup (…@g.us), satu per baris
 *   wa_checkin_enabled   '1' = kirim pesan otomatis ke tamu saat check-in
 *   wa_checkin_template  isi pesan check-in (placeholder {guest_name}, {room}, …)
 *
 * Sengaja satu kelas: bila nanti pindah ke WAHA / API resmi Meta, cukup ganti isi request().
 */
require_once __DIR__ . '/GuestServices.php';

class WhatsAppHelper
{
    public const DEFAULT_CHECKIN_TEMPLATE = "Hello {guest_name}, welcome to {hotel_name}! 🌴\n"
        . "Room {room} is ready for you.\n\n"
        . "📶 WiFi · 🏝️ Trips · 🏍️ Motorbike & 🚗 Car rental — all here:\n{services_link}\n\n"
        . "Need anything? Just reply here 🙏\n— Front Office";

    /** Bawaan untuk tamu berbahasa Indonesia (dipakai bila template belum diubah di pengaturan). */
    public const DEFAULT_CHECKIN_TEMPLATE_ID = "Halo {guest_name}, selamat datang di {hotel_name}! 🌴\n"
        . "Kamar {room} sudah siap untuk Anda.\n\n"
        . "📶 WiFi · 🏝️ Trip · 🏍️ Sewa motor & 🚗 mobil — semua ada di sini:\n{services_link}\n\n"
        . "Butuh bantuan? Balas pesan ini ya 🙏\n— Front Office";

    public const CHECKIN_PLACEHOLDERS = ['{guest_name}', '{hotel_name}', '{room}', '{room_type}', '{check_in}', '{check_out}', '{nights}', '{booking_code}', '{services_link}'];

    private $db;
    private $settings = null;
    private $baseUrl;

    public function __construct($db)
    {
        $this->db = $db;
        // Override hanya untuk pengujian lokal (server tiruan); produksi selalu api.fonnte.com.
        $this->baseUrl = rtrim(getenv('WA_FONNTE_BASE') ?: 'https://api.fonnte.com', '/');
    }

    /* ---------------- Pengaturan ---------------- */

    public function settings(): array
    {
        if ($this->settings === null) {
            $this->settings = ['wa_token' => '', 'wa_report_targets' => '', 'wa_checkin_enabled' => '0', 'wa_checkin_template' => ''];
            try {
                $rows = $this->db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('wa_token','wa_report_targets','wa_checkin_enabled','wa_checkin_template')") ?: [];
                foreach ($rows as $r) {
                    $this->settings[$r['setting_key']] = (string)$r['setting_value'];
                }
            } catch (\Throwable $e) {
            }
        }
        return $this->settings;
    }

    public function saveSetting(string $key, string $value): void
    {
        if ($key === 'wa_token') {
            $value = trim($value, " \t\n\r\0\x0B\"'");
        }
        $exists = $this->db->fetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
        if ($exists) {
            $this->db->query("UPDATE settings SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
        } else {
            $this->db->query("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)", [$key, $value]);
        }
        $this->settings = null;
    }

    public function isConfigured(): bool
    {
        return trim($this->settings()['wa_token']) !== '';
    }

    /** Tujuan laporan: daftar nomor / ID grup yang sudah dinormalisasi. */
    public function reportTargets(): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,;]+/', $this->settings()['wa_report_targets']) as $t) {
            $t = self::normalizeTarget($t);
            if ($t !== '') $out[$t] = true;
        }
        return array_map('strval', array_keys($out)); // kunci numerik -> tetap string
    }

    public function maskedToken(): string
    {
        $t = trim($this->settings()['wa_token']);
        return $t === '' ? '' : str_repeat('•', 8) . substr($t, -4);
    }

    /* ---------------- Format ---------------- */

    /** 0812… / +62 812… / 812… -> 62812…; ID grup (…@g.us) dibiarkan. */
    public static function normalizeTarget(string $t): string
    {
        $t = trim($t);
        if ($t === '') return '';
        if (stripos($t, '@g.us') !== false) return preg_replace('/\s+/', '', $t);
        $d = preg_replace('/\D+/', '', $t);
        if ($d === '') return '';
        if (strpos($d, '0') === 0) $d = '62' . substr($d, 1);
        elseif (strpos($d, '8') === 0) $d = '62' . $d;
        return strlen($d) >= 9 ? $d : '';
    }

    public static function render(string $template, array $vars): string
    {
        return strtr($template, $vars);
    }

    /* ---------------- API Fonnte ---------------- */

    private function request(string $path, array $fields, int $timeout = 25, ?string $tokenOverride = null): array
    {
        // Token: buang spasi / baris baru / tanda kutip yang ikut tersalin dari dashboard.
        $token = trim($tokenOverride ?? $this->settings()['wa_token'], " \t\n\r\0\x0B\"'");
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'detail' => 'Ekstensi PHP cURL tidak aktif di server (aktifkan di cPanel → Select PHP Version → Extensions)'];
        }
        if ($token === '') {
            return ['ok' => false, 'detail' => 'Token WhatsApp belum diisi di Pengaturan WhatsApp'];
        }
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'detail' => 'Server tidak bisa menghubungi api.fonnte.com: ' . $err . ' — kemungkinan koneksi keluar diblokir hosting'];
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'detail' => 'Respons gateway tidak dikenal (HTTP ' . $code . '): ' . mb_substr(trim(strip_tags((string)$body)), 0, 120)];
        }
        $ok = !empty($json['status']);
        $detail = $ok ? (string)($json['detail'] ?? 'OK') : (string)($json['reason'] ?? $json['detail'] ?? 'Gagal');
        return ['ok' => $ok, 'detail' => $detail, 'data' => $json];
    }

    /**
     * Kirim pesan (opsional dengan file) ke satu tujuan dan catat di log.
     * @param string $type report | checkin | test
     */
    public function send(string $target, string $message, ?string $filePath = null, ?string $fileName = null, string $type = 'test', string $ref = '', ?string $fileUrl = null): array
    {
        $target = self::normalizeTarget($target);
        if ($target === '') {
            $res = ['ok' => false, 'detail' => 'Nomor / grup tujuan tidak valid'];
            $this->log($type, '', $ref, 'failed', $res['detail']);
            return $res;
        }
        // normalizeTarget sudah menghasilkan format internasional (0812… → 62812…, +31 6… → 316…).
        // Nomor Indonesia tetap pakai countryCode '62' (perilaku lama yang terbukti jalan); nomor luar
        // negeri pakai '0' agar filter kode negara Fonnte tidak memperlakukannya sebagai nomor Indonesia.
        $isIndo = strpos($target, '62') === 0 || stripos($target, '@g.us') !== false;
        $fields = ['target' => $target, 'message' => $message, 'countryCode' => $isIndo ? '62' : '0'];
        if ($fileUrl !== null) {
            // Lampiran lewat link publik: Fonnte mengunduh file sendiri (didukung lebih banyak paket
            // daripada unggah langsung, yang pada sebagian paket diabaikan diam-diam).
            $fields['url'] = $fileUrl;
            $fields['filename'] = $fileName ?: basename(parse_url($fileUrl, PHP_URL_PATH));
        } elseif ($filePath !== null) {
            $fields['file'] = new \CURLFile($filePath, 'application/pdf', $fileName ?: basename($filePath));
            $fields['filename'] = $fileName ?: basename($filePath);
        }
        $res = $this->request('/send', $fields, ($filePath !== null || $fileUrl !== null) ? 60 : 25);
        $this->log($type, $target, $ref, $res['ok'] ? 'sent' : 'failed', $res['detail']);
        return $res;
    }

    /** Status perangkat; $token = uji token yang belum disimpan. 'connected' sudah dinormalisasi. */
    /**
     * Simpan file sementara di uploads/wa-tmp dengan nama acak (tidak bisa ditebak) agar bisa diunduh
     * gateway / dibuka dari link di pesan; file lebih dari 2 hari dihapus otomatis agar hosting tidak penuh.
     * @return array{path:string,url:string}
     */
    public static function publishTempFile(string $bytes, string $ext = 'pdf', string $prefix = ''): array
    {
        $dir = BASE_PATH . '/uploads/wa-tmp';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        // Folder tidak boleh bisa dijelajah; file hanya bisa dibuka lewat nama acaknya.
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Options -Indexes
");
        if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
        foreach (glob($dir . '/*.' . $ext) ?: [] as $old) {
            if (filemtime($old) < time() - 2 * 86400) @unlink($old);
        }
        $prefix = trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower($prefix)), '-');
        $name = ($prefix !== '' ? $prefix . '-' : '') . bin2hex(random_bytes(12)) . '.' . $ext;
        if (file_put_contents($dir . '/' . $name, $bytes) === false) {
            throw new \RuntimeException('Tidak bisa menulis file sementara di uploads/wa-tmp');
        }
        return ['path' => $dir . '/' . $name, 'url' => rtrim(BASE_URL, '/') . '/uploads/wa-tmp/' . $name];
    }

    public function deviceStatus(?string $token = null): array
    {
        $r = $this->request('/device', [], 25, $token !== null && trim($token) !== '' ? $token : null);
        $state = strtolower(trim((string)($r['data']['device_status'] ?? '')));
        $r['connected'] = $r['ok'] && in_array($state, ['connect', 'connected', 'online', 'open', 'ready'], true);
        $r['state'] = $state;
        return $r;
    }

    /** Daftar grup WhatsApp pada perangkat (refresh = minta Fonnte memperbarui dulu). */
    public function groups(bool $refresh = true): array
    {
        if ($refresh) {
            $this->request('/fetch-group', []);
        }
        $res = $this->request('/get-whatsapp-group', []);
        if (!$res['ok']) return $res;
        $list = [];
        foreach (($res['data']['data'] ?? []) as $g) {
            if (!empty($g['id'])) $list[] = ['id' => (string)$g['id'], 'name' => (string)($g['name'] ?? $g['id'])];
        }
        return ['ok' => true, 'detail' => count($list) . ' grup', 'groups' => $list];
    }

    /* ---------------- Log ---------------- */

    private function ensureLogTable(): void
    {
        static $done = false;
        if ($done) return;
        $this->db->getConnection()->exec("CREATE TABLE IF NOT EXISTS wa_message_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(20) NOT NULL,
            target VARCHAR(100) NOT NULL DEFAULT '',
            ref VARCHAR(80) NOT NULL DEFAULT '',
            status VARCHAR(10) NOT NULL,
            detail VARCHAR(255) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_type_target (type, target),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Riwayat direset harian: hanya pengiriman hari ini yang disimpan agar tabel tidak menumpuk.
        try {
            $this->db->getConnection()->exec("DELETE FROM wa_message_log WHERE created_at < CURDATE()");
        } catch (\Throwable $e) {
        }
        $done = true;
    }

    public function log(string $type, string $target, string $ref, string $status, string $detail): void
    {
        try {
            $this->ensureLogTable();
            $this->db->getConnection()->prepare("INSERT INTO wa_message_log (type, target, ref, status, detail, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$type, $target, $ref, $status, mb_substr($detail, 0, 255), $_SESSION['user_id'] ?? null]);
        } catch (\Throwable $e) {
            error_log('WA log: ' . $e->getMessage());
        }
    }

    public function recentLog(int $limit = 20): array
    {
        try {
            $this->ensureLogTable();
            return $this->db->fetchAll("SELECT * FROM wa_message_log ORDER BY id DESC LIMIT " . (int)$limit) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Sudah ada pesan check-in terkirim ke nomor ini dalam N jam terakhir? (booking grup: 1 pesan saja) */
    public function recentlySent(string $type, string $target, int $hours = 12): bool
    {
        try {
            $this->ensureLogTable();
            return (bool)$this->db->fetchOne(
                "SELECT id FROM wa_message_log WHERE type = ? AND target = ? AND status = 'sent' AND created_at >= (NOW() - INTERVAL " . (int)$hours . " HOUR) LIMIT 1",
                [$type, self::normalizeTarget($target)]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* ---------------- Pesan check-in ---------------- */

    /**
     * Kirim pesan sambutan ke tamu setelah check-in (bila diaktifkan). Tidak pernah melempar exception.
     * @param array $booking baris booking + guest_name, guest_phone, room_number
     */
    public function sendCheckinMessage(array $booking): void
    {
        try {
            $s = $this->settings();
            if ($s['wa_checkin_enabled'] !== '1' || !$this->isConfigured()) return;

            $ref = 'booking:' . ($booking['id'] ?? '') . ' ' . ($booking['booking_code'] ?? '');
            $phone = self::normalizeTarget((string)($booking['guest_phone'] ?? ''));
            if ($phone === '') {
                $this->log('checkin', '', $ref, 'skipped', 'Tamu tidak punya nomor telepon');
                return;
            }
            if ($this->recentlySent('checkin', $phone)) {
                $this->log('checkin', $phone, $ref, 'skipped', 'Sudah dikirim (booking grup / check-in ulang)');
                return;
            }

            $message = $this->checkinMessage($booking);
            $this->send($phone, $message, null, null, 'checkin', $ref);
        } catch (\Throwable $e) {
            error_log('WA check-in message: ' . $e->getMessage());
        }
    }

    /** Isi pesan welcome check-in (template dari pengaturan WhatsApp) untuk satu booking. */
    public function checkinMessage(array $booking): string
    {
        $s = $this->settings();
        $type = '';
        try {
            $row = $this->db->fetchOne("SELECT rt.type_name FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id WHERE r.id = ?", [$booking['room_id'] ?? 0]);
            $type = (string)($row['type_name'] ?? '');
        } catch (\Throwable $e) {
        }
        $hotel = '';
        try {
            $row = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
            $hotel = (string)($row['setting_value'] ?? '');
        } catch (\Throwable $e) {
        }
        if ($hotel === '' && defined('BUSINESS_NAME')) $hotel = BUSINESS_NAME;

        $in = $booking['check_in_date'] ?? null;
        $out = $booking['check_out_date'] ?? null;
        $nights = ($in && $out) ? max(1, (int)round((strtotime($out) - strtotime($in)) / 86400)) : '';
        // Bahasa mengikuti nomor tamu (luar negeri → Inggris). Template kustom dipakai apa adanya untuk semua tamu.
        $nat = '';
        try {
            $g = $this->db->fetchOne("SELECT nationality FROM guests WHERE id = ?", [$booking['guest_id'] ?? 0]);
            $nat = (string)($g['nationality'] ?? '');
        } catch (\Throwable $e) {
        }
        $lang = GuestServices::langFor($nat, (string)($booking['guest_phone'] ?? ''));
        $template = trim($s['wa_checkin_template']) !== ''
            ? $s['wa_checkin_template']
            : ($lang === 'id' ? self::DEFAULT_CHECKIN_TEMPLATE_ID : self::DEFAULT_CHECKIN_TEMPLATE);
        $servicesLink = GuestServices::linkFor($this->db, $booking, $lang);
        if ($servicesLink === '') {
            // Fitur mati / gagal: buang seluruh paragraf yang memuat link agar tidak tersisa teks pengantar menggantung
            $paras = array_filter(explode("\n\n", $template), fn($p) => strpos($p, '{services_link}') === false);
            $template = implode("\n\n", $paras);
        } elseif (strpos($template, '{services_link}') === false) {
            $template = rtrim($template) . "\n\n" . ($lang === 'id' ? 'Layanan & penawaran untuk Anda:' : 'Services & offers for you:') . "\n{services_link}";
        }
        $message = self::render($template, [
            '{services_link}' => $servicesLink,
            '{guest_name}'   => trim((string)($booking['guest_name'] ?? 'Guest')),
            '{hotel_name}'   => $hotel,
            '{room}'         => (string)($booking['room_number'] ?? ''),
            '{room_type}'    => $type ?: '-',
            '{check_in}'     => $in ? date('d M Y', strtotime($in)) : '',
            '{check_out}'    => $out ? date('d M Y', strtotime($out)) : '',
            '{nights}'       => (string)$nights,
            '{booking_code}' => (string)($booking['booking_code'] ?? ''),
        ]);
        return $message;
    }
}
