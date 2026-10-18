<?php

/**
 * API publik: kirim permintaan reservasi dari website.
 * Disimpan sebagai PENAWARAN di ADF (quotations, source='website') — kamar belum dikunci.
 * Admin menerima popup + WhatsApp + email, menghubungi tamu, menerima DP, lalu mengonfirmasi di ADF.
 *
 * POST JSON: check_in, check_out, room_type_id, rooms, adults, children, name, phone, email, notes, website (honeypot)
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$_cfg = dirname(__DIR__) . '/config/config.php';
if (!file_exists($_cfg)) $_cfg = dirname(__DIR__, 2) . '/config/config.php';
require_once $_cfg;
require_once dirname(__DIR__) . '/includes/booking-engine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) {
        throw new Exception('Invalid request.');
    }
    // Anti-bot: kolom tersembunyi harus kosong, dan formulir tidak boleh dikirim terlalu cepat
    if (!empty($in['website'])) {
        throw new Exception('Invalid submission.');
    }
    if (isset($in['t']) && (time() * 1000 - (int)$in['t']) < 4000) {
        throw new Exception('Please take a moment to review your details, then try again.');
    }

    $ci = (string)($in['check_in'] ?? '');
    $co = (string)($in['check_out'] ?? '');
    if (!nrbValidDate($ci) || !nrbValidDate($co) || $co <= $ci || $ci < date('Y-m-d')) {
        throw new Exception('Please choose valid dates.');
    }
    $nights = (int)round((strtotime($co) - strtotime($ci)) / 86400);
    if ($nights > 30) {
        throw new Exception('For stays longer than 30 nights, please contact us via WhatsApp.');
    }
    $typeId = (int)($in['room_type_id'] ?? 0);
    $qty = max(1, min(10, (int)($in['rooms'] ?? 1)));
    $adults = max(1, min(40, (int)($in['adults'] ?? 1)));
    $children = max(0, min(20, (int)($in['children'] ?? 0)));

    $name = trim((string)($in['name'] ?? ''));
    $phone = preg_replace('/[^\d+]/', '', (string)($in['phone'] ?? ''));
    $email = trim((string)($in['email'] ?? ''));
    $notes = mb_substr(trim(strip_tags((string)($in['notes'] ?? ''))), 0, 500);
    if (mb_strlen($name) < 2 || mb_strlen($name) > 80 || !preg_match('/^[\p{L}\p{M}\x27\.\-\s]+$/u', $name) || preg_match('/https?:\/\/|www\./i', $name)) {
        throw new Exception('Please enter your full name (letters only).');
    }
    if (strlen(ltrim($phone, '+')) < 8 || strlen(ltrim($phone, '+')) > 15) {
        throw new Exception('Please enter a valid WhatsApp number.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please enter a valid email address.');
    }
    if (preg_match('/https?:\/\/|www\./i', $notes)) {
        throw new Exception('Links are not allowed in the special request.');
    }

    $ip = substr((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    nrbEnsureQuoteColumns($pdo);
    // Batas wajar: maksimal 3 permintaan per jam dari alamat yang sama
    $recent = dbFetch("SELECT COUNT(*) c FROM quotations WHERE source = 'website' AND client_ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)", [$ip]);
    if ((int)($recent['c'] ?? 0) >= 3) {
        throw new Exception('We have received several requests from you. Please contact us via WhatsApp to continue.');
    }

    // Cek ulang ketersediaan saat dikirim
    $type = null;
    foreach (nrbAvailability($ci, $co) as $t) {
        if ($t['id'] === $typeId) $type = $t;
    }
    if (!$type) {
        throw new Exception('Room type not found.');
    }
    if ($type['available'] < $qty) {
        throw new Exception($type['available'] > 0 ? 'Only ' . $type['available'] . ' ' . $type['name'] . ' room(s) left for these dates.' : 'Sorry, ' . $type['name'] . ' is fully booked for these dates.');
    }
    if ($adults > $type['max'] * $qty) {
        throw new Exception('Too many guests for ' . $qty . ' room(s) — maximum ' . ($type['max'] * $qty) . ' adults. Please add a room.');
    }

    $roomIds = array_slice($type['room_ids'], 0, $qty);
    $price = (float)$type['price'];
    $lineTotal = round($price * $nights, 2);
    $total = $lineTotal * $qty;

    $pdo->beginTransaction();
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code = 'WEB-' . date('ymd') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        if (!dbFetch("SELECT id FROM quotations WHERE quote_code = ?", [$code])) break;
    }
    $st = $pdo->prepare("INSERT INTO quotations (quote_code, guest_name, guest_phone, guest_email, notes, check_in, check_out, nights, valid_until, status, subtotal, discount_total, total, extras_total, lang, source, adults, children, client_ip)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, 0, ?, 0, 'en', 'website', ?, ?, ?)");
    $st->execute([$code, $name, $phone, $email, $notes, $ci, $co, $nights, $ci, $total, $total, $adults, $children, $ip]);
    $qid = (int)$pdo->lastInsertId();
    $it = $pdo->prepare("INSERT INTO quotation_items (quote_id, room_id, room_number, room_type, base_price, disc_type, disc_value, net_price, line_total) VALUES (?, ?, ?, ?, ?, '', 0, ?, ?)");
    foreach ($roomIds as $rid) {
        $rn = dbFetch("SELECT room_number FROM rooms WHERE id = ?", [$rid]);
        $it->execute([$qid, $rid, (string)($rn['room_number'] ?? ''), $type['name'], $price, $price, $lineTotal]);
    }
    $pdo->commit();

    nrbNotifyAdf($qid);

    $dp = round($total * 0.5, -3);
    $waText = "Hi Narayana, I just sent a reservation request ({$code}) for {$qty} {$type['name']} room(s), " . date('d M Y', strtotime($ci)) . ' - ' . date('d M Y', strtotime($co)) . '. My name is ' . $name . '.';
    echo json_encode([
        'success' => true,
        'code' => $code,
        'room' => $type['name'],
        'rooms' => $qty,
        'nights' => $nights,
        'total' => $total,
        'dp' => $dp,
        'whatsapp' => 'https://wa.me/' . BUSINESS_WHATSAPP . '?text=' . rawurlencode($waText),
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
