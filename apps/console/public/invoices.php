<?php

/** Tagihan → Invoice: daftar invoice (bebas & bulanan grup), filter status, tandai lunas / batal / hapus. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();
$db = Biz::db();
$isAdmin = $me['role'] === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!csrf_ok() || !$isAdmin) {
            throw new RuntimeException(!$isAdmin ? 'Hanya admin yang bisa mengubah invoice.' : 'Sesi form kedaluwarsa, silakan coba lagi.');
        }
        if ($action === 'delete') {
            Invoice::delete($id);
            Auth::audit('invoice.delete', "#{$id}");
            flash('Invoice dihapus.');
        } else {
            Invoice::setStatus($id, $action, (string)($_POST['method'] ?? 'transfer'));
            Auth::audit('invoice.' . $action, "#{$id}");
            flash(['paid' => 'Invoice ditandai lunas — pembayaran tercatat.', 'cancelled' => 'Invoice dibatalkan.', 'unpaid' => 'Invoice kembali belum dibayar.'][$action] ?? 'Disimpan.');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('/invoices.php' . (isset($_POST['back']) ? '?' . http_build_query(['s' => $_POST['back']]) : ''));
}

$filter = (string)($_GET['s'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$rows = $db->query('SELECT i.*, c.name AS client_name,
        (SELECT COALESCE(SUM(qty * unit_price), 0) FROM invoice_items it WHERE it.invoice_id = i.id) AS subtotal
    FROM invoices i LEFT JOIN clients c ON c.id = i.client_id ORDER BY i.issue_date DESC, i.id DESC')->fetchAll();
$sum = ['unpaid' => 0.0, 'overdue' => 0.0, 'paid_month' => 0.0, 'draft' => 0];
$list = [];
foreach ($rows as $r) {
    $r['items'] = [['qty' => 1, 'unit_price' => $r['subtotal']]];
    $r['total'] = Invoice::totals($r)['total'];
    $r['ds'] = Invoice::displayStatus($r);
    if ($r['ds'] === 'unpaid' || $r['ds'] === 'overdue') {
        $sum['unpaid'] += $r['total'];
    }
    if ($r['ds'] === 'overdue') {
        $sum['overdue'] += $r['total'];
    }
    if ($r['status'] === 'paid' && substr((string)$r['paid_at'], 0, 7) === date('Y-m')) {
        $sum['paid_month'] += $r['total'];
    }
    $sum['draft'] += $r['status'] === 'draft' ? 1 : 0;
    $hit = $q === '' || stripos($r['number'] . ' ' . $r['customer_name'] . ' ' . $r['customer_company'] . ' ' . $r['client_name'], $q) !== false;
    $match = $filter === '' || $r['ds'] === $filter || ($filter === 'unpaid' && $r['ds'] === 'overdue');
    if ($hit && $match) {
        $list[] = $r;
    }
}
$clients = $db->query('SELECT id, name FROM clients WHERE is_demo = 0 ORDER BY name')->fetchAll();

View::start('Invoice', 'invoices', $me);
?>
<div class="pagehead">
    <div><h1>Invoice</h1><p>Invoice manual, mis. registrasi pelanggan baru, biaya setup, tambah modul, atau custom development. Pelanggan menerima link invoice yang bisa dicetak / disimpan PDF.</p></div>
    <?php if ($isAdmin): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn ghost" href="/invoice-settings.php">Pengaturan invoice</a>
            <a class="btn" href="/invoice-edit.php">+ Invoice baru</a>
        </div>
    <?php endif; ?>
</div>

<div class="row2" style="margin-bottom:14px">
    <div class="card" style="margin:0"><h2>Belum dibayar</h2><div class="stat" style="color:<?= $sum['unpaid'] > 0 ? 'var(--warn)' : 'var(--ink)' ?>"><?= Invoice::rp($sum['unpaid']) ?></div><p class="muted" style="margin:4px 0 0;font-size:12px"><?= $sum['overdue'] > 0 ? Invoice::rp($sum['overdue']) . ' sudah lewat jatuh tempo' : 'tidak ada yang lewat jatuh tempo' ?></p></div>
    <div class="card" style="margin:0"><h2>Lunas bulan ini</h2><div class="stat" style="color:var(--ok)"><?= Invoice::rp($sum['paid_month']) ?></div><p class="muted" style="margin:4px 0 0;font-size:12px"><?= e(Invoice::date(date('Y-m-01'), false)) ?></p></div>
    <?php if ($isAdmin && $clients): ?>
        <div class="card" style="margin:0"><h2>Invoice bulanan grup</h2>
            <form method="get" action="/invoice-edit.php" style="display:flex;gap:6px;flex-wrap:wrap">
                <select class="sm" name="client" style="flex:1"><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
                <input class="sm" type="month" name="period" value="<?= date('Y-m') ?>">
                <button class="btn sm">Buat</button>
            </form>
            <p class="muted" style="margin:6px 0 0;font-size:11.5px">Baris diisi otomatis dari paket & tambahan tiap bisnis.</p>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
        <div class="subtabs" style="margin:0">
            <?php foreach (['' => 'Semua', 'unpaid' => 'Belum dibayar', 'overdue' => 'Jatuh tempo', 'paid' => 'Lunas', 'draft' => 'Draft' . ($sum['draft'] ? " ({$sum['draft']})" : ''), 'cancelled' => 'Batal'] as $k => $l): ?>
                <a class="<?= $filter === $k ? 'on' : '' ?>" href="?<?= http_build_query(array_filter(['s' => $k, 'q' => $q])) ?>"><?= e($l) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="get" style="min-width:220px"><input type="hidden" name="s" value="<?= e($filter) ?>"><input class="sm" style="width:100%" name="q" value="<?= e($q) ?>" placeholder="Cari nomor atau pelanggan…"></form>
    </div>
    <table class="stack">
        <thead><tr><th>Nomor</th><th>Pelanggan</th><th>Terbit</th><th>Jatuh tempo</th><th>Status</th><th style="text-align:right">Total</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($list as $r): $id = (int)$r['id']; ?>
                <tr>
                    <td><a href="/invoice-view.php?id=<?= $id ?>" style="color:var(--ink)"><b><?= e($r['number']) ?></b></a><?= $r['kind'] === 'monthly' ? ' <span class="pill">bulanan</span>' : '' ?></td>
                    <td><?= e($r['customer_name']) ?><?= $r['customer_company'] ? '<br><span class="muted" style="font-size:11.5px">' . e($r['customer_company']) . '</span>' : '' ?></td>
                    <td class="muted"><?= e(date('d M Y', strtotime((string)$r['issue_date']))) ?></td>
                    <td class="muted"><?= e(date('d M Y', strtotime((string)$r['due_date']))) ?></td>
                    <td><span class="pill <?= Invoice::tone($r['ds']) ?>"><?= e(Invoice::STATUS[$r['ds']] ?? $r['ds']) ?></span></td>
                    <td style="text-align:right;font-weight:600"><?= Invoice::rp($r['total']) ?></td>
                    <td style="text-align:right;white-space:nowrap">
                        <a class="btn ghost sm" href="/invoice-view.php?id=<?= $id ?>">Lihat</a>
                        <?php if ($isAdmin && in_array($r['ds'], ['unpaid', 'overdue'], true)): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Tandai <?= e($r['number']) ?> sudah dibayar?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="paid"><input type="hidden" name="back" value="<?= e($filter) ?>"><button class="btn sm">Lunas</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$list): ?><tr><td colspan="7" class="muted">Belum ada invoice<?= $filter !== '' || $q !== '' ? ' yang cocok' : '' ?>.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php View::end();
