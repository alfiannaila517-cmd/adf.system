<?php

declare(strict_types=1);

/**
 * Penyimpanan integrasi per bisnis (tabel business_integrations). Definisi field & tes ada di core/Integrations.php.
 * Nilai rahasia disimpan terenkripsi (Vault) dan tidak pernah dikirim balik ke halaman — hanya "tersimpan ••••1234".
 */
final class Integration
{
    private static function db(): PDO
    {
        return Auth::db();
    }

    private static function ctx(array $biz, string $code, string $field): string
    {
        return "biz:{$biz['id']}:{$code}:{$field}";
    }

    /** @return array<string, array{enabled:bool, config:array, secrets:array, row:?array}> semua integrasi yang tersedia untuk jenis bisnis ini */
    public static function all(array $biz): array
    {
        $st = self::db()->prepare('SELECT * FROM business_integrations WHERE business_id = ?');
        $st->execute([(int)$biz['id']]);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[$r['provider']] = $r;
        }
        $out = [];
        foreach (Integrations::DEFS as $code => $def) {
            if (!Integrations::available($code, (string)$biz['type'])) {
                continue;
            }
            $r = $rows[$code] ?? null;
            $out[$code] = [
                'enabled' => $r ? (bool)$r['enabled'] : false,
                'config' => $r ? (json_decode((string)$r['config'], true) ?: []) : [],
                'secrets' => $r ? (json_decode((string)$r['secrets'], true) ?: []) : [],
                'row' => $r,
            ];
        }
        return $out;
    }

    /** Petunjuk nilai rahasia yang tersimpan: "••••1234", "tersimpan (tidak bisa dibuka)", atau null. */
    public static function secretHint(array $biz, string $code, string $field, array $secrets): ?string
    {
        if (empty($secrets[$field])) {
            return null;
        }
        try {
            $plain = Vault::decrypt($secrets[$field], self::ctx($biz, $code, $field));
            return '••••' . (mb_strlen($plain) > 8 ? mb_substr($plain, -4) : '');
        } catch (Throwable) {
            return 'tersimpan (tidak bisa dibuka — VAULT_KEY berubah?)';
        }
    }

    /**
     * Simpan isian form. Field rahasia: diisi = ganti, kosong = tetap, dicentang hapus = dikosongkan.
     * @param array<string,string> $input  @param string[] $clear field rahasia yang dihapus
     */
    public static function save(array $biz, string $code, array $input, bool $enabled, array $clear = []): void
    {
        $def = Integrations::DEFS[$code] ?? throw new InvalidArgumentException('Integrasi tidak dikenal');
        $cur = self::all($biz)[$code] ?? throw new InvalidArgumentException('Integrasi tidak tersedia untuk jenis bisnis ini');
        $config = $cur['config'];
        $secrets = $cur['secrets'];
        foreach ($def['fields'] as $field => [, $kind, , $opt]) {
            $val = trim((string)($input[$field] ?? ''));
            if ($kind === 'secret') {
                if (in_array($field, $clear, true)) {
                    unset($secrets[$field]);
                } elseif ($val !== '') {
                    $secrets[$field] = Vault::encrypt($val, self::ctx($biz, $code, $field));
                }
                continue;
            }
            if ($kind === 'select' && $val !== '' && !array_key_exists($val, (array)$opt)) {
                $val = '';
            }
            if ($kind === 'number' && $val !== '') {
                $val = (string)max(1, min(65535, (int)$val));
            }
            $config[$field] = mb_substr($val, 0, 2000);
        }
        self::db()->prepare('INSERT INTO business_integrations (business_id, provider, enabled, config, secrets) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), config = VALUES(config), secrets = VALUES(secrets)')
            ->execute([(int)$biz['id'], $code, $enabled ? 1 : 0, json_encode($config), json_encode((object)$secrets)]);
    }

    /** Nilai lengkap untuk tes: isian form (yang diketik) menimpa nilai tersimpan; rahasia dibuka dari Vault. */
    public static function values(array $biz, string $code, array $typed = []): array
    {
        $cur = self::all($biz)[$code] ?? ['config' => [], 'secrets' => []];
        $v = $cur['config'];
        foreach ($cur['secrets'] as $field => $blob) {
            $v[$field] = Vault::decrypt((string)$blob, self::ctx($biz, $code, $field));
        }
        foreach ($typed as $field => $val) {
            if (isset(Integrations::DEFS[$code]['fields'][$field]) && trim((string)$val) !== '') {
                $v[$field] = trim((string)$val);
            }
        }
        return $v;
    }

    /** Catat hasil tes; nilai "auto" (mis. property ID Cloudbeds) diisi bila integrasi sudah tersimpan. */
    public static function recordTest(array $biz, string $code, array $res): void
    {
        $db = self::db();
        $db->prepare('INSERT INTO business_integrations (business_id, provider, enabled, last_test_at, last_test_ok, last_test_msg) VALUES (?, ?, 0, NOW(), ?, ?)
            ON DUPLICATE KEY UPDATE last_test_at = NOW(), last_test_ok = VALUES(last_test_ok), last_test_msg = VALUES(last_test_msg)')
            ->execute([(int)$biz['id'], $code, $res['ok'] ? 1 : 0, mb_substr((string)$res['msg'], 0, 300)]);
        if ($res['ok'] && !empty($res['auto'])) {
            $cfg = self::all($biz)[$code]['config'] ?? [];
            $db->prepare('UPDATE business_integrations SET config = ? WHERE business_id = ? AND provider = ?')
                ->execute([json_encode(array_merge($cfg, $res['auto'])), (int)$biz['id'], $code]);
        }
    }
}
