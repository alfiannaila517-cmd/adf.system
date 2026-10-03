<?php

/**
 * Tagihan Langganan ADF System untuk bisnis yang sedang aktif (flat bulanan, dibayar via Pakasir).
 * Tarif, jatuh tempo, dan kunci sistem dikontrol dari adfsystem.store (menu Klien Langganan).
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/subscription_client.php';

$auth = new Auth();
$auth->requireLogin();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['developer', 'owner', 'admin', 'manager'], true)) {
    http_response_code(403);
    exit('Halaman ini khusus owner / admin.');
}
$isDev = $role === 'developer';

$pdo = adfsub_pdo();
adfsub_ensure_schema($pdo);
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_connection' && $isDev) {
        adfsub_set_setting($pdo, 'subscription_client_key', trim($_POST['client_key'] ?? ''));
        adfsub_set_setting($pdo, 'subscription_client_token', trim($_POST['client_token'] ?? ''));
        adfsub_set_setting($pdo, 'subscription_sync_url', trim($_POST['sync_url'] ?? '') ?: ADFSUB_DEFAULT_SYNC_URL);
        $ok = adfsub_sync($pdo, true);
        if ($ok) {
            adfsub_get_or_refresh_invoice($pdo, date('Y-m'));
        }
        $flash = $ok ? ['success', 'Koneksi tersimpan dan berhasil sinkron dengan ADF System.'] : ['error', 'Koneksi tersimpan, tapi sinkron gagal. Cek Client Key / Token.'];
    } elseif ($action === 'sync_now') {
        $ok = adfsub_sync($pdo, true);
        if ($ok) {
            adfsub_get_or_refresh_invoice($pdo, date('Y-m'));
        }
        $flash = $ok ? ['success', 'Sinkron berhasil.'] : ['error', 'Sinkron gagal. Coba lagi beberapa saat.'];
    } elseif ($action === 'pay') {
        $link = adfsub_create_payment($pdo, (string) ($_POST['period'] ?? ''));
        if ($link) {
            header('Location: ' . $link);
            exit;
        }
        $flash = ['error', 'Gagal membuat link pembayaran. Pastikan akun Pakasir sudah diatur di ADF System.'];
    } elseif ($action === 'check_status') {
        $stmt = $pdo->prepare("SELECT * FROM adf_subscription_invoices WHERE period = ?");
        $stmt->execute([(string) ($_POST['period'] ?? '')]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        $paid = $inv ? adfsub_reconcile($pdo, $inv) : false;
        $flash = $paid ? ['success', 'Pembayaran terkonfirmasi. Terima kasih!'] : ['info', 'Pembayaran belum terkonfirmasi di Pakasir.'];
    }
}

$state = adfsub_tick();
$cfg = adfsub_config($pdo);
$invoices = adfsub_all_invoices($pdo);
$connected = adfsub_is_connected($cfg);

$statusLabel = ['unpaid' => 'Belum Dibayar', 'paid' => 'Lunas', 'cancelled' => 'Batal'];
$rupiah = static fn($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
$tgl = static fn($d) => $d ? date('d M Y', strtotime($d)) : '-';

$pageTitle = 'Tagihan Langganan';
include '../../includes/header.php';
?>
<style>
    .sub-wrap { max-width: 980px; }
    .sub-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 16px; }
    .sub-stat { background: var(--bg-secondary); border: 1px solid var(--bg-tertiary); border-radius: 12px; padding: 12px 14px; }
    .sub-stat small { display: block; font-size: 11px; color: var(--text-muted, #94a3b8); }
    .sub-stat strong { display: block; font-size: 17px; margin-top: 4px; color: var(--text-primary); }
    .sub-card { background: var(--bg-secondary); border: 1px solid var(--bg-tertiary); border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; }
    .sub-card h3 { font-size: 14px; margin: 0 0 10px; color: var(--text-primary); }
    .sub-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .sub-table th, .sub-table td { padding: 8px 10px; border-bottom: 1px solid var(--bg-tertiary); text-align: left; white-space: nowrap; }
    .sub-table th { font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted, #94a3b8); }
    .sub-badge { display: inline-block; padding: 2px 9px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .sub-badge-unpaid { background: rgba(245, 158, 11, .15); color: #d97706; }
    .sub-badge-paid { background: rgba(16, 185, 129, .15); color: #059669; }
    .sub-badge-cancelled { background: rgba(148, 163, 184, .2); color: #64748b; }
    .sub-badge-overdue { background: rgba(239, 68, 68, .15); color: #dc2626; }
    .sub-btn { display: inline-flex; align-items: center; height: 28px; padding: 0 11px; border-radius: 7px; font-size: 12px; font-weight: 600; border: 1px solid var(--bg-quaternary, #cbd5e1); background: transparent; color: var(--text-primary); cursor: pointer; }
    .sub-btn-primary { background: #16a34a; border-color: #16a34a; color: #fff; }
    .sub-form label { display: block; font-size: 12px; margin-bottom: 10px; color: var(--text-secondary, #64748b); }
    .sub-form input { display: block; width: 100%; margin-top: 4px; padding: 7px 10px; border-radius: 8px; border: 1px solid var(--bg-quaternary, #cbd5e1); background: var(--bg-primary); color: var(--text-primary); font-size: 13px; }
    .sub-flash { padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }
    .sub-flash-success { background: rgba(16, 185, 129, .12); color: #059669; }
    .sub-flash-error { background: rgba(239, 68, 68, .12); color: #dc2626; }
    .sub-flash-info { background: rgba(59, 130, 246, .12); color: #2563eb; }
</style>

<div class="sub-wrap">
    <h2 style="margin:0 0 4px;font-size:20px;">Tagihan Langganan ADF System</h2>
    <p style="margin:0 0 16px;font-size:13px;color:var(--text-muted,#94a3b8);">Biaya langganan bulanan sistem. Tarif dan jatuh tempo diatur oleh ADF System; pembayaran via Pakasir (QRIS / Virtual Account).</p>

    <?php if ($flash): ?>
        <div class="sub-flash sub-flash-<?php echo htmlspecialchars($flash[0]); ?>"><?php echo htmlspecialchars($flash[1]); ?></div>
    <?php endif; ?>

    <?php if (!$connected): ?>
        <div class="sub-card">Tagihan langganan belum diaktifkan untuk bisnis ini<?php echo $isDev ? ' — isi koneksi ADF System di bawah.' : '. Hubungi developer.'; ?></div>
    <?php else: ?>
        <div class="sub-grid">
            <div class="sub-stat"><small>Status Sistem</small><strong style="color:<?php echo $cfg['locked'] ? '#dc2626' : '#059669'; ?>;"><?php echo $cfg['locked'] ? 'Terkunci' : 'Aktif'; ?></strong></div>
            <div class="sub-stat"><small>Biaya Bulanan</small><strong><?php echo $rupiah($cfg['base_fee']); ?></strong></div>
            <?php
            // Jatuh tempo berikutnya: tagihan belum dibayar terdekat, atau jadwal bulan ini / jatuh tempo pertama.
            $nextDue = $state['unpaid'][0]['due_date'] ?? null;
            if (!$nextDue) {
                $firstPeriod = adfsub_first_period($cfg);
                $nextDue = ($firstPeriod !== '' && date('Y-m') < $firstPeriod) ? adfsub_anchor_due_date($cfg) : adfsub_due_date($cfg, date('Y-m'));
            }
            ?>
            <div class="sub-stat"><small>Jatuh Tempo Berikutnya</small><strong><?php echo $tgl($nextDue); ?></strong></div>
            <div class="sub-stat"><small>Belum Dibayar</small><strong><?php echo $rupiah(array_sum(array_column($state['unpaid'], 'total_amount'))); ?></strong></div>
        </div>

        <div class="sub-card">
            <h3>Riwayat Tagihan</h3>
            <div style="overflow-x:auto;">
                <table class="sub-table">
                    <thead>
                        <tr><th>Tagihan</th><th>Jumlah</th><th>Jatuh Tempo</th><th>Status</th><th>Dibayar</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($invoices)): ?>
                            <tr><td colspan="6" style="text-align:center;color:var(--text-muted,#94a3b8);">Belum ada tagihan.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($invoices as $inv):
                            $overdue = $inv['status'] === 'unpaid' && !empty($inv['due_date']) && $inv['due_date'] < date('Y-m-d');
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($inv['description'] ?: $inv['period']); ?></td>
                                <td><strong><?php echo $rupiah($inv['total_amount']); ?></strong></td>
                                <td><?php echo $tgl($inv['due_date']); ?></td>
                                <td><span class="sub-badge sub-badge-<?php echo $overdue ? 'overdue' : htmlspecialchars($inv['status']); ?>"><?php echo $overdue ? 'Lewat Jatuh Tempo' : ($statusLabel[$inv['status']] ?? $inv['status']); ?></span></td>
                                <td><?php echo $inv['paid_at'] ? date('d M Y H:i', strtotime($inv['paid_at'])) : '-'; ?></td>
                                <td style="text-align:right;">
                                    <?php if ($inv['status'] === 'unpaid'): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="period" value="<?php echo htmlspecialchars($inv['period']); ?>">
                                            <button type="submit" name="action" value="pay" class="sub-btn sub-btn-primary">Bayar</button>
                                            <?php if (!empty($inv['txn_id'])): ?>
                                                <button type="submit" name="action" value="check_status" class="sub-btn">Cek Status</button>
                                            <?php endif; ?>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <form method="post" style="margin-bottom:16px;font-size:12px;color:var(--text-muted,#94a3b8);">
            Sinkron terakhir: <?php echo $cfg['last_sync_at'] ? date('d M Y H:i', strtotime($cfg['last_sync_at'])) : '-'; ?>
            <?php if ($cfg['last_sync_error']): ?><span style="color:#dc2626;"> — <?php echo htmlspecialchars($cfg['last_sync_error']); ?></span><?php endif; ?>
            <button type="submit" name="action" value="sync_now" class="sub-btn" style="margin-left:8px;">Sinkron Sekarang</button>
        </form>
    <?php endif; ?>

    <?php if ($isDev): ?>
        <div class="sub-card">
            <h3>Koneksi ke ADF System (khusus developer)</h3>
            <form method="post" class="sub-form">
                <input type="hidden" name="action" value="save_connection">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 12px;">
                    <label>Client Key<input name="client_key" value="<?php echo htmlspecialchars($cfg['client_key']); ?>" placeholder="mis. narayana-hotel"></label>
                    <label>Client Token<input name="client_token" value="<?php echo htmlspecialchars($cfg['client_token']); ?>"></label>
                    <label>URL Sinkronisasi<input name="sync_url" value="<?php echo htmlspecialchars($cfg['sync_url']); ?>"></label>
                </div>
                <button type="submit" class="sub-btn sub-btn-primary">Simpan &amp; Sinkron</button>
                <span style="font-size:11.5px;color:var(--text-muted,#94a3b8);margin-left:8px;">Client Key &amp; Token dari adfsystem.store → Klien Langganan.</span>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
