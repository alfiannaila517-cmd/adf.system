<?php

/**
 * Koreksi nama / nomor telepon tamu dari sebuah booking — diterapkan ke semua data yang menyangkut booking itu:
 * data tamu (termasuk kamar lain dalam booking grup yang atas nama sama), invoice hotel service, pesanan sarapan,
 * link layanan tamu, dan keterangan buku kas yang memuat kode booking.
 */
class GuestBookingEdit
{
    /**
     * @return array{changed:bool,name:bool,phone:bool,details:string[]}
     */
    public static function apply($db, int $bookingId, string $newName, string $newPhone): array
    {
        $res = ['changed' => false, 'name' => false, 'phone' => false, 'details' => []];
        $b = $db->fetchOne("SELECT b.id, b.booking_code, b.group_id, b.guest_id, g.guest_name, g.phone
            FROM bookings b LEFT JOIN guests g ON g.id = b.guest_id WHERE b.id = ?", [$bookingId]);
        if (!$b || empty($b['guest_id'])) return $res;

        $newName = trim(preg_replace('/\s+/', ' ', $newName));
        $newPhone = trim($newPhone);
        $oldName = (string)$b['guest_name'];
        $oldPhone = (string)$b['phone'];
        $doName = $newName !== '' && mb_strlen($newName) <= 150 && $newName !== $oldName;
        $doPhone = $newPhone !== '' && WhatsAppHelper::normalizeTarget($newPhone) !== '' && $newPhone !== $oldPhone;
        if (!$doName && !$doPhone) return $res;

        // Booking yang ikut berubah: booking ini + kamar lain di grup yang tamunya bernama sama
        $rows = [['id' => (int)$b['id'], 'code' => $b['booking_code'], 'guest_id' => (int)$b['guest_id']]];
        if (!empty($b['group_id'])) {
            $sib = $db->fetchAll("SELECT b.id, b.booking_code, b.guest_id FROM bookings b LEFT JOIN guests g ON g.id = b.guest_id
                WHERE b.group_id = ? AND b.id <> ? AND b.status <> 'cancelled' AND LOWER(TRIM(g.guest_name)) = LOWER(TRIM(?))", [$b['group_id'], $b['id'], $oldName]) ?: [];
            foreach ($sib as $r) $rows[] = ['id' => (int)$r['id'], 'code' => $r['booking_code'], 'guest_id' => (int)$r['guest_id']];
        }
        $bookingIds = array_map(fn($r) => $r['id'], $rows);
        $guestIds = array_values(array_unique(array_map(fn($r) => $r['guest_id'], $rows)));
        $inB = implode(',', $bookingIds);
        $inG = implode(',', $guestIds);

        $conn = $db->getConnection();
        $conn->beginTransaction();
        try {
            // 1) Data tamu
            $set = [];
            $args = [];
            if ($doName) { $set[] = 'guest_name = ?'; $args[] = $newName; }
            if ($doPhone) { $set[] = 'phone = ?'; $args[] = $newPhone; }
            $db->query("UPDATE guests SET " . implode(', ', $set) . " WHERE id IN ($inG)", $args);
            $res['details'][] = 'guests';

            // 2) Salinan nama / nomor di tabel lain (setiap tabel dicoba sendiri: struktur bisnis bisa berbeda)
            $copies = [
                ['hotel_invoices', 'booking_id', $doName ? 'guest_name' : null, $doPhone ? 'guest_phone' : null],
                ['breakfast_orders', 'booking_id', $doName ? 'guest_name' : null, null],
                ['guest_service_links', 'booking_id', $doName ? 'guest_name' : null, null],
            ];
            foreach ($copies as [$tbl, $key, $nameCol, $phoneCol]) {
                try {
                    $s2 = [];
                    $a2 = [];
                    if ($nameCol) { $s2[] = "$nameCol = ?"; $a2[] = $newName; }
                    if ($phoneCol) { $s2[] = "$phoneCol = ?"; $a2[] = $newPhone; }
                    if (!$s2) continue;
                    $db->query("UPDATE $tbl SET " . implode(', ', $s2) . " WHERE $key IN ($inB)", $a2);
                    $res['details'][] = $tbl;
                } catch (\Throwable $e) {
                    // tabel / kolom tidak ada di bisnis ini: lewati
                }
            }

            // 3) Keterangan buku kas yang memuat kode booking + nama lama
            if ($doName && $oldName !== '') {
                try {
                    foreach ($rows as $r) {
                        if (empty($r['code'])) continue;
                        $db->query("UPDATE cash_book SET description = REPLACE(description, ?, ?) WHERE description LIKE ? AND description LIKE ?",
                            [$oldName, $newName, '%' . $r['code'] . '%', '%' . $oldName . '%']);
                    }
                    $res['details'][] = 'cash_book';
                } catch (\Throwable $e) {
                }
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        $res['changed'] = true;
        $res['name'] = $doName;
        $res['phone'] = $doPhone;
        return $res;
    }
}
