<?php

declare(strict_types=1);

/**
 * Isi website jualan adfsystem.store — file data/content.json milik website itu sendiri (format SAMA dengan admin lama
 * adfsystem.store/admin, jadi keduanya bisa dipakai bergantian sampai admin lama dimatikan).
 *   STORE_DIR  (secrets, opsional) folder website; bawaan /srv/adf/legacy/public_html/adfsystem.store
 *   STORE_URL  (secrets, opsional) alamat publik untuk pratinjau gambar; bawaan https://adfsystem.store
 */
final class StoreSite
{
    /** Warna kartu yang dikenal CSS website (sama dengan admin lama) */
    public const COLORS = ['c-orange' => 'Oranye', 'c-blue' => 'Biru', 'c-teal' => 'Hijau toska', 'c-purple' => 'Ungu', 'c-pink' => 'Merah muda', 'c-green' => 'Hijau'];

    public static function dir(): string
    {
        return rtrim((string)Secrets::get('STORE_DIR', '/srv/adf/legacy/public_html/adfsystem.store'), '/');
    }

    public static function url(string $path = ''): string
    {
        return rtrim((string)Secrets::get('STORE_URL', 'https://adfsystem.store'), '/') . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    private static function file(): string
    {
        return self::dir() . '/data/content.json';
    }

    /** null = siap; selain itu alasan kenapa belum bisa dipakai */
    public static function problem(): ?string
    {
        if (!is_dir(self::dir() . '/data')) {
            return 'Folder website jualan tidak ditemukan: ' . self::dir();
        }
        if (!is_writable(self::dir() . '/data') || (is_file(self::file()) && !is_writable(self::file()))) {
            return 'Console tidak punya izin menulis ke ' . self::dir() . '/data (perlu milik www-data).';
        }
        return null;
    }

    /** Nilai bawaan — sama dengan adf_default_content() di website */
    private static function defaults(): array
    {
        return [
            'branding' => ['logo' => ''],
            'hero' => ['title' => '', 'subtitle' => '', 'background' => ''],
            'contact' => ['email' => '', 'whatsapp' => '', 'address' => ''],
            'modules' => [],
            'portfolio' => [],
            'clients' => [],
            'layanan' => ['hero' => ['title' => '', 'subtitle' => ''], 'items' => []],
            'products' => [],
            'payment' => ['provider' => 'pakasir', 'project_slug' => '', 'api_key' => '', 'webhook_secret' => '', 'sandbox' => false],
        ];
    }

    public static function load(): array
    {
        $raw = is_file(self::file()) ? (string)file_get_contents(self::file()) : '';
        $data = json_decode($raw, true);
        return array_replace_recursive(self::defaults(), is_array($data) ? $data : []);
    }

    public static function save(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents(self::file(), $json, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menyimpan content.json (cek izin folder data/).');
        }
    }

    /**
     * Unggah gambar (JPG/PNG/WEBP/GIF ≤ 3 MB) ke uploads/<subdir>/ website.
     * @return ?string path relatif "uploads/…", null bila tidak ada file; RuntimeException bila file tidak valid
     */
    public static function upload(string $field, string $subdir): ?string
    {
        $f = $_FILES[$field] ?? null;
        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
        $info = $f['error'] === UPLOAD_ERR_OK && $f['size'] <= 3 * 1024 * 1024 ? @getimagesize($f['tmp_name']) : false;
        if (!$info || !isset($types[$info[2]])) {
            throw new RuntimeException('Gambar tidak valid. Gunakan JPG/PNG/WEBP/GIF maksimal 3 MB.');
        }
        $sub = preg_replace('/[^a-z0-9_-]/', '', $subdir);
        $dir = self::dir() . '/uploads/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Folder uploads website tidak bisa dibuat.');
        }
        $name = bin2hex(random_bytes(8)) . '.' . $types[$info[2]];
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
            throw new RuntimeException('Gagal menyimpan gambar.');
        }
        return "uploads/{$sub}/{$name}";
    }

    /** Hapus gambar lama — hanya di dalam uploads/ website */
    public static function deleteUpload(string $rel): void
    {
        if ($rel === '' || !str_starts_with($rel, 'uploads/') || str_contains($rel, '..')) {
            return;
        }
        $path = self::dir() . '/' . $rel;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Teks multi-baris → daftar (baris kosong dibuang) */
    public static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []), fn ($l) => $l !== ''));
    }
}
