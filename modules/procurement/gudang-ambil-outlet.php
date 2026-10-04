<?php

/**
 * Gudang Nasita — Ambil Barang dari Outlet
 * Gudang memilih outlet, melihat stoknya, mengambil barang (stok outlet berkurang, stok gudang
 * bertambah) dan langsung membayar ke rekening outlet. Bisa dipakai saat admin outlet libur.
 * Logika ada di gudangTakeFromOutlet() (includes/procurement_functions.php).
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();
if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse'))) {
    http_response_code(403);
    echo 'Akses Gudang Nasita ditolak.';
    exit;
}

$currentUser = $auth->getCurrentUser();
$pageTitle = 'Ambil dari Outlet';
$outlets = gudangTrackedBizList();
$outletSlugs = array_column($outlets, 'slug');

// ── POST: konfirmasi ambil barang ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'take') {
    $slug = (string)($_POST['outlet'] ?? '');
    $lines = [];
    foreach ((array)($_POST['qty'] ?? []) as $i => $qty) {
        if ((float)$qty <= 0) {
            continue;
        }
        $lines[] = [
            'item_name' => (string)($_POST['item_name'][$i] ?? ''),
            'unit'      => (string)($_POST['unit'][$i] ?? ''),
            'quantity'  => (float)$qty,
            'price'     => (float)($_POST['price'][$i] ?? 0),
        ];
    }
    try {
        setFlash('success', gudangTakeFromOutlet($slug, $lines, trim((string)($_POST['notes'] ?? '')), (int)($currentUser['id'] ?? 0)));
    } catch (Throwable $e) {
        error_log('gudang ambil outlet: ' . $e->getMessage());
        setFlash('error', 'Gagal: ' . $e->getMessage());
    }
    header('Location: gudang-ambil-outlet.php?outlet=' . urlencode($slug));
    exit;
}

$aoOutlet = in_array($_GET['outlet'] ?? '', $outletSlugs, true) ? $_GET['outlet'] : '';
$aoOutletName = '';
$stockItems = [];
if ($aoOutlet !== '') {
    $aoOutletName = array_column($outlets, 'name', 'slug')[$aoOutlet];
    try {
        $stockItems = gudangOutletStockForTake($aoOutlet);
    } catch (Throwable $e) {
        error_log('gudang ambil outlet stock: ' . $e->getMessage());
    }
}

// Riwayat pengambilan terakhir (semua outlet).
$recentTakes = [];
try {
    $stmt = gudangMasterPdo()->prepare(
        "SELECT t.created_at, t.source_business_name, t.item_name, t.unit, t.quantity, t.subtotal, t.transfer_number, t.transfer_type, u.full_name
         FROM business_inter_stock_transfers t
         LEFT JOIN users u ON u.id = t.created_by
         WHERE t.target_business_slug = 'gudang-nasita' AND t.notes LIKE ?
         ORDER BY t.created_at DESC, t.id DESC
         LIMIT 15"
    );
    $stmt->execute([GUDANG_TAKE_MARK . '%']);
    $recentTakes = $stmt->fetchAll();
} catch (Throwable $e) {
}

$forceTheme = 'light';
include '../../includes/header.php';
?>

<style>
    .ao-steps { display:flex; gap:.5rem; flex-wrap:wrap; margin:.6rem 0 1rem; font-size:.75rem; color:#64748b; }
    .ao-steps span { background:#fff; border:1px solid #e2e8f0; border-radius:999px; padding:.25rem .7rem; }
    .ao-steps b { color:#1e40af; }
    .ao-outlets { display:flex; gap:.6rem; flex-wrap:wrap; margin-bottom:1rem; }
    .ao-outlet { display:flex; align-items:center; gap:.5rem; padding:.6rem 1rem; border:1.5px solid #e2e8f0; border-radius:.75rem; background:#fff; text-decoration:none; color:#0f172a; font-weight:600; font-size:.88rem; }
    .ao-outlet:hover { border-color:#93c5fd; text-decoration:none; color:#0f172a; }
    .ao-outlet.on { border-color:#1e40af; background:#eff6ff; color:#1e40af; }
    .ao-table input { height:32px; font-size:.82rem; padding:0 .5rem; }
    .ao-table tr.picked td { background:#f0fdf4; }
    .ao-bar { position:sticky; bottom:0; z-index:20; margin-top:1rem; background:#0f172a; color:#fff; border-radius:.85rem; padding:.75rem 1rem; display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; box-shadow:0 -6px 24px rgba(15,23,42,.18); }
    .ao-bar small { color:#94a3b8; display:block; font-size:.72rem; }
    .ao-bar b { font-size:1.15rem; color:#fff !important; }
</style>

<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;flex-wrap:wrap;">
    <div>
        <h2 style="font-size:1.3rem;font-weight:700;margin:0;color:var(--text-primary);">Ambil Barang dari Outlet</h2>
        <p style="color:var(--text-muted);font-size:.82rem;margin:.25rem 0 0;max-width:760px;">Gudang bisa mengambil barang langsung dari stok outlet — walau admin outlet sedang libur. Barang yang dulu dikirim Gudang dihitung <b>retur</b> (mengurangi tagihan outlet, tanpa uang keluar); barang milik outlet dihitung <b>pembelian</b> dan langsung dibayar ke rekening outlet.</p>
    </div>
    <a href="gudang-riwayat-masuk.php?sumber=bisnis" class="btn btn-secondary" style="font-size:.82rem;">Riwayat barang dari bisnis</a>
</div>
<div class="ao-steps">
    <span><b>1</b> Pilih outlet</span><span><b>2</b> Isi jumlah yang diambil</span><span><b>3</b> Cek harga</span><span><b>4</b> Konfirmasi &amp; bayar</span>
</div>

<div class="ao-outlets">
    <?php foreach ($outlets as $o): ?>
        <a class="ao-outlet <?php echo $o['slug'] === $aoOutlet ? 'on' : ''; ?>" href="?outlet=<?php echo urlencode($o['slug']); ?>"><span><?php echo $o['icon']; ?></span><?php echo htmlspecialchars($o['name']); ?></a>
    <?php endforeach; ?>
</div>

<?php if ($aoOutlet === ''): ?>
    <div class="card" style="padding:2rem;text-align:center;color:var(--text-muted);">Pilih outlet di atas untuk melihat stoknya.</div>
<?php else: ?>
    <form method="POST" id="aoForm" onsubmit="return aoConfirm();">
        <input type="hidden" name="action" value="take">
        <input type="hidden" name="outlet" value="<?php echo htmlspecialchars($aoOutlet); ?>">
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem;">
                <div>
                    <h3 style="font-size:1rem;font-weight:700;margin:0;">Stok <?php echo htmlspecialchars($aoOutletName); ?> saat ini</h3>
                    <p style="font-size:.76rem;color:var(--text-muted);margin:.15rem 0 0;">Isi kolom <b>Ambil</b> untuk barang yang diambil Gudang. Harga terisi dari Daftar Barang Gudang — boleh diubah.</p>
                </div>
                <input type="search" id="aoSearch" class="form-control" placeholder="Cari barang..." style="max-width:240px;">
            </div>
            <?php if (empty($stockItems)): ?>
                <div style="padding:1.5rem;text-align:center;color:var(--text-muted);">Stok <?php echo htmlspecialchars($aoOutletName); ?> kosong atau belum tercatat.</div>
            <?php else: ?>
                <div class="table-responsive" style="max-height:520px;overflow-y:auto;">
                    <table class="table ao-table" style="font-size:.84rem;margin:0;">
                        <thead style="position:sticky;top:0;background:#fff;z-index:1;">
                            <tr>
                                <th>Barang</th>
                                <th class="text-right">Stok outlet</th>
                                <th>Asal barang</th>
                                <th style="width:130px;">Ambil</th>
                                <th style="width:150px;">Harga / satuan (Rp)</th>
                                <th class="text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stockItems as $i => $it): ?>
                                <tr data-name="<?php echo htmlspecialchars(strtolower($it['item_name'])); ?>" data-returnable="<?php echo (float)$it['returnable']; ?>" data-retur-price="<?php echo (float)$it['retur_price']; ?>">
                                    <td style="font-weight:600;"><?php echo htmlspecialchars($it['item_name']); ?>
                                        <input type="hidden" name="item_name[<?php echo $i; ?>]" value="<?php echo htmlspecialchars($it['item_name']); ?>">
                                        <input type="hidden" name="unit[<?php echo $i; ?>]" value="<?php echo htmlspecialchars($it['unit']); ?>">
                                    </td>
                                    <td class="text-right"><?php echo rtrim(rtrim(number_format($it['available'], 2, '.', ''), '0'), '.'); ?> <?php echo htmlspecialchars($it['unit']); ?></td>
                                    <td style="font-size:.74rem;">
                                        <?php if ($it['returnable'] > 0): ?>
                                            <span style="background:#e2e8f0;color:#334155;font-weight:700;padding:2px 7px;border-radius:999px;">Dari Gudang</span>
                                            <div style="color:var(--text-muted);margin-top:2px;">s/d <?php echo rtrim(rtrim(number_format($it['returnable'], 2, '.', ''), '0'), '.'); ?> dihitung retur</div>
                                        <?php else: ?>
                                            <span style="background:#dcfce7;color:#166534;font-weight:700;padding:2px 7px;border-radius:999px;">Milik outlet</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><input type="number" class="form-control ao-qty" name="qty[<?php echo $i; ?>]" min="0" max="<?php echo $it['available']; ?>" step="any" placeholder="0" data-unit="<?php echo htmlspecialchars($it['unit']); ?>"></td>
                                    <td><input type="number" class="form-control ao-price" name="price[<?php echo $i; ?>]" min="0" step="any" value="<?php echo $it['price'] > 0 ? (float)$it['price'] : ''; ?>" placeholder="Isi harga"></td>
                                    <td class="text-right ao-sub" style="font-weight:700;color:#0f9d6a;">—</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($stockItems)): ?>
            <div class="ao-bar">
                <div style="display:flex;gap:1.5rem;align-items:center;flex-wrap:wrap;">
                    <div><small>Barang diambil</small><b id="aoCount">0</b></div>
                    <div><small>Retur (potong tagihan <?php echo htmlspecialchars($aoOutletName); ?>)</small><b id="aoRetur">Rp 0</b></div>
                    <div><small>Dibayar ke <?php echo htmlspecialchars($aoOutletName); ?></small><b id="aoTotal">Rp 0</b></div>
                </div>
                <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                    <input type="text" name="notes" class="form-control" placeholder="Catatan (opsional), mis. untuk stok Bens" style="width:260px;height:36px;font-size:.82rem;">
                    <button type="submit" class="btn btn-success" id="aoSubmit" disabled style="font-weight:700;">✓ Konfirmasi</button>
                </div>
            </div>
        <?php endif; ?>
    </form>
<?php endif; ?>

<div class="card" style="margin-top:1.25rem;">
    <h3 style="font-size:.95rem;font-weight:700;margin:0 0 .6rem;">Pengambilan terakhir</h3>
    <?php if (empty($recentTakes)): ?>
        <div style="padding:1rem;text-align:center;color:var(--text-muted);font-size:.85rem;">Belum ada barang yang diambil dari outlet.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table" style="font-size:.8rem;margin:0;">
                <thead>
                    <tr><th>Tanggal</th><th>Outlet</th><th>Barang</th><th class="text-right">Qty</th><th class="text-right">Nilai</th><th>Jenis</th><th>Oleh</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recentTakes as $rt): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?php echo date('d M Y H:i', strtotime($rt['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars((string)$rt['source_business_name']); ?></td>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($rt['item_name']); ?> <span style="color:var(--text-muted);font-weight:400;font-size:.72rem;"><?php echo htmlspecialchars((string)$rt['transfer_number']); ?></span></td>
                            <td class="text-right"><?php echo rtrim(rtrim(number_format((float)$rt['quantity'], 2, '.', ''), '0'), '.'); ?> <?php echo htmlspecialchars($rt['unit']); ?></td>
                            <td class="text-right" style="font-weight:700;color:#0f9d6a;">Rp <?php echo number_format((float)$rt['subtotal'], 0, ',', '.'); ?></td>
                            <td><?php echo ($rt['transfer_type'] ?? '') === 'retur' ? '<span style="font-size:.7rem;font-weight:700;color:#334155;background:#e2e8f0;padding:2px 7px;border-radius:999px;">Retur</span>' : '<span style="font-size:.7rem;font-weight:700;color:#166534;background:#dcfce7;padding:2px 7px;border-radius:999px;">Dibayar</span>'; ?></td>
                            <td><?php echo htmlspecialchars((string)($rt['full_name'] ?? '-')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
    (function () {
        var form = document.getElementById('aoForm');
        if (!form) return;
        var rupiah = function (n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); };

        // Hitung subtotal per baris dan total di bar bawah.
        function recalc() {
            var total = 0, retur = 0, count = 0, missingPrice = false;
            form.querySelectorAll('tbody tr').forEach(function (tr) {
                var qtyEl = tr.querySelector('.ao-qty');
                var qty = parseFloat(qtyEl.value) || 0;
                var price = parseFloat(tr.querySelector('.ao-price').value) || 0;
                var max = parseFloat(qtyEl.max) || 0;
                if (qty > max) { qtyEl.value = max; qty = max; }
                // Barang yang dulu dari Gudang dihitung retur (harga = harga tagihan Gudang), sisanya dibeli.
                var returQty = Math.min(qty, parseFloat(tr.dataset.returnable) || 0);
                var buyQty = qty - returQty;
                var returPrice = parseFloat(tr.dataset.returPrice) || price;
                tr.classList.toggle('picked', qty > 0);
                var parts = [];
                if (returQty > 0) parts.push('Retur ' + rupiah(returQty * returPrice));
                if (buyQty > 0) parts.push('Bayar ' + rupiah(buyQty * price));
                tr.querySelector('.ao-sub').textContent = qty > 0 ? parts.join(' · ') : '—';
                if (qty > 0) {
                    count++;
                    retur += returQty * returPrice;
                    total += buyQty * price;
                    if ((buyQty > 0 && price <= 0) || (returQty > 0 && returPrice <= 0)) missingPrice = true;
                }
            });
            document.getElementById('aoCount').textContent = count;
            document.getElementById('aoRetur').textContent = rupiah(retur);
            document.getElementById('aoTotal').textContent = rupiah(total);
            var btn = document.getElementById('aoSubmit');
            btn.disabled = count === 0 || missingPrice;
            btn.title = missingPrice ? 'Isi harga untuk semua barang yang diambil' : '';
        }
        form.addEventListener('input', recalc);

        // Konfirmasi dengan ringkasan barang.
        window.aoConfirm = function () {
            var lines = [];
            form.querySelectorAll('tbody tr.picked').forEach(function (tr) {
                var qty = tr.querySelector('.ao-qty');
                lines.push('• ' + tr.querySelector('td').childNodes[0].textContent.trim() + ' ' + qty.value + ' ' + qty.dataset.unit);
            });
            var ok = confirm('Ambil barang dari <?php echo htmlspecialchars(addslashes($aoOutletName), ENT_QUOTES); ?>:\n\n' + lines.join('\n') +
                '\n\nRetur ' + document.getElementById('aoRetur').textContent + ' → mengurangi tagihan outlet (tidak ada uang keluar).' +
                '\nDibayar ' + document.getElementById('aoTotal').textContent + ' → dari rekening Gudang ke rekening outlet.\n\nLanjutkan?');
            if (ok) document.getElementById('aoSubmit').disabled = true;
            return ok;
        };

        var search = document.getElementById('aoSearch');
        if (search) search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            form.querySelectorAll('tbody tr').forEach(function (tr) {
                tr.style.display = !q || tr.dataset.name.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    })();
</script>

<?php include '../../includes/footer.php'; ?>
