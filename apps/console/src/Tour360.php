<?php

declare(strict_types=1);

/**
 * Tour 360° website (bisnis jenis Website): daftar lokasi di settings "web_tour360" (JSON) database hotel — dibaca
 * website/public/virtual-tour/index.php. Foto 360° (equirectangular 2:1) disimpan di folder website VPS:
 *   WEBSITE_DIR (secrets, opsional) bawaan /srv/adf/legacy/public_html/website/public  →  uploads/tour360/<id>-{4096,8192,thumb}.jpg
 * Lokasi: id, name {id,en}, base ("/uploads/tour360/<id>" atau "img/aerial" bawaan), view {yaw,pitch,fov},
 *         links [{to, yaw, pitch}] — derajat, pitch positif = ke bawah (konvensi Marzipano).
 */
final class Tour360
{
    public static function webDir(): string
    {
        return rtrim((string)Secrets::get('WEBSITE_DIR', '/srv/adf/legacy/public_html/website/public'), '/');
    }

    private static function upDir(): string
    {
        return self::webDir() . '/uploads/tour360';
    }

    /** null = siap; selain itu alasan (folder tidak ada / tidak bisa ditulis) */
    public static function problem(): ?string
    {
        $up = self::webDir() . '/uploads';
        if (!is_dir($up)) {
            return 'Folder website tidak ditemukan: ' . self::webDir();
        }
        if (!is_dir(self::upDir()) && !@mkdir(self::upDir(), 0775, true)) {
            return 'Console tidak bisa membuat folder ' . self::upDir() . ' (izin tulis).';
        }
        return is_writable(self::upDir()) ? null : 'Console tidak punya izin menulis ke ' . self::upDir() . '.';
    }

    /** Lokasi bawaan bila belum ada data: foto udara di virtual-tour/img */
    private static function defaults(): array
    {
        return [['id' => 'aerial', 'name' => ['id' => 'Pemandangan Udara Karimunjawa', 'en' => 'Karimunjawa Aerial View'], 'base' => 'img/aerial', 'view' => ['yaw' => 25, 'pitch' => 12, 'fov' => 100], 'links' => []]];
    }

    public static function load(PDO $pdo): array
    {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'web_tour360'");
        $st->execute();
        $s = json_decode((string)$st->fetchColumn(), true);
        return is_array($s) && $s ? array_values($s) : self::defaults();
    }

    public static function save(PDO $pdo, array $scenes): void
    {
        $json = json_encode(array_values($scenes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES ('web_tour360', ?, 'json', 'Tour 360° website (dikelola ADF Console)')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$json]);
    }

    /** Path gambar lokasi di disk (untuk pratinjau Console): base relatif terhadap folder virtual-tour atau root website */
    public static function file(array $scene, string $size): ?string
    {
        $base = (string)$scene['base'];
        $path = str_starts_with($base, '/') ? self::webDir() . $base : self::webDir() . '/virtual-tour/' . $base;
        $f = $path . '-' . $size . '.jpg';
        if (!is_file($f) && $size === 'thumb') {
            $f = $path . '-4096.jpg';
        }
        return is_file($f) ? $f : null;
    }

    /**
     * Tambah lokasi dari foto 360° unggahan (JPG equirectangular ~2:1): disimpan 8192, 4096 & gambar kecil 480×240.
     * @return string id lokasi baru
     */
    public static function add(PDO $pdo, array $file, string $nameId, string $nameEn): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ((int)($file['error'] ?? 4)) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File terlalu besar untuk batas unggah server (lihat petunjuk di halaman ini).',
                UPLOAD_ERR_NO_FILE => 'Pilih foto 360° dulu.',
                default => 'Unggahan gagal (kode ' . (int)$file['error'] . ').',
            });
        }
        if ($p = self::problem()) {
            throw new RuntimeException($p);
        }
        $info = @getimagesize($file['tmp_name']);
        if (!$info || $info[2] !== IMAGETYPE_JPEG) {
            throw new RuntimeException('Foto harus JPG.');
        }
        [$w, $h] = $info;
        if (abs($w / max(1, $h) - 2) > 0.08) {
            throw new RuntimeException("Foto bukan 360° (ukuran {$w}×{$h}; seharusnya lebar = 2 × tinggi, mis. 8192×4096).");
        }
        $nameId = trim($nameId) ?: 'Lokasi';
        $id = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($nameId)), '-') ?: 'lokasi';
        $scenes = self::load($pdo);
        $ids = array_column($scenes, 'id');
        for ($i = 2, $base = $id; in_array($id, $ids, true); $i++) {
            $id = $base . '-' . $i;
        }
        // Ubah ukuran (foto 12000 px butuh memori besar)
        ini_set('memory_limit', '2048M');
        @set_time_limit(300);
        $src = imagecreatefromjpeg($file['tmp_name']);
        if (!$src) {
            throw new RuntimeException('Foto tidak bisa dibaca.');
        }
        $dir = self::upDir();
        foreach ([8192 => 84, 4096 => 82] as $size => $q) {
            $tw = min($size, $w);
            $im = imagecreatetruecolor($tw, (int)($tw / 2));
            imagecopyresampled($im, $src, 0, 0, 0, 0, $tw, (int)($tw / 2), $w, $h);
            imagejpeg($im, "{$dir}/{$id}-{$size}.jpg", $q);
            imagedestroy($im);
        }
        // Gambar kecil: potongan tengah (arah depan) — untuk bola penghubung & daftar lokasi
        $th = imagecreatetruecolor(480, 240);
        imagecopyresampled($th, $src, 0, 0, (int)($w * 0.375), (int)($h * 0.3125), 480, 240, (int)($w * 0.25), (int)($h * 0.25));
        imagejpeg($th, "{$dir}/{$id}-thumb.jpg", 82);
        imagedestroy($src);

        $scenes[] = ['id' => $id, 'name' => ['id' => $nameId, 'en' => trim($nameEn) ?: $nameId], 'base' => '/uploads/tour360/' . $id,
            'view' => ['yaw' => 0, 'pitch' => 0, 'fov' => 100], 'links' => []];
        self::save($pdo, $scenes);
        return $id;
    }

    /** Ubah nama / tampilan awal satu lokasi */
    public static function update(PDO $pdo, string $id, array $patch): void
    {
        $scenes = self::load($pdo);
        foreach ($scenes as &$s) {
            if ($s['id'] !== $id) {
                continue;
            }
            if (isset($patch['name_id'])) {
                $s['name']['id'] = trim((string)$patch['name_id']) ?: $s['name']['id'];
            }
            if (isset($patch['name_en'])) {
                $s['name']['en'] = trim((string)$patch['name_en']) ?: $s['name']['en'];
            }
            if (isset($patch['view'])) {
                $s['view'] = array_map('floatval', array_intersect_key((array)$patch['view'], ['yaw' => 1, 'pitch' => 1, 'fov' => 1])) + $s['view'];
            }
        }
        unset($s);
        self::save($pdo, $scenes);
    }

    /** Jadikan lokasi pertama (dibuka saat tour dimuat) */
    public static function makeFirst(PDO $pdo, string $id): void
    {
        $scenes = self::load($pdo);
        usort($scenes, fn ($a, $b) => ($b['id'] === $id) <=> ($a['id'] === $id));
        self::save($pdo, $scenes);
    }

    /** Hapus lokasi + penghubung yang menuju ke sana + filenya (hanya unggahan Console) */
    public static function delete(PDO $pdo, string $id): void
    {
        $scenes = self::load($pdo);
        $gone = null;
        foreach ($scenes as $k => $s) {
            if ($s['id'] === $id) {
                $gone = $s;
                unset($scenes[$k]);
            }
        }
        if (!$gone) {
            return;
        }
        if (!$scenes) {
            throw new RuntimeException('Tour harus punya minimal satu lokasi.');
        }
        foreach ($scenes as &$s) {
            $s['links'] = array_values(array_filter($s['links'] ?? [], fn ($l) => $l['to'] !== $id));
        }
        unset($s);
        self::save($pdo, $scenes);
        if (str_starts_with((string)$gone['base'], '/uploads/tour360/')) {
            foreach (['4096', '8192', 'thumb'] as $sz) {
                @unlink(self::webDir() . $gone['base'] . '-' . $sz . '.jpg');
            }
        }
    }

    /** Tambah penghubung (bola foto) di lokasi $from menuju $to pada yaw/pitch */
    public static function addLink(PDO $pdo, string $from, string $to, float $yaw, float $pitch): void
    {
        $scenes = self::load($pdo);
        if ($from === $to || !in_array($to, array_column($scenes, 'id'), true)) {
            throw new RuntimeException('Pilih lokasi tujuan yang lain.');
        }
        foreach ($scenes as &$s) {
            if ($s['id'] === $from) {
                $s['links'] = array_values(array_filter($s['links'] ?? [], fn ($l) => $l['to'] !== $to)); // satu bola per tujuan
                $s['links'][] = ['to' => $to, 'yaw' => round($yaw, 1), 'pitch' => round($pitch, 1)];
            }
        }
        unset($s);
        self::save($pdo, $scenes);
    }

    public static function removeLink(PDO $pdo, string $from, string $to): void
    {
        $scenes = self::load($pdo);
        foreach ($scenes as &$s) {
            if ($s['id'] === $from) {
                $s['links'] = array_values(array_filter($s['links'] ?? [], fn ($l) => $l['to'] !== $to));
            }
        }
        unset($s);
        self::save($pdo, $scenes);
    }
}
