<?php

declare(strict_types=1);

/**
 * Tour 360° website (bisnis jenis Website): daftar lokasi di settings "web_tour360" (JSON) database hotel — dibaca
 * website/public/virtual-tour/index.php. Foto 360° (equirectangular 2:1) disimpan di folder website VPS:
 *   WEBSITE_DIR (secrets, opsional) bawaan /srv/adf/legacy/public_html/website/public  →  uploads/tour360/<id>-{4096,8192,thumb}.jpg
 * Lokasi: id, name {id,en}, base ("/uploads/tour360/<id>" atau "img/aerial" bawaan), view {yaw,pitch,fov},
 *         links [{to, yaw, pitch}] — derajat, pitch positif = ke bawah (konvensi Marzipano).
 * Foto biasa (bukan 360°): type "flat", w, h, gambar <base>-full.jpg (sisi terpanjang ≤ 4096) + -thumb.jpg;
 *         penghubungnya [{to, x, y}] — posisi di foto 0…1 dari kiri-atas.
 * Opsional: ball = alamat gambar bola sendiri "/uploads/tour360/<id>-ball.png?v=…" (mis. logo) — tanpa ini bola memakai gambar kecil;
 *         link.lp = letak nama di sebelah bola: l (kiri) / r (kanan); kosong atau t = di atas bola (bola bertiang);
 *         hide = id lokasi yang bola kembali otomatisnya disembunyikan (dihapus admin).
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
        if ($size === 'ball') {
            // gambar bola sendiri, bila tidak ada → gambar kecil
            $png = self::upDir() . '/' . $scene['id'] . '-ball.png';
            if (!empty($scene['ball']) && is_file($png)) {
                return $png;
            }
            $size = 'thumb';
        }
        $f = $path . '-' . $size . '.jpg';
        if (!is_file($f) && $size === 'thumb') {
            $f = $path . '-4096.jpg';
        }
        return is_file($f) ? $f : null;
    }

    public static function isFlat(array $scene): bool
    {
        return ($scene['type'] ?? '') === 'flat';
    }

    /**
     * Posisi bola kembali otomatis di lokasi $b menuju $a, dari penghubung $link (di $a menuju $b):
     * 360°→360° arah berlawanan (yaw + 180°, pitch dibalik); menuju foto biasa → tengah bawah foto;
     * dari foto biasa → di depan tampilan awal lokasi 360°, sedikit ke bawah. (Website menghitung dengan cara yang sama.)
     */
    public static function backPos(array $a, array $link, array $b): array
    {
        if (self::isFlat($b)) {
            return ['x' => 0.5, 'y' => 0.85];
        }
        if (self::isFlat($a)) {
            return ['yaw' => (float)($b['view']['yaw'] ?? 0), 'pitch' => min(40.0, (float)($b['view']['pitch'] ?? 0) + 15)];
        }
        return ['yaw' => round(fmod((float)$link['yaw'] + 360, 360) - 180, 1), 'pitch' => round(max(-40, min(40, -(float)$link['pitch'])), 1)];
    }

    /** Bola kembali yang tampil otomatis di lokasi $cur (lokasi lain menuju sini, sini belum punya penghubung ke sana) */
    public static function implied(array $scenes, string $cur): array
    {
        $byId = array_column($scenes, null, 'id');
        $here = $byId[$cur] ?? null;
        if (!$here) {
            return [];
        }
        // yang sudah punya penghubung sendiri, atau sengaja dihapus admin (hide), tidak dibuatkan bola otomatis
        $have = array_merge(array_column($here['links'] ?? [], 'to'), $here['hide'] ?? []);
        $out = [];
        foreach ($scenes as $o) {
            foreach ($o['links'] ?? [] as $l) {
                if ($l['to'] === $cur && $o['id'] !== $cur && !in_array($o['id'], $have, true)) {
                    $out[] = ['to' => $o['id']] + self::backPos($o, $l, $here);
                    $have[] = $o['id'];
                }
            }
        }
        return $out;
    }

    /**
     * Tambah lokasi dari foto unggahan. Foto 360° (equirectangular ~2:1, JPG) → 8192, 4096 & gambar kecil 480×240.
     * Foto lain (JPG/PNG/WEBP) → foto biasa: sisi terpanjang ≤ 4096 (rotasi EXIF dari HP diperbaiki) + gambar kecil.
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
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new RuntimeException('Foto harus JPG, PNG, atau WEBP.');
        }
        [$w, $h] = $info;
        $pano = $info[2] === IMAGETYPE_JPEG && abs($w / max(1, $h) - 2) <= 0.08;
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
        $src = match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($file['tmp_name']),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file['tmp_name']),
            default => @imagecreatefromjpeg($file['tmp_name']),
        };
        if (!$src) {
            throw new RuntimeException('Foto tidak bisa dibaca.');
        }
        $dir = self::upDir();
        if (!$pano) {
            return self::addFlat($pdo, $src, $info[2], $file['tmp_name'], $id, $nameId, $nameEn, $scenes);
        }
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

    /** Foto biasa: putar sesuai EXIF (foto HP), sisi terpanjang ≤ 4096, gambar kecil 480×240 dari tengah */
    private static function addFlat(PDO $pdo, GdImage $src, int $type, string $tmp, string $id, string $nameId, string $nameEn, array $scenes): string
    {
        $ori = $type === IMAGETYPE_JPEG && function_exists('exif_read_data') ? (int)(@exif_read_data($tmp)['Orientation'] ?? 1) : 1;
        $rot = [3 => 180, 6 => -90, 8 => 90][$ori] ?? 0; // imagerotate: sudut berlawanan arah jarum jam
        if ($rot) {
            $src = imagerotate($src, $rot, 0);
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $k = min(1, 4096 / max($w, $h));
        $fw = (int)round($w * $k);
        $fh = (int)round($h * $k);
        $dir = self::upDir();
        $im = imagecreatetruecolor($fw, $fh);
        imagecopyresampled($im, $src, 0, 0, 0, 0, $fw, $fh, $w, $h);
        imagejpeg($im, "{$dir}/{$id}-full.jpg", 86);
        // gambar kecil 2:1 dari tengah foto
        $cw = min($w, $h * 2);
        $ch = (int)($cw / 2);
        $th = imagecreatetruecolor(480, 240);
        imagecopyresampled($th, $src, 0, 0, (int)(($w - $cw) / 2), (int)(($h - $ch) / 2), 480, 240, (int)$cw, $ch);
        imagejpeg($th, "{$dir}/{$id}-thumb.jpg", 82);

        $scenes[] = ['id' => $id, 'type' => 'flat', 'w' => $fw, 'h' => $fh, 'name' => ['id' => $nameId, 'en' => trim($nameEn) ?: $nameId],
            'base' => '/uploads/tour360/' . $id, 'view' => ['yaw' => 0, 'pitch' => 0, 'fov' => 100], 'links' => []];
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

    /**
     * Gambar bola lokasi (mis. logo): potong persegi dari tengah, 256×256 PNG (transparan tetap transparan).
     * $file null = kembali ke gambar kecil foto lokasi.
     */
    public static function setBall(PDO $pdo, string $id, ?array $file): void
    {
        $scenes = self::load($pdo);
        $k = array_search($id, array_column($scenes, 'id'), true);
        if ($k === false) {
            throw new RuntimeException('Lokasi tidak ditemukan.');
        }
        $png = self::upDir() . '/' . $id . '-ball.png';
        if ($file === null) {
            @unlink($png);
            unset($scenes[$k]['ball']);
            self::save($pdo, $scenes);
            return;
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(($file['error'] ?? 4) === UPLOAD_ERR_NO_FILE ? 'Pilih gambar bola dulu.' : 'Unggahan gagal (kode ' . (int)$file['error'] . ').');
        }
        if ($p = self::problem()) {
            throw new RuntimeException($p);
        }
        $info = @getimagesize($file['tmp_name']);
        $src = match ($info[2] ?? 0) {
            IMAGETYPE_PNG => @imagecreatefrompng($file['tmp_name']),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file['tmp_name']),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
            default => false,
        };
        if (!$src) {
            throw new RuntimeException('Gambar bola harus JPG, PNG, atau WEBP.');
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        $side = min($w, $h);
        $im = imagecreatetruecolor(256, 256);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagecopyresampled($im, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), 256, 256, $side, $side);
        imagepng($im, $png, 6);
        $scenes[$k]['ball'] = '/uploads/tour360/' . $id . '-ball.png?v=' . time(); // ?v → browser memuat gambar baru
        self::save($pdo, $scenes);
    }

    /** Letak nama di sebelah bola penghubung $from→$to: t / l / r / '' (bawah). Bola kembali otomatis ikut disimpan jadi tetap. */
    public static function setLabel(PDO $pdo, string $from, string $to, string $lp): void
    {
        $lp = in_array($lp, ['t', 'l', 'r'], true) ? $lp : '';
        $scenes = self::load($pdo);
        foreach ($scenes as &$s) {
            if ($s['id'] !== $from) {
                continue;
            }
            $found = false;
            foreach ($s['links'] as &$l) {
                if ($l['to'] === $to) {
                    $found = true;
                    if ($lp === '') {
                        unset($l['lp']);
                    } else {
                        $l['lp'] = $lp;
                    }
                }
            }
            unset($l);
            if (!$found) {
                foreach (self::implied($scenes, $from) as $l) {
                    if ($l['to'] === $to) {
                        $s['links'][] = $l + ($lp !== '' ? ['lp' => $lp] : []);
                    }
                }
            }
        }
        unset($s);
        self::save($pdo, $scenes);
    }

    /** Alamat musik latar (settings web_tour360_music), '' = musik bawaan yang dibuat di browser */
    public static function music(PDO $pdo): string
    {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'web_tour360_music'");
        $st->execute();
        return (string)$st->fetchColumn();
    }

    /** Unggah musik latar MP3 (≤ 20 MB) ke uploads/tour360/music-<waktu>.mp3; $file null = kembali ke musik bawaan */
    public static function setMusic(PDO $pdo, ?array $file): void
    {
        $old = self::music($pdo);
        $url = '';
        if ($file !== null) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException(($file['error'] ?? 4) === UPLOAD_ERR_NO_FILE ? 'Pilih file musik dulu.' : 'Unggahan gagal (kode ' . (int)$file['error'] . ').');
            }
            if ($p = self::problem()) {
                throw new RuntimeException($p);
            }
            if ((int)$file['size'] > 20 * 1048576) {
                throw new RuntimeException('Musik maksimal 20 MB.');
            }
            $head = (string)file_get_contents($file['tmp_name'], false, null, 0, 3);
            // MP3: tag ID3 atau frame sync 0xFFEx/0xFFFx
            if ($head !== 'ID3' && !(strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0)) {
                throw new RuntimeException('File musik harus MP3.');
            }
            $name = 'music-' . date('YmdHis') . '.mp3';
            if (!move_uploaded_file($file['tmp_name'], self::upDir() . '/' . $name)) {
                throw new RuntimeException('Musik tidak bisa disimpan.');
            }
            $url = '/uploads/tour360/' . $name;
        }
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES ('web_tour360_music', ?, 'text', 'Musik latar Tour 360° (dikelola ADF Console)')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$url]);
        if ($old !== '' && str_starts_with($old, '/uploads/tour360/music-')) {
            @unlink(self::webDir() . $old);
        }
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
        @unlink(self::upDir() . '/' . $id . '-ball.png');
        if (str_starts_with((string)$gone['base'], '/uploads/tour360/')) {
            foreach (['4096', '8192', 'full', 'thumb'] as $sz) {
                @unlink(self::webDir() . $gone['base'] . '-' . $sz . '.jpg');
            }
        }
    }

    /**
     * Tambah penghubung (bola foto) di lokasi $from menuju $to. $pos: lokasi 360° → yaw/pitch (derajat); foto biasa → x/y (0…1).
     * $back: lokasi tujuan yang belum punya bola ke $from otomatis diberi bola kembali (posisi dari backPos) — bisa dipindah nanti.
     * @return bool true bila bola kembali ikut dibuat
     */
    public static function addLink(PDO $pdo, string $from, string $to, array $pos, bool $back = true): bool
    {
        $scenes = self::load($pdo);
        $byId = array_column($scenes, null, 'id');
        if ($from === $to || !isset($byId[$to], $byId[$from])) {
            throw new RuntimeException('Pilih lokasi tujuan yang lain.');
        }
        $link = self::isFlat($byId[$from])
            ? ['to' => $to, 'x' => round(max(0, min(1, (float)($pos['x'] ?? .5))), 4), 'y' => round(max(0, min(1, (float)($pos['y'] ?? .5))), 4)]
            : ['to' => $to, 'yaw' => round((float)($pos['yaw'] ?? 0), 1), 'pitch' => round((float)($pos['pitch'] ?? 0), 1)];
        $made = false;
        foreach ($scenes as &$s) {
            if ($s['id'] === $from) {
                $s['links'] = array_values(array_filter($s['links'] ?? [], fn ($l) => $l['to'] !== $to)); // satu bola per tujuan
                $s['links'][] = $link;
                $s['hide'] = array_values(array_diff($s['hide'] ?? [], [$to])); // dipasang lagi → tidak disembunyikan
            } elseif ($back && $s['id'] === $to && !in_array($from, array_column($s['links'] ?? [], 'to'), true)) {
                $s['links'][] = ['to' => $from] + self::backPos($byId[$from], $link, $s);
                $made = true;
            }
        }
        unset($s);
        self::save($pdo, $scenes);
        return $made;
    }

    /**
     * Hapus bola di $from menuju $to. Dicatat juga di "hide" supaya bola kembali OTOMATIS (dari penghubung $to → $from)
     * tidak muncul lagi — dulu bola yang dihapus langsung muncul kembali sebagai bola otomatis.
     */
    public static function removeLink(PDO $pdo, string $from, string $to): void
    {
        $scenes = self::load($pdo);
        foreach ($scenes as &$s) {
            if ($s['id'] === $from) {
                $s['links'] = array_values(array_filter($s['links'] ?? [], fn ($l) => $l['to'] !== $to));
                $s['hide'] = array_values(array_unique(array_merge($s['hide'] ?? [], [$to])));
            }
        }
        unset($s);
        self::save($pdo, $scenes);
    }
}
