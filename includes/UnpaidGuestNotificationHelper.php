<?php

/**
 * Header Notification Banner - Unpaid Checked-in Guests
 * Display running text notification for guests who checked in without full payment
 * Include in includes/header.php
 */

/**
 * Tamu in-house yang HARI INI terakhir menginap (atau sudah lewat tanggal check-out) dan masih
 * punya sisa tagihan. Sisa dihitung dari pembayaran yang benar-benar tercatat (booking_payments /
 * paid_amount), bukan kolom payment_status: booking grup yang sudah lunas secara gabungan bisa
 * masih berstatus 'unpaid' per kamar. Untuk grup, sisa = total gabungan semua kamar - total bayar.
 */
function getUnpaidCheckedInGuests($pdo)
{
    try {
        $paidSub = "SELECT booking_id, SUM(amount) AS total_paid FROM booking_payments GROUP BY booking_id";
        $stmt = $pdo->prepare("
            SELECT
                b.id,
                b.booking_code,
                b.group_id,
                b.final_price,
                g.guest_name,
                r.room_number,
                GREATEST(COALESCE(bp.total_paid, 0), COALESCE(b.paid_amount, 0)) AS total_paid
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN ({$paidSub}) bp ON bp.booking_id = b.id
            WHERE b.status = 'checked_in'
              AND b.check_out_date <= ?
            ORDER BY b.check_out_date ASC, r.room_number ASC
            LIMIT 50
        ");
        $stmt->execute([date('Y-m-d')]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Sisa tagihan gabungan per grup (semua kamar grup yang tidak dibatalkan).
        $groupIds = array_values(array_unique(array_filter(array_column($rows, 'group_id'))));
        $groupRemaining = [];
        if ($groupIds) {
            $ph = implode(',', array_fill(0, count($groupIds), '?'));
            $gStmt = $pdo->prepare("
                SELECT b.group_id,
                       SUM(b.final_price) AS total_final,
                       SUM(GREATEST(COALESCE(bp.total_paid, 0), COALESCE(b.paid_amount, 0))) AS total_paid
                FROM bookings b
                LEFT JOIN ({$paidSub}) bp ON bp.booking_id = b.id
                WHERE b.group_id IN ({$ph}) AND b.status <> 'cancelled'
                GROUP BY b.group_id
            ");
            $gStmt->execute($groupIds);
            foreach ($gStmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
                $groupRemaining[$g['group_id']] = max(0, (float)$g['total_final'] - (float)$g['total_paid']);
            }
        }

        $result = [];
        $groupCounted = [];
        foreach ($rows as $row) {
            if (!empty($row['group_id'])) {
                $remaining = $groupRemaining[$row['group_id']] ?? 0;
                // Sisa grup dihitung sekali saja; kamar lain di grup tetap ikut sebagai daftar kamar.
                $row['remaining'] = isset($groupCounted[$row['group_id']]) ? 0 : $remaining;
                $groupCounted[$row['group_id']] = true;
                if ($remaining <= 0) {
                    continue;
                }
            } else {
                $row['remaining'] = max(0, (float)$row['final_price'] - (float)$row['total_paid']);
                if ($row['remaining'] <= 0) {
                    continue;
                }
            }
            $result[] = $row;
        }
        return $result;
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

    // Gabungkan tamu dengan nama persis sama jadi satu baris (total tagihan digabung)
    $grouped = [];
    foreach ($unpaidGuests as $guest) {
        $name = trim($guest['guest_name'] ?? '-');
        $key = mb_strtolower($name);
        $remaining = isset($guest['remaining'])
            ? (float)$guest['remaining']
            : max(0, (float)($guest['final_price'] ?? 0) - (float)($guest['total_paid'] ?? 0));

        if (!isset($grouped[$key])) {
            $grouped[$key] = ['name' => $name, 'rooms' => [], 'remaining' => 0];
        }
        $grouped[$key]['rooms'][] = $guest['room_number'];
        $grouped[$key]['remaining'] += $remaining;
    }

    $messages = [];
    foreach ($grouped as $g) {
        $roomLabel = count($g['rooms']) > 1
            ? count($g['rooms']) . ' Kamar (' . implode(', ', $g['rooms']) . ')'
            : 'Room ' . $g['rooms'][0];
        $messages[] = "💰 {$roomLabel} — {$g['name']} — check-out hari ini, BELUM LUNAS (Sisa Rp " . number_format($g['remaining'], 0, ',', '.') . ")";
    }

    return $messages;
}

function getUnpaidHotelServiceInvoices($pdo, $businessId)
{
    try {
        $stmt = $pdo->prepare("
            SELECT invoice_number, guest_name, room_number, total, paid_amount
            FROM hotel_invoices
            WHERE business_id = ?
            AND payment_status != 'paid'
            AND status != 'cancelled'
            ORDER BY created_at ASC
            LIMIT 50
        ");
        $stmt->execute([$businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log("Unpaid hotel service invoices query failed: " . $e->getMessage());
        return [];
    }
}

function formatUnpaidHotelServiceMessages($unpaidInvoices)
{
    if (empty($unpaidInvoices)) {
        return [];
    }

    // Gabungkan tamu dengan nama persis sama jadi satu baris (total tagihan digabung)
    $grouped = [];
    foreach ($unpaidInvoices as $invoice) {
        $name = trim($invoice['guest_name'] ?? '-');
        $key = mb_strtolower($name);
        $remaining = max(0, (float)($invoice['total'] ?? 0) - (float)($invoice['paid_amount'] ?? 0));

        if (!isset($grouped[$key])) {
            $grouped[$key] = ['name' => $name, 'rooms' => [], 'remaining' => 0];
        }
        if (!empty($invoice['room_number'])) {
            $grouped[$key]['rooms'][] = $invoice['room_number'];
        }
        $grouped[$key]['remaining'] += $remaining;
    }

    $messages = [];
    foreach ($grouped as $g) {
        $rooms = array_unique($g['rooms']);
        $roomLabel = count($rooms) > 1
            ? count($rooms) . ' Kamar (' . implode(', ', $rooms) . ')'
            : (count($rooms) === 1 ? 'Room ' . reset($rooms) : '-');
        $messages[] = "🛎️ {$roomLabel} — {$g['name']} — Hotel Service BELUM LUNAS (Sisa Rp " . number_format($g['remaining'], 0, ',', '.') . ")";
    }

    return $messages;
}

// Unpaid cafe invoices (Ben's Cafe / other businesses with the cafe-invoice module)
// Note: cafe_invoices has no business_id column — each cafe-type business uses its own database.
function getUnpaidCafeInvoices($pdo)
{
    try {
        $stmt = $pdo->prepare("
            SELECT invoice_number, customer_name, total_amount, created_at
            FROM cafe_invoices
            WHERE status = 'unpaid'
            ORDER BY created_at ASC
            LIMIT 50
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log("Unpaid cafe invoices query failed: " . $e->getMessage());
        return [];
    }
}

function formatUnpaidCafeInvoiceMessages($unpaidInvoices)
{
    if (empty($unpaidInvoices)) {
        return [];
    }

    $messages = [];
    foreach ($unpaidInvoices as $invoice) {
        $label = trim($invoice['customer_name'] ?? '') ?: ('Invoice ' . ($invoice['invoice_number'] ?? '-'));
        $amount = number_format((float)($invoice['total_amount'] ?? 0), 0, ',', '.');
        $messages[] = "☕ {$label} — BELUM LUNAS (Rp {$amount})";
    }

    return $messages;
}
