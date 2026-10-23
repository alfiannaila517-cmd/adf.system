<?php

/** Lihat satu invoice (dokumen A4 sama dengan Store) + tombol: ubah, tandai lunas, salin link, kirim WhatsApp. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();
$id = (int)($_GET['id'] ?? 0);
$inv = Invoice::find($id);
if (!$inv) {
    flash('Invoice tidak ditemukan.', 'err');
    redirect('/invoices.php');
}
$isAdmin = $me['role'] === 'admin';

// Kirim tagihan lewat email (tombol di bawah, atau otomatis dari "Simpan & kirim" di form invoice)
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    try {
        if (!csrf_ok()) {
            throw new RuntimeException('Sesi form kedaluwarsa, silakan coba lagi.');
        }
        $to = Invoice::sendEmail($id);
        Auth::audit('invoice.send', "{$inv['number']} → {$to}");
        flash("Tagihan terkirim ke {$to}.");
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect("/invoice-view.php?id={$id}");
}
$ds = Invoice::displayStatus($inv);
$url = Invoice::publicUrl($inv);
$total = Invoice::totals($inv)['total'];
$csrf = csrf_field();

$actions = '<a href="/invoices.php">← Daftar</a>';
if ($isAdmin) {
    $actions .= '<a href="/invoice-edit.php?id=' . $id . '">Ubah</a>';
    if (in_array($ds, ['unpaid', 'overdue'], true)) {
        $actions .= '<form method="post" action="/invoices.php" onsubmit="return confirm(\'Tandai sudah dibayar?\')">' . $csrf
            . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="paid"><button>Tandai lunas</button></form>';
    }
    if (!in_array($inv['status'], ['paid', 'cancelled'], true)) {
        $sent = !empty($inv['sent_at']) ? ' (terkirim ' . date('d M H:i', strtotime((string)$inv['sent_at'])) . ')' : '';
        $actions .= '<form method="post" onsubmit="return confirm(\'Kirim tagihan ke ' . e((string)($inv['customer_email'] ?: 'email pelanggan')) . '?\')">' . $csrf
            . '<input type="hidden" name="action" value="send"><button class="pri">Kirim tagihan (email)' . e($sent) . '</button></form>';
    }
    if ($inv['status'] !== 'draft' && $inv['status'] !== 'cancelled') {
        $actions .= '<button type="button" onclick="navigator.clipboard.writeText(' . e(json_encode($url)) . ');this.textContent=\'Link tersalin ✓\'">Salin link pelanggan</button>';
        $wa = preg_replace('/\D/', '', (string)$inv['customer_phone']);
        $wa = str_starts_with($wa, '0') ? '62' . substr($wa, 1) : $wa;
        $text = "Halo {$inv['customer_name']}, berikut invoice {$inv['number']} sebesar " . Invoice::rp($total)
            . ', jatuh tempo ' . Invoice::date($inv['due_date']) . ":\n{$url}\n\nTerima kasih.";
        $actions .= '<a target="_blank" rel="noopener" href="https://wa.me/' . e($wa) . '?text=' . rawurlencode($text) . '">Kirim WhatsApp</a>';
    }
    if (in_array($inv['status'], ['draft', 'cancelled'], true)) {
        $actions .= '<form method="post" action="/invoices.php" onsubmit="return confirm(\'Hapus invoice ini?\')">' . $csrf
            . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="delete"><button>Hapus</button></form>';
    } elseif ($ds !== 'paid') {
        $actions .= '<form method="post" action="/invoices.php" onsubmit="return confirm(\'Batalkan invoice ini?\')">' . $csrf
            . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="cancelled"><button>Batalkan</button></form>';
    }
}
$f = flash();
Invoice::render($inv, ['actions' => $actions, 'flash' => $f['msg'] ?? '', 'flash_type' => $f['type'] ?? 'ok']);
