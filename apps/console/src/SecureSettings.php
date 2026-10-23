<?php

declare(strict_types=1);

/**
 * Kunci layanan yang bisa diatur dari halaman Console (seperti admin adfsystem.store lama): Telegram & Login Google.
 * Disimpan terenkripsi (Vault) di console_settings 'secure'; bila terisi, menimpa nilai dari secrets/*.env
 * (apply() dipanggil di bootstrap). Kosong = tetap memakai file secrets di server.
 */
final class SecureSettings
{
    public const KEYS = ['TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID', 'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'MAYAR_API_KEY', 'MAYAR_MODE'];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    private static function db(): PDO
    {
        return Auth::db();
    }

    /** Nilai yang tersimpan di Console (sudah dibuka), tanpa yang dari file */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            $raw = json_decode((string)(self::db()->query("SELECT setting_value FROM console_settings WHERE setting_key = 'secure'")->fetchColumn() ?: ''), true);
        } catch (Throwable) {
            return self::$cache; // DB belum siap (mis. saat migrasi pertama)
        }
        foreach (is_array($raw) ? $raw : [] as $k => $blob) {
            if (!in_array($k, self::KEYS, true)) {
                continue;
            }
            try {
                self::$cache[$k] = Vault::decrypt((string)$blob, 'console:' . $k);
            } catch (Throwable) {
                // VAULT_KEY berubah → abaikan, pakai file
            }
        }
        return self::$cache;
    }

    /** Terapkan ke Secrets (dipanggil di bootstrap Console) */
    public static function apply(): void
    {
        foreach (self::all() as $k => $v) {
            if ($v !== '') {
                Secrets::override($k, $v);
            }
        }
    }

    /** Simpan sebagian kunci: nilai '' = hapus dari Console (kembali ke file) */
    public static function save(array $values): void
    {
        if (!Vault::ready()) {
            throw new RuntimeException('Brankas kunci belum dibuat (console.php vault-key).');
        }
        $cur = self::all();
        foreach ($values as $k => $v) {
            if (!in_array($k, self::KEYS, true)) {
                continue;
            }
            $v = trim((string)$v);
            if ($v === '') {
                unset($cur[$k]);
            } else {
                $cur[$k] = $v;
            }
        }
        $enc = [];
        foreach ($cur as $k => $v) {
            $enc[$k] = Vault::encrypt($v, 'console:' . $k);
        }
        self::db()->prepare("INSERT INTO console_settings (setting_key, setting_value) VALUES ('secure', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([json_encode($enc)]);
        self::$cache = null;
        self::apply();
    }

    /** Asal nilai yang sedang dipakai: 'console' | 'file' | null (belum diisi) */
    public static function source(string $key): ?string
    {
        if (isset(self::all()[$key])) {
            return 'console';
        }
        return Secrets::get($key) !== null ? 'file' : null;
    }
}
