<?php

declare(strict_types=1);

/**
 * Brankas kunci API: enkripsi AES-256-GCM dengan kunci VAULT_KEY dari secrets/vault.env (tidak pernah di database / git).
 * Database hanya menyimpan sandi acak "v1:…"; tanpa vault.env isinya tidak bisa dibaca.
 *
 *   Vault::encrypt('rahasia', 'biz:5:whatsapp')  → "v1:…"
 *   Vault::decrypt($blob, 'biz:5:whatsapp')      → 'rahasia'
 *
 * $context ikut dikunci (AAD): sandi milik bisnis A tidak bisa disalin ke bisnis B lalu dibuka di sana.
 * PENTING: vault.env ikut di-backup terpisah — kalau hilang, semua kunci API harus diisi ulang.
 */
final class Vault
{
    private const CIPHER = 'aes-256-gcm';

    public static function ready(): bool
    {
        return self::key() !== null;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    public static function encrypt(string $plain, string $context): string
    {
        $key = self::key() ?? throw new RuntimeException('VAULT_KEY belum dibuat (jalankan: php cli/console.php vault-key)');
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $context);
        if ($ct === false) {
            throw new RuntimeException('Enkripsi gagal');
        }
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $blob, string $context): string
    {
        $key = self::key() ?? throw new RuntimeException('VAULT_KEY belum dibuat');
        $raw = str_starts_with($blob, 'v1:') ? base64_decode(substr($blob, 3), true) : false;
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Format sandi tidak dikenal');
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $context);
        if ($plain === false) {
            throw new RuntimeException('Sandi tidak bisa dibuka (VAULT_KEY berbeda atau data rusak)');
        }
        return $plain;
    }

    private static function key(): ?string
    {
        $raw = base64_decode((string)Secrets::get('VAULT_KEY', ''), true);
        return $raw !== false && strlen($raw) === 32 ? $raw : null;
    }
}
