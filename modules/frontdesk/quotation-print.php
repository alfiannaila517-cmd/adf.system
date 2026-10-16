<?php

/**
 * Cetak penawaran kamar (A4). ?id=<quotation id>  [&lang=id|en untuk mengganti bahasa dokumen]
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

$L = $lang === 'en' ? [
    'title' => 'ROOM QUOTATION', 'no' => 'Quotation No.', 'date' => 'Date', 'valid' => 'Valid until', 'to' => 'Prepared for', 'stay' => 'Stay details',
    'ci' => 'Check-in', 'co' => 'Check-out', 'nights' => 'night(s)', 'type' => 'Room type', 'rooms' => 'Room(s)', 'rate' => 'Rate / night', 'disc' => 'Discount',
    'net' => 'Net / night', 'n' => 'Nights', 'total' => 'Total', 'subtotal' => 'Subtotal', 'discount' => 'Discount', 'grand' => 'TOTAL', 'notes' => 'Notes',
    'terms' => 'Terms', 'term1' => 'This quotation does not hold the rooms; availability is guaranteed only after confirmation.',
    'term2' => 'Prices are in Indonesian Rupiah (IDR) per room per night.', 'term3' => 'Please contact the Front Office to confirm before the validity date.',
    'pay' => 'Payment information', 'regards' => 'Best regards,', 'fo' => 'Front Office', 'print' => 'Print', 'close' => 'Close', 'lang' => 'Bahasa Indonesia',
    'status_c' => 'CONFIRMED', 'status_x' => 'CANCELLED', 'status_e' => 'EXPIRED', 'room' => 'Room',
] : [
    'title' => 'PENAWARAN KAMAR', 'no' => 'No. Penawaran', 'date' => 'Tanggal', 'valid' => 'Berlaku sampai', 'to' => 'Ditujukan kepada', 'stay' => 'Rincian menginap',
    'ci' => 'Check-in', 'co' => 'Check-out', 'nights' => 'malam', 'type' => 'Tipe kamar', 'rooms' => 'Kamar', 'rate' => 'Harga / malam', 'disc' => 'Diskon',
    'net' => 'Harga bersih / malam', 'n' => 'Malam', 'total' => 'Total', 'subtotal' => 'Subtotal', 'discount' => 'Diskon', 'grand' => 'TOTAL', 'notes' => 'Catatan',
    'terms' => 'Ketentuan', 'term1' => 'Penawaran ini belum memblok kamar; ketersediaan dijamin setelah konfirmasi.',
    'term2' => 'Harga dalam Rupiah (IDR) per kamar per malam.', 'term3' => 'Mohon hubungi Front Office untuk konfirmasi sebelum tanggal berlaku berakhir.',
    'pay' => 'Informasi pembayaran', 'regards' => 'Hormat kami,', 'fo' => 'Front Office', 'print' => 'Cetak', 'close' => 'Tutup', 'lang' => 'English',
    'status_c' => 'TERKONFIRMASI', 'status_x' => 'DIBATALKAN', 'status_e' => 'KEDALUWARSA', 'room' => 'Kamar',
];
$months = $lang === 'en'
    ? ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
    : ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$days = $lang === 'en' ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] : ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
$fd = fn($d, $dow = false) => $d ? ($dow ? $days[(int)date('w', strtotime($d))] . ', ' : '') . date('j', strtotime($d)) . ' ' . $months[(int)date('n', strtotime($d)) - 1] . ' ' . date('Y', strtotime($d)) : '-';
$rp = fn($v) => 'Rp ' . number_format((float)$v, 0, ',', '.');

// Hotel
$set = [];
foreach ($db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'company_%' OR setting_key LIKE 'invoice_bank_%'") ?: [] as $r) $set[$r['setting_key']] = $r['setting_value'];
$hotel = $set['company_name'] ?? (defined('BUSINESS_NAME') ? BUSINESS_NAME : 'Hotel');
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

// Kelompokkan kamar dengan tipe & harga yang sama jadi satu baris
$groups = [];
foreach ($q['items'] as $it) {
    $k = $it['room_type'] . '|' . $it['base_price'] . '|' . $it['disc_type'] . '|' . $it['disc_value'];
    if (!isset($groups[$k])) $groups[$k] = ['type' => $it['room_type'], 'base' => (float)$it['base_price'], 'dt' => $it['disc_type'], 'dv' => (float)$it['disc_value'], 'net' => (float)$it['net_price'], 'rooms' => [], 'total' => 0.0];
    $groups[$k]['rooms'][] = $it['room_number'];
    $groups[$k]['total'] += (float)$it['line_total'];
}
$stateLbl = $q['status'] === 'confirmed' ? $L['status_c'] : ($q['status'] === 'cancelled' ? $L['status_x'] : ($q['expired'] ? $L['status_e'] : ''));
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($L['title'] . ' ' . $q['quote_code']); ?></title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e8edf3; font-family: 'Segoe UI', system-ui, Arial, sans-serif; color: #0f172a; font-size: 12.5px; }
        .bar { position: sticky; top: 0; z-index: 5; display: flex; justify-content: center; gap: 8px; padding: 10px; background: #0f2744; }
        .bar button, .bar a { height: 34px; padding: 0 16px; border: 0; border-radius: 9px; background: #fff; color: #0f2744; font-weight: 700; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
        .bar .pri { background: #b8893b; color: #fff; }
        .page { width: 210mm; min-height: 297mm; margin: 14px auto; padding: 16mm 15mm; background: #fff; box-shadow: 0 10px 40px rgba(15, 39, 68, .18); position: relative; }
        .head { display: flex; align-items: center; gap: 14px; padding-bottom: 12px; border-bottom: 3px solid #0f2744; }
        .head img { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 2px solid #d9c39c; }
        .head .h { flex: 1; } .head h1 { margin: 0; font-size: 19px; color: #0f2744; letter-spacing: .01em; }
        .head .a { margin-top: 3px; font-size: 11px; color: #475569; line-height: 1.45; }
        .doc { text-align: right; } .doc b { display: block; font-size: 15px; letter-spacing: .08em; color: #b8893b; }
        .doc span { display: block; font-size: 11px; color: #475569; margin-top: 2px; }
        .state { position: absolute; top: 78mm; right: 22mm; transform: rotate(8deg); border: 3px solid #b91c1c; color: #b91c1c; padding: 3px 12px; font-weight: 900; letter-spacing: .12em; font-size: 17px; opacity: .5; border-radius: 6px; pointer-events: none; }
        .state.ok { border-color: #15803d; color: #15803d; }
        .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 16px 0 14px; }
        .box { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; background: #f8fafc; }
        .box small { display: block; font-size: 9.5px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #64748b; margin-bottom: 4px; }
        .box b { font-size: 14px; } .box div { line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th { background: #0f2744; color: #fff; font-size: 10px; letter-spacing: .05em; text-transform: uppercase; padding: 8px 8px; text-align: left; }
        th.r, td.r { text-align: right; } th.c, td.c { text-align: center; }
        td { padding: 9px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        td .rooms { color: #475569; font-size: 11px; }
        .tot { margin: 12px 0 0 auto; width: 70mm; }
        .tot div { display: flex; justify-content: space-between; padding: 3px 0; font-size: 12px; }
        .tot .g { margin-top: 4px; padding: 8px 10px; border-radius: 8px; background: #0f2744; color: #fff; font-weight: 800; font-size: 14px; }
        .sec { margin-top: 16px; } .sec h3 { margin: 0 0 4px; font-size: 10px; letter-spacing: .08em; text-transform: uppercase; color: #64748b; }
        .sec p, .sec li { margin: 2px 0; line-height: 1.5; color: #334155; } .sec ul { margin: 0; padding-left: 16px; }
        .sig { margin-top: 28px; display: flex; justify-content: flex-end; } .sig div { width: 56mm; text-align: center; } .sig .line { height: 52px; border-bottom: 1px solid #64748b; margin-bottom: 4px; }
        .foot { position: absolute; left: 15mm; right: 15mm; bottom: 10mm; text-align: center; font-size: 10px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 6px; }
        @media print { body { background: #fff; } .bar { display: none; } .page { margin: 0; width: auto; min-height: 0; box-shadow: none; padding: 0; } .foot { position: fixed; left: 0; right: 0; bottom: 0; } }
    </style>
</head>
<body>
    <div class="bar">
        <button class="pri" onclick="window.print()"><?php echo htmlspecialchars($L['print']); ?></button>
        <a href="?id=<?php echo (int)$q['id']; ?>&lang=<?php echo $lang === 'en' ? 'id' : 'en'; ?>"><?php echo htmlspecialchars($L['lang']); ?></a>
        <button onclick="window.close()"><?php echo htmlspecialchars($L['close']); ?></button>
    </div>
    <div class="page">
        <?php if ($stateLbl !== ''): ?><div class="state <?php echo $q['status'] === 'confirmed' ? 'ok' : ''; ?>"><?php echo $stateLbl; ?></div><?php endif; ?>
        <div class="head">
            <?php if ($logoUrl !== ''): ?><img src="<?php echo htmlspecialchars($logoUrl); ?>" alt=""><?php endif; ?>
            <div class="h">
                <h1><?php echo htmlspecialchars($hotel); ?></h1>
                <div class="a"><?php echo htmlspecialchars(trim(($set['company_address'] ?? ''))); ?><br>
                    <?php echo htmlspecialchars(implode(' · ', array_filter([$set['company_phone'] ?? '', $set['company_email'] ?? '', $set['company_website'] ?? '']))); ?></div>
            </div>
            <div class="doc">
                <b><?php echo htmlspecialchars($L['title']); ?></b>
                <span><?php echo htmlspecialchars($L['no']); ?>: <strong><?php echo htmlspecialchars($q['quote_code']); ?></strong></span>
                <span><?php echo htmlspecialchars($L['date']); ?>: <?php echo $fd(substr((string)$q['created_at'], 0, 10)); ?></span>
                <span><?php echo htmlspecialchars($L['valid']); ?>: <strong><?php echo $fd($q['valid_until']); ?></strong></span>
            </div>
        </div>

        <div class="cols">
            <div class="box">
                <small><?php echo htmlspecialchars($L['to']); ?></small>
                <b><?php echo htmlspecialchars($q['guest_name']); ?></b>
                <div><?php echo htmlspecialchars(implode(' · ', array_filter([$q['guest_phone'], $q['guest_email']]))); ?></div>
            </div>
            <div class="box">
                <small><?php echo htmlspecialchars($L['stay']); ?></small>
                <div><?php echo htmlspecialchars($L['ci']); ?>: <b><?php echo $fd($q['check_in'], true); ?></b></div>
                <div><?php echo htmlspecialchars($L['co']); ?>: <b><?php echo $fd($q['check_out'], true); ?></b> · <?php echo (int)$q['nights'] . ' ' . htmlspecialchars($L['nights']); ?></div>
            </div>
        </div>

        <table>
            <thead><tr><th><?php echo htmlspecialchars($L['type']); ?></th><th class="r"><?php echo htmlspecialchars($L['rate']); ?></th><th class="r"><?php echo htmlspecialchars($L['disc']); ?></th><th class="r"><?php echo htmlspecialchars($L['net']); ?></th><th class="c"><?php echo htmlspecialchars($L['n']); ?></th><th class="r"><?php echo htmlspecialchars($L['total']); ?></th></tr></thead>
            <tbody>
                <?php foreach ($groups as $g): ?>
                    <tr>
                        <td><b><?php echo htmlspecialchars($g['type']); ?></b> × <?php echo count($g['rooms']); ?><div class="rooms"><?php echo htmlspecialchars($L['room'] . ' ' . implode(', ', $g['rooms'])); ?></div></td>
                        <td class="r"><?php echo $rp($g['base']); ?></td>
                        <td class="r"><?php echo $g['dv'] > 0 ? ($g['dt'] === 'percent' ? rtrim(rtrim(number_format($g['dv'], 2, ',', '.'), '0'), ',') . '%' : $rp($g['dv'])) : '—'; ?></td>
                        <td class="r"><?php echo $rp($g['net']); ?></td>
                        <td class="c"><?php echo (int)$q['nights']; ?></td>
                        <td class="r"><b><?php echo $rp($g['total']); ?></b></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="tot">
            <?php if ((float)$q['discount_total'] > 0): ?>
                <div><span><?php echo htmlspecialchars($L['subtotal']); ?></span><span><?php echo $rp($q['subtotal']); ?></span></div>
                <div><span><?php echo htmlspecialchars($L['discount']); ?></span><span>−<?php echo $rp($q['discount_total']); ?></span></div>
            <?php endif; ?>
            <div class="g"><span><?php echo htmlspecialchars($L['grand']); ?></span><span><?php echo $rp($q['total']); ?></span></div>
        </div>

        <?php if (trim((string)$q['notes']) !== ''): ?>
            <div class="sec"><h3><?php echo htmlspecialchars($L['notes']); ?></h3><p><?php echo nl2br(htmlspecialchars($q['notes'])); ?></p></div>
        <?php endif; ?>
        <div class="sec"><h3><?php echo htmlspecialchars($L['terms']); ?></h3>
            <ul><li><?php echo htmlspecialchars($L['term1']); ?></li><li><?php echo htmlspecialchars($L['term2']); ?></li><li><?php echo htmlspecialchars($L['term3']); ?></li></ul></div>
        <?php if ($bankNo !== ''): ?>
            <div class="sec"><h3><?php echo htmlspecialchars($L['pay']); ?></h3><p><b><?php echo htmlspecialchars($bankNo); ?></b><?php echo $bankName !== '' ? ' · ' . htmlspecialchars($bankName) : ''; ?></p></div>
        <?php endif; ?>
        <div class="sig"><div><?php echo htmlspecialchars($L['regards']); ?><div class="line"></div><b><?php echo htmlspecialchars($L['fo'] . ' ' . $hotel); ?></b></div></div>
        <div class="foot"><?php echo htmlspecialchars($hotel); ?> · <?php echo htmlspecialchars($q['quote_code']); ?></div>
    </div>
</body>
</html>
