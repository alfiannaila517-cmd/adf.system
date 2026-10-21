<?php

declare(strict_types=1);

/**
 * Branding login per client (grup): domain sendiri → halaman login sistem lama tampil dengan background, logo & nama
 * perusahaan, TANPA mengunci satu bisnis (beda dengan addon_domain bisnis). Dibaca login.php versi VPS (branch vps-legacy).
 * Disimpan di master sistem lama, settings.setting_key = 'login_brands' (JSON daftar), gambar di uploads/brands/.
 *   [{"client_id":5,"domain":"nasita.biz.id","name":"NASITA","subtitle":"…","bg":"uploads/brands/c5-bg-ab12.jpg","logo":"…"}]
 */
final class Branding
{
    private const KEY = 'login_brands';
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    public static function available(): bool
    {
        return Db::legacyMaster() !== null && Legacy::appDir() !== null;
    }

    /** @return array<int, array> semua branding */
    public static function all(): array
    {
        $v = Legacy::db()->query("SELECT setting_value FROM settings WHERE setting_key = '" . self::KEY . "'")->fetchColumn();
        $list = json_decode((string)$v, true);
        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    public static function forClient(int $clientId): array
    {
        foreach (self::all() as $b) {
            if ((int)($b['client_id'] ?? 0) === $clientId) {
                return $b;
            }
        }
        return ['client_id' => $clientId, 'domain' => '', 'name' => '', 'subtitle' => '', 'bg' => '', 'logo' => ''];
    }

    /**
     * Simpan branding client. $files = $_FILES (bg, logo); $remove = ['bg','logo'] untuk menghapus gambar.
     * Domain tidak boleh dipakai client lain atau sebagai domain bisnis (addon_domain).
     */
    public static function save(int $clientId, array $in, array $files, array $remove = []): void
    {
        $cur = self::forClient($clientId);
        $domain = Legacy::normalizeDomain((string)($in['domain'] ?? ''));
        if ($domain !== '' && !preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $domain)) {
            throw new RuntimeException('Domain tidak valid.');
        }
        if ($domain !== '') {
            foreach (self::all() as $b) {
                if ((int)$b['client_id'] !== $clientId && ($b['domain'] ?? '') === $domain) {
                    throw new RuntimeException("Domain {$domain} sudah dipakai grup lain.");
                }
            }
            $st = Legacy::db()->prepare("SELECT business_name FROM businesses WHERE LOWER(REPLACE(addon_domain,'www.','')) = ?");
            $st->execute([$domain]);
            if ($other = $st->fetchColumn()) {
                throw new RuntimeException("Domain {$domain} sudah dipakai sebagai domain bisnis {$other}.");
            }
        }
        $b = [
            'client_id' => $clientId, 'domain' => $domain,
            'name' => mb_substr(trim((string)($in['name'] ?? '')), 0, 40), 'subtitle' => mb_substr(trim((string)($in['subtitle'] ?? '')), 0, 80),
            'bg' => (string)($cur['bg'] ?? ''), 'logo' => (string)($cur['logo'] ?? ''),
        ];
        foreach (['bg', 'logo'] as $k) {
            if (in_array($k, $remove, true)) {
                self::deleteImage($b[$k]);
                $b[$k] = '';
            }
            if (!empty($files[$k]['name'])) {
                $new = self::storeImage($files[$k], "c{$clientId}-{$k}");
                self::deleteImage($b[$k]);
                $b[$k] = $new;
            }
        }
        $list = array_values(array_filter(self::all(), fn ($x) => (int)($x['client_id'] ?? 0) !== $clientId));
        if ($b['domain'] !== '' || $b['bg'] !== '' || $b['logo'] !== '' || $b['name'] !== '') {
            $list[] = $b;
        }
        $db = Legacy::db();
        $json = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($db->query("SELECT 1 FROM settings WHERE setting_key = '" . self::KEY . "'")->fetchColumn()) {
            $db->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$json, self::KEY]);
        } else {
            $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute([self::KEY, $json]);
        }
    }

    /** Simpan gambar unggahan ke uploads/brands/ sistem lama; kembalikan path relatif. */
    private static function storeImage(array $file, string $prefix): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Unggah gambar gagal, coba lagi.');
        }
        if ((int)$file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('Ukuran gambar maksimal 8 MB.');
        }
        $info = @getimagesize((string)$file['tmp_name']);
        $ext = $info ? (self::TYPES[$info[2]] ?? null) : null;
        if (!$ext) {
            throw new RuntimeException('Gambar harus JPG, PNG, atau WEBP.');
        }
        $dir = Legacy::appDir() . '/uploads/brands';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('Folder uploads/brands tidak bisa dibuat.');
        }
        $name = $prefix . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file((string)$file['tmp_name'], "{$dir}/{$name}")) {
            throw new RuntimeException('Gambar tidak bisa disimpan.');
        }
        @chmod("{$dir}/{$name}", 0664);
        return 'uploads/brands/' . $name;
    }

    private static function deleteImage(string $rel): void
    {
        if (str_starts_with($rel, 'uploads/brands/') && !str_contains($rel, '..')) {
            @unlink(Legacy::appDir() . '/' . $rel);
        }
    }

    /** URL publik gambar (untuk pratinjau di Console). */
    public static function publicUrl(array $b, string $key): ?string
    {
        if (empty($b[$key])) {
            return null;
        }
        $base = rtrim((string)Secrets::get('LEGACY_BASE_URL', 'https://staging.adfsystem.online'), '/');
        return $base . '/' . $b[$key];
    }
}
