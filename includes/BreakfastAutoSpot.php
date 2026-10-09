<?php
/**
 * Sarapan: otomatis "on the spot" untuk kamar yang belum memilih menu sampai pukul 05:00 pagi sarapan.
 * Dipakai oleh api/breakfast-guest-portal.php (saat link dibuka) dan cron/breakfast-auto-onspot.php.
 */

function auto_submit_on_the_spot_after_midnight($db, $pdo, $link)
{
    $linkStatus = (string)($link['link_status'] ?? 'open');
    if (!in_array($linkStatus, ['open', 'expired'], true) || !empty($link['submitted_at'])) {
        return false;
    }

    $breakfastDate = (string)($link['breakfast_date'] ?? '');
    if ($breakfastDate === '') {
        return false;
    }

    // Link induk grup (punya link per kamar) tidak membuat pesanan sendiri: tiap kamar diproses sendiri-sendiri
    try {
        if (!empty($link['token']) && $db->fetchOne("SELECT id FROM breakfast_guest_links WHERE parent_token = ? LIMIT 1", [(string)$link['token']])) {
            return false;
        }
    } catch (\Throwable $e) {
    }

    // Batas pilih menu: pukul 05:00 pagi sarapan (sarapan tanggal D disajikan pagi hari D+1).
    // Yang belum memilih sampai batas itu otomatis "on the spot" di restoran.
    $tz = new DateTimeZone('Asia/Jakarta');
    $now = new DateTime('now', $tz);
    $cutoff = DateTime::createFromFormat('Y-m-d H:i:s', $breakfastDate . ' 05:00:00', $tz);
    if (!$cutoff) {
        return false;
    }
    $cutoff->modify('+1 day');
    if ($now < $cutoff) {
        return false;
    }

    $guestName = trim((string)($link['guest_name'] ?? ''));
    if ($guestName === '') {
        return false;
    }

    $bookingId = !empty($link['booking_id']) ? (int)$link['booking_id'] : null;
    $roomJson = $link['room_number'] ?: json_encode([]);
    $guestComposition = json_decode($link['guest_composition'] ?? '{}', true);
    if (!is_array($guestComposition)) $guestComposition = [];
    $totalPax = max(1, (int)($guestComposition['total_pax'] ?? (($guestComposition['adults'] ?? 1) + ($guestComposition['children_young'] ?? 0) + ($guestComposition['children_old'] ?? 0))));
    $createdBy = isset($link['created_by']) ? (int)$link['created_by'] : 0;

    $breakfastTime = '07:00:00';
    $serviceType = 'restaurant';
    $breakfastLocation = 'Main Restaurant';
    $specialReason = '[AUTO ON THE SPOT MIDNIGHT] Guest did not submit menu before 05:00';

    $menuItems = [[
        'menu_id' => 0,
        'menu_name' => 'ON THE SPOT (Guest will choose at restaurant)',
        'quantity' => 1,
        'price' => 0,
        'is_free' => 1,
        'group' => 'on_the_spot',
        'is_on_the_spot' => 1,
        'auto_set' => 1
    ]];
    $menuJson = json_encode($menuItems);

    // Kamar dalam grup memakai nama tamu yang sama: pesanan dicocokkan per booking (per kamar), bukan per nama
    if (!empty($link['parent_token']) && $bookingId) {
        $existing = $db->fetchOne("SELECT id FROM breakfast_orders WHERE breakfast_date = ? AND booking_id = ? LIMIT 1", [$breakfastDate, $bookingId]);
    } else {
        $existing = $db->fetchOne(
            "SELECT id FROM breakfast_orders WHERE breakfast_date = ? AND FIND_IN_SET(?, REPLACE(guest_name, ', ', ',')) > 0 LIMIT 1",
            [$breakfastDate, $guestName]
        );
    }

    if ($existing) {
        $pdo->prepare("UPDATE breakfast_orders SET
            booking_id = ?, guest_name = ?, room_number = ?, total_pax = ?, breakfast_time = ?,
            breakfast_date = ?, location = ?, breakfast_location = ?, menu_items = ?, special_requests = ?, total_price = ?,
            on_the_spot = 1, order_status = 'submitted', updated_at = NOW()
            WHERE id = ?")
            ->execute([
                $bookingId,
                $guestName,
                $roomJson,
                $totalPax,
                $breakfastTime,
                $breakfastDate,
                $serviceType,
                $breakfastLocation,
                $menuJson,
                $specialReason,
                0,
                (int)$existing['id']
            ]);
    } else {
        $pdo->prepare("INSERT INTO breakfast_orders
            (booking_id, guest_name, room_number, total_pax, breakfast_time, breakfast_date,
             location, breakfast_location, on_the_spot, menu_items, special_requests, total_price, order_status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 0, 'submitted', ?)")
            ->execute([
                $bookingId,
                $guestName,
                $roomJson,
                $totalPax,
                $breakfastTime,
                $breakfastDate,
                $serviceType,
                $breakfastLocation,
                $menuJson,
                $specialReason,
                $createdBy
            ]);
    }

    $pdo->prepare("UPDATE breakfast_guest_links
        SET link_status = 'submitted', selected_menu_ids = '[]', selected_menu_notes = '{}', selected_menu_qty = '{}',
            selected_drink_ids = '[]', selected_drink_notes = '{}', selected_drink_qty = '{}',
            selected_child_ids = '[]', selected_child_notes = '{}', selected_child_qty = '{}',
            breakfast_time = ?, breakfast_service = ?, breakfast_location = ?, on_the_spot = 1,
            special_requests = ?, submitted_at = NOW(), updated_at = NOW()
        WHERE id = ?")
        ->execute([
            $breakfastTime,
            $serviceType,
            $breakfastLocation,
            $specialReason,
            (int)$link['id']
        ]);

    return true;
}

/**
 * Jalankan untuk semua link yang belum dikirim dan sudah lewat batas waktunya. Mengembalikan jumlah yang diproses.
 */
function bf_auto_on_the_spot_run($db, $pdo): int
{
    $n = 0;
    $rows = $db->fetchAll("SELECT * FROM breakfast_guest_links WHERE submitted_at IS NULL AND link_status IN ('open', 'expired') AND breakfast_date <= CURDATE() ORDER BY id") ?: [];
    foreach ($rows as $link) {
        try {
            if (auto_submit_on_the_spot_after_midnight($db, $pdo, $link)) $n++;
        } catch (\Throwable $e) {
            error_log('breakfast auto on-the-spot: ' . $e->getMessage());
        }
    }
    return $n;
}

/**
 * Bersihkan link sarapan lama (sudah lewat beberapa hari): isi pilihan tamu sudah tersimpan di pesanan (breakfast_orders),
 * jadi baris link tidak diperlukan lagi dan hanya memenuhi database. Pesanan untuk laporan/dapur TIDAK dihapus.
 * @return int jumlah link yang dihapus
 */
function bf_cleanup_old_links($db, int $keepDays = 3): int
{
    $keepDays = max(1, $keepDays);
    $n = 0;
    try {
        $row = $db->fetchOne("SELECT COUNT(*) c FROM breakfast_guest_links WHERE breakfast_date < DATE_SUB(CURDATE(), INTERVAL {$keepDays} DAY)");
        $n = (int)($row['c'] ?? 0);
        if ($n > 0) {
            $db->query("DELETE FROM breakfast_guest_links WHERE breakfast_date < DATE_SUB(CURDATE(), INTERVAL {$keepDays} DAY)");
        }
    } catch (\Throwable $e) {
        error_log('breakfast cleanup links: ' . $e->getMessage());
    }
    return $n;
}
