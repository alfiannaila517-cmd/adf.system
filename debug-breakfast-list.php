<?php
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

$today = ((int)date('H') < 10) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');

echo "=== BREAKFAST DEBUG ===\n";
echo "Today (hotel date): $today\n";
echo "Current time: " . date('Y-m-d H:i:s') . "\n";
echo "Hour: " . date('H') . " (< 10? " . ((int)date('H') < 10 ? 'YES' : 'NO') . ")\n\n";

// 1. Check how many checked-in bookings exist
echo "1. CHECKED-IN BOOKINGS:\n";
$result = $pdo->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'checked_in'")->fetch();
echo "   Total: " . $result['count'] . "\n\n";

// 2. Check sample checked-in guests
echo "2. SAMPLE CHECKED-IN GUESTS:\n";
$guests = $pdo->query("
    SELECT b.id as booking_id, g.guest_name, b.room_number, r.room_number as room_from_table, b.status
    FROM bookings b
    JOIN guests g ON b.guest_id = g.id
    LEFT JOIN rooms r ON b.room_id = r.id
    WHERE b.status = 'checked_in'
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($guests as $g) {
    echo "   Booking " . $g['booking_id'] . ": " . $g['guest_name'] .
        " | Room: " . ($g['room_from_table'] ?? $g['room_number']) . "\n";
}
echo "\n";

// 3. Check breakfast orders for today
echo "3. BREAKFAST ORDERS FOR TODAY ($today):\n";
$orders = $pdo->query("
    SELECT id, booking_id, guest_name, room_number, breakfast_date
    FROM breakfast_orders
    WHERE breakfast_date = '$today'
")->fetchAll(PDO::FETCH_ASSOC);

echo "   Total orders today: " . count($orders) . "\n";
foreach ($orders as $o) {
    echo "   Order " . $o['id'] . ": booking_id=" . ($o['booking_id'] ?? 'NULL') .
        ", guest=" . $o['guest_name'] . ", room=" . $o['room_number'] . "\n";
}
echo "\n";

// 4. Run the actual query from breakfast.php
echo "4. RUNNING ACTUAL GUEST LIST QUERY:\n";
$stmt = $pdo->prepare("
    SELECT g.id as guest_id, g.guest_name, COALESCE(g.phone,'') as guest_phone,
           GROUP_CONCAT(DISTINCT COALESCE(r.room_number, b.room_number) ORDER BY COALESCE(r.room_number, b.room_number) SEPARATOR ',') as rooms,
           GROUP_CONCAT(DISTINCT b.id ORDER BY b.id SEPARATOR ',') as booking_ids
    FROM bookings b
    JOIN guests g ON b.guest_id = g.id
    LEFT JOIN rooms r ON b.room_id = r.id
    LEFT JOIN breakfast_orders bo ON b.id = bo.booking_id AND bo.breakfast_date = ?
    WHERE b.status = 'checked_in'
    AND bo.id IS NULL
    GROUP BY g.id, g.guest_name, g.phone
    ORDER BY MIN(COALESCE(r.room_number, b.room_number)) ASC
");
$stmt->execute([$today]);
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "   Result count: " . count($result) . "\n";
foreach ($result as $r) {
    echo "   " . $r['guest_name'] . " | Rooms: " . $r['rooms'] . " | Booking IDs: " . $r['booking_ids'] . "\n";
}
echo "\n";

// 5. Try WITHOUT the breakfast_orders LEFT JOIN
echo "5. WITHOUT breakfast_orders JOIN (all checked-in):\n";
$stmt2 = $pdo->prepare("
    SELECT g.id as guest_id, g.guest_name, 
           GROUP_CONCAT(DISTINCT COALESCE(r.room_number, b.room_number) ORDER BY COALESCE(r.room_number, b.room_number) SEPARATOR ',') as rooms,
           GROUP_CONCAT(DISTINCT b.id ORDER BY b.id SEPARATOR ',') as booking_ids
    FROM bookings b
    JOIN guests g ON b.guest_id = g.id
    LEFT JOIN rooms r ON b.room_id = r.id
    WHERE b.status = 'checked_in'
    GROUP BY g.id, g.guest_name, g.phone
    ORDER BY MIN(COALESCE(r.room_number, b.room_number)) ASC
");
$stmt2->execute([]);
$result2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

echo "   Result count: " . count($result2) . "\n";
foreach ($result2 as $r) {
    echo "   " . $r['guest_name'] . " | Rooms: " . $r['rooms'] . " | Booking IDs: " . $r['booking_ids'] . "\n";
}
