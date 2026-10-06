<?php

/**
 * Aturan bersama sarapan (portal tamu & order front desk).
 *
 * Jatah sarapan = jumlah pax (dewasa + anak) di reservasi, bisa diubah lewat Setup.
 * 1 pax = 1 makanan + 2 minuman (1 jus + 1 kopi/teh). Hanya MAKANAN di luar jatah yang ditagih:
 * 1 makanan extra = 1 paket Extra Breakfast, dan setiap paket menambah jatah 1 jus + 1 kopi/teh.
 * Minuman di atas jatah (pax + paket extra) ditolak; minuman tidak pernah ditagih.
 * Anak di bawah 7 tahun (kids) gratis: per anak 1 menu anak (pancake/waffle) + 1 minuman bebas jenis.
 * Tagihannya berupa invoice Hotel Service "Extra Breakfast" atas nama tamu.
 */

if (!function_exists('bf_extra_package_price')) {
    /** Harga 1 paket Extra Breakfast (setting breakfast_extra_price, bawaan Rp 82.500). */
    function bf_extra_package_price($db): float
    {
        static $price = null;
        if ($price !== null) return $price;
        $price = 82500.0;
        try {
            $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'breakfast_extra_price' LIMIT 1");
            $v = (float)($row['setting_value'] ?? 0);
            if ($v > 0) $price = $v;
        } catch (\Throwable $e) {
        }
        return $price;
    }
}

if (!function_exists('bf_extra_packages')) {
    /** Jumlah paket extra dari kelebihan makanan & minuman. */
    function bf_extra_packages(int $extraMain, int $extraDrink): int
    {
        return max(0, $extraMain, $extraDrink);
    }
}

if (!function_exists('bf_drink_kind')) {
    /** Jenis minuman dari namanya: 'juice' (jus/smoothie/lemonade) atau 'coffee' (kopi, teh, cokelat & lainnya). */
    function bf_drink_kind(string $name): string
    {
        return preg_match('/juice|\bjus\b|smoothie|lemonade/i', $name) ? 'juice' : 'coffee';
    }
}

if (!function_exists('bf_count_extra')) {
    /**
     * Hitung kelebihan jatah. Jatah per pax: 1 makanan + 1 jus + 1 kopi/teh; paket extra = makanan di luar jatah.
     * Menu berbayar, menu anak, item manual & ON THE SPOT tidak memakai jatah.
     * @param array $items item order (menu_name, quantity, category, group, is_free, is_custom, is_on_the_spot)
     */
    function bf_count_extra(array $items, int $pax, int $kids = 0): array
    {
        $sum = ['main' => 0, 'juice' => 0, 'coffee' => 0, 'child' => 0];
        foreach ($items as $it) {
            if (!empty($it['is_custom']) || !empty($it['is_on_the_spot'])) continue;
            if (isset($it['is_free']) && (int)$it['is_free'] === 0) continue;
            $group = (string)($it['group'] ?? '');
            $qty = max(1, (int)($it['quantity'] ?? 1));
            if ($group === 'child') {
                $sum['child'] += $qty;
                continue;
            }
            $cat = strtolower(trim((string)($it['category'] ?? '')));
            $isDrink = $group === 'drink' || in_array($cat, ['drinks', 'drink', 'beverages', 'beverage'], true);
            if ($isDrink) {
                $sum[bf_drink_kind((string)($it['menu_name'] ?? ''))] += $qty;
            } else {
                $sum['main'] += $qty;
            }
        }
        $packages = max(0, $sum['main'] - $pax);
        $drinkCap = $pax + $packages; // jatah jus & kopi/teh ikut bertambah per paket extra
        // Minuman anak: $kids tambahan, boleh jus maupun kopi/teh.
        $ex = [
            'main' => $packages,
            'juice' => max(0, $sum['juice'] - $drinkCap - $kids),
            'coffee' => max(0, $sum['coffee'] - $drinkCap - $kids),
        ];
        $drinkTotalOk = ($sum['juice'] + $sum['coffee']) <= (2 * $drinkCap + $kids);
        return [
            'sum' => $sum,
            'extra' => $ex,
            'packages' => $packages,
            'drink_cap' => $drinkCap,
            'drink_ok' => $ex['juice'] === 0 && $ex['coffee'] === 0 && $drinkTotalOk,
            'kids' => $kids,
            'kids_ok' => $sum['child'] <= $kids,
        ];
    }
}

if (!function_exists('bf_sync_extra_invoice')) {
    /**
     * Tagihan Extra Breakfast sebagai invoice Hotel Service (belum lunas) atas nama tamu.
     * Satu invoice per order/link (penanda [BF-EXTRA ref] di catatan): dibuat, diperbarui, atau
     * dibatalkan bila tidak ada lagi kelebihan. Invoice yang sudah dibayar/diproses tidak diubah.
     * @param array $c booking_id, guest_name, guest_phone, rooms (array), date (Y-m-d), packages, ref, created_by
     * @return float total tagihan
     */
    function bf_sync_extra_invoice($db, $pdo, array $c): float
    {
        $packages = max(0, (int)($c['packages'] ?? 0));
        $price = bf_extra_package_price($db);
        $charge = $packages * $price;
        $ref = '[BF-EXTRA ' . preg_replace('/[^A-Za-z0-9=_-]/', '', (string)$c['ref']) . ']';
        $rooms = array_values(array_filter(array_map('strval', (array)($c['rooms'] ?? []))));
        $date = (string)($c['date'] ?? date('Y-m-d'));

        $hasTable = false;
        try {
            $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'hotel_invoices'")->fetch();
        } catch (\Throwable $e) {
        }
        if (!$hasTable) return 0.0; // modul Hotel Service belum dipakai di bisnis ini

        $bizId = (int)($_SESSION['business_id'] ?? 0);
        if ($bizId <= 0 && function_exists('getNumericBusinessId') && defined('ACTIVE_BUSINESS_ID')) {
            $bizId = (int)getNumericBusinessId(ACTIVE_BUSINESS_ID);
        }
        if ($bizId <= 0) $bizId = 1;

        $existing = $db->fetchOne(
            "SELECT id, paid_amount, cashbook_synced, status FROM hotel_invoices WHERE notes LIKE ? ORDER BY id DESC LIMIT 1",
            ['%' . $ref . '%']
        );
        $locked = $existing && ((float)$existing['paid_amount'] > 0 || (int)$existing['cashbook_synced'] === 1);
        if ($locked) return (float)$charge;

        if ($charge <= 0) {
            if ($existing) {
                $pdo->prepare("DELETE FROM hotel_invoice_items WHERE invoice_id = ?")->execute([(int)$existing['id']]);
                $pdo->prepare("DELETE FROM hotel_invoices WHERE id = ?")->execute([(int)$existing['id']]);
            }
            return 0.0;
        }

        // Jenis layanan "Extra Breakfast" di daftar Hotel Service.
        try {
            $has = $db->fetchOne("SELECT id FROM hotel_service_types WHERE business_id = ? AND type_key = 'extra_breakfast'", [$bizId]);
            if (!$has) {
                $pdo->prepare("INSERT INTO hotel_service_types (business_id, type_key, type_label, type_icon, sort_order) VALUES (?, 'extra_breakfast', 'Extra Breakfast', '🍳', 9)")
                    ->execute([$bizId]);
            }
        } catch (\Throwable $e) {
        }

        $serveDate = date('d M', strtotime($date . ' +1 day'));
        $desc = 'Extra Breakfast ' . $packages . ' paket (1 makanan, termasuk 1 jus + 1 kopi/teh) · ' . $serveDate;
        $notes = $ref . ' Extra breakfast · rooms=' . implode(',', $rooms) . ' date=' . $date;
        $roomLabel = mb_substr(implode(', ', $rooms), 0, 20);

        if ($existing) {
            $id = (int)$existing['id'];
            $pdo->prepare("UPDATE hotel_invoices SET total = ?, payment_status = 'unpaid', status = 'confirmed', notes = ?, last_service_at = NOW() WHERE id = ?")
                ->execute([$charge, $notes, $id]);
            $pdo->prepare("DELETE FROM hotel_invoice_items WHERE invoice_id = ?")->execute([$id]);
        } else {
            $prefix = 'HSV-' . date('Ym') . '-';
            $last = $pdo->query("SELECT invoice_number FROM hotel_invoices WHERE invoice_number LIKE '{$prefix}%' ORDER BY invoice_number DESC LIMIT 1")->fetchColumn();
            $invNo = $prefix . str_pad((string)($last ? ((int)substr($last, -4) + 1) : 1), 4, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO hotel_invoices
                (business_id, invoice_number, booking_id, guest_name, guest_phone, room_number, total, paid_amount,
                 payment_status, payment_method, status, notes, last_service_at, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'unpaid', 'cash', 'confirmed', ?, NOW(), ?, NOW())")
                ->execute([
                    $bizId, $invNo, ((int)($c['booking_id'] ?? 0)) ?: null,
                    mb_substr((string)$c['guest_name'], 0, 120), ((string)($c['guest_phone'] ?? '')) ?: null,
                    $roomLabel ?: null, $charge, $notes, $c['created_by'] ?? null,
                ]);
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO hotel_invoice_items (invoice_id, service_type, description, quantity, unit_price, total_price) VALUES (?, 'extra_breakfast', ?, ?, ?, ?)")
            ->execute([$id, $desc, $packages, $price, $charge]);
        return (float)$charge;
    }
}
