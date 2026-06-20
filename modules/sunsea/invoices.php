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
            ")->execute([
                $date,
                'income',
                'Penerimaan Trip',
                "Pembayaran Invoice $invNo — $custName",
                $amount,
                $ref ?: $invNo,
                $iId,
                $user
            ]);

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
            $price = (float)str_replace(['.', ','], ['', '.'], $prices[$i] ?? '0');
            $sub  = $qty * $price;
            $subtotal += $sub;
            $items[] = [
                'item_type' => $itemTypes[$i] ?? 'other',
                'description' => $desc,
                'qty' => $qty,
                'unit' => trim($units[$i] ?? 'pax'),
                'unit_price' => $price,
                'subtotal' => $sub,
                'sort_order' => $i
            ];
        }
        $tax       = round($subtotal * $taxPct / 100, 2);
        $total     = $subtotal + $tax - $discount;
        $remaining = $total;

        if ($id > 0) {
            $pdo->prepare("
                UPDATE invoices SET customer_id=?, trip_date=?, trip_end_date=?, pax_count=?,
                subtotal=?, tax_pct=?, tax_amount=?, discount_amount=?, total_amount=?,
                remaining_amount=?, due_date=?, notes=?, updated_at=NOW() WHERE id=?
            ")->execute([
                $customerId,
                $tripDate,
                $tripEnd,
                $paxCount,
                $subtotal,
                $taxPct,
                $tax,
                $discount,
                $total,
                $remaining,
                $dueDate,
                $notes,
                $id
            ]);
            $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$id]);
        } else {
            $invNo = sunseaNextNumber($pdo, 'invoice');
            $pdo->prepare("
                INSERT INTO invoices 
                (invoice_no, customer_id, trip_date, trip_end_date, pax_count,
                 subtotal, tax_pct, tax_amount, discount_amount, total_amount,
                 remaining_amount, due_date, notes, status, issued_at, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'issued',NOW(),?)
            ")->execute([
                $invNo,
                $customerId,
                $tripDate,
                $tripEnd,
                $paxCount,
                $subtotal,
                $taxPct,
                $tax,
                $discount,
                $total,
                $remaining,
                $dueDate,
                $notes,
                $user
            ]);
            $id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare("INSERT INTO invoice_items (invoice_id,item_type,description,qty,unit,unit_price,subtotal,sort_order) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($items as $item) {
            $ins->execute([$id, $item['item_type'], $item['description'], $item['qty'], $item['unit'], $item['unit_price'], $item['subtotal'], $item['sort_order']]);
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
    if (!$invoice) {
        header('Location: invoices.php');
        exit;
    }

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

$pageTitle  = match ($action) {
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
    $companyEmail   = sunseaSetting($pdo, 'company_email', '');
    $companyLogo    = sunseaSetting($pdo, 'invoice_logo', sunseaSetting($pdo, 'company_logo', ''));
    $companyTagline = sunseaSetting($pdo, 'company_tagline', 'Travel Bureau');
    $bankName       = sunseaSetting($pdo, 'bank_name', '');
    $bankAccount    = sunseaSetting($pdo, 'bank_account', '');
    $bankHolder     = sunseaSetting($pdo, 'bank_holder', '');
    $bankName2      = sunseaSetting($pdo, 'bank_name2', '');
    $bankAccount2   = sunseaSetting($pdo, 'bank_account2', '');
    $bankHolder2    = sunseaSetting($pdo, 'bank_holder2', '');
    $invoiceNotes   = sunseaSetting($pdo, 'invoice_notes', '');
    $footer         = sunseaSetting($pdo, 'invoice_footer', '');
    $logoUrl        = $companyLogo ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($companyLogo, '/')) : '';

    $issuedDate = $invoice['issued_at'] ? strtotime($invoice['issued_at']) : time();
    $dueDateTs  = $invoice['due_date'] ? strtotime($invoice['due_date']) : $issuedDate;
    $termsLabel = ($dueDateTs <= $issuedDate) ? 'On Receipt' : (string) max(0, (int) ceil(($dueDateTs - $issuedDate) / 86400));
    if ($termsLabel !== 'On Receipt') {
        $termsLabel .= ' Days';
    }

    $lastPayment = null;
    if (!empty($payments)) {
        $lastPayment = end($payments);
    }

    $dpPayment = null;
    if (!empty($payments)) {
        $dpPayment = $payments[0];
    }

    $watermarkText = '';
    if ((float)$invoice['remaining_amount'] <= 0 || $invoice['status'] === 'paid') {
        $watermarkText = 'LUNAS';
    } elseif ((float)$invoice['paid_amount'] > 0 || $invoice['status'] === 'partial') {
        $watermarkText = 'DP';
    }

    $fmtMoney = function ($amount, $prefix = 'Rp') {
        return $prefix . number_format((float) $amount, 2, '.', ',');
    };
?>
    <!DOCTYPE html>
    <html lang="id">

    <head>
        <meta charset="UTF-8">
        <title>Invoice <?php echo htmlspecialchars($invoice['invoice_no']); ?></title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            @page {
                size: A4;
                margin: 0;
            }

            body {
                font-family: "Segoe UI", "Trebuchet MS", Arial, sans-serif;
                font-size: 12px;
                color: #17324d;
                background: #f3f7fb;
            }

            .sheet {
                width: 210mm;
                min-height: 297mm;
                margin: 0 auto;
                padding: 16mm 14mm 14mm;
                position: relative;
                background: #ffffff;
            }

            .watermark {
                position: absolute;
                top: 47%;
                left: 50%;
                transform: translate(-50%, -50%) rotate(-20deg);
                font-size: 110px;
                font-weight: 900;
                letter-spacing: 10px;
                opacity: .06;
                pointer-events: none;
                z-index: 1;
                text-transform: uppercase;
                white-space: nowrap;
            }

            .watermark.dp {
                color: #0ea5e9;
            }

            .watermark.paid {
                color: #0284c7;
            }

            .top-accent {
                position: absolute;
                left: 0;
                top: 0;
                right: 0;
                height: 18mm;
                background: linear-gradient(95deg, #0b3b6e 0%, #0f4d87 32%, #1279b5 68%, #35a7df 100%);
                opacity: .96;
            }

            .bottom-accent {
                position: absolute;
                left: 0;
                right: 0;
                bottom: 0;
                height: 8mm;
                background: linear-gradient(90deg, #0b3b6e 0%, #1077b2 100%);
            }

            .header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 16px;
                margin-top: 6mm;
                position: relative;
                z-index: 2;
                padding: 10px 12px;
                border: 1px solid #d8e7f4;
                border-radius: 10px;
                background: linear-gradient(160deg, #ffffff 0%, #f5faff 100%);
            }

            .company {
                display: flex;
                gap: 12px;
                align-items: flex-start;
                width: 63%;
            }

            .logo {
                width: 44mm;
                height: 26mm;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 8px;
                background: #ffffff;
                border: 1px solid #d4e4f2;
                padding: 4px;
            }

            .logo img {
                max-width: 100%;
                max-height: 100%;
                object-fit: contain;
            }

            .logo-fallback {
                font-size: 30px;
                color: #0e76b2;
            }

            .company-name {
                font-size: 26px;
                font-weight: 800;
                color: #103b67;
                line-height: 1.1;
                margin-bottom: 4px;
                letter-spacing: .2px;
            }

            .company-line {
                font-size: 11px;
                color: #47657f;
                line-height: 1.35;
            }

            .invoice-meta {
                width: 35%;
                text-align: left;
                font-size: 11px;
                color: #3c5b77;
                border-radius: 8px;
                background: #eef5fc;
                border: 1px solid #d5e7f5;
                padding: 10px 12px;
            }

            .meta-title {
                font-size: 10px;
                font-weight: 700;
                text-transform: uppercase;
                color: #5b7892;
                margin-bottom: 1px;
                letter-spacing: .4px;
            }

            .meta-value {
                margin-bottom: 5px;
                color: #0e3760;
                font-weight: 600;
            }

            .balance-title {
                font-size: 10px;
                font-weight: 800;
                text-transform: uppercase;
                margin-top: 6px;
                color: #0e4f83;
            }

            .balance-value {
                font-size: 22px;
                font-weight: 900;
                color: #0b3f72;
                line-height: 1.1;
            }

            .bill-to-label {
                font-size: 10px;
                font-weight: 800;
                text-transform: uppercase;
                color: #5f7d97;
                margin-bottom: 5px;
                position: relative;
                z-index: 2;
                letter-spacing: .4px;
            }

            .bill-to-name {
                font-size: 21px;
                font-weight: 700;
                color: #123f67;
                margin-bottom: 4px;
                position: relative;
                z-index: 2;
            }

            .bill-to-phone {
                font-size: 11px;
                color: #5b7590;
                margin-bottom: 14px;
                position: relative;
                z-index: 2;
            }

            .item-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 6px;
                position: relative;
                z-index: 2;
                border: 1px solid #d8e7f4;
                border-radius: 8px;
                overflow: hidden;
            }

            .item-table thead th {
                background: linear-gradient(90deg, #0e4b80 0%, #1276af 100%);
                color: #fff;
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: .4px;
                font-weight: 700;
                padding: 8px 9px;
                text-align: left;
            }

            .item-table thead th.r,
            .item-table tbody td.r {
                text-align: right;
            }

            .item-table tbody td {
                padding: 9px 8px;
                font-size: 11px;
                border-bottom: 1px solid #e3edf6;
                vertical-align: top;
                color: #264865;
                background: #ffffff;
            }

            .item-table tbody tr:nth-child(even) td {
                background: #f7fbff;
            }

            .desc-main {
                font-weight: 700;
                margin-bottom: 2px;
                color: #103c66;
            }

            .desc-sub {
                font-size: 10px;
                color: #62819b;
            }

            .payment-wrap {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 16px;
                margin-top: 14px;
                border-top: 1px solid #d3e4f2;
                padding-top: 12px;
                position: relative;
                z-index: 2;
            }

            .payment-left-title {
                font-size: 21px;
                font-weight: 700;
                color: #0e4473;
                margin-bottom: 8px;
            }

            .payment-block-title {
                font-size: 10px;
                font-weight: 800;
                text-transform: uppercase;
                color: #5b7891;
                margin-bottom: 4px;
                letter-spacing: .4px;
            }

            .payment-line {
                font-size: 11px;
                color: #315470;
                line-height: 1.4;
            }

            .payment-right {
                font-size: 11px;
                border-left: 1px solid #dbe8f3;
                padding-left: 12px;
                background: #f4f9fe;
                border-radius: 8px;
                border: 1px solid #d6e6f4;
                padding: 10px 12px;
            }

            .sum-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 6px;
                color: #274867;
            }

            .sum-row.total {
                margin-top: 8px;
                border-top: 1px solid #b7d4ea;
                padding-top: 8px;
                font-weight: 800;
                text-transform: uppercase;
                color: #0f416f;
            }

            .sum-row.total .v {
                font-size: 25px;
                text-transform: none;
                letter-spacing: .3px;
                color: #0b3f72;
            }

            .invoice-note {
                margin-top: 18px;
                max-width: 76%;
                border-top: 1px solid #c4dbec;
                padding-top: 10px;
                font-size: 11px;
                color: #355572;
                line-height: 1.4;
                position: relative;
                z-index: 2;
            }

            @media print {
                .sheet {
                    margin: 0;
                }
            }
        </style>
    </head>

    <body onload="window.print()">
        <div class="sheet">
            <?php if ($watermarkText): ?>
                <div class="watermark <?php echo $watermarkText === 'LUNAS' ? 'paid' : 'dp'; ?>"><?php echo $watermarkText; ?></div>
            <?php endif; ?>
            <div class="top-accent"></div>

            <div class="header">
                <div class="company">
                    <div class="logo">
                        <?php if ($logoUrl): ?>
                            <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Logo">
                        <?php else: ?>
                            <div class="logo-fallback">🌊</div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="company-name"><?php echo htmlspecialchars($companyName); ?></div>
                        <div class="company-line"><?php echo htmlspecialchars($companyTagline); ?></div>
                        <?php if ($companyAddress): ?><div class="company-line"><?php echo nl2br(htmlspecialchars($companyAddress)); ?></div><?php endif; ?>
                        <?php if ($companyPhone): ?><div class="company-line"><?php echo htmlspecialchars($companyPhone); ?></div><?php endif; ?>
                        <?php if ($companyEmail): ?><div class="company-line"><?php echo htmlspecialchars($companyEmail); ?></div><?php endif; ?>
                    </div>
                </div>

                <div class="invoice-meta">
                    <div class="meta-title">Invoice</div>
                    <div class="meta-value"><?php echo htmlspecialchars($invoice['invoice_no']); ?></div>
                    <div class="meta-title">Date</div>
                    <div class="meta-value"><?php echo date('d/m/Y', $issuedDate); ?></div>
                    <div class="meta-title">Due</div>
                    <div class="meta-value"><?php echo htmlspecialchars($termsLabel); ?></div>
                    <div class="meta-title">On Receipt</div>
                    <div class="balance-title">Balance Due</div>
                    <div class="balance-value">IDR <?php echo $fmtMoney((float)$invoice['remaining_amount'] > 0 ? (float)$invoice['remaining_amount'] : (float)$invoice['total_amount'], 'Rp'); ?></div>
                </div>
            </div>

            <div class="bill-to-label">Bill To</div>
            <div class="bill-to-name"><?php echo htmlspecialchars($invoice['customer_name']); ?></div>
            <?php if ($invoice['customer_phone']): ?><div class="bill-to-phone"><?php echo htmlspecialchars($invoice['customer_phone']); ?></div><?php endif; ?>

            <table class="item-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="r">Rate</th>
                        <th class="r">Qty</th>
                        <th class="r">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invItems as $item): ?>
                        <tr>
                            <td>
                                <div class="desc-main"><?php echo htmlspecialchars($item['description']); ?></div>
                                <div class="desc-sub"><?php echo htmlspecialchars(($invoice['trip_date'] ? date('d/m/Y', strtotime($invoice['trip_date'])) : '-') . ($invoice['trip_end_date'] ? ' - ' . date('d/m/Y', strtotime($invoice['trip_end_date'])) : '')); ?><?php echo $invoice['pax_count'] ? ' | ' . (int)$invoice['pax_count'] . ' pax' : ''; ?></div>
                            </td>
                            <td class="r"><?php echo $fmtMoney((float)$item['unit_price']); ?></td>
                            <td class="r"><?php echo $item['qty'] == (int)$item['qty'] ? (int)$item['qty'] : (float)$item['qty']; ?></td>
                            <td class="r"><?php echo $fmtMoney((float)$item['subtotal']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="payment-wrap">
                <div>
                    <div class="payment-left-title">Payment Info</div>
                    <div class="payment-block-title">Payment Instructions</div>
                    <?php if ($bankName || $bankAccount): ?>
                        <div class="payment-line"><?php echo htmlspecialchars($bankName ?: '-'); ?></div>
                        <div class="payment-line">Account Number: <?php echo htmlspecialchars($bankAccount ?: '-'); ?></div>
                        <div class="payment-line">Account Holder: <?php echo htmlspecialchars($bankHolder ?: '-'); ?></div>
                    <?php endif; ?>
                    <?php if ($bankName2 || $bankAccount2): ?>
                        <div class="payment-line" style="margin-top:8px;"><?php echo htmlspecialchars($bankName2 ?: '-'); ?></div>
                        <div class="payment-line">Account Number: <?php echo htmlspecialchars($bankAccount2 ?: '-'); ?></div>
                        <div class="payment-line">Account Holder: <?php echo htmlspecialchars($bankHolder2 ?: '-'); ?></div>
                    <?php endif; ?>
                </div>

                <div class="payment-right">
                    <div class="sum-row"><span>Total</span><span class="v"><?php echo $fmtMoney((float)$invoice['total_amount']); ?></span></div>
                    <?php if ($invoice['paid_amount'] > 0): ?>
                        <div class="sum-row"><span>Down Payment</span><span class="v">-<?php echo $fmtMoney((float)$invoice['paid_amount']); ?></span></div>
                    <?php endif; ?>
                    <div class="sum-row"><span>Tanggal DP</span><span class="v"><?php echo $dpPayment && !empty($dpPayment['payment_date']) ? date('d/m/Y', strtotime($dpPayment['payment_date'])) : '-'; ?></span></div>
                    <div class="sum-row"><span>Pembayaran Terakhir</span><span class="v"><?php echo $lastPayment && !empty($lastPayment['payment_date']) ? date('d/m/Y', strtotime($lastPayment['payment_date'])) : '-'; ?></span></div>
                    <div class="sum-row total"><span>Balance Due</span><span class="v">IDR <?php echo $fmtMoney((float)$invoice['remaining_amount'] > 0 ? (float)$invoice['remaining_amount'] : 0, 'Rp'); ?></span></div>
                </div>
            </div>

            <?php if ($invoiceNotes || $invoice['notes'] || $footer): ?>
                <div class="invoice-note">
                    <?php if ($invoiceNotes): ?><div><?php echo nl2br(htmlspecialchars($invoiceNotes)); ?></div><?php endif; ?>
                    <?php if ($invoice['notes']): ?><div style="margin-top:8px;"><strong>Catatan:</strong> <?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></div><?php endif; ?>
                    <?php if ($footer): ?><div style="margin-top:8px;"><?php echo nl2br(htmlspecialchars($footer)); ?></div><?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="bottom-accent"></div>
        </div>
    </body>

    </html>
<?php exit;
endif;

include 'layout-header.php';
?>

<?php if ($action === 'view' && $invoice): ?>
    <!-- ============ VIEW ============ -->
    <div style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;">
        <a href="invoices.php" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="arrow-left"></i> Kembali</a>
        <a href="invoices.php?action=print&id=<?php echo $invoice['id']; ?>" target="_blank" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="printer"></i> Cetak</a>
        <?php if (in_array($invoice['status'], ['issued', 'partial'])): ?>
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
                    <div>
                        <div style="font-size:10px;color:var(--ss-muted);">Tanggal Trip</div>
                        <div style="font-weight:600;"><?php echo $invoice['trip_date'] ? date('d M Y', strtotime($invoice['trip_date'])) : '-'; ?></div>
                    </div>
                    <div>
                        <div style="font-size:10px;color:var(--ss-muted);">Peserta</div>
                        <div style="font-weight:600;"><?php echo $invoice['pax_count']; ?> orang</div>
                    </div>
                    <div>
                        <div style="font-size:10px;color:var(--ss-muted);">Jatuh Tempo</div>
                        <div style="font-weight:600;"><?php echo $invoice['due_date'] ? date('d M Y', strtotime($invoice['due_date'])) : '-'; ?></div>
                    </div>
                </div>
                <div class="ss-table-wrap">
                    <table class="ss-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Keterangan</th>
                                <th>Qty</th>
                                <th>Sat.</th>
                                <th>Harga</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invItems as $i => $item): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
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
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Jumlah</th>
                                    <th>Metode</th>
                                    <th>Referensi</th>
                                </tr>
                            </thead>
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
                $rows[] = ['PPN ' . $invoice['tax_pct'] . '%', $invoice['tax_amount'], ''];
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
                        <thead>
                            <tr>
                                <th style="width:120px;">Kategori</th>
                                <th>Keterangan</th>
                                <th style="width:60px;">Qty</th>
                                <th style="width:60px;">Sat.</th>
                                <th style="width:130px;">Harga</th>
                                <th style="width:130px;">Subtotal</th>
                                <th style="width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody2">
                            <?php if (!empty($invItems)): ?>
                                <?php foreach ($invItems as $item): echo '<tr>' . invItemRow($item['item_type'], $item['description'], $item['qty'], $item['unit'], $item['unit_price']) . '</tr>';
                                endforeach; ?>
                            <?php else: echo '<tr>' . invItemRow() . '</tr><tr>' . invItemRow() . '</tr>';
                            endif; ?>
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
            <div>
                <div class="ss-card-title">Daftar Invoice</div>
                <div class="ss-card-sub"><?php echo count($invoiceList); ?> invoice</div>
            </div>
            <a href="invoices.php?action=add" class="ss-btn ss-btn-primary"><i data-feather="plus"></i> Buat Invoice</a>
        </div>
        <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
            <?php foreach (['' => 'Semua', 'issued' => 'Issued', 'partial' => 'Partial', 'paid' => 'Lunas', 'overdue' => 'Overdue'] as $st => $lbl): ?>
                <a href="invoices.php?status=<?php echo $st; ?>" class="ss-btn ss-btn-sm <?php echo $statusFilter === $st ? 'ss-btn-primary' : 'ss-btn-outline'; ?>"><?php echo $lbl; ?></a>
            <?php endforeach; ?>
        </div>
        <?php if (empty($invoiceList)): ?>
            <div class="ss-empty">
                <div class="ss-empty-icon">🧾</div>
                <h3>Belum ada invoice</h3>
            </div>
        <?php else: ?>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th>No. Invoice</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Terbayar</th>
                            <th>Sisa</th>
                            <th>Status</th>
                            <th>Jatuh Tempo</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
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
                                    <a href="invoices.php?action=print&id=<?php echo $inv['id']; ?>" target="_blank" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="printer"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
function invItemRow($type = '', $desc = '', $qty = 1, $unit = 'pax', $price = 0): string
{
    $typeOpts = ['accommodation', 'transport', 'meal', 'activity', 'guide', 'equipment', 'other'];
    $typeLabels = ['Penginapan', 'Transport', 'Makan', 'Aktivitas', 'Guide', 'Perlengkapan', 'Lainnya'];
    $sel = '';
    foreach ($typeOpts as $i => $t) {
        $s = $type === $t ? ' selected' : '';
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
    function unFmt(s) {
        return parseFloat(String(s).replace(/\./g, '').replace(',', '.')) || 0;
    }

    function fmt(n) {
        return 'Rp ' + Math.round(n).toLocaleString('id-ID');
    }

    function calcTotals2() {
        var sub = 0;
        document.querySelectorAll('#itemsBody2 tr').forEach(function(row) {
            var q = parseFloat(row.querySelector('.item-qty2')?.value) || 0;
            var p = unFmt(row.querySelector('.item-price2')?.value || '0');
            var s = q * p;
            var sf = row.querySelector('.item-sub2');
            if (sf) sf.value = s ? Math.round(s).toLocaleString('id-ID') : '';
            sub += s;
        });
        var disc = unFmt(document.getElementById('discountInput2')?.value || '0');
        var taxP = parseFloat(document.getElementById('taxInput2')?.value) || 0;
        var tax = (sub - disc) * taxP / 100;
        var tot = sub + tax - disc;
        document.getElementById('calcTotal2').textContent = fmt(tot);
    }

    function addItem2() {
        var tr = document.createElement('tr');
        tr.innerHTML = `<td><select name="item_type[]" class="ss-select" style="font-size:12px;padding:6px 8px;">
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

    function removeRow2(btn) {
        btn.closest('tr').remove();
        calcTotals2();
    }

    function setupRowListeners2(row) {
        row.querySelectorAll('.item-qty2,.item-price2').forEach(function(i) {
            i.addEventListener('input', calcTotals2);
        });
    }
    document.querySelectorAll('#itemsBody2 tr').forEach(setupRowListeners2);
    ['discountInput2', 'taxInput2'].forEach(function(id) {
        document.getElementById(id)?.addEventListener('input', calcTotals2);
    });
    calcTotals2();
</script>

<?php include 'layout-footer.php'; ?>