<?php

/**
 * Cetak penawaran kamar (A4) — tata letak sama dengan Invoice Room (kop, kartu tamu & menginap, tabel navy, ringkasan, footer navy).
 * ?id=<quotation id>  [&lang=id|en untuk mengganti bahasa dokumen]  [&autoprint=1]
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/Quotations.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/modules/frontdesk/dashboard.php');
    exit;
}
$db = Database::getInstance();
$q = Quotations::get($db, (int)($_GET['id'] ?? 0));
if (!$q) {
    http_response_code(404);
    exit('Penawaran tidak ditemukan');
}
$lang = in_array($_GET['lang'] ?? '', ['id', 'en'], true) ? $_GET['lang'] : ($q['lang'] === 'en' ? 'en' : 'id');
$autoPrint = !empty($_GET['autoprint']);

if (!function_exists('qtWordsEn')) {
    function qtWordsEn(int $n): string
    {
        $ones = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        if ($n < 20) return $ones[$n];
        if ($n < 100) return $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
        if ($n < 1000) return $ones[intdiv($n, 100)] . ' hundred' . ($n % 100 ? ' ' . qtWordsEn($n % 100) : '');
        foreach ([1000000000000 => 'trillion', 1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand'] as $div => $name) {
            if ($n >= $div) return qtWordsEn(intdiv($n, $div)) . ' ' . $name . ($n % $div ? ' ' . qtWordsEn($n % $div) : '');
        }
        return '';
    }

    function qtWordsId(int $n): string
    {
        $s = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
        if ($n === 0) return 'nol';
        if ($n < 12) return $s[$n];
        if ($n < 20) return qtWordsId($n - 10) . ' belas';
        if ($n < 100) return qtWordsId(intdiv($n, 10)) . ' puluh' . ($n % 10 ? ' ' . qtWordsId($n % 10) : '');
        if ($n < 200) return 'seratus' . ($n - 100 ? ' ' . qtWordsId($n - 100) : '');
        if ($n < 1000) return qtWordsId(intdiv($n, 100)) . ' ratus' . ($n % 100 ? ' ' . qtWordsId($n % 100) : '');
        if ($n < 2000) return 'seribu' . ($n - 1000 ? ' ' . qtWordsId($n - 1000) : '');
        foreach ([1000000000000 => 'triliun', 1000000000 => 'miliar', 1000000 => 'juta', 1000 => 'ribu'] as $div => $name) {
            if ($n >= $div) return qtWordsId(intdiv($n, $div)) . ' ' . $name . ($n % $div ? ' ' . qtWordsId($n % $div) : '');
        }
        return '';
    }
}

$L = $lang === 'en' ? [
    'title' => 'QUOTATION', 'sub' => 'Room Offer', 'to' => 'Prepared For', 'stay' => 'Stay Details', 'phone' => 'Phone', 'email' => 'Email', 'ci' => 'Check-in', 'co' => 'Check-out',
    'night' => 'night', 'nights' => 'nights', 'rooms' => 'Rooms', 'room' => 'Room', 'rtype' => 'Room Type', 'rate' => 'Rate', 'rateplan' => 'special rate', 'valid' => 'Valid Until', 'qno' => 'Quotation No.', 'date' => 'Issued',
    'desc' => 'Description', 'type' => 'Type', 'disc' => 'Discount', 'qty' => 'Qty', 'unit' => 'Unit Price', 'amount' => 'Amount', 'extra' => 'Service',
    'words' => 'Amount in Words', 'rupiah' => 'rupiah', 'subtotal' => 'Room subtotal', 'rdisc' => 'Room discount', 'rtotal' => 'Room total', 'extras' => 'Additional services', 'grand' => 'TOTAL',
    'notes' => 'Notes', 'bank' => 'Bank Transfer', 'accname' => 'Account name', 'ref' => 'Please include the quotation number in the transfer reference.',
    'terms' => 'Terms & Conditions', 'terms_txt' => 'This quotation does not hold or block any room; availability is guaranteed only after confirmation · Rates are in Indonesian Rupiah (IDR), room rates per room per night · Valid until the date stated above · Check-in 14:00 · Check-out 10:30 · Please contact the Front Office to confirm before the validity date.',
    'thanks' => 'We look forward to welcoming you', 'issued' => 'This document was issued electronically by', 'printed' => 'printed',
    'open' => 'OPEN', 'expired' => 'EXPIRED', 'confirmed' => 'CONFIRMED', 'cancelled' => 'CANCELLED', 'fo' => 'Front Office', 'save' => 'Save PDF', 'print' => 'Print', 'close' => 'Close', 'other' => 'Bahasa Indonesia',
] : [
    'title' => 'PENAWARAN', 'sub' => 'Penawaran Kamar', 'to' => 'Ditujukan Kepada', 'stay' => 'Rincian Menginap', 'phone' => 'Telepon', 'email' => 'Email', 'ci' => 'Check-in', 'co' => 'Check-out',
    'night' => 'malam', 'nights' => 'malam', 'rooms' => 'Kamar', 'room' => 'Kamar', 'rtype' => 'Tipe Kamar', 'rate' => 'Harga', 'rateplan' => 'harga khusus', 'valid' => 'Berlaku Sampai', 'qno' => 'No. Penawaran', 'date' => 'Diterbitkan',
    'desc' => 'Deskripsi', 'type' => 'Jenis', 'disc' => 'Diskon', 'qty' => 'Jml', 'unit' => 'Harga Satuan', 'amount' => 'Jumlah', 'extra' => 'Layanan',
    'words' => 'Terbilang', 'rupiah' => 'rupiah', 'subtotal' => 'Subtotal kamar', 'rdisc' => 'Diskon kamar', 'rtotal' => 'Total kamar', 'extras' => 'Layanan tambahan', 'grand' => 'TOTAL',
    'notes' => 'Catatan', 'bank' => 'Transfer Bank', 'accname' => 'Atas nama', 'ref' => 'Mohon cantumkan nomor penawaran pada berita transfer.',
    'terms' => 'Syarat & Ketentuan', 'terms_txt' => 'Penawaran ini tidak menahan / memblok kamar; ketersediaan dijamin setelah konfirmasi · Harga dalam Rupiah (IDR), tarif kamar per kamar per malam · Berlaku sampai tanggal yang tertera · Check-in 14:00 · Check-out 10:30 · Mohon hubungi Front Office untuk konfirmasi sebelum tanggal berlaku berakhir.',
    'thanks' => 'Kami menantikan kedatangan Anda', 'issued' => 'Dokumen ini diterbitkan secara elektronik oleh', 'printed' => 'dicetak',
    'open' => 'TERBUKA', 'expired' => 'KEDALUWARSA', 'confirmed' => 'TERKONFIRMASI', 'cancelled' => 'DIBATALKAN', 'fo' => 'Front Office', 'save' => 'Simpan PDF', 'print' => 'Cetak', 'close' => 'Tutup', 'other' => 'English',
];
$months = $lang === 'en'
    ? ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
    : ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
$days = $lang === 'en' ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] : ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
// Tanggal pendek mengikuti bahasa dokumen (hindari nama bulan Inggris di dokumen Indonesia)
$fs = fn($d) => $d ? date('j', strtotime($d)) . ' ' . $months[(int)date('n', strtotime($d)) - 1] . ' ' . date('Y', strtotime($d)) : '-';
$fd = fn($d) => $d ? $days[(int)date('w', strtotime($d))] . ', ' . $fs($d) : '-';
$rp = fn($v) => 'Rp ' . number_format((float)$v, 0, ',', '.');
$qtyFmt = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',');

// Hotel (sama dengan invoice room)
$set = [];
foreach ($db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'company_%' OR setting_key LIKE 'invoice_bank_%' OR setting_key LIKE 'invoice_swift_%'") ?: [] as $r) $set[$r['setting_key']] = $r['setting_value'];
$coName = $set['company_name'] ?? (defined('BUSINESS_NAME') ? BUSINESS_NAME : 'Hotel');
$coTagline = trim((string)($set['company_tagline'] ?? ''));
$coAddress = trim((string)($set['company_address'] ?? ''));
$coPhone = trim((string)($set['company_phone'] ?? ''));
$coEmail = trim((string)($set['company_email'] ?? ''));
$coWebsite = trim((string)($set['company_website'] ?? ''));
$logoUrl = '';
try {
    require_once '../../includes/business_helper.php';
    $inv = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", ['invoice_logo_' . ACTIVE_BUSINESS_ID]);
    $logoUrl = trim((string)($inv['setting_value'] ?? ''));
    if ($logoUrl === '' && function_exists('getBusinessLogo')) $logoUrl = (string)getBusinessLogo();
} catch (\Throwable $e) {
}
$bankNo = trim((string)($set['invoice_bank_account_number_' . ACTIVE_BUSINESS_ID] ?? ''));
$bankName = trim((string)($set['invoice_bank_account_name_' . ACTIVE_BUSINESS_ID] ?? ''));
$swift = trim((string)($set['invoice_swift_code_' . ACTIVE_BUSINESS_ID] ?? ''));

$extrasTotal = (float)($q['extras_total'] ?? 0);
$roomsNet = (float)$q['total'] - $extrasTotal;
$roomTypes = array_values(array_unique(array_filter(array_map(fn($i) => $i['room_type'], $q['items']))));
$nNights = (int)$q['nights'];
$state = $q['status'] === 'confirmed' ? 'confirmed' : ($q['status'] === 'cancelled' ? 'cancelled' : ($q['expired'] ? 'expired' : 'open'));
$blankRows = 5;
$words = $lang === 'en' ? qtWordsEn((int)floor((float)$q['total'])) : qtWordsId((int)floor((float)$q['total']));
$no = 0;
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($L['title'] . ' ' . $q['quote_code'] . ' - ' . $q['guest_name']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --navy: #0f2747; --navy-2: #1d3f6e; --gold: #b08d57; --gold-soft: #f4ecdf; --ink: #1f2937; --muted: #6b7280; --line: #e5e7eb; --paper: #ffffff; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif; background: #eceae6; color: var(--ink); font-size: 11.5px; line-height: 1.5; padding: 24px 12px 90px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .page { position: relative; max-width: 794px; min-height: 1123px; margin: 0 auto; background: var(--paper); box-shadow: 0 2px 6px rgba(0, 0, 0, .05), 0 24px 60px -24px rgba(0, 0, 0, .25); display: flex; flex-direction: column; overflow: hidden; }
        .ribbon { height: 8px; background: var(--navy); }
        .ribbon-gold { height: 2px; background: linear-gradient(90deg, var(--gold), #d9bf8f, var(--gold)); }
        .inner { padding: 30px 44px 0; flex: 1; position: relative; z-index: 2; }

        /* Watermark logo hotel di tengah kertas (di belakang isi) */
        .wm { position: absolute; z-index: 1; top: 54%; left: 50%; width: 520px; height: 520px; transform: translate(-50%, -50%); border-radius: 50%; object-fit: cover; opacity: .085; pointer-events: none; }
        /* Stempel status (hanya bila bukan 'terbuka') */
        .stamp { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-28deg); border: 6px double currentColor; border-radius: 18px; padding: 10px 40px; font-weight: 800; font-size: 78px; letter-spacing: .12em; line-height: 1; white-space: nowrap; opacity: .09; z-index: 1; pointer-events: none; }
        .stamp.confirmed { color: #047857; } .stamp.cancelled { color: #6b7280; } .stamp.expired { color: #b91c1c; }

        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
        .brand { display: flex; gap: 16px; align-items: center; }
        .brand img, .brand .mono { width: 116px; height: 116px; border-radius: 50%; object-fit: cover; flex-shrink: 0; box-shadow: 0 0 0 1px var(--line), 0 0 0 5px var(--gold-soft); }
        .brand .mono { display: flex; align-items: center; justify-content: center; background: var(--navy); color: #e8d6b3; font-family: 'Cormorant Garamond', serif; font-size: 38px; font-weight: 700; }
        .brand h1 { font-family: 'Cormorant Garamond', serif; font-weight: 700; font-size: 27px; color: var(--navy); line-height: 1.05; letter-spacing: .01em; }
        .brand .tag { font-style: italic; color: var(--gold); font-size: 11px; margin-top: 2px; }
        .brand .addr { color: var(--muted); font-size: 9.8px; margin-top: 5px; line-height: 1.45; text-wrap: pretty; }
        .brand .co { text-align: left; max-width: 330px; }
        .doc { text-align: right; min-width: 200px; }
        .doc .title { font-family: 'Cormorant Garamond', serif; font-weight: 700; font-size: 32px; letter-spacing: .24em; color: var(--navy); line-height: 1; margin-right: -.24em; }
        .doc .sub { font-size: 9px; letter-spacing: .2em; color: var(--gold); text-transform: uppercase; margin-top: 4px; }
        .doc .status { display: inline-block; margin-top: 10px; padding: 3px 10px; border-radius: 999px; font-size: 9px; font-weight: 700; letter-spacing: .08em; }
        .status.open { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .status.confirmed { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .status.cancelled { background: #f3f4f6; color: #4b5563; border: 1px solid #d1d5db; }
        .status.expired { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 22px 0 14px; }
        .party { border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; background: rgba(255, 255, 255, .7); }
        .party h3 { font-size: 8.5px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--gold); margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid var(--gold-soft); }
        .party .name { font-size: 15px; font-weight: 700; color: var(--navy); line-height: 1.3; margin-bottom: 6px; }
        .kv { display: grid; grid-template-columns: 88px 1fr; gap: 3px 8px; font-size: 12px; }
        .kv span:nth-child(odd) { color: var(--muted); } .kv span:nth-child(even) { color: var(--ink); font-weight: 500; }
        .stay { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .stay .d { flex: 1; text-align: center; background: #f8fafc; border-radius: 8px; padding: 6px 4px; }
        .stay .d small { display: block; font-size: 8px; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); }
        .stay .d b { display: block; font-size: 12.5px; color: var(--navy); }
        .stay .n { font-size: 9.5px; font-weight: 700; color: var(--gold); white-space: nowrap; }

        .meta { display: grid; grid-template-columns: repeat(3, 1fr); margin: 0 0 20px; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; background: rgba(255, 255, 255, .7); }
        .meta div { padding: 11px 16px; } .meta div + div { border-left: 1px solid var(--line); }
        .meta small { display: block; font-size: 9.5px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); }
        .meta b { display: block; margin-top: 3px; font-size: 13px; color: var(--ink); font-weight: 600; }

        table.items { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        table.items thead th { background: var(--navy); color: #fff; font-size: 10px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; padding: 9px 10px; text-align: left; }
        table.items thead th:first-child { border-radius: 8px 0 0 8px; } table.items thead th:last-child { border-radius: 0 8px 8px 0; }
        table.items td { padding: 10px 10px; border-bottom: 1px solid var(--line); font-size: 12.5px; vertical-align: top; }
        table.items td .desc { font-weight: 600; color: var(--ink); font-size: 13px; }
        table.items td.type { font-size: 12px; color: var(--ink); white-space: nowrap; }
        table.items td.type .rate-plan { display: inline-block; margin-top: 3px; padding: 1px 6px; border-radius: 6px; font-size: 10px; font-weight: 700; color: #b45309; background: #fef3c7; white-space: nowrap; }
        table.items td.ref { color: var(--muted); font-size: 11.5px; white-space: nowrap; }
        table.items td .sub { color: var(--muted); font-size: 10.8px; margin-top: 2px; white-space: nowrap; }
        table.items .r { text-align: right; white-space: nowrap; } table.items .c { text-align: center; }
        table.items tbody tr:nth-child(even) td { background: rgba(251, 250, 247, .8); }
        table.items tr.blank td { height: 30px; border-bottom: 1px dotted #c3ccd9; background: transparent !important; }

        .totals-wrap { display: grid; grid-template-columns: 1fr 300px; gap: 20px; margin-top: 14px; }
        .words { align-self: start; background: var(--gold-soft); border-left: 3px solid var(--gold); border-radius: 0 8px 8px 0; padding: 9px 12px; font-size: 10px; color: #5b4630; }
        .words small { display: block; font-size: 8.5px; letter-spacing: .12em; text-transform: uppercase; color: var(--gold); font-weight: 700; margin-bottom: 2px; }
        .words i { text-transform: capitalize; }
        .totals { border: 1px solid var(--line); border-radius: 10px; overflow: hidden; background: #fff; }
        .totals .row { display: flex; justify-content: space-between; padding: 8px 14px; font-size: 12px; }
        .totals .row + .row { border-top: 1px solid var(--line); }
        .totals .row span:first-child { color: var(--muted); } .totals .row span:last-child { font-weight: 600; white-space: nowrap; }
        .totals .disc span:last-child { color: #b91c1c; }
        .totals .grand { background: var(--navy); color: #fff; padding: 10px 14px; }
        .totals .grand span { color: #fff !important; font-size: 12.5px; font-weight: 700 !important; letter-spacing: .04em; }

        .note { margin-top: 14px; font-size: 10.5px; background: #f8fafc; border-radius: 8px; padding: 8px 12px; color: var(--ink); }
        .note b { color: var(--navy); }

        .bottom { display: grid; grid-template-columns: 1fr 230px; gap: 16px; margin-top: 22px; align-items: stretch; }
        .bottom.solo { grid-template-columns: 1fr; } .bottom.solo .sign { margin-left: auto; width: 230px; }
        .box { border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; max-width: 360px; background: rgba(255, 255, 255, .7); }
        .box h5 { font-size: 8.5px; letter-spacing: .14em; text-transform: uppercase; color: var(--gold); margin-bottom: 6px; font-weight: 700; }
        .box .acc { font-size: 14px; font-weight: 700; color: var(--navy); letter-spacing: .04em; }
        .box p { font-size: 9.8px; color: var(--muted); }
        .sign { text-align: center; display: flex; flex-direction: column; justify-content: flex-end; }
        .sign .place { font-size: 9.5px; color: var(--muted); margin-bottom: 44px; }
        .sign .line { border-top: 1px solid var(--navy); padding-top: 5px; font-size: 10.8px; font-weight: 700; color: var(--navy); }
        .sign .role { font-size: 9px; color: var(--muted); }
        .terms-line { margin-top: 14px; padding-top: 8px; border-top: 1px solid var(--line); font-size: 8.8px; line-height: 1.55; color: var(--muted); text-align: center; }
        .terms-line b { color: var(--navy); font-weight: 700; letter-spacing: .08em; text-transform: uppercase; margin-right: 4px; }
        .legal { margin: 14px 0 0; font-size: 8.8px; color: var(--muted); text-align: center; }

        .foot { margin-top: 24px; background: var(--navy); color: #cbd5e1; text-align: center; padding: 16px 20px 18px; position: relative; z-index: 2; }
        .foot::before { content: ''; position: absolute; left: 0; right: 0; top: 0; height: 2px; background: linear-gradient(90deg, var(--gold), #d9bf8f, var(--gold)); }
        .foot .ty { font-family: 'Cormorant Garamond', serif; font-size: 17px; color: #fff; font-style: italic; }
        .foot .ci { font-size: 9.2px; margin-top: 4px; letter-spacing: .02em; }

        .actions { position: fixed; bottom: 18px; left: 50%; transform: translateX(-50%); z-index: 50; display: flex; gap: 8px; padding: 8px; border-radius: 14px; background: rgba(255, 255, 255, .9); backdrop-filter: blur(10px); box-shadow: 0 12px 30px -10px rgba(0, 0, 0, .35); }
        .actions button, .actions a { border: none; cursor: pointer; font: inherit; font-weight: 600; font-size: 12px; padding: 9px 18px; border-radius: 10px; text-decoration: none; display: inline-flex; align-items: center; }
        .actions .pri { background: var(--navy); color: #fff; } .actions .sec2 { background: #eef2f7; color: var(--navy); }

        @page { size: A4; margin: 0; }
        @media print { html, body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } body { background: #fff; padding: 0; } .page { box-shadow: none; max-width: none; margin: 0; } .actions { display: none; } }
        /* Tata letak cetak (JS beforeprint → html.pm): satu lembar A4, teks lebih tegas */
        html.pm { --ink: #111827; --muted: #374151; --line: #cbd5e1; }
        html.pm body { background: #fff; padding: 0; color: #111827; }
        html.pm .page { box-shadow: none; max-width: none; width: 210mm; margin: 0; }
        html.pm .brand .addr, html.pm .box p, html.pm .note, html.pm .words { font-size: 10.5px; }
        html.pm .kv, html.pm table.items td, html.pm .totals .row { font-size: 12.5px; }
        html.pm table.items td .sub, html.pm .sign .place, html.pm .sign .role, html.pm .legal, html.pm .foot .ci { font-size: 10px; }
        html.pm .meta small, html.pm .party h3, html.pm .box h5, html.pm .words small, html.pm table.items th, html.pm .stay .d small { font-size: 9.5px; }
        html.pm .terms-line { font-size: 9.5px; }
        html.pm .wm { opacity: .075; }
        html.pm .inner { padding: 24px 40px 0; }
        html.pm table.items td { padding: 7px 10px; } html.pm table.items tr.blank td { height: 24px; }
        html.pm .sign .place { margin-bottom: 34px; }
        @media (max-width: 640px) {
            .inner { padding: 22px 18px 0; }
            .head, .parties, .totals-wrap, .bottom { display: block; }
            .doc { text-align: left; margin-top: 14px; }
            .meta { grid-template-columns: 1fr; } .meta div + div { border-left: 0; border-top: 1px solid var(--line); }
            .party, .box { margin-bottom: 10px; }
            .wm { width: 320px; height: 320px; }
        }
    </style>
</head>

<body>
    <div class="page" id="quoteContent">
        <div class="ribbon"></div>
        <div class="ribbon-gold"></div>
        <?php if ($logoUrl !== ''): ?><img class="wm" src="<?php echo htmlspecialchars($logoUrl); ?>" alt=""><?php endif; ?>
        <?php if ($state !== 'open'): ?><div class="stamp <?php echo $state; ?>"><?php echo htmlspecialchars($L[$state]); ?></div><?php endif; ?>

        <div class="inner">
            <div class="head">
                <div class="brand">
                    <?php if ($logoUrl !== ''): ?>
                        <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="<?php echo htmlspecialchars($coName); ?>">
                    <?php else: ?>
                        <div class="mono"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($coName, 0, 1))); ?></div>
                    <?php endif; ?>
                    <div class="co">
                        <h1><?php echo htmlspecialchars($coName); ?></h1>
                        <?php if ($coTagline !== ''): ?><div class="tag">“<?php echo htmlspecialchars($coTagline); ?>”</div><?php endif; ?>
                        <div class="addr">
                            <?php echo htmlspecialchars($coAddress); ?><br>
                            <?php echo htmlspecialchars(implode(' · ', array_filter([$coPhone, $coEmail]))); ?>
                        </div>
                    </div>
                </div>
                <div class="doc">
                    <div class="title"><?php echo htmlspecialchars($L['title']); ?></div>
                    <div class="sub"><?php echo htmlspecialchars($L['sub']); ?></div>
                    <span class="status <?php echo $state; ?>"><?php echo htmlspecialchars($L[$state]); ?></span>
                </div>
            </div>

            <div class="parties">
                <div class="party">
                    <h3><?php echo htmlspecialchars($L['to']); ?></h3>
                    <div class="name"><?php echo htmlspecialchars($q['guest_name']); ?></div>
                    <div class="kv">
                        <span><?php echo htmlspecialchars($L['phone']); ?></span><span><?php echo htmlspecialchars($q['guest_phone'] ?: '-'); ?></span>
                        <span><?php echo htmlspecialchars($L['email']); ?></span><span><?php echo htmlspecialchars($q['guest_email'] ?: '-'); ?></span>
                    </div>
                </div>
                <div class="party">
                    <h3><?php echo htmlspecialchars($L['stay']); ?></h3>
                    <div class="stay">
                        <div class="d"><small><?php echo htmlspecialchars($L['ci']); ?></small><b><?php echo $fd($q['check_in']); ?></b></div>
                        <div class="n"><?php echo $nNights . ' ' . htmlspecialchars($nNights === 1 ? $L['night'] : $L['nights']); ?> →</div>
                        <div class="d"><small><?php echo htmlspecialchars($L['co']); ?></small><b><?php echo $fd($q['check_out']); ?></b></div>
                    </div>
                    <div class="kv">
                        <span><?php echo htmlspecialchars($L['rtype']); ?></span><span><?php echo htmlspecialchars($roomTypes ? implode(', ', $roomTypes) : '-'); ?></span>
                        <span><?php echo htmlspecialchars($L['rooms']); ?></span><span><?php echo count($q['items']); ?></span>
                        <?php if (!empty($q['rate_plan'])): ?>
                            <span><?php echo htmlspecialchars($L['rate']); ?></span><span><b><?php echo htmlspecialchars($q['rate_plan']); ?></b> <small style="color:var(--muted)">(<?php echo htmlspecialchars($L['rateplan']); ?>)</small></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="meta">
                <div><small><?php echo htmlspecialchars($L['qno']); ?></small><b><?php echo htmlspecialchars($q['quote_code']); ?></b></div>
                <div><small><?php echo htmlspecialchars($L['date']); ?></small><b><?php echo $fs(substr((string)$q['created_at'], 0, 10)); ?></b></div>
                <div><small><?php echo htmlspecialchars($L['valid']); ?></small><b><?php echo $fs($q['valid_until']); ?></b></div>
            </div>

            <table class="items">
                <thead>
                    <tr>
                        <th style="width:34px">#</th>
                        <th><?php echo htmlspecialchars($L['desc']); ?></th>
                        <th style="width:100px"><?php echo htmlspecialchars($L['type']); ?></th>
                        <th style="width:96px"><?php echo htmlspecialchars($L['disc']); ?></th>
                        <th class="c" style="width:76px"><?php echo htmlspecialchars($L['qty']); ?></th>
                        <th class="r" style="width:118px"><?php echo htmlspecialchars($L['unit']); ?></th>
                        <th class="r" style="width:128px"><?php echo htmlspecialchars($L['amount']); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($q['items'] as $it): $no++;
                        $dv = (float)$it['disc_value'];
                        $discTxt = $dv > 0 ? ($it['disc_type'] === 'percent' ? rtrim(rtrim(number_format($dv, 2, ',', '.'), '0'), ',') . '%' : $rp($dv) . ' /' . $L['night']) : '—'; ?>
                        <tr>
                            <td><?php echo $no; ?></td>
                            <td>
                                <div class="desc"><?php echo htmlspecialchars($L['room'] . ' ' . $it['room_number'] . ' — ' . ($it['room_type'] ?: $L['room'])); ?></div>
                                <div class="sub"><?php echo $fs($q['check_in']); ?> – <?php echo $fs($q['check_out']); ?></div>
                            </td>
                            <td class="type"><?php echo htmlspecialchars($L['room']); ?><?php if (!empty($q['rate_plan'])): ?><br><small class="rate-plan"><?php echo htmlspecialchars($q['rate_plan']); ?></small><?php endif; ?></td>
                            <td class="ref"><?php echo $discTxt; ?></td>
                            <td class="c"><?php echo $nNights . ' ' . htmlspecialchars($nNights === 1 ? $L['night'] : $L['nights']); ?></td>
                            <td class="r"><?php echo $rp($it['net_price']); ?></td>
                            <td class="r"><?php echo $rp($it['line_total']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($q['extras'] as $x): $no++; ?>
                        <tr>
                            <td><?php echo $no; ?></td>
                            <td>
                                <div class="desc"><?php echo htmlspecialchars($x['item_name']); ?></div>
                                <?php if (trim((string)$x['type_label']) !== ''): ?><div class="sub"><?php echo htmlspecialchars($x['type_label']); ?></div><?php endif; ?>
                            </td>
                            <td class="type"><?php echo htmlspecialchars($L['extra']); ?></td>
                            <td class="ref">—</td>
                            <td class="c"><?php echo $qtyFmt($x['qty']); ?><?php echo (trim((string)$x['unit']) !== '' && $x['unit'] !== 'unit') ? ' ' . htmlspecialchars($x['unit']) : ''; ?></td>
                            <td class="r"><?php echo $rp($x['unit_price']); ?></td>
                            <td class="r"><?php echo $rp($x['line_total']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php for ($i = 0; $i < $blankRows; $i++): ?>
                        <tr class="blank"><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                    <?php endfor; ?>
                </tbody>
            </table>

            <div class="totals-wrap">
                <div class="words">
                    <small><?php echo htmlspecialchars($L['words']); ?></small>
                    <i><?php echo htmlspecialchars(ucfirst($words)); ?> <?php echo htmlspecialchars($L['rupiah']); ?></i>
                </div>
                <div class="totals">
                    <div class="row"><span><?php echo htmlspecialchars($L['subtotal']); ?></span><span><?php echo $rp($q['subtotal']); ?></span></div>
                    <?php if ((float)$q['discount_total'] > 0): ?>
                        <div class="row disc"><span><?php echo htmlspecialchars($L['rdisc']); ?></span><span>- <?php echo $rp($q['discount_total']); ?></span></div>
                        <div class="row"><span><?php echo htmlspecialchars($L['rtotal']); ?></span><span><?php echo $rp($roomsNet); ?></span></div>
                    <?php endif; ?>
                    <?php if ($extrasTotal > 0): ?>
                        <div class="row"><span><?php echo htmlspecialchars($L['extras']); ?></span><span><?php echo $rp($extrasTotal); ?></span></div>
                    <?php endif; ?>
                    <div class="row grand"><span><?php echo htmlspecialchars($L['grand']); ?></span><span><?php echo $rp($q['total']); ?></span></div>
                </div>
            </div>

            <?php if (trim((string)$q['notes']) !== ''): ?>
                <div class="note"><b><?php echo htmlspecialchars($L['notes']); ?>:</b> <?php echo nl2br(htmlspecialchars($q['notes'])); ?></div>
            <?php endif; ?>

            <div class="bottom<?php echo $bankNo === '' ? ' solo' : ''; ?>">
                <?php if ($bankNo !== ''): ?>
                    <div class="box">
                        <h5><?php echo htmlspecialchars($L['bank']); ?></h5>
                        <div class="acc"><?php echo htmlspecialchars($bankNo); ?></div>
                        <?php if ($bankName !== ''): ?><p><?php echo htmlspecialchars($L['accname']); ?>: <?php echo htmlspecialchars($bankName); ?></p><?php endif; ?>
                        <?php if ($swift !== ''): ?><p>SWIFT: <?php echo htmlspecialchars($swift); ?></p><?php endif; ?>
                        <p style="margin-top:4px"><?php echo htmlspecialchars($L['ref']); ?></p>
                    </div>
                <?php endif; ?>
                <div class="sign">
                    <div class="place">Karimunjawa, <?php echo $fs(date('Y-m-d')); ?></div>
                    <div class="line"><?php echo htmlspecialchars($L['fo'] . ' ' . $coName); ?></div>
                    <div class="role"><?php echo htmlspecialchars($coName); ?></div>
                </div>
            </div>

            <div class="terms-line"><b><?php echo htmlspecialchars($L['terms']); ?></b> <?php echo htmlspecialchars($L['terms_txt']); ?></div>
            <div class="legal"><?php echo htmlspecialchars($L['issued']); ?> <?php echo htmlspecialchars($coName); ?> · <?php echo htmlspecialchars($q['quote_code']); ?> · <?php echo htmlspecialchars($L['printed']); ?> <?php echo $fs(date('Y-m-d')) . ' ' . date('H:i'); ?></div>
        </div>

        <div class="foot">
            <div class="ty"><?php echo htmlspecialchars($L['thanks']); ?></div>
            <div class="ci"><?php echo htmlspecialchars($coName); ?> · <?php echo htmlspecialchars($coAddress); ?></div>
            <div class="ci"><?php echo htmlspecialchars(implode(' · ', array_filter([$coPhone, $coEmail, $coWebsite]))); ?></div>
        </div>
    </div>

    <div class="actions">
        <button class="pri" onclick="savePDF()"><?php echo htmlspecialchars($L['save']); ?></button>
        <button class="sec2" onclick="window.print()"><?php echo htmlspecialchars($L['print']); ?></button>
        <a class="sec2" href="?id=<?php echo (int)$q['id']; ?>&lang=<?php echo $lang === 'en' ? 'id' : 'en'; ?>"><?php echo htmlspecialchars($L['other']); ?></a>
        <button class="sec2" onclick="window.close()"><?php echo htmlspecialchars($L['close']); ?></button>
    </div>

    <script>
        function savePDF() {
            document.title = 'Quotation_<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $q['quote_code']); ?>_<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', mb_substr($q['guest_name'], 0, 20)); ?>';
            window.print();
        }
        <?php if ($autoPrint): ?>
            window.onload = function() { setTimeout(function() { window.print(); }, 500); };
        <?php endif; ?>
    </script>
    <script>
        // Cetak pas satu lembar A4: pakai tata letak cetak (html.pm), ukur, lalu perkecil seluruh halaman bila isinya panjang
        (function() {
            const A4W = 793.7, A4H = 1118;
            const html = document.documentElement, page = document.querySelector('.page');
            if (!page) return;
            function fitPrint() {
                html.classList.add('pm');
                page.style.zoom = '';
                page.style.minHeight = '0';
                let z = 1;
                for (let i = 0; i < 6; i++) {
                    page.style.width = (A4W / z) + 'px';
                    const nz = Math.min(1, A4H / page.scrollHeight);
                    if (Math.abs(nz - z) < 0.002) break;
                    z = nz;
                }
                z = Math.min(1, A4H / page.scrollHeight);
                page.style.minHeight = (A4H / z) + 'px'; // footer tetap di dasar lembar
                page.style.zoom = z < 1 ? String(z) : '';
                for (let i = 0; i < 5; i++) {
                    const vh = page.getBoundingClientRect().height;
                    if (vh <= A4H + 0.5) break;
                    z = z * (A4H / vh) * 0.995;
                    page.style.width = (A4W / z) + 'px';
                    page.style.minHeight = (A4H / z) + 'px';
                    page.style.zoom = String(z);
                }
            }
            function resetPrint() { html.classList.remove('pm'); page.style.zoom = page.style.width = page.style.minHeight = ''; }
            window.addEventListener('beforeprint', fitPrint);
            window.addEventListener('afterprint', resetPrint);
        })();
    </script>
</body>

</html>
