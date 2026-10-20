<?php

declare(strict_types=1);

/**
 * Pembaca rahasia (password database, API key) dari file *.env di folder rahasia — tidak pernah dari git.
 * Format file: satu KUNCI=nilai per baris; baris kosong / diawali # diabaikan. Contoh: secrets/db.env berisi DB_PASS=...
 *
 *   Secrets::get('DB_PASS')            → nilai atau null
 *   Secrets::require('DB_PASS')        → nilai, atau RuntimeException bila belum diisi
 */
final class Secrets
{
    /** @var array<string,string>|null */
    private static ?array $values = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        return self::$values[$key] ?? $default;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new RuntimeException("Secret {$key} belum diisi di " . ADF_SECRETS);
        }
        return $value;
    }

    /** Nama kunci yang tersedia (tanpa nilainya) — aman untuk ditampilkan di halaman diagnosa. */
    public static function keys(): array
    {
        self::load();
        return array_keys(self::$values);
    }

    private static function load(): void
    {
        if (self::$values !== null) {
            return;
        }
        self::$values = [];
        foreach (glob(ADF_SECRETS . '/*.env') ?: [] as $file) {
            if (!is_readable($file)) {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                // Nilai boleh diapit tanda kutip: KEY="nilai dengan spasi"
                self::$values[trim($k)] = trim(trim($v), "\"'");
            }
        }
    }
}
