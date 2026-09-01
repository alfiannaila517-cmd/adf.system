<?php

/**
 * TAGIHAN BISNIS & GUDANG
 *
 * Setiap kali satu bisnis mengirim barang ke bisnis lain (termasuk Gudang Nasita),
 * transaksi itu tercatat di tabel master `business_inter_stock_transfers`.
 * Logikanya:
 * - Bisnis PENGIRIM (source) berarti PIUTANG — bisnis lain berhutang ke kita.
 *   Contoh: Narayana kirim 1 botol Amer ke Bens Cafe -> Bens Cafe berhutang ke Narayana.
 * - Bisnis PENERIMA (target) berarti HUTANG — kita harus membayar ke pengirim.
 *   Contoh: Narayana kirim roti ke Gudang Nasita -> Gudang berhutang ke Narayana,
 *   begitu juga sebaliknya jika Gudang/bisnis lain yang mengirim ke kita.
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/business_helper.php';

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
$activeSlug = strtolower(trim((string)($bizConfig['business_id'] ?? '')));
$activeName = (string)($bizConfig['name'] ?? '');

// Banyak transfer lama tersimpan dengan unit_price/subtotal = 0 (barang belum
// pernah diberi harga saat dikirim). Izinkan siapa saja dari bisnis terkait
// (pengirim atau penerima) mengisi harga langsung dari halaman ini.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_transfer_price') {
    $transferId = (int)($_POST['transfer_id'] ?? 0);
    $newUnitPrice = (float)str_replace(['.', ','], ['', '.'], (string)($_POST['unit_price'] ?? '0'));
    if ($transferId > 0 && $newUnitPrice > 0) {
        try {
            $masterDsnSet = 'mysql:host=' . DB_HOST . ';dbname=' . (defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME) . ';charset=' . DB_CHARSET;
            $masterPdoSet = new PDO($masterDsnSet, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $rowToUpdate = $masterPdoSet->prepare(
                "SELECT * FROM business_inter_stock_transfers WHERE id = ? AND (source_business_slug = ? OR target_business_slug = ?) LIMIT 1"
            );
            $rowToUpdate->execute([$transferId, $activeSlug, $activeSlug]);
            $row = $rowToUpdate->fetch();
            if ($row) {
                $newSubtotal = $newUnitPrice * (float)($row['quantity'] ?? 0);
                $upd = $masterPdoSet->prepare("UPDATE business_inter_stock_transfers SET unit_price = ?, subtotal = ? WHERE id = ?");
                $upd->execute([$newUnitPrice, $newSubtotal, $transferId]);
            }
        } catch (Throwable $e) {
            error_log('business-warehouse set_transfer_price error: ' . $e->getMessage());
        }
    }
    header('Location: business-warehouse.php');
    exit;
}

// Friendly names for every known business, used as fallback when a transfer's
// stored name is missing/blank.
$knownBusinessNames = [];
foreach (glob(__DIR__ . '/../../config/businesses/*.php') as $cfgFile) {
    $cfg = require $cfgFile;
    if (!empty($cfg['business_id'])) {
        $knownBusinessNames[strtolower($cfg['business_id'])] = $cfg['name'] ?? $cfg['business_id'];
    }
}

$transfers = [];
try {
    $masterDsn = 'mysql:host=' . DB_HOST . ';dbname=' . (defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME) . ';charset=' . DB_CHARSET;
    $masterPdo = new PDO($masterDsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $stmt = $masterPdo->prepare(
        "SELECT * FROM business_inter_stock_transfers
         WHERE source_business_slug = ? OR target_business_slug = ?
         ORDER BY created_at DESC
         LIMIT 300"
    );
    $stmt->execute([$activeSlug, $activeSlug]);
    $transfers = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('business-warehouse tagihan error: ' . $e->getMessage());
    $transfers = [];
}

// Split into piutang (kita pengirim -> orang lain berhutang ke kita)
// dan hutang (kita penerima -> kita berhutang ke pengirim), dikelompokkan per mitra bisnis.
$piutangByPartner = [];
$hutangByPartner = [];
$piutangTotal = 0.0;
$hutangTotal = 0.0;

// Nama database Gudang Nasita, dipakai untuk menebak harga item yang belum
// punya unit_price/subtotal (mis. barang baru seperti "roti" yang belum
// pernah diberi harga saat dikirim). Tanpa ini, transfer dengan harga 0
// disembunyikan total dari daftar tagihan padahal transfernya nyata terjadi.
$gudangDbNameForPricing = '';
try {
    $gudangCfgPathForPricing = __DIR__ . '/../../config/businesses/gudang-nasita.php';
    if (file_exists($gudangCfgPathForPricing)) {
        $gudangCfgForPricing = include $gudangCfgPathForPricing;
        $gudangDbNameForPricing = (string)($gudangCfgForPricing['database'] ?? '');
    }
} catch (Throwable $e) {
    $gudangDbNameForPricing = '';
}

foreach ($transfers as $t) {
    $qty = (float)($t['quantity'] ?? 0);
    $unitPrice = (float)($t['unit_price'] ?? 0);
    $value = isset($t['subtotal']) && $t['subtotal'] !== null ? (float)$t['subtotal'] : ($qty * $unitPrice);
    $isEstimated = false;

    if ($value <= 0 && $gudangDbNameForPricing !== '') {
        try {
            $originDbForPricing = Database::getCurrentDatabase();
            $gudangDbForPricing = Database::switchDatabase($gudangDbNameForPricing);
            $stockCols = $gudangDbForPricing->fetchAll("SHOW COLUMNS FROM gudang_nasita_stock");
            $stockColNames = array_column($stockCols, 'Field');
            $hasBarangId = in_array('barang_id', $stockColNames, true);
            $barangJoin = $hasBarangId ? 'LEFT JOIN gudang_nasita_barang gb ON gb.id = gs.barang_id' : '';
            $priceExpr = $hasBarangId ? 'COALESCE(NULLIF(gs.harga_beli, 0), gb.harga_beli, 0)' : 'COALESCE(gs.harga_beli, 0)';
            $stockPriceRow = $gudangDbForPricing->fetchOne(
                "SELECT {$priceExpr} AS harga_beli
                 FROM gudang_nasita_stock gs
                 {$barangJoin}
                 WHERE LOWER(TRIM(gs.item_name)) = LOWER(TRIM(?)) AND LOWER(TRIM(gs.unit)) = LOWER(TRIM(?))
                 LIMIT 1",
                [(string)($t['item_name'] ?? ''), (string)($t['unit'] ?? '')]
            );
            $estimatedPrice = (float)($stockPriceRow['harga_beli'] ?? 0);
            if ($estimatedPrice > 0) {
                $value = $estimatedPrice * $qty;
                $isEstimated = true;
            }
            if ($originDbForPricing !== '') {
                Database::switchDatabase($originDbForPricing);
            }
        } catch (Throwable $e) {
            error_log('business-warehouse estimasi harga error: ' . $e->getMessage());
        }
    }

    $t['_bw_value'] = $value;
    $t['_bw_estimated'] = $isEstimated;

    $sourceSlug = strtolower((string)($t['source_business_slug'] ?? ''));
    $targetSlug = strtolower((string)($t['target_business_slug'] ?? ''));

    if ($sourceSlug === $activeSlug) {
        $partnerSlug = $targetSlug;
        $partnerName = $t['target_business_name'] ?: ($knownBusinessNames[$partnerSlug] ?? $partnerSlug);
        if (!isset($piutangByPartner[$partnerSlug])) {
            $piutangByPartner[$partnerSlug] = ['name' => $partnerName, 'total' => 0.0, 'items' => []];
        }
        $piutangByPartner[$partnerSlug]['total'] += $value;
        $piutangByPartner[$partnerSlug]['items'][] = $t;
        $piutangTotal += $value;
    } elseif ($targetSlug === $activeSlug) {
        $partnerSlug = $sourceSlug;
        $partnerName = $t['source_business_name'] ?: ($knownBusinessNames[$partnerSlug] ?? $partnerSlug);
        if (!isset($hutangByPartner[$partnerSlug])) {
            $hutangByPartner[$partnerSlug] = ['name' => $partnerName, 'total' => 0.0, 'items' => []];
        }
        $hutangByPartner[$partnerSlug]['total'] += $value;
        $hutangByPartner[$partnerSlug]['items'][] = $t;
        $hutangTotal += $value;
    }
}

uasort($piutangByPartner, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});
uasort($hutangByPartner, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});

$netPosition = $piutangTotal - $hutangTotal;

include '../../includes/header.php';
?>

<style>
    .bw-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 1rem;
    }

    .bw-header h1 {
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0 0 0.25rem;
    }

    .bw-header p {
        color: var(--text-secondary);
        font-size: 0.85rem;
        margin: 0 0 1.25rem;
    }

    .bw-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .bw-summary-card {
        background: var(--card-bg, #fff);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 1rem 1.25rem;
    }

    .bw-summary-card .label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: var(--text-secondary);
        margin-bottom: 0.35rem;
    }

    .bw-summary-card .value {
        font-size: 1.3rem;
        font-weight: 800;
    }

    .bw-summary-card.piutang .value {
        color: #059669;
    }

    .bw-summary-card.hutang .value {
        color: #dc2626;
    }

    .bw-summary-card.net .value {
        color: <?php echo $netPosition >= 0 ? '#059669' : '#dc2626'; ?>;
    }

    .bw-section-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1rem;
        font-weight: 700;
        margin: 1.5rem 0 0.75rem;
        color: var(--text-primary);
    }

    .bw-section-title.piutang {
        color: #059669;
    }

    .bw-section-title.hutang {
        color: #dc2626;
    }

    .bw-partner-card {
        background: var(--card-bg, #fff);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        margin-bottom: 0.85rem;
        overflow: hidden;
    }

    .bw-partner-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.75rem 1rem;
        cursor: pointer;
        gap: 0.75rem;
    }

    .bw-partner-name {
        font-weight: 700;
        font-size: 0.9rem;
        color: var(--text-primary);
    }

    .bw-partner-total {
        font-weight: 800;
        font-size: 0.95rem;
        white-space: nowrap;
    }

    .bw-partner-card.piutang .bw-partner-total {
        color: #059669;
    }

    .bw-partner-card.hutang .bw-partner-total {
        color: #dc2626;
    }

    .bw-partner-items {
        display: none;
        border-top: 1px solid var(--border-color);
    }

    .bw-partner-card.open .bw-partner-items {
        display: block;
    }

    .bw-item-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.55rem 1rem;
        font-size: 0.78rem;
        border-bottom: 1px solid var(--border-color);
        gap: 0.75rem;
    }

    .bw-item-row:last-child {
        border-bottom: none;
    }

    .bw-item-name {
        color: var(--text-primary);
        font-weight: 600;
    }

    .bw-item-meta {
        color: var(--text-secondary);
        font-size: 0.72rem;
    }

    .bw-item-value {
        font-weight: 700;
        white-space: nowrap;
    }

    .bw-empty {
        color: var(--text-secondary);
        font-size: 0.85rem;
        padding: 1rem;
        background: var(--card-bg, #fff);
        border: 1px dashed var(--border-color);
        border-radius: 12px;
        text-align: center;
    }

    .bw-chevron {
        transition: transform 0.15s ease;
        color: var(--text-secondary);
    }

    .bw-partner-card.open .bw-chevron {
        transform: rotate(180deg);
    }
</style>

<div class="bw-container">
    <div class="bw-header">
        <h1>Tagihan Bisnis & Gudang</h1>
        <p>Rekap piutang &amp; hutang antar bisnis dari transfer barang <?php echo htmlspecialchars($activeName); ?> ke/dari bisnis lain (termasuk Gudang Nasita).</p>
    </div>

    <div class="bw-summary">
        <div class="bw-summary-card piutang">
            <div class="label">Total Piutang (Ditagih)</div>
            <div class="value">Rp <?php echo number_format($piutangTotal, 0, ',', '.'); ?></div>
        </div>
        <div class="bw-summary-card hutang">
            <div class="label">Total Hutang (Harus Dibayar)</div>
            <div class="value">Rp <?php echo number_format($hutangTotal, 0, ',', '.'); ?></div>
        </div>
        <div class="bw-summary-card net">
            <div class="label">Posisi Bersih</div>
            <div class="value">Rp <?php echo number_format(abs($netPosition), 0, ',', '.'); ?> <?php echo $netPosition >= 0 ? '(Piutang)' : '(Hutang)'; ?></div>
        </div>
    </div>

    <div class="bw-section-title piutang">💰 Piutang — Bisnis Lain Berhutang ke Kami</div>
    <?php if (empty($piutangByPartner)): ?>
        <div class="bw-empty">Belum ada barang yang kami kirim ke bisnis lain.</div>
    <?php else: ?>
        <?php foreach ($piutangByPartner as $partner): ?>
            <div class="bw-partner-card piutang">
                <div class="bw-partner-header" onclick="this.closest('.bw-partner-card').classList.toggle('open')">
                    <span class="bw-partner-name"><?php echo htmlspecialchars($partner['name']); ?></span>
                    <span style="display:flex; align-items:center; gap:0.5rem;">
                        <span class="bw-partner-total">Rp <?php echo number_format($partner['total'], 0, ',', '.'); ?></span>
                        <span class="bw-chevron">&#9662;</span>
                    </span>
                </div>
                <div class="bw-partner-items">
                    <?php foreach ($partner['items'] as $item): ?>
                        <?php $itemValue = (float)($item['_bw_value'] ?? 0); ?>
                        <div class="bw-item-row">
                            <div>
                                <div class="bw-item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                <div class="bw-item-meta"><?php echo number_format((float)$item['quantity'], 0, ',', '.'); ?> <?php echo htmlspecialchars($item['unit']); ?> &middot; <?php echo date('d M Y', strtotime($item['created_at'])); ?><?php if (!empty($item['_bw_estimated'])): ?> &middot; <span style="color:#b45309;">harga diperkirakan</span><?php endif; ?></div>
                                <?php if ($itemValue <= 0): ?>
                                <form method="post" style="display:flex; align-items:center; gap:0.35rem; margin-top:0.35rem;">
                                    <input type="hidden" name="action" value="set_transfer_price">
                                    <input type="hidden" name="transfer_id" value="<?php echo (int)$item['id']; ?>">
                                    <span style="font-size:0.72rem; color:#b45309;">belum ada harga —</span>
                                    <input type="text" name="unit_price" placeholder="harga/unit" required style="width:110px; padding:0.2rem 0.4rem; font-size:0.75rem; border:1px solid var(--border-color); border-radius:6px;">
                                    <button type="submit" style="font-size:0.72rem; padding:0.2rem 0.5rem; border-radius:6px; border:1px solid var(--border-color); background:#f3f4f6; cursor:pointer;">Simpan</button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <div class="bw-item-value">Rp <?php echo number_format($itemValue, 0, ',', '.'); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="bw-section-title hutang">📥 Hutang — Kami Berhutang ke Bisnis Lain</div>
    <?php if (empty($hutangByPartner)): ?>
        <div class="bw-empty">Belum ada barang yang kami terima dari bisnis lain.</div>
    <?php else: ?>
        <?php foreach ($hutangByPartner as $partner): ?>
            <div class="bw-partner-card hutang">
                <div class="bw-partner-header" onclick="this.closest('.bw-partner-card').classList.toggle('open')">
                    <span class="bw-partner-name"><?php echo htmlspecialchars($partner['name']); ?></span>
                    <span style="display:flex; align-items:center; gap:0.5rem;">
                        <span class="bw-partner-total">Rp <?php echo number_format($partner['total'], 0, ',', '.'); ?></span>
                        <span class="bw-chevron">&#9662;</span>
                    </span>
                </div>
                <div class="bw-partner-items">
                    <?php foreach ($partner['items'] as $item): ?>
                        <?php $itemValue = (float)($item['_bw_value'] ?? 0); ?>
                        <div class="bw-item-row">
                            <div>
                                <div class="bw-item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                <div class="bw-item-meta"><?php echo number_format((float)$item['quantity'], 0, ',', '.'); ?> <?php echo htmlspecialchars($item['unit']); ?> &middot; <?php echo date('d M Y', strtotime($item['created_at'])); ?><?php if (!empty($item['_bw_estimated'])): ?> &middot; <span style="color:#b45309;">harga diperkirakan</span><?php endif; ?></div>
                                <?php if ($itemValue <= 0): ?>
                                <form method="post" style="display:flex; align-items:center; gap:0.35rem; margin-top:0.35rem;">
                                    <input type="hidden" name="action" value="set_transfer_price">
                                    <input type="hidden" name="transfer_id" value="<?php echo (int)$item['id']; ?>">
                                    <span style="font-size:0.72rem; color:#b45309;">belum ada harga —</span>
                                    <input type="text" name="unit_price" placeholder="harga/unit" required style="width:110px; padding:0.2rem 0.4rem; font-size:0.75rem; border:1px solid var(--border-color); border-radius:6px;">
                                    <button type="submit" style="font-size:0.72rem; padding:0.2rem 0.5rem; border-radius:6px; border:1px solid var(--border-color); background:#f3f4f6; cursor:pointer;">Simpan</button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <div class="bw-item-value">Rp <?php echo number_format($itemValue, 0, ',', '.'); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
