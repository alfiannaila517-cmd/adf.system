<?php

/**
 * TAGIHAN BISNIS & GUDANG (dilihat dari outlet: Narayana / Bens Cafe / Eat Meet)
 *
 * Semua uang antar bisnis lewat Gudang Nasita, dipisah PER BULAN:
 *  A) Tagihan dari Gudang — yang harus DIBAYAR outlet: barang dari Gudang + barang yang diterima
 *     dari bisnis lain + bagian TKBM. Angka dari gudangMonthlyBillBreakdown() (sama persis dengan
 *     halaman Tagihan Bisnis di Gudang dan proses bayar).
 *  B) Barang yang kami kirim — yang akan DITERIMA outlet: kiriman ke Gudang / bisnis lain, dibayar
 *     Gudang (getGudangNasitaIncomingSupplyBills), termasuk barang yang diambil langsung oleh Gudang.
 *  C) Uang masuk dari Gudang — pembayaran yang sudah masuk ke buku kas outlet bulan ini.
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/business_helper.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('bills')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Tagihan Bisnis & Gudang';

$bizConfig = getActiveBusinessConfig();
$activeSlug = gudangNormalizeBizSlug((string)($bizConfig['business_id'] ?? ''));
$activeName = (string)($bizConfig['name'] ?? '');
$isTracked = in_array($activeSlug, array_column(gudangTrackedBizList(), 'slug'), true);

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['bulan'] ?? '')) ? $_GET['bulan'] : date('Y-m');
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));
$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));

// ── POST: bayar tagihan Gudang bulan ini (rekening bank outlet → rekening Gudang) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_gudang_bill') {
    $payMonth = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['bulan'] ?? '')) ? $_POST['bulan'] : $month;
    try {
        setFlash('success', gudangTagihanPayMonthlyBill($activeSlug, $payMonth, (int)($currentUser['id'] ?? 0)));
    } catch (Throwable $e) {
        setFlash('error', 'Gagal membayar: ' . $e->getMessage());
    }
    header('Location: business-warehouse.php?bulan=' . urlencode($payMonth));
    exit;
}

// ── POST: isi harga barang kiriman yang belum berharga (hanya oleh bisnis PENGIRIM) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_transfer_price') {
    $transferId = (int)($_POST['transfer_id'] ?? 0);
    $newUnitPrice = (float)($_POST['unit_price'] ?? 0);
    if ($transferId > 0 && $newUnitPrice > 0) {
        try {
            $pdo = gudangMasterPdo();
            $row = $pdo->prepare('SELECT source_business_slug, quantity, subtotal FROM business_inter_stock_transfers WHERE id = ? LIMIT 1');
            $row->execute([$transferId]);
            $t = $row->fetch();
            // Harga yang sudah terisi tidak boleh diubah dari sini (bisa mengubah tagihan yang sudah dibayar).
            if ($t && gudangNormalizeBizSlug((string)$t['source_business_slug']) === $activeSlug && (float)$t['subtotal'] <= 0) {
                $pdo->prepare('UPDATE business_inter_stock_transfers SET unit_price = ?, subtotal = ? WHERE id = ?')
                    ->execute([$newUnitPrice, $newUnitPrice * (float)$t['quantity'], $transferId]);
                setFlash('success', 'Harga tersimpan.');
            }
        } catch (Throwable $e) {
            error_log('business-warehouse set_transfer_price: ' . $e->getMessage());
        }
    }
    header('Location: business-warehouse.php?bulan=' . urlencode($month));
    exit;
}

// ── A) Tagihan dari Gudang ──
$bill = null;
if ($isTracked) {
    try {
        $bill = gudangMonthlyBillBreakdown($activeSlug, $month);
    } catch (Throwable $e) {
        error_log('business-warehouse bill: ' . $e->getMessage());
    }
}
$billPaid = $bill && $bill['paid'] !== null;
$billAmount = $bill ? ($billPaid ? $bill['paid']['amount'] : $bill['total']) : 0.0;

// ── B) Barang yang kami kirim bulan ini ──
$sentItems = [];
$sentTotal = 0.0;
$sentUnpaid = 0.0;
$owedAllPeriods = 0.0;
try {
    foreach (getGudangNasitaIncomingSupplyBills() as $sup) {
        if ($sup['slug'] !== $activeSlug) {
            continue;
        }
        $owedAllPeriods = (float)$sup['outstanding'];
        foreach ($sup['items'] as $it) {
            if (substr((string)$it['date'], 0, 7) !== $month) {
                continue;
            }
            $sentItems[] = $it;
            $sentTotal += $it['value'];
            if (in_array($it['status'], ['belum', 'sebagian'], true)) {
                $sentUnpaid += $it['value'];
            }
        }
    }
} catch (Throwable $e) {
    error_log('business-warehouse sent: ' . $e->getMessage());
}

// ── C) Uang masuk dari Gudang bulan ini (buku kas outlet) ──
$receivedRows = [];
try {
    $receivedRows = $db->fetchAll(
        "SELECT transaction_date, amount, description FROM cash_book
         WHERE source_type = 'gudang_supply_income' AND transaction_date BETWEEN ? AND ?
         ORDER BY transaction_date DESC, id DESC",
        [$monthStart, $monthEnd]
    ) ?: [];
} catch (Throwable $e) {
}
$receivedTotal = array_sum(array_column($receivedRows, 'amount'));

$statusStyle = [
    'lunas'    => ['#dcfce7', '#166534', 'Sudah dibayar'],
    'sebagian' => ['#fef3c7', '#92400e', 'Dibayar sebagian'],
    'belum'    => ['#fee2e2', '#991b1b', 'Belum dibayar'],
    'dipotong' => ['#e0e7ff', '#3730a3', 'Dipotong tagihan'],
    'diambil'  => ['#dbeafe', '#1e40af', 'Diambil Gudang · lunas'],
];
$fmt = function ($n) {
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
};
$qtyFmt = function ($q) {
    return rtrim(rtrim(number_format((float)$q, 2, '.', ''), '0'), '.');
};

include '../../includes/header.php';
?>

<style>
    .bw { --line:rgba(148,163,184,.3); --muted:#94a3b8; }
    .bw-head { display:flex; justify-content:space-between; align-items:flex-end; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; }
    .bw-month { display:flex; align-items:center; gap:.4rem; }
    .bw-month a { display:inline-grid; place-items:center; width:34px; height:34px; border:1px solid var(--line); border-radius:.5rem; background:transparent; color:var(--text-primary); text-decoration:none; font-weight:700; }
    .bw-kpis { display:grid; grid-template-columns:repeat(4, 1fr); gap:.75rem; margin-bottom:1rem; }
    .bw-kpi { background:var(--bg-primary, #fff); border:1px solid var(--line); border-top:3px solid var(--k); border-radius:.75rem; padding:.8rem .95rem; }
    .bw-kpi small { display:block; font-size:.68rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--muted); }
    .bw-kpi b { display:block; font-size:1.2rem; margin:.2rem 0 .1rem; color:var(--text-primary); }
    .bw-kpi span { font-size:.72rem; color:var(--muted); }
    .bw-card { background:var(--bg-primary, #fff); border:1px solid var(--line); border-radius:.85rem; padding:1rem 1.1rem; margin-bottom:1rem; }
    .bw-card h3 { font-size:1rem; font-weight:700; margin:0; display:flex; align-items:center; gap:.45rem; color:var(--text-primary); }
    .bw-card .sub { font-size:.78rem; color:var(--muted); margin:.2rem 0 .75rem; }
    .bw-tag { display:inline-block; font-size:.66rem; font-weight:700; padding:2px 8px; border-radius:999px; white-space:nowrap; }
    .bw-table { width:100%; border-collapse:collapse; font-size:.8rem; }
    .bw-table th { text-align:left; font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); padding:.45rem .5rem; border-bottom:1px solid var(--line); }
    .bw-table td { padding:.5rem; border-bottom:1px solid rgba(148,163,184,.18); color:var(--text-primary); }
    .bw-table .r { text-align:right; white-space:nowrap; }
    .bw-group { font-size:.74rem; font-weight:700; color:var(--text-primary) !important; background:rgba(148,163,184,.14); }
    .bw-total { display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; margin-top:.75rem; padding:.75rem .9rem; border-radius:.65rem; background:rgba(148,163,184,.12); color:var(--text-primary); }
    .bw-empty { padding:1rem; text-align:center; color:var(--muted); font-size:.84rem; }
    .bw-step { display:inline-grid; place-items:center; width:22px; height:22px; border-radius:50%; background:#1e40af; color:#fff !important; font-size:.72rem; font-weight:800; }
    @media (max-width: 900px) { .bw-kpis { grid-template-columns:repeat(2, 1fr); } }
</style>

<div class="bw">
    <div class="bw-head">
        <div>
            <h2 style="font-size:1.3rem;font-weight:800;margin:0;color:var(--text-primary);">Tagihan Bisnis &amp; Gudang</h2>
            <p style="font-size:.82rem;color:var(--muted);margin:.25rem 0 0;max-width:760px;">Semua uang antar bisnis lewat Gudang Nasita, per bulan. <b>(1)</b> Tagihan dari Gudang yang harus dibayar <?php echo htmlspecialchars($activeName); ?>. <b>(2)</b> Barang yang kami kirim, dibayar oleh Gudang. <b>(3)</b> Uang yang sudah masuk dari Gudang.</p>
        </div>
        <form method="GET" class="bw-month">
            <a href="?bulan=<?php echo $prevMonth; ?>" title="Bulan sebelumnya">‹</a>
            <input type="month" name="bulan" value="<?php echo htmlspecialchars($month); ?>" class="form-control" style="width:auto;" onchange="this.form.submit()">
            <a href="?bulan=<?php echo $nextMonth; ?>" title="Bulan berikutnya">›</a>
        </form>
    </div>

    <div class="bw-kpis">
        <div class="bw-kpi" style="--k:#e11d48;">
            <small>Tagihan dari Gudang · <?php echo $monthLabel; ?></small>
            <b><?php echo $fmt($billAmount); ?></b>
            <span><?php echo !$isTracked ? 'Tidak ditagih bulanan' : ($billPaid ? '✅ Sudah dibayar' : ($billAmount > 0 ? 'Belum dibayar' : 'Tidak ada tagihan')); ?></span>
        </div>
        <div class="bw-kpi" style="--k:#7c3aed;">
            <small>Barang kami kirim · <?php echo $monthLabel; ?></small>
            <b><?php echo $fmt($sentTotal); ?></b>
            <span>Belum dibayar Gudang <?php echo $fmt($sentUnpaid); ?></span>
        </div>
        <div class="bw-kpi" style="--k:#059669;">
            <small>Uang masuk dari Gudang · <?php echo $monthLabel; ?></small>
            <b><?php echo $fmt($receivedTotal); ?></b>
            <span>Tercatat di buku kas sebagai pendapatan</span>
        </div>
        <div class="bw-kpi" style="--k:#2563eb;">
            <small>Gudang masih berhutang ke kami</small>
            <b><?php echo $fmt($owedAllPeriods); ?></b>
            <span>Semua periode</span>
        </div>
    </div>

    <!-- (1) Tagihan dari Gudang -->
    <div class="bw-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
            <div>
                <h3><span class="bw-step">1</span> Tagihan dari Gudang · <?php echo $monthLabel; ?></h3>
                <p class="sub">Barang yang kami terima dari Gudang dan dari bisnis lain bulan ini, ditambah bagian biaya TKBM. Dibayar ke Gudang (Gudang meneruskan ke bisnis pengirim).</p>
            </div>
            <?php if ($billPaid): ?>
                <span class="bw-tag" style="background:#dcfce7;color:#166534;font-size:.75rem;">✅ Lunas · <?php echo date('d M Y', strtotime((string)$bill['paid']['paid_at'])); ?></span>
            <?php endif; ?>
        </div>
        <?php if (!$isTracked): ?>
            <div class="bw-empty">Bisnis ini tidak termasuk tagihan bulanan Gudang.</div>
        <?php elseif (!$bill || (!$billPaid && empty($bill['gudang_items']) && empty($bill['from_biz_items']) && $bill['tkbm_share'] <= 0)): ?>
            <div class="bw-empty">Tidak ada tagihan dari Gudang pada <?php echo $monthLabel; ?>.</div>
        <?php else: ?>
            <?php if (empty($bill['gudang_items']) && empty($bill['from_biz_items']) && $bill['tkbm_share'] <= 0): ?>
                <div class="bw-empty">Rincian barang bulan ini tidak tersedia (tagihan lama).</div>
            <?php else: ?>
            <div style="max-height:420px;overflow-y:auto;">
                <table class="bw-table">
                    <thead><tr><th>Tanggal</th><th>Barang</th><th class="r">Qty</th><th>No.</th><th class="r">Nilai</th></tr></thead>
                    <tbody>
                        <?php if (!empty($bill['gudang_items'])): ?>
                            <tr><td colspan="5" class="bw-group">Dari Gudang Nasita · <?php echo $fmt($bill['gudang_total']); ?></td></tr>
                            <?php foreach ($bill['gudang_items'] as $it): ?>
                                <tr>
                                    <td><?php echo date('d M', strtotime($it['date'])); ?></td>
                                    <td style="font-weight:600;"><?php echo htmlspecialchars($it['item_name']); ?></td>
                                    <td class="r"><?php echo $qtyFmt($it['quantity']) . ' ' . htmlspecialchars($it['unit']); ?></td>
                                    <td style="color:var(--muted);font-size:.72rem;"><?php echo htmlspecialchars($it['number']); ?></td>
                                    <td class="r"><?php echo $fmt($it['value']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($bill['from_biz_items'])): ?>
                            <tr><td colspan="5" class="bw-group">Dari bisnis lain (dibayar lewat Gudang) · <?php echo $fmt($bill['from_biz_total']); ?></td></tr>
                            <?php foreach ($bill['from_biz_items'] as $it): ?>
                                <tr>
                                    <td><?php echo date('d M', strtotime($it['date'])); ?></td>
                                    <td style="font-weight:600;"><?php echo htmlspecialchars($it['item_name']); ?> <span style="color:var(--muted);font-weight:400;font-size:.72rem;">dari <?php echo htmlspecialchars($it['from']); ?></span><?php if ($it['estimated']): ?> <span class="bw-tag" style="background:#fef3c7;color:#92400e;">estimasi harga</span><?php endif; ?></td>
                                    <td class="r"><?php echo $qtyFmt($it['quantity']) . ' ' . htmlspecialchars($it['unit']); ?></td>
                                    <td style="color:var(--muted);font-size:.72rem;"><?php echo htmlspecialchars($it['number']); ?></td>
                                    <td class="r"><?php echo $fmt($it['value']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if ($bill['tkbm_share'] > 0): ?>
                            <tr><td colspan="4" class="bw-group">Bagian biaya TKBM (bongkar muat) — <?php echo $fmt($bill['tkbm_total']); ?> ÷ 3 bisnis</td><td class="r bw-group"><?php echo $fmt($bill['tkbm_share']); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <div class="bw-total">
                <div>
                    <div style="font-size:.74rem;color:var(--muted);"><?php echo $billPaid ? 'Jumlah yang dibayar' : 'Total harus dibayar ke Gudang'; ?></div>
                    <div style="font-size:1.2rem;font-weight:800;color:<?php echo $billPaid ? '#10b981' : '#f43f5e'; ?>;"><?php echo $fmt($billAmount); ?></div>
                    <?php if ($billPaid && $bill['total'] > 0 && abs($bill['paid']['amount'] - $bill['total']) > 1): ?>
                        <div style="font-size:.7rem;color:#92400e;">Rincian sekarang Rp <?php echo number_format($bill['total'], 0, ',', '.'); ?> (ada perubahan setelah dibayar) — hubungi Gudang.</div>
                    <?php endif; ?>
                </div>
                <?php if (!$billPaid && $billAmount > 0): ?>
                    <form method="POST" onsubmit="if (confirm('Bayar tagihan Gudang <?php echo $monthLabel; ?> sebesar <?php echo $fmt($billAmount); ?>?\n\nUang dipotong dari rekening bank <?php echo htmlspecialchars(addslashes($activeName), ENT_QUOTES); ?> dan tercatat sebagai pengeluaran.')) { this.querySelector('button').disabled = true; return true; } return false;">
                        <input type="hidden" name="action" value="pay_gudang_bill">
                        <input type="hidden" name="bulan" value="<?php echo htmlspecialchars($month); ?>">
                        <button type="submit" class="btn btn-primary" style="font-weight:700;">Bayar Tagihan <?php echo $monthLabel; ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- (2) Barang yang kami kirim -->
    <div class="bw-card">
        <h3><span class="bw-step">2</span> Barang yang kami kirim · <?php echo $monthLabel; ?></h3>
        <p class="sub">Kiriman <?php echo htmlspecialchars($activeName); ?> ke Gudang atau ke bisnis lain, termasuk barang yang diambil langsung oleh Gudang. Semuanya dibayar oleh Gudang dan masuk sebagai pendapatan kami.</p>
        <?php if (empty($sentItems)): ?>
            <div class="bw-empty">Tidak ada barang yang kami kirim pada <?php echo $monthLabel; ?>.</div>
        <?php else: ?>
            <div style="max-height:420px;overflow-y:auto;">
                <table class="bw-table">
                    <thead><tr><th>Tanggal</th><th>Barang</th><th class="r">Qty</th><th>Dikirim ke</th><th class="r">Nilai</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($sentItems as $it):
                            [$sBg, $sFg, $sText] = $statusStyle[$it['status']] ?? $statusStyle['belum']; ?>
                            <tr>
                                <td><?php echo date('d M', strtotime($it['date'])); ?></td>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($it['item_name']); ?> <span style="color:var(--muted);font-weight:400;font-size:.7rem;"><?php echo htmlspecialchars($it['number']); ?></span></td>
                                <td class="r"><?php echo $qtyFmt($it['quantity']) . ' ' . htmlspecialchars($it['unit']); ?></td>
                                <td><?php echo htmlspecialchars($it['target']); ?></td>
                                <td class="r">
                                    <?php echo $fmt($it['value']); ?>
                                    <?php if ($it['estimated'] && !empty($it['id'])): ?>
                                        <form method="POST" style="display:flex;gap:.25rem;justify-content:flex-end;margin-top:.25rem;">
                                            <input type="hidden" name="action" value="set_transfer_price">
                                            <input type="hidden" name="transfer_id" value="<?php echo (int)$it['id']; ?>">
                                            <input type="number" name="unit_price" min="1" step="any" placeholder="Harga/<?php echo htmlspecialchars($it['unit']); ?>" class="form-control" style="width:110px;height:26px;font-size:.72rem;padding:0 .35rem;" required>
                                            <button type="submit" class="btn btn-sm btn-secondary" style="font-size:.68rem;padding:0 .45rem;">Simpan</button>
                                        </form>
                                        <div style="font-size:.66rem;color:#92400e;">harga belum diisi — memakai estimasi</div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="bw-tag" style="background:<?php echo $sBg; ?>;color:<?php echo $sFg; ?>;"><?php echo $sText; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="bw-total">
                <div><div style="font-size:.74rem;color:var(--muted);">Total kiriman <?php echo $monthLabel; ?></div><div style="font-size:1.1rem;font-weight:800;"><?php echo $fmt($sentTotal); ?></div></div>
                <div style="text-align:right;"><div style="font-size:.74rem;color:var(--muted);">Belum dibayar Gudang</div><div style="font-size:1.1rem;font-weight:800;color:#7c3aed;"><?php echo $fmt($sentUnpaid); ?></div></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- (3) Uang masuk dari Gudang -->
    <div class="bw-card">
        <h3><span class="bw-step">3</span> Uang masuk dari Gudang · <?php echo $monthLabel; ?></h3>
        <p class="sub">Pembayaran Gudang yang sudah masuk ke rekening &amp; buku kas <?php echo htmlspecialchars($activeName); ?>.</p>
        <?php if (empty($receivedRows)): ?>
            <div class="bw-empty">Belum ada uang masuk dari Gudang pada <?php echo $monthLabel; ?>.</div>
        <?php else: ?>
            <table class="bw-table">
                <thead><tr><th>Tanggal</th><th>Keterangan</th><th class="r">Jumlah</th></tr></thead>
                <tbody>
                    <?php foreach ($receivedRows as $rr): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?php echo date('d M Y', strtotime($rr['transaction_date'])); ?></td>
                            <td style="font-size:.76rem;color:var(--muted);"><?php echo htmlspecialchars((string)$rr['description']); ?></td>
                            <td class="r" style="font-weight:700;color:#10b981;"><?php echo $fmt($rr['amount']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
