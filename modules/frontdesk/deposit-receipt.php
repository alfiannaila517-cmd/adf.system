<?php

/**
 * TANDA TERIMA DEPOSIT (uang tunai atau kartu identitas) — A5 landscape.
 * Data dari booking_deposits (dibuat lewat popup Deposit di kalender).
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
    die('Data deposit tidak ditemukan (sudah terhapus otomatis setelah check-out / ganti bulan).');
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

/** Terbilang (bahasa Indonesia). */
function depTerbilang(int $n): string
{
    $w = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($n < 12) return $w[$n];
    if ($n < 20) return depTerbilang($n - 10) . ' belas';
    if ($n < 100) return trim(depTerbilang(intdiv($n, 10)) . ' puluh ' . depTerbilang($n % 10));
    if ($n < 200) return trim('seratus ' . depTerbilang($n - 100));
    if ($n < 1000) return trim(depTerbilang(intdiv($n, 100)) . ' ratus ' . depTerbilang($n % 100));
    if ($n < 2000) return trim('seribu ' . depTerbilang($n - 1000));
    if ($n < 1000000) return trim(depTerbilang(intdiv($n, 1000)) . ' ribu ' . depTerbilang($n % 1000));
    if ($n < 1000000000) return trim(depTerbilang(intdiv($n, 1000000)) . ' juta ' . depTerbilang($n % 1000000));
    return trim(depTerbilang(intdiv($n, 1000000000)) . ' miliar ' . depTerbilang($n % 1000000000));
}

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$isCash = $dep['deposit_type'] === 'cash';
$amount = (int)round((float)$dep['amount']);
$receiptNo = 'DEP/' . date('Ym', strtotime($dep['created_at'])) . '/' . $dep['booking_code'] . '-' . $dep['id'];
$idLabels = ['KTP' => 'KTP (Kartu Tanda Penduduk)', 'Passport' => 'Passport / Paspor', 'SIM' => 'SIM (Surat Izin Mengemudi)'];
$idLabel = $idLabels[$dep['id_type']] ?? ($dep['id_type'] ?: 'Kartu Identitas');
$d = fn($x) => $x ? date('d M Y', strtotime($x)) : '-';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tanda Terima Deposit - <?php echo $e($dep['booking_code']); ?></title>
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
        .page { position: relative; width: 210mm; height: 148mm; margin: 18px auto; padding: 9mm 11mm 8mm; background: #fff; box-shadow: 0 12px 40px rgba(15, 39, 71, .18); display: flex; flex-direction: column; overflow: hidden; }
        .page::before { content: ''; position: absolute; inset: 0 0 auto 0; height: 5px; background: linear-gradient(90deg, var(--navy) 70%, var(--gold)); }

        .head { display: flex; align-items: center; gap: 14px; padding-bottom: 8px; border-bottom: 1.5px solid var(--navy); }
        .head img { width: 66px; height: 66px; object-fit: contain; flex-shrink: 0; }
        .head .co { flex: 1; min-width: 0; }
        .head .co h1 { font-family: 'Cormorant Garamond', serif; font-size: 23px; font-weight: 700; color: var(--navy); line-height: 1.05; }
        .head .co p { font-size: 9px; color: var(--muted); margin-top: 2px; line-height: 1.45; }
        .head .doc { text-align: right; }
        .head .doc h2 { font-family: 'Cormorant Garamond', serif; font-size: 21px; font-weight: 700; color: var(--navy); letter-spacing: .08em; text-transform: uppercase; line-height: 1.05; }
        .head .doc .en { font-size: 8.5px; font-weight: 700; letter-spacing: .22em; color: var(--gold); text-transform: uppercase; }
        .head .doc .no { margin-top: 4px; font-size: 9.5px; font-weight: 700; color: var(--ink); }

        .body { display: grid; grid-template-columns: 1.35fr 1fr; gap: 12px; margin-top: 10px; flex: 1; min-height: 0; }
        .rows { display: flex; flex-direction: column; gap: 0; border: 1px solid var(--line); border-radius: 9px; overflow: hidden; }
        .row { display: grid; grid-template-columns: 112px 1fr; padding: 6px 10px; border-bottom: 1px solid var(--line); font-size: 10.5px; }
        .row:last-child { border-bottom: 0; }
        .row span { color: var(--muted); font-weight: 600; }
        .row b { color: var(--ink); font-weight: 700; }
        .row.big b { font-size: 13px; color: var(--navy); }

        .dep { border: 1.5px solid var(--gold); border-radius: 10px; background: linear-gradient(180deg, #fffaf0, #fff); padding: 10px 12px; display: flex; flex-direction: column; }
        .dep .k { font-size: 8.5px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; color: var(--gold); }
        .dep .type { margin-top: 2px; font-size: 12px; font-weight: 800; color: var(--navy); }
        .dep .amt { margin-top: 6px; font-family: 'Cormorant Garamond', serif; font-size: 30px; font-weight: 700; color: var(--navy); line-height: 1; }
        .dep .words { margin-top: 5px; padding: 5px 8px; border-radius: 6px; background: #f8f1e1; font-size: 9.5px; font-style: italic; font-weight: 600; color: #7a5a17; text-transform: capitalize; line-height: 1.4; }
        .dep .idno { margin-top: 6px; font-size: 15px; font-weight: 800; letter-spacing: .06em; color: var(--navy); }
        .dep .note { margin-top: auto; padding-top: 6px; font-size: 8.8px; color: var(--muted); line-height: 1.45; }

        .remark { margin-top: 8px; font-size: 9px; color: var(--muted); line-height: 1.45; }
        .remark b { color: var(--ink); }

        .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 6px; }
        .sign .box { text-align: center; position: relative; }
        .sign .lbl { font-size: 9px; color: var(--muted); }
        .sign .space { height: 42px; border-bottom: 1px solid var(--ink); margin: 0 10px; position: relative; }
        .sign .who { margin-top: 4px; font-size: 10.5px; font-weight: 800; color: var(--navy); }
        .sign .role { font-size: 8.5px; color: var(--muted); letter-spacing: .1em; text-transform: uppercase; }
        .stamp { position: absolute; right: 6px; bottom: 4px; width: 62px; height: 62px; border: 1.5px dashed #cbd5e1; border-radius: 50%; display: grid; place-items: center; text-align: center; font-size: 7px; font-weight: 700; letter-spacing: .1em; color: #94a3b8; text-transform: uppercase; line-height: 1.3; }

        @page { size: A5 landscape; margin: 0; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .page { margin: 0; box-shadow: none; }
        }
    </style>
</head>

<body>
    <div class="toolbar">
        <button class="print" onclick="window.print()">Print Tanda Terima</button>
        <button class="close" onclick="window.close()">Close</button>
    </div>

    <div class="page">
        <div class="head">
            <?php if ($logoUrl): ?><img src="<?php echo $e($logoUrl); ?>" alt="<?php echo $e($coName); ?>"><?php endif; ?>
            <div class="co">
                <h1><?php echo $e($coName); ?></h1>
                <p><?php echo $e($coAddress); ?><br><?php echo $e($coPhone); ?> · <?php echo $e($coEmail); ?></p>
            </div>
            <div class="doc">
                <h2>Tanda Terima</h2>
                <div class="en">Deposit Receipt</div>
                <div class="no"><?php echo $e($receiptNo); ?></div>
            </div>
        </div>

        <div class="body">
            <div>
                <div class="rows">
                    <div class="row big"><span>Telah terima dari</span><b><?php echo $e($dep['guest_name'] ?: '-'); ?></b></div>
                    <?php if (!empty($dep['phone'])): ?><div class="row"><span>No. Telepon</span><b><?php echo $e($dep['phone']); ?></b></div><?php endif; ?>
                    <div class="row"><span>Reservation ID</span><b><?php echo $e($dep['booking_code']); ?></b></div>
                    <div class="row"><span>Kamar</span><b><?php echo $e(trim(($dep['room_number'] ?? '-') . ' · ' . ($dep['room_type'] ?? ''), ' ·')); ?></b></div>
                    <div class="row"><span>Check-in / out</span><b><?php echo $d($dep['check_in_date']); ?> — <?php echo $d($dep['check_out_date']); ?></b></div>
                    <div class="row"><span>Tanggal terima</span><b><?php echo date('d M Y, H:i', strtotime($dep['created_at'])); ?></b></div>
                    <?php if (!empty($dep['notes'])): ?><div class="row"><span>Keterangan</span><b><?php echo $e($dep['notes']); ?></b></div><?php endif; ?>
                </div>
                <div class="remark">Deposit ini merupakan <b>jaminan selama menginap</b> dan akan <b>dikembalikan saat check-out</b> setelah pemeriksaan kamar, dikurangi biaya kerusakan, kehilangan atau pelanggaran house rules bila ada.</div>
            </div>

            <div class="dep">
                <div class="k">Jenis Deposit</div>
                <?php if ($isCash): ?>
                    <div class="type">Uang Tunai (Cash)</div>
                    <div class="amt">Rp <?php echo number_format($amount, 0, ',', '.'); ?></div>
                    <div class="words"># <?php echo $e(depTerbilang($amount)); ?> rupiah #</div>
                    <div class="note">Uang deposit disimpan oleh front office dan dikembalikan utuh saat check-out.</div>
                <?php else: ?>
                    <div class="type"><?php echo $e($idLabel); ?></div>
                    <?php if (!empty($dep['id_number'])): ?>
                        <div class="k" style="margin-top:8px;">Nomor Identitas</div>
                        <div class="idno"><?php echo $e($dep['id_number']); ?></div>
                    <?php endif; ?>
                    <div class="note">Kartu identitas asli disimpan oleh front office sebagai jaminan dan dikembalikan saat check-out.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="sign">
            <div class="box">
                <div class="lbl">Penyetor / Guest</div>
                <div class="space"></div>
                <div class="who"><?php echo $e($dep['guest_name'] ?: 'Guest'); ?></div>
                <div class="role">Tamu</div>
            </div>
            <div class="box">
                <div class="lbl">Hormat kami, <?php echo $e($coName); ?></div>
                <div class="space"><div class="stamp">Stempel<br>Hotel</div></div>
                <div class="who"><?php echo $e($dep['received_by'] ?: 'Front Office'); ?></div>
                <div class="role">Front Office</div>
            </div>
        </div>
    </div>

    <script>
        window.addEventListener('load', function() {
            if (new URLSearchParams(location.search).get('autoprint') === '1') setTimeout(function() { window.print(); }, 400);
        });
    </script>
</body>

</html>
