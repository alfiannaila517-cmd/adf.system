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

    /* ---------------- Nilai yang sedang dipakai sistem (database bisnis) ---------------- */

    /** Field yang wajib terisi (selain kunci rahasia) agar integrasi dianggap aktif di sistem. */
    private const LIVE_REQUIRED = ['cloudbeds' => 'property_id', 'email' => 'user', 'fingerprint' => 'cloud_id'];

    /** 'settings:key|enc-x' → ['settings', key, 'enc-x'] ; 'tabel.kolom' → [tabel, kolom, ''] */
    private static function target(string $t): array
    {
        if (str_starts_with($t, 'settings:')) {
            [$key, $enc] = array_pad(explode('|', substr($t, 9), 2), 2, '');
            return ['settings', $key, $enc];
        }
        [$table, $col] = explode('.', $t, 2);
        return [$table, $col, ''];
    }

    private static function readTarget(PDO $db, string $t): ?string
    {
        [$table, $key] = self::target($t);
        try {
            if ($table === 'settings') {
                $st = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
                $st->execute([$key]);
            } else {
                $st = $db->query("SELECT `{$key}` FROM `{$table}` WHERE id = 1");
            }
            $v = $st->fetchColumn();
            return $v === false || $v === null ? null : (string)$v;
        } catch (Throwable) {
            return null; // tabel / kolom belum ada di bisnis ini
        }
    }

    private static function writeTarget(PDO $db, string $t, string $value): void
    {
        [$table, $key] = self::target($t);
        if ($table === 'settings') {
            $st = $db->prepare('SELECT COUNT(*) FROM settings WHERE setting_key = ?');
            $st->execute([$key]);
            $sql = $st->fetchColumn() ? 'UPDATE settings SET setting_value = ? WHERE setting_key = ?' : 'INSERT INTO settings (setting_value, setting_key) VALUES (?, ?)';
            $db->prepare($sql)->execute([$value, $key]);
            return;
        }
        if (!preg_match('/^\w+$/', $table . $key)) {
            throw new InvalidArgumentException('Tujuan tidak valid');
        }
        $db->prepare("UPDATE `{$table}` SET `{$key}` = ? WHERE id = 1")->execute([$value]);
    }

    /**
     * Pengaturan yang SEDANG DIPAKAI sistem bisnis ini (dibaca langsung dari database bisnis), atau null bila belum terhubung.
     * @return array{config:array<string,string>, secrets:array<string,array{set:bool, hint:string, plain:?string}>, flag:?bool, active:bool}
     *   secrets.plain = teks asli bila sistem menyimpannya tanpa enkripsi (dipakai untuk tes), null bila terenkripsi
     */
    public static function live(array $biz, string $code): ?array
    {
        $db = StaffPortal::db($biz);
        $def = Integrations::DEFS[$code] ?? null;
        if (!$db || !$def) {
            return null;
        }
        $config = [];
        $secrets = [];
        foreach ($def['fields'] as $field => [, $kind, $target]) {
            $v = trim((string)self::readTarget($db, $target));
            if ($kind !== 'secret') {
                $config[$field] = $v;
                continue;
            }
            $enc = self::target($target)[2] !== '';
            $secrets[$field] = [
                'set' => $v !== '',
                'hint' => $v === '' ? '' : ($enc ? 'tersimpan (terenkripsi)' : '••••' . (mb_strlen($v) > 8 ? mb_substr($v, -4) : '')),
                'plain' => $enc ? null : $v,
            ];
        }
        $flag = $def['enabled_target'] ? self::readTarget($db, $def['enabled_target']) === '1' : null;
        $req = self::LIVE_REQUIRED[$code] ?? null;
        $active = $secrets && !in_array(false, array_column($secrets, 'set'), true)
            && (!$req || ($config[$req] ?? '') !== '') && $flag !== false;
        return ['config' => $config, 'secrets' => $secrets, 'flag' => $flag, 'active' => $active];
    }

    /**
     * Tulis isian form langsung ke sistem bisnis (yang benar-benar dipakai). Field biasa ditulis apa adanya (form sudah
     * terisi nilai sistem); rahasia: diisi = ganti, kosong = tetap, dicentang hapus = dikosongkan. Rahasia yang dienkripsi
     * sistem (API key Cloudbeds, password email) memakai kunci dari password database sistem yang tidak ada di Console →
     * tidak ditulis; kembalikan daftar labelnya agar admin mengubahnya dari sistem.
     * @return string[] label field yang dilewati
     */
    public static function pushLive(array $biz, string $code, array $input, bool $enabled, array $clear = []): array
    {
        $db = StaffPortal::db($biz) ?? throw new RuntimeException('Bisnis ini belum terhubung ke database sistem.');
        $def = Integrations::DEFS[$code] ?? throw new InvalidArgumentException('Integrasi tidak dikenal');
        $skipped = [];
        foreach ($def['fields'] as $field => [$label, $kind, $target, $opt]) {
            $val = trim((string)($input[$field] ?? ''));
            if ($kind === 'secret') {
                $cleared = in_array($field, $clear, true);
                if (!$cleared && $val === '') {
                    continue;
                }
                if (self::target($target)[2] !== '') {
                    $skipped[] = $label;
                    continue;
                }
                self::writeTarget($db, $target, $cleared ? '' : trim($val, " \t\n\r\0\x0B\"'"));
                continue;
            }
            if ($kind === 'select' && $val !== '' && !array_key_exists($val, (array)$opt)) {
                continue;
            }
            if ($kind === 'number' && $val !== '') {
                $val = (string)max(1, min(65535, (int)$val));
            }
            self::writeTarget($db, $target, mb_substr($val, 0, 2000));
        }
        if ($def['enabled_target']) {
            self::writeTarget($db, $def['enabled_target'], $enabled ? '1' : '0');
        }
        return $skipped;
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
