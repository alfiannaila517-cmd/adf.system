<?php

/**
 * DEPOSIT RECEIPT (cash or identity card) — slip kecil di bagian atas kertas A4 portrait,
 * dengan garis putus-putus untuk dipotong. Data dari booking_deposits (popup Deposit di kalender).
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$depositId = (int)($_GET['id'] ?? 0);

try {
    $dep = $db->fetchOne("
        SELECT d.*, b.booking_code, b.check_in_date, b.check_out_date, b.total_nights,
               g.guest_name, g.phone, r.room_number, rt.type_name AS room_type
        FROM booking_deposits d
        JOIN bookings b ON b.id = d.booking_id
        LEFT JOIN guests g ON g.id = b.guest_id
        LEFT JOIN rooms r ON r.id = b.room_id
        LEFT JOIN room_types rt ON rt.id = r.room_type_id
        WHERE d.id = ?
    ", [$depositId]);
} catch (Exception $e) {
    $dep = null;
}
if (!$dep) {
    die('Deposit not found (deposits are removed automatically after check-out or when the month changes).');
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

/** Amount in English words (whole rupiah). */
function depWords(int $n): string
{
    $ones = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    if ($n < 20) return $ones[$n];
    if ($n < 100) return $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
    if ($n < 1000) return $ones[intdiv($n, 100)] . ' hundred' . ($n % 100 ? ' ' . depWords($n % 100) : '');
    foreach ([1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand'] as $div => $label) {
        if ($n >= $div) return depWords(intdiv($n, $div)) . ' ' . $label . ($n % $div ? ' ' . depWords($n % $div) : '');
    }
    return '';
}

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$isCash = $dep['deposit_type'] === 'cash';
$amount = (int)round((float)$dep['amount']);
$receiptNo = 'DEP/' . date('Ym', strtotime($dep['created_at'])) . '/' . $dep['booking_code'] . '-' . $dep['id'];
$idLabels = ['KTP' => 'KTP (Indonesian ID Card)', 'Passport' => 'Passport', 'SIM' => 'SIM (Driving Licence)', 'Lainnya' => 'Identity Card'];
$idLabel = $idLabels[$dep['id_type']] ?? ($dep['id_type'] ?: 'Identity Card');
$d = fn($x) => $x ? date('d M Y', strtotime($x)) : '-';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deposit Receipt - <?php echo $e($dep['booking_code']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --navy: #0f2747; --gold: #b8913a; --ink: #1e293b; --muted: #64748b; --line: #e2e8f0; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #e9edf3; font-family: 'Inter', system-ui, sans-serif; color: var(--ink); -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .toolbar { position: sticky; top: 0; z-index: 5; display: flex; justify-content: center; gap: 10px; padding: 12px; background: rgba(15, 39, 71, .95); }
        .toolbar button { height: 38px; padding: 0 18px; border: 0; border-radius: 9px; font: 700 13px 'Inter', sans-serif; cursor: pointer; }
        .toolbar .print { background: linear-gradient(135deg, #b8913a, #d6b25e); color: #fff; }
        .toolbar .close { background: rgba(255, 255, 255, .12); color: #fff; }

        /* A4 portrait: slip kecil di atas + garis potong */
        .page { width: 210mm; min-height: 297mm; margin: 18px auto; background: #fff; box-shadow: 0 12px 40px rgba(15, 39, 71, .18); }
        .slip { position: relative; padding: 11mm 12mm 7mm; }
        .slip::before { content: ''; position: absolute; left: 12mm; right: 12mm; top: 7mm; height: 3px; border-radius: 2px; background: linear-gradient(90deg, var(--navy) 70%, var(--gold)); }

        .head { display: flex; align-items: center; gap: 12px; padding: 4mm 0 7px; border-bottom: 1.5px solid var(--navy); }
        .head img { width: 50px; height: 50px; object-fit: contain; flex-shrink: 0; }
        .head .co { flex: 1; min-width: 0; }
        .head .co h1 { font-family: 'Cormorant Garamond', serif; font-size: 20px; font-weight: 700; color: var(--navy); line-height: 1.05; }
        .head .co p { font-size: 8.5px; color: var(--muted); margin-top: 2px; line-height: 1.45; }
        .head .doc { text-align: right; }
        .head .doc h2 { font-family: 'Cormorant Garamond', serif; font-size: 19px; font-weight: 700; color: var(--navy); letter-spacing: .1em; text-transform: uppercase; line-height: 1.05; }
        .head .doc .no { margin-top: 3px; font-size: 9px; font-weight: 700; color: var(--ink); }
        .head .doc .dt { font-size: 8.5px; color: var(--muted); }

        .body { display: grid; grid-template-columns: 1.45fr 1fr; gap: 10px; margin-top: 8px; align-items: start; }
        .rows { border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
        .row { display: grid; grid-template-columns: 104px 1fr; padding: 5px 9px; border-bottom: 1px solid var(--line); font-size: 10px; }
        .row:last-child { border-bottom: 0; }
        .row span { color: var(--muted); font-weight: 600; }
        .row b { color: var(--ink); font-weight: 700; }
        .row.big b { font-size: 11.5px; color: var(--navy); }

        .dep { border: 1px solid #e6d3a3; border-radius: 8px; background: #fffbf2; padding: 8px 10px; }
        .dep .k { font-size: 8px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: var(--gold); }
        .dep .type { margin-top: 1px; font-size: 10.5px; font-weight: 700; color: var(--navy); }
        .dep .amt { margin-top: 5px; font-size: 17px; font-weight: 800; color: var(--navy); letter-spacing: -.01em; }
        .dep .words { margin-top: 3px; font-size: 9px; font-style: italic; font-weight: 600; color: #7a5a17; line-height: 1.4; }
        .dep .idno { margin-top: 2px; font-size: 13px; font-weight: 800; letter-spacing: .05em; color: var(--navy); }
        .dep .note { margin-top: 6px; padding-top: 5px; border-top: 1px dashed #e6d3a3; font-size: 8.5px; color: var(--muted); line-height: 1.45; }
        .remark { margin-top: 6px; font-size: 8.8px; color: var(--muted); line-height: 1.45; }
        .remark b { color: var(--ink); }

        .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 10px; }
        .sign .box { text-align: center; }
        .sign .lbl { font-size: 8.5px; color: var(--muted); }
        .sign .space { height: 34px; border-bottom: 1px solid var(--ink); margin: 0 14px; }
        .sign .who { margin-top: 3px; font-size: 10px; font-weight: 800; color: var(--navy); }
        .sign .role { font-size: 8px; color: var(--muted); letter-spacing: .1em; text-transform: uppercase; }

        .cut { position: relative; margin: 2mm 6mm 0; border-top: 1.5px dashed #94a3b8; }
        .cut span { position: absolute; left: 50%; top: -9px; transform: translateX(-50%); padding: 0 8px; background: #fff; font-size: 9px; font-weight: 700; letter-spacing: .12em; color: #94a3b8; text-transform: uppercase; }

        @page { size: A4 portrait; margin: 0; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .page { margin: 0; box-shadow: none; }
        }
    </style>
</head>

<body>
    <div class="toolbar">
        <button class="print" onclick="window.print()">Print Receipt</button>
        <button class="close" onclick="window.close()">Close</button>
    </div>

    <div class="page">
        <div class="slip">
            <div class="head">
                <?php if ($logoUrl): ?><img src="<?php echo $e($logoUrl); ?>" alt="<?php echo $e($coName); ?>"><?php endif; ?>
                <div class="co">
                    <h1><?php echo $e($coName); ?></h1>
                    <p><?php echo $e($coAddress); ?><br><?php echo $e($coPhone); ?> · <?php echo $e($coEmail); ?></p>
                </div>
                <div class="doc">
                    <h2>Deposit Receipt</h2>
                    <div class="no"><?php echo $e($receiptNo); ?></div>
                    <div class="dt"><?php echo date('d M Y, H:i', strtotime($dep['created_at'])); ?></div>
                </div>
            </div>

            <div class="body">
                <div>
                    <div class="rows">
                        <div class="row big"><span>Received from</span><b><?php echo $e($dep['guest_name'] ?: '-'); ?></b></div>
                        <?php if (!empty($dep['phone'])): ?><div class="row"><span>Phone</span><b><?php echo $e($dep['phone']); ?></b></div><?php endif; ?>
                        <div class="row"><span>Reservation ID</span><b><?php echo $e($dep['booking_code']); ?></b></div>
                        <div class="row"><span>Room</span><b><?php echo $e(trim(($dep['room_number'] ?? '-') . ' · ' . ($dep['room_type'] ?? ''), ' ·')); ?></b></div>
                        <div class="row"><span>Stay</span><b><?php echo $d($dep['check_in_date']); ?> — <?php echo $d($dep['check_out_date']); ?></b></div>
                        <?php if (!empty($dep['notes'])): ?><div class="row"><span>Notes</span><b><?php echo $e($dep['notes']); ?></b></div><?php endif; ?>
                    </div>
                    <div class="remark">This deposit is held as a <b>guarantee during the stay</b> and will be <b>returned at check-out</b> after the room inspection, less any charges for damage, loss or violation of the house rules.</div>
                </div>

                <div class="dep">
                    <div class="k">Deposit Type</div>
                    <?php if ($isCash): ?>
                        <div class="type">Cash</div>
                        <div class="amt">IDR <?php echo number_format($amount, 0, '.', ','); ?></div>
                        <div class="words"><?php echo $e(ucfirst(depWords($amount))); ?> rupiah</div>
                        <div class="note">Kept by the front office and returned in full at check-out.</div>
                    <?php else: ?>
                        <div class="type"><?php echo $e($idLabel); ?></div>
                        <?php if (!empty($dep['id_number'])): ?>
                            <div class="k" style="margin-top:6px;">ID Number</div>
                            <div class="idno"><?php echo $e($dep['id_number']); ?></div>
                        <?php endif; ?>
                        <div class="note">The original document is kept by the front office and returned at check-out.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sign">
                <div class="box">
                    <div class="lbl">Guest</div>
                    <div class="space"></div>
                    <div class="who"><?php echo $e($dep['guest_name'] ?: 'Guest'); ?></div>
                    <div class="role">Depositor</div>
                </div>
                <div class="box">
                    <div class="lbl">Received by, <?php echo $e($coName); ?></div>
                    <div class="space"></div>
                    <div class="who"><?php echo $e($dep['received_by'] ?: 'Front Office'); ?></div>
                    <div class="role">Front Office</div>
                </div>
            </div>
        </div>
        <div class="cut"><span>&#9986; Cut here</span></div>
    </div>

    <script>
        window.addEventListener('load', function() {
            if (new URLSearchParams(location.search).get('autoprint') === '1') setTimeout(function() { window.print(); }, 400);
        });
    </script>
</body>

</html>
