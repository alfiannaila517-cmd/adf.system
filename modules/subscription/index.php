<?php

/**
 * Tagihan Langganan ADF System untuk bisnis yang sedang aktif (flat bulanan, dibayar via Pakasir).
 * Tarif, jatuh tempo, dan kunci sistem dikontrol dari adfsystem.store (menu Klien Langganan).
 *
 * Owner / admin / manager hanya melihat status langganan, tagihan, dan tombol bayar.
 * Pengaturan koneksi & sinkron hanya untuk developer (tab "Koneksi", ?tab=koneksi).
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
$tab = ($isDev && ($_GET['tab'] ?? '') === 'koneksi') ? 'koneksi' : 'tagihan';

$pdo = adfsub_pdo();
adfsub_ensure_schema($pdo);
$flash = null;

// Tombol "Bayar" dari popup / layar kunci / header: langsung buat transaksi & arahkan ke halaman bayar Pakasir
// (user memilih metode QRIS / Virtual Account di sana). Kalau gagal, tetap di halaman ini dengan pesan.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['pay'])) {
    $link = adfsub_create_payment($pdo, (string) $_GET['pay']);
    if ($link) {
        header('Location: ' . $link);
        exit;
    }
    $flash = ['error', 'Gagal membuat link pembayaran. Silakan coba lagi atau hubungi developer.'];
}

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
    } elseif ($action === 'sync_now' && $isDev) {
        $ok = adfsub_sync($pdo, true);
        if ($ok) {
            adfsub_get_or_refresh_invoice($pdo, date('Y-m'));
        }
        $flash = $ok ? ['success', 'Sinkron berhasil.'] : ['error', 'Sinkron gagal. Lihat keterangan di bawah.'];
    } elseif ($action === 'pay') {
        $link = adfsub_create_payment($pdo, (string) ($_POST['period'] ?? ''));
        if ($link) {
            header('Location: ' . $link);
            exit;
        }
        $flash = ['error', 'Gagal membuat link pembayaran. Silakan coba lagi atau hubungi developer.'];
    } elseif ($action === 'check_status') {
        $stmt = $pdo->prepare("SELECT * FROM adf_subscription_invoices WHERE period = ?");
        $stmt->execute([(string) ($_POST['period'] ?? '')]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        $paid = $inv ? adfsub_reconcile($pdo, $inv) : false;
        $flash = $paid ? ['success', 'Pembayaran terkonfirmasi. Terima kasih!'] : ['info', 'Pembayaran belum terkonfirmasi. Jika sudah membayar, tunggu 1–2 menit lalu cek lagi.'];
    }
}

$state = adfsub_tick();
$cfg = adfsub_config($pdo);
$invoices = adfsub_all_invoices($pdo);
$connected = adfsub_is_connected($cfg);

$statusLabel = ['unpaid' => 'Belum Dibayar', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan'];
$rupiah = static fn($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
$tgl = static fn($d) => $d ? date('d M Y', strtotime($d)) : '-';

// Tagihan yang perlu dibayar sekarang (manual langsung, bulanan mulai H-7), dan total semua yang belum dibayar.
$dueBills = $state['due_soon'] ?? [];
$unpaidTotal = array_sum(array_column($state['unpaid'], 'total_amount'));
$dueTotal = array_sum(array_column($dueBills, 'total_amount'));
$hasOverdue = (bool) array_filter($dueBills, static fn($i) => !empty($i['due_date']) && $i['due_date'] < date('Y-m-d'));
$activeUntil = $state['active_until'] ?? '';
$visibleInvoices = array_values(array_filter($invoices, static fn($i) => $isDev || $i['status'] !== 'cancelled'));
$waUrl = 'https://wa.me/628214400664?text=' . rawurlencode('Halo Developer ADF System, saya ingin bertanya tentang langganan ' . (defined('BUSINESS_NAME') ? BUSINESS_NAME : '') . '.');

$pageTitle = 'Tagihan Langganan';
include '../../includes/header.php';
?>
<style>
    .sb { max-width: 960px; font-size: 13px; color: var(--text-primary); }
    .sb-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
    .sb-head h2 { margin: 0; font-size: 18px; }
    .sb-head p { margin: 2px 0 0; font-size: 12px; color: var(--text-muted, #94a3b8); }
    .sb-tabs { display: inline-flex; background: var(--bg-tertiary, #eef2f7); border-radius: 9px; padding: 3px; }
    .sb-tabs a { padding: 5px 12px; border-radius: 7px; font-size: 12px; font-weight: 600; color: var(--text-secondary, #64748b); text-decoration: none; }
    .sb-tabs a.on { background: var(--bg-secondary, #fff); color: var(--text-primary); box-shadow: 0 1px 2px rgba(15, 23, 42, .08); }

    .sb-card { background: var(--bg-secondary, #fff); border: 1px solid var(--bg-tertiary, #e2e8f0); border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; }
    .sb-card h3 { margin: 0 0 10px; font-size: 13px; font-weight: 700; }

    /* Kartu paket */
    .sb-plan { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .sb-plan-ico { width: 42px; height: 42px; border-radius: 11px; display: grid; place-items: center; background: linear-gradient(135deg, #16a34a, #22c55e); color: #fff; font-size: 19px; flex-shrink: 0; }
    .sb-plan.locked .sb-plan-ico { background: linear-gradient(135deg, #dc2626, #f87171); }
    .sb-plan-main { flex: 1; min-width: 180px; }
    .sb-plan-main strong { font-size: 15px; display: flex; align-items: center; gap: 8px; }
    .sb-plan-main span { display: block; font-size: 12px; color: var(--text-muted, #94a3b8); margin-top: 2px; }
    .sb-plan-meta { display: flex; gap: 22px; }
    .sb-plan-meta div small { display: block; font-size: 10.5px; color: var(--text-muted, #94a3b8); text-transform: uppercase; letter-spacing: .04em; }
    .sb-plan-meta div b { font-size: 14px; }

    .sb-badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 10.5px; font-weight: 700; }
    .sb-b-ok, .sb-b-paid { background: rgba(22, 163, 74, .12); color: #15803d; }
    .sb-b-lock, .sb-b-overdue { background: rgba(220, 38, 38, .12); color: #b91c1c; }
    .sb-b-unpaid { background: rgba(245, 158, 11, .15); color: #b45309; }
    .sb-b-cancelled { background: rgba(148, 163, 184, .18); color: #64748b; }

    /* Tagihan yang harus dibayar */
    .sb-due { border-color: #fcd34d; background: #fffbeb; }
    .sb-due.late { border-color: #fca5a5; background: #fef2f2; }
    .sb-due-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .sb-due-info { flex: 1; min-width: 200px; }
    .sb-due-info small { font-size: 11px; font-weight: 600; color: #b45309; }
    .sb-due.late .sb-due-info small { color: #b91c1c; }
    .sb-due-info b { display: block; font-size: 20px; color: #1e293b; margin-top: 1px; }
    .sb-due-info span { font-size: 12px; color: #64748b; }

    .sb-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 30px; padding: 0 12px; border-radius: 8px; font-size: 12px; font-weight: 600; border: 1px solid var(--bg-quaternary, #cbd5e1); background: var(--bg-secondary, #fff); color: var(--text-primary); cursor: pointer; text-decoration: none; white-space: nowrap; }
    .sb-btn-pay { background: #16a34a; border-color: #16a34a; color: #fff !important; -webkit-text-fill-color: #fff; }
    .sb-btn-pay.lg { height: 38px; padding: 0 18px; font-size: 13px; }
    .sb-btn-wa { background: #25d366; border-color: #25d366; color: #fff !important; -webkit-text-fill-color: #fff; }

    .sb-table { width: 100%; border-collapse: collapse; }
    .sb-table th { text-align: left; font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #94a3b8); padding: 7px 10px; border-bottom: 1px solid var(--bg-tertiary, #e2e8f0); }
    .sb-table td { padding: 9px 10px; border-bottom: 1px solid var(--bg-tertiary, #eef2f7); white-space: nowrap; font-size: 12.5px; }
    .sb-table tr:last-child td { border-bottom: 0; }
    .sb-table td small { display: block; font-size: 11px; color: var(--text-muted, #94a3b8); }
    .sb-empty { text-align: center; padding: 22px !important; color: var(--text-muted, #94a3b8); }

    .sb-help { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; font-size: 12px; color: var(--text-muted, #94a3b8); }

    .sb-flash { padding: 9px 13px; border-radius: 9px; font-size: 12.5px; margin-bottom: 12px; }
    .sb-flash-success { background: rgba(22, 163, 74, .1); color: #15803d; }
    .sb-flash-error { background: rgba(220, 38, 38, .1); color: #b91c1c; }
    .sb-flash-info { background: rgba(37, 99, 235, .1); color: #1d4ed8; }

    .sb-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px 12px; }
    .sb-form label { font-size: 11.5px; font-weight: 600; color: var(--text-secondary, #64748b); }
    .sb-form input { display: block; width: 100%; margin-top: 4px; height: 32px; padding: 0 10px; border-radius: 8px; border: 1px solid var(--bg-quaternary, #cbd5e1); background: var(--bg-primary); color: var(--text-primary); font-size: 12.5px; }
    .sb-kv { display: grid; grid-template-columns: 150px 1fr; gap: 6px 12px; font-size: 12.5px; }
    .sb-kv dt { color: var(--text-muted, #94a3b8); }
    .sb-kv dd { margin: 0; }
</style>

<div class="sb">
    <div class="sb-head">
        <div>
            <h2>Langganan ADF System</h2>
            <p>Status paket, tagihan, dan pembayaran langganan sistem.</p>
        </div>
        <?php if ($isDev): ?>
            <div class="sb-tabs">
                <a href="index.php" class="<?php echo $tab === 'tagihan' ? 'on' : ''; ?>">Tagihan</a>
                <a href="index.php?tab=koneksi" class="<?php echo $tab === 'koneksi' ? 'on' : ''; ?>">Koneksi (Developer)</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
        <div class="sb-flash sb-flash-<?php echo htmlspecialchars($flash[0]); ?>"><?php echo htmlspecialchars($flash[1]); ?></div>
    <?php endif; ?>

    <?php if ($tab === 'tagihan'): ?>
        <?php if (!$connected): ?>
            <div class="sb-card sb-help">
                <span>Langganan belum diaktifkan untuk bisnis ini.</span>
                <?php if ($isDev): ?>
                    <a href="index.php?tab=koneksi" class="sb-btn">Atur Koneksi</a>
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars($waUrl); ?>" target="_blank" rel="noopener" class="sb-btn sb-btn-wa">Hubungi Developer</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Paket aktif -->
            <div class="sb-card sb-plan<?php echo $cfg['locked'] ? ' locked' : ''; ?>">
                <div class="sb-plan-ico"><?php echo $cfg['locked'] ? '🔒' : '✓'; ?></div>
                <div class="sb-plan-main">
                    <strong>ADF System Pro <span class="sb-badge <?php echo $cfg['locked'] ? 'sb-b-lock' : 'sb-b-ok'; ?>"><?php echo $cfg['locked'] ? 'Terkunci' : 'Aktif'; ?></span></strong>
                    <span>
                        <?php if ($cfg['locked']): ?>
                            Akses sistem sedang dikunci. Hubungi developer untuk membuka kembali.
                        <?php elseif ($activeUntil): ?>
                            Langganan aktif sampai <b><?php echo $tgl($activeUntil); ?></b>
                        <?php else: ?>
                            Langganan aktif
                        <?php endif; ?>
                    </span>
                </div>
                <div class="sb-plan-meta">
                    <div><small>Biaya / bulan</small><b><?php echo $rupiah($cfg['base_fee']); ?></b></div>
                    <div><small>Belum dibayar</small><b style="color:<?php echo $unpaidTotal > 0 ? '#b45309' : 'inherit'; ?>;"><?php echo $rupiah($unpaidTotal); ?></b></div>
                </div>
            </div>

            <!-- Tagihan yang harus dibayar sekarang -->
            <?php if ($dueBills):
                $first = $dueBills[0];
            ?>
                <div class="sb-card sb-due<?php echo $hasOverdue ? ' late' : ''; ?>">
                    <div class="sb-due-row">
                        <div class="sb-due-info">
                            <small><?php echo $hasOverdue ? 'TAGIHAN LEWAT JATUH TEMPO' : 'TAGIHAN PERLU DIBAYAR'; ?></small>
                            <b><?php echo $rupiah($dueTotal); ?></b>
                            <span>
                                <?php echo htmlspecialchars($first['description'] ?: $first['period']); ?>
                                <?php echo !empty($first['due_date']) ? ' · jatuh tempo ' . $tgl($first['due_date']) : ''; ?>
                                <?php echo count($dueBills) > 1 ? ' · +' . (count($dueBills) - 1) . ' tagihan lain' : ''; ?>
                            </span>
                        </div>
                        <a href="index.php?pay=<?php echo urlencode($first['period']); ?>" class="sb-btn sb-btn-pay lg">Bayar Sekarang</a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Riwayat -->
            <div class="sb-card">
                <h3>Riwayat Tagihan</h3>
                <div style="overflow-x:auto;">
                    <table class="sb-table">
                        <thead>
                            <tr><th>Tagihan</th><th>Jumlah</th><th>Jatuh Tempo</th><th>Status</th><th>Dibayar</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$visibleInvoices): ?>
                                <tr><td colspan="6" class="sb-empty">Belum ada tagihan. Tagihan berikutnya terbit 7 hari sebelum jatuh tempo.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($visibleInvoices as $inv):
                                $overdue = $inv['status'] === 'unpaid' && !empty($inv['due_date']) && $inv['due_date'] < date('Y-m-d');
                                $badge = $overdue ? 'overdue' : $inv['status'];
                            ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($inv['description'] ?: $inv['period']); ?>
                                        <small><?php echo $inv['type'] === 'manual' ? 'Tagihan tambahan' : 'Langganan bulanan'; ?></small>
                                    </td>
                                    <td><strong><?php echo $rupiah($inv['total_amount']); ?></strong></td>
                                    <td><?php echo $tgl($inv['due_date']); ?></td>
                                    <td><span class="sb-badge sb-b-<?php echo htmlspecialchars($badge); ?>"><?php echo $overdue ? 'Lewat Jatuh Tempo' : ($statusLabel[$inv['status']] ?? $inv['status']); ?></span></td>
                                    <td><?php echo $inv['paid_at'] ? date('d M Y H:i', strtotime($inv['paid_at'])) : '-'; ?></td>
                                    <td style="text-align:right;">
                                        <?php if ($inv['status'] === 'unpaid'): ?>
                                            <form method="post" style="display:inline-flex;gap:4px;">
                                                <input type="hidden" name="period" value="<?php echo htmlspecialchars($inv['period']); ?>">
                                                <?php if (!empty($inv['txn_id'])): ?>
                                                    <button type="submit" name="action" value="check_status" class="sb-btn">Cek Status</button>
                                                <?php endif; ?>
                                                <button type="submit" name="action" value="pay" class="sb-btn sb-btn-pay">Bayar</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="sb-help">
                <span>Pembayaran via Pakasir (QRIS / Virtual Account). Status lunas terkonfirmasi otomatis dalam 1–2 menit.</span>
                <a href="<?php echo htmlspecialchars($waUrl); ?>" target="_blank" rel="noopener" class="sb-btn sb-btn-wa">Hubungi Developer</a>
            </div>
        <?php endif; ?>

    <?php else: /* tab koneksi — developer saja */ ?>
        <div class="sb-card">
            <h3>Status Sinkron</h3>
            <dl class="sb-kv">
                <dt>Klien di ADF</dt><dd><?php echo htmlspecialchars($cfg['client_name'] ?? '') ?: '-'; ?></dd>
                <dt>Sinkron terakhir</dt><dd><?php echo $cfg['last_sync_at'] ? date('d M Y H:i', strtotime($cfg['last_sync_at'])) : '-'; ?></dd>
                <dt>Hasil</dt>
                <dd><?php echo $cfg['last_sync_error']
                        ? '<span class="sb-badge sb-b-lock">Gagal</span> ' . htmlspecialchars($cfg['last_sync_error'])
                        : ($connected ? '<span class="sb-badge sb-b-ok">Berhasil</span>' : '-'); ?></dd>
                <dt>Status kunci</dt><dd><?php echo $cfg['locked'] ? 'Terkunci' : 'Tidak dikunci'; ?></dd>
                <dt>Jatuh tempo pertama</dt><dd><?php echo $tgl(adfsub_anchor_due_date($cfg)); ?></dd>
            </dl>
            <form method="post" style="margin-top:12px;">
                <button type="submit" name="action" value="sync_now" class="sb-btn">Sinkron Sekarang</button>
            </form>
        </div>

        <div class="sb-card">
            <h3>Koneksi ke adfsystem.store</h3>
            <form method="post">
                <input type="hidden" name="action" value="save_connection">
                <div class="sb-form">
                    <label>Client Key<input name="client_key" value="<?php echo htmlspecialchars($cfg['client_key']); ?>" placeholder="mis. narayana-hotel"></label>
                    <label>Client Token<input name="client_token" value="<?php echo htmlspecialchars($cfg['client_token']); ?>"></label>
                    <label>URL Sinkronisasi<input name="sync_url" value="<?php echo htmlspecialchars($cfg['sync_url']); ?>"></label>
                </div>
                <div style="display:flex;align-items:center;gap:10px;margin-top:12px;flex-wrap:wrap;">
                    <button type="submit" class="sb-btn sb-btn-pay">Simpan &amp; Sinkron</button>
                    <span style="font-size:11.5px;color:var(--text-muted,#94a3b8);">Salin Client Key &amp; Token dari adfsystem.store → Klien Langganan.</span>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
