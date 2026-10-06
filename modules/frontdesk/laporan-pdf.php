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

/** Satu bagian tabel; $cols = [judul => [lebar%, fungsi isi sel(row)]] */
$section = static function (string $title, array $rows, array $cols, string $empty = 'Tidak ada data') use ($e): string {
    $h = '<table class="sec" cellspacing="0"><tr><td class="sec-t">' . $e($title) . '</td><td class="sec-n">' . count($rows) . '</td></tr></table>';
    $h .= '<table class="tbl" cellspacing="0"><tr>';
    foreach ($cols as $label => [$w]) {
        $h .= '<th style="width:' . $w . '%">' . $e($label) . '</th>';
    }
    $h .= '</tr>';
    if (!$rows) {
        $h .= '<tr><td class="empty" colspan="' . count($cols) . '">' . $e($empty) . '</td></tr>';
    }
    foreach ($rows as $i => $r) {
        $h .= '<tr class="' . ($i % 2 ? 'odd' : '') . '">';
        foreach ($cols as [$w, $fn]) {
            $h .= '<td style="width:' . $w . '%">' . $fn($r) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</table>';
};
$room = static fn($r) => '<span class="room">' . $e($r['room_number']) . '</span>';

ob_start();
?>
<style>
    .head { width: 100%; border-bottom: 1.2mm solid #0f2747; }
    .head td { vertical-align: middle; }
    .hotel { font-size: 15pt; font-weight: bold; color: #0f2747; }
    .addr { font-size: 7.5pt; color: #475569; }
    .title { font-size: 13pt; font-weight: bold; color: #0f2747; text-align: right; letter-spacing: 1pt; }
    .date { font-size: 8.5pt; color: #b08d57; text-align: right; }
    .stats { width: 100%; margin-top: 4mm; }
    .stats td { width: 16.66%; text-align: center; padding: 2.2mm 0.5mm; border: 0.3mm solid #dbe3ee; background: #f6f8fc; }
    .stats .v { font-size: 13pt; font-weight: bold; color: #0f2747; }
    .stats .l { font-size: 6pt; color: #64748b; text-transform: uppercase; }
    .sec { width: 100%; margin-top: 5mm; }
    .sec-t { width: 85%; font-size: 9.5pt; font-weight: bold; color: #0f2747; }
    .sec-n { width: 15%; text-align: right; font-size: 8pt; color: #1d4ed8; font-weight: bold; }
    .tbl { width: 100%; margin-top: 1.2mm; }
    .tbl th { background: #0f2747; color: #ffffff; font-size: 7pt; font-weight: bold; text-transform: uppercase; padding: 1.6mm 2mm; text-align: left; }
    .tbl td { font-size: 8pt; color: #1f2937; padding: 1.5mm 2mm; border-bottom: 0.2mm solid #e5e7eb; vertical-align: top; }
    .tbl tr.odd td { background: #f8fafc; }
    .tbl td.empty { color: #94a3b8; font-style: italic; text-align: center; }
    .room { font-weight: bold; color: #1d4ed8; }
    .paid { color: #047857; font-weight: bold; }
    .partial { color: #b45309; font-weight: bold; }
    .unpaid { color: #b91c1c; font-weight: bold; }
    .muted { color: #64748b; font-size: 7pt; }
    .recap { width: 100%; margin-top: 1.2mm; }
    .recap td { font-size: 8pt; padding: 1.3mm 2mm; border: 0.2mm solid #fde68a; background: #fffbeb; }
    .recap .q { font-weight: bold; color: #b45309; text-align: right; }
</style>
<page backtop="8mm" backbottom="12mm" backleft="10mm" backright="10mm">
    <page_footer>
        <table style="margin-left: 10mm; border-top: 0.2mm solid #cbd5e1;" cellspacing="0">
            <tr>
                <td style="width: 140mm; font-size: 7pt; color: #64748b; padding-top: 1.5mm;">
                    Dicetak oleh <?php echo $e($currentUser['full_name'] ?? $currentUser['username'] ?? 'Staff'); ?> · <?php echo date('d M Y, H:i'); ?> WIB · ADF System
                </td>
                <td style="width: 50mm; font-size: 7pt; color: #64748b; text-align: right; padding-top: 1.5mm;">Halaman [[page_cu]] / [[page_nb]]</td>
            </tr>
        </table>
    </page_footer>

    <table class="head" cellspacing="0">
        <tr>
            <?php if ($logoSrc !== ''): ?>
                <!--LOGO--><td style="width: 11%; padding-bottom: 3mm;"><img src="<?php echo $e($logoSrc); ?>" style="width: 18mm; height: 18mm;"></td><!--/LOGO-->
            <?php endif; ?>
            <td style="width: <?php echo $logoSrc !== '' ? '57' : '68'; ?>%; padding-bottom: 3mm;">
                <div class="hotel"><?php echo $e($company['name']); ?></div>
                <div class="addr"><?php echo $e(implode(' · ', array_filter([$company['address'], $company['phone'], $company['email']]))); ?></div>
            </td>
            <td style="width: 32%; padding-bottom: 3mm;">
                <div class="title">LAPORAN HARIAN</div>
                <div class="date"><?php echo $e($todayDisplay); ?></div>
            </td>
        </tr>
    </table>

    <table class="stats" cellspacing="0">
        <tr>
            <td><span class="v"><?php echo $occupancyRate; ?>%</span><br><span class="l">Occupancy (<?php echo $occupiedRooms . '/' . $totalRooms; ?>)</span></td>
            <td><span class="v"><?php echo count($inHouseGuests); ?></span><br><span class="l">In House</span></td>
            <td><span class="v"><?php echo count($checkInToday); ?></span><br><span class="l">Check-in hari ini</span></td>
            <td><span class="v"><?php echo count($checkOutToday); ?></span><br><span class="l">Check-out hari ini</span></td>
            <td><span class="v"><?php echo count($arrivalTomorrow); ?></span><br><span class="l">Tiba besok</span></td>
            <td><span class="v"><?php echo $breakfastPax; ?></span><br><span class="l">Pax sarapan</span></td>
        </tr>
    </table>

    <?php
    echo $section('Tamu In-House', $inHouseGuests, [
        'Kamar'  => [9, $room],
        'Tamu'   => [37, fn($r) => $e($r['guest_name']) . ($r['type_name'] ? '<br><span class="muted">' . $e($r['type_name']) . '</span>' : '')],
        'Kode'   => [19, fn($r) => $e($r['booking_code'])],
        'Masuk'  => [9, fn($r) => $d($r['check_in_date'])],
        'Keluar' => [9, fn($r) => $d($r['check_out_date'])],
        'Bayar'  => [17, fn($r) => '<span class="' . $r['pay_state'] . '">' . $payStateLabel[$r['pay_state']] . '</span>' . ($r['balance'] > 0 ? '<br><span class="muted">Sisa Rp ' . number_format($r['balance'], 0, ',', '.') . '</span>' : '')],
    ], 'Tidak ada tamu in-house');

    echo $section('Check-in Hari Ini', $checkInToday, [
        'Kamar'  => [9, $room],
        'Tamu'   => [41, fn($r) => $e($r['guest_name'])],
        'Telepon' => [20, fn($r) => $e($r['phone'] ?: '-')],
        'Kode'   => [20, fn($r) => $e($r['booking_code'])],
        'Keluar' => [10, fn($r) => $d($r['check_out_date'])],
    ], 'Tidak ada kedatangan hari ini');

    echo $section('Check-out Hari Ini', $checkOutToday, [
        'Kamar'  => [9, $room],
        'Tamu'   => [51, fn($r) => $e($r['guest_name'])],
        'Kode'   => [20, fn($r) => $e($r['booking_code'])],
        'Masuk'  => [10, fn($r) => $d($r['check_in_date'])],
        'Keluar' => [10, fn($r) => $d($r['check_out_date'])],
    ], 'Tidak ada check-out hari ini');

    echo $section('Check-out Besok', $checkOutTomorrow, [
        'Kamar'  => [9, $room],
        'Tamu'   => [41, fn($r) => $e($r['guest_name'])],
        'Telepon' => [20, fn($r) => $e($r['phone'] ?: '-')],
        'Kode'   => [20, fn($r) => $e($r['booking_code'])],
        'Masuk'  => [10, fn($r) => $d($r['check_in_date'])],
    ], 'Tidak ada check-out besok');

    echo $section('Kedatangan Besok', $arrivalTomorrow, [
        'Kamar'  => [9, $room],
        'Tamu'   => [35, fn($r) => $e($r['guest_name'])],
        'Telepon' => [18, fn($r) => $e($r['phone'] ?: '-')],
        'Kode'   => [20, fn($r) => $e($r['booking_code'])],
        'Pax'    => [8, fn($r) => (int)($r['guest_count'] ?: 1)],
        'Keluar' => [10, fn($r) => $d($r['check_out_date'])],
    ], 'Tidak ada kedatangan besok');

    // Sarapan: rekap kitchen + daftar order
    if ($breakfastOrders) {
        echo '<table class="sec" cellspacing="0"><tr><td class="sec-t">Rekap Sarapan (untuk kitchen)</td><td class="sec-n">' . $breakfastPax . ' pax</td></tr></table>';
        echo '<table class="recap" cellspacing="1mm">';
        foreach (array_chunk($menuRecap, 3, true) as $chunk) {
            echo '<tr>';
            foreach ($chunk as $name => $qty) {
                echo '<td style="width:26%">' . $e($name) . '</td><td class="q" style="width:7.3%">x' . (int)$qty . '</td>';
            }
            for ($i = count($chunk); $i < 3; $i++) echo '<td style="width:26%; border:0; background:#fff"></td><td style="width:7.3%; border:0; background:#fff"></td>';
            echo '</tr>';
        }
        echo '</table>';

        echo $section('Order Sarapan', $breakfastOrders, [
            'Jam'    => [8, fn($o) => $o['breakfast_time'] ? date('H:i', strtotime($o['breakfast_time'])) : '-'],
            'Kamar'  => [11, fn($o) => '<span class="room">' . $e($o['room_number'] ?: '-') . '</span>'],
            'Tamu'   => [25, fn($o) => $e($o['guest_name']) . '<br><span class="muted">' . $e($bfLocationLabel($o['location'] ?? '')) . ' · ' . (int)$o['total_pax'] . ' pax</span>'],
            'Menu'   => [56, fn($o) => implode('<br>', array_map(fn($it) => (int)($it['quantity'] ?? 1) . 'x ' . $e($it['menu_name'] ?? '?') . (!empty($it['note']) ? ' <span class="muted">(' . $e($it['note']) . ')</span>' : ''), $o['menu_items'])) . (!empty($o['special_requests']) ? '<br><span class="muted">Catatan: ' . $e($o['special_requests']) . '</span>' : '')],
        ]);
    }
    ?>
</page>
<?php
$html = ob_get_clean();

/** PDF sebagai string; percobaan kedua tanpa logo bila gambar logo bermasalah. */
$render = static function (string $html): string {
    $pdf = new Html2Pdf('P', 'A4', 'en', true, 'UTF-8', [0, 0, 0, 0]);
    $pdf->setDefaultFont('dejavusans'); // UTF-8 penuh untuk nama tamu
    $pdf->pdf->SetTitle('Laporan Harian ' . date('d M Y'));
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
$fileName = 'Laporan-Harian-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string)$company['name']) . '-' . $today . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $fileName . '"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, no-store');
echo $bytes;
