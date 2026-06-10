<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';

$pdo = getPwfOfficePdo();

// ── POST HANDLERS ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? 'create';

    if ($action === 'create') {
        $productName = trim($_POST['product_name'] ?? '');
        $quantity = (float)($_POST['quantity'] ?? 0);
        if ($productName !== '' && $quantity > 0) {
            try {
                $code = genPwfCode($pdo, 'STK');
                $pdo->prepare('INSERT INTO pwf_warehouse_stock
                    (stock_code, product_name, quantity, unit, finish, wood_color, dimensions, specification, notes, source, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([
                        $code,
                        $productName,
                        $quantity,
                        trim($_POST['unit'] ?? 'pcs'),
                        trim($_POST['finish'] ?? ''),
                        trim($_POST['wood_color'] ?? ''),
                        trim($_POST['dimensions'] ?? ''),
                        trim($_POST['specification'] ?? ''),
                        trim($_POST['notes'] ?? ''),
                        'manual',
                        $_SESSION['user_id'] ?? null
                    ]);
                $msg = 'Stock added successfully.';
            } catch (\Throwable $e) {
                error_log('PWF warehouse create error: ' . $e->getMessage());
                $msgType = 'warning';
                $msg = 'Failed to add stock: ' . $e->getMessage();
            }
        } else {
            $msgType = 'warning';
            $msg = 'Product name and quantity are required.';
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['stock_id'] ?? 0);
        if ($id > 0) {
            try {
                $quantity = (float)($_POST['quantity'] ?? 0);
                $pdo->prepare('UPDATE pwf_warehouse_stock SET
                    product_name=?, quantity=?, unit=?, finish=?, wood_color=?, dimensions=?, specification=?, notes=?, updated_at=NOW()
                    WHERE id=?')
                    ->execute([
                        trim($_POST['product_name'] ?? ''),
                        $quantity,
                        trim($_POST['unit'] ?? 'pcs'),
                        trim($_POST['finish'] ?? ''),
                        trim($_POST['wood_color'] ?? ''),
                        trim($_POST['dimensions'] ?? ''),
                        trim($_POST['specification'] ?? ''),
                        trim($_POST['notes'] ?? ''),
                        $id
                    ]);
                $msg = 'Stock updated successfully.';
            } catch (\Throwable $e) {
                error_log('PWF warehouse update error: ' . $e->getMessage());
                $msgType = 'warning';
                $msg = 'Failed to update stock: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['stock_id'] ?? 0);
        if ($id > 0) {
            try {
                $pdo->prepare('DELETE FROM pwf_warehouse_stock WHERE id=?')->execute([$id]);
                $msg = 'Stock deleted successfully.';
            } catch (\Throwable $e) {
                error_log('PWF warehouse delete error: ' . $e->getMessage());
                $msgType = 'warning';
                $msg = 'Failed to delete stock: ' . $e->getMessage();
            }
        }
    }
}

// ── DATA ────────────────────────────────────────────────────────────────────
$filterSearch = trim($_GET['search'] ?? '');
$whereParts = [];
$whereArgs = [];

if ($filterSearch !== '') {
    $whereParts[] = '(product_name LIKE ? OR stock_code LIKE ?)';
    $whereArgs[] = '%' . $filterSearch . '%';
    $whereArgs[] = '%' . $filterSearch . '%';
}

$whereClause = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

$stmt = $pdo->prepare("SELECT * FROM pwf_warehouse_stock $whereClause ORDER BY created_at DESC");
$stmt->execute($whereArgs);
$stocks = $stmt->fetchAll();

// Summary stats
$summaryStmt = $pdo->query("
    SELECT 
        COUNT(DISTINCT id) AS total_items,
        COUNT(DISTINCT product_name) AS unique_products,
        SUM(quantity) AS total_qty
    FROM pwf_warehouse_stock
");
$summary = $summaryStmt->fetch();

$msg = '';
$msgType = 'success';
$baseUrl = rtrim(BASE_URL, '/');

pwfOfficeHeader('Warehouse / Stock', 'warehouse');
?>

<style>
    .filter-bar {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        padding: 12px 16px;
        background: var(--nav-hover);
        border-bottom: 1px solid var(--border)
    }

    .stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px;
        padding: 16px;
        background: #fcfdff;
        border-bottom: 1px solid var(--border)
    }

    .stat-card {
        border: 1px solid var(--border);
        background: #fff;
        border-radius: 10px;
        padding: 14px;
        text-align: center
    }

    .stat-label {
        color: var(--muted);
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .4px;
        font-weight: 700;
        margin-bottom: 4px
    }

    .stat-value {
        font-size: 20px;
        font-weight: 800;
        color: var(--gold)
    }

    .stock-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 14px;
        padding: 16px
    }

    .stock-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 14px;
        transition: box-shadow .2s, transform .15s
    }

    .stock-card:hover {
        box-shadow: 0 6px 24px rgba(0, 0, 0, .09);
        transform: translateY(-2px)
    }

    .stock-code {
        font-family: monospace;
        font-size: 11px;
        color: var(--gold);
        font-weight: 700
    }

    .stock-name {
        font-size: 15px;
        font-weight: 800;
        margin: 6px 0;
        color: var(--text)
    }

    .stock-meta {
        font-size: 12px;
        color: var(--muted);
        margin: 5px 0
    }

    .stock-footer {
        display: flex;
        gap: 6px;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid var(--border)
    }

    .stock-footer .btn {
        flex: 1;
        padding: 6px 8px;
        font-size: 11px
    }

    @media print {
        .no-print {
            display: none;
        }

        .filter-bar,
        .stat-grid {
            display: none;
        }

        .stock-card {
            page-break-inside: avoid;
        }
    }
</style>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'warning' ?>" style="margin-bottom:16px">
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<!-- FILTER & ACTIONS -->
<div class="pwf-card" style="margin-bottom:16px">
    <div class="filter-bar">
        <form method="get" style="display:contents">
            <input type="text" name="search" placeholder="Search by product or stock code..." value="<?= htmlspecialchars($filterSearch) ?>" class="input" style="flex:1;min-width:240px">
            <button type="submit" class="btn btn-outline-secondary" style="gap:6px">
                <i class="bi bi-search"></i> Search
            </button>
            <?php if ($filterSearch !== ''): ?>
                <a href="warehouse.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i> Clear</a>
            <?php endif; ?>
        </form>

        <div style="margin-left:auto;display:flex;gap:6px">
            <button class="btn btn-sm" onclick="openCreateModal()" style="gap:6px">
                <i class="bi bi-plus-lg"></i> Add Stock
            </button>
        </div>
    </div>

    <!-- STATS -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-label">Total Stock Items</div>
            <div class="stat-value"><?= (int)($summary['total_items'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Unique Products</div>
            <div class="stat-value"><?= (int)($summary['unique_products'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Quantity</div>
            <div class="stat-value"><?= rtrim(rtrim(number_format((float)($summary['total_qty'] ?? 0), 2), '0'), '.') ?></div>
        </div>
    </div>
</div>

<!-- STOCK GRID -->
<div class="pwf-card">
    <?php if (empty($stocks)): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--muted)">
            <i class="bi bi-inbox" style="font-size:40px;display:block;margin-bottom:12px;opacity:.4"></i>
            No stock items found.
        </div>
    <?php else: ?>
        <div class="stock-grid">
            <?php foreach ($stocks as $s): ?>
                <div class="stock-card">
                    <div class="stock-code"><?= htmlspecialchars($s['stock_code']) ?></div>
                    <div class="stock-name"><?= htmlspecialchars($s['product_name']) ?></div>
                    <div class="stock-meta">
                        <span style="font-weight:700;color:var(--gold)"><?= rtrim(rtrim(number_format((float)$s['quantity'], 2), '0'), '.') ?> <?= htmlspecialchars($s['unit']) ?></span>
                    </div>
                    <?php if (!empty($s['dimensions'])): ?>
                        <div class="stock-meta">📏 <?= htmlspecialchars($s['dimensions']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($s['finish']) || !empty($s['wood_color'])): ?>
                        <div class="stock-meta">
                            <?php $color = trim((string)($s['finish'] ?? ''));
                            if ($color === '') $color = trim((string)($s['wood_color'] ?? '')); ?>
                            🎨 <?= htmlspecialchars($color) ?>
                        </div>
                    <?php endif; ?>
                    <div class="stock-meta" style="color:#64748b;font-size:10px">
                        Added: <?= date('d M Y', strtotime($s['created_at'])) ?>
                    </div>
                    <div class="stock-footer">
                        <button class="btn btn-sm btn-outline-secondary" onclick="openEditModal(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)" style="gap:4px">
                            <i class="bi bi-pencil"></i> Edit
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="if(confirm('Delete this stock?')) submitDelete(<?= (int)$s['id'] ?>)" style="gap:4px">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- CREATE MODAL -->
<div class="modal-overlay" id="createModal">
    <div class="modal-box">
        <div class="modal-header">
            <h5><i class="bi bi-plus-circle me-2" style="color:var(--gold)"></i>Add Stock Item</h5>
            <button class="modal-close" onclick="closeCreateModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post">
                <input type="hidden" name="_action" value="create">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px">
                    <div class="pwf-form-group" style="grid-column:1/-1"><label style="font-size:12px">Product Name *</label>
                        <input class="input" name="product_name" required autofocus style="font-size:13px">
                    </div>
                    <div class="pwf-form-group"><label style="font-size:12px">Quantity *</label>
                        <input class="input" type="number" step="0.01" name="quantity" value="1" required style="font-size:13px">
                    </div>
                    <div class="pwf-form-group"><label style="font-size:12px">Unit</label>
                        <input class="input" name="unit" value="pcs" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group"><label style="font-size:12px">Dimensions</label>
                        <input class="input" name="dimensions" placeholder="120×60×75" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group"><label style="font-size:12px">Wood Color</label>
                        <input class="input" name="wood_color" placeholder="Mahogany" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group"><label style="font-size:12px">Finish</label>
                        <input class="input" name="finish" placeholder="Lacquer" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group" style="grid-column:1/-1"><label style="font-size:12px">Specification / Notes</label>
                        <textarea name="specification" style="height:60px;font-size:13px"></textarea>
                    </div>
                    <div class="pwf-form-group" style="grid-column:1/-1"><label style="font-size:12px">Additional Notes</label>
                        <textarea name="notes" style="height:40px;font-size:13px"></textarea>
                    </div>
                </div>
                <div style="display:flex;gap:8px;margin-top:12px">
                    <button class="btn" type="submit" style="font-size:13px"><i class="bi bi-check-circle"></i> Add Stock</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="closeCreateModal()" style="font-size:13px">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-header">
            <h5><i class="bi bi-pencil-square me-2" style="color:var(--gold)"></i>Edit Stock Item</h5>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" id="editForm">
                <input type="hidden" name="_action" value="update">
                <input type="hidden" name="stock_id" id="ef_id">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px">
                    <div class="pwf-form-group" style="grid-column:1/-1"><label style="font-size:12px">Product Name</label>
                        <input class="input" name="product_name" id="ef_product" required style="font-size:13px">
                    </div>
                    <div class="pwf-form-group"><label style="font-size:12px">Quantity</label>
                        <input class="input" type="number" step="0.01" name="quantity" id="ef_qty" required style="font-size:13px">
                    </div>
                    <div class="pwf-form-group"><label style="font-size:12px">Unit</label>
                        <input class="input" name="unit" id="ef_unit" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group"><label style="font-size:12px">Dimensions</label>
                        <input class="input" name="dimensions" id="ef_dim" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group"><label style="font-size:12px">Wood Color</label>
                        <input class="input" name="wood_color" id="ef_color" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group"><label style="font-size:12px">Finish</label>
                        <input class="input" name="finish" id="ef_finish" style="font-size:13px">
                    </div>
                    <div class="pwf-form-group" style="grid-column:1/-1"><label style="font-size:12px">Specification / Notes</label>
                        <textarea name="specification" id="ef_spec" style="height:60px;font-size:13px"></textarea>
                    </div>
                    <div class="pwf-form-group" style="grid-column:1/-1"><label style="font-size:12px">Additional Notes</label>
                        <textarea name="notes" id="ef_notes" style="height:40px;font-size:13px"></textarea>
                    </div>
                </div>
                <div style="display:flex;gap:8px;margin-top:12px">
                    <button class="btn" type="submit" style="font-size:13px"><i class="bi bi-check-circle"></i> Save Changes</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="closeEditModal()" style="font-size:13px">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DELETE FORM -->
<form method="post" id="deleteForm" style="display:none">
    <input type="hidden" name="_action" value="delete">
    <input type="hidden" name="stock_id" id="df_id">
</form>

<script>
    function openCreateModal() {
        document.getElementById('createModal').classList.add('open');
    }

    function closeCreateModal() {
        document.getElementById('createModal').classList.remove('open');
    }

    function openEditModal(data) {
        document.getElementById('ef_id').value = data.id;
        document.getElementById('ef_product').value = data.product_name;
        document.getElementById('ef_qty').value = data.quantity;
        document.getElementById('ef_unit').value = data.unit || 'pcs';
        document.getElementById('ef_dim').value = data.dimensions || '';
        document.getElementById('ef_color').value = data.wood_color || '';
        document.getElementById('ef_finish').value = data.finish || '';
        document.getElementById('ef_spec').value = data.specification || '';
        document.getElementById('ef_notes').value = data.notes || '';
        document.getElementById('editModal').classList.add('open');
    }

    function closeEditModal() {
        document.getElementById('editModal').classList.remove('open');
    }

    function submitDelete(id) {
        document.getElementById('df_id').value = id;
        document.getElementById('deleteForm').submit();
    }

    document.getElementById('createModal').addEventListener('click', (e) => {
        if (e.target.id === 'createModal') closeCreateModal();
    });

    document.getElementById('editModal').addEventListener('click', (e) => {
        if (e.target.id === 'editModal') closeEditModal();
    });
</script>