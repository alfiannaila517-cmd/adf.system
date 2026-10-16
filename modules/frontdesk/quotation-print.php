<?php

/**
 * Cetak penawaran kamar (A4). ?id=<quotation id>  [&lang=id|en untuk mengganti bahasa dokumen]
 * Tata letak: pita kop navy + garis emas, isi, lalu penutup (tanda tangan & footer) yang selalu menempel di dasar halaman.
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
    'ci' => 'Check-in', 'co' => 'Check-out', 'nights' => 'night(s)', 'desc' => 'Description', 'rate' => 'Rate', 'disc' => 'Discount',
    'net' => 'Net rate', 'n' => 'Qty / Nights', 'total' => 'Total', 'subtotal' => 'Room subtotal', 'discount' => 'Room discount', 'extras_total' => 'Additional services',
    'grand' => 'TOTAL', 'notes' => 'Notes', 'terms' => 'Terms & conditions', 'term1' => 'This quotation does not hold the rooms; availability is guaranteed only after confirmation.',
    'term2' => 'Prices are in Indonesian Rupiah (IDR); room rates are per room per night.', 'term3' => 'Please contact the Front Office to confirm before the validity date.',
    'pay' => 'Payment information', 'regards' => 'Best regards,', 'fo' => 'Front Office', 'print' => 'Print', 'close' => 'Close', 'lang' => 'Bahasa Indonesia',
    'status_c' => 'CONFIRMED', 'status_x' => 'CANCELLED', 'status_e' => 'EXPIRED', 'room' => 'Room', 'acc' => 'Accommodation', 'extras' => 'Additional services',
    'thanks' => 'Thank you for considering us — we look forward to welcoming you.', 'per' => '/ ',
] : [
    'title' => 'PENAWARAN KAMAR', 'no' => 'No. Penawaran', 'date' => 'Tanggal', 'valid' => 'Berlaku sampai', 'to' => 'Ditujukan kepada', 'stay' => 'Rincian menginap',
    'ci' => 'Check-in', 'co' => 'Check-out', 'nights' => 'malam', 'desc' => 'Deskripsi', 'rate' => 'Harga', 'disc' => 'Diskon',
    'net' => 'Harga bersih', 'n' => 'Jml / Malam', 'total' => 'Total', 'subtotal' => 'Subtotal kamar', 'discount' => 'Diskon kamar', 'extras_total' => 'Layanan tambahan',
    'grand' => 'TOTAL', 'notes' => 'Catatan', 'terms' => 'Syarat & ketentuan', 'term1' => 'Penawaran ini belum memblok kamar; ketersediaan dijamin setelah konfirmasi.',
    'term2' => 'Harga dalam Rupiah (IDR); tarif kamar per kamar per malam.', 'term3' => 'Mohon hubungi Front Office untuk konfirmasi sebelum tanggal berlaku berakhir.',
    'pay' => 'Informasi pembayaran', 'regards' => 'Hormat kami,', 'fo' => 'Front Office', 'print' => 'Cetak', 'close' => 'Tutup', 'lang' => 'English',
    'status_c' => 'TERKONFIRMASI', 'status_x' => 'DIBATALKAN', 'status_e' => 'KEDALUWARSA', 'room' => 'Kamar', 'acc' => 'Akomodasi', 'extras' => 'Layanan tambahan',
    'thanks' => 'Terima kasih atas kepercayaan Anda — kami menantikan kedatangan Anda.', 'per' => '/ ',
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
$extrasTotal = (float)($q['extras_total'] ?? 0);
$roomsNet = (float)$q['total'] - $extrasTotal;
$blankRows = 5;
$stateLbl = $q['status'] === 'confirmed' ? $L['status_c'] : ($q['status'] === 'cancelled' ? $L['status_x'] : ($q['expired'] ? $L['status_e'] : ''));
$qtyFmt = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',');
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($L['title'] . ' ' . $q['quote_code']); ?></title>
    <style>
        @page { size: A4; margin: 0; }
        * { box-sizing: border-box; }
        :root { --navy: #0f2744; --gold: #b8893b; --ink: #1e293b; --mute: #64748b; --line: #e5e9f0; --sand: #faf7f1; }
        body { margin: 0; background: #e3e9f1; font-family: 'Segoe UI', system-ui, Arial, sans-serif; color: var(--ink); font-size: 12px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .bar { position: sticky; top: 0; z-index: 5; display: flex; justify-content: center; gap: 8px; padding: 10px; background: #0b1d33; }
        .bar button, .bar a { height: 34px; padding: 0 16px; border: 0; border-radius: 9px; background: #fff; color: var(--navy); font-weight: 700; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
        .bar .pri { background: var(--gold); color: #fff; }

        /* Halaman: kop + isi + penutup (penutup menempel di dasar) */
        .page { position: relative; display: flex; flex-direction: column; width: 210mm; min-height: 296mm; margin: 14px auto; background: #fff; box-shadow: 0 12px 44px rgba(15, 39, 68, .2); overflow: hidden; }
        .top { display: flex; align-items: center; gap: 16px; padding: 11mm 15mm 8mm; background: linear-gradient(135deg, #0b1d33, #14385f); color: #fff; }
        .top img { width: 62px; height: 62px; border-radius: 50%; object-fit: cover; border: 2px solid #d9c39c; background: #fff; flex: none; }
        .top .h { flex: 1; min-width: 0; }
        .top h1 { margin: 0; font-family: Georgia, 'Times New Roman', serif; font-size: 21px; font-weight: 700; letter-spacing: .01em; color: #fff; }
        .top .a { margin-top: 4px; font-size: 10.5px; line-height: 1.5; color: #c6d2e4; }
        .top .doc { text-align: right; flex: none; }
        .top .doc b { display: block; font-size: 12px; letter-spacing: .22em; color: #e3c68f; }
        .top .doc .no { margin-top: 5px; font-size: 14.5px; font-weight: 700; color: #fff; letter-spacing: .02em; }
        .top .doc span { display: block; margin-top: 2px; font-size: 10.5px; color: #c6d2e4; }
        .rule { height: 4px; background: linear-gradient(90deg, var(--gold), #e3c68f 55%, var(--gold)); }
        .body { flex: 1; padding: 9mm 15mm 6mm; }
        .state { position: absolute; top: 88mm; right: 20mm; transform: rotate(8deg); border: 3px solid #b91c1c; color: #b91c1c; padding: 3px 12px; font-weight: 900; letter-spacing: .12em; font-size: 17px; opacity: .45; border-radius: 6px; pointer-events: none; }
        .state.ok { border-color: #15803d; color: #15803d; }

        .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 7mm; }
        .box { padding: 10px 14px; border-radius: 10px; background: var(--sand); border-left: 3px solid var(--gold); }
        .box small { display: block; margin-bottom: 4px; font-size: 9px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--gold); }
        .box .nm { font-family: Georgia, serif; font-size: 15px; font-weight: 700; color: var(--navy); }
        .box div { line-height: 1.55; color: #334155; }

        table { width: 100%; border-collapse: collapse; }
        thead th { padding: 7px 8px; text-align: left; font-size: 9px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--navy); border-bottom: 2px solid var(--navy); }
        th.r, td.r { text-align: right; } th.c, td.c { text-align: center; }
        td { padding: 8px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
        td .rooms { margin-top: 1px; font-size: 10.5px; color: var(--mute); }
        tr.sec td { padding: 9px 8px 4px; border-bottom: 0; font-size: 9px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: var(--gold); }
        tr.blank td { height: 8.5mm; border-bottom: 1px dotted #c3ccd9; }

        .tot { width: 78mm; margin: 5mm 0 0 auto; }
        .tot .r { display: flex; justify-content: space-between; padding: 3px 2px; font-size: 11.5px; color: #475569; }
        .tot .g { display: flex; justify-content: space-between; align-items: center; margin-top: 5px; padding: 9px 12px; border-radius: 9px; background: var(--navy); color: #fff; font-weight: 700; letter-spacing: .06em; }
        .tot .g b { font-size: 15px; color: #e3c68f; letter-spacing: .01em; }

        .sec2 { margin-top: 6mm; } .sec2 h3 { margin: 0 0 4px; font-size: 9px; letter-spacing: .14em; text-transform: uppercase; color: var(--gold); }
        .sec2 p, .sec2 li { margin: 2px 0; line-height: 1.55; color: #334155; font-size: 11px; } .sec2 ul { margin: 0; padding-left: 15px; }

        .foot { padding: 0 15mm 9mm; }
        .sig { display: flex; justify-content: flex-end; margin-bottom: 7mm; }
        .sig > div { width: 62mm; text-align: center; font-size: 11px; color: #334155; }
        .sig .line { height: 17mm; border-bottom: 1px solid #94a3b8; margin-bottom: 4px; }
        .sig b { color: var(--navy); }
        .foot .thx { padding-top: 5px; border-top: 1px solid var(--line); text-align: center; font-size: 10px; color: var(--mute); font-style: italic; }
        .foot .fl { margin-top: 3px; text-align: center; font-size: 9.5px; letter-spacing: .06em; color: #94a3b8; }

        @media print { body { background: #fff; } .bar { display: none; } .page { margin: 0; box-shadow: none; width: 210mm; min-height: 296mm; } }
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
        <div class="top">
            <?php if ($logoUrl !== ''): ?><img src="<?php echo htmlspecialchars($logoUrl); ?>" alt=""><?php endif; ?>
            <div class="h">
                <h1><?php echo htmlspecialchars($hotel); ?></h1>
                <div class="a"><?php echo htmlspecialchars(trim(($set['company_address'] ?? ''))); ?><br>
                    <?php echo htmlspecialchars(implode('  ·  ', array_filter([$set['company_phone'] ?? '', $set['company_email'] ?? '', $set['company_website'] ?? '']))); ?></div>
            </div>
            <div class="doc">
                <b><?php echo htmlspecialchars($L['title']); ?></b>
                <div class="no"><?php echo htmlspecialchars($q['quote_code']); ?></div>
                <span><?php echo htmlspecialchars($L['date']); ?>: <?php echo $fd(substr((string)$q['created_at'], 0, 10)); ?></span>
                <span><?php echo htmlspecialchars($L['valid']); ?>: <strong><?php echo $fd($q['valid_until']); ?></strong></span>
            </div>
        </div>
        <div class="rule"></div>

        <div class="body">
            <div class="cols">
                <div class="box">
                    <small><?php echo htmlspecialchars($L['to']); ?></small>
                    <div class="nm"><?php echo htmlspecialchars($q['guest_name']); ?></div>
                    <div><?php echo htmlspecialchars(implode('  ·  ', array_filter([$q['guest_phone'], $q['guest_email']]))); ?></div>
                </div>
                <div class="box">
                    <small><?php echo htmlspecialchars($L['stay']); ?></small>
                    <div><?php echo htmlspecialchars($L['ci']); ?>: <b><?php echo $fd($q['check_in'], true); ?></b></div>
                    <div><?php echo htmlspecialchars($L['co']); ?>: <b><?php echo $fd($q['check_out'], true); ?></b> · <?php echo (int)$q['nights'] . ' ' . htmlspecialchars($L['nights']); ?></div>
                </div>
            </div>

            <table>
                <thead><tr><th><?php echo htmlspecialchars($L['desc']); ?></th><th class="r"><?php echo htmlspecialchars($L['rate']); ?></th><th class="r"><?php echo htmlspecialchars($L['disc']); ?></th><th class="r"><?php echo htmlspecialchars($L['net']); ?></th><th class="c"><?php echo htmlspecialchars($L['n']); ?></th><th class="r"><?php echo htmlspecialchars($L['total']); ?></th></tr></thead>
                <tbody>
                    <tr class="sec"><td colspan="6"><?php echo htmlspecialchars($L['acc']); ?></td></tr>
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
                    <?php if (!empty($q['extras'])): ?>
                        <tr class="sec"><td colspan="6"><?php echo htmlspecialchars($L['extras']); ?></td></tr>
                        <?php foreach ($q['extras'] as $x): ?>
                            <tr>
                                <td><b><?php echo htmlspecialchars($x['item_name']); ?></b><?php if (trim((string)$x['type_label']) !== ''): ?><div class="rooms"><?php echo htmlspecialchars($x['type_label']); ?></div><?php endif; ?></td>
                                <td class="r"><?php echo $rp($x['unit_price']); ?><?php echo (trim((string)$x['unit']) !== '' && $x['unit'] !== 'unit') ? '<div class="rooms">' . htmlspecialchars($L['per'] . $x['unit']) . '</div>' : ''; ?></td>
                                <td class="r">—</td>
                                <td class="r"><?php echo $rp($x['unit_price']); ?></td>
                                <td class="c"><?php echo $qtyFmt($x['qty']); ?></td>
                                <td class="r"><b><?php echo $rp($x['line_total']); ?></b></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php for ($i = 0; $i < $blankRows; $i++): ?>
                        <tr class="blank"><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                    <?php endfor; ?>
                </tbody>
            </table>

            <div class="tot">
                <?php if ((float)$q['discount_total'] > 0 || $extrasTotal > 0): ?>
                    <div class="r"><span><?php echo htmlspecialchars($L['subtotal']); ?></span><span><?php echo $rp($q['subtotal']); ?></span></div>
                <?php endif; ?>
                <?php if ((float)$q['discount_total'] > 0): ?>
                    <div class="r"><span><?php echo htmlspecialchars($L['discount']); ?></span><span>−<?php echo $rp($q['discount_total']); ?></span></div>
                <?php endif; ?>
                <?php if ($extrasTotal > 0): ?>
                    <div class="r"><span><?php echo htmlspecialchars($L['extras_total']); ?></span><span><?php echo $rp($extrasTotal); ?></span></div>
                <?php endif; ?>
                <div class="g"><span><?php echo htmlspecialchars($L['grand']); ?></span><b><?php echo $rp($q['total']); ?></b></div>
            </div>

            <?php if (trim((string)$q['notes']) !== ''): ?>
                <div class="sec2"><h3><?php echo htmlspecialchars($L['notes']); ?></h3><p><?php echo nl2br(htmlspecialchars($q['notes'])); ?></p></div>
            <?php endif; ?>
            <div class="sec2"><h3><?php echo htmlspecialchars($L['terms']); ?></h3>
                <ul><li><?php echo htmlspecialchars($L['term1']); ?></li><li><?php echo htmlspecialchars($L['term2']); ?></li><li><?php echo htmlspecialchars($L['term3']); ?></li></ul></div>
            <?php if ($bankNo !== ''): ?>
                <div class="sec2"><h3><?php echo htmlspecialchars($L['pay']); ?></h3><p><b><?php echo htmlspecialchars($bankNo); ?></b><?php echo $bankName !== '' ? '  ·  ' . htmlspecialchars($bankName) : ''; ?></p></div>
            <?php endif; ?>
        </div>

        <div class="foot">
            <div class="sig"><div><?php echo htmlspecialchars($L['regards']); ?><div class="line"></div><b><?php echo htmlspecialchars($L['fo'] . ' ' . $hotel); ?></b></div></div>
            <div class="thx"><?php echo htmlspecialchars($L['thanks']); ?></div>
            <div class="fl"><?php echo htmlspecialchars($hotel); ?>  ·  <?php echo htmlspecialchars($q['quote_code']); ?></div>
        </div>
    </div>
</body>
</html>
