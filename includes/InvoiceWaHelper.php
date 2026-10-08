<?php
/**
 * Invoice booking untuk dikirim lewat WhatsApp: teks template + lampiran PDF.
 * invoiceWaBuild($db, $bookingId) → data, teks (ID/EN), dan byte PDF.
 */
if (!defined('APP_ACCESS')) { http_response_code(403); exit; }

use Spipu\Html2Pdf\Html2Pdf;

function invoiceWaCollect($db, int $bookingId): ?array
{
    $q = "SELECT b.id, b.booking_code, b.check_in_date, b.check_out_date, b.room_price, b.total_price, b.final_price, COALESCE(b.discount, 0) discount,
            b.status, b.total_nights, b.paid_amount, b.adults, b.children, b.created_at, b.group_id, b.booking_source,
            g.guest_name, g.phone, g.email, r.room_number, rt.type_name AS room_type
        FROM bookings b LEFT JOIN guests g ON g.id = b.guest_id LEFT JOIN rooms r ON r.id = b.room_id LEFT JOIN room_types rt ON rt.id = r.room_type_id";
    $main = $db->fetchOne($q . " WHERE b.id = ?", [$bookingId]);
    if (!$main) return null;
    $rooms = !empty($main['group_id'])
        ? ($db->fetchAll($q . " WHERE b.group_id = ? AND b.status <> 'cancelled' ORDER BY r.room_number", [$main['group_id']]) ?: [])
        : [$main];
    if (!$rooms) $rooms = [$main];
    $ids = array_map(fn($r) => (int)$r['id'], $rooms);
    $in = implode(',', $ids);
    $paid = (float)($db->fetchOne("SELECT COALESCE(SUM(amount), 0) s FROM booking_payments WHERE booking_id IN ($in)")['s'] ?? 0);
    if ($paid <= 0) $paid = array_sum(array_map(fn($r) => (float)$r['paid_amount'], $rooms));
    $total = array_sum(array_map(fn($r) => (float)$r['final_price'], $rooms));
    $gross = array_sum(array_map(fn($r) => (float)$r['total_price'], $rooms));
    $disc = array_sum(array_map(fn($r) => (float)$r['discount'], $rooms));
    $extras = [];
    try {
        $extras = $db->fetchAll("SELECT item_name AS description, quantity, unit_price, total_price FROM booking_extras WHERE booking_id IN ($in) ORDER BY id") ?: [];
    } catch (\Throwable $e) {
    }
    $get = function (string $k, string $default = '') use ($db): string {
        try {
            $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1", [$k]);
            return trim((string)($row['setting_value'] ?? '')) !== '' ? (string)$row['setting_value'] : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    };
    $biz = defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : '';
    $co = [
        'name' => $get('company_name', defined('BUSINESS_NAME') ? BUSINESS_NAME : 'Narayana Hotel'),
        'address' => $get('company_address', 'Jl. Kasimo Jatikerep, Karimunjawa, Jepara, Jawa Tengah 59455'),
        'phone' => $get('company_phone', '081222228590'),
        'email' => $get('company_email', 'narayanahotelkarimunjawa@gmail.com'),
        'bank_no' => $get('invoice_bank_account_number_' . $biz, '1926663992'),
        'bank_name' => $get('invoice_bank_account_name_' . $biz, 'BNI Jepara'),
    ];
    return ['main' => $main, 'rooms' => $rooms, 'total' => $total, 'gross' => $gross, 'discount' => $disc, 'paid' => $paid,
        'remaining' => max(0, $total - $paid), 'extras' => $extras, 'co' => $co];
}

/** Teks WhatsApp. Bahasa Indonesia untuk nomor +62, selain itu Inggris. */
function invoiceWaText(array $d, string $phone, string $pdfUrl = ''): string
{
    $m = $d['main'];
    $co = $d['co'];
    $id = preg_match('/^(\+?62|0)\d/', preg_replace('/[\s\-()]/', '', $phone)) === 1 || $phone === '';
    $rp = fn($v) => 'Rp ' . number_format((float)$v, 0, ',', '.');
    $dt = fn($v) => date('d M Y', strtotime((string)$v));
    $types = [];
    foreach ($d['rooms'] as $r) $types[(string)($r['room_type'] ?: 'Room')][] = (string)$r['room_number'];
    $roomTxt = implode(', ', array_map(fn($t, $n) => $t . ' (' . implode(', ', $n) . ')', array_keys($types), $types));
    $dpMin = ceil($d['total'] * 0.5 / 1000) * 1000;
    $dpLeft = max(0, $dpMin - $d['paid']);
    $name = trim(preg_replace('/\s*[(\[].*$/u', '', (string)$m['guest_name'])) ?: ($id ? 'Tamu' : 'Guest'); // buang tambahan seperti "(Ana)" / daftar nama
    $nights = (int)$m['total_nights'];
    $L = [];
    if ($id) {
        $L[] = "Halo {$name},";
        $L[] = "";
        $L[] = "Terima kasih telah memilih {$co['name']}. Berikut invoice reservasi Anda:";
        $L[] = "";
        $L[] = "📋 *Booking ID:* {$m['booking_code']}";
        $L[] = "🛏️ *Tipe kamar:* {$roomTxt}";
        $L[] = "📅 *Check-in:* " . $dt($m['check_in_date']);
        $L[] = "📅 *Check-out:* " . $dt($m['check_out_date']) . " ({$nights} malam)";
        $L[] = "💰 *Total:* " . $rp($d['total']);
        if ($d['paid'] > 0) $L[] = "✅ *Sudah dibayar:* " . $rp($d['paid']);
        $L[] = "";
        if ($d['total'] > 0 && $d['paid'] >= $d['total']) {
            $L[] = "Pembayaran Anda sudah lunas. Kami menantikan kedatangan Anda 🙏";
        } elseif ($d['paid'] >= $dpMin && $dpMin > 0) {
            $L[] = "DP sudah kami terima. Sisa pembayaran *" . $rp($d['remaining']) . "* dapat dilunasi saat check-in.";
        } else {
            $L[] = "Untuk mengamankan reservasi, mohon melakukan *DP minimal 50%* (" . $rp($dpLeft) . ") dalam waktu *24 jam*. Jika belum ada pembayaran dalam waktu tersebut, blok kamar akan kami buka kembali.";
            if ($co['bank_no'] !== '') {
                $L[] = "";
                $L[] = "🏦 Transfer ke: *{$co['bank_no']}* a.n. {$co['bank_name']}";
            }
        }
        if ($pdfUrl !== '') { $L[] = ""; $L[] = "📄 *Invoice (PDF):*"; $L[] = $pdfUrl; }
        $L[] = "";
        $L[] = "Terima kasih 🙏";
        $L[] = $co['name'];
    } else {
        $L[] = "Hello {$name},";
        $L[] = "";
        $L[] = "Thank you for choosing {$co['name']}. Please find your reservation invoice below:";
        $L[] = "";
        $L[] = "📋 *Booking ID:* {$m['booking_code']}";
        $L[] = "🛏️ *Room type:* {$roomTxt}";
        $L[] = "📅 *Check-in:* " . $dt($m['check_in_date']);
        $L[] = "📅 *Check-out:* " . $dt($m['check_out_date']) . " ({$nights} night" . ($nights === 1 ? '' : 's') . ")";
        $L[] = "💰 *Total:* " . $rp($d['total']);
        if ($d['paid'] > 0) $L[] = "✅ *Paid:* " . $rp($d['paid']);
        $L[] = "";
        if ($d['total'] > 0 && $d['paid'] >= $d['total']) {
            $L[] = "Your reservation is fully paid. We look forward to welcoming you 🙏";
        } elseif ($d['paid'] >= $dpMin && $dpMin > 0) {
            $L[] = "We have received your deposit. The remaining *" . $rp($d['remaining']) . "* can be settled upon check-in.";
        } else {
            $L[] = "To secure your reservation, a *down payment of at least 50%* (" . $rp($dpLeft) . ") is required within *24 hours*. If no payment is received within this time, the room block will be released.";
            if ($co['bank_no'] !== '') {
                $L[] = "";
                $L[] = "🏦 Bank transfer: *{$co['bank_no']}* ({$co['bank_name']})";
            }
        }
        if ($pdfUrl !== '') { $L[] = ""; $L[] = "📄 *Invoice (PDF):*"; $L[] = $pdfUrl; }
        $L[] = "";
        $L[] = "Warm regards,";
        $L[] = $co['name'];
    }
    return implode("\n", $L);
}

/** PDF invoice sederhana & rapi (Html2Pdf). */
function invoiceWaPdf(array $d): string
{
    $m = $d['main'];
    $co = $d['co'];
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $rp = fn($v) => 'Rp ' . number_format((float)$v, 0, ',', '.');
    $dt = fn($v) => date('d M Y', strtotime((string)$v));
    $status = $d['total'] > 0 && $d['paid'] >= $d['total'] ? 'PAID' : ($d['paid'] > 0 ? 'DOWN PAYMENT' : 'UNPAID');
    $statusColor = $status === 'PAID' ? '#047857' : ($status === 'UNPAID' ? '#b91c1c' : '#b45309');
    $invNo = 'INV/' . date('Y/m', strtotime((string)$m['created_at'])) . '/' . $m['booking_code'];
    $rows = '';
    foreach ($d['rooms'] as $r) {
        $rows .= '<tr><td style="width:46mm;border-bottom:1px solid #e2e8f0;padding:2mm 1mm">Room ' . $e($r['room_number']) . '<br><span style="color:#64748b;font-size:8pt">' . $e($r['room_type'] ?: 'Room') . '</span></td>'
            . '<td style="width:44mm;border-bottom:1px solid #e2e8f0;padding:2mm 1mm">' . $dt($r['check_in_date']) . ' &rarr; ' . $dt($r['check_out_date']) . '</td>'
            . '<td style="width:16mm;border-bottom:1px solid #e2e8f0;padding:2mm 1mm;text-align:center">' . (int)$r['total_nights'] . '</td>'
            . '<td style="width:34mm;border-bottom:1px solid #e2e8f0;padding:2mm 1mm;text-align:right">' . $rp($r['total_price']) . '</td></tr>';
    }
    foreach ($d['extras'] as $x) {
        $rows .= '<tr><td colspan="3" style="border-bottom:1px solid #e2e8f0;padding:2mm 1mm">' . $e($x['description'] ?? 'Extra') . ((int)($x['quantity'] ?? 1) > 1 ? ' &times; ' . (int)$x['quantity'] : '') . '</td>'
            . '<td style="border-bottom:1px solid #e2e8f0;padding:2mm 1mm;text-align:right">' . $rp($x['total_price'] ?? 0) . '</td></tr>';
    }
    $tot = function ($label, $val, $bold = false, $color = '#0f172a') use ($e) {
        return '<tr><td style="padding:1.2mm 1mm;text-align:right;color:#475569' . ($bold ? ';font-weight:bold' : '') . '">' . $e($label) . '</td><td style="width:40mm;padding:1.2mm 1mm;text-align:right;color:' . $color . ($bold ? ';font-weight:bold;font-size:11pt' : '') . '">' . $val . '</td></tr>';
    };
    $dpMin = ceil($d['total'] * 0.5 / 1000) * 1000;
    $noteDp = ($d['paid'] < $dpMin && $d['total'] > 0)
        ? '<div style="margin-top:6mm;padding:3mm;background:#fffbeb;border:1px solid #fde68a;font-size:9pt;color:#92400e">A down payment of at least 50% (' . $rp(max(0, $dpMin - $d['paid'])) . ') is required within 24 hours to secure this reservation. If no payment is received, the room block will be released.<br>Dibutuhkan DP minimal 50% dalam 24 jam; bila belum ada pembayaran, blok kamar akan dibuka kembali.</div>'
        : '';
    $bank = $co['bank_no'] !== '' ? '<div style="margin-top:4mm;font-size:9pt;color:#334155"><b>Bank transfer:</b> ' . $e($co['bank_no']) . ' &middot; ' . $e($co['bank_name']) . '</div>' : '';
    $html = '<page backtop="12mm" backbottom="12mm" backleft="14mm" backright="14mm"><div style="font-family:freesans;font-size:10pt;color:#0f172a">'
        . '<table style="width:100%"><tr><td style="width:110mm"><div style="font-size:17pt;font-weight:bold;color:#1e3a8a">' . $e($co['name']) . '</div>'
        . '<div style="font-size:8.5pt;color:#64748b;margin-top:1mm">' . $e($co['address']) . '<br>' . $e($co['phone']) . ' &middot; ' . $e($co['email']) . '</div></td>'
        . '<td style="width:66mm;text-align:right"><div style="font-size:20pt;font-weight:bold;color:#1e3a8a">INVOICE</div>'
        . '<div style="font-size:9pt;color:#475569">' . $e($invNo) . '<br>Issued ' . $dt($m['created_at']) . '</div>'
        . '<div style="margin-top:2mm;font-size:10pt;font-weight:bold;color:' . $statusColor . '">' . $status . '</div></td></tr></table>'
        . '<div style="height:1px;background:#1e3a8a;margin:5mm 0"></div>'
        . '<table style="width:100%"><tr><td style="width:88mm;vertical-align:top"><div style="font-size:8pt;color:#94a3b8">BILLED TO</div><div style="font-size:12pt;font-weight:bold">' . $e($m['guest_name']) . '</div>'
        . '<div style="font-size:9pt;color:#475569">' . $e($m['phone'] ?? '') . ($m['email'] ? '<br>' . $e($m['email']) : '') . '</div></td>'
        . '<td style="width:88mm;vertical-align:top"><div style="font-size:8pt;color:#94a3b8">BOOKING</div><div style="font-size:12pt;font-weight:bold">' . $e($m['booking_code']) . '</div>'
        . '<div style="font-size:9pt;color:#475569">' . (int)$m['adults'] . ' adult(s)' . ((int)$m['children'] > 0 ? ', ' . (int)$m['children'] . ' child' : '') . '</div></td></tr></table>'
        . '<table style="width:100%;margin-top:6mm;border-collapse:collapse"><tr style="background:#1e3a8a;color:#ffffff;font-size:8.5pt">'
        . '<td style="padding:2mm 1mm;width:46mm">ROOM</td><td style="padding:2mm 1mm;width:44mm">STAY</td><td style="padding:2mm 1mm;width:16mm;text-align:center">NIGHTS</td><td style="padding:2mm 1mm;width:34mm;text-align:right">AMOUNT</td></tr>'
        . $rows . '</table>'
        . '<table style="width:100%;margin-top:3mm"><tr><td style="width:100mm"></td><td><table style="width:100%">'
        . ($d['discount'] > 0 ? $tot('Subtotal', $rp($d['gross'])) . $tot('Discount', '- ' . $rp($d['discount']), false, '#b91c1c') : '')
        . $tot('Total', $rp($d['total']), true)
        . $tot('Paid', $rp($d['paid']), false, '#047857')
        . $tot('Balance due', $rp($d['remaining']), true, $d['remaining'] > 0 ? '#b91c1c' : '#047857')
        . '</table></td></tr></table>'
        . $noteDp . $bank
        . '<div style="margin-top:10mm;font-size:8.5pt;color:#94a3b8;text-align:center">Thank you for choosing ' . $e($co['name']) . '</div>'
        . '</div></page>';
    if (defined('INVWA_HTML_ONLY')) return $html; // untuk pratinjau/uji tampilan
    require_once __DIR__ . '/../vendor/autoload.php';
    $pdf = new Html2Pdf('P', 'A4', 'en', true, 'UTF-8', [0, 0, 0, 0]);
    $pdf->setDefaultFont('freesans');
    $pdf->pdf->SetTitle('Invoice ' . $m['booking_code']);
    $pdf->writeHTML($html);
    return $pdf->output('invoice.pdf', 'S');
}
