<?php

/**
 * INVOICE / BILL for Booking
 * Print-friendly invoice page
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

// Get current logged-in user
$currentUser = $auth->getCurrentUser();

if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$bookingId = (int)($_GET['booking_id'] ?? 0);

if ($bookingId === 0) {
    die('Invalid Booking ID');
}

// Get primary booking details
$booking = $db->fetchOne("
    SELECT 
        b.id, b.booking_code, b.check_in_date, b.check_out_date,
        b.room_price, b.total_price, b.final_price, b.discount,
        b.status, b.payment_status, b.booking_source, b.total_nights,
        b.paid_amount, b.special_request, b.adults, b.children,
        b.created_at, b.group_id,
        g.guest_name, g.phone, g.email, g.id_card_number,
        r.room_number,
        rt.type_name as room_type
    FROM bookings b
    LEFT JOIN guests g ON b.guest_id = g.id
    LEFT JOIN rooms r ON b.room_id = r.id
    LEFT JOIN room_types rt ON r.room_type_id = rt.id
    WHERE b.id = ?
", [$bookingId]);

if (!$booking) {
    die('Booking not found');
}

// Find related bookings - prefer group_id, fallback to fuzzy matching for old bookings
$relatedBookings = [];
if (!empty($booking['group_id'])) {
    // Use group_id for reliable matching
    $relatedBookings = $db->fetchAll("
        SELECT 
            b.id, b.booking_code, b.check_in_date, b.check_out_date,
            b.room_price, b.total_price, b.final_price, b.discount,
            b.status, b.payment_status, b.booking_source, b.total_nights,
            b.paid_amount, b.special_request, b.adults, b.children,
            b.created_at, b.group_id,
            g.guest_name, g.phone, g.email, g.id_card_number,
            r.room_number,
            rt.type_name as room_type
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE b.group_id = ?
        AND b.status != 'cancelled'
        ORDER BY r.room_number ASC
    ", [$booking['group_id']]);
} else {
    // Fallback: fuzzy matching for old bookings without group_id
    $relatedBookings = $db->fetchAll("
        SELECT 
            b.id, b.booking_code, b.check_in_date, b.check_out_date,
            b.room_price, b.total_price, b.final_price, b.discount,
            b.status, b.payment_status, b.booking_source, b.total_nights,
            b.paid_amount, b.special_request, b.adults, b.children,
            b.created_at,
            g.guest_name, g.phone, g.email, g.id_card_number,
            r.room_number,
            rt.type_name as room_type
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE g.guest_name = ?
        AND b.check_in_date = ?
        AND b.check_out_date = ?
        AND b.booking_source = ?
        AND b.status != 'cancelled'
        AND ABS(TIMESTAMPDIFF(MINUTE, b.created_at, ?)) <= 5
        ORDER BY r.room_number ASC
    ", [
        $booking['guest_name'],
        $booking['check_in_date'],
        $booking['check_out_date'],
        $booking['booking_source'],
        $booking['created_at']
    ]);
}

// If no related found or only 1, use single booking
$allBookings = (!empty($relatedBookings) && count($relatedBookings) > 1) ? $relatedBookings : [$booking];
$isMultiRoom = count($allBookings) > 1;

// Collect all booking IDs for payment query
$allBookingIds = array_column($allBookings, 'id');
$placeholders = implode(',', array_fill(0, count($allBookingIds), '?'));

// Get payment history for all related bookings
$payments = $db->fetchAll("
    SELECT 
        bp.booking_id, bp.amount, bp.payment_method, bp.payment_date, bp.notes,
        bk.booking_code, r.room_number
    FROM booking_payments bp
    LEFT JOIN bookings bk ON bp.booking_id = bk.id
    LEFT JOIN rooms r ON bk.room_id = r.id
    WHERE bp.booking_id IN ($placeholders)
    ORDER BY bp.payment_date ASC
", $allBookingIds);

// Get extras for all related bookings
$allExtras = $db->fetchAll("
    SELECT be.*, r.room_number
    FROM booking_extras be
    LEFT JOIN bookings bk ON be.booking_id = bk.id
    LEFT JOIN rooms r ON bk.room_id = r.id
    WHERE be.booking_id IN ($placeholders)
    ORDER BY r.room_number ASC, be.created_at ASC
", $allBookingIds);

$combinedExtrasTotal = 0;
foreach ($allExtras as $ex) {
    $combinedExtrasTotal += $ex['total_price'];
}

// Calculate combined totals
$totalPaid = 0;
foreach ($payments as $payment) {
    $totalPaid += $payment['amount'];
}

// Fallback: sum paid_amount from all bookings if no payment records
if ($totalPaid == 0) {
    foreach ($allBookings as $bk) {
        $totalPaid += $bk['paid_amount'];
    }
}

$combinedFinalPrice = 0;
$combinedTotalPrice = 0;
$combinedDiscount = 0;
foreach ($allBookings as $bk) {
    $combinedFinalPrice += $bk['final_price'];
    $combinedTotalPrice += $bk['total_price'];
    $combinedDiscount += $bk['discount'];
}

$remaining = $combinedFinalPrice - $totalPaid;
$isPdf = isset($_GET['pdf']);

// Determine payment status
$overallStatus = 'unpaid';
$overallLabel = 'UNPAID';
if ($totalPaid >= $combinedFinalPrice && $combinedFinalPrice > 0) {
    $overallStatus = 'paid';
    $overallLabel = 'PAID';
} elseif ($totalPaid > 0) {
    $overallStatus = 'partial';
    $overallLabel = 'DOWN PAYMENT';
}

// Get business info
$businessId = $_SESSION['business_id'] ?? 1;
$business = $db->fetchOne("SELECT * FROM businesses WHERE id = ?", [$businessId]);

// Get invoice logo from PDF settings (Settings > Pengaturan Laporan PDF)
$invoiceLogoRow = $db->fetchOne(
    "SELECT setting_value FROM settings WHERE setting_key = ?",
    ['invoice_logo_' . ACTIVE_BUSINESS_ID]
);
$logoUrl = $invoiceLogoRow['setting_value'] ?? null;
// Fallback to company logo if no invoice logo
if (empty($logoUrl)) {
    $logoUrl = getBusinessLogo();
}

// Get company settings from master DB
$masterDb = Database::getInstance();
$settingsQuery = "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'company_%'";
$settingsResult = $masterDb->fetchAll($settingsQuery);
$companySettings = [];
foreach ($settingsResult as $setting) {
    if (strpos($setting['setting_key'], 'company_logo_') === 0) continue; // skip logo keys
    $key = str_replace('company_', '', $setting['setting_key']);
    $companySettings[$key] = $setting['setting_value'];
}

// Fallback for company name
if (empty($companySettings['name'])) {
    $companySettings['name'] = $business['business_name'] ?? 'Narayana Hotel';
}

// Get Bank Transfer Info from settings
$bankAccNumberRow = $db->fetchOne(
    "SELECT setting_value FROM settings WHERE setting_key = ?",
    ['invoice_bank_account_number_' . ACTIVE_BUSINESS_ID]
);
$bankAccountNumber = $bankAccNumberRow['setting_value'] ?? '1926663992';

$bankAccNameRow = $db->fetchOne(
    "SELECT setting_value FROM settings WHERE setting_key = ?",
    ['invoice_bank_account_name_' . ACTIVE_BUSINESS_ID]
);
$bankAccountName = $bankAccNameRow['setting_value'] ?? 'BNI Jepara';

// Get Swift Code from settings
$swiftRow = $db->fetchOne(
    "SELECT setting_value FROM settings WHERE setting_key = ?",
    ['invoice_swift_code_' . ACTIVE_BUSINESS_ID]
);
$swiftCode = $swiftRow['setting_value'] ?? '';

// Get accounting info for signature
$accountingNameRow = $db->fetchOne(
    "SELECT setting_value FROM settings WHERE setting_key = ?",
    ['invoice_accounting_name_' . ACTIVE_BUSINESS_ID]
);
$accountingName = $accountingNameRow['setting_value'] ?? '';

// If no saved name, use current logged-in user's name
if (empty($accountingName)) {
    $accountingName = $currentUser['full_name'] ?? 'Accounting Staff';
}

$accountingTitleRow = $db->fetchOne(
    "SELECT setting_value FROM settings WHERE setting_key = ?",
    ['invoice_accounting_title_' . ACTIVE_BUSINESS_ID]
);
$accountingTitle = $accountingTitleRow['setting_value'] ?? 'Accounting Staff';

// Data tampilan invoice (perusahaan, nomor, terbilang). Data booking/pembayaran dihitung di atas.
$coName = $companySettings['name'] ?? 'Narayana Hotel';
$coTagline = $companySettings['tagline'] ?? 'The Paradise of Java';
$coAddress = $companySettings['address'] ?? 'Jl. Kasimo Jatikerep, Karimunjawa, Jepara, Jawa Tengah 59455';
$coPhone = $companySettings['phone'] ?? '081222228590';
$coEmail = $companySettings['email'] ?? 'narayanahotelkarimunjawa@gmail.com';
$coWebsite = $companySettings['website'] ?? 'www.narayanakarimunjawa.com';
$coNpwp = $companySettings['npwp'] ?? '';

$invoiceNo = 'INV/' . date('Y/m', strtotime($booking['created_at'])) . '/' . $allBookings[0]['booking_code'];
$issueDate = date('d M Y', strtotime($booking['created_at']));
$dueDate = date('d M Y', strtotime($booking['check_out_date']));

if (!function_exists('invTerbilang')) {
    // Angka -> kata (Rupiah), dipakai di baris "Terbilang".
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
$rp = fn($v) => 'Rp ' . number_format((float)$v, 0, ',', '.');
$statusText = ['paid' => 'PAID', 'partial' => 'PARTIALLY PAID', 'unpaid' => 'UNPAID'][$overallStatus];
$sourceLabel = ucwords(str_replace('_', ' ', $booking['booking_source'] ?? 'Walk-in'));
$guestsLabel = (int)($booking['adults'] ?? 1) . ' Adult' . ((int)($booking['adults'] ?? 1) === 1 ? '' : 's') . ((int)($booking['children'] ?? 0) > 0 ? ', ' . (int)$booking['children'] . ' Child' . ((int)$booking['children'] === 1 ? '' : 'ren') : '');
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
    <title>Invoice <?php echo htmlspecialchars($allBookings[0]['booking_code']); ?> - <?php echo htmlspecialchars($booking['guest_name']); ?></title>
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
            align-self: start; background: var(--gold-soft); border-left: 3px solid var(--gold);
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
            body { background: #fff; padding: 0; }
            .page { box-shadow: none; max-width: none; min-height: 297mm; }
            .actions { display: none; }
        }

        @media (max-width: 640px) {
            .inner { padding: 22px 18px 0; }
            .head, .parties, .totals-wrap, .bottom { display: block; }
            .doc { text-align: left; margin-top: 14px; }
            .meta { grid-template-columns: 1fr 1fr; }
            .party, .box { margin-bottom: 10px; }
        }
    </style>
</head>

<body>
    <div class="page" id="invoiceContent">
        <div class="ribbon"></div>
        <div class="ribbon-gold"></div>
        <div class="stamp <?php echo $overallStatus; ?>"><?php echo $overallStatus === 'paid' ? 'PAID' : ($overallStatus === 'partial' ? 'PARTIAL' : 'UNPAID'); ?></div>

        <div class="inner">
            <!-- Kop -->
            <div class="head">
                <div class="brand">
                    <?php if ($logoUrl): ?>
                        <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="<?php echo htmlspecialchars($coName); ?>">
                    <?php else: ?>
                        <div class="mono"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($coName, 0, 1))); ?></div>
                    <?php endif; ?>
                    <div>
                        <h1><?php echo htmlspecialchars($coName); ?></h1>
                        <?php if ($coTagline): ?><div class="tag">“<?php echo htmlspecialchars($coTagline); ?>”</div><?php endif; ?>
                        <div class="addr">
                            <?php echo htmlspecialchars($coAddress); ?><br>
                            <?php echo htmlspecialchars($coPhone); ?> · <?php echo htmlspecialchars($coEmail); ?><?php echo $coNpwp ? '<br>NPWP: ' . htmlspecialchars($coNpwp) : ''; ?>
                        </div>
                    </div>
                </div>
                <div class="doc">
                    <div class="title">INVOICE</div>
                    <div class="sub">Guest Folio</div>
                    <span class="status <?php echo $overallStatus; ?>"><?php echo $statusText; ?></span>
                </div>
            </div>

            <!-- Meta dokumen -->
            <div class="meta">
                <div><small>Invoice No.</small><b><?php echo htmlspecialchars($invoiceNo); ?></b></div>
                <div><small>Issue Date</small><b><?php echo $issueDate; ?></b></div>
                <div><small>Due Date</small><b><?php echo $dueDate; ?></b></div>
                <div><small>Booking Code</small><b><?php echo htmlspecialchars($allBookings[0]['booking_code']); ?><?php echo $isMultiRoom ? ' +' . (count($allBookings) - 1) : ''; ?></b></div>
            </div>

            <!-- Tamu & menginap -->
            <div class="parties">
                <div class="party">
                    <h3>Bill To</h3>
                    <div class="name"><?php echo htmlspecialchars($booking['guest_name']); ?></div>
                    <div class="kv">
                        <span>Phone</span><span><?php echo htmlspecialchars($booking['phone'] ?: '-'); ?></span>
                        <span>Email</span><span><?php echo htmlspecialchars($booking['email'] ?: '-'); ?></span>
                        <?php if (!empty($booking['id_card_number']) && strpos($booking['id_card_number'], 'TEMP-') !== 0): ?>
                            <span>ID Number</span><span><?php echo htmlspecialchars($booking['id_card_number']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="party">
                    <h3>Stay Details</h3>
                    <div class="stay">
                        <div class="d"><small>Check-in</small><b><?php echo date('D, d M Y', strtotime($booking['check_in_date'])); ?></b></div>
                        <div class="n"><?php echo (int)$booking['total_nights']; ?> night<?php echo (int)$booking['total_nights'] === 1 ? '' : 's'; ?> →</div>
                        <div class="d"><small>Check-out</small><b><?php echo date('D, d M Y', strtotime($booking['check_out_date'])); ?></b></div>
                    </div>
                    <div class="kv">
                        <span>Guests</span><span><?php echo $guestsLabel; ?></span>
                        <span>Rooms</span><span><?php echo count($allBookings); ?> room<?php echo count($allBookings) === 1 ? '' : 's'; ?></span>
                        <span>Source</span><span><?php echo htmlspecialchars($sourceLabel); ?></span>
                    </div>
                </div>
            </div>

            <!-- Rincian -->
            <table class="items">
                <thead>
                    <tr>
                        <th style="width:34px">#</th>
                        <th>Description</th>
                        <th class="c" style="width:70px">Qty</th>
                        <th class="r" style="width:120px">Unit Price</th>
                        <th class="r" style="width:130px">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 0;
                    foreach ($allBookings as $bk): $no++; ?>
                        <tr>
                            <td><?php echo $no; ?></td>
                            <td>
                                <div class="desc">Room <?php echo htmlspecialchars($bk['room_number']); ?> — <?php echo htmlspecialchars($bk['room_type'] ?? 'Room'); ?></div>
                                <div class="sub"><?php echo date('d M', strtotime($bk['check_in_date'])); ?> – <?php echo date('d M Y', strtotime($bk['check_out_date'])); ?> · Room charge</div>
                            </td>
                            <td class="c"><?php echo (int)$bk['total_nights']; ?> night<?php echo (int)$bk['total_nights'] === 1 ? '' : 's'; ?></td>
                            <td class="r"><?php echo $rp($bk['room_price']); ?></td>
                            <td class="r"><?php echo $rp($bk['total_price']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($allExtras as $ex): $no++; ?>
                        <tr>
                            <td><?php echo $no; ?></td>
                            <td>
                                <div class="desc"><?php echo htmlspecialchars($ex['item_name']); ?></div>
                                <div class="sub">Additional service<?php echo $isMultiRoom && !empty($ex['room_number']) ? ' · Room ' . htmlspecialchars($ex['room_number']) : ''; ?></div>
                            </td>
                            <td class="c"><?php echo (int)$ex['quantity']; ?></td>
                            <td class="r"><?php echo $rp($ex['unit_price']); ?></td>
                            <td class="r"><?php echo $rp($ex['total_price']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Ringkasan -->
            <div class="totals-wrap">
                <div class="words">
                    <small>Amount in Words</small>
                    <i><?php echo htmlspecialchars(ucfirst(invAmountWords($combinedFinalPrice))); ?> rupiah</i>
                </div>
                <div class="totals">
                    <div class="row"><span>Room subtotal</span><span><?php echo $rp($combinedTotalPrice); ?></span></div>
                    <?php if ($combinedExtrasTotal > 0): ?>
                        <div class="row"><span>Additional services</span><span><?php echo $rp($combinedExtrasTotal); ?></span></div>
                    <?php endif; ?>
                    <?php if ($combinedDiscount > 0): ?>
                        <div class="row disc"><span>Discount</span><span>- <?php echo $rp($combinedDiscount); ?></span></div>
                    <?php endif; ?>
                    <div class="row grand"><span>TOTAL</span><span><?php echo $rp($combinedFinalPrice); ?></span></div>
                    <div class="row paid"><span>Amount paid</span><span><?php echo $rp($totalPaid); ?></span></div>
                    <?php if ($remaining > 0): ?>
                        <div class="row due"><span>Balance Due</span><span><?php echo $rp($remaining); ?></span></div>
                    <?php else: ?>
                        <div class="row settled"><span>Status</span><span>PAID IN FULL</span></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Riwayat pembayaran -->
            <?php if (!empty($payments)): ?>
                <h4 class="sec">Payment History</h4>
                <table class="pay">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <?php if ($isMultiRoom): ?><th>Room</th><?php endif; ?>
                            <th>Method</th>
                            <th>Notes</th>
                            <th class="r">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td><?php echo date('d M Y, H:i', strtotime($p['payment_date'])); ?></td>
                                <?php if ($isMultiRoom): ?><td><?php echo htmlspecialchars($p['room_number'] ?? '-'); ?></td><?php endif; ?>
                                <td><?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', (string)$p['payment_method']))); ?></td>
                                <td><?php echo htmlspecialchars($p['notes'] ?: '-'); ?></td>
                                <td class="r"><b><?php echo $rp($p['amount']); ?></b></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!empty($booking['special_request'])): ?>
                <div class="note"><b>Guest notes:</b> <?php echo nl2br(htmlspecialchars($booking['special_request'])); ?></div>
            <?php endif; ?>

            <!-- Pembayaran, ketentuan, tanda tangan -->
            <div class="bottom">
                <div class="box">
                    <h5>Bank Transfer</h5>
                    <div class="acc"><?php echo htmlspecialchars($bankAccountNumber); ?></div>
                    <p>Account name: <?php echo htmlspecialchars($bankAccountName); ?></p>
                    <?php if (!empty($swiftCode)): ?><p>SWIFT: <?php echo htmlspecialchars($swiftCode); ?></p><?php endif; ?>
                    <p style="margin-top:4px">Please include the booking code in the transfer reference.</p>
                </div>
                <div class="box">
                    <h5>Terms &amp; Conditions</h5>
                    <ul class="terms">
                        <li>Full payment is due no later than check-out.</li>
                        <li>Check-in 14:00 · Check-out 12:00.</li>
                        <li>Payments received are non-refundable, except as provided by the cancellation policy.</li>
                        <li>This system-generated invoice is valid without a stamp.</li>
                    </ul>
                </div>
                <div class="sign">
                    <div class="place">Karimunjawa, <?php echo date('d M Y'); ?></div>
                    <div class="line"><?php echo htmlspecialchars($accountingName ?: '..........................'); ?></div>
                    <div class="role"><?php echo htmlspecialchars($accountingTitle); ?></div>
                </div>
            </div>

            <div class="legal">
                This document was issued electronically by <?php echo htmlspecialchars($coName); ?> · <?php echo htmlspecialchars($invoiceNo); ?> · printed <?php echo date('d M Y H:i'); ?>
            </div>
        </div>

        <div class="foot">
            <div class="ty">Thank you for staying with us</div>
            <div class="ci"><?php echo htmlspecialchars($coName); ?> · <?php echo htmlspecialchars($coAddress); ?></div>
            <div class="ci"><?php echo htmlspecialchars($coPhone); ?> · <?php echo htmlspecialchars($coEmail); ?> · <?php echo htmlspecialchars($coWebsite); ?></div>
        </div>
    </div>

    <div class="actions">
        <button class="pri" onclick="savePDF()">Save PDF</button>
        <button class="sec2" onclick="window.print()">Print</button>
    </div>

    <script>
        function savePDF() {
            document.title = 'Invoice_<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $allBookings[0]['booking_code']); ?>_<?php echo date('Ymd'); ?>';
            window.print();
        }
        <?php if ($isPdf): ?>
            window.onload = function() {
                setTimeout(function() { window.print(); }, 500);
            };
        <?php endif; ?>
    </script>
</body>

</html>
