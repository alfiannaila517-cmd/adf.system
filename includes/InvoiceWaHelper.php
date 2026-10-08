<?php
/**
 * Invoice booking untuk dikirim lewat WhatsApp: teks template + lampiran PDF.
 * invoiceWaBuild($db, $bookingId) → data, teks (ID/EN), dan byte PDF.
 */
if (!defined('APP_ACCESS')) { http_response_code(403); exit; }

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

/** Teks WhatsApp (bahasa Inggris) berisi ringkasan booking, aturan DP 50% / 24 jam, dan link invoice. */
function invoiceWaText(array $d, string $invoiceUrl = ''): string
{
    $m = $d['main'];
    $co = $d['co'];
    $rp = fn($v) => 'Rp ' . number_format((float)$v, 0, ',', '.');
    $dt = fn($v) => date('d M Y', strtotime((string)$v));
    $types = [];
    foreach ($d['rooms'] as $r) $types[(string)($r['room_type'] ?: 'Room')][] = (string)$r['room_number'];
    $roomTxt = implode(', ', array_map(fn($t, $n) => $t . ' (' . implode(', ', $n) . ')', array_keys($types), $types));
    $dpMin = ceil($d['total'] * 0.5 / 1000) * 1000;
    $dpLeft = max(0, $dpMin - $d['paid']);
    // buang tambahan seperti "(Ana)" / daftar nama panjang dari sapaan
    $name = trim(preg_replace('/\s*[(\[].*$/u', '', (string)$m['guest_name'])) ?: 'Guest';
    $nights = (int)$m['total_nights'];
    $L = [];
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
            $L[] = "🏦 *Bank transfer:* {$co['bank_no']} ({$co['bank_name']})";
        }
    }
    if ($invoiceUrl !== '') {
        $L[] = "";
        $L[] = "📄 *Your invoice* (tap to view, print or save as PDF):";
        $L[] = $invoiceUrl;
    }
    $L[] = "";
    $L[] = "Warm regards,";
    $L[] = $co['name'];
    return implode("\n", $L);
}
