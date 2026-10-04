<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/content-store.php';
require_once __DIR__ . '/../includes/orders-store.php';
require_once __DIR__ . '/../includes/pakasir-client.php';
adf_admin_require_login();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh') {
    if (adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $orderId = trim($_POST['order_id'] ?? '');
        $txnId = trim($_POST['txn_id'] ?? '');
        if ($txnId !== '') {
            $status = adf_pakasir_transaction_status($txnId);
            if ($status !== null && !empty($status['status'])) {
                adf_orders_update_status($orderId, $status['status'], $status['completed_at'] ?? null);
                $message = 'Status pesanan ' . htmlspecialchars($orderId) . ' diperbarui: ' . htmlspecialchars($status['status']);
            } else {
                $message = 'Gagal mengambil status dari Pakasir.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $orderId = trim($_POST['order_id'] ?? '');
        $orderToDelete = null;
        foreach (adf_orders_load() as $candidate) {
            if (($candidate['order_id'] ?? '') === $orderId) {
                $orderToDelete = $candidate;
                break;
            }
        }
        $status = strtolower((string) ($orderToDelete['status'] ?? ''));
        if ($orderToDelete === null) {
            $message = 'Pesanan tidak ditemukan.';
        } elseif ($status === 'completed') {
            $message = 'Transaksi completed tidak bisa dihapus.';
        } elseif (adf_orders_delete($orderId)) {
            $message = 'Pesanan ' . htmlspecialchars($orderId) . ' dihapus.';
        } else {
            $message = 'Gagal menghapus pesanan.';
        }
    }
}

// Sinkron tagihan manual yang sudah dibayar langsung dari Pakasir: otomatis tiap 5 menit saat
// halaman dibuka, atau segera lewat tombol "Sinkron dari Pakasir".
$forceSync = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync' && adf_admin_csrf_check($_POST['csrf'] ?? null);
if ($forceSync || ($_SESSION['adf_manual_sync_at'] ?? 0) < time() - 300) {
    $_SESSION['adf_manual_sync_at'] = time();
    $newPayments = adf_sync_manual_invoice_payments();
    if ($forceSync || $newPayments > 0) {
        $message = $newPayments > 0
            ? $newPayments . ' pembayaran tagihan manual baru tercatat dari Pakasir.'
            : 'Sinkron selesai. Tidak ada pembayaran tagihan manual baru di Pakasir.';
    }
}

/** Jenis transaksi: tagihan bulanan klien, tagihan manual klien, atau pembayaran website/lainnya. */
$orderType = static function (array $order): string {
    if (($order['source'] ?? '') !== 'subscription') {
        return 'website';
    }
    $id = strtolower((string) ($order['order_id'] ?? ''));
    $title = strtolower((string) ($order['product_title'] ?? ''));
    return (strpos($id, 'manual') !== false || strpos($title, 'tagihan manual') !== false) ? 'manual' : 'bulanan';
};
$typeLabels = ['all' => 'Semua', 'bulanan' => 'Tagihan Bulanan', 'manual' => 'Tagihan Manual', 'website' => 'Website & Lainnya'];
$typeFilter = (string) ($_GET['type'] ?? 'all');
if (!isset($typeLabels[$typeFilter])) {
    $typeFilter = 'all';
}

$everyOrder = adf_orders_load();
$typeCounts = ['all' => count($everyOrder), 'bulanan' => 0, 'manual' => 0, 'website' => 0];
$typeTotals = ['all' => 0, 'bulanan' => 0, 'manual' => 0, 'website' => 0];
foreach ($everyOrder as $o) {
    $t = $orderType($o);
    $typeCounts[$t]++;
    if (strtolower((string) ($o['status'] ?? '')) === 'completed') {
        $typeTotals[$t] += (int) ($o['amount'] ?? 0);
        $typeTotals['all'] += (int) ($o['amount'] ?? 0);
    }
}
$allOrders = $typeFilter === 'all' ? $everyOrder : array_values(array_filter($everyOrder, static fn($o) => $orderType($o) === $typeFilter));
$completedOrders = array_filter($allOrders, static function (array $order): bool {
    return strtolower((string) ($order['status'] ?? '')) === 'completed';
});
$completedTotal = array_sum(array_map(static function (array $order): int {
    return (int) ($order['amount'] ?? 0);
}, $completedOrders));
$pendingTotal = array_sum(array_map(static function (array $order): int {
    return (int) ($order['amount'] ?? 0);
}, array_filter($allOrders, static function (array $order): bool {
    return strtolower((string) ($order['status'] ?? '')) === 'pending';
})));
$formatDate = static function (?string $date): string {
    if (empty($date)) {
        return '-';
    }
    try {
        return (new DateTime($date))->format('d M Y, H:i');
    } catch (Exception $exception) {
        return $date;
    }
};
// Urutkan terbaru di atas berdasarkan waktu bayar (atau waktu dibuat), karena pembayaran
// dari webhook/klien langganan bisa tercatat tidak berurutan.
$orders = $allOrders;
usort($orders, static function (array $a, array $b): int {
    return strcmp((string) ($b['completed_at'] ?? $b['created_at'] ?? ''), (string) ($a['completed_at'] ?? $a['created_at'] ?? ''));
});
$sourceLabel = static function (array $order): string {
    switch ($order['source'] ?? 'website') {
        case 'subscription':
            return 'Klien Langganan';
        case 'pakasir':
            return 'Pakasir';
        default:
            return 'Checkout Website';
    }
};
$csrf = adf_admin_csrf_token();
$adminPageTitle = 'Transaksi Pembayaran';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container admin-container-wide">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
        <h1>Transaksi Pembayaran</h1>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <form method="post">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="sync">
                <button type="submit" class="btn btn-outline btn-sm">Sinkron dari Pakasir</button>
            </form>
            <!-- Pakasir tidak menyediakan API penarikan: penarikan dilakukan di dashboard Pakasir -->
            <a href="https://app.pakasir.com/" target="_blank" rel="noopener" class="btn btn-primary btn-sm" title="Penarikan saldo dilakukan di dashboard Pakasir (login akun Pakasir Anda)">Tarik Dana di Pakasir ↗</a>
        </div>
    </div>
    <p class="admin-lead">Semua pembayaran yang masuk lewat Pakasir: checkout website, tagihan klien langganan, dan pembayaran lain di proyek Pakasir ADF. Status <strong>completed</strong> berarti uang sudah diterima.</p>

    <!-- Filter jenis transaksi -->
    <div class="ord-tabs">
        <?php foreach ($typeLabels as $key => $label): ?>
            <a href="orders.php<?php echo $key === 'all' ? '' : '?type=' . $key; ?>" class="<?php echo $typeFilter === $key ? 'on' : ''; ?>">
                <?php echo $label; ?> <span><?php echo $typeCounts[$key]; ?></span>
                <small>Rp <?php echo number_format($typeTotals[$key], 0, ',', '.'); ?></small>
            </a>
        <?php endforeach; ?>
    </div>
    <style>
        .ord-tabs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin: 4px 0 14px; }
        .ord-tabs a { display: block; padding: 9px 12px; border: 1px solid rgba(255,255,255,.08); border-radius: 10px; text-decoration: none; color: inherit; font-size: 12.5px; font-weight: 600; background: rgba(255,255,255,.02); }
        .ord-tabs a span { font-size: 10.5px; padding: 1px 6px; border-radius: 99px; background: rgba(255,255,255,.08); margin-left: 4px; }
        .ord-tabs a small { display: block; font-weight: 500; font-size: 11px; opacity: .6; margin-top: 2px; }
        .ord-tabs a:hover { border-color: rgba(255,255,255,.2); }
        .ord-tabs a.on { border-color: #ff6b1a; background: rgba(255,107,26,.08); }
        .ord-type { display: inline-block; font-size: 10.5px; font-weight: 600; padding: 2px 7px; border-radius: 99px; white-space: nowrap; }
        .ord-type-bulanan { background: rgba(99,102,241,.16); color: #a5b4fc; }
        .ord-type-manual { background: rgba(245,158,11,.16); color: #fcd34d; }
        .ord-type-website { background: rgba(148,163,184,.16); color: #cbd5e1; }
        @media (max-width: 800px) { .ord-tabs { grid-template-columns: 1fr 1fr; } }
    </style>

    <div class="payment-summary-grid">
        <div class="payment-summary-card payment-summary-card-completed">
            <span class="payment-summary-label">Uang Terkumpul</span>
            <strong>Rp <?php echo number_format($completedTotal, 0, ',', '.'); ?></strong>
            <small><?php echo count($completedOrders); ?> pembayaran berhasil</small>
        </div>
        <div class="payment-summary-card">
            <span class="payment-summary-label">Menunggu Pembayaran</span>
            <strong>Rp <?php echo number_format($pendingTotal, 0, ',', '.'); ?></strong>
            <small>Transaksi belum selesai</small>
        </div>
        <div class="payment-summary-card">
            <span class="payment-summary-label">Total Transaksi</span>
            <strong><?php echo count($allOrders); ?></strong>
            <small><?php echo $typeFilter === 'all' ? 'Semua sumber pembayaran' : htmlspecialchars($typeLabels[$typeFilter]); ?></small>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="admin-alert admin-alert-success"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if (empty($orders)): ?>
        <div class="payment-table-wrap"><div class="adm-empty">Belum ada pesanan.</div></div>
    <?php else: ?>
        <div class="payment-table-wrap">
            <table class="admin-table payment-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Jenis</th>
                        <th>Keterangan</th>
                        <th>Nama</th>
                        <th>WhatsApp</th>
                        <th>Jumlah</th>
                        <th>Status</th>
                        <th>Dibayar</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($order['order_id']); ?></td>
                            <?php $ot = $orderType($order); ?>
                            <td><span class="ord-type ord-type-<?php echo $ot; ?>"><?php echo ['bulanan' => 'Bulanan', 'manual' => 'Manual', 'website' => $sourceLabel($order)][$ot]; ?></span></td>
                            <td><?php echo htmlspecialchars($order['product_title'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($order['name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars(($order['whatsapp'] ?? '') ?: '-'); ?></td>
                            <td class="payment-amount">Rp <?php echo number_format((int) $order['amount'], 0, ',', '.'); ?></td>
                            <td><span class="payment-status payment-status-<?php echo htmlspecialchars(strtolower((string) $order['status'])); ?>"><?php echo htmlspecialchars($order['status']); ?></span></td>
                            <td class="payment-date"><?php echo htmlspecialchars($formatDate($order['completed_at'] ?? null)); ?></td>
                            <td class="payment-actions">
                                <?php if (!empty($order['txn_id'])): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="action" value="refresh">
                                    <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order['order_id']); ?>">
                                    <input type="hidden" name="txn_id" value="<?php echo htmlspecialchars($order['txn_id']); ?>">
                                    <button type="submit" class="btn btn-outline payment-btn-sm">Cek Status</button>
                                </form>
                                <?php endif; ?>
                                <?php if (strtolower((string) $order['status']) !== 'completed'): ?>
                                    <form method="post" onsubmit="return confirm('Hapus pesanan <?php echo htmlspecialchars($order['order_id']); ?>?');">
                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($order['order_id']); ?>">
                                        <button type="submit" class="btn btn-outline payment-btn-sm payment-btn-danger">Hapus</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>