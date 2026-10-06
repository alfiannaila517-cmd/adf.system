<?php

/**
 * Notifikasi untuk staf Housekeeping (Staff Portal) saat tamu check-in / check-out:
 *   - baris di tabel notifications (muncul di lonceng Staff Portal)
 *   - push notification ke HP staf yang mengaktifkan notifikasi
 * Staf HK = karyawan aktif dengan departemen/jabatan housekeeping (sama dengan pembagian kamar HK).
 * Tidak pernah melempar exception: check-in/out tidak boleh gagal karena notifikasi.
 */
function hkNotifyGuestMovement($db, string $event, array $booking): void
{
    try {
        $employees = $db->fetchAll(
            "SELECT id FROM payroll_employees
             WHERE is_active = 1
               AND (
                    LOWER(COALESCE(department, '')) LIKE '%housekeeping%'
                    OR LOWER(COALESCE(department, '')) = 'hk'
                    OR LOWER(COALESCE(position, '')) LIKE '%housekeeping%'
                    OR LOWER(COALESCE(position, '')) LIKE 'hk%'
               )"
        ) ?: [];
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
            $title = '🧹 ' . $roomLabel . ' perlu dibersihkan';
            $message = $guest . ' check-out pukul ' . date('H:i') . '. Kamar kosong & kotor — siapkan untuk tamu berikutnya.';
            $kind = 'hk_checkout';
        } else {
            $out = !empty($booking['check_out_date']) ? date('d M', strtotime($booking['check_out_date'])) : '-';
            $title = '🛎️ Tamu check-in · ' . $roomLabel;
            $message = $guest . ' check-in pukul ' . date('H:i') . ', menginap sampai ' . $out . '. Kamar kini terisi.';
            $kind = 'hk_checkin';
        }
        $data = json_encode(['room' => $room, 'guest' => $guest, 'booking_id' => (int)($booking['id'] ?? 0), 'event' => $event]);

        // Notifikasi di Staff Portal (tabel dibuat oleh staff-api bila belum ada).
        try {
            $db->getConnection()->exec("CREATE TABLE IF NOT EXISTS `notifications` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `type` VARCHAR(50) NOT NULL,
                `title` VARCHAR(255) DEFAULT NULL,
                `message` TEXT,
                `data` TEXT,
                `is_read` TINYINT(1) DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $db->getConnection()->prepare("INSERT INTO notifications (user_id, type, title, message, data, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            foreach ($ids as $id) {
                $stmt->execute([$id, $kind, $title, $message, $data]);
            }
        } catch (\Throwable $e) {
            error_log('HK notify (table): ' . $e->getMessage());
        }

        // Push ke HP staf HK.
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
