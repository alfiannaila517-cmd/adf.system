<?php

declare(strict_types=1);

/**
 * Invoice Console (pengganti invoice adfsystem.store): bebas (siapa pun), atau bulanan per grup (diisi dari Tagihan).
 * Data di tabel invoices + invoice_items; pembayaran dicatat di payments. Pengaturan (profil perusahaan, rekening,
 * syarat) di console_settings 'invoice'. Tampilan dokumen = sama persis dengan invoice Store (A4, siap cetak / PDF).
 * Status tersimpan: draft | unpaid | paid | cancelled — "jatuh tempo" dihitung dari unpaid + due_date lewat.
 */
final class Invoice
{
    public const STATUS = ['draft' => 'Draft', 'unpaid' => 'Belum dibayar', 'overdue' => 'Jatuh tempo', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan'];

    private static function db(): PDO
    {
        return Biz::db();
    }

    /** Pengaturan invoice + nilai awal. */
    public static function settings(): array
    {
        $defaults = [
            'company_name' => 'ADF System', 'tagline' => 'Sistem manajemen bisnis', 'address' => '', 'email' => '', 'whatsapp' => '',
            'website' => 'adfsystem.store', 'prefix' => 'INV', 'default_due_days' => 7, 'default_tax_percent' => 0,
            'banks' => [['bank' => '', 'number' => '', 'holder' => '']],
            'payment_note' => 'Mohon cantumkan nomor invoice pada berita transfer dan kirim bukti pembayaran ke email / WhatsApp kami.',
            'terms' => "Pembayaran paling lambat pada tanggal jatuh tempo.\nInvoice ini sah dan dibuat secara elektronik tanpa tanda tangan basah.\nBiaya yang sudah dibayarkan tidak dapat dikembalikan, kecuali disepakati tertulis.",
            'signer_name' => '', 'signer_title' => 'Developer',
        ];
        $saved = json_decode((string)(self::db()->query("SELECT setting_value FROM console_settings WHERE setting_key = 'invoice'")->fetchColumn() ?: ''), true);
        $merged = array_merge($defaults, array_intersect_key(is_array($saved) ? $saved : [], $defaults));
        if (empty($merged['banks']) || !is_array($merged['banks'])) {
            $merged['banks'] = $defaults['banks'];
        }
        return $merged;
    }

    public static function saveSettings(array $s): void
    {
        self::db()->prepare("INSERT INTO console_settings (setting_key, setting_value) VALUES ('invoice', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /** Nomor berikutnya: PREFIX-YYYYMM-001 (urut per bulan terbit). */
    public static function nextNumber(string $issueDate = ''): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', (string)self::settings()['prefix']) ?: 'INV';
        $ym = date('Ym', strtotime($issueDate ?: 'now') ?: time());
        $st = self::db()->prepare('SELECT number FROM invoices WHERE number LIKE ?');
        $st->execute([$prefix . '-' . $ym . '-%']);
        $max = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
            if (preg_match('/-(\d+)$/', (string)$n, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return $prefix . '-' . $ym . '-' . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /** Invoice lengkap dengan item; null bila tidak ada. */
    public static function find(int $id): ?array
    {
        $st = self::db()->prepare('SELECT * FROM invoices WHERE id = ?');
        $st->execute([$id]);
        $inv = $st->fetch();
        return $inv ? self::withItems($inv) : null;
    }

    /** Untuk halaman publik: draft tidak bisa dibuka lewat link. */
    public static function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
            return null;
        }
        $st = self::db()->prepare("SELECT * FROM invoices WHERE token = ? AND status <> 'draft'");
        $st->execute([$token]);
        $inv = $st->fetch();
        return $inv ? self::withItems($inv) : null;
    }

    private static function withItems(array $inv): array
    {
        $st = self::db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort, id');
        $st->execute([(int)$inv['id']]);
        $inv['items'] = $st->fetchAll();
        return $inv;
    }

    /** @return array{subtotal:float,discount:float,tax:float,tax_percent:float,total:float} */
    public static function totals(array $inv): array
    {
        $subtotal = 0.0;
        foreach ($inv['items'] ?? [] as $it) {
            $subtotal += round((float)$it['qty'] * (float)$it['unit_price'], 2);
        }
        $dv = max(0.0, (float)($inv['discount_value'] ?? 0));
        $discount = ($inv['discount_type'] ?? 'rp') === 'percent' ? round($subtotal * min(100.0, $dv) / 100, 2) : min($dv, $subtotal);
        $base = max(0.0, $subtotal - $discount);
        $taxPct = max(0.0, (float)($inv['tax_percent'] ?? 0));
        $tax = round($base * $taxPct / 100, 2);
        return ['subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'tax_percent' => $taxPct, 'total' => round($base + $tax, 2)];
    }

    public static function displayStatus(array $inv): string
    {
        $st = (string)$inv['status'];
        return $st === 'unpaid' && !empty($inv['due_date']) && $inv['due_date'] < date('Y-m-d') ? 'overdue' : $st;
    }

    public static function tone(string $status): string
    {
        return ['paid' => 'ok', 'unpaid' => 'warn', 'overdue' => 'bad'][$status] ?? '';
    }

    /**
     * Simpan dari form (baru bila $id = 0). Mengembalikan id invoice.
     * @throws RuntimeException pesan untuk pengguna
     */
    public static function saveFromPost(array $p, int $id = 0): int
    {
        $old = $id ? self::find($id) : null;
        // Rupiah format Indonesia: titik = ribuan, koma = desimal ("150.000" → 150000)
        $num = static fn ($v) => (float)str_replace(['.', ','], ['', '.'], preg_replace('/[^\d,.\-]/', '', (string)$v));
        $cust = [];
        foreach (['name', 'company', 'email', 'phone', 'address'] as $k) {
            $cust[$k] = trim((string)($p['cust_' . $k] ?? ''));
        }
        if ($cust['name'] === '') {
            throw new RuntimeException('Nama pelanggan wajib diisi.');
        }
        if ($cust['email'] !== '' && !filter_var($cust['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Format email pelanggan tidak valid.');
        }
        $items = [];
        foreach ((array)($p['item_desc'] ?? []) as $i => $desc) {
            $desc = trim((string)$desc);
            if ($desc === '') {
                continue;
            }
            $qty = (float)str_replace(',', '.', (string)($p['item_qty'][$i] ?? 1));
            $price = $num($p['item_price'][$i] ?? 0);
            if ($qty <= 0 || $price < 0) {
                throw new RuntimeException("Jumlah harus lebih dari 0 dan harga tidak boleh negatif (baris: {$desc}).");
            }
            $items[] = [mb_substr($desc, 0, 240), mb_substr(trim((string)($p['item_note'][$i] ?? '')), 0, 240), $qty, $price, ((int)($p['item_biz'][$i] ?? 0)) ?: null];
        }
        if (!$items) {
            throw new RuntimeException('Tambahkan minimal satu baris tagihan.');
        }
        $issue = (string)($p['issue_date'] ?? '');
        $due = (string)($p['due_date'] ?? '');
        foreach ([$issue, $due] as $d) {
            $o = DateTime::createFromFormat('Y-m-d', $d);
            if (!$o || $o->format('Y-m-d') !== $d) {
                throw new RuntimeException('Tanggal terbit dan jatuh tempo wajib diisi dengan benar.');
            }
        }
        if ($due < $issue) {
            throw new RuntimeException('Jatuh tempo tidak boleh lebih awal dari tanggal terbit.');
        }
        $status = in_array($p['status'] ?? '', ['draft', 'unpaid', 'paid'], true) ? $p['status'] : 'unpaid';
        $number = mb_substr(trim((string)($p['number'] ?? '')), 0, 40) ?: ($old['number'] ?? self::nextNumber($issue));
        $dup = self::db()->prepare('SELECT 1 FROM invoices WHERE number = ? AND id <> ?');
        $dup->execute([$number, $id]);
        if ($dup->fetchColumn()) {
            throw new RuntimeException("Nomor invoice {$number} sudah dipakai.");
        }
        $clientId = ((int)($p['client_id'] ?? 0)) ?: ($old['client_id'] ?? null);
        // Ditagihkan lewat sistem bisnis (muncul di menu Langganan sistem itu & bisa dibayar di sana): hanya bisnis berlangganan
        $billBiz = (int)($p['bill_business_id'] ?? 0);
        if ($billBiz && !Biz::subscription($billBiz)) {
            throw new RuntimeException('Bisnis yang dipilih belum punya langganan, jadi tagihan tidak bisa tampil di sistemnya.');
        }
        $kind = ($p['kind'] ?? '') === 'monthly' || ($old['kind'] ?? '') === 'monthly' ? 'monthly' : ($billBiz ? 'manual' : 'free');
        $row = [
            $number, $kind, $clientId, $billBiz ?: null,
            $cust['name'], $cust['company'] ?: null, $cust['email'] ?: null, $cust['phone'] ?: null, $cust['address'] ?: null,
            $issue, $due, ($p['discount_type'] ?? 'rp') === 'percent' ? 'percent' : 'rp',
            max(0.0, ($p['discount_type'] ?? 'rp') === 'percent' ? (float)str_replace(',', '.', (string)($p['discount_value'] ?? 0)) : $num($p['discount_value'] ?? 0)),
            max(0.0, min(100.0, (float)str_replace(',', '.', (string)($p['tax_percent'] ?? 0)))), trim((string)($p['notes'] ?? '')) ?: null, $status,
        ];
        $db = self::db();
        $db->beginTransaction();
        try {
            if ($old) {
                $db->prepare('UPDATE invoices SET number = ?, kind = ?, client_id = ?, business_id = ?, customer_name = ?, customer_company = ?, customer_email = ?, customer_phone = ?, customer_address = ?,
                        issue_date = ?, due_date = ?, discount_type = ?, discount_value = ?, tax_percent = ?, notes = ?, status = ?,
                        paid_at = ' . ($status === 'paid' ? 'COALESCE(paid_at, NOW())' : 'NULL') . ' WHERE id = ?')->execute([...$row, $id]);
                $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            } else {
                $db->prepare('INSERT INTO invoices (number, kind, client_id, business_id, customer_name, customer_company, customer_email, customer_phone, customer_address,
                        issue_date, due_date, discount_type, discount_value, tax_percent, notes, status, paid_at, token)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . ($status === 'paid' ? 'NOW()' : 'NULL') . ', ?)')
                    ->execute([...$row, bin2hex(random_bytes(12))]);
                $id = (int)$db->lastInsertId();
            }
            $ins = $db->prepare('INSERT INTO invoice_items (invoice_id, business_id, description, note, qty, unit_price, sort) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($items as $i => [$desc, $note, $qty, $price, $biz]) {
                $ins->execute([$id, $biz, $desc, $note ?: null, $qty, $price, $i]);
            }
            if ($status === 'paid' && ($old['status'] ?? '') !== 'paid') {
                self::recordPayment($id, 'manual');
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $id;
    }

    /** Ubah status (lunas / batal / kembali belum dibayar). Lunas → catat pembayaran sebesar total. */
    public static function setStatus(int $id, string $status, string $method = 'transfer'): void
    {
        $inv = self::find($id) ?? throw new RuntimeException('Invoice tidak ditemukan.');
        if (!in_array($status, ['unpaid', 'paid', 'cancelled'], true)) {
            throw new RuntimeException('Status tidak dikenal.');
        }
        self::db()->prepare('UPDATE invoices SET status = ?, paid_at = ' . ($status === 'paid' ? 'COALESCE(paid_at, NOW())' : 'NULL') . ' WHERE id = ?')->execute([$status, $id]);
        if ($status === 'paid' && $inv['status'] !== 'paid') {
            self::recordPayment($id, $method);
        }
    }

    private static function recordPayment(int $id, string $method): void
    {
        $inv = self::find($id);
        self::db()->prepare("INSERT INTO payments (invoice_id, amount, method, gateway, paid_at) VALUES (?, ?, ?, 'manual', NOW())")
            ->execute([$id, self::totals($inv)['total'], mb_substr($method, 0, 40)]);
    }

    /**
     * Kirim tagihan ke email pelanggan: ringkasan + tombol "Lihat & bayar" (link publik; bayar online bila Mayar aktif).
     * Draft diterbitkan dulu (jadi Belum dibayar). Mencatat sent_at. @throws RuntimeException pesan untuk pengguna
     */
    public static function sendEmail(int $id): string
    {
        $inv = self::find($id) ?? throw new RuntimeException('Invoice tidak ditemukan.');
        $to = trim((string)$inv['customer_email']);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email pelanggan belum diisi / tidak valid — isi di Ubah invoice, atau kirim lewat WhatsApp.');
        }
        if (in_array($inv['status'], ['paid', 'cancelled'], true)) {
            throw new RuntimeException('Invoice sudah ' . ($inv['status'] === 'paid' ? 'lunas' : 'dibatalkan') . '.');
        }
        if ($inv['status'] === 'draft') {
            self::db()->prepare("UPDATE invoices SET status = 'unpaid' WHERE id = ?")->execute([$id]);
            $inv['status'] = 'unpaid';
        }
        $s = self::settings();
        $e = static fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $total = self::totals($inv)['total'];
        $url = self::publicUrl($inv);
        $lines = '';
        foreach ($inv['items'] as $it) {
            $lines .= '<tr><td style="padding:6px 0;color:#334155">' . $e($it['description']) . '</td><td style="padding:6px 0;text-align:right;color:#334155">'
                . $e(self::rp((float)$it['qty'] * (float)$it['unit_price'])) . '</td></tr>';
        }
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;color:#0f172a">'
            . '<h2 style="margin:0 0 4px">' . $e($s['company_name']) . '</h2><p style="margin:0 0 18px;color:#64748b">Tagihan ' . $e($inv['number']) . '</p>'
            . '<p>Halo ' . $e($inv['customer_name']) . ',</p><p>berikut tagihan dari kami:</p>'
            . '<table style="width:100%;border-collapse:collapse;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;margin:10px 0">' . $lines . '</table>'
            . '<p style="font-size:18px;margin:12px 0"><b>Total ' . $e(self::rp($total)) . '</b><br><span style="font-size:13px;color:#64748b">Jatuh tempo ' . $e(self::date($inv['due_date'])) . '</span></p>'
            . '<p style="margin:22px 0"><a href="' . $e($url) . '" style="background:#ff6a1a;color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:700;display:inline-block">Lihat &amp; bayar invoice</a></p>'
            . '<p style="font-size:12px;color:#64748b">Atau buka: ' . $e($url) . '</p>'
            . '<p style="font-size:12px;color:#94a3b8;margin-top:24px">' . $e($s['company_name']) . ($s['whatsapp'] ? ' · WA ' . $e($s['whatsapp']) : '') . ($s['email'] ? ' · ' . $e($s['email']) : '') . '</p></div>';
        if (!Mailer::send($to, "Tagihan {$inv['number']} · " . self::rp($total), $html, (string)$s['company_name'])) {
            throw new RuntimeException('Email gagal dikirim: ' . Mailer::lastError());
        }
        self::db()->prepare('UPDATE invoices SET sent_at = NOW() WHERE id = ?')->execute([$id]);
        return $to;
    }

    public static function delete(int $id): void
    {
        self::db()->prepare("DELETE FROM invoices WHERE id = ? AND status IN ('draft', 'cancelled')")->execute([$id]);
    }

    /**
     * Baris tagihan bulanan satu grup: paket tiap bisnis aktif + biaya tambahan aktif.
     * @return array<int, array{desc:string, note:string, qty:float, unit_price:float, business_id:int}>
     */
    public static function monthlyLines(int $clientId, string $period): array
    {
        $monthName = self::date($period . '-01', false);
        $lines = [];
        $addons = Biz::addons();
        foreach (Biz::businessesOf($clientId) as $b) {
            if ($b['status'] !== 'active') {
                continue;
            }
            [$tLabel] = Biz::type((string)$b['type']);
            if ($b['sub_id'] && (float)$b['base_fee'] > 0) {
                $lines[] = ['desc' => "Langganan {$b['name']}", 'note' => "Paket {$tLabel} · {$monthName}", 'qty' => 1, 'unit_price' => (float)$b['base_fee'], 'business_id' => (int)$b['id']];
            }
            foreach ($addons[(int)$b['id']] ?? [] as $a) {
                if ($a['active']) {
                    $lines[] = ['desc' => $a['name'], 'note' => "Tambahan {$b['name']} · {$monthName}", 'qty' => 1, 'unit_price' => (float)$a['monthly_fee'], 'business_id' => (int)$b['id']];
                }
            }
        }
        return $lines;
    }

    public static function publicUrl(array $inv): string
    {
        return 'https://' . ($_SERVER['HTTP_HOST'] ?? 'console.adfsystem.store') . '/invoice.php?t=' . $inv['token'];
    }

    public static function rp(float|int|string|null $n): string
    {
        return 'Rp ' . number_format((float)$n, 0, ',', '.');
    }

    /** 21 Oktober 2026 (atau "Oktober 2026" tanpa tanggal). */
    public static function date(?string $d, bool $withDay = true): string
    {
        $t = strtotime((string)$d);
        if (!$t) {
            return '-';
        }
        $m = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        return ($withDay ? date('j', $t) . ' ' : '') . $m[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
    }

    /** Terbilang bahasa Indonesia (bilangan bulat). */
    public static function terbilang(int $n): string
    {
        $s = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
        if ($n === 0) return 'nol';
        if ($n < 12) return $s[$n];
        if ($n < 20) return self::terbilang($n - 10) . ' belas';
        if ($n < 100) return self::terbilang(intdiv($n, 10)) . ' puluh' . ($n % 10 ? ' ' . self::terbilang($n % 10) : '');
        if ($n < 200) return 'seratus' . ($n - 100 ? ' ' . self::terbilang($n - 100) : '');
        if ($n < 1000) return self::terbilang(intdiv($n, 100)) . ' ratus' . ($n % 100 ? ' ' . self::terbilang($n % 100) : '');
        if ($n < 2000) return 'seribu' . ($n - 1000 ? ' ' . self::terbilang($n - 1000) : '');
        foreach ([1000000000000 => 'triliun', 1000000000 => 'miliar', 1000000 => 'juta', 1000 => 'ribu'] as $div => $name) {
            if ($n >= $div) {
                return self::terbilang(intdiv($n, $div)) . ' ' . $name . ($n % $div ? ' ' . self::terbilang($n % $div) : '');
            }
        }
        return '';
    }

    /** Lambang ADF (huruf A segitiga) — sama dengan invoice Store. */
    private static function markSvg(int $size, string $fill, string $accent): string
    {
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            . '<path d="M50 9 91 86H71L50 46 29 86H9L50 9Z" fill="' . $fill . '"/><path d="M36 70h28" stroke="' . $accent . '" stroke-width="9" stroke-linecap="round"/></svg>';
    }

    /**
     * Dokumen invoice A4 (sama dengan Store). $opts: actions (HTML tombol admin), flash, public (true = halaman pelanggan).
     */
    public static function render(array $inv, array $opts = []): void
    {
        $settings = self::settings();
        $e = static fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $public = !empty($opts['public']);
        $t = self::totals($inv);
        $status = self::displayStatus($inv);
        $banks = array_values(array_filter((array)$settings['banks'], static fn ($b) => trim((string)($b['number'] ?? '')) !== ''));
        $terbilang = ucfirst(self::terbilang((int)floor($t['total']))) . ' rupiah';
        $contact = array_filter([(string)$settings['email'], (string)$settings['whatsapp'], (string)$settings['website']]);
        $tone = ['paid' => 'ok', 'unpaid' => 'warn', 'overdue' => 'bad'][$status] ?? 'gray';
        $stamp = ['paid' => 'LUNAS', 'draft' => 'DRAFT', 'cancelled' => 'BATAL', 'overdue' => 'JATUH TEMPO'][$status] ?? '';
        $fmtNum = static fn ($v) => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',');
        ?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e('Invoice ' . $inv['number'] . ' - ' . $inv['customer_name']) ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --ink: #0b0e14; --ink-2: #334155; --muted: #64748b; --line: #e6e9ef; --soft: #f7f8fb; --acc: #ff6a1a; --acc-soft: #fff1e8; --ok: #15803d; --ok-bg: #dcfce7; --warn: #b45309; --warn-bg: #fef3c7; --bad: #b91c1c; --bad-bg: #fee2e2; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', system-ui, sans-serif; background: #e9ebf0; color: var(--ink); font-size: 12px; line-height: 1.55; padding: 24px 12px 96px; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-variant-numeric: tabular-nums; }
        .page { position: relative; width: 794px; min-height: 1123px; margin: 0 auto; background: #fff; box-shadow: 0 2px 6px rgba(0, 0, 0, .06), 0 28px 60px -28px rgba(0, 0, 0, .3); display: flex; flex-direction: column; overflow: hidden; }
        .topband { height: 10px; background: linear-gradient(90deg, var(--ink) 0 72%, var(--acc) 72% 100%); }
        .inner { position: relative; z-index: 2; flex: 1; padding: 34px 46px 0; }
        .wm { position: absolute; z-index: 1; top: 52%; left: 50%; width: 600px; transform: translate(-50%, -50%) rotate(-16deg); text-align: center; opacity: .095; pointer-events: none; user-select: none; }
        .wm .mk { display: block; margin: 0 auto; }
        .wm .tx { font-weight: 800; font-size: 74px; letter-spacing: .06em; line-height: 1; margin-top: 8px; color: var(--ink); }
        .wm .tx b { color: var(--acc); font-weight: 800; }
        .wm .sub { font-weight: 700; font-size: 20px; letter-spacing: .5em; color: var(--ink); margin-top: 10px; }
        .stamp { position: absolute; z-index: 1; top: 40%; right: 52px; transform: rotate(-14deg); border: 5px double currentColor; border-radius: 14px; padding: 6px 26px; font-weight: 800; font-size: 46px; letter-spacing: .14em; line-height: 1.1; opacity: .13; pointer-events: none; }
        .stamp.ok { color: var(--ok); } .stamp.bad { color: var(--bad); } .stamp.gray { color: #475569; } .stamp.warn { color: var(--warn); }
        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
        .brand { display: flex; gap: 14px; align-items: center; }
        .brand-mark { width: 56px; height: 56px; border-radius: 14px; background: var(--ink); display: grid; place-items: center; flex-shrink: 0; overflow: hidden; }
        .brand-mark img { width: 100%; height: 100%; object-fit: contain; }
        .brand h1 { font-size: 22px; font-weight: 800; letter-spacing: -.02em; line-height: 1.1; }
        .brand h1 span { color: var(--acc); }
        .brand .tag { font-size: 10.5px; color: var(--muted); margin-top: 2px; }
        .brand .addr { font-size: 10px; color: var(--muted); margin-top: 6px; max-width: 280px; line-height: 1.45; }
        .doc { text-align: right; }
        .doc .t { font-size: 34px; font-weight: 800; letter-spacing: .2em; margin-right: -.2em; line-height: 1; }
        .doc .t::after { content: ''; display: block; width: 64px; height: 4px; background: var(--acc); border-radius: 3px; margin: 8px 0 0 auto; }
        .doc .no { margin-top: 10px; font-size: 13px; font-weight: 700; color: var(--ink-2); }
        .pill { display: inline-block; margin-top: 8px; padding: 3px 12px; border-radius: 999px; font-size: 10px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        .pill.ok { background: var(--ok-bg); color: var(--ok); } .pill.warn { background: var(--warn-bg); color: var(--warn); } .pill.bad { background: var(--bad-bg); color: var(--bad); } .pill.gray { background: #e8ebf0; color: #475569; }
        .parties { display: grid; grid-template-columns: 1.25fr 1fr; gap: 14px; margin: 26px 0 20px; }
        .card { border: 1px solid var(--line); border-radius: 12px; padding: 13px 16px; background: rgba(255, 255, 255, .82); }
        .card h3 { font-size: 9px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; color: var(--acc); padding-bottom: 7px; margin-bottom: 9px; border-bottom: 1px solid var(--acc-soft); }
        .card .nm { font-size: 15px; font-weight: 800; line-height: 1.3; }
        .card .co { font-size: 12px; font-weight: 600; color: var(--ink-2); margin-top: 1px; }
        .card .ln { font-size: 11px; color: var(--muted); margin-top: 3px; white-space: pre-line; }
        .kv { display: grid; grid-template-columns: 96px 1fr; gap: 5px 10px; font-size: 11.5px; }
        .kv span:nth-child(odd) { color: var(--muted); } .kv span:nth-child(even) { font-weight: 600; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items thead th { background: var(--ink); color: #fff; font-size: 9.5px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; padding: 10px 12px; text-align: left; border-bottom: 3px solid var(--acc); }
        table.items thead th:first-child { border-radius: 9px 0 0 0; } table.items thead th:last-child { border-radius: 0 9px 0 0; }
        table.items td { padding: 11px 12px; border-bottom: 1px solid var(--line); vertical-align: top; font-size: 12px; }
        table.items tbody tr { break-inside: avoid; }
        table.items tbody tr:nth-child(even) td { background: rgba(247, 248, 251, .85); }
        table.items .d { font-weight: 600; } table.items .n { display: block; margin-top: 2px; font-size: 10.5px; color: var(--muted); font-weight: 400; }
        table.items .r { text-align: right; white-space: nowrap; } table.items .c { text-align: center; }
        .sumrow { display: grid; grid-template-columns: 1fr 290px; gap: 22px; margin-top: 16px; align-items: start; }
        .words { background: var(--acc-soft); border-left: 3px solid var(--acc); border-radius: 0 10px 10px 0; padding: 10px 14px; font-size: 10.5px; color: #7a3410; }
        .words small { display: block; font-size: 8.5px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: var(--acc); margin-bottom: 3px; }
        .words i { font-style: italic; font-weight: 600; }
        .totals { border: 1px solid var(--line); border-radius: 12px; overflow: hidden; background: #fff; }
        .totals .row { display: flex; justify-content: space-between; gap: 10px; padding: 8px 14px; font-size: 12px; }
        .totals .row + .row { border-top: 1px solid var(--line); }
        .totals .row span:first-child { color: var(--muted); } .totals .row span:last-child { font-weight: 600; white-space: nowrap; }
        .totals .disc span:last-child { color: var(--bad); }
        .totals .grand { background: var(--ink); padding: 12px 14px; }
        .totals .grand span:first-child { color: #cbd5e1; font-size: 11px; font-weight: 700; letter-spacing: .12em; }
        .totals .grand span:last-child { color: #fff; font-size: 16px; font-weight: 800; }
        .totals .grand span:last-child em { font-style: normal; color: var(--acc); }
        .pay { margin-top: 22px; }
        .pay h3, .sec h3 { font-size: 9px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; color: var(--acc); margin-bottom: 8px; }
        .banks { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; }
        .bank { border: 1px solid var(--line); border-radius: 11px; padding: 10px 14px; background: var(--soft); }
        .bank b { display: block; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-2); }
        .bank .num { font-size: 16px; font-weight: 800; letter-spacing: .06em; margin: 3px 0 1px; }
        .bank small { font-size: 10.5px; color: var(--muted); }
        .bank-missing { border: 1.5px dashed #f59e0b; background: #fffbeb; color: #92400e; border-radius: 11px; padding: 10px 14px; font-size: 11.5px; }
        .bank-missing a { color: #c2410c; font-weight: 700; }
        .pay .pnote { margin-top: 8px; font-size: 10.5px; color: var(--muted); }
        .cols2 { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin-top: 20px; }
        .sec .tx { font-size: 10.5px; color: var(--ink-2); white-space: pre-line; line-height: 1.6; }
        .sign { margin-top: 22px; display: flex; justify-content: flex-end; }
        .sign > div { width: 220px; text-align: center; font-size: 10.5px; color: var(--muted); }
        .sign .sp { height: 54px; }
        .sign .ln { border-top: 1px solid var(--ink); padding-top: 5px; font-weight: 700; color: var(--ink); font-size: 11.5px; }
        .foot { margin-top: 26px; background: var(--ink); color: #cbd5e1; padding: 15px 46px 17px; text-align: center; position: relative; z-index: 2; }
        .foot::before { content: ''; position: absolute; left: 0; top: 0; width: 28%; height: 3px; background: var(--acc); }
        .foot .ty { color: #fff; font-weight: 700; font-size: 12.5px; }
        .foot .ct { font-size: 10px; margin-top: 3px; letter-spacing: .02em; }
        .flash { width: 794px; max-width: 100%; margin: 0 auto 12px; padding: 10px 16px; border-radius: 10px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857; font-weight: 600; font-size: 12.5px; }
        .flash.bad { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
        .actions { position: fixed; bottom: 18px; left: 50%; transform: translateX(-50%); z-index: 50; display: flex; gap: 8px; padding: 8px; border-radius: 14px; background: rgba(255, 255, 255, .92); backdrop-filter: blur(10px); box-shadow: 0 12px 30px -10px rgba(0, 0, 0, .4); flex-wrap: wrap; justify-content: center; max-width: calc(100vw - 24px); }
        .actions a, .actions button { border: 0; cursor: pointer; font: inherit; font-weight: 700; font-size: 12px; padding: 9px 16px; border-radius: 10px; text-decoration: none; background: #eef1f6; color: var(--ink); display: inline-flex; align-items: center; }
        .actions .pri { background: var(--acc); color: #fff; }
        .actions form { display: inline; }
        @page { size: A4; margin: 0; }
        @media print {
            body { background: #fff; padding: 0; }
            .page { box-shadow: none; width: 210mm; min-height: 296mm; margin: 0; }
            .actions, .flash, .nobank { display: none !important; }
            .wm, .stamp { position: fixed; }
        }
        @media screen and (max-width: 820px) {
            body { padding: 10px 0 96px; }
            .page { width: 100%; min-height: 0; }
            .inner { padding: 22px 18px 0; }
            .head, .parties, .sumrow, .cols2 { display: block; }
            .doc { text-align: left; margin-top: 14px; } .doc .t::after { margin-left: 0; }
            .card { margin-bottom: 10px; } .totals { margin-top: 12px; }
            table.items td, table.items th { padding: 8px 6px; }
            .foot { padding: 14px 18px; } .wm { width: 320px; } .wm .tx { font-size: 44px; }
        }
    </style>
</head>

<body>
    <?php if (!empty($opts['flash'])): ?><div class="flash<?= ($opts['flash_type'] ?? 'ok') === 'err' ? ' bad' : '' ?>"><?= $e($opts['flash']) ?></div><?php endif; ?>
    <div class="page">
        <div class="topband"></div>
        <div class="wm"><span class="mk"><?= self::markSvg(210, '#0b0e14', '#ff6a1a') ?></span><div class="tx">ADF<b>system</b></div><div class="sub">DEVELOPER</div></div>
        <?php if ($stamp !== ''): ?><div class="stamp <?= $tone ?>"><?= $stamp ?></div><?php endif; ?>
        <div class="inner">
            <div class="head">
                <div class="brand">
                    <div class="brand-mark"><img src="/logo.png" alt=""></div>
                    <div>
                        <h1>ADF<span>system</span></h1>
                        <div class="tag"><?= $e($settings['tagline']) ?></div>
                        <div class="addr"><b style="color:var(--ink-2)"><?= $e($settings['company_name']) ?></b><br><?= $e($settings['address']) ?></div>
                    </div>
                </div>
                <div class="doc">
                    <div class="t">INVOICE</div>
                    <div class="no"><?= $e($inv['number']) ?></div>
                    <span class="pill <?= $tone ?>"><?= $e(self::STATUS[$status] ?? $status) ?></span>
                </div>
            </div>
            <div class="parties">
                <div class="card">
                    <h3>Ditagihkan kepada</h3>
                    <div class="nm"><?= $e($inv['customer_name']) ?></div>
                    <?php if ($inv['customer_company']): ?><div class="co"><?= $e($inv['customer_company']) ?></div><?php endif; ?>
                    <?php foreach (['customer_address', 'customer_email', 'customer_phone'] as $k): if ($inv[$k]): ?><div class="ln"><?= $e($inv[$k]) ?></div><?php endif; endforeach; ?>
                </div>
                <div class="card">
                    <h3>Detail invoice</h3>
                    <div class="kv">
                        <span>No. Invoice</span><span><?= $e($inv['number']) ?></span>
                        <span>Tanggal terbit</span><span><?= $e(self::date($inv['issue_date'])) ?></span>
                        <span>Jatuh tempo</span><span><?= $e(self::date($inv['due_date'])) ?></span>
                        <?php if ($inv['paid_at']): ?><span>Dibayar</span><span><?= $e(self::date($inv['paid_at'])) ?></span><?php endif; ?>
                    </div>
                </div>
            </div>
            <table class="items">
                <thead><tr><th style="width:34px">#</th><th>Deskripsi</th><th class="c" style="width:56px">Qty</th><th class="r" style="width:120px">Harga</th><th class="r" style="width:130px">Jumlah</th></tr></thead>
                <tbody>
                    <?php foreach ($inv['items'] as $i => $it): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><span class="d"><?= $e($it['description']) ?></span><?php if ($it['note']): ?><span class="n"><?= $e($it['note']) ?></span><?php endif; ?></td>
                            <td class="c"><?= $e($fmtNum($it['qty'])) ?></td>
                            <td class="r"><?= $e(self::rp($it['unit_price'])) ?></td>
                            <td class="r"><b><?= $e(self::rp((float)$it['qty'] * (float)$it['unit_price'])) ?></b></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="sumrow">
                <div class="words"><small>Terbilang</small><i><?= $e($terbilang) ?></i></div>
                <div class="totals">
                    <div class="row"><span>Subtotal</span><span><?= $e(self::rp($t['subtotal'])) ?></span></div>
                    <?php if ($t['discount'] > 0): ?><div class="row disc"><span>Diskon<?= $inv['discount_type'] === 'percent' ? ' (' . $e($fmtNum($inv['discount_value'])) . '%)' : '' ?></span><span>- <?= $e(self::rp($t['discount'])) ?></span></div><?php endif; ?>
                    <?php if ($t['tax'] > 0): ?><div class="row"><span>PPN <?= $e($fmtNum($t['tax_percent'])) ?>%</span><span><?= $e(self::rp($t['tax'])) ?></span></div><?php endif; ?>
                    <div class="row grand"><span>TOTAL</span><span><em>Rp</em> <?= $e(number_format($t['total'], 0, ',', '.')) ?></span></div>
                </div>
            </div>
            <?php if ($banks): ?>
                <div class="pay">
                    <h3>Cara pembayaran</h3>
                    <div class="banks"><?php foreach ($banks as $b): ?><div class="bank"><b><?= $e($b['bank'] ?? '') ?></b><div class="num"><?= $e($b['number'] ?? '') ?></div><small>a.n. <?= $e($b['holder'] ?? '') ?></small></div><?php endforeach; ?></div>
                    <?php if (trim((string)$settings['payment_note']) !== ''): ?><div class="pnote"><?= $e($settings['payment_note']) ?></div><?php endif; ?>
                </div>
            <?php elseif (!$public): ?>
                <div class="pay nobank"><h3>Cara pembayaran</h3><div class="bank-missing">Nomor rekening belum diatur, jadi belum tampil di invoice. Isi di <a href="/invoice-settings.php">Pengaturan invoice → Rekening</a>.</div></div>
            <?php endif; ?>
            <?php $note = trim((string)$inv['notes']); $terms = trim((string)$settings['terms']); ?>
            <?php if ($note !== '' || $terms !== ''): ?>
                <div class="cols2">
                    <div class="sec"><?php if ($note !== ''): ?><h3>Catatan</h3><div class="tx"><?= $e($note) ?></div><?php endif; ?></div>
                    <div class="sec"><?php if ($terms !== ''): ?><h3>Syarat &amp; ketentuan</h3><div class="tx"><?= $e($terms) ?></div><?php endif; ?></div>
                </div>
            <?php endif; ?>
            <div class="sign"><div>Hormat kami,<div class="sp"></div><div class="ln"><?= $e(trim((string)$settings['signer_name']) ?: $settings['company_name']) ?></div><?php if (trim((string)$settings['signer_title']) !== ''): ?><div><?= $e($settings['signer_title']) ?></div><?php endif; ?></div></div>
        </div>
        <div class="foot">
            <div class="ty">Terima kasih atas kepercayaan Anda</div>
            <div class="ct"><?= $e(implode('  ·  ', array_merge([(string)$settings['company_name']], $contact))) ?></div>
        </div>
    </div>
    <div class="actions">
        <button type="button" class="pri" onclick="window.print()">Cetak / Simpan PDF</button>
        <?= $opts['actions'] ?? '' ?>
    </div>
</body>

</html>
<?php
    }
}
