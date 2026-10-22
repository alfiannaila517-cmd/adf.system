<?php

declare(strict_types=1);

/**
 * Pengaturan website publik bisnis hotel (sama dengan developer/web-settings.php sistem lama): kunci web_* di tabel
 * `settings` database bisnis. Website membaca nilai yang sama, jadi perubahan dari Console langsung tampil.
 * Foto diunggah ke Cloudinary (kunci di secrets/cloudinary.env: CLOUDINARY_CLOUD_NAME/API_KEY/API_SECRET) atau
 * cukup tempel URL gambar. Nama & harga kamar tetap dari Front Desk (room_types), bukan dari sini.
 */
final class Website
{
    /** Kode kamar yang dikenal website (galeri + foto utama); deskripsi hanya untuk 3 tipe pertama. */
    public const ROOMS = ['king' => 'King', 'queen' => 'Queen', 'twin' => 'Twin', 'deluxe_king' => 'Deluxe King', 'deluxe_queen' => 'Deluxe Queen'];

    /**
     * Bagian → [kunci => [label, jenis, bawaan, petunjuk]]. Jenis: text, textarea, image, toggle, time, number, color.
     */
    public const SECTIONS = [
        'umum' => ['Umum', [
            'web_enabled' => ['Website aktif', 'toggle', '1', 'Matikan untuk menampilkan halaman "sedang perbaikan".'],
            'web_site_name' => ['Nama website', 'text', 'Narayana Karimunjawa', ''],
            'web_tagline' => ['Tagline', 'text', 'Island Paradise Resort', ''],
            'web_description' => ['Deskripsi singkat', 'textarea', '', ''],
            'web_logo' => ['Logo', 'image', '', 'PNG transparan disarankan.'],
            'web_favicon' => ['Favicon', 'image', '', 'Ikon kecil di tab browser (persegi).'],
        ]],
        'halaman' => ['Halaman', [
            'web_hero_accent' => ['Beranda · teks kecil di atas judul', 'text', 'Welcome to Paradise', ''],
            'web_hero_title' => ['Beranda · judul', 'text', '', 'Boleh pakai <br> untuk ganti baris.'],
            'web_hero_subtitle' => ['Beranda · subjudul', 'textarea', '', ''],
            'web_hero_background' => ['Beranda · foto latar', 'image', '', 'Foto horizontal, min. 1920×1080.'],
            'web_hero_rooms_eyebrow' => ['Kamar · teks kecil', 'text', 'Accommodations', ''],
            'web_hero_rooms_title' => ['Kamar · judul', 'text', 'Our Rooms', ''],
            'web_hero_rooms_subtitle' => ['Kamar · subjudul', 'textarea', '', ''],
            'web_hero_rooms_background' => ['Kamar · foto latar', 'image', '', ''],
            'web_hero_act_eyebrow' => ['Aktivitas · teks kecil', 'text', 'Karimunjawa Islands', ''],
            'web_hero_act_title' => ['Aktivitas · judul', 'text', '', ''],
            'web_hero_act_subtitle' => ['Aktivitas · subjudul', 'textarea', '', ''],
            'web_hero_act_background' => ['Aktivitas · foto latar', 'image', '', ''],
            'web_hero_dest_eyebrow' => ['Destinasi · teks kecil', 'text', 'Explore the Archipelago', ''],
            'web_hero_dest_title' => ['Destinasi · judul', 'text', 'Discover Karimunjawa', ''],
            'web_hero_dest_subtitle' => ['Destinasi · subjudul', 'textarea', '', ''],
            'web_hero_dest_background' => ['Destinasi · foto latar', 'image', '', ''],
            'web_hero_contact_eyebrow' => ['Kontak · teks kecil', 'text', 'Get in Touch', ''],
            'web_hero_contact_title' => ['Kontak · judul', 'text', 'Contact Us', ''],
            'web_hero_contact_subtitle' => ['Kontak · subjudul', 'textarea', '', ''],
            'web_hero_contact_background' => ['Kontak · foto latar', 'image', '', ''],
        ]],
        'kontak' => ['Kontak & Booking', [
            'web_whatsapp' => ['WhatsApp', 'text', '', 'Format 628…, tanpa + dan spasi.'],
            'web_phone' => ['Telepon', 'text', '', ''],
            'web_email' => ['Email', 'text', '', ''],
            'web_instagram' => ['Instagram', 'text', '', 'Nama akun tanpa @.'],
            'web_address' => ['Alamat', 'textarea', '', ''],
            'web_checkin_time' => ['Jam check-in', 'time', '14:00', ''],
            'web_checkout_time' => ['Jam check-out', 'time', '12:00', ''],
            'web_min_stay_nights' => ['Minimal menginap (malam)', 'number', '1', ''],
            'web_max_advance_days' => ['Bisa pesan maks. (hari ke depan)', 'number', '365', ''],
            'web_booking_notice' => ['Catatan booking untuk tamu', 'textarea', '', 'Tampil di halaman pemesanan.'],
        ]],
        'kamar' => ['Kamar', [
            'web_room_desc_king' => ['Deskripsi King', 'textarea', '', ''],
            'web_room_desc_queen' => ['Deskripsi Queen', 'textarea', '', ''],
            'web_room_desc_twin' => ['Deskripsi Twin', 'textarea', '', ''],
        ]],
        'seo' => ['SEO & Tampilan', [
            'web_meta_title' => ['Judul di Google', 'text', '', 'Sekitar 60 huruf.'],
            'web_meta_description' => ['Deskripsi di Google', 'textarea', '', 'Sekitar 155 huruf.'],
            'web_meta_keywords' => ['Kata kunci', 'text', '', 'Pisahkan dengan koma.'],
            'web_primary_color' => ['Warna utama', 'color', '#0c2340', ''],
            'web_accent_color' => ['Warna aksen', 'color', '#c8a45e', ''],
        ]],
    ];

    /** Tab Website hanya untuk bisnis hotel yang terhubung ke sistem lama. */
    public static function available(array $biz): bool
    {
        return $biz['type'] === 'hotel' && Legacy::linked($biz) && !empty($biz['db_name']);
    }

    public static function db(array $biz): PDO
    {
        return Db::legacyBusiness((string)$biz['db_name']);
    }

    /** Semua nilai web_* tersimpan (yang belum ada memakai bawaan). */
    public static function values(PDO $pdo): array
    {
        $out = [];
        foreach (self::SECTIONS as [, $fields]) {
            foreach ($fields as $k => $f) {
                $out[$k] = $f[2];
            }
        }
        foreach (self::ROOMS as $code => $label) {
            $out["web_room_gallery_{$code}"] = '[]';
            $out["web_room_primary_{$code}"] = '';
        }
        foreach ($pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web\\_%'")->fetchAll() as $r) {
            if (array_key_exists($r['setting_key'], $out)) {
                $out[$r['setting_key']] = (string)$r['setting_value'];
            }
        }
        return $out;
    }

    private static function put(PDO $pdo, string $key, string $value): void
    {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES (?, ?, 'text', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $value, 'Website: ' . $key]);
    }

    /**
     * Simpan satu bagian dari form. Gambar: unggah baru → Cloudinary; atau URL ditempel; centang hapus = kosong.
     * @return int jumlah nilai yang berubah
     */
    public static function save(PDO $pdo, string $section, array $post, array $files): int
    {
        if (!isset(self::SECTIONS[$section])) {
            throw new RuntimeException('Bagian tidak dikenal.');
        }
        $cur = self::values($pdo);
        $changed = 0;
        foreach (self::SECTIONS[$section][1] as $key => [$label, $kind]) {
            $new = $cur[$key];
            if ($kind === 'toggle') {
                $new = !empty($post[$key]) ? '1' : '0';
            } elseif ($kind === 'image') {
                if (!empty($post['remove'][$key])) {
                    $new = '';
                } elseif (!empty($files[$key]['name'])) {
                    $new = self::upload($files[$key], 'website/' . str_replace('web_', '', $key));
                } elseif (isset($post[$key]) && trim((string)$post[$key]) !== '' && trim((string)$post[$key]) !== $cur[$key]) {
                    $new = self::cleanUrl((string)$post[$key]);
                }
            } elseif (array_key_exists($key, $post)) {
                $v = trim(str_replace("\r\n", "\n", (string)$post[$key]));
                $new = match ($kind) {
                    'number' => (string)max(0, (int)$v),
                    'time' => preg_match('/^\d{2}:\d{2}$/', $v) ? $v : $cur[$key],
                    'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $cur[$key],
                    default => mb_substr($v, 0, 2000),
                };
            }
            if ($new !== $cur[$key]) {
                self::put($pdo, $key, $new);
                $changed++;
            }
        }
        return $changed;
    }

    /** Foto kamar: unggah ke galeri, hapus dari galeri, pilih foto utama. */
    public static function saveRoomPhotos(PDO $pdo, string $room, array $post, array $files): void
    {
        if (!isset(self::ROOMS[$room])) {
            throw new RuntimeException('Tipe kamar tidak dikenal.');
        }
        $cur = self::values($pdo);
        $gallery = json_decode($cur["web_room_gallery_{$room}"], true) ?: [];
        $remove = array_map('strval', (array)($post['remove_photo'] ?? []));
        $gallery = array_values(array_filter($gallery, fn ($u) => !in_array((string)$u, $remove, true)));
        foreach (self::fileList($files['photos'] ?? []) as $f) {
            $gallery[] = self::upload($f, "website/rooms/{$room}");
        }
        if (trim((string)($post['photo_url'] ?? '')) !== '') {
            $gallery[] = self::cleanUrl((string)$post['photo_url']);
        }
        $primary = (string)($post['primary'] ?? $cur["web_room_primary_{$room}"]);
        if (!in_array($primary, $gallery, true)) {
            $primary = $gallery[0] ?? '';
        }
        self::put($pdo, "web_room_gallery_{$room}", json_encode($gallery, JSON_UNESCAPED_SLASHES));
        self::put($pdo, "web_room_primary_{$room}", $primary);
    }

    /** URL gambar yang ditampilkan (Cloudinary / http apa adanya; path lokal sistem lama → alamat sistem lama). */
    public static function imageUrl(string $v): ?string
    {
        if ($v === '') {
            return null;
        }
        if (str_starts_with($v, 'http')) {
            return $v;
        }
        return rtrim((string)Secrets::get('LEGACY_BASE_URL', 'https://staging.adfsystem.online'), '/') . '/' . ltrim($v, '/');
    }

    public static function cloudinaryReady(): bool
    {
        return Secrets::get('CLOUDINARY_CLOUD_NAME') && Secrets::get('CLOUDINARY_API_KEY') && Secrets::get('CLOUDINARY_API_SECRET');
    }

    private static function cleanUrl(string $u): string
    {
        $u = trim($u);
        if (!preg_match('#^https://[^\s"<>]+$#i', $u)) {
            throw new RuntimeException('Alamat gambar harus diawali https:// (mis. link Cloudinary).');
        }
        return mb_substr($u, 0, 600);
    }

    /** $_FILES dengan multiple → daftar file tunggal. */
    private static function fileList(array $f): array
    {
        if (!isset($f['name']) || !is_array($f['name'])) {
            return !empty($f['name']) ? [$f] : [];
        }
        $out = [];
        foreach ($f['name'] as $i => $n) {
            if ($n !== '') {
                $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
            }
        }
        return $out;
    }

    /** Unggah gambar ke Cloudinary (signed upload, folder adf_system/<folder>); kembalikan secure_url. */
    private static function upload(array $file, string $folder): string
    {
        if (!self::cloudinaryReady()) {
            throw new RuntimeException('Unggah foto belum diatur di server (secrets/cloudinary.env). Sementara, tempel alamat (URL) gambarnya.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Unggah gagal — ukuran file mungkin terlalu besar.');
        }
        $info = @getimagesize((string)$file['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            throw new RuntimeException('File harus gambar JPG, PNG, WEBP, atau GIF.');
        }
        $params = ['folder' => 'adf_system/' . trim($folder, '/'), 'timestamp' => (string)time()];
        ksort($params);
        $toSign = implode('&', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($params), $params));
        $params['signature'] = sha1($toSign . Secrets::get('CLOUDINARY_API_SECRET'));
        $params['api_key'] = (string)Secrets::get('CLOUDINARY_API_KEY');
        $params['file'] = new CURLFile((string)$file['tmp_name'], (string)($info['mime'] ?? 'image/jpeg'), (string)$file['name']);
        $ch = curl_init('https://api.cloudinary.com/v1_1/' . rawurlencode((string)Secrets::get('CLOUDINARY_CLOUD_NAME')) . '/image/upload');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120]);
        $res = json_decode((string)curl_exec($ch), true);
        $err = curl_error($ch);
        curl_close($ch);
        if (empty($res['secure_url'])) {
            throw new RuntimeException('Unggah ke Cloudinary gagal: ' . ($res['error']['message'] ?? $err ?: 'tanpa keterangan'));
        }
        return (string)$res['secure_url'];
    }
}
