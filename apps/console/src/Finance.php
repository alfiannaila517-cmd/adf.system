<?php

declare(strict_types=1);

/**
 * Pembukuan ADF (uang masuk & keluar) dari semua sumber, tanpa menghitung dobel:
 *   1. orders (DB Console, status completed)     — pembayaran Pakasir dari sistem klien (SubSync) / impor store
 *   2. payments (DB Console) selain metode pakasir — invoice / tagihan yang ditandai lunas manual (transfer, tunai)
 *                                                    (invoice yang dibayar lewat Pakasir sudah tercatat di orders)
 *   3. orders.json website jualan (completed)     — checkout website & webhook Pakasir; order_id yang sudah ada di 1 dilewati
 *   4. finance_entries                            — catatan manual: uang masuk lain, pengeluaran, tarik dana Pakasir
 */
final class Finance
{
    public const KINDS = ['in' => 'Uang masuk', 'out' => 'Pengeluaran', 'withdraw' => 'Tarik dana (Mayar / Pakasir)'];
    public const CATEGORIES = [
        'in' => ['Langganan', 'Jasa / proyek', 'Website', 'Lainnya'],
        'out' => ['Server / VPS', 'Domain', 'Email & layanan', 'Gaji / honor', 'Iklan / promosi', 'Peralatan', 'Lainnya'],
        'withdraw' => ['Tarik dana Mayar', 'Tarik dana Pakasir'],
    ];

    private static function db(): PDO
    {
        return Auth::db();
    }

    /**
     * Semua baris pada rentang tanggal (Y-m-d … Y-m-d), terbaru di atas.
     * @return list<array{date:string, src:string, title:string, who:string, in:float, out:float, kind:string, entry:?array}>
     *   src: pakasir | website | manual ; kind: in | out | withdraw
     */
    public static function rows(string $from, string $to): array
    {
        $db = self::db();
        $end = $to . ' 23:59:59';
        $rows = [];

        // 1. Pembayaran Pakasir yang tercatat di Console
        $st = $db->prepare("SELECT o.order_id, o.product_title, o.amount, o.name, o.source, COALESCE(o.completed_at, o.created_at) AS at, b.name AS biz
            FROM orders o LEFT JOIN businesses b ON b.id = o.business_id
            WHERE o.status = 'completed' AND COALESCE(o.completed_at, o.created_at) BETWEEN ? AND ?");
        $st->execute([$from, $end]);
        foreach ($st as $o) {
            $rows[] = self::row((string)$o['at'], $o['source'] === 'checkout' ? 'website' : 'pakasir', (string)$o['product_title'], (string)($o['biz'] ?: $o['name']), (float)$o['amount'], 0, 'in');
        }

        // 2. Lunas manual (invoice / tagihan langganan) — yang lewat Pakasir sudah ada di orders
        $st = $db->prepare("SELECT p.amount, p.method, p.paid_at, i.number, i.customer_name, ch.period, b.name AS biz
            FROM payments p
            LEFT JOIN invoices i ON i.id = p.invoice_id
            LEFT JOIN subscription_charges ch ON ch.id = p.charge_id
            LEFT JOIN businesses b ON b.id = COALESCE(p.business_id, i.business_id, ch.business_id)
            WHERE COALESCE(p.method, '') NOT IN ('pakasir', 'mayar') AND p.paid_at BETWEEN ? AND ?");
        $st->execute([$from, $end]);
        foreach ($st as $p) {
            $title = $p['number'] ? 'Invoice ' . $p['number'] : 'Langganan ' . $p['period'];
            $rows[] = self::row((string)$p['paid_at'], 'manual', $title . ($p['method'] ? ' · ' . $p['method'] : ''), (string)($p['biz'] ?: $p['customer_name']), (float)$p['amount'], 0, 'in');
        }

        // 3. Website jualan (orders.json) — lewati yang sudah tercatat di Console
        $known = array_flip($db->query('SELECT order_id FROM orders')->fetchAll(PDO::FETCH_COLUMN));
        foreach (StoreSite::orders() as $o) {
            if (strtolower((string)($o['status'] ?? '')) !== 'completed' || isset($known[(string)($o['order_id'] ?? '')])) {
                continue;
            }
            $ts = strtotime((string)($o['completed_at'] ?? $o['created_at'] ?? ''));
            $d = $ts ? date('Y-m-d H:i:s', $ts) : '';
            if ($d === '' || $d < $from || $d > $end) {
                continue;
            }
            $src = ($o['source'] ?? '') === 'subscription' ? 'pakasir' : 'website';
            $rows[] = self::row($d, $src, (string)($o['product_title'] ?? '-'), (string)($o['name'] ?? ''), (float)($o['amount'] ?? 0), 0, 'in');
        }

        // 4. Catatan manual
        $st = $db->prepare('SELECT * FROM finance_entries WHERE entry_date BETWEEN ? AND ? ORDER BY entry_date, id');
        $st->execute([$from, $to]);
        foreach ($st as $e) {
            $amt = (float)$e['amount'];
            $title = $e['category'] . ($e['note'] ? ' · ' . $e['note'] : '');
            $rows[] = self::row($e['entry_date'] . ' 12:00:00', 'manual', $title, (string)($e['method'] ?? ''), $e['kind'] === 'in' ? $amt : 0, $e['kind'] === 'out' ? $amt : 0, (string)$e['kind'], $e);
        }

        usort($rows, fn ($a, $b) => strcmp($b['date'], $a['date']));
        return $rows;
    }

    private static function row(string $date, string $src, string $title, string $who, float $in, float $out, string $kind, ?array $entry = null): array
    {
        return compact('date', 'src', 'title', 'who', 'in', 'out', 'kind', 'entry');
    }

    /** Uang masuk & keluar per bulan dalam satu tahun: [1..12 => [in, out]] */
    public static function year(int $year): array
    {
        $out = array_fill(1, 12, ['in' => 0.0, 'out' => 0.0]);
        foreach (self::rows("{$year}-01-01", "{$year}-12-31") as $r) {
            $m = (int)substr($r['date'], 5, 2);
            $out[$m]['in'] += $r['in'];
            $out[$m]['out'] += $r['out'];
        }
        return $out;
    }

    /**
     * Perkiraan saldo yang masih di Pakasir = semua pembayaran lewat Pakasir/website − semua penarikan yang dicatat.
     * (Sebelum potongan biaya Pakasir; cocokkan dengan dashboard Pakasir.)
     */
    public static function pakasirBalance(): array
    {
        $in = 0.0;
        foreach (self::rows('2000-01-01', date('Y-m-d')) as $r) {
            if ($r['src'] !== 'manual') {
                $in += $r['in'];
            }
        }
        $wd = (float)self::db()->query("SELECT COALESCE(SUM(amount), 0) FROM finance_entries WHERE kind = 'withdraw'")->fetchColumn();
        return ['in' => $in, 'withdrawn' => $wd, 'balance' => $in - $wd];
    }

    public static function saveEntry(array $in, ?int $id, string $by): void
    {
        $kind = array_key_exists($in['kind'] ?? '', self::KINDS) ? (string)$in['kind'] : 'in';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['entry_date'] ?? '')) ? (string)$in['entry_date'] : date('Y-m-d');
        $amount = (float)preg_replace('/\D/', '', (string)($in['amount'] ?? ''));
        $cat = mb_substr(trim((string)($in['category'] ?? '')), 0, 60) ?: self::CATEGORIES[$kind][0];
        if ($amount <= 0) {
            throw new RuntimeException('Jumlah harus lebih dari 0.');
        }
        $vals = [$date, $kind, $cat, $amount, mb_substr(trim((string)($in['method'] ?? '')), 0, 40) ?: null, mb_substr(trim((string)($in['note'] ?? '')), 0, 300) ?: null];
        if ($id) {
            self::db()->prepare('UPDATE finance_entries SET entry_date = ?, kind = ?, category = ?, amount = ?, method = ?, note = ? WHERE id = ?')->execute([...$vals, $id]);
        } else {
            self::db()->prepare('INSERT INTO finance_entries (entry_date, kind, category, amount, method, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $by]);
        }
    }

    public static function deleteEntry(int $id): void
    {
        self::db()->prepare('DELETE FROM finance_entries WHERE id = ?')->execute([$id]);
    }

    /**
     * Tagihan langganan ditandai lunas / dibatalkan lunas manual dari Console → catat / hapus pembayaran manual-nya,
     * supaya masuk pembukuan (pembayaran lewat Pakasir sudah tercatat sendiri di orders).
     */
    public static function chargeManualPayment(array $charge, bool $paid, float $amount): void
    {
        $db = self::db();
        $db->prepare("DELETE FROM payments WHERE charge_id = ? AND gateway = 'manual'")->execute([(int)$charge['id']]);
        if ($paid) {
            $db->prepare("INSERT INTO payments (charge_id, business_id, amount, method, gateway, paid_at) VALUES (?, ?, ?, 'manual', 'manual', ?)")
                ->execute([(int)$charge['id'], (int)$charge['business_id'], $amount, $charge['paid_at'] ?: date('Y-m-d H:i:s')]);
        }
    }
}
