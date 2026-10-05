<?php

/**
 * Hotel Service Invoice — Print View
 * Reads from hotel_invoices + hotel_invoice_items tables
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db  = Database::getInstance();
$pdo = $db->getConnection();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) {
    die('Invalid invoice ID');
}

// Load invoice
$stmt = $pdo->prepare("SELECT * FROM hotel_invoices WHERE id = ?");
$stmt->execute([$id]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) {
    die('Invoice not found');
}

// Load items
$istmt = $pdo->prepare("SELECT * FROM hotel_invoice_items WHERE invoice_id = ? ORDER BY id ASC");
$istmt->execute([$id]);
$items = $istmt->fetchAll(PDO::FETCH_ASSOC);

// Payment method label (splits into "Cash Rp X + Transfer Rp Y" when split cash+kartu was used)
$pmNames = ['cash' => 'Cash', 'transfer' => 'Bank Transfer', 'card' => 'Card', 'kartu' => 'Card', 'debit' => 'Debit Card', 'credit' => 'Credit Card', 'qris' => 'QRIS', 'split' => 'Split Payment'];
$pmName = fn($m) => $pmNames[strtolower((string)$m)] ?? ucfirst((string)$m);
$paymentMethodLabel = $pmName($inv['payment_method']);
if ($inv['payment_method'] === 'split') {
    try {
        $pStmt = $pdo->prepare("SELECT amount, method FROM hotel_invoice_payments WHERE invoice_id = ? ORDER BY id ASC");
        $pStmt->execute([$id]);
        $paymentParts = [];
        foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $paymentParts[] = $pmName($p['method']) . ' Rp ' . number_format((float)$p['amount'], 0, ',', '.');
        }
        if ($paymentParts) $paymentMethodLabel = implode(' + ', $paymentParts);
    } catch (\Throwable $e) {
    }
}

// Company settings
$settings = [];
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'company_%' OR setting_key LIKE 'payment_info_%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
} catch (\Throwable $e) {
}

$companyName    = $settings['company_name']    ?? 'Narayana Hotel Karimunjawa';
$companyAddress = $settings['company_address'] ?? 'Karimunjawa, Jepara, Central Java, Indonesia';
$companyPhone   = $settings['company_phone']   ?? '';
$companyEmail   = $settings['company_email']   ?? '';
// Logo from settings
$companyLogo = null;
$logoKey = $settings['company_logo'] ?? '';
if ($logoKey) {
    $logoFile = basename($logoKey);
    $logoPhysical = rtrim(defined('BASE_PATH') ? BASE_PATH : __DIR__ . '/../..', '/') . '/uploads/logos/' . $logoFile;
    if (file_exists($logoPhysical)) {
        $companyLogo = BASE_URL . '/uploads/logos/' . $logoFile;
    }
}
$companyWebsite = $settings['company_website'] ?? 'www.narayanakarimunjawa.com';

// Payment info (bank account details)
$payBank    = $settings['payment_info_bank']    ?? '';
$payAccount = $settings['payment_info_account'] ?? '';
$payName    = $settings['payment_info_name']    ?? '';
$payNote    = $settings['payment_info_note']    ?? '';

// Processed status
$isProcessed = (bool)($inv['cashbook_synced'] ?? 0);

$serviceLabels = [
    'motor_rental'  => ['label' => 'Motor Rental',  'icon' => '🏍️'],
    'laundry'       => ['label' => 'Laundry',        'icon' => '👕'],
    'service'       => ['label' => 'Service',        'icon' => '🔧'],
    'airport_drop'  => ['label' => 'Airport Drop',   'icon' => '✈️'],
    'harbor_drop'   => ['label' => 'Harbor Drop',    'icon' => '⚓'],
    'narayana_trip' => ['label' => 'Narayana Trip',  'icon' => '🚤'],
    'lain_lain'     => ['label' => 'Miscellaneous',  'icon' => '📦'],
];
// Load dynamic service types from DB
try {
    $stStmt = $pdo->prepare("SELECT type_key, type_label, type_icon FROM hotel_service_types WHERE business_id=? AND is_active=1");
    $stStmt->execute([$inv['business_id']]);
    foreach ($stStmt->fetchAll(PDO::FETCH_ASSOC) as $st) {
        $serviceLabels[$st['type_key']] = ['label' => $st['type_label'], 'icon' => $st['type_icon']];
    }
} catch (\Throwable $e) {
}

// PPN / tax info
$taxRate             = (float)($inv['tax_rate']             ?? 0);
$taxAmount           = (float)($inv['tax_amount']           ?? 0);
$serviceChargeRate   = (float)($inv['service_charge_rate']  ?? 0);
$serviceChargeAmount = (float)($inv['service_charge_amount'] ?? 0);
$discountRate        = (float)($inv['discount_rate']        ?? 0);
$discountAmount      = (float)($inv['discount_amount']      ?? 0);
// Recalculate subtotal from total
$subtotal = (float)$inv['total'] - $taxAmount - $serviceChargeAmount + $discountAmount;

// Load created_by user info for signature
$createdByName = '';
try {
    if (!empty($inv['created_by'])) {
        $uStmt = $pdo->prepare("SELECT full_name FROM users WHERE id=?");
        $uStmt->execute([$inv['created_by']]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        if ($uRow) $createdByName = $uRow['full_name'];
    }
} catch (\Throwable $e) {
}

// Logo sama dengan invoice reservasi: logo invoice (Pengaturan PDF) -> logo perusahaan -> logo bisnis.
try {
    $lr = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $lr->execute(['invoice_logo_' . ACTIVE_BUSINESS_ID]);
    $invLogo = $lr->fetchColumn();
    if (!empty($invLogo)) $companyLogo = $invLogo;
} catch (\Throwable $e) {
}
if (empty($companyLogo) && function_exists('getBusinessLogo')) {
    $companyLogo = getBusinessLogo();
}
$coTagline = $settings['company_tagline'] ?? 'The Paradise of Java';
$coNpwp = $settings['company_npwp'] ?? '';
$hsStatus = in_array($inv['payment_status'], ['paid', 'partial', 'unpaid'], true) ? $inv['payment_status'] : 'unpaid';
$statusText = ['paid' => 'PAID', 'partial' => 'PARTIALLY PAID', 'unpaid' => 'UNPAID'][$hsStatus];
$rp = fn($v) => 'Rp ' . number_format((float)$v, 0, ',', '.');
$balance = (float)$inv['total'] - (float)$inv['paid_amount'];
if (!function_exists('invTerbilang')) {
    function invTerbilang($n)
    {
        $n = (int)floor(abs($n));
        $w = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
        if ($n < 12) return $w[$n];
        if ($n < 20) return invTerbilang($n - 10) . ' belas';
        if ($n < 100) return trim(invTerbilang(intdiv($n, 10)) . ' puluh ' . invTerbilang($n % 10));
        if ($n < 200) return trim('seratus ' . invTerbilang($n - 100));
        if ($n < 1000) return trim(invTerbilang(intdiv($n, 100)) . ' ratus ' . invTerbilang($n % 100));
        if ($n < 2000) return trim('seribu ' . invTerbilang($n - 1000));
        if ($n < 1000000) return trim(invTerbilang(intdiv($n, 1000)) . ' ribu ' . invTerbilang($n % 1000));
        if ($n < 1000000000) return trim(invTerbilang(intdiv($n, 1000000)) . ' juta ' . invTerbilang($n % 1000000));
        return trim(invTerbilang(intdiv($n, 1000000000)) . ' miliar ' . invTerbilang($n % 1000000000));
    }
}
$fmtRate = fn($r) => rtrim(rtrim(number_format($r, 2), '0'), '.');
if (!function_exists('invAmountWords')) {
    // Amount in English words (whole rupiah).
    function invAmountWords($n)
    {
        $n = (int)floor(abs($n));
        $ones = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        if ($n < 20) return $ones[$n];
        if ($n < 100) return $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
        if ($n < 1000) return $ones[intdiv($n, 100)] . ' hundred' . ($n % 100 ? ' ' . invAmountWords($n % 100) : '');
        foreach ([1000000000000 => 'trillion', 1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand'] as $div => $name) {
            if ($n >= $div) return invAmountWords(intdiv($n, $div)) . ' ' . $name . ($n % $div ? ' ' . invAmountWords($n % $div) : '');
        }
        return '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo htmlspecialchars($inv['invoice_number']); ?> - <?php echo htmlspecialchars($inv['guest_name']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0f2747;
            --navy-2: #1d3f6e;
            --gold: #b08d57;
            --gold-soft: #f4ecdf;
            --ink: #1f2937;
            --muted: #6b7280;
            --line: #e5e7eb;
            --paper: #ffffff;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif;
            background: #eceae6;
            color: var(--ink);
            font-size: 11.5px;
            line-height: 1.5;
            padding: 24px 12px 90px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .page {
            position: relative;
            max-width: 794px;
            min-height: 1123px;
            margin: 0 auto;
            background: var(--paper);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05), 0 24px 60px -24px rgba(0, 0, 0, 0.25);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Pita atas navy + garis emas */
        .ribbon { height: 8px; background: var(--navy); }
        .ribbon-gold { height: 2px; background: linear-gradient(90deg, var(--gold), #d9bf8f, var(--gold)); }

        .inner { padding: 30px 44px 0; flex: 1; position: relative; z-index: 2; }

        /* Stempel status */
        .stamp {
            position: absolute;
            top: 50%;
            left: 50%;
            right: auto;
            transform: translate(-50%, -50%) rotate(-28deg);
            border: 6px double currentColor;
            border-radius: 18px;
            padding: 10px 40px;
            font-weight: 800;
            font-size: 96px;
            letter-spacing: 0.16em;
            line-height: 1;
            white-space: nowrap;
            opacity: 0.09;
            z-index: 1;
            pointer-events: none;
        }
        .stamp.paid { color: #047857; }
        .stamp.partial { color: #b45309; }
        .stamp.unpaid { color: #b91c1c; }

        /* Kop */
        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
        .brand { display: flex; gap: 14px; align-items: center; }
        .brand img, .brand .mono {
            width: 92px; height: 92px; border-radius: 50%; object-fit: cover; flex-shrink: 0;
            box-shadow: 0 0 0 1px var(--line), 0 0 0 4px var(--gold-soft);
        }
        .brand .mono {
            display: flex; align-items: center; justify-content: center;
            background: var(--navy); color: #e8d6b3;
            font-family: 'Cormorant Garamond', serif; font-size: 30px; font-weight: 700;
        }
        .brand h1 {
            font-family: 'Cormorant Garamond', serif; font-weight: 700; font-size: 25px;
            color: var(--navy); line-height: 1.05; letter-spacing: 0.01em;
        }
        .brand .tag { font-style: italic; color: var(--gold); font-size: 11px; margin-top: 2px; }
        .brand .addr { color: var(--muted); font-size: 9.8px; margin-top: 5px; line-height: 1.45; }
        /* Letterhead text block centred, address lines balanced instead of a ragged wrap. */
        .brand .co { text-align: center; max-width: 340px; }
        .brand .addr { text-wrap: balance; }

        .doc { text-align: right; min-width: 220px; }
        .doc .title {
            font-family: 'Cormorant Garamond', serif; font-weight: 700; font-size: 34px;
            letter-spacing: 0.28em; color: var(--navy); line-height: 1;
            margin-right: -0.28em;
        }
        .doc .sub { font-size: 9px; letter-spacing: 0.2em; color: var(--gold); text-transform: uppercase; margin-top: 4px; }
        .doc .status {
            display: inline-block; margin-top: 10px; padding: 3px 10px; border-radius: 999px;
            font-size: 9px; font-weight: 700; letter-spacing: 0.08em;
        }
        .status.paid { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .status.partial { background: #fffbeb; color: #b45309; border: 1px solid #fcd34d; }
        .status.unpaid { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

        /* Baris meta dokumen */
        .meta {
            display: grid; grid-template-columns: repeat(4, 1fr);
            margin: 22px 0 18px; border: 1px solid var(--line); border-radius: 10px; overflow: hidden;
        }
        .meta div { padding: 9px 12px; }
        .meta div + div { border-left: 1px solid var(--line); }
        .meta small {
            display: block; font-size: 8.5px; font-weight: 700; letter-spacing: 0.12em;
            text-transform: uppercase; color: var(--muted);
        }
        .meta b { display: block; margin-top: 2px; font-size: 11px; color: var(--ink); font-weight: 600; }

        /* Kartu tamu & menginap */
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 18px; }
        .party { border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; }
        .party h3 {
            font-size: 8.5px; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase;
            color: var(--gold); margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid var(--gold-soft);
        }
        .party .name {
            font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 700;
            color: var(--navy); line-height: 1.3; margin-bottom: 6px;
        }
        .kv { display: grid; grid-template-columns: 82px 1fr; gap: 2px 8px; font-size: 10.5px; }
        .kv span:nth-child(odd) { color: var(--muted); }
        .kv span:nth-child(even) { color: var(--ink); font-weight: 500; }
        .stay {
            display: flex; align-items: center; gap: 10px; margin-bottom: 8px;
        }
        .stay .d { flex: 1; text-align: center; background: #f8fafc; border-radius: 8px; padding: 6px 4px; }
        .stay .d small { display: block; font-size: 8px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--muted); }
        .stay .d b { display: block; font-size: 11px; color: var(--navy); }
        .stay .n { font-size: 9.5px; font-weight: 700; color: var(--gold); white-space: nowrap; }

        /* Tabel rincian */
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        table.items thead th {
            background: var(--navy); color: #fff; font-size: 8.8px; font-weight: 600;
            letter-spacing: 0.12em; text-transform: uppercase; padding: 9px 10px; text-align: left;
        }
        table.items thead th:first-child { border-radius: 8px 0 0 8px; }
        table.items thead th:last-child { border-radius: 0 8px 8px 0; }
        table.items td { padding: 9px 10px; border-bottom: 1px solid var(--line); font-size: 10.8px; vertical-align: top; }
        table.items td .desc { font-weight: 600; color: var(--ink); }
        table.items td .sub { color: var(--muted); font-size: 9.5px; margin-top: 1px; }
        table.items .r { text-align: right; white-space: nowrap; }
        table.items .c { text-align: center; }
        table.items tbody tr:nth-child(even) td { background: #fbfaf7; }

        /* Ringkasan */
        .totals-wrap { display: grid; grid-template-columns: 1fr 300px; gap: 20px; margin-top: 14px; }
        .words {
            align-self: end; background: var(--gold-soft); border-left: 3px solid var(--gold);
            border-radius: 0 8px 8px 0; padding: 9px 12px; font-size: 10px; color: #5b4630;
        }
        .words small { display: block; font-size: 8.5px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--gold); font-weight: 700; margin-bottom: 2px; }
        .words i { text-transform: capitalize; }
        .totals { border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
        .totals .row { display: flex; justify-content: space-between; padding: 7px 14px; font-size: 10.8px; }
        .totals .row + .row { border-top: 1px solid var(--line); }
        .totals .row span:first-child { color: var(--muted); }
        .totals .row span:last-child { font-weight: 600; white-space: nowrap; }
        .totals .disc span:last-child { color: #b91c1c; }
        .totals .grand { background: var(--navy); color: #fff; padding: 10px 14px; }
        .totals .grand span { color: #fff !important; font-size: 12.5px; font-weight: 700 !important; letter-spacing: 0.04em; }
        .totals .paid span:last-child { color: #047857; }
        .totals .due { background: #fef2f2; }
        .totals .due span { color: #b91c1c !important; font-weight: 700 !important; }
        .totals .settled { background: #ecfdf5; }
        .totals .settled span { color: #047857 !important; font-weight: 700 !important; }

        h4.sec {
            font-size: 8.8px; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase;
            color: var(--navy); margin: 22px 0 8px; display: flex; align-items: center; gap: 8px;
        }
        h4.sec::after { content: ''; flex: 1; height: 1px; background: var(--line); }

        table.pay { width: 100%; border-collapse: collapse; }
        table.pay th { font-size: 8.5px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--muted); text-align: left; padding: 5px 8px; border-bottom: 1px solid var(--line); }
        table.pay td { font-size: 10.3px; padding: 6px 8px; border-bottom: 1px dashed var(--line); }
        table.pay .r { text-align: right; }

        .note { margin-top: 14px; font-size: 10px; background: #f8fafc; border-radius: 8px; padding: 8px 12px; color: var(--ink); }
        .note b { color: var(--navy); }

        /* Pembayaran + tanda tangan */
        .bottom { display: grid; grid-template-columns: 1fr 1fr 210px; gap: 16px; margin-top: 22px; align-items: stretch; }
        .box { border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; }
        .box h5 { font-size: 8.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--gold); margin-bottom: 6px; font-weight: 700; }
        .box .acc { font-size: 14px; font-weight: 700; color: var(--navy); letter-spacing: 0.04em; }
        .box p { font-size: 9.8px; color: var(--muted); }
        .terms li { font-size: 9.2px; color: var(--muted); margin-left: 14px; line-height: 1.5; }
        .sign { text-align: center; display: flex; flex-direction: column; justify-content: flex-end; }
        .sign .place { font-size: 9.5px; color: var(--muted); margin-bottom: 44px; }
        .sign .line { border-top: 1px solid var(--navy); padding-top: 5px; font-size: 10.8px; font-weight: 700; color: var(--navy); }
        .sign .role { font-size: 9px; color: var(--muted); }

        .legal { margin: 18px 0 0; font-size: 8.8px; color: var(--muted); text-align: center; }

        .foot {
            margin-top: 24px; background: var(--navy); color: #cbd5e1; text-align: center;
            padding: 16px 20px 18px; position: relative;
        }
        .foot::before { content: ''; position: absolute; left: 0; right: 0; top: 0; height: 2px; background: linear-gradient(90deg, var(--gold), #d9bf8f, var(--gold)); }
        .foot .ty { font-family: 'Cormorant Garamond', serif; font-size: 17px; color: #fff; font-style: italic; }
        .foot .ci { font-size: 9.2px; margin-top: 4px; letter-spacing: 0.02em; }

        .actions {
            position: fixed; bottom: 18px; left: 50%; transform: translateX(-50%); z-index: 50;
            display: flex; gap: 8px; padding: 8px; border-radius: 14px;
            background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px);
            box-shadow: 0 12px 30px -10px rgba(0, 0, 0, 0.35);
        }
        .actions button {
            border: none; cursor: pointer; font: inherit; font-weight: 600; font-size: 12px;
            padding: 9px 18px; border-radius: 10px;
        }
        .actions .pri { background: var(--navy); color: #fff; }
        .actions .sec2 { background: #eef2f7; color: var(--navy); }

        @page { size: A4; margin: 0; }
        @media print {
            /* Printers halftone light grey into dots, so small text looks blurry: print darker and slightly larger. */
            :root { --ink: #111827; --muted: #374151; --line: #cbd5e1; }
            html, body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            body { background: #fff; padding: 0; color: #111827; }
            .page { box-shadow: none; max-width: none; min-height: 297mm; }
            .actions { display: none; }
            .brand .addr, .box p, .terms li, .note, .words { font-size: 10.5px; }
            .kv, table.items td, table.pay td, .totals .row, .sign .line { font-size: 11.5px; }
            table.items td .sub, .sign .place, .sign .role, .legal, .foot .ci { font-size: 10px; }
            .meta small, .party h3, .box h5, .words small, table.items th, table.pay th, .stay .d small { font-size: 9.5px; }
            .meta b, .stay .d b { font-size: 12px; }
            .stamp { opacity: 0.07; }
            /* Tighter spacing in print so the larger text still fits on one A4 sheet. */
            .page { min-height: 296mm; }
            .inner { padding: 24px 40px 0; }
            .meta { margin: 16px 0 12px; }
            .parties { margin-bottom: 12px; }
            table.items td { padding: 7px 10px; }
            .totals-wrap { margin-top: 10px; }
            .totals .row { padding: 6px 14px; }
            .bottom { margin-top: 14px; }
            .sign .place { margin-bottom: 34px; }
            .legal { margin-top: 12px; }
        }

        @media (max-width: 640px) {
            .inner { padding: 22px 18px 0; }
            .head, .parties, .totals-wrap, .bottom { display: block; }
            .doc { text-align: left; margin-top: 14px; }
            .meta { grid-template-columns: 1fr 1fr; }
            .party, .box { margin-bottom: 10px; }
        }
    </style>
    <style>
        .svc { display: inline-block; padding: 1px 8px; border-radius: 999px; background: var(--gold-soft); color: #7a5b2e; font-size: 9.5px; font-weight: 600; margin-bottom: 2px; }
        .actions .proc { background: #059669; color: #fff; }
        .actions .done { align-self: center; font-size: 11px; font-weight: 600; color: #047857; padding: 0 6px; }
        .actions .back { background: #eef2f7; color: var(--navy); }
        .sign2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .bottom.hs { grid-template-columns: 1fr 1fr; }
    </style>
</head>

<body>
    <div class="page" id="invoiceContent">
        <div class="ribbon"></div>
        <div class="ribbon-gold"></div>
        <div class="stamp <?php echo $hsStatus; ?>"><?php echo $hsStatus === 'paid' ? 'PAID' : ($hsStatus === 'partial' ? 'PARTIAL' : 'UNPAID'); ?></div>

        <div class="inner">
            <div class="head">
                <div class="brand">
                    <?php if (!empty($companyLogo)): ?>
                        <img src="<?php echo htmlspecialchars($companyLogo); ?>" alt="<?php echo htmlspecialchars($companyName); ?>">
                    <?php else: ?>
                        <div class="mono"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($companyName, 0, 1))); ?></div>
                    <?php endif; ?>
                    <div class="co">
                        <h1><?php echo htmlspecialchars($companyName); ?></h1>
                        <?php if ($coTagline): ?><div class="tag">“<?php echo htmlspecialchars($coTagline); ?>”</div><?php endif; ?>
                        <div class="addr">
                            <?php echo htmlspecialchars($companyAddress); ?><br>
                            <?php echo htmlspecialchars(implode(' · ', array_filter([$companyPhone, $companyEmail]))); ?><?php echo $coNpwp ? '<br>NPWP: ' . htmlspecialchars($coNpwp) : ''; ?>
                        </div>
                    </div>
                </div>
                <div class="doc">
                    <div class="title">INVOICE</div>
                    <div class="sub">Hotel Services</div>
                    <span class="status <?php echo $hsStatus; ?>"><?php echo $statusText; ?></span>
                </div>
            </div>

            <div class="meta">
                <div><small>Invoice No.</small><b><?php echo htmlspecialchars($inv['invoice_number']); ?></b></div>
                <div><small>Date</small><b><?php echo date('d M Y', strtotime($inv['created_at'])); ?></b></div>
                <div><small>Payment Method</small><b><?php echo htmlspecialchars($paymentMethodLabel); ?></b></div>
                <div><small>Status</small><b><?php echo htmlspecialchars(ucfirst((string)$inv['status'])); ?></b></div>
            </div>

            <div class="parties">
                <div class="party">
                    <h3>Bill To</h3>
                    <div class="name"><?php echo htmlspecialchars($inv['guest_name']); ?></div>
                    <div class="kv">
                        <span>Phone</span><span><?php echo htmlspecialchars($inv['guest_phone'] ?: '-'); ?></span>
                        <span>Room</span><span><?php echo htmlspecialchars($inv['room_number'] ?: '-'); ?></span>
                    </div>
                </div>
                <div class="party">
                    <h3>Service Summary</h3>
                    <div class="kv">
                        <span>Items</span><span><?php echo count($items); ?> service<?php echo count($items) === 1 ? '' : 's'; ?></span>
                        <span>Total</span><span><?php echo $rp($inv['total']); ?></span>
                        <span>Amount paid</span><span><?php echo $rp($inv['paid_amount']); ?></span>
                        <span>Balance</span><span><?php echo $balance > 0 ? $rp($balance) : 'Paid in full'; ?></span>
                    </div>
                </div>
            </div>

            <table class="items">
                <thead>
                    <tr>
                        <th style="width:34px">#</th>
                        <th>Service</th>
                        <th class="c" style="width:60px">Qty</th>
                        <th class="r" style="width:120px">Unit Price</th>
                        <th class="r" style="width:130px">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 0;
                    foreach ($items as $item): $no++;
                        $svcInfo = $serviceLabels[$item['service_type']] ?? ['label' => $item['service_type'], 'icon' => '']; ?>
                        <tr>
                            <td><?php echo $no; ?></td>
                            <td>
                                <span class="svc"><?php echo htmlspecialchars($svcInfo['label']); ?></span>
                                <div class="desc"><?php echo htmlspecialchars($item['description'] ?: $svcInfo['label']); ?></div>
                            </td>
                            <td class="c"><?php echo rtrim(rtrim(number_format($item['quantity'], 2), '0'), '.'); ?></td>
                            <td class="r"><?php echo $rp($item['unit_price']); ?></td>
                            <td class="r"><?php echo $rp($item['total_price']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="totals-wrap">
                <div class="words">
                    <small>Amount in Words</small>
                    <i><?php echo htmlspecialchars(ucfirst(invAmountWords($inv['total']))); ?> rupiah</i>
                </div>
                <div class="totals">
                    <div class="row"><span>Subtotal</span><span><?php echo $rp($subtotal); ?></span></div>
                    <?php if ($serviceChargeRate > 0): ?>
                        <div class="row"><span>Service charge <?php echo $fmtRate($serviceChargeRate); ?>%</span><span><?php echo $rp($serviceChargeAmount); ?></span></div>
                    <?php endif; ?>
                    <?php if ($discountRate > 0 || $discountAmount > 0): ?>
                        <div class="row disc"><span>Discount<?php echo $discountRate > 0 ? ' ' . $fmtRate($discountRate) . '%' : ''; ?></span><span>- <?php echo $rp($discountAmount); ?></span></div>
                    <?php endif; ?>
                    <?php if ($taxRate > 0): ?>
                        <div class="row"><span>VAT <?php echo $fmtRate($taxRate); ?>%</span><span><?php echo $rp($taxAmount); ?></span></div>
                    <?php endif; ?>
                    <div class="row grand"><span>TOTAL</span><span><?php echo $rp($inv['total']); ?></span></div>
                    <div class="row paid"><span><?php echo ($balance > 0 && (float)$inv['paid_amount'] > 0) ? 'Down payment' : 'Amount paid'; ?></span><span><?php echo $rp($inv['paid_amount']); ?></span></div>
                    <?php if ($balance > 0): ?>
                        <div class="row due"><span>Balance Due</span><span><?php echo $rp($balance); ?></span></div>
                    <?php else: ?>
                        <div class="row settled"><span>Status</span><span>PAID IN FULL</span></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($inv['notes'])): ?>
                <div class="note"><b>Notes:</b> <?php echo nl2br(htmlspecialchars($inv['notes'])); ?></div>
            <?php endif; ?>

            <div class="bottom hs">
                <div class="box">
                    <h5>Bank Transfer</h5>
                    <?php if ($payAccount || $payBank): ?>
                        <div class="acc"><?php echo htmlspecialchars($payAccount ?: '-'); ?></div>
                        <p><?php echo htmlspecialchars(trim($payBank . ($payName ? ' · Account name: ' . $payName : ''))); ?></p>
                        <?php if ($payNote): ?><p style="margin-top:4px"><?php echo htmlspecialchars($payNote); ?></p><?php endif; ?>
                    <?php else: ?>
                        <p>Payment at the Front Desk.</p>
                    <?php endif; ?>
                    <p style="margin-top:4px">Please include the invoice number in the transfer reference.</p>
                </div>
                <div class="sign2">
                    <div class="sign">
                        <div class="place">Issued by</div>
                        <div class="line"><?php echo htmlspecialchars($createdByName ?: '..........................'); ?></div>
                        <div class="role">Staff / Accounting</div>
                    </div>
                    <div class="sign">
                        <div class="place">Received by</div>
                        <div class="line"><?php echo htmlspecialchars($inv['guest_name']); ?></div>
                        <div class="role">Guest</div>
                    </div>
                </div>
            </div>

            <div class="legal">
                This document was issued electronically by <?php echo htmlspecialchars($companyName); ?> · <?php echo htmlspecialchars($inv['invoice_number']); ?> · printed <?php echo date('d M Y H:i'); ?>
            </div>
        </div>

        <div class="foot">
            <div class="ty">Thank you for choosing our services</div>
            <div class="ci"><?php echo htmlspecialchars($companyName); ?> · <?php echo htmlspecialchars($companyAddress); ?></div>
            <div class="ci"><?php echo htmlspecialchars(implode(' · ', array_filter([$companyPhone, $companyEmail, $companyWebsite]))); ?></div>
        </div>
    </div>

    <div class="actions">
        <button class="back" onclick="window.history.length > 1 ? history.back() : window.location.href='hotel-services.php'">← Back</button>
        <button class="pri" onclick="window.print()">Print / PDF</button>
        <?php if (!$isProcessed): ?>
            <button class="proc" id="btnProcess" onclick="processInvoice(<?php echo (int)$inv['id']; ?>)">Post to Cash Book</button>
        <?php else: ?>
            <span class="done">✓ Posted to Cash Book</span>
        <?php endif; ?>
    </div>

    <script>
        function processInvoice(id) {
            const btn = document.getElementById('btnProcess');
            if (!btn) return;
            if (!confirm('Process this invoice? The payment will be recorded in the Cash Book.')) return;
            btn.disabled = true;
            btn.textContent = 'Processing...';
            const fd = new FormData();
            fd.append('action', 'process_invoice');
            fd.append('id', id);
            fetch('<?php echo BASE_URL; ?>/modules/frontdesk/hotel-services.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.success && (d.cashbook || d.already)) {
                        btn.textContent = '✓ Done';
                        setTimeout(() => location.reload(), 800);
                    } else {
                        alert(d.success ? 'Failed to sync to the Cash Book. Please try again or contact the admin.' : 'Error: ' + (d.message || 'Unknown error'));
                        btn.disabled = false;
                        btn.textContent = 'Post to Cash Book';
                    }
                })
                .catch(() => {
                    alert('Network error');
                    btn.disabled = false;
                    btn.textContent = 'Post to Cash Book';
                });
        }
    </script>
</body>

</html>
