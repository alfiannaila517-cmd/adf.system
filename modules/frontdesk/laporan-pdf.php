<?php

/**
 * FRONT DESK - LAPORAN HARIAN (PDF)
 * File PDF A4 (teks vektor, tajam saat dicetak) dari data yang sama dengan laporan.php.
 *   ?download=1 -> unduh sebagai file, selain itu tampil di browser (untuk dicetak).
 */

define('APP_ACCESS', true);
ob_start();
@set_time_limit(90);
@ini_set('memory_limit', '256M');
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/report_helper.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Spipu\Html2Pdf\Html2Pdf;

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('frontdesk')) {
    http_response_code(403);
    exit('Akses ditolak.');
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
session_write_close(); // pembuatan PDF bisa beberapa detik; jangan kunci sesi tab lain

require __DIR__ . '/laporan-data.php';

$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$d = static fn($v) => $v ? date('d M', strtotime($v)) : '-';

// Logo: path lokal bila file ada di server ini, selain itu URL (mis. Cloudinary).
$logoSrc = '';
$logoVal = (string)($company['invoice_logo'] ?? $company['logo'] ?? '');
if ($logoVal !== '') {
    $local = '';
    if (strpos($logoVal, BASE_URL) === 0) {
        $local = BASE_PATH . parse_url(substr($logoVal, strlen(BASE_URL)), PHP_URL_PATH);
    } elseif (strpos($logoVal, 'http') !== 0) {
        $local = BASE_PATH . '/' . ltrim(parse_url($logoVal, PHP_URL_PATH), '/');
    }
    if ($local !== '' && is_file($local) && @getimagesize($local)) {
        $logoSrc = $local;
    } elseif (strpos($logoVal, 'http') === 0 && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 5], 'https' => ['timeout' => 5]]);
        $img = @file_get_contents($logoVal, false, $ctx);
        if ($img !== false && @getimagesizefromstring($img)) {
            // Simpan sementara sebagai file lokal: lebih andal daripada TCPDF mengambil URL sendiri.
            $tmpLogo = tempnam(sys_get_temp_dir(), 'lgo');
            if ($tmpLogo && file_put_contents($tmpLogo, $img) !== false) $logoSrc = $tmpLogo;
        }
    }
}

/** One report section; $cols = [label => [width %, cell renderer(row)]] */
$section = static function (string $title, array $rows, array $cols, string $empty = 'No records') use ($e): string {
    $h = '<table class="sec" cellspacing="0"><tr><td class="sec-t">' . $e(strtoupper($title)) . '</td><td class="sec-n">' . count($rows) . '</td></tr></table>';
    $h .= '<table class="tbl" cellspacing="0"><tr>';
    foreach ($cols as $label => [$w]) {
        $h .= '<th style="width:' . $w . '%">' . $e($label) . '</th>';
    }
    $h .= '</tr>';
    if (!$rows) {
        $h .= '<tr><td class="empty" colspan="' . count($cols) . '">' . $e($empty) . '</td></tr>';
    }
    foreach ($rows as $r) {
        $h .= '<tr>';
        foreach ($cols as [$w, $fn]) {
            $h .= '<td style="width:' . $w . '%">' . $fn($r) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</table>';
};
$room = static fn($r) => $e($r['room_number']);
$type = static fn($r) => $e($r['type_name'] ?: '-');
$guest = static fn($r) => $e($r['guest_name']);
$code = static fn($r) => $e($r['booking_code']);
$phone = static fn($r) => $e($r['phone'] ?: '-');
$arr = static fn($r) => $d($r['check_in_date']);
$dep = static fn($r) => $d($r['check_out_date']);

ob_start();
?>
<style>
    .head { width: 100%; }
    .head td { vertical-align: bottom; }
    .hotel { font-size: 15pt; color: #111111; }
    .addr { font-size: 7.5pt; color: #6b6b6b; margin-top: 1mm; }
    .title { font-size: 9pt; color: #111111; text-align: right; letter-spacing: 2pt; }
    .date { font-size: 8pt; color: #6b6b6b; text-align: right; margin-top: 1mm; }
    .stats { width: 100%; margin-top: 4mm; }
    .stats td { width: 16.66%; padding: 0 0 0 2.5mm; border-left: 0.2mm solid #d4d4d4; vertical-align: top; }
    .stats td.first { border-left: 0; padding-left: 0; }
    .stats .v { font-size: 14pt; color: #111111; }
    .stats .l { font-size: 6.5pt; color: #7a7a7a; letter-spacing: 0.4pt; }
    .sec { width: 100%; margin-top: 7mm; }
    .sec-t { width: 85%; font-size: 8pt; color: #111111; letter-spacing: 1.2pt; }
    .sec-n { width: 15%; text-align: right; font-size: 8pt; color: #7a7a7a; }
    .tbl { width: 100%; margin-top: 1.5mm; }
    .tbl th { font-size: 6.5pt; font-weight: normal; color: #7a7a7a; letter-spacing: 0.5pt; text-align: left; padding: 1.4mm 2mm 1.4mm 0; border-top: 0.3mm solid #111111; border-bottom: 0.2mm solid #111111; }
    .tbl td { font-size: 8.5pt; color: #222222; padding: 1.6mm 2mm 1.6mm 0; border-bottom: 0.1mm solid #dcdcdc; vertical-align: top; }
    .tbl td.empty { color: #9a9a9a; font-style: italic; }
    .muted { color: #7a7a7a; font-size: 7.5pt; }
    .recap { width: 100%; margin-top: 1.5mm; }
    .recap td { font-size: 8.5pt; color: #222222; padding: 1.4mm 2mm 1.4mm 0; border-bottom: 0.1mm solid #dcdcdc; }
    .recap td.q { text-align: right; color: #111111; padding-right: 4mm; }
</style>
<page backtop="10mm" backbottom="14mm" backleft="12mm" backright="12mm">
    <page_footer>
        <table style="margin-left: 12mm; border-top: 0.1mm solid #cfcfcf;" cellspacing="0">
            <tr>
                <td style="width: 136mm; font-size: 7pt; color: #8a8a8a; padding-top: 1.5mm;">
                    Printed by <?php echo $e($currentUser['full_name'] ?? $currentUser['username'] ?? 'Staff'); ?> · <?php echo date('d M Y, H:i'); ?> · ADF System
                </td>
                <td style="width: 50mm; font-size: 7pt; color: #8a8a8a; text-align: right; padding-top: 1.5mm;">Page [[page_cu]] of [[page_nb]]</td>
            </tr>
        </table>
    </page_footer>

    <table class="head" cellspacing="0">
        <tr>
            <?php if ($logoSrc !== ''): ?>
                <!--LOGO--><td style="width: 10%;"><img src="<?php echo $e($logoSrc); ?>" style="width: 15mm; height: 15mm;"></td><!--/LOGO-->
            <?php endif; ?>
            <td style="width: <?php echo $logoSrc !== '' ? '60' : '70'; ?>%;">
                <div class="hotel"><?php echo $e($company['name']); ?></div>
                <div class="addr"><?php echo $e(implode('  ·  ', array_filter([$company['address'], $company['phone'], $company['email']]))); ?></div>
            </td>
            <td style="width: 30%;">
                <div class="title">DAILY REPORT</div>
                <div class="date"><?php echo $e($todayDisplay); ?></div>
            </td>
        </tr>
    </table>
    <div style="margin-top: 3mm; border-top: 0.3mm solid #111111;"></div>

    <table class="stats" cellspacing="0">
        <tr>
            <td class="first"><span class="v"><?php echo $occupancyRate; ?>%</span><br><span class="l">OCCUPANCY · <?php echo $occupiedRooms . '/' . $totalRooms; ?></span></td>
            <td><span class="v"><?php echo count($inHouseGuests); ?></span><br><span class="l">IN-HOUSE</span></td>
            <td><span class="v"><?php echo count($checkInToday); ?></span><br><span class="l">ARRIVALS TODAY</span></td>
            <td><span class="v"><?php echo count($checkOutToday); ?></span><br><span class="l">DEPARTURES TODAY</span></td>
            <td><span class="v"><?php echo count($arrivalTomorrow); ?></span><br><span class="l">ARRIVALS TOMORROW</span></td>
            <td><span class="v"><?php echo $breakfastPax; ?></span><br><span class="l">BREAKFAST PAX</span></td>
        </tr>
    </table>

    <?php
    echo $section('In-House Guests', $inHouseGuests, [
        'ROOM'      => [8, $room],
        'ROOM TYPE' => [17, $type],
        'GUEST'     => [39, $guest],
        'BOOKING'   => [16, $code],
        'ARRIVAL'   => [10, $arr],
        'DEPARTURE' => [10, $dep],
    ], 'No in-house guests');

    echo $section('Arrivals Today', $checkInToday, [
        'ROOM'      => [8, $room],
        'ROOM TYPE' => [17, $type],
        'GUEST'     => [37, $guest],
        'PHONE'     => [16, $phone],
        'BOOKING'   => [12, $code],
        'DEPARTURE' => [10, $dep],
    ], 'No arrivals today');

    echo $section('Departures Today', $checkOutToday, [
        'ROOM'      => [8, $room],
        'ROOM TYPE' => [17, $type],
        'GUEST'     => [39, $guest],
        'BOOKING'   => [16, $code],
        'ARRIVAL'   => [10, $arr],
        'DEPARTURE' => [10, $dep],
    ], 'No departures today');

    echo $section('Departures Tomorrow', $checkOutTomorrow, [
        'ROOM'      => [8, $room],
        'ROOM TYPE' => [17, $type],
        'GUEST'     => [37, $guest],
        'PHONE'     => [16, $phone],
        'BOOKING'   => [12, $code],
        'ARRIVAL'   => [10, $arr],
    ], 'No departures tomorrow');

    echo $section('Arrivals Tomorrow', $arrivalTomorrow, [
        'ROOM'      => [8, $room],
        'ROOM TYPE' => [17, $type],
        'GUEST'     => [33, $guest],
        'PHONE'     => [16, $phone],
        'BOOKING'   => [12, $code],
        'PAX'       => [5, fn($r) => (int)($r['guest_count'] ?: 1)],
        'DEPARTURE' => [9, $dep],
    ], 'No arrivals tomorrow');

    // Breakfast: kitchen summary + orders
    if ($breakfastOrders) {
        echo '<table class="sec" cellspacing="0"><tr><td class="sec-t">BREAKFAST SUMMARY (KITCHEN)</td><td class="sec-n">' . $breakfastPax . ' pax</td></tr></table>';
        echo '<table class="recap" cellspacing="0">';
        foreach (array_chunk($menuRecap, 3, true) as $chunk) {
            echo '<tr>';
            foreach ($chunk as $name => $qty) {
                echo '<td style="width:26%">' . $e($name) . '</td><td class="q" style="width:7.3%">' . (int)$qty . '</td>';
            }
            for ($i = count($chunk); $i < 3; $i++) echo '<td style="width:26%; border:0"></td><td style="width:7.3%; border:0"></td>';
            echo '</tr>';
        }
        echo '</table>';

        echo $section('Breakfast Orders', $breakfastOrders, [
            'TIME'  => [8, fn($o) => $o['breakfast_time'] ? date('H:i', strtotime($o['breakfast_time'])) : '-'],
            'ROOM'  => [12, fn($o) => $e($o['room_number'] ?: '-')],
            'GUEST' => [26, fn($o) => $e($o['guest_name']) . '<br><span class="muted">' . $e($bfLocationLabel($o['location'] ?? '')) . ' · ' . (int)$o['total_pax'] . ' pax</span>'],
            'ITEMS' => [54, fn($o) => implode('<br>', array_map(fn($it) => (int)($it['quantity'] ?? 1) . ' × ' . $e($it['menu_name'] ?? '?') . (!empty($it['note']) ? ' <span class="muted">(' . $e($it['note']) . ')</span>' : ''), $o['menu_items'])) . (!empty($o['special_requests']) ? '<br><span class="muted">Note: ' . $e($o['special_requests']) . '</span>' : '')],
        ]);
    }
    ?>
</page>
<?php
$html = ob_get_clean();

/** PDF sebagai string; percobaan kedua tanpa logo bila gambar logo bermasalah. */
$render = static function (string $html): string {
    $pdf = new Html2Pdf('P', 'A4', 'en', true, 'UTF-8', [0, 0, 0, 0]);
    $pdf->setDefaultFont('freesans'); // Helvetica-style, full UTF-8 for guest names
    $pdf->pdf->SetTitle('Daily Report ' . date('d M Y'));
    $pdf->writeHTML($html);
    return $pdf->output('laporan.pdf', 'S');
};

try {
    try {
        $bytes = $render($html);
    } catch (\Throwable $first) {
        error_log('Laporan PDF (dengan logo): ' . $first->getMessage());
        $bytes = $render(preg_replace('#<!--LOGO-->.*?<!--/LOGO-->#s', '', $html));
    }
} catch (\Throwable $ex) {
    error_log('Laporan PDF: ' . $ex->getMessage());
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Gagal membuat PDF: ' . $ex->getMessage();
    exit;
} finally {
    if (!empty($tmpLogo) && is_file($tmpLogo)) @unlink($tmpLogo);
}

// Buang output liar sebelum mengirim file.
while (ob_get_level() > 0) ob_end_clean();
$fileName = 'Daily-Report-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string)$company['name']) . '-' . $today . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $fileName . '"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, no-store');
echo $bytes;
