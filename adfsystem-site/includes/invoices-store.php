<?php

/**
 * Invoice manual ADF System (di luar tagihan langganan otomatis): mis. registrasi awal pelanggan baru,
 * biaya setup, tambah modul, custom development. Disimpan di JSON (pola sama dengan store lain di situs ini):
 *   data/invoices.json          daftar invoice
 *   data/invoice-settings.json  profil perusahaan, rekening bank, syarat default
 * File data dibuat saat dipakai (tidak ada di repo).
 *
 * Status tersimpan: draft | unpaid | paid | cancelled.  "Jatuh tempo" (overdue) dihitung dari unpaid + due_date lewat.
 */

require_once __DIR__ . '/site-config.php';

function adf_invoices_path(): string
{
    return __DIR__ . '/../data/invoices.json';
}

function adf_invoice_settings_path(): string
{
    return __DIR__ . '/../data/invoice-settings.json';
}

function adf_invoices_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function adf_invoices_write_json(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

function adf_invoices_load(): array
{
    return adf_invoices_read_json(adf_invoices_path());
}

function adf_invoices_save(array $invoices): bool
{
    return adf_invoices_write_json(adf_invoices_path(), array_values($invoices));
}

function adf_invoice_find(string $id): ?array
{
    foreach (adf_invoices_load() as $inv) {
        if (($inv['id'] ?? '') === $id) {
            return $inv;
        }
    }
    return null;
}

function adf_invoice_find_by_token(string $token): ?array
{
    if ($token === '' || strlen($token) < 16) {
        return null;
    }
    foreach (adf_invoices_load() as $inv) {
        if (hash_equals((string) ($inv['token'] ?? ''), $token) && ($inv['status'] ?? '') !== 'draft') {
            return $inv;
        }
    }
    return null;
}

/** Pengaturan invoice (profil perusahaan, rekening, syarat). Nilai awal diambil dari site-config. */
function adf_invoice_settings(): array
{
    $defaults = [
        'company_name' => COMPANY_LEGAL_NAME,
        'tagline' => SITE_TAGLINE,
        'address' => CONTACT_ADDRESS,
        'email' => CONTACT_EMAIL,
        'whatsapp' => CONTACT_WHATSAPP,
        'website' => 'adfsystem.store',
        'prefix' => 'INV',
        'default_due_days' => 7,
        'default_tax_percent' => 0,
        'banks' => [['bank' => '', 'number' => '', 'holder' => '']],
        'payment_note' => 'Mohon cantumkan nomor invoice pada berita transfer dan kirim bukti pembayaran ke email / WhatsApp kami.',
        'terms' => "Pembayaran paling lambat pada tanggal jatuh tempo.\nInvoice ini sah dan dibuat secara elektronik tanpa tanda tangan basah.\nBiaya yang sudah dibayarkan tidak dapat dikembalikan, kecuali disepakati tertulis.",
        'signer_name' => '',
        'signer_title' => 'Developer',
    ];
    $saved = adf_invoices_read_json(adf_invoice_settings_path());
    $merged = array_merge($defaults, array_intersect_key($saved, $defaults));
    if (empty($merged['banks']) || !is_array($merged['banks'])) {
        $merged['banks'] = $defaults['banks'];
    }
    return $merged;
}

function adf_invoice_settings_save(array $settings): bool
{
    return adf_invoices_write_json(adf_invoice_settings_path(), $settings);
}

/** Nomor berikutnya: PREFIX-YYYYMM-001 (urut per bulan terbit). */
function adf_invoice_next_number(string $issueDate = ''): string
{
    $s = adf_invoice_settings();
    $prefix = preg_replace('/[^A-Za-z0-9]/', '', (string) $s['prefix']) ?: 'INV';
    $ts = $issueDate !== '' ? strtotime($issueDate) : time();
    $ym = date('Ym', $ts ?: time());
    $max = 0;
    foreach (adf_invoices_load() as $inv) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '-' . $ym . '-(\d+)$/', (string) ($inv['number'] ?? ''), $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return $prefix . '-' . $ym . '-' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
}

/** Hitung subtotal, diskon, PPN, total dari item + aturan diskon/pajak. */
function adf_invoice_totals(array $inv): array
{
    $subtotal = 0.0;
    foreach ($inv['items'] ?? [] as $it) {
        $subtotal += round((float) ($it['qty'] ?? 0) * (float) ($it['unit_price'] ?? 0), 2);
    }
    $dv = max(0.0, (float) ($inv['discount_value'] ?? 0));
    $discount = ($inv['discount_type'] ?? 'rp') === 'percent' ? round($subtotal * min(100.0, $dv) / 100, 2) : min($dv, $subtotal);
    $taxBase = max(0.0, $subtotal - $discount);
    $taxPercent = max(0.0, (float) ($inv['tax_percent'] ?? 0));
    $tax = round($taxBase * $taxPercent / 100, 2);
    return ['subtotal' => $subtotal, 'discount' => $discount, 'tax_base' => $taxBase, 'tax' => $tax, 'tax_percent' => $taxPercent, 'total' => round($taxBase + $tax, 2)];
}

/** Status tampilan: menambahkan 'overdue' untuk unpaid yang lewat jatuh tempo. */
function adf_invoice_display_status(array $inv): string
{
    $st = (string) ($inv['status'] ?? 'draft');
    if ($st === 'unpaid' && !empty($inv['due_date']) && $inv['due_date'] < date('Y-m-d')) {
        return 'overdue';
    }
    return $st;
}

function adf_invoice_status_label(string $status): string
{
    return [
        'draft' => 'Draft', 'unpaid' => 'Belum dibayar', 'overdue' => 'Jatuh tempo', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan',
    ][$status] ?? ucfirst($status);
}

/** Bersihkan & normalisasi input form menjadi struktur invoice. Mengembalikan [invoice|null, error]. */
function adf_invoice_from_post(array $post, array $base = []): array
{
    $cust = [
        'name' => trim((string) ($post['cust_name'] ?? '')),
        'company' => trim((string) ($post['cust_company'] ?? '')),
        'email' => trim((string) ($post['cust_email'] ?? '')),
        'phone' => trim((string) ($post['cust_phone'] ?? '')),
        'address' => trim((string) ($post['cust_address'] ?? '')),
    ];
    if ($cust['name'] === '') {
        return [null, 'Nama pelanggan wajib diisi.'];
    }
    if ($cust['email'] !== '' && !filter_var($cust['email'], FILTER_VALIDATE_EMAIL)) {
        return [null, 'Format email pelanggan tidak valid.'];
    }

    $items = [];
    $descs = (array) ($post['item_desc'] ?? []);
    foreach ($descs as $i => $desc) {
        $desc = trim((string) $desc);
        $qty = (float) str_replace(',', '.', (string) ($post['item_qty'][$i] ?? 1));
        $price = (float) str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', (string) ($post['item_price'][$i] ?? 0)));
        if ($desc === '') {
            continue;
        }
        if ($qty <= 0 || $price < 0) {
            return [null, 'Jumlah harus lebih dari 0 dan harga tidak boleh negatif (baris: ' . $desc . ').'];
        }
        $items[] = ['desc' => mb_substr($desc, 0, 240), 'qty' => $qty, 'unit_price' => $price, 'note' => mb_substr(trim((string) ($post['item_note'][$i] ?? '')), 0, 240)];
    }
    if (!$items) {
        return [null, 'Tambahkan minimal satu item tagihan.'];
    }

    $issue = trim((string) ($post['issue_date'] ?? ''));
    $due = trim((string) ($post['due_date'] ?? ''));
    foreach (['issue' => $issue, 'due' => $due] as $d) {
        $o = DateTime::createFromFormat('Y-m-d', $d);
        if (!$o || $o->format('Y-m-d') !== $d) {
            return [null, 'Tanggal terbit dan jatuh tempo wajib diisi dengan benar.'];
        }
    }
    if ($due < $issue) {
        return [null, 'Jatuh tempo tidak boleh lebih awal dari tanggal terbit.'];
    }

    $status = (string) ($post['status'] ?? 'unpaid');
    if (!in_array($status, ['draft', 'unpaid', 'paid'], true)) {
        $status = 'unpaid';
    }
    $inv = $base + ['id' => uniqid('inv', true), 'token' => bin2hex(random_bytes(12)), 'created_at' => date('c')];
    $inv['number'] = trim((string) ($post['number'] ?? '')) !== '' ? mb_substr(trim((string) $post['number']), 0, 40) : ($base['number'] ?? adf_invoice_next_number($issue));
    $inv['customer'] = $cust;
    $inv['items'] = $items;
    $inv['issue_date'] = $issue;
    $inv['due_date'] = $due;
    $inv['discount_type'] = ($post['discount_type'] ?? 'rp') === 'percent' ? 'percent' : 'rp';
    $inv['discount_value'] = max(0.0, (float) str_replace(',', '.', (string) ($post['discount_value'] ?? 0)));
    $inv['tax_percent'] = max(0.0, min(100.0, (float) str_replace(',', '.', (string) ($post['tax_percent'] ?? 0))));
    $inv['notes'] = trim((string) ($post['notes'] ?? ''));
    $inv['updated_at'] = date('c');
    $wasPaid = ($base['status'] ?? '') === 'paid';
    $inv['status'] = $status;
    if ($status === 'paid' && !$wasPaid) {
        $inv['paid_at'] = date('c');
    } elseif ($status !== 'paid') {
        unset($inv['paid_at']);
    }
    return [$inv, ''];
}

/** Terbilang bahasa Indonesia untuk bilangan bulat (rupiah). */
function adf_terbilang(int $n): string
{
    $s = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($n === 0) {
        return 'nol';
    }
    if ($n < 12) {
        return $s[$n];
    }
    if ($n < 20) {
        return adf_terbilang($n - 10) . ' belas';
    }
    if ($n < 100) {
        return adf_terbilang(intdiv($n, 10)) . ' puluh' . ($n % 10 ? ' ' . adf_terbilang($n % 10) : '');
    }
    if ($n < 200) {
        return 'seratus' . ($n - 100 ? ' ' . adf_terbilang($n - 100) : '');
    }
    if ($n < 1000) {
        return adf_terbilang(intdiv($n, 100)) . ' ratus' . ($n % 100 ? ' ' . adf_terbilang($n % 100) : '');
    }
    if ($n < 2000) {
        return 'seribu' . ($n - 1000 ? ' ' . adf_terbilang($n - 1000) : '');
    }
    foreach ([1000000000000 => 'triliun', 1000000000 => 'miliar', 1000000 => 'juta', 1000 => 'ribu'] as $div => $name) {
        if ($n >= $div) {
            return adf_terbilang(intdiv($n, $div)) . ' ' . $name . ($n % $div ? ' ' . adf_terbilang($n % $div) : '');
        }
    }
    return '';
}

function adf_invoice_rp($n): string
{
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
}

function adf_invoice_date($d): string
{
    $t = strtotime((string) $d);
    if (!$t) {
        return '-';
    }
    $m = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return date('j', $t) . ' ' . $m[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
}

/** URL publik invoice (dibagikan ke pelanggan). */
function adf_invoice_public_url(array $inv): string
{
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'adfsystem.store';
    $root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php')), '/\\');
    // dipanggil dari /admin/*.php → naik satu level; dari root (invoice.php) dirname dua kali tetap valid
    if (strpos((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/') === false) {
        $root = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/x.php'), '/\\');
    }
    return $scheme . $host . $root . '/invoice.php?t=' . urlencode((string) ($inv['token'] ?? ''));
}
