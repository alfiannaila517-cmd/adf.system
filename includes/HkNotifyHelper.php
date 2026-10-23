<?php

/**
 * Notifikasi untuk semua staf (Staff Portal) saat tamu check-in / check-out:
 *   push ke HP staf (bunyi + popup sekali), TIDAK disimpan di lonceng Staff Portal (permintaan pemilik, 2026-10).
 * Penerima: semua karyawan aktif (FO, HK, F&B, dll) agar seluruh tim tahu pergerakan tamu.
 * Tidak pernah melempar exception: check-in/out tidak boleh gagal karena notifikasi.
 */
function hkNotifyGuestMovement($db, string $event, array $booking): void
{
    try {
        $employees = $db->fetchAll("SELECT id FROM payroll_employees WHERE is_active = 1") ?: [];
        $ids = array_map('intval', array_column($employees, 'id'));
        if (!$ids) return;

        $room = (string)($booking['room_number'] ?? '-');
        $guest = trim((string)($booking['guest_name'] ?? 'Tamu'));
        $type = '';
        try {
            $row = $db->fetchOne("SELECT rt.type_name FROM rooms r LEFT JOIN room_types rt ON rt.id = r.room_type_id WHERE r.id = ?", [$booking['room_id'] ?? 0]);
            $type = (string)($row['type_name'] ?? '');
        } catch (\Throwable $e) {
        }
        $roomLabel = 'Kamar ' . $room . ($type !== '' ? ' (' . $type . ')' : '');

        if ($event === 'checkout') {
            $title = '🚪 Tamu check-out · ' . $roomLabel;
            $message = $guest . ' check-out pukul ' . date('H:i') . '. Kamar kosong & perlu dibersihkan (HK).';
            $kind = 'hk_checkout';
        } else {
            $out = !empty($booking['check_out_date']) ? date('d M', strtotime($booking['check_out_date'])) : '-';
            $title = '🛎️ Tamu check-in · ' . $roomLabel;
            $message = $guest . ' check-in pukul ' . date('H:i') . ', menginap sampai ' . $out . '. Kamar kini terisi.';
            $kind = 'hk_checkin';
        }
        // Push ke HP staf.
        try {
            require_once __DIR__ . '/PushNotificationHelper.php';
            $slug = defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : '';
            (new PushNotificationHelper($db))->sendToEmployees($ids, $title, $message, [
                'type' => $kind,
                'tag'  => $kind . '-' . (int)($booking['id'] ?? 0),
                'url'  => '/modules/payroll/staff-portal.php' . ($slug !== '' ? '?b=' . rawurlencode($slug) : ''),
                'room_number' => $room,
            ]);
        } catch (\Throwable $e) {
            error_log('HK notify (push): ' . $e->getMessage());
        }
    } catch (\Throwable $e) {
        error_log('HK notify: ' . $e->getMessage());
    }
}
