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

    /**
     * Tanggal patokan & jatuh tempo tagihan bulanan — aturan SAMA dengan sistem bisnis (adfsub_anchor_due_date /
     * adfsub_due_date): tanggal "Jatuh tempo" bila diisi, selain itu tanggal mulai + 1 bulan; diulang tiap bulan.
     * @return array{first:string, day:int}|null
     */
    public static function dueRule(array $sub): ?array
    {
        $ov = (string)($sub['due_date_override'] ?? '');
        $start = (string)($sub['start_date'] ?? '');
        if ($ov !== '' && strtotime($ov)) {
            return ['first' => date('Y-m', strtotime($ov)), 'day' => (int)date('j', strtotime($ov))];
        }
        if ($start !== '' && strtotime($start)) {
            return ['first' => date('Y-m', strtotime(date('Y-m-01', strtotime($start)) . ' +1 month')), 'day' => (int)date('j', strtotime($start))];
        }
        return null;
    }

    public static function dueDate(int $day, string $period): string
    {
        return sprintf('%s-%02d', $period, min(max($day, 1), (int)date('t', strtotime($period . '-01'))));
    }

    /**
     * Tagihan bulanan bisnis bertarif tetap (tanpa tarif per tamu & tanpa buku tagihan sistem) dibuat di Console juga,
     * mengikuti sistem bisnis: bulan berjalan (sejak periode pertama), + bulan depan bila jatuh tempo ≤ 7 hari. Tagihan yang
     * belum dibayar & tidak diedit mengikuti tarif / jatuh tempo terbaru. Return info tagihan berikutnya.
     */
    public static function ensureFlatCharges(int $businessId): ?array
    {
        $sub = self::subscription($businessId);
        if (!$sub || (float)$sub['base_fee'] <= 0 || (float)$sub['per_guest_fee'] > 0 || !empty($sub['ledger_from']) || !($rule = self::dueRule($sub))) {
            return null;
        }
        $db = self::db();
        $periods = [];
        // Hanya bulan berjalan (tidak mundur): bulan-bulan lama mungkin sudah dibayar lewat sistem lama → jangan buat tunggakan palsu
        if (date('Y-m') >= $rule['first']) {
            $periods[] = date('Y-m');
        }
        $next = date('Y-m', strtotime(date('Y-m-01') . ' +1 month'));
        if ($next >= $rule['first'] && strtotime(self::dueDate($rule['day'], $next)) - strtotime(date('Y-m-d')) <= 7 * 86400) {
            $periods[] = $next;
        }
        $fee = (float)$sub['base_fee'];
        $find = $db->prepare('SELECT * FROM subscription_charges WHERE business_id = ? AND ref = ?');
        foreach ($periods as $p) {
            $due = self::dueDate($rule['day'], $p);
            $find->execute([$businessId, $p]);
            $row = $find->fetch();
            if (!$row) {
                $db->prepare("INSERT INTO subscription_charges (business_id, kind, ref, period, base_fee, status, due_date) VALUES (?, 'recurring', ?, ?, ?, 'unpaid', ?)")
                    ->execute([$businessId, $p, $p, $fee, $due]);
            } elseif ($row['status'] === 'unpaid' && empty($row['edited']) && empty($row['locked']) && ((float)$row['base_fee'] !== $fee || $row['due_date'] !== $due)) {
                $db->prepare('UPDATE subscription_charges SET base_fee = ?, due_date = ? WHERE id = ?')->execute([$fee, $due, (int)$row['id']]);
            }
        }
        // Tagihan berikutnya yang belum dibuat (untuk ditampilkan)
        $up = max($rule['first'], $periods ? date('Y-m', strtotime(end($periods) . '-01 +1 month')) : $rule['first']);
        return ['period' => $up, 'due' => self::dueDate($rule['day'], $up), 'amount' => $fee];
    }

    /** Tagihan langganan belum lunas untuk semua bisnis milik client. */
    public static function unpaidChargesOfClient(int $clientId): array
    {
        $st = self::db()->prepare('SELECT ch.*, b.name AS business_name FROM subscription_charges ch JOIN businesses b ON b.id = ch.business_id
            WHERE b.client_id = ? AND ch.status = \'unpaid\' ORDER BY ch.due_date, b.name');
        $st->execute([$clientId]);
        return $st->fetchAll();
    }

    /** Biaya tambahan bulanan aktif per bisnis: [business_id => [rows]] (tabel belum dibuat = kosong). */
    public static function addons(?int $businessId = null): array
    {
        try {
            $sql = 'SELECT * FROM subscription_addons' . ($businessId ? ' WHERE business_id = ' . (int)$businessId : '') . ' ORDER BY name';
            $out = [];
            foreach (self::db()->query($sql)->fetchAll() as $r) {
                $out[(int)$r['business_id']][] = $r;
            }
            return $out;
        } catch (PDOException) {
            return [];
        }
    }

    /**
     * Jatuh tempo berikutnya: tagihan belum lunas paling awal, atau tanggal yang diubah manual,
     * atau tanggal mulai langganan yang diulang tiap bulan. null = belum bisa dihitung.
     */
    public static function nextDue(array $sub, ?string $unpaidDue = null): ?DateTimeImmutable
    {
        if ($unpaidDue) {
            return new DateTimeImmutable($unpaidDue);
        }
        if (!empty($sub['due_date_override'])) {
            return new DateTimeImmutable((string)$sub['due_date_override']);
        }
        if (empty($sub['start_date'])) {
            return null;
        }
        $start = new DateTimeImmutable((string)$sub['start_date']);
        $today = new DateTimeImmutable('today');
        $day = (int)$start->format('j');
        $due = $today->setDate((int)$today->format('Y'), (int)$today->format('n'), min($day, (int)$today->format('t')));
        if ($due < $today) {
            $next = $today->modify('first day of next month');
            $due = $next->setDate((int)$next->format('Y'), (int)$next->format('n'), min($day, (int)$next->format('t')));
        }
        return $due < $start ? $start : $due;
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

    /**
     * Tambah bisnis ke grup, lalu otomatis dibuat di sistem (database, tabel, config, menu & akses) bila bisa.
     * Dipakai halaman Bisnis (tombol ＋ Bisnis di atas) dan halaman grup.
     * @return array{0:int, 1:string, 2:bool} [id bisnis, pesan, berhasil penuh?]
     */
    public static function add(array $client, array $in): array
    {
        $db = self::db();
        $name = trim((string)($in['name'] ?? ''));
        $type = array_key_exists($in['type'] ?? '', self::TYPES) ? (string)$in['type'] : 'other';
        $slug = self::slugify((string)($in['slug'] ?? '') ?: $name);
        if (mb_strlen($name) < 2 || strlen($slug) < 2) {
            throw new RuntimeException('Nama bisnis wajib diisi.');
        }
        if ($db->query('SELECT 1 FROM businesses WHERE slug = ' . $db->quote($slug))->fetchColumn()) {
            throw new RuntimeException("Kode bisnis \"{$slug}\" sudah dipakai, pakai kode lain.");
        }
        $db->prepare('INSERT INTO businesses (client_id, slug, name, type, domain, hosted_elsewhere) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([(int)$client['id'], $slug, mb_substr($name, 0, 150), $type, Legacy::normalizeDomain((string)($in['domain'] ?? '')) ?: null, !empty($in['hosted_elsewhere']) ? 1 : 0]);
        $bid = (int)$db->lastInsertId();
        Auth::audit('business.add', "{$name} → {$client['name']}");
        $msg = "Bisnis {$name} ditambahkan.";
        // Otomatis dibuat juga di sistem (database, tabel, config) — kecuali website / server lain
        $newBiz = self::business($bid);
        if (Provision::can($newBiz)) {
            try {
                $msg .= ' Sistem: ' . implode(' · ', Provision::run($newBiz)) . '.';
                Auth::audit('business.provision', $name);
            } catch (Throwable $e) {
                return [$bid, "{$msg} TAPI gagal membuat di sistem: " . $e->getMessage() . ' — coba lagi dari tab Database & Storage.', false];
            }
        }
        return [$bid, $msg, true];
    }

    /** Kembali ke bawaan jenis bisnis (hapus pilihan tersimpan). */
    public static function resetModules(int $businessId): void
    {
        self::db()->prepare('DELETE FROM business_modules WHERE business_id = ?')->execute([$businessId]);
    }
}
