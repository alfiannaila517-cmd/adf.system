<?php
// One-off diagnostic: compare raw booking/payment data vs. what the
// notification banner (UnpaidGuestNotificationHelper) actually computes.
// Delete this file after use.
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/UnpaidGuestNotificationHelper.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$pdo = $db->getConnection();

$search = $_GET['q'] ?? 'Hilda';
$code = $_GET['code'] ?? null;

header('Content-Type: text/plain; charset=utf-8');

echo "=== RAW bookings + payments for guest LIKE '%$search%' ===\n\n";

$sql = "
    SELECT b.id, b.booking_code, b.group_id, b.status, b.payment_status,
           b.final_price, b.paid_amount, g.guest_name, r.room_number,
           b.actual_checkin_time,
           (SELECT COALESCE(SUM(amount),0) FROM booking_payments WHERE booking_id = b.id) AS bp_sum
    FROM bookings b
    LEFT JOIN guests g ON b.guest_id = g.id
    LEFT JOIN rooms r ON b.room_id = r.id
    WHERE g.guest_name LIKE ?
    ORDER BY b.group_id, r.room_number
";
$stmt = $pdo->prepare($sql);
$stmt->execute(["%$search%"]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalFinal = 0;
$totalPaid = 0;
foreach ($rows as $r) {
    $effectivePaid = $r['bp_sum'] > 0 ? $r['bp_sum'] : $r['paid_amount'];
    $totalFinal += (float)$r['final_price'];
    $totalPaid += (float)$effectivePaid;
    printf(
        "id=%-5s code=%-20s group=%-25s status=%-12s pay_status=%-10s room=%-6s final=%12s paid_amount=%12s bp_sum=%12s checkin=%s\n",
        $r['id'],
        $r['booking_code'],
        $r['group_id'],
        $r['status'],
        $r['payment_status'],
        $r['room_number'],
        number_format($r['final_price'], 0, ',', '.'),
        number_format($r['paid_amount'], 0, ',', '.'),
        number_format($r['bp_sum'], 0, ',', '.'),
        $r['actual_checkin_time']
    );
}
echo "\nTOTAL final_price=" . number_format($totalFinal, 0, ',', '.') . " | TOTAL effective paid=" . number_format($totalPaid, 0, ',', '.') . " | TOTAL remaining=" . number_format($totalFinal - $totalPaid, 0, ',', '.') . "\n";

echo "\n=== booking_payments rows for these booking ids ===\n\n";
$ids = array_column($rows, 'id');
if ($ids) {
    $in = implode(',', array_map('intval', $ids));
    $bp = $pdo->query("SELECT * FROM booking_payments WHERE booking_id IN ($in) ORDER BY booking_id, id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($bp as $p) {
        print_r($p);
    }
    if (!$bp) echo "(no booking_payments rows found for these ids)\n";
}

echo "\n=== What the NOTIFICATION BANNER actually computes right now ===\n\n";
$unpaidGuests = getUnpaidCheckedInGuests($pdo);
$messages = formatUnpaidGuestMessages($unpaidGuests);
echo "Raw getUnpaidCheckedInGuests() rows matching '$search':\n";
foreach ($unpaidGuests as $g) {
    if (stripos($g['guest_name'], $search) !== false) {
        print_r($g);
    }
}
echo "\nFormatted messages containing '$search':\n";
foreach ($messages as $m) {
    if (stripos($m, $search) !== false) echo $m . "\n";
}

echo "\nTotal unpaidGuests rows fetched (LIMIT 50 applies): " . count($unpaidGuests) . "\n";
