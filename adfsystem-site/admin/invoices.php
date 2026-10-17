<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/invoices-store.php';
require_once __DIR__ . '/../includes/invoice-mail.php';
adf_admin_require_role('admin');

$settings = adf_invoice_settings();
$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $flash = ['type' => 'error', 'msg' => 'Sesi form kedaluwarsa, silakan coba lagi.'];
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $id = (string) ($_POST['id'] ?? '');
        $all = adf_invoices_load();
        $idx = null;
        foreach ($all as $i => $row) {
            if (($row['id'] ?? '') === $id) {
                $idx = $i;
            }
        }
        if ($idx === null) {
            $flash = ['type' => 'error', 'msg' => 'Invoice tidak ditemukan.'];
        } elseif ($action === 'paid') {
            $all[$idx]['status'] = 'paid';
            $all[$idx]['paid_at'] = date('c');
            adf_invoices_save($all);
            $flash = ['type' => 'success', 'msg' => 'Invoice ' . $all[$idx]['number'] . ' ditandai lunas.'];
        } elseif ($action === 'unpaid') {
            $all[$idx]['status'] = 'unpaid';
            unset($all[$idx]['paid_at']);
            adf_invoices_save($all);
            $flash = ['type' => 'success', 'msg' => 'Invoice ' . $all[$idx]['number'] . ' dikembalikan ke belum dibayar.'];
        } elseif ($action === 'cancel') {
            $all[$idx]['status'] = 'cancelled';
            adf_invoices_save($all);
            $flash = ['type' => 'success', 'msg' => 'Invoice ' . $all[$idx]['number'] . ' dibatalkan.'];
        } elseif ($action === 'delete') {
            $num = $all[$idx]['number'];
            array_splice($all, $idx, 1);
            adf_invoices_save($all);
            $flash = ['type' => 'success', 'msg' => 'Invoice ' . $num . ' dihapus.'];
        } elseif ($action === 'duplicate') {
            $copy = $all[$idx];
            $copy['id'] = uniqid('inv', true);
            $copy['token'] = bin2hex(random_bytes(12));
            $copy['number'] = adf_invoice_next_number(date('Y-m-d'));
            $copy['status'] = 'draft';
            $copy['issue_date'] = date('Y-m-d');
            $copy['due_date'] = date('Y-m-d', strtotime('+' . max(0, (int) $settings['default_due_days']) . ' days'));
            $copy['created_at'] = $copy['updated_at'] = date('c');
            unset($copy['paid_at']);
            $all[] = $copy;
            adf_invoices_save($all);
            header('Location: invoice-edit.php?id=' . urlencode($copy['id']));
            exit;
        } elseif ($action === 'email') {
            [$ok, $err] = adf_invoice_send_email($all[$idx], $settings);
            $flash = $ok ? ['type' => 'success', 'msg' => 'Email invoice ' . $all[$idx]['number'] . ' terkirim ke ' . $all[$idx]['customer']['email'] . '.'] : ['type' => 'error', 'msg' => 'Email gagal dikirim: ' . $err];
        }
    }
}

$all = adf_invoices_load();
usort($all, static fn($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

// Ringkasan
$sum = ['unpaid' => 0.0, 'unpaid_n' => 0, 'overdue' => 0.0, 'overdue_n' => 0, 'paid_month' => 0.0, 'paid_month_n' => 0, 'draft_n' => 0];
$counts = ['all' => count($all), 'draft' => 0, 'unpaid' => 0, 'overdue' => 0, 'paid' => 0, 'cancelled' => 0];
foreach ($all as $row) {
    $st = adf_invoice_display_status($row);
    $counts[$st] = ($counts[$st] ?? 0) + 1;
    $tot = adf_invoice_totals($row)['total'];
    if ($st === 'unpaid') { $sum['unpaid'] += $tot; $sum['unpaid_n']++; }
    if ($st === 'overdue') { $sum['overdue'] += $tot; $sum['overdue_n']++; }
    if ($st === 'draft') { $sum['draft_n']++; }
    if ($st === 'paid' && substr((string) ($row['paid_at'] ?? ''), 0, 7) === date('Y-m')) { $sum['paid_month'] += $tot; $sum['paid_month_n']++; }
}

$filter = (string) ($_GET['status'] ?? 'all');
if (!isset($counts[$filter])) {
    $filter = 'all';
}
$q = trim((string) ($_GET['q'] ?? ''));
$rows = array_values(array_filter($all, static function ($row) use ($filter, $q) {
    if ($filter !== 'all' && adf_invoice_display_status($row) !== $filter) {
        return false;
    }
    if ($q !== '') {
        $hay = strtolower(($row['number'] ?? '') . ' ' . ($row['customer']['name'] ?? '') . ' ' . ($row['customer']['company'] ?? ''));
        if (strpos($hay, strtolower($q)) === false) {
            return false;
        }
    }
    return true;
}));

$csrf = adf_admin_csrf_token();
$adminPageTitle = 'Invoice';
require __DIR__ . '/../includes/admin-header.php';
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$tabs = ['all' => 'Semua', 'draft' => 'Draft', 'unpaid' => 'Belum dibayar', 'overdue' => 'Jatuh tempo', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan'];
?>
<div class="container admin-container admin-container-wide">
    <div class="inv-pagehead">
        <div>
            <h1>Invoice</h1>
            <p class="admin-lead">Buat dan kelola invoice manual, mis. registrasi awal pelanggan baru, biaya setup, tambah modul, atau custom development. Pelanggan menerima link invoice yang bisa dicetak / disimpan PDF.</p>
        </div>
        <div class="inv-pagehead-actions">
            <a href="invoice-settings.php" class="btn btn-outline">Pengaturan Invoice</a>
            <a href="invoice-edit.php" class="btn btn-primary">+ Buat Invoice</a>
        </div>
    </div>

    <?php if ($flash['msg'] !== ''): ?><div class="admin-alert admin-alert-<?php echo $e($flash['type']); ?>"><?php echo $e($flash['msg']); ?></div><?php endif; ?>

    <div class="inv-kpis">
        <div class="inv-kpi tone-amber"><small>Belum dibayar</small><b><?php echo $e(adf_invoice_rp($sum['unpaid'])); ?></b><span><?php echo (int) $sum['unpaid_n']; ?> invoice</span></div>
        <div class="inv-kpi tone-red"><small>Jatuh tempo</small><b><?php echo $e(adf_invoice_rp($sum['overdue'])); ?></b><span><?php echo (int) $sum['overdue_n']; ?> invoice terlambat</span></div>
        <div class="inv-kpi tone-green"><small>Lunas bulan ini</small><b><?php echo $e(adf_invoice_rp($sum['paid_month'])); ?></b><span><?php echo (int) $sum['paid_month_n']; ?> invoice</span></div>
        <div class="inv-kpi"><small>Draft</small><b><?php echo (int) $sum['draft_n']; ?></b><span>belum diterbitkan</span></div>
    </div>

    <div class="inv-toolbar">
        <div class="inv-tabs">
            <?php foreach ($tabs as $k => $label): ?>
                <a href="invoices.php?status=<?php echo $e($k); ?><?php echo $q !== '' ? '&q=' . urlencode($q) : ''; ?>" class="<?php echo $filter === $k ? 'active' : ''; ?>"><?php echo $e($label); ?> <em><?php echo (int) ($counts[$k] ?? 0); ?></em></a>
            <?php endforeach; ?>
        </div>
        <form method="get" class="inv-search">
            <input type="hidden" name="status" value="<?php echo $e($filter); ?>">
            <input type="search" name="q" value="<?php echo $e($q); ?>" placeholder="Cari nomor / pelanggan…">
        </form>
    </div>

    <div class="payment-table-wrap">
        <table class="admin-table inv-table">
            <thead>
                <tr>
                    <th>No. Invoice</th>
                    <th>Pelanggan</th>
                    <th>Terbit</th>
                    <th>Jatuh tempo</th>
                    <th style="text-align:right;">Total</th>
                    <th>Status</th>
                    <th style="text-align:right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row):
                    $st = adf_invoice_display_status($row);
                    $tot = adf_invoice_totals($row)['total'];
                    $rid = (string) $row['id']; ?>
                    <tr>
                        <td><a href="invoice-view.php?id=<?php echo $e(urlencode($rid)); ?>"><b><?php echo $e($row['number']); ?></b></a></td>
                        <td><?php echo $e($row['customer']['name'] ?? '-'); ?><?php if (!empty($row['customer']['company'])): ?><small class="inv-sub"><?php echo $e($row['customer']['company']); ?></small><?php endif; ?></td>
                        <td><?php echo $e(adf_invoice_date($row['issue_date'] ?? '')); ?></td>
                        <td><?php echo $e(adf_invoice_date($row['due_date'] ?? '')); ?></td>
                        <td style="text-align:right;"><b><?php echo $e(adf_invoice_rp($tot)); ?></b></td>
                        <td><span class="inv-status st-<?php echo $e($st); ?>"><?php echo $e(adf_invoice_status_label($st)); ?></span></td>
                        <td style="text-align:right;white-space:nowrap;">
                            <a href="invoice-view.php?id=<?php echo $e(urlencode($rid)); ?>" class="btn btn-outline btn-sm">Lihat</a>
                            <a href="invoice-edit.php?id=<?php echo $e(urlencode($rid)); ?>" class="btn btn-outline btn-sm">Ubah</a>
                            <details class="inv-menu">
                                <summary class="btn btn-outline btn-sm" aria-label="Aksi lain">⋯</summary>
                                <div class="inv-menu-body">
                                    <?php
                                    $acts = [];
                                    if ($st === 'unpaid' || $st === 'overdue') { $acts[] = ['paid', 'Tandai lunas', false]; }
                                    if ($st === 'paid') { $acts[] = ['unpaid', 'Kembalikan ke belum dibayar', false]; }
                                    if (($row['customer']['email'] ?? '') !== '' && $st !== 'draft' && $st !== 'cancelled') { $acts[] = ['email', 'Kirim ke email', false]; }
                                    $acts[] = ['duplicate', 'Duplikat', false];
                                    if ($st !== 'cancelled' && $st !== 'paid') { $acts[] = ['cancel', 'Batalkan invoice', true]; }
                                    $acts[] = ['delete', 'Hapus permanen', true];
                                    foreach ($acts as [$a, $label, $danger]): ?>
                                        <form method="post" <?php echo in_array($a, ['delete', 'cancel'], true) ? 'onsubmit="return confirm(\'' . ($a === 'delete' ? 'Hapus invoice ini secara permanen?' : 'Batalkan invoice ini?') . '\')"' : ''; ?>>
                                            <input type="hidden" name="csrf" value="<?php echo $e($csrf); ?>">
                                            <input type="hidden" name="id" value="<?php echo $e($rid); ?>">
                                            <input type="hidden" name="action" value="<?php echo $e($a); ?>">
                                            <button type="submit" class="<?php echo $danger ? 'danger' : ''; ?>"><?php echo $e($label); ?></button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="7"><div class="adm-empty" style="padding:28px 12px;text-align:center;"><?php echo $all ? 'Tidak ada invoice yang cocok dengan filter.' : 'Belum ada invoice. Klik “+ Buat Invoice” untuk membuat tagihan pertama.'; ?></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
    // Menu aksi: tutup saat klik di luar / buka menu lain
    document.addEventListener('click', function(e) {
        document.querySelectorAll('details.inv-menu[open]').forEach(function(d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
    });
</script>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
