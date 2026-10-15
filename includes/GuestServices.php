<?php

/**
 * LAYANAN TAMU - link di pesan welcome check-in yang membuka halaman mobile (flyer trip + sewa motor).
 * Halaman hanya MENAWARKAN; semua tombol membuka WhatsApp ke Front Office (Front Desk yang membalas).
 *
 * Pengaturan (tabel settings, kunci gs_*):
 *   gs_enabled       '1' = link disertakan di pesan welcome
 *   gs_fo_wa         nomor WhatsApp Front Office (kosong = company_phone)
 *   gs_fo_hours      teks jam balas, mis. "07.00–22.00"
 *   gs_trip_title    judul bagian trip
 *   gs_trip_chips    chip singkat dipisah koma
 *   gs_flyers        JSON [{path,title}]
 *   gs_motor_enabled '1' = tab sewa motor tampil
 *   gs_motor_photo   path foto motor
 *   gs_motor_price   harga manual (kosong = harga terendah katalog hotel service bertipe motor)
 *   gs_motor_unit    satuan (default "hari")
 *   gs_motor_notes   syarat / info, satu per baris
 *   gs_custom        JSON layanan tambahan [{id,name,name_en,icon,enabled,price,unit,notes,notes_en}] (mis. Laundry); flyer-nya cat = x_<id>
 *   gs_flyers        JSON [{path,title,title_en,cat}] cat = trip | motor | taxi | x_<id> (bawaan trip)
 *   gs_wifi_ssid / gs_wifi_pass / gs_wifi_note  kartu WiFi (tampil bila SSID diisi; password kosong = jaringan terbuka)
 */
class GuestServices
{
    public const KEYS = ['gs_enabled', 'gs_fo_wa', 'gs_fo_hours', 'gs_trip_title', 'gs_trip_chips', 'gs_flyers',
        'gs_motor_enabled', 'gs_motor_photo', 'gs_motor_price', 'gs_motor_unit', 'gs_motor_notes', 'gs_wifi_ssid', 'gs_wifi_pass', 'gs_wifi_note',
        'gs_taxi_enabled', 'gs_taxi_price', 'gs_taxi_unit', 'gs_taxi_notes',
        'gs_trip_title_en', 'gs_trip_chips_en', 'gs_motor_notes_en', 'gs_taxi_notes_en', 'gs_custom'];

    public static function ensureTables($db): void
    {
        static $done = false;
        if ($done) return;
        $db->query("CREATE TABLE IF NOT EXISTS guest_service_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            short_code VARCHAR(12) NOT NULL,
            booking_id INT NULL,
            booking_code VARCHAR(40) NULL,
            guest_name VARCHAR(150) NULL,
            room_number VARCHAR(30) NULL,
            lang VARCHAR(2) NOT NULL DEFAULT 'id',
            opens INT NOT NULL DEFAULT 0,
            last_open DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_gs_code (short_code),
            KEY idx_gs_booking (booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS guest_service_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            link_id INT NOT NULL,
            event VARCHAR(24) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_gse_link (link_id),
            KEY idx_gse_event (event, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }

    /** Semua pengaturan dengan nilai bawaan. */
    public static function settings($db): array
    {
        $s = ['gs_enabled' => '0', 'gs_fo_wa' => '', 'gs_fo_hours' => '07.00–22.00', 'gs_trip_title' => '', 'gs_trip_chips' => '',
            'gs_flyers' => '[]', 'gs_motor_enabled' => '1', 'gs_motor_photo' => '', 'gs_motor_price' => '', 'gs_motor_unit' => '', 'gs_motor_notes' => '', 'gs_wifi_ssid' => '', 'gs_wifi_pass' => '', 'gs_wifi_note' => '',
            'gs_taxi_enabled' => '0', 'gs_taxi_price' => '', 'gs_taxi_unit' => '', 'gs_taxi_notes' => '',
            'gs_trip_title_en' => '', 'gs_trip_chips_en' => '', 'gs_motor_notes_en' => '', 'gs_taxi_notes_en' => '', 'gs_custom' => '[]'];
        try {
            $in = "'" . implode("','", self::KEYS) . "'";
            foreach ($db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($in)") ?: [] as $r) {
                $s[$r['setting_key']] = (string)$r['setting_value'];
            }
        } catch (\Throwable $e) {
        }
        return $s;
    }

    public static function save($db, string $key, string $value): void
    {
        if (!in_array($key, self::KEYS, true)) return;
        if ($db->fetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key])) {
            $db->query("UPDATE settings SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
        } else {
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)", [$key, $value]);
        }
    }

    public const FLYER_CATS = ['trip' => 'Trip', 'motor' => 'Sewa Motor', 'taxi' => 'Sewa Taxi'];

    /** @return array<int,array{path:string,title:string,cat:string}> */
    public static function flyers(array $s): array
    {
        $list = json_decode($s['gs_flyers'] ?? '[]', true);
        $out = [];
        foreach (is_array($list) ? $list : [] as $f) {
            $cat = (string)($f['cat'] ?? 'trip');
            if (!isset(self::FLYER_CATS[$cat]) && !preg_match('/^x_[a-z0-9]+$/', $cat)) continue; // kategori sudah dihapus (mis. sewa mobil)
            if (!empty($f['path'])) $out[] = ['path' => (string)$f['path'], 'title' => (string)($f['title'] ?? ''), 'title_en' => (string)($f['title_en'] ?? ''), 'cat' => (string)($f['cat'] ?? 'trip')];
        }
        return $out;
    }

    /** Ikon (emoji) yang boleh dipilih untuk layanan tambahan. */
    public const CUSTOM_ICONS = ['🧺', '🍽️', '💆', '🚤', '🎁', '🛎️', '🧴', '🏊', '🎣', '🛒', '⭐'];

    /** @return array<int,array{id:string,name:string,name_en:string,icon:string,enabled:string,price:string,unit:string,notes:string,notes_en:string}> */
    public static function customs(array $s): array
    {
        $out = [];
        $list = json_decode($s['gs_custom'] ?? '[]', true);
        foreach (is_array($list) ? $list : [] as $c) {
            if (empty($c['id']) || !preg_match('/^[a-z0-9]+$/', (string)$c['id'])) continue;
            $out[] = ['id' => (string)$c['id'], 'name' => (string)($c['name'] ?? ''), 'name_en' => (string)($c['name_en'] ?? ''),
                'icon' => in_array($c['icon'] ?? '', self::CUSTOM_ICONS, true) ? $c['icon'] : '⭐', 'enabled' => ($c['enabled'] ?? '1') === '1' ? '1' : '0',
                'price' => (string)($c['price'] ?? ''), 'unit' => (string)($c['unit'] ?? ''), 'notes' => (string)($c['notes'] ?? ''), 'notes_en' => (string)($c['notes_en'] ?? '')];
        }
        return $out;
    }

    /** Satuan harga mengikuti bahasa halaman (katalog biasanya berbahasa Indonesia: hari, jam, …). */
    public static function unitLabel(string $unit, string $lang): string
    {
        $u = strtolower(trim($unit));
        if ($u === '' || $u === 'unit') return '';
        $map = ['hari' => 'day', 'day' => 'day', 'jam' => 'hour', 'hour' => 'hour', 'malam' => 'night', 'night' => 'night', 'orang' => 'person', 'person' => 'person',
            'pax' => 'person', 'trip' => 'trip', 'kali' => 'trip', 'perjalanan' => 'trip', 'minggu' => 'week', 'week' => 'week'];
        $id = ['day' => 'hari', 'hour' => 'jam', 'night' => 'malam', 'person' => 'orang', 'trip' => 'trip', 'week' => 'minggu'];
        if (!isset($map[$u])) return trim($unit); // satuan bebas: tampilkan apa adanya
        return $lang === 'en' ? $map[$u] : $id[$map[$u]];
    }

    public static function baseUrl(): string
    {
        if (defined('BASE_URL') && BASE_URL !== '') return rtrim((string)BASE_URL, '/');
        return 'https://' . (defined('MASTER_DOMAIN') ? MASTER_DOMAIN : 'adfsystem.online');
    }

    public static function fileUrl(string $path): string
    {
        return strpos($path, 'http') === 0 ? $path : self::baseUrl() . '/' . ltrim($path, '/');
    }

    /** Nomor WhatsApp Front Office (hanya digit, format 62…). */
    public static function foNumber($db, array $s): string
    {
        $n = WhatsAppHelper::normalizeTarget($s['gs_fo_wa'] !== '' ? $s['gs_fo_wa'] : '');
        if ($n === '') {
            try {
                $n = WhatsAppHelper::normalizeTarget((string)($db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_phone'")['setting_value'] ?? ''));
            } catch (\Throwable $e) {
            }
        }
        return $n;
    }

    public static function motorPrice($db, array $s): array
    {
        return self::vehiclePrice($db, $s, 'motor', 'motor');
    }

    public static function taxiPrice($db, array $s): array
    {
        return self::vehiclePrice($db, $s, 'taxi', 'taxi');
    }

    /** Harga kendaraan: manual (gs_<prefix>_price) bila diisi, kalau tidak harga terendah katalog hotel service (tipe/nama memuat $word). */
    private static function vehiclePrice($db, array $s, string $prefix, string $word): array
    {
        $manual = (float)preg_replace('/[^\d]/', '', $s["gs_{$prefix}_price"]);
        $unitSet = $s["gs_{$prefix}_unit"];
        if ($manual > 0) return ['price' => $manual, 'unit' => $unitSet];
        try {
            $r = $db->fetchOne("SELECT default_price, unit FROM hotel_service_catalog
                WHERE is_active = 1 AND default_price > 0 AND (LOWER(item_name) LIKE ? OR LOWER(service_type) LIKE ?)
                ORDER BY default_price ASC LIMIT 1", ["%$word%", "%$word%"]);
            if ($r) return ['price' => (float)$r['default_price'], 'unit' => $unitSet !== '' ? $unitSet : (string)$r['unit']];
        } catch (\Throwable $e) {
        }
        return ['price' => 0.0, 'unit' => $unitSet];
    }

    /** Bahasa tamu: nomor luar negeri → en; nomor Indonesia → id kecuali kebangsaan jelas asing. */
    public static function langFor(string $nationality, string $phone): string
    {
        $t = WhatsAppHelper::normalizeTarget($phone);
        $n = strtolower(trim($nationality));
        $indo = $n === '' || strpos($n, 'indonesia') !== false || $n === 'id' || $n === 'wni';
        if ($t !== '') return strpos($t, '62') !== 0 ? 'en' : ($indo ? 'id' : 'en');
        return ($n !== '' && !$indo) ? 'en' : 'id';
    }

    /** Link layanan per booking (dipakai ulang bila sudah ada). Kosong bila fitur mati / gagal. */
    public static function linkFor($db, array $booking, string $lang): string
    {
        try {
            $s = self::settings($db);
            if ($s['gs_enabled'] !== '1') return '';
            self::ensureTables($db);
            $bid = (int)($booking['id'] ?? 0);
            $row = $bid ? $db->fetchOne("SELECT short_code FROM guest_service_links WHERE booking_id = ? ORDER BY id DESC LIMIT 1", [$bid]) : null;
            if ($row) {
                $db->query("UPDATE guest_service_links SET lang = ? WHERE short_code = ?", [$lang, $row['short_code']]);
                $code = $row['short_code'];
            } else {
                $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
                for ($try = 0; $try < 5; $try++) {
                    $code = '';
                    for ($i = 0; $i < 7; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                    if (!$db->fetchOne("SELECT id FROM guest_service_links WHERE short_code = ?", [$code])) break;
                }
                $db->query("INSERT INTO guest_service_links (short_code, booking_id, booking_code, guest_name, room_number, lang) VALUES (?, ?, ?, ?, ?, ?)",
                    [$code, $bid ?: null, (string)($booking['booking_code'] ?? ''), trim((string)($booking['guest_name'] ?? '')), (string)($booking['room_number'] ?? ''), $lang]);
            }
            return self::baseUrl() . '/guest-services.php?k=' . $code;
        } catch (\Throwable $e) {
            error_log('GuestServices link: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Simpan gambar unggahan (JPG/PNG/WEBP, maks 6 MB). Bila GD tersedia, sisi terpanjang diperkecil ke $maxSide
     * dan disimpan JPEG agar ringan di sinyal lemah. Mengembalikan path relatif (uploads/guest-services/…).
     * @throws \RuntimeException pesan siap tampil
     */
    public static function saveImage(array $file, int $maxSide = 1400): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new \RuntimeException('Unggah gagal, coba lagi.');
        if ((int)$file['size'] > 6 * 1024 * 1024) throw new \RuntimeException('Ukuran gambar maksimal 6 MB.');
        $info = @getimagesize($file['tmp_name']);
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (!$info || !isset($types[$info[2]])) throw new \RuntimeException('Format gambar harus JPG, PNG, atau WEBP.');
        $dir = dirname(__DIR__) . '/uploads/guest-services';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $base = 'gs_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $ext = $types[$info[2]];

        if (function_exists('imagecreatetruecolor')) {
            $src = $info[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($file['tmp_name'])
                : ($info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($file['tmp_name']) : @imagecreatefromwebp($file['tmp_name']));
            if ($src) {
                [$w, $h] = [$info[0], $info[1]];
                $scale = min(1, $maxSide / max($w, $h));
                $nw = max(1, (int)round($w * $scale));
                $nh = max(1, (int)round($h * $scale));
                $dst = imagecreatetruecolor($nw, $nh);
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // PNG transparan → latar putih
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                $ok = imagejpeg($dst, "$dir/$base.jpg", 82);
                imagedestroy($src);
                imagedestroy($dst);
                if ($ok) return "uploads/guest-services/$base.jpg";
            }
        }
        if (!move_uploaded_file($file['tmp_name'], "$dir/$base.$ext")) throw new \RuntimeException('Tidak bisa menyimpan gambar.');
        return "uploads/guest-services/$base.$ext";
    }

    /** Hapus berkas hanya bila berada di folder uploads/guest-services. */
    public static function deleteImage(string $path): void
    {
        if (strpos($path, 'uploads/guest-services/') !== 0 || strpos($path, '..') !== false) return;
        $f = dirname(__DIR__) . '/' . $path;
        if (is_file($f)) @unlink($f);
    }
}
