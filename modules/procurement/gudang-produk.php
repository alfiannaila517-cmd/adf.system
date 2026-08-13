<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse'))) {
    http_response_code(403);
    echo 'Akses Gudang Nasita ditolak.';
    exit;
}

$db = Database::getInstance();
$pageTitle = 'Database Produk Gudang';

// Ensure table exists with unique constraint on nama_barang
try {
    $db->query("CREATE TABLE IF NOT EXISTS `gudang_nasita_barang` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `kode_barang` VARCHAR(30) NULL,
        `nama_barang` VARCHAR(200) NOT NULL,
        `kategori`   VARCHAR(100) DEFAULT 'lainnya',
        `satuan`     VARCHAR(30)  DEFAULT 'pcs',
        `deskripsi`  TEXT NULL,
        `harga_beli` DECIMAL(15,2) DEFAULT 0,
        `harga_jual` DECIMAL(15,2) DEFAULT 0,
        `is_active`  TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_nama_barang` (`nama_barang`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
}

$msg = '';
$msgType = 'success';

// Handle POST (create/update via regular form fallback when JS disabled)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $nama     = trim($_POST['nama_barang'] ?? '');
        $kategori = trim($_POST['kategori'] ?? 'lainnya') ?: 'lainnya';
        $satuan   = trim($_POST['satuan'] ?? 'pcs') ?: 'pcs';
        $deskripsi = trim($_POST['deskripsi'] ?? '');

        if ($nama === '') {
            $msg = 'Nama barang wajib diisi.';
            $msgType = 'danger';
        } else {
            $dupe = $db->fetchOne("SELECT id FROM gudang_nasita_barang WHERE LOWER(nama_barang) = LOWER(?) AND id != ? LIMIT 1", [$nama, $id]);
            if ($dupe) {
                $msg = "Nama \"$nama\" sudah ada di database (ID #{$dupe['id']}). Gunakan nama yang berbeda atau edit produk yang ada.";
                $msgType = 'danger';
            } else {
                $data = ['nama_barang' => $nama, 'kategori' => $kategori, 'satuan' => $satuan, 'deskripsi' => $deskripsi, 'is_active' => 1];
                if ($id > 0) {
                    $db->update('gudang_nasita_barang', $data, 'id = :id', ['id' => $id]);
                    $msg = 'Produk berhasil diperbarui.';
                } else {
                    $prefix = 'BRG-';
                    $last = $db->fetchOne('SELECT kode_barang FROM gudang_nasita_barang WHERE kode_barang LIKE ? ORDER BY kode_barang DESC LIMIT 1', [$prefix . '%']);
                    $seq = $last ? ((int)substr($last['kode_barang'], -4) + 1) : 1;
                    $data['kode_barang'] = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
                    $db->insert('gudang_nasita_barang', $data);
                    $msg = 'Produk berhasil ditambahkan.';
                }
            }
        }
    }

    if ($formAction === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $cur = $db->fetchOne('SELECT is_active FROM gudang_nasita_barang WHERE id = ?', [$id]);
        if ($cur) {
            $db->update('gudang_nasita_barang', ['is_active' => $cur['is_active'] ? 0 : 1], 'id = :id', ['id' => $id]);
            $msg = 'Status produk diubah.';
        }
    }
}

// Search & filter
$qSearch = trim($_GET['q'] ?? '');
$filterKategori = trim($_GET['kategori'] ?? '');
$where = 'WHERE 1=1';
$params = [];
if ($qSearch !== '') {
    $where .= ' AND (nama_barang LIKE ? OR kode_barang LIKE ?)';
    $params[] = '%' . $qSearch . '%';
    $params[] = '%' . $qSearch . '%';
}
if ($filterKategori !== '') {
    $where .= ' AND kategori = ?';
    $params[] = $filterKategori;
}

$products = $db->fetchAll("SELECT * FROM gudang_nasita_barang $where ORDER BY nama_barang ASC", $params);
$allKategori = $db->fetchAll("SELECT DISTINCT COALESCE(kategori,'lainnya') AS kategori FROM gudang_nasita_barang ORDER BY kategori ASC");

// Count current stock per product (by name join)
$stockMap = [];
$stockRows = $db->fetchAll("SELECT LOWER(item_name) AS k, SUM(quantity) AS total FROM gudang_nasita_stock WHERE is_active = 1 GROUP BY LOWER(item_name)");
foreach ($stockRows as $sr) {
    $stockMap[$sr['k']] = (float)$sr['total'];
}

// Build full product list with current stock for JS (rendered once, filtered client-side)
$allProductsForJs = $db->fetchAll(
    "SELECT gb.id, gb.kode_barang, gb.nama_barang, gb.kategori, gb.satuan,
            COALESCE((SELECT SUM(gs.quantity) FROM gudang_nasita_stock gs
                      WHERE gs.is_active = 1 AND LOWER(gs.item_name) = LOWER(gb.nama_barang)), 0) AS stok_qty
     FROM gudang_nasita_barang gb
     WHERE gb.is_active = 1
     ORDER BY gb.nama_barang ASC"
) ?: [];

$forceTheme = 'light';
include '../../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:1rem;">
    <div>
        <h2 style="font-size:1.4rem; font-weight:700; margin:0; color:var(--text-primary);">Database Produk Gudang</h2>
        <p style="color:var(--text-muted); font-size:0.875rem; margin:0.25rem 0 0;">Master barang terpusat — cegah nama ganda seperti "Beer" vs "Bir"</p>
    </div>
    <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
        <button type="button" class="btn btn-success" onclick="openAddStockSearch()">
            <i data-feather="plus-circle" style="width:15px;height:15px;"></i> Tambah Stock
        </button>
        <button type="button" class="btn" style="background:#7c3aed;color:#fff;" onclick="openProdukModal(0)">
            <i data-feather="plus" style="width:15px;height:15px;"></i> Tambah Produk
        </button>
        <a href="gudang-nasita.php" class="btn btn-secondary">← Kembali ke Stock Gudang</a>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?php echo $msgType; ?>" style="margin-bottom:1rem;"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<!-- Search bar -->
<div class="card" style="margin-bottom:1rem; padding:0.85rem 1rem;">
    <form method="GET" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
        <input type="text" name="q" class="form-control" placeholder="Cari nama atau kode barang..." value="<?php echo htmlspecialchars($qSearch); ?>" style="min-width:220px;">
        <select name="kategori" class="form-control" style="width:160px;">
            <option value="">Semua Kategori</option>
            <?php foreach ($allKategori as $k): ?>
                <option value="<?php echo htmlspecialchars($k['kategori']); ?>" <?php echo $filterKategori === $k['kategori'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars(ucfirst($k['kategori'])); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Cari</button>
        <a href="gudang-produk.php" class="btn btn-secondary">Reset</a>
        <span style="margin-left:auto; font-size:0.83rem; color:var(--text-muted);"><?php echo count($products); ?> produk</span>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Barang</th>
                    <th>Kategori</th>
                    <th>Satuan</th>
                    <th class="text-right">Stok Sekarang</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:2rem; color:var(--text-muted);">Belum ada produk. Klik "Tambah Produk" untuk mulai.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <?php $stok = $stockMap[strtolower((string)($p['nama_barang'] ?? ''))] ?? null; ?>
                        <tr>
                            <td style="font-weight:600; font-size:0.82rem;"><?php echo htmlspecialchars($p['kode_barang'] ?? '-'); ?></td>
                            <td>
                                <div style="font-weight:600;"><?php echo htmlspecialchars($p['nama_barang']); ?></div>
                                <?php if (!empty($p['deskripsi'])): ?>
                                    <div style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($p['deskripsi']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-info" style="text-transform:capitalize;"><?php echo htmlspecialchars($p['kategori'] ?? 'lainnya'); ?></span></td>
                            <td><?php echo htmlspecialchars($p['satuan'] ?? 'pcs'); ?></td>
                            <td class="text-right" style="font-weight:700; color:<?php echo $stok !== null ? ($stok <= 0 ? '#dc2626' : '#0f9d6a') : 'var(--text-muted)'; ?>">
                                <?php echo $stok !== null ? number_format($stok, 2) : '—'; ?>
                            </td>
                            <td>
                                <?php if ($p['is_active']): ?>
                                    <span class="badge badge-success">Aktif</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#6b7280;color:#fff;">Non-aktif</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:0.3rem; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-sm btn-primary"
                                        onclick="openProdukModal(<?php echo (int)$p['id']; ?>, <?php echo htmlspecialchars(json_encode($p), ENT_QUOTES); ?>)">
                                        Edit
                                    </button>
                                    <button type="button" class="btn btn-sm btn-success"
                                        onclick="quickAddStock(<?php echo htmlspecialchars(json_encode($p['nama_barang']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($p['kategori'] ?? 'lainnya'), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($p['satuan'] ?? 'pcs'), ENT_QUOTES); ?>)">
                                        <i data-feather="plus-circle" style="width:13px;height:13px;"></i> Tambah Stok
                                    </button>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="form_action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="btn btn-sm" style="background:<?php echo $p['is_active'] ? '#6b7280' : '#0f9d6a'; ?>;color:#fff;"
                                            onclick="return confirm('<?php echo $p['is_active'] ? 'Non-aktifkan' : 'Aktifkan'; ?> produk ini?')">
                                            <?php echo $p['is_active'] ? 'Non-aktif' : 'Aktifkan'; ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Tambah / Edit Produk -->
<div id="produkModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:2000; align-items:center; justify-content:center; padding:1rem;">
    <div class="card" style="width:min(520px,100%); max-height:92vh; overflow:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 id="produkModalTitle" style="font-size:1.05rem; margin:0;">Tambah Produk</h3>
            <button type="button" onclick="closeProdukModal()" class="btn btn-sm btn-outline-secondary">✕ Tutup</button>
        </div>
        <div id="produkModalMsg" style="display:none; padding:0.6rem 0.9rem; border-radius:0.5rem; margin-bottom:0.75rem; font-size:0.875rem;"></div>
        <form id="produkForm">
            <input type="hidden" id="produkId" name="id" value="0">
            <div style="display:grid; gap:0.85rem;">
                <div>
                    <label class="form-label">Nama Barang <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="produkNama" name="nama_barang" class="form-control" required placeholder="Contoh: Bir Bintang 330ml">
                    <div id="produkNamaHint" style="font-size:0.75rem; color:#0f9d6a; margin-top:2px; display:none;"></div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.7rem;">
                    <div>
                        <label class="form-label">Kategori</label>
                        <input type="text" id="produkKategori" name="kategori" class="form-control" list="kategoriList" placeholder="minuman">
                        <datalist id="kategoriList">
                            <option value="minuman"></option>
                            <option value="frozen"></option>
                            <option value="alat"></option>
                            <option value="sayur"></option>
                            <option value="daging"></option>
                            <option value="sembako"></option>
                            <option value="bumbu"></option>
                            <option value="lainnya"></option>
                        </datalist>
                    </div>
                    <div>
                        <label class="form-label">Satuan</label>
                        <input type="text" id="produkSatuan" name="satuan" class="form-control" list="satuanList" placeholder="pcs">
                        <datalist id="satuanList">
                            <option value="pcs"></option>
                            <option value="kg"></option>
                            <option value="liter"></option>
                            <option value="botol"></option>
                            <option value="karton"></option>
                            <option value="lusin"></option>
                            <option value="gram"></option>
                        </datalist>
                    </div>
                </div>
                <div>
                    <label class="form-label">Deskripsi / Catatan</label>
                    <textarea id="produkDeskripsi" name="deskripsi" class="form-control" rows="2" placeholder="Opsional"></textarea>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.1rem;">
                <button type="button" onclick="closeProdukModal()" class="btn btn-secondary">Batal</button>
                <button type="button" onclick="saveProduk()" class="btn btn-success" id="produkSaveBtn">Simpan Produk</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Tambah Stock — fase 1: search+filter, fase 2: form -->
<div id="addStockModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:2100; align-items:flex-start; justify-content:center; padding:1.5rem 1rem; overflow-y:auto;">
    <div class="card" style="width:min(580px,100%); margin:auto;">

        <!-- Header modal -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <div style="display:flex; align-items:center; gap:0.6rem;">
                <button type="button" id="asBackBtn" onclick="showSearchPhase()" style="display:none; background:none; border:none; cursor:pointer; color:var(--text-muted); font-size:1.1rem; padding:0 4px;">← </button>
                <h3 id="asTitle" style="font-size:1.05rem; margin:0;">Tambah Stock — Pilih Produk</h3>
            </div>
            <button type="button" onclick="closeAddStockModal()" class="btn btn-sm btn-outline-secondary">✕</button>
        </div>

        <!-- FASE 1: Search + filter + list -->
        <div id="asSearchPhase">
            <div style="display:flex; gap:0.5rem; margin-bottom:0.75rem;">
                <input type="text" id="asSearchInput" class="form-control" placeholder="Cari nama produk..." oninput="renderProductList()" style="flex:1;">
                <select id="asKategoriFilter" class="form-control" style="width:150px;" onchange="renderProductList()">
                    <option value="">Semua Kategori</option>
                    <?php
                    $jsKats = $db->fetchAll("SELECT DISTINCT COALESCE(kategori,'lainnya') AS kategori FROM gudang_nasita_barang WHERE is_active=1 ORDER BY kategori ASC");
                    foreach ($jsKats as $kk): ?>
                        <option value="<?php echo htmlspecialchars($kk['kategori']); ?>"><?php echo htmlspecialchars(ucfirst($kk['kategori'])); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="asProductList" style="max-height:380px; overflow-y:auto; border:1px solid var(--border); border-radius:0.5rem;">
                <!-- rendered by JS -->
            </div>
            <div style="margin-top:0.75rem; font-size:0.78rem; color:var(--text-muted); text-align:right;" id="asCountLabel"></div>
        </div>

        <!-- FASE 2: Form tambah stock -->
        <div id="asFormPhase" style="display:none;">
            <div style="background:var(--bg-secondary); border-radius:0.6rem; padding:0.75rem 1rem; margin-bottom:1rem; display:flex; align-items:center; justify-content:space-between; gap:1rem;">
                <div>
                    <div style="font-weight:700; font-size:0.95rem;" id="asSelectedName"></div>
                    <div style="font-size:0.78rem; color:var(--text-muted);"><span id="asSelectedKat"></span> · <span id="asSelectedSat"></span> · Stok saat ini: <strong id="asSelectedStok" style="color:#0f9d6a;"></strong></div>
                </div>
            </div>
            <form method="POST" action="gudang-nasita.php" id="asForm">
                <input type="hidden" name="action" value="manual_stock_in">
                <input type="hidden" name="item_name" id="asFormItem">
                <input type="hidden" name="category" id="asFormKat">
                <input type="hidden" name="unit" id="asFormUnit">
                <div style="display:grid; gap:0.85rem;">
                    <div>
                        <label class="form-label">Qty Masuk <span style="color:#dc2626;">*</span></label>
                        <input type="number" name="quantity" id="asFormQty" class="form-control" step="0.01" min="0.01" required placeholder="0">
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.7rem;">
                        <div>
                            <label class="form-label">Reorder Level</label>
                            <input type="number" name="reorder_level" class="form-control" step="0.01" min="0" value="0">
                        </div>
                        <div>
                            <label class="form-label">Supplier</label>
                            <input type="text" name="supplier_name" class="form-control" placeholder="Opsional">
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Opsional"></textarea>
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                    <button type="button" onclick="showSearchPhase()" class="btn btn-secondary">← Ganti Produk</button>
                    <button type="submit" class="btn btn-success">Simpan Stock</button>
                </div>
            </form>
        </div>

    </div>
</div>

<script>
    if (typeof feather !== 'undefined') feather.replace();

    const BASE = '<?php echo BASE_URL; ?>';
    let produkNamaTimer;

    function openProdukModal(id, data) {
        document.getElementById('produkId').value = id || 0;
        document.getElementById('produkModalTitle').textContent = id ? 'Edit Produk' : 'Tambah Produk';
        document.getElementById('produkNama').value = data ? data.nama_barang : '';
        document.getElementById('produkKategori').value = data ? (data.kategori || '') : '';
        document.getElementById('produkSatuan').value = data ? (data.satuan || '') : '';
        document.getElementById('produkDeskripsi').value = data ? (data.deskripsi || '') : '';
        document.getElementById('produkModalMsg').style.display = 'none';
        document.getElementById('produkNamaHint').style.display = 'none';
        document.getElementById('produkNama').readOnly = !!id; // lock name when editing
        document.getElementById('produkModal').style.display = 'flex';
        if (!id) setTimeout(() => document.getElementById('produkNama').focus(), 80);
    }

    function closeProdukModal() {
        document.getElementById('produkModal').style.display = 'none';
    }

    // Live duplicate check while typing
    document.getElementById('produkNama').addEventListener('input', function() {
        clearTimeout(produkNamaTimer);
        const val = this.value.trim();
        const hint = document.getElementById('produkNamaHint');
        if (!val) {
            hint.style.display = 'none';
            return;
        }
        produkNamaTimer = setTimeout(async () => {
            try {
                const r = await fetch(`${BASE}/api/gudang-produk-search.php?action=search&q=${encodeURIComponent(val)}`);
                const d = await r.json();
                const exact = (d.data || []).find(p => p.nama_barang.toLowerCase() === val.toLowerCase());
                if (exact) {
                    hint.textContent = `⚠️ "${exact.nama_barang}" sudah ada (${exact.kode_barang})`;
                    hint.style.color = '#dc2626';
                    hint.style.display = 'block';
                } else {
                    hint.style.display = 'none';
                }
            } catch (e) {}
        }, 400);
    });

    async function saveProduk() {
        const btn = document.getElementById('produkSaveBtn');
        const msgEl = document.getElementById('produkModalMsg');
        btn.disabled = true;
        btn.textContent = 'Menyimpan...';

        const form = document.getElementById('produkForm');
        const body = new URLSearchParams(new FormData(form));
        body.append('action', 'save');

        try {
            const r = await fetch(`${BASE}/api/gudang-produk-search.php?action=save`, {
                method: 'POST',
                body
            });
            const d = await r.json();
            msgEl.style.display = 'block';
            if (d.success) {
                msgEl.style.background = '#d1fae5';
                msgEl.style.color = '#065f46';
                msgEl.textContent = d.message;
                setTimeout(() => location.reload(), 900);
            } else {
                msgEl.style.background = '#fee2e2';
                msgEl.style.color = '#991b1b';
                msgEl.textContent = d.message;
                btn.disabled = false;
                btn.textContent = 'Simpan Produk';
            }
        } catch (e) {
            msgEl.style.display = 'block';
            msgEl.style.background = '#fee2e2';
            msgEl.style.color = '#991b1b';
            msgEl.textContent = 'Gagal menyimpan. Coba lagi.';
            btn.disabled = false;
            btn.textContent = 'Simpan Produk';
        }
    }

    // All active products embedded for client-side search/filter
    const GP_PRODUCTS = <?php echo json_encode(array_values($allProductsForJs), JSON_UNESCAPED_UNICODE); ?>;

    function openAddStockSearch() {
        document.getElementById('addStockModal').style.display = 'flex';
        showSearchPhase();
        setTimeout(() => document.getElementById('asSearchInput').focus(), 80);
    }

    function closeAddStockModal() {
        document.getElementById('addStockModal').style.display = 'none';
    }

    function showSearchPhase() {
        document.getElementById('asSearchPhase').style.display = 'block';
        document.getElementById('asFormPhase').style.display = 'none';
        document.getElementById('asBackBtn').style.display = 'none';
        document.getElementById('asTitle').textContent = 'Tambah Stock — Pilih Produk';
        renderProductList();
        setTimeout(() => document.getElementById('asSearchInput').focus(), 60);
    }

    function renderProductList() {
        const q = (document.getElementById('asSearchInput').value || '').toLowerCase().trim();
        const kat = document.getElementById('asKategoriFilter').value;
        const filtered = GP_PRODUCTS.filter(p =>
            (!q || p.nama_barang.toLowerCase().includes(q)) &&
            (!kat || (p.kategori || 'lainnya') === kat)
        );
        const list = document.getElementById('asProductList');
        document.getElementById('asCountLabel').textContent = filtered.length + ' produk';
        if (!filtered.length) {
            list.innerHTML = '<div style="padding:1.5rem; text-align:center; color:var(--text-muted); font-size:0.875rem;">Tidak ada produk yang cocok</div>';
            return;
        }
        // Group by kategori
        const groups = {};
        filtered.forEach(p => {
            const k = (p.kategori || 'lainnya');
            if (!groups[k]) groups[k] = [];
            groups[k].push(p);
        });
        let html = '';
        Object.keys(groups).sort().forEach(k => {
            html += `<div style="padding:0.35rem 0.85rem; font-size:0.72rem; font-weight:700; text-transform:uppercase; color:#64748b; background:var(--bg-secondary); border-bottom:1px solid var(--border); letter-spacing:0.05em;">${k}</div>`;
            groups[k].forEach(p => {
                const stokColor = p.stok_qty > 0 ? '#0f9d6a' : '#dc2626';
                // Store JSON in data-attribute to avoid breaking onclick with embedded quotes
                html += `<div class="as-prod-row" data-product="${encodeURIComponent(JSON.stringify(p))}" style="display:flex; align-items:center; justify-content:space-between; padding:0.65rem 0.85rem; cursor:pointer; border-bottom:1px solid var(--border); transition:background 0.12s;" onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background=''">` +
                    `<div><div style="font-weight:600; font-size:0.875rem;">${p.nama_barang}</div><div style="font-size:0.75rem; color:#64748b;">${p.kode_barang || ''} · ${p.satuan || 'pcs'}</div></div>` +
                    `<div style="text-align:right; flex-shrink:0;"><div style="font-weight:700; font-size:0.9rem; color:${stokColor};">${parseFloat(p.stok_qty).toLocaleString('id-ID', {minimumFractionDigits:0, maximumFractionDigits:2})}</div><div style="font-size:0.7rem; color:#94a3b8;">stok saat ini</div></div>` +
                    `</div>`;
            });
        });
        list.innerHTML = html;
    }

    function selectProductForStock(p) {
        document.getElementById('asFormItem').value = p.nama_barang;
        document.getElementById('asFormKat').value = p.kategori || 'lainnya';
        document.getElementById('asFormUnit').value = p.satuan || 'pcs';
        document.getElementById('asSelectedName').textContent = p.nama_barang;
        document.getElementById('asSelectedKat').textContent = p.kategori || 'lainnya';
        document.getElementById('asSelectedSat').textContent = p.satuan || 'pcs';
        document.getElementById('asSelectedStok').textContent = parseFloat(p.stok_qty).toLocaleString('id-ID', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }) + ' ' + (p.satuan || 'pcs');
        document.getElementById('asFormQty').value = '';
        document.getElementById('asSearchPhase').style.display = 'none';
        document.getElementById('asFormPhase').style.display = 'block';
        document.getElementById('asBackBtn').style.display = 'inline';
        document.getElementById('asTitle').textContent = 'Tambah Stock';
        setTimeout(() => document.getElementById('asFormQty').focus(), 60);
    }

    // Per-row button still works by jumping straight to the form phase
    function quickAddStock(nama, kategori, satuan) {
        const p = GP_PRODUCTS.find(x => x.nama_barang === nama) || {
            nama_barang: nama,
            kategori: kategori,
            satuan: satuan,
            stok_qty: 0,
            kode_barang: ''
        };
        openAddStockSearch();
        selectProductForStock(p);
    }

    // Delegated click for product rows (avoids JSON-in-onclick quoting issues)
    document.getElementById('asProductList').addEventListener('click', function(e) {
        const row = e.target.closest('.as-prod-row');
        if (row) selectProductForStock(JSON.parse(decodeURIComponent(row.dataset.product)));
    });

    document.addEventListener('click', e => {
        if (e.target === document.getElementById('produkModal')) closeProdukModal();
        if (e.target === document.getElementById('addStockModal')) closeAddStockModal();
    });

    // Render list on first open
    document.addEventListener('DOMContentLoaded', renderProductList);
</script>

<?php include '../../includes/footer.php'; ?>