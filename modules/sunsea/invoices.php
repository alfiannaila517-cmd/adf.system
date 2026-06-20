<?php
/**
 * Sunsea - Invoice Management
 * List, View, Add payment, Print invoice
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();

$pdo    = getSunseaConnection();
$action = $_GET['action'] ?? 'list';
$invId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ---- HANDLE POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // Add manual payment
    if ($postAction === 'add_payment') {
        $iId    = (int)($_POST['invoice_id'] ?? 0);
        $amount = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
        $method = $_POST['method'] ?? 'transfer';
        $date   = $_POST['payment_date'] ?: date('Y-m-d');
        $ref    = trim($_POST['reference'] ?? '');
        $notes  = trim($_POST['notes'] ?? '');
        $user   = $auth->getCurrentUser()['username'] ?? 'system';

        if ($iId > 0 && $amount > 0) {
            $pdo->prepare("
                INSERT INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_by)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([$iId, $date, $amount, $method, $ref, $notes, $user]);

            // Recalculate paid & remaining
            $totalPaid = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=?");
            $totalPaid->execute([$iId]);
            $paid = (float)$totalPaid->fetchColumn();

            $inv = $pdo->prepare("SELECT total_amount FROM invoices WHERE id=?");
            $inv->execute([$iId]);
            $total = (float)$inv->fetchColumn();
            $remaining = max(0, $total - $paid);

            $newStatus = $remaining <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'issued');
            $paidAt = $remaining <= 0 ? ', paid_at=NOW()' : '';
            $pdo->prepare("UPDATE invoices SET paid_amount=?, remaining_amount=?, status=? $paidAt WHERE id=?")
                ->execute([$paid, $remaining, $newStatus, $iId]);

            // Add to cashbook automatically
            $custRow = $pdo->prepare("SELECT c.name FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=?");
            $custRow->execute([$iId]);
            $custName = $custRow->fetchColumn();
            $invRow = $pdo->prepare("SELECT invoice_no FROM invoices WHERE id=?");
            $invRow->execute([$iId]);
            $invNo = $invRow->fetchColumn();
            $pdo->prepare("
                INSERT INTO cash_book (transaction_date, type, category, description, amount, reference, invoice_id, created_by)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([$date, 'income', 'Penerimaan Trip', "Pembayaran Invoice $invNo — $custName",
                         $amount, $ref ?: $invNo, $iId, $user]);

            $_SESSION['flash_message'] = 'Pembayaran berhasil dicatat.';
            $_SESSION['flash_type']    = 'success';
        }
        header('Location: invoices.php?action=view&id=' . $iId);
        exit;

    // Create direct invoice (without quotation)
    } elseif ($postAction === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $taxPct     = (float)($_POST['tax_pct'] ?? 11);
        $discount   = (float)str_replace(['.', ','], ['', '.'], $_POST['discount_amount'] ?? '0');
        $tripDate   = $_POST['trip_date']     ?: null;
        $tripEnd    = $_POST['trip_end_date'] ?: null;
        $paxCount   = max(1, (int)($_POST['pax_count'] ?? 1));
        $dueDate    = $_POST['due_date']      ?: date('Y-m-d', strtotime('+14 days'));
        $notes      = trim($_POST['notes'] ?? '');
        $user       = $auth->getCurrentUser()['username'] ?? 'system';

        $descriptions = $_POST['item_description'] ?? [];
        $itemTypes    = $_POST['item_type']         ?? [];
        $qtys         = $_POST['item_qty']          ?? [];
        $units        = $_POST['item_unit']         ?? [];
        $prices       = $_POST['item_price']        ?? [];

        $subtotal = 0;
        $items    = [];
        foreach ($descriptions as $i => $desc) {
            $desc = trim($desc);
            if (!$desc) continue;
            $qty  = max(0, (float)$qtys[$i]);
            $price= (float)str_replace(['.', ','], ['', '.'], $prices[$i] ?? '0');
            $sub  = $qty * $price;
            $subtotal += $sub;
            $items[] = ['item_type'=>$itemTypes[$i]??'other','description'=>$desc,
                        'qty'=>$qty,'unit'=>trim($units[$i]??'pax'),'unit_price'=>$price,
                        'subtotal'=>$sub,'sort_order'=>$i];
        }
        $tax       = round($subtotal * $taxPct / 100, 2);
        $total     = $subtotal + $tax - $discount;
        $remaining = $total;

        if ($id > 0) {
            $pdo->prepare("
                UPDATE invoices SET customer_id=?, trip_date=?, trip_end_date=?, pax_count=?,
                subtotal=?, tax_pct=?, tax_amount=?, discount_amount=?, total_amount=?,
                remaining_amount=?, due_date=?, notes=?, updated_at=NOW() WHERE id=?
            ")->execute([$customerId,$tripDate,$tripEnd,$paxCount,
                          $subtotal,$taxPct,$tax,$discount,$total,$remaining,$dueDate,$notes,$id]);
            $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$id]);
        } else {
            $invNo = sunseaNextNumber($pdo, 'invoice');
            $pdo->prepare("
                INSERT INTO invoices 
                (invoice_no, customer_id, trip_date, trip_end_date, pax_count,
                 subtotal, tax_pct, tax_amount, discount_amount, total_amount,
                 remaining_amount, due_date, notes, status, issued_at, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'issued',NOW(),?)
            ")->execute([$invNo,$customerId,$tripDate,$tripEnd,$paxCount,
                          $subtotal,$taxPct,$tax,$discount,$total,$remaining,$dueDate,$notes,$user]);
            $id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare("INSERT INTO invoice_items (invoice_id,item_type,description,qty,unit,unit_price,subtotal,sort_order) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($items as $item) {
            $ins->execute([$id,$item['item_type'],$item['description'],$item['qty'],$item['unit'],$item['unit_price'],$item['subtotal'],$item['sort_order']]);
        }

        $_SESSION['flash_message'] = 'Invoice berhasil disimpan.';
        $_SESSION['flash_type']    = 'success';
        header('Location: invoices.php?action=view&id=' . $id);
        exit;
    }
}

// ---- LOAD DATA ----
$invoice = null;
$invItems = [];
$payments = [];
if (in_array($action, ['view', 'print']) && $invId > 0) {
    $s = $pdo->prepare("
        SELECT i.*, c.name as customer_name, c.phone as customer_phone,
               c.email as customer_email, c.address as customer_address, c.city as customer_city
        FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=?
    ");
    $s->execute([$invId]);
    $invoice = $s->fetch();
    if (!$invoice) { header('Location: invoices.php'); exit; }

    $si = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
    $si->execute([$invId]);
    $invItems = $si->fetchAll();

    $sp = $pdo->prepare("SELECT * FROM payments WHERE invoice_id=? ORDER BY payment_date");
    $sp->execute([$invId]);
    $payments = $sp->fetchAll();
}

$editInvoice = null;
if ($action === 'edit' && $invId > 0) {
    $s2 = $pdo->prepare("SELECT * FROM invoices WHERE id=?");
    $s2->execute([$invId]);
    $editInvoice = $s2->fetch();
    $si2 = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
    $si2->execute([$invId]);
    $invItems = $si2->fetchAll();
}

$customers = $pdo->query("SELECT id, name FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();

// List
$statusFilter = $_GET['status'] ?? '';
$wh = $statusFilter ? "WHERE i.status=?" : "";
$lp = $statusFilter ? [$statusFilter] : [];
$invoiceList = $pdo->prepare("
    SELECT i.id, i.invoice_no, i.status, i.total_amount, i.paid_amount, i.remaining_amount, i.due_date, i.created_at,
           c.name as customer_name, i.pax_count
    FROM invoices i JOIN customers c ON c.id=i.customer_id $wh
    ORDER BY i.created_at DESC LIMIT 100
");
$invoiceList->execute($lp);
$invoiceList = $invoiceList->fetchAll();

$pageTitle  = match($action) {
    'add'   => 'Buat Invoice Baru',
    'edit'  => 'Edit Invoice',
    'view'  => 'Detail Invoice',
    'print' => 'Cetak Invoice',
    default => 'Daftar Invoice'
};
$activePage = 'invoices';

// ---- PRINT ----
if ($action === 'print' && $invoice):
    $companyName    = sunseaSetting($pdo, 'company_name', 'Sunsea');
    $companyAddress = sunseaSetting($pdo, 'company_address', '');
    $companyPhone   = sunseaSetting($pdo, 'company_phone', '');
    $bankName       = sunseaSetting($pdo, 'bank_name', '');
    $bankAccount    = sunseaSetting($pdo, 'bank_account', '');
    $bankHolder     = sunseaSetting($pdo, 'bank_holder', '');
    $footer         = sunseaSetting($pdo, 'invoice_footer', '');
?>
<!DOCTYPE html><html lang="id"><head>
<meta charset="UTF-8">
<title>Invoice <?php echo htmlspecialchars($invoice['invoice_no']); ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',sans-serif;font-size:12px;color:#0F172A;padding:30px 40px;}
.header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;border-bottom:3px solid #0EA5E9;padding-bottom:14px;}
.brand{font-size:24px;font-weight:800;color:#0C4A6E;}
.doc-no{font-size:20px;font-weight:800;color:#0EA5E9;}
.status-badge{display:inline-block;padding:4px 12px;border-radius:99px;font-size:11px;font-weight:700;background:#D1FAE5;color:#065F46;}
.to-from{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;}
.section-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#64748B;margin-bottom:4px;}
table{width:100%;border-collapse:collapse;margin-bottom:16px;}
th{background:#F0F9FF;padding:8px 10px;text-align:left;font-size:11px;color:#64748B;font-weight:700;}
td{padding:8px 10px;border-bottom:1px solid #E2E8F0;}
.total-box{float:right;width:280px;}
.total-row{display:flex;justify-content:space-between;padding:5px 0;font-size:12px;}
.total-row.final{font-size:15px;font-weight:800;color:#0EA5E9;border-top:2px solid #0EA5E9;padding-top:8px;margin-top:4px;}
.bank-info{background:#F0F9FF;padding:12px;border-radius:8px;margin-top:20px;font-size:11px;}
.footer{margin-top:28px;padding-top:10px;border-top:1px solid #E2E8F0;font-size:11px;color:#64748B;text-align:center;}
@media print{body{padding:15px;}}
</style></head>
<body onload="window.print()">
<div class="header">
    <div>
        <div class="brand">🌊 <?php echo htmlspecialchars($companyName); ?></div>
        <div style="color:#64748B;font-size:11px;">Travel Bureau</div>
        <?php if ($companyAddress): ?><div style="color:#64748B;font-size:11px;margin-top:4px;"><?php echo nl2br(htmlspecialchars($companyAddress)); ?></div><?php endif; ?>
        <?php if ($companyPhone): ?><div style="color:#64748B;font-size:11px;">📞 <?php echo htmlspecialchars($companyPhone); ?></div><?php endif; ?>
    </div>
    <div style="text-align:right;">
        <div class="doc-no"><?php echo htmlspecialchars($invoice['invoice_no']); ?></div>
        <div>INVOICE</div>
        <div style="color:#64748B;">Tanggal: <?php echo $invoice['issued_at'] ? date('d/m/Y', strtotime($invoice['issued_at'])) : date('d/m/Y'); ?></div>
        <div style="color:#64748B;">Jatuh Tempo: <?php echo $invoice['due_date'] ? date('d/m/Y', strtotime($invoice['due_date'])) : '-'; ?></div>
        <?php
        $sl = ['issued'=>'Issued','partial'=>'Partial','paid'=>'Lunas','overdue'=>'Overdue','cancelled'=>'Batal'];
        $sc = ['issued'=>'background:#E0F2FE;color:#0C4A6E','partial'=>'background:#FEF3C7;color:#92400E','paid'=>'background:#D1FAE5;color:#065F46','overdue'=>'background:#FEE2E2;color:#991B1B'];
        echo '<div style="margin-top:6px;"><span class="status-badge" style="'.($sc[$invoice['status']]??'background:#F1F5F9;color:#64748B').'">'.($sl[$invoice['status']]??ucfirst($invoice['status'])).'</span></div>';
        ?>
    </div>
</div>

<div class="to-from">
    <div>
        <div class="section-title">Tagihan Kepada</div>
        <strong><?php echo htmlspecialchars($invoice['customer_name']); ?></strong>
        <?php if ($invoice['customer_address']): ?><div><?php echo htmlspecialchars($invoice['customer_address']); ?></div><?php endif; ?>
        <?php if ($invoice['customer_phone']): ?><div>📞 <?php echo htmlspecialchars($invoice['customer_phone']); ?></div><?php endif; ?>
    </div>
    <div>
        <div class="section-title">Info Trip</div>
        <?php if ($invoice['trip_date']): ?><div>Tanggal: <strong><?php echo date('d/m/Y', strtotime($invoice['trip_date'])); ?><?php echo $invoice['trip_end_date'] ? ' - '.date('d/m/Y', strtotime($invoice['trip_end_date'])) : ''; ?></strong></div><?php endif; ?>
        <div>Peserta: <strong><?php echo $invoice['pax_count']; ?> orang</strong></div>
    </div>
</div>

<table>
    <thead><tr><th>#</th><th>Keterangan</th><th>Qty</th><th>Sat.</th><th>Harga</th><th>Subtotal</th></tr></thead>
    <tbody>
        <?php foreach ($invItems as $i => $item): ?>
        <tr>
            <td><?php echo $i+1; ?></td>
            <td><?php echo htmlspecialchars($item['description']); ?></td>
            <td style="text-align:right;"><?php echo $item['qty'] == intval($item['qty']) ? (int)$item['qty'] : $item['qty']; ?></td>
            <td><?php echo htmlspecialchars($item['unit']); ?></td>
            <td style="text-align:right;"><?php echo sunseaRupiah((float)$item['unit_price']); ?></td>
            <td style="text-align:right;font-weight:600;"><?php echo sunseaRupiah((float)$item['subtotal']); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="total-box">
    <div class="total-row"><span>Subtotal</span><span><?php echo sunseaRupiah((float)$invoice['subtotal']); ?></span></div>
    <?php if ($invoice['discount_amount'] > 0): ?>
    <div class="total-row"><span>Diskon</span><span>- <?php echo sunseaRupiah((float)$invoice['discount_amount']); ?></span></div>
    <?php endif; ?>
    <div class="total-row"><span>PPN <?php echo $invoice['tax_pct']; ?>%</span><span><?php echo sunseaRupiah((float)$invoice['tax_amount']); ?></span></div>
    <div class="total-row final"><span>TOTAL</span><span><?php echo sunseaRupiah((float)$invoice['total_amount']); ?></span></div>
    <?php if ($invoice['paid_amount'] > 0): ?>
    <div class="total-row" style="color:#10B981;"><span>Terbayar</span><span>- <?php echo sunseaRupiah((float)$invoice['paid_amount']); ?></span></div>
    <div class="total-row" style="font-weight:700;<?php echo $invoice['remaining_amount'] > 0 ? 'color:#EF4444' : ''; ?>"><span>Sisa</span><span><?php echo sunseaRupiah((float)$invoice['remaining_amount']); ?></span></div>
    <?php endif; ?>
</div>
<div style="clear:both;"></div>

<?php if ($bankName || $bankAccount): ?>
<div class="bank-info">
    <strong>Informasi Pembayaran:</strong>
    <?php echo htmlspecialchars($bankName); ?> — No. Rek: <?php echo htmlspecialchars($bankAccount); ?> a/n <?php echo htmlspecialchars($bankHolder); ?>
</div>
<?php endif; ?>

<?php if ($invoice['notes']): ?><div style="margin-top:14px;"><strong>Catatan:</strong><br><?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></div><?php endif; ?>
<div class="footer"><?php echo htmlspecialchars($footer); ?></div>
</body></html>
<?php exit; endif;

include 'layout-header.php';
?>

<?php if ($action === 'view' && $invoice): ?>
<!-- ============ VIEW ============ -->
<div style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;">
    <a href="invoices.php" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="arrow-left"></i> Kembali</a>
    <a href="invoices.php?action=print&id=<?php echo $invoice['id']; ?>" target="_blank" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="printer"></i> Cetak</a>
    <?php if (in_array($invoice['status'], ['issued','partial'])): ?>
    <button onclick="document.getElementById('paymentModal').style.display='flex'" class="ss-btn ss-btn-primary ss-btn-sm">
        <i data-feather="dollar-sign"></i> Catat Pembayaran
    </button>
    <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:20px;">
    <div>
        <div class="ss-card" style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:16px;">
                <div>
                    <div style="font-size:22px;font-weight:800;color:var(--ss-ocean);"><?php echo htmlspecialchars($invoice['invoice_no']); ?></div>
                    <div style="color:var(--ss-muted);">untuk <?php echo htmlspecialchars($invoice['customer_name']); ?></div>
                </div>
                <span class="ss-status ss-status-<?php echo $invoice['status']; ?>" style="font-size:13px;padding:5px 14px;"><?php echo ucfirst($invoice['status']); ?></span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;padding:14px;background:var(--ss-sky);border-radius:8px;margin-bottom:16px;">
                <div><div style="font-size:10px;color:var(--ss-muted);">Tanggal Trip</div><div style="font-weight:600;"><?php echo $invoice['trip_date'] ? date('d M Y', strtotime($invoice['trip_date'])) : '-'; ?></div></div>
                <div><div style="font-size:10px;color:var(--ss-muted);">Peserta</div><div style="font-weight:600;"><?php echo $invoice['pax_count']; ?> orang</div></div>
                <div><div style="font-size:10px;color:var(--ss-muted);">Jatuh Tempo</div><div style="font-weight:600;"><?php echo $invoice['due_date'] ? date('d M Y', strtotime($invoice['due_date'])) : '-'; ?></div></div>
            </div>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead><tr><th>#</th><th>Keterangan</th><th>Qty</th><th>Sat.</th><th>Harga</th><th>Subtotal</th></tr></thead>
                    <tbody>
                        <?php foreach ($invItems as $i => $item): ?>
                        <tr>
                            <td><?php echo $i+1; ?></td>
                            <td><?php echo htmlspecialchars($item['description']); ?></td>
                            <td><?php echo $item['qty'] == intval($item['qty']) ? (int)$item['qty'] : $item['qty']; ?></td>
                            <td><?php echo htmlspecialchars($item['unit']); ?></td>
                            <td><?php echo sunseaRupiah((float)$item['unit_price']); ?></td>
                            <td style="font-weight:600;"><?php echo sunseaRupiah((float)$item['subtotal']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($payments)): ?>
        <div class="ss-card">
            <div class="ss-card-title" style="margin-bottom:14px;">Riwayat Pembayaran</div>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead><tr><th>Tanggal</th><th>Jumlah</th><th>Metode</th><th>Referensi</th></tr></thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($p['payment_date'])); ?></td>
                            <td style="font-weight:600;color:var(--ss-success);"><?php echo sunseaRupiah((float)$p['amount']); ?></td>
                            <td><?php echo ucfirst($p['method']); ?></td>
                            <td><?php echo htmlspecialchars($p['reference'] ?: '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="ss-card">
            <div class="ss-card-title" style="margin-bottom:14px;">Ringkasan</div>
            <?php
            $rows = [['Subtotal', $invoice['subtotal'], '']];
            if ($invoice['discount_amount'] > 0) $rows[] = ['Diskon', -$invoice['discount_amount'], 'color:var(--ss-success)'];
            $rows[] = ['PPN '.$invoice['tax_pct'].'%', $invoice['tax_amount'], ''];
            foreach ($rows as [$lbl, $val, $style]): ?>
            <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--ss-gray-2);">
                <span style="color:var(--ss-muted);"><?php echo $lbl; ?></span>
                <span style="font-weight:600;<?php echo $style; ?>"><?php echo sunseaRupiah((float)$val); ?></span>
            </div>
            <?php endforeach; ?>
            <div style="display:flex;justify-content:space-between;padding:12px 0 0;font-size:18px;font-weight:800;color:var(--ss-ocean);">
                <span>TOTAL</span><span><?php echo sunseaRupiah((float)$invoice['total_amount']); ?></span>
            </div>
            <?php if ($invoice['paid_amount'] > 0): ?>
            <div style="display:flex;justify-content:space-between;padding:8px 0;font-size:13px;color:var(--ss-success);font-weight:600;">
                <span>Terbayar</span><span><?php echo sunseaRupiah((float)$invoice['paid_amount']); ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:8px 14px;background:<?php echo $invoice['remaining_amount'] > 0 ? '#FEE2E2' : '#D1FAE5'; ?>;border-radius:8px;font-weight:800;color:<?php echo $invoice['remaining_amount'] > 0 ? 'var(--ss-danger)' : 'var(--ss-success)'; ?>;">
                <span><?php echo $invoice['remaining_amount'] > 0 ? 'Sisa Tagihan' : '✓ Lunas'; ?></span>
                <span><?php echo sunseaRupiah((float)$invoice['remaining_amount']); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div id="paymentModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;">
    <div class="ss-card" style="width:420px;max-width:96vw;">
        <div class="ss-card-header">
            <div class="ss-card-title">Catat Pembayaran</div>
            <button onclick="document.getElementById('paymentModal').style.display='none'" style="background:none;border:none;cursor:pointer;"><i data-feather="x"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_payment">
            <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
            <div class="ss-form-group">
                <label class="ss-label">Jumlah (Rp) *</label>
                <input type="text" name="amount" class="ss-input" required
                       placeholder="<?php echo number_format($invoice['remaining_amount'], 0, ',', '.'); ?>"
                       value="<?php echo number_format($invoice['remaining_amount'], 0, ',', '.'); ?>">
            </div>
            <div class="ss-form-group">
                <label class="ss-label">Tanggal Bayar</label>
                <input type="date" name="payment_date" class="ss-input" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="ss-form-group">
                <label class="ss-label">Metode</label>
                <select name="method" class="ss-select">
                    <option value="transfer">Transfer Bank</option>
                    <option value="cash">Tunai</option>
                    <option value="qris">QRIS</option>
                    <option value="other">Lainnya</option>
                </select>
            </div>
            <div class="ss-form-group">
                <label class="ss-label">No. Referensi / Bukti</label>
                <input type="text" name="reference" class="ss-input" placeholder="Opsional">
            </div>
            <div class="ss-form-group">
                <label class="ss-label">Catatan</label>
                <textarea name="notes" class="ss-textarea" rows="2"></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('paymentModal').style.display='none'" class="ss-btn ss-btn-outline">Batal</button>
                <button type="submit" class="ss-btn ss-btn-primary"><i data-feather="check"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>

<?php elseif (in_array($action, ['add', 'edit'])): ?>
<!-- Add/Edit: same pattern as quotations form (simplified) -->
<div style="max-width:900px;">
    <a href="invoices.php" class="ss-btn ss-btn-outline ss-btn-sm" style="margin-bottom:16px;display:inline-flex;"><i data-feather="arrow-left"></i> Kembali</a>
    <form method="POST">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo $editInvoice['id'] ?? 0; ?>">
        <div class="ss-card" style="margin-bottom:16px;">
            <div class="ss-card-title" style="margin-bottom:16px;">Informasi Invoice</div>
            <div class="ss-form-grid cols-2">
                <div class="ss-form-group" style="grid-column:1/-1;">
                    <label class="ss-label">Customer *</label>
                    <select name="customer_id" class="ss-select" required>
                        <option value="">-- Pilih Customer --</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($editInvoice['customer_id'] ?? $_GET['customer_id'] ?? 0) == $c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ss-form-group"><label class="ss-label">Tanggal Trip</label><input type="date" name="trip_date" class="ss-input" value="<?php echo $editInvoice['trip_date'] ?? ''; ?>"></div>
                <div class="ss-form-group"><label class="ss-label">Tanggal Selesai</label><input type="date" name="trip_end_date" class="ss-input" value="<?php echo $editInvoice['trip_end_date'] ?? ''; ?>"></div>
                <div class="ss-form-group"><label class="ss-label">Peserta</label><input type="number" name="pax_count" class="ss-input" min="1" value="<?php echo $editInvoice['pax_count'] ?? 1; ?>"></div>
                <div class="ss-form-group"><label class="ss-label">Jatuh Tempo</label><input type="date" name="due_date" class="ss-input" value="<?php echo $editInvoice['due_date'] ?? date('Y-m-d', strtotime('+14 days')); ?>"></div>
                <div class="ss-form-group"><label class="ss-label">PPN (%)</label><input type="number" name="tax_pct" class="ss-input" step="0.1" value="<?php echo $editInvoice['tax_pct'] ?? 11; ?>" id="taxInput2"></div>
                <div class="ss-form-group"><label class="ss-label">Diskon (Rp)</label><input type="text" name="discount_amount" class="ss-input" value="<?php echo number_format($editInvoice['discount_amount'] ?? 0, 0, ',', '.'); ?>" id="discountInput2"></div>
                <div class="ss-form-group" style="grid-column:1/-1;"><label class="ss-label">Catatan</label><textarea name="notes" class="ss-textarea"><?php echo htmlspecialchars($editInvoice['notes'] ?? ''); ?></textarea></div>
            </div>
        </div>

        <div class="ss-card" style="margin-bottom:16px;">
            <div class="ss-card-header">
                <div class="ss-card-title">Item Invoice</div>
                <button type="button" onclick="addItem2()" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="plus"></i> Tambah Baris</button>
            </div>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead><tr><th style="width:120px;">Kategori</th><th>Keterangan</th><th style="width:60px;">Qty</th><th style="width:60px;">Sat.</th><th style="width:130px;">Harga</th><th style="width:130px;">Subtotal</th><th style="width:40px;"></th></tr></thead>
                    <tbody id="itemsBody2">
                        <?php if (!empty($invItems)): ?>
                            <?php foreach ($invItems as $item): echo '<tr>'.invItemRow($item['item_type'],$item['description'],$item['qty'],$item['unit'],$item['unit_price']).'</tr>'; endforeach; ?>
                        <?php else: echo '<tr>'.invItemRow().'</tr><tr>'.invItemRow().'</tr>'; endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="text-align:right;margin-top:12px;font-size:15px;font-weight:800;color:var(--ss-ocean);">
                TOTAL: <span id="calcTotal2">Rp 0</span>
            </div>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <a href="invoices.php" class="ss-btn ss-btn-outline">Batal</a>
            <button type="submit" class="ss-btn ss-btn-primary"><i data-feather="save"></i> Simpan Invoice</button>
        </div>
    </form>
</div>

<?php else: ?>
<!-- ============ LIST ============ -->
<div class="ss-card">
    <div class="ss-card-header">
        <div><div class="ss-card-title">Daftar Invoice</div><div class="ss-card-sub"><?php echo count($invoiceList); ?> invoice</div></div>
        <a href="invoices.php?action=add" class="ss-btn ss-btn-primary"><i data-feather="plus"></i> Buat Invoice</a>
    </div>
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
        <?php foreach (['' => 'Semua', 'issued' => 'Issued', 'partial' => 'Partial', 'paid' => 'Lunas', 'overdue' => 'Overdue'] as $st => $lbl): ?>
        <a href="invoices.php?status=<?php echo $st; ?>" class="ss-btn ss-btn-sm <?php echo $statusFilter === $st ? 'ss-btn-primary' : 'ss-btn-outline'; ?>"><?php echo $lbl; ?></a>
        <?php endforeach; ?>
    </div>
    <?php if (empty($invoiceList)): ?>
        <div class="ss-empty"><div class="ss-empty-icon">🧾</div><h3>Belum ada invoice</h3></div>
    <?php else: ?>
        <div class="ss-table-wrap">
            <table class="ss-table">
                <thead><tr><th>No. Invoice</th><th>Customer</th><th>Total</th><th>Terbayar</th><th>Sisa</th><th>Status</th><th>Jatuh Tempo</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php foreach ($invoiceList as $inv): ?>
                    <tr>
                        <td><a href="invoices.php?action=view&id=<?php echo $inv['id']; ?>" style="color:var(--ss-ocean);font-weight:600;text-decoration:none;"><?php echo htmlspecialchars($inv['invoice_no']); ?></a></td>
                        <td><?php echo htmlspecialchars($inv['customer_name']); ?></td>
                        <td style="font-weight:600;"><?php echo sunseaRupiah((float)$inv['total_amount']); ?></td>
                        <td style="color:var(--ss-success);font-weight:600;"><?php echo sunseaRupiah((float)$inv['paid_amount']); ?></td>
                        <td style="color:<?php echo $inv['remaining_amount'] > 0 ? 'var(--ss-danger)' : 'var(--ss-success)'; ?>;font-weight:700;"><?php echo sunseaRupiah((float)$inv['remaining_amount']); ?></td>
                        <td><span class="ss-status ss-status-<?php echo $inv['status']; ?>"><?php echo ucfirst($inv['status']); ?></span></td>
                        <td><?php echo $inv['due_date'] ? date('d M Y', strtotime($inv['due_date'])) : '-'; ?></td>
                        <td><a href="invoices.php?action=view&id=<?php echo $inv['id']; ?>" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="eye"></i></a>
                            <a href="invoices.php?action=print&id=<?php echo $inv['id']; ?>" target="_blank" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="printer"></i></a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
function invItemRow($type='', $desc='', $qty=1, $unit='pax', $price=0): string {
    $typeOpts = ['accommodation','transport','meal','activity','guide','equipment','other'];
    $typeLabels = ['Penginapan','Transport','Makan','Aktivitas','Guide','Perlengkapan','Lainnya'];
    $sel = '';
    foreach ($typeOpts as $i => $t) {
        $s = $type === $t ? ' selected':'';
        $sel .= "<option value=\"$t\"$s>{$typeLabels[$i]}</option>";
    }
    $pFmt = number_format((float)$price, 0, ',', '.');
    return "<td><select name=\"item_type[]\" class=\"ss-select\" style=\"font-size:12px;padding:6px 8px;\">$sel</select></td>
        <td><input type=\"text\" name=\"item_description[]\" class=\"ss-input\" style=\"font-size:12px;padding:6px 8px;\" value=\"$desc\" placeholder=\"Keterangan...\"></td>
        <td><input type=\"number\" name=\"item_qty[]\" class=\"ss-input item-qty2\" style=\"font-size:12px;padding:6px 8px;\" value=\"$qty\" min=\"0\" step=\"0.5\"></td>
        <td><input type=\"text\" name=\"item_unit[]\" class=\"ss-input\" style=\"font-size:12px;padding:6px 8px;\" value=\"$unit\"></td>
        <td><input type=\"text\" name=\"item_price[]\" class=\"ss-input item-price2\" style=\"font-size:12px;padding:6px 8px;\" value=\"$pFmt\" placeholder=\"0\"></td>
        <td><input type=\"text\" class=\"ss-input item-sub2\" style=\"font-size:12px;padding:6px 8px;font-weight:600;\" readonly placeholder=\"0\"></td>
        <td><button type=\"button\" onclick=\"removeRow2(this)\" style=\"background:none;border:none;cursor:pointer;color:var(--ss-danger);\"><i data-feather=\"x\" style=\"width:14px;height:14px;\"></i></button></td>";
}
?>
<script>
function unFmt(s){ return parseFloat(String(s).replace(/\./g,'').replace(',','.')) || 0; }
function fmt(n){ return 'Rp '+Math.round(n).toLocaleString('id-ID'); }
function calcTotals2(){
    var sub=0;
    document.querySelectorAll('#itemsBody2 tr').forEach(function(row){
        var q=parseFloat(row.querySelector('.item-qty2')?.value)||0;
        var p=unFmt(row.querySelector('.item-price2')?.value||'0');
        var s=q*p; var sf=row.querySelector('.item-sub2');
        if(sf) sf.value=s?Math.round(s).toLocaleString('id-ID'):'';
        sub+=s;
    });
    var disc=unFmt(document.getElementById('discountInput2')?.value||'0');
    var taxP=parseFloat(document.getElementById('taxInput2')?.value)||0;
    var tax=(sub-disc)*taxP/100;
    var tot=sub+tax-disc;
    document.getElementById('calcTotal2').textContent=fmt(tot);
}
function addItem2(){
    var tr=document.createElement('tr');
    tr.innerHTML=`<td><select name="item_type[]" class="ss-select" style="font-size:12px;padding:6px 8px;">
        <option value="accommodation">Penginapan</option><option value="transport">Transport</option>
        <option value="meal">Makan</option><option value="activity">Aktivitas</option>
        <option value="guide">Guide</option><option value="equipment">Perlengkapan</option>
        <option value="other" selected>Lainnya</option></select></td>
        <td><input type="text" name="item_description[]" class="ss-input" style="font-size:12px;padding:6px 8px;" placeholder="Keterangan..."></td>
        <td><input type="number" name="item_qty[]" class="ss-input item-qty2" style="font-size:12px;padding:6px 8px;" value="1" min="0" step="0.5"></td>
        <td><input type="text" name="item_unit[]" class="ss-input" style="font-size:12px;padding:6px 8px;" value="pax"></td>
        <td><input type="text" name="item_price[]" class="ss-input item-price2" style="font-size:12px;padding:6px 8px;" placeholder="0"></td>
        <td><input type="text" class="ss-input item-sub2" style="font-size:12px;padding:6px 8px;font-weight:600;" readonly placeholder="0"></td>
        <td><button type="button" onclick="removeRow2(this)" style="background:none;border:none;cursor:pointer;color:var(--ss-danger);"><i data-feather="x" style="width:14px;height:14px;"></i></button></td>`;
    document.getElementById('itemsBody2').appendChild(tr);
    feather.replace();
    setupRowListeners2(tr);
}
function removeRow2(btn){ btn.closest('tr').remove(); calcTotals2(); }
function setupRowListeners2(row){
    row.querySelectorAll('.item-qty2,.item-price2').forEach(function(i){ i.addEventListener('input',calcTotals2); });
}
document.querySelectorAll('#itemsBody2 tr').forEach(setupRowListeners2);
['discountInput2','taxInput2'].forEach(function(id){ document.getElementById(id)?.addEventListener('input',calcTotals2); });
calcTotals2();
</script>

<?php include 'layout-footer.php'; ?>
