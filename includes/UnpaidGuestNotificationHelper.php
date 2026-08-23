<?php

/**
 * Header Notification Banner - Unpaid Checked-in Guests
 * Display running text notification for guests who checked in without full payment
 * Include in includes/header.php
 */

function getUnpaidCheckedInGuests($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT
                b.id,
                b.booking_code,
                b.final_price,
                b.paid_amount,
                g.guest_name,
                r.room_number,
                COALESCE(bp.total_paid, b.paid_amount, 0) AS total_paid
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN (
                SELECT booking_id, SUM(amount) AS total_paid
                FROM booking_payments
                GROUP BY booking_id
            ) bp ON bp.booking_id = b.id
            WHERE b.status = 'checked_in'
            AND b.payment_status != 'paid'
            ORDER BY b.actual_checkin_time ASC
            LIMIT 15
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log("Unpaid checked-in guests query failed: " . $e->getMessage());
        return [];
    }
}

function formatUnpaidGuestMessages($unpaidGuests)
{
    if (empty($unpaidGuests)) {
        return [];
    }

    $messages = [];
    foreach ($unpaidGuests as $guest) {
        $total = (float)($guest['final_price'] ?? 0);
        $paid = (float)($guest['total_paid'] ?? 0);
        $remaining = max(0, $total - $paid);

        $messages[] = "💰 Room {$guest['room_number']} — {$guest['guest_name']} — BELUM LUNAS (Sisa Rp " . number_format($remaining, 0, ',', '.') . ") — #{$guest['booking_code']}";
    }

    return $messages;
}
