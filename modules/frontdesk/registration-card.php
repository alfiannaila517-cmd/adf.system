<?php

/**
 * GUEST REGISTRATION CARD (dicetak saat check-in)
 * Data tamu & menginap terisi otomatis dari booking; house rules + tanda tangan tamu di bagian bawah.
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();

if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$bookingId = (int)($_GET['booking_id'] ?? 0);
if ($bookingId <= 0) {
    die('Invalid Booking ID');
}

$booking = $db->fetchOne("
    SELECT b.id, b.booking_code, b.check_in_date, b.check_out_date, b.total_nights, b.adults, b.children,
           b.booking_source, b.special_request,
           g.guest_name, g.phone, g.email, g.address, g.nationality, g.id_card_type, g.id_card_number,
           r.room_number, rt.type_name AS room_type
    FROM bookings b
    LEFT JOIN guests g ON g.id = b.guest_id
    LEFT JOIN rooms r ON r.id = b.room_id
    LEFT JOIN room_types rt ON rt.id = r.room_type_id
    WHERE b.id = ?
", [$bookingId]);
if (!$booking) {
    die('Booking not found');
}

// Identitas hotel (sama dengan invoice)
$logoRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", ['invoice_logo_' . ACTIVE_BUSINESS_ID]);
$logoUrl = $logoRow['setting_value'] ?? null;
if (empty($logoUrl) && function_exists('getBusinessLogo')) {
    $logoUrl = getBusinessLogo();
}
$company = [];
foreach ($db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'company_%'") ?: [] as $row) {
    if (strpos($row['setting_key'], 'company_logo_') === 0) continue;
    $company[str_replace('company_', '', $row['setting_key'])] = $row['setting_value'];
}
$business = $db->fetchOne("SELECT business_name FROM businesses WHERE id = ?", [$_SESSION['business_id'] ?? 1]) ?: [];
$coName = $company['name'] ?? ($business['business_name'] ?? 'Narayana Hotel');
$coAddress = $company['address'] ?? 'Jl. Kasimo Jatikerep, Karimunjawa, Jepara, Jawa Tengah 59455';
$coPhone = $company['phone'] ?? '081222228590';
$coEmail = $company['email'] ?? 'narayanahotelkarimunjawa@gmail.com';
$coWebsite = $company['website'] ?? 'www.narayanakarimunjawa.com';

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmtDate = fn($d) => $d ? date('l, d F Y', strtotime($d)) : '-';
$nights = (int)($booking['total_nights'] ?: max(1, (int)((strtotime($booking['check_out_date']) - strtotime($booking['check_in_date'])) / 86400)));
$adults = (int)($booking['adults'] ?? 1);
$children = (int)($booking['children'] ?? 0);
$guestsText = $adults . ' Adult' . ($adults === 1 ? '' : 's') . ($children > 0 ? ', ' . $children . ' Child' . ($children === 1 ? '' : 'ren') : '');

// Baris data tamu; Address/City & Nationality hanya tampil bila diisi
$guestRows = [
    ['Full Name', $booking['guest_name'] ?: '-'],
    ['Phone Number', $booking['phone'] ?: '-'],
    ['Email', $booking['email'] ?: '-'],
];
if (trim((string)$booking['address']) !== '') $guestRows[] = ['City / Address', $booking['address']];
if (trim((string)$booking['nationality']) !== '') $guestRows[] = ['Nationality', $booking['nationality']];
if (trim((string)$booking['id_card_number']) !== '') $guestRows[] = [strtoupper((string)($booking['id_card_type'] ?: 'ID')) . ' Number', $booking['id_card_number']];

$houseRules = [
    ['Security Deposit', 'A security deposit of <b>IDR 500,000</b> or a valid <b>ID card</b> is required upon check-in and will be returned at check-out after the room inspection.'],
    ['No Smoking', 'Smoking inside the room is strictly prohibited. A penalty of <b>IDR 500,000</b> will be charged.'],
    ['No Open-Flame Cooking', 'Cooking with fire or any open flame inside the room is not allowed. A penalty of <b>IDR 1,000,000</b> will be charged.'],
    ['Room Key', 'A lost room key will be charged <b>IDR 200,000</b>.'],
    ['Check-in &amp; Check-out', 'Check-in time is from <b>14:00</b>, and check-out time is no later than <b>10:30</b>.'],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Card - <?php echo $e($booking['booking_code']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --navy: #0f2747; --gold: #b8913a; --ink: #1e293b; --muted: #64748b; --line: #e2e8f0; --soft: #f8fafc; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #e9edf3; font-family: 'Inter', system-ui, sans-serif; color: var(--ink); -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .toolbar { position: sticky; top: 0; z-index: 5; display: flex; justify-content: center; gap: 10px; padding: 12px; background: rgba(15, 39, 71, .95); }
        .toolbar button { height: 38px; padding: 0 18px; border: 0; border-radius: 9px; font: 700 13px 'Inter', sans-serif; cursor: pointer; }
        .toolbar .print { background: linear-gradient(135deg, #b8913a, #d6b25e); color: #fff; }
        .toolbar .close { background: rgba(255, 255, 255, .12); color: #fff; }
        .page { width: 210mm; min-height: 297mm; margin: 18px auto; padding: 14mm 15mm 12mm; background: #fff; box-shadow: 0 12px 40px rgba(15, 39, 71, .18); display: flex; flex-direction: column; }

        /* Kop */
        .head { display: flex; align-items: center; gap: 16px; padding-bottom: 12px; border-bottom: 2px solid var(--navy); position: relative; }
        .head::after { content: ''; position: absolute; left: 0; right: 0; bottom: -6px; height: 1px; background: var(--gold); }
        .head img { width: 64px; height: 64px; object-fit: contain; }
        .head .co { flex: 1; }
        .head .co h1 { font-family: 'Cormorant Garamond', serif; font-size: 27px; font-weight: 700; color: var(--navy); letter-spacing: .02em; line-height: 1.05; }
        .head .co p { font-size: 10px; color: var(--muted); margin-top: 3px; line-height: 1.5; }
        .head .doc { text-align: right; }
        .head .doc .t { font-size: 10px; font-weight: 800; letter-spacing: .22em; color: var(--gold); text-transform: uppercase; }
        .head .doc .n { font-size: 17px; font-weight: 800; color: var(--navy); margin-top: 2px; }
        .head .doc .d { font-size: 10px; color: var(--muted); margin-top: 2px; }

        .title { text-align: center; margin: 18px 0 14px; }
        .title h2 { font-family: 'Cormorant Garamond', serif; font-size: 25px; font-weight: 700; color: var(--navy); letter-spacing: .14em; text-transform: uppercase; }
        .title span { display: inline-block; margin-top: 4px; font-size: 10.5px; color: var(--muted); letter-spacing: .05em; }

        .sec { margin-bottom: 12px; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
        .sec-h { display: flex; align-items: center; gap: 8px; padding: 7px 12px; background: var(--navy); color: #fff; font-size: 10.5px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
        .sec-h i { width: 6px; height: 6px; border-radius: 50%; background: var(--gold); display: inline-block; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; }
        .f { padding: 8px 12px; border-bottom: 1px solid var(--line); border-right: 1px solid var(--line); min-height: 44px; }
        .grid .f:nth-child(2n) { border-right: 0; }
        .f.wide { grid-column: 1 / -1; border-right: 0; }
        .f label { display: block; font-size: 8.5px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); }
        .f div { margin-top: 3px; font-size: 13px; font-weight: 700; color: var(--ink); word-break: break-word; }
        .grid .f:nth-last-child(-n+2) { border-bottom: 0; }

        .stay { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; }
        .stay .f { border-bottom: 0; }
        .stay .f:last-child { border-right: 0; }
        .stay .hi div { color: var(--navy); }
        .stay small { display: block; margin-top: 2px; font-size: 9.5px; font-weight: 600; color: var(--gold); }

        .dep { display: grid; grid-template-columns: 1fr 1.3fr; }
        .dep-opts { padding: 10px 12px; border-right: 1px solid var(--line); display: flex; flex-direction: column; gap: 9px; }
        .dep-opts label { display: flex; align-items: center; gap: 8px; font-size: 11.5px; font-weight: 600; color: var(--ink); }
        .dep-opts .cb { width: 14px; height: 14px; border: 1.5px solid var(--navy); border-radius: 3px; flex-shrink: 0; }
        .dep-opts .amt { margin-top: 2px; }
        .dep-opts .line { flex: 1; border-bottom: 1px dotted #94a3b8; height: 14px; }
        .notes { padding: 10px 12px; }
        .notes label { display: block; font-size: 8.5px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); }
        .notes .req { margin-top: 4px; font-size: 11.5px; font-weight: 600; color: var(--ink); line-height: 1.45; }
        .notes .ln { height: 22px; border-bottom: 1px dotted #94a3b8; }
        .rules { margin-top: auto; border: 1px solid #e8dcc0; border-radius: 10px; background: linear-gradient(180deg, #fffdf7, #fff); padding: 12px 14px; }
        .rules h3 { display: flex; align-items: center; gap: 10px; font-family: 'Cormorant Garamond', serif; font-size: 19px; font-weight: 700; color: var(--navy); letter-spacing: .06em; }
        .rules h3::after { content: ''; flex: 1; height: 1px; background: linear-gradient(90deg, var(--gold), transparent); }
        .rules ol { list-style: none; margin-top: 8px; display: grid; gap: 6px; counter-reset: r; }
        .rules li { counter-increment: r; display: grid; grid-template-columns: 22px 1fr; gap: 8px; font-size: 10.8px; line-height: 1.5; color: #334155; }
        .rules li::before { content: counter(r); width: 20px; height: 20px; border-radius: 50%; background: var(--navy); color: #fff; font-size: 10px; font-weight: 800; display: grid; place-items: center; margin-top: 1px; }
        .rules li strong { color: var(--navy); }
        .rules li b { color: #9a6b12; }
        .ack { margin-top: 10px; font-size: 10px; font-style: italic; color: var(--muted); line-height: 1.5; }

        .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 18px; }
        .sign .box { text-align: center; }
        .sign .space { height: 58px; border-bottom: 1px solid var(--ink); }
        .sign .who { margin-top: 6px; font-size: 11px; font-weight: 800; color: var(--navy); }
        .sign .role { font-size: 9.5px; color: var(--muted); letter-spacing: .1em; text-transform: uppercase; }
        .foot { margin-top: 12px; padding-top: 8px; border-top: 1px solid var(--line); display: flex; justify-content: space-between; font-size: 9px; color: var(--muted); }

        @page { size: A4; margin: 0; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .page { margin: 0; box-shadow: none; width: 210mm; height: 297mm; }
        }
    </style>
</head>

<body>
    <div class="toolbar">
        <button class="print" onclick="window.print()">Print Registration Card</button>
        <button class="close" onclick="window.close()">Close</button>
    </div>

    <div class="page">
        <div class="head">
            <?php if ($logoUrl): ?><img src="<?php echo $e($logoUrl); ?>" alt="<?php echo $e($coName); ?>"><?php endif; ?>
            <div class="co">
                <h1><?php echo $e($coName); ?></h1>
                <p><?php echo $e($coAddress); ?><br>Phone <?php echo $e($coPhone); ?> · <?php echo $e($coEmail); ?> · <?php echo $e($coWebsite); ?></p>
            </div>
            <div class="doc">
                <div class="t">Reservation ID</div>
                <div class="n"><?php echo $e($booking['booking_code']); ?></div>
                <div class="d">Printed <?php echo date('d M Y, H:i'); ?></div>
            </div>
        </div>

        <div class="title">
            <h2>Guest Registration Card</h2>
            <span>Please review your details and sign below</span>
        </div>

        <div class="sec">
            <div class="sec-h"><i></i> Guest Information</div>
            <div class="grid">
                <?php foreach ($guestRows as $i => [$label, $val]): ?>
                    <div class="f<?php echo (count($guestRows) % 2 === 1 && $i === count($guestRows) - 1) ? ' wide' : ''; ?>">
                        <label><?php echo $e($label); ?></label>
                        <div><?php echo $e($val); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="sec">
            <div class="sec-h"><i></i> Stay Details</div>
            <div class="stay">
                <div class="f hi"><label>Room Type</label><div><?php echo $e($booking['room_type'] ?: '-'); ?></div></div>
                <div class="f hi"><label>Room Number</label><div><?php echo $e($booking['room_number'] ?: '-'); ?></div></div>
                <div class="f"><label>Nights</label><div><?php echo $nights; ?> Night<?php echo $nights === 1 ? '' : 's'; ?></div></div>
                <div class="f"><label>Guests</label><div><?php echo $e($guestsText); ?></div></div>
            </div>
            <div class="grid" style="border-top:1px solid var(--line);">
                <div class="f"><label>Check-in Date</label><div><?php echo $e($fmtDate($booking['check_in_date'])); ?></div><small style="display:block;margin-top:2px;font-size:9.5px;font-weight:600;color:var(--gold);">From 14:00</small></div>
                <div class="f"><label>Check-out Date</label><div><?php echo $e($fmtDate($booking['check_out_date'])); ?></div><small style="display:block;margin-top:2px;font-size:9.5px;font-weight:600;color:var(--gold);">Before 10:30</small></div>
            </div>
        </div>

        <div class="sec">
            <div class="sec-h"><i></i> Deposit &amp; Notes</div>
            <div class="dep">
                <div class="dep-opts">
                    <label><span class="cb"></span> Cash Deposit IDR 500,000</label>
                    <label><span class="cb"></span> ID Card / Passport held</label>
                    <label class="amt">Amount received: <span class="line"></span></label>
                </div>
                <div class="notes">
                    <label>Special Request / Notes</label>
                    <?php if (trim((string)$booking['special_request']) !== ''): ?><div class="req"><?php echo nl2br($e($booking['special_request'])); ?></div><?php endif; ?>
                    <div class="ln"></div><div class="ln"></div>
                </div>
            </div>
        </div>

        <div class="rules">
            <h3>House Rules · <?php echo $e($coName); ?></h3>
            <ol>
                <?php foreach ($houseRules as [$t, $txt]): ?>
                    <li><span><strong><?php echo $t; ?>.</strong> <?php echo $txt; ?></span></li>
                <?php endforeach; ?>
            </ol>
            <p class="ack">By signing this registration card, I confirm that the information above is correct and I agree to comply with the house rules of <?php echo $e($coName); ?>. I accept responsibility for any charges arising from damage, loss or violation of these rules during my stay.</p>

            <div class="sign">
                <div class="box">
                    <div class="space"></div>
                    <div class="who"><?php echo $e($booking['guest_name'] ?: 'Guest'); ?></div>
                    <div class="role">Guest Signature</div>
                </div>
                <div class="box">
                    <div class="space"></div>
                    <div class="who"><?php echo $e($currentUser['full_name'] ?? 'Front Office'); ?></div>
                    <div class="role">Front Office</div>
                </div>
            </div>
        </div>

        <div class="foot">
            <span>Thank you for choosing <?php echo $e($coName); ?>. We wish you a pleasant stay.</span>
            <span><?php echo $e($booking['booking_code']); ?></span>
        </div>
    </div>

    <script>
        // Langsung buka dialog cetak (ditunda sedikit agar font & logo termuat)
        window.addEventListener('load', function() {
            if (new URLSearchParams(location.search).get('autoprint') === '1') setTimeout(function() { window.print(); }, 400);
        });
    </script>
</body>

</html>
