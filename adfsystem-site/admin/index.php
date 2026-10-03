<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/orders-store.php';
require_once __DIR__ . '/../includes/subscription-clients-store.php';
adf_admin_require_login();

$isAdmin = (adf_admin_current_user()['role'] ?? '') === 'admin';

// Ringkasan angka utama untuk dashboard.
$orders = adf_orders_load();
$completedTotal = 0;
$completedCount = 0;
$pendingCount = 0;
$customerKeys = [];
foreach ($orders as $order) {
    $status = strtolower((string) ($order['status'] ?? ''));
    if ($status === 'completed') {
        $completedTotal += (int) ($order['amount'] ?? 0);
        $completedCount++;
    } elseif ($status === 'pending') {
        $pendingCount++;
    }
    $key = trim((string) ($order['whatsapp'] ?? '')) ?: trim((string) ($order['name'] ?? ''));
    if ($key !== '') {
        $customerKeys[$key] = true;
    }
}
$clientCount = count(adf_subscription_clients_load());
$recentOrders = array_slice(array_reverse($orders), 0, 5);

$quickLinks = [
    'Bisnis' => [
        ['orders.php', 'receipt', 'Transaksi', 'Pembayaran langganan via Pakasir'],
        ['customers.php', 'users', 'Pelanggan', 'Daftar pelanggan & riwayat'],
    ],
    'Konten Website' => [
        ['edit-hero.php', 'home', 'Hero Beranda', 'Judul & deskripsi utama'],
        ['edit-modules.php', 'blocks', 'Modul', 'Fitur yang tampil di beranda'],
        ['edit-layanan.php', 'layers', 'Layanan', 'Isi halaman Layanan'],
        ['edit-products.php', 'tag', 'Paket Harga', 'Paket di halaman Harga'],
        ['edit-portfolio.php', 'folder', 'Portofolio', 'Produk & website contoh'],
        ['edit-clients.php', 'building', 'Klien', 'Logo perusahaan klien'],
        ['edit-logo.php', 'image', 'Logo', 'Logo header website & admin'],
        ['edit-contact.php', 'mail', 'Kontak', 'Email, WhatsApp, alamat'],
    ],
];
if ($isAdmin) {
    $quickLinks['Bisnis'][] = ['subscription-clients.php', 'link', 'Klien Langganan', 'Tarif & tagihan klien sistem'];
    $quickLinks['Pengaturan'] = [
        ['edit-payment.php', 'card', 'Payment Gateway', 'Koneksi Pakasir'],
        ['users.php', 'user', 'Pengguna', 'Akun yang bisa login admin'],
    ];
}

$adminPageTitle = 'Dashboard';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="admin-container admin-container-wide">
    <h1>Dashboard</h1>
    <p class="admin-lead">Ringkasan transaksi dan akses cepat ke pengaturan website ADF System.</p>

    <div class="adm-stats">
        <div class="adm-stat adm-tone-green">
            <span class="adm-stat-label"><span class="adm-stat-ico"><?php echo adf_admin_icon('wallet', 14); ?></span>Uang Terkumpul</span>
            <span class="adm-stat-value">Rp <?php echo number_format($completedTotal, 0, ',', '.'); ?></span>
            <span class="adm-stat-sub"><?php echo $completedCount; ?> pembayaran berhasil</span>
        </div>
        <div class="adm-stat adm-tone-amber">
            <span class="adm-stat-label"><span class="adm-stat-ico"><?php echo adf_admin_icon('clock', 14); ?></span>Menunggu Bayar</span>
            <span class="adm-stat-value"><?php echo $pendingCount; ?></span>
            <span class="adm-stat-sub">transaksi pending</span>
        </div>
        <div class="adm-stat adm-tone-blue">
            <span class="adm-stat-label"><span class="adm-stat-ico"><?php echo adf_admin_icon('users', 14); ?></span>Pelanggan</span>
            <span class="adm-stat-value"><?php echo count($customerKeys); ?></span>
            <span class="adm-stat-sub">dari <?php echo count($orders); ?> pesanan</span>
        </div>
        <div class="adm-stat adm-tone-accent">
            <span class="adm-stat-label"><span class="adm-stat-ico"><?php echo adf_admin_icon('link', 14); ?></span>Klien Langganan</span>
            <span class="adm-stat-value"><?php echo $clientCount; ?></span>
            <span class="adm-stat-sub">sistem aktif terhubung</span>
        </div>
    </div>

    <div class="admin-subheading" style="display:flex;justify-content:space-between;align-items:center;margin-top:0;">
        <span>Transaksi Terbaru</span>
        <a href="orders.php" style="font-size:12px;font-weight:500;">Lihat semua →</a>
    </div>
    <div class="payment-table-wrap">
        <?php if (empty($recentOrders)): ?>
            <div class="adm-empty">Belum ada transaksi.</div>
        <?php else: ?>
            <table class="payment-table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Paket</th>
                        <th>Jumlah</th>
                        <th>Status</th>
                        <th>Tanggal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentOrders as $order): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($order['name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($order['product_title'] ?? '-'); ?></td>
                            <td class="payment-amount">Rp <?php echo number_format((int) ($order['amount'] ?? 0), 0, ',', '.'); ?></td>
                            <td><span class="payment-status payment-status-<?php echo htmlspecialchars(strtolower((string) ($order['status'] ?? ''))); ?>"><?php echo htmlspecialchars($order['status'] ?? '-'); ?></span></td>
                            <td class="payment-date"><?php echo !empty($order['created_at']) ? date('d M Y, H:i', strtotime($order['created_at'])) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php foreach ($quickLinks as $groupTitle => $links): ?>
        <div class="adm-section-title"><?php echo htmlspecialchars($groupTitle); ?></div>
        <div class="adm-quick-grid">
            <?php foreach ($links as [$href, $icon, $label, $desc]): ?>
                <a class="adm-quick" href="<?php echo htmlspecialchars($href); ?>">
                    <span class="adm-quick-ico"><?php echo adf_admin_icon($icon, 16); ?></span>
                    <span>
                        <strong><?php echo htmlspecialchars($label); ?></strong>
                        <small><?php echo htmlspecialchars($desc); ?></small>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
