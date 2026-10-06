<?php

/**
 * FRONT DESK - LAPORAN HARIAN: pengambilan data bersama.
 * Dipakai oleh laporan.php (tampilan) dan laporan-pdf.php (file PDF) agar isinya selalu sama.
 * Membutuhkan $db (Database) dari pemanggil.
 */
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit;
}

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$todayDisplay = date('l, d F Y');
$company = getCompanyInfo();

$totalRooms = 0;
$occupiedRooms = 0;
$occupancyRate = 0;
$inHouseGuests = $checkInToday = $checkOutToday = $checkOutTomorrow = $arrivalTomorrow = [];
$breakfastOrders = [];
$menuRecap = [];

try {
    // 1. Occupancy
    $totalRooms = (int)($db->fetchOne("SELECT COUNT(*) AS total FROM rooms WHERE status != 'maintenance'")['total'] ?? 0);
    $occupiedRooms = (int)($db->fetchOne("SELECT COUNT(DISTINCT room_id) AS occupied FROM bookings WHERE status = 'checked_in'")['occupied'] ?? 0);
    $occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 1) : 0;

    // 2. Tamu in-house + status bayar dari pembayaran yang tercatat (booking grup: total gabungan),
    //    sama dengan halaman In-House — kolom payment_status per kamar bisa keliru untuk grup.
    $inHouseGuests = $db->fetchAll("SELECT
            b.id AS booking_id, b.booking_code, b.group_id, b.final_price, b.paid_amount,
            g.guest_name, r.room_number, rt.type_name, b.check_in_date, b.check_out_date
        FROM bookings b
        INNER JOIN guests g ON b.guest_id = g.id
        INNER JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE b.status = 'checked_in'
        ORDER BY r.room_number ASC") ?: [];

    if ($inHouseGuests) {
        $paidSub = "SELECT booking_id, SUM(amount) AS total_paid FROM booking_payments GROUP BY booking_id";
        $ids = array_map('intval', array_column($inHouseGuests, 'booking_id'));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $paidById = [];
        foreach ($db->fetchAll("SELECT b.id, GREATEST(COALESCE(bp.total_paid, 0), COALESCE(b.paid_amount, 0)) AS paid
                FROM bookings b LEFT JOIN ({$paidSub}) bp ON bp.booking_id = b.id WHERE b.id IN ({$ph})", $ids) ?: [] as $row) {
            $paidById[(int)$row['id']] = (float)$row['paid'];
        }
        $groupIds = array_values(array_unique(array_filter(array_column($inHouseGuests, 'group_id'))));
        $groupTotals = [];
        if ($groupIds) {
            $gph = implode(',', array_fill(0, count($groupIds), '?'));
            foreach ($db->fetchAll("SELECT b.group_id, SUM(b.final_price) AS final_total,
                        SUM(GREATEST(COALESCE(bp.total_paid, 0), COALESCE(b.paid_amount, 0))) AS paid_total
                    FROM bookings b LEFT JOIN ({$paidSub}) bp ON bp.booking_id = b.id
                    WHERE b.group_id IN ({$gph}) AND b.status <> 'cancelled' GROUP BY b.group_id", $groupIds) ?: [] as $gRow) {
                $groupTotals[$gRow['group_id']] = $gRow;
            }
        }
        foreach ($inHouseGuests as &$ig) {
            if (!empty($ig['group_id']) && isset($groupTotals[$ig['group_id']])) {
                $final = (float)$groupTotals[$ig['group_id']]['final_total'];
                $paid = (float)$groupTotals[$ig['group_id']]['paid_total'];
            } else {
                $final = (float)$ig['final_price'];
                $paid = $paidById[(int)$ig['booking_id']] ?? 0.0;
            }
            $ig['balance'] = max(0, $final - $paid);
            $ig['pay_state'] = $ig['balance'] <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
        }
        unset($ig);
    }

    // 3. Check-in hari ini (belum check-in)
    $checkInToday = $db->fetchAll("SELECT b.booking_code, g.guest_name, g.phone, r.room_number, b.check_in_date, b.check_out_date
        FROM bookings b
        INNER JOIN guests g ON b.guest_id = g.id
        INNER JOIN rooms r ON b.room_id = r.id
        WHERE DATE(b.check_in_date) = ? AND b.status IN ('confirmed', 'pending')
        ORDER BY r.room_number ASC", [$today]) ?: [];

    // 4. Check-out hari ini (masih in-house)
    $checkOutToday = $db->fetchAll("SELECT b.booking_code, g.guest_name, r.room_number, b.check_in_date, b.check_out_date
        FROM bookings b
        INNER JOIN guests g ON b.guest_id = g.id
        INNER JOIN rooms r ON b.room_id = r.id
        WHERE b.check_out_date = ? AND b.status = 'checked_in'
        ORDER BY r.room_number ASC", [$today]) ?: [];

    // 5. Check-out besok
    $checkOutTomorrow = $db->fetchAll("SELECT b.booking_code, g.guest_name, g.phone, r.room_number, b.check_in_date, b.check_out_date
        FROM bookings b
        INNER JOIN guests g ON b.guest_id = g.id
        INNER JOIN rooms r ON b.room_id = r.id
        WHERE b.check_out_date = ? AND b.status = 'checked_in'
        ORDER BY r.room_number ASC", [$tomorrow]) ?: [];

    // 6. Kedatangan besok
    $arrivalTomorrow = $db->fetchAll("SELECT b.booking_code, g.guest_name, g.phone, r.room_number, b.check_in_date, b.check_out_date, b.guest_count
        FROM bookings b
        INNER JOIN guests g ON b.guest_id = g.id
        INNER JOIN rooms r ON b.room_id = r.id
        WHERE b.check_in_date = ? AND b.status IN ('confirmed', 'pending')
        ORDER BY r.room_number ASC", [$tomorrow]) ?: [];

    // 7. Order sarapan hari ini (order terakhir per tamu/kamar)
    try {
        $breakfastOrders = $db->fetchAll("SELECT bo.* FROM breakfast_orders bo
            WHERE bo.breakfast_date = ?
            AND bo.id = (
                SELECT MAX(bo2.id) FROM breakfast_orders bo2
                WHERE bo2.guest_name = bo.guest_name
                  AND bo2.breakfast_date = bo.breakfast_date
                  AND bo2.room_number = bo.room_number
            )
            ORDER BY bo.breakfast_time ASC, bo.id ASC", [$today]) ?: [];
        foreach ($breakfastOrders as &$bfOrder) {
            $bfOrder['menu_items'] = json_decode($bfOrder['menu_items'], true) ?: [];
            $decodedRoom = json_decode($bfOrder['room_number'], true);
            if (is_array($decodedRoom)) {
                $bfOrder['room_number'] = implode(', ', $decodedRoom);
            }
        }
        unset($bfOrder);
    } catch (Exception $e) {
    }

    // Rekap total per menu (untuk kitchen)
    foreach ($breakfastOrders as $order) {
        foreach ($order['menu_items'] as $item) {
            $menuName = trim($item['menu_name'] ?? '');
            if ($menuName === '') continue;
            $menuRecap[$menuName] = ($menuRecap[$menuName] ?? 0) + (int)($item['quantity'] ?? 1);
        }
    }
    arsort($menuRecap);
} catch (Exception $e) {
    error_log('Laporan Error: ' . $e->getMessage());
    $error = $e->getMessage();
}

$bfLocationLabel = static function (?string $loc): string {
    return $loc === 'take_away' ? 'Take Away' : ($loc === 'room_service' ? 'Room Service' : 'Restaurant');
};
$payStateLabel = ['paid' => 'Lunas', 'partial' => 'DP', 'unpaid' => 'Belum bayar'];
$breakfastPax = array_sum(array_map(fn($o) => (int)($o['total_pax'] ?? 0), $breakfastOrders));
