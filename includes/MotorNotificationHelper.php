<?php

/**
 * Header Notification Banner - Motor Overdue Tracking
 * Display running text notification for motors not returned >24hrs
 * Include in includes/header.php
 */

// Get motors overdue (>24 hours without return confirmation after payment)
function getOverdueMotorsForNotification($pdo, $businessId = 1)
{
    try {
        $stmt = $pdo->prepare("
            SELECT 
                rb.id,
                rb.guest_name,
                rm.motor_name,
                rm.plate_number,
                TIMESTAMPDIFF(HOUR, rb.payment_date, NOW()) as hours_overdue
            FROM rental_motor_bookings rb
            JOIN rental_motors rm ON rb.motor_id = rm.id
            WHERE rb.business_id = ?
            AND rb.status = 'active'
            AND rb.return_confirmed = 0
            AND rb.payment_date IS NOT NULL
            AND TIMESTAMPDIFF(HOUR, rb.payment_date, NOW()) >= 24
            ORDER BY rb.payment_date ASC
            LIMIT 10
        ");
        $stmt->execute([$businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log("Overdue motors query failed: " . $e->getMessage());
        return [];
    }
}

// Format notification messages
function formatOverdueMotorMessages($overdueMotors)
{
    if (empty($overdueMotors)) {
        return [];
    }

    $messages = [];
    foreach ($overdueMotors as $motor) {
        $hoursOverdue = $motor['hours_overdue'] ?? 0;
        $daysOverdue = floor($hoursOverdue / 24);
        $hoursLeft = $hoursOverdue % 24;

        $timeStr = $daysOverdue > 0 ? "{$daysOverdue} hari {$hoursLeft} jam" : "{$hoursOverdue} jam";

        $messages[] = "⚠️ {$motor['motor_name']} ({$motor['plate_number']}) belum dikembalikan - {$timeStr} overdue! Guest: {$motor['guest_name']}";
    }

    return $messages;
}
