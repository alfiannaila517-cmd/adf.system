<?php

declare(strict_types=1);

/**
 * Data client & bisnis untuk halaman Console (query bersama, label jenis bisnis, format rupiah).
 */
final class Biz
{
    /** Jenis bisnis: kode => [label, ikon] */
    public const TYPES = [
        'hotel' => ['Hotel', '🏨'],
        'restaurant' => ['Restoran / Kafe', '🍽️'],
        'warehouse' => ['Gudang', '📦'],
        'website' => ['Website', '🌐'],
        'travel' => ['Travel', '🧭'],
        'manufacture' => ['Manufaktur', '🏭'],
        'contractor' => ['Kontraktor', '🏗️'],
        'other' => ['Lainnya', '▦'],
    ];

    public const STATUS = ['active' => 'Aktif', 'suspended' => 'Ditangguhkan', 'archived' => 'Arsip'];

    public static function db(): PDO
    {
        return Auth::db();
    }

    public static function type(string $code): array
    {
        return self::TYPES[$code] ?? self::TYPES['other'];
    }

    public static function rp(float|int|string|null $n): string
    {
        return 'Rp ' . number_format((float)$n, 0, ',', '.');
    }

    /** Total satu tagihan langganan: biaya dasar + (tamu + carry) × tarif + penyesuaian (sama dengan store lama). */
    public static function chargeTotal(array $c): float
    {
        return round((float)$c['base_fee'] + ((int)$c['guest_count'] + (int)$c['carry_guests']) * (float)$c['per_guest_fee'] + (float)$c['adjustment'], 2);
    }

    public static function client(int $id): ?array
    {
        $st = self::db()->prepare('SELECT * FROM clients WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function business(int $id): ?array
    {
        $st = self::db()->prepare('SELECT b.*, c.name AS client_name FROM businesses b JOIN clients c ON c.id = b.client_id WHERE b.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Bisnis milik client + ringkasan langganan & tagihan belum lunas. */
    public static function businessesOf(int $clientId): array
    {
        $st = self::db()->prepare('SELECT b.*, s.id AS sub_id, s.base_fee, s.per_guest_fee, s.locked, s.client_key,
                (SELECT COUNT(*) FROM subscription_charges ch WHERE ch.business_id = b.id AND ch.status = \'unpaid\') AS unpaid_count,
                (SELECT MIN(ch.due_date) FROM subscription_charges ch WHERE ch.business_id = b.id AND ch.status = \'unpaid\') AS next_due
            FROM businesses b LEFT JOIN subscriptions s ON s.business_id = b.id WHERE b.client_id = ? ORDER BY b.status = \'archived\', b.name');
        $st->execute([$clientId]);
        return $st->fetchAll();
    }

    public static function subscription(int $businessId): ?array
    {
        $st = self::db()->prepare('SELECT * FROM subscriptions WHERE business_id = ?');
        $st->execute([$businessId]);
        return $st->fetch() ?: null;
    }

    public static function charges(int $businessId, int $limit = 24): array
    {
        $st = self::db()->prepare('SELECT * FROM subscription_charges WHERE business_id = ? ORDER BY period DESC, ref DESC LIMIT ' . (int)$limit);
        $st->execute([$businessId]);
        return $st->fetchAll();
    }

    /** Tagihan langganan belum lunas untuk semua bisnis milik client. */
    public static function unpaidChargesOfClient(int $clientId): array
    {
        $st = self::db()->prepare('SELECT ch.*, b.name AS business_name FROM subscription_charges ch JOIN businesses b ON b.id = ch.business_id
            WHERE b.client_id = ? AND ch.status = \'unpaid\' ORDER BY ch.due_date, b.name');
        $st->execute([$clientId]);
        return $st->fetchAll();
    }

    public static function slugify(string $s): string
    {
        $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
        return substr($s, 0, 60);
    }

    /**
     * Modul & fitur yang berlaku untuk bisnis: nilai tersimpan, atau bawaan jenis bisnis bila belum pernah diatur.
     * @return array{module: array<string,bool>, feature: array<string,bool>, custom: bool}
     */
    public static function modules(array $biz): array
    {
        $st = self::db()->prepare('SELECT kind, code, enabled FROM business_modules WHERE business_id = ?');
        $st->execute([(int)$biz['id']]);
        $saved = ['module' => [], 'feature' => []];
        foreach ($st->fetchAll() as $r) {
            $saved[$r['kind']][$r['code']] = (bool)$r['enabled'];
        }
        $type = (string)$biz['type'];
        $out = ['module' => [], 'feature' => [], 'custom' => (bool)($saved['module'] || $saved['feature'])];
        foreach (Catalog::MODULES as $code => $m) {
            if (Catalog::moduleAvailable($code, $type)) {
                $out['module'][$code] = $m[4] ? true : ($saved['module'][$code] ?? Catalog::moduleDefault($code, $type));
            }
        }
        foreach (array_keys(Catalog::FEATURES) as $code) {
            if (Catalog::featureAvailable($code, $type)) {
                $out['feature'][$code] = $saved['feature'][$code] ?? Catalog::featureDefault($code, $type);
            }
        }
        return $out;
    }

    /** Simpan pilihan modul & fitur (semua yang tersedia untuk jenisnya disimpan eksplisit; modul wajib selalu nyala). */
    public static function saveModules(array $biz, array $modules, array $features): void
    {
        $type = (string)$biz['type'];
        $db = self::db();
        $own = !$db->inTransaction(); // ikut transaksi pemanggil bila ada (mis. impor sistem lama)
        if ($own) {
            $db->beginTransaction();
        }
        $db->prepare('DELETE FROM business_modules WHERE business_id = ?')->execute([(int)$biz['id']]);
        $ins = $db->prepare('INSERT INTO business_modules (business_id, kind, code, enabled) VALUES (?, ?, ?, ?)');
        foreach (Catalog::MODULES as $code => $m) {
            if (Catalog::moduleAvailable($code, $type)) {
                $ins->execute([(int)$biz['id'], 'module', $code, ($m[4] || in_array($code, $modules, true)) ? 1 : 0]);
            }
        }
        foreach (array_keys(Catalog::FEATURES) as $code) {
            if (Catalog::featureAvailable($code, $type)) {
                $ins->execute([(int)$biz['id'], 'feature', $code, in_array($code, $features, true) ? 1 : 0]);
            }
        }
        if ($own) {
            $db->commit();
        }
    }

    /** Kembali ke bawaan jenis bisnis (hapus pilihan tersimpan). */
    public static function resetModules(int $businessId): void
    {
        self::db()->prepare('DELETE FROM business_modules WHERE business_id = ?')->execute([$businessId]);
    }
}
