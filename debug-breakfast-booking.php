<?php
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

$today = ((int)date('H') < 10) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');

echo "=== BREAKFAST BOOKING LEVEL DEBUG ===\n";
echo "Today (hotel date): $today\n";
echo "Current time: " . date('Y-m-d H:i:s') . "\n\n";

// Check how many checked-in bookings exist
echo "1. TOTAL CHECKED-IN BOOKINGS:\n";
$total = $pdo->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'checked_in'")->fetch();
echo "   Total: " . $total['count'] . "\n\n";

// Check for same-name guests in different rooms
echo "2. CHECKING FOR DUPLICATE NAMES IN DIFFERENT ROOMS:\n";
$dupNames = $pdo->query("
    SELECT g.guest_name, COUNT(DISTINCT b.id) as booking_count, 
           GROUP_CONCAT(DISTINCT COALESCE(r.room_number, b.room_number) ORDER BY COALESCE(r.room_number, b.room_number) SEPARATOR ', ') as rooms
    FROM bookings b
    JOIN guests g ON b.guest_id = g.id
    LEFT JOIN rooms r ON b.room_id = r.id
    WHERE b.status = 'checked_in'
    GROUP BY g.id, g.guest_name
    HAVING COUNT(DISTINCT b.id) > 1
    ORDER BY g.guest_name
")->fetchAll(PDO::FETCH_ASSOC);

echo "   Found " . count($dupNames) . " guests with multiple bookings:\n";
foreach ($dupNames as $dn) {
    echo "   - " . $dn['guest_name'] . ": " . $dn['booking_count'] . " bookings in rooms " . $dn['rooms'] . "\n";
}
echo "\n";

// Run the ACTUAL query from breakfast.php (per-booking)
echo "3. RUNNING BOOKING-LEVEL QUERY FROM BREAKFAST.PHP:\n";
$stmt = $pdo->prepare("
    SELECT b.id as booking_id, g.id as guest_id, g.guest_name, COALESCE(g.phone,'') as guest_phone,
           COALESCE(r.room_number, b.room_number) as room_number,
           EXISTS(
               SELECT 1
               FROM breakfast_orders bo
               WHERE bo.breakfast_date = ?
                 AND bo.booking_id = b.id
           ) as has_order_today
    FROM bookings b
    JOIN guests g ON b.guest_id = g.id
    LEFT JOIN rooms r ON b.room_id = r.id
    WHERE b.status = 'checked_in'
    ORDER BY has_order_today ASC, COALESCE(r.room_number, b.room_number) ASC, b.id ASC
");
$stmt->execute([$today]);
$bookingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "   Total rows returned: " . count($bookingRows) . "\n";
echo "\n   Listing:\n";
foreach ($bookingRows as $row) {
    $status = $row['has_order_today'] ? '✓ ORDERED' : '  available';
    echo "   [{$status}] Booking #{$row['booking_id']}: {$row['guest_name']} | Room {$row['room_number']}\n";
}
echo "\n";

// Check breakfast_orders for today
echo "4. BREAKFAST ORDERS FOR TODAY:\n";
$orders = $pdo->query("
    SELECT id, booking_id, guest_name, room_number, breakfast_date
    FROM breakfast_orders
    WHERE breakfast_date = '$today'
    ORDER BY booking_id
")->fetchAll(PDO::FETCH_ASSOC);

echo "   Total orders: " . count($orders) . "\n";
foreach ($orders as $o) {
    echo "   Order #{$o['id']}: booking_id={$o['booking_id']}, guest={$o['guest_name']}, room={$o['room_number']}\n";
}
echo "\n";

// Verify PT Indomina specifically if it exists
echo "5. CHECKING PT.INDOMINA SPECIFICALLY:\n";
$pt = $pdo->query("
    SELECT b.id as booking_id, g.guest_name, COALESCE(r.room_number, b.room_number) as room_number, b.status
    FROM bookings b
    JOIN guests g ON b.guest_id = g.id
    LEFT JOIN rooms r ON b.room_id = r.id
    WHERE g.guest_name LIKE '%Indomina%' AND b.status = 'checked_in'
    ORDER BY COALESCE(r.room_number, b.room_number)
")->fetchAll(PDO::FETCH_ASSOC);

if (count($pt) > 0) {
    echo "   Found " . count($pt) . " PT Indomina bookings:\n";
    foreach ($pt as $p) {
        echo "   - Booking #{$p['booking_id']}: Room {$p['room_number']}\n";
    }
} else {
    echo "   No PT Indomina bookings found\n";
}
