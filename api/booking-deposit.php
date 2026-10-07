<?php

/**
 * API: Deposit tamu (uang atau kartu identitas) per booking — untuk Tanda Terima Deposit.
 *
 * Data bersifat sementara: terhapus otomatis ketika booking sudah check-out / dibatalkan,
 * atau bila dibuat sebelum bulan berjalan.
 *
 * GET  ?action=list&booking_id=
 * POST action=save   booking_id, deposit_type (cash|id_card), amount, id_type, id_number, notes
 * POST action=update id, deposit_type, amount, id_type, id_number, notes
 * POST action=delete id
 */

header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn() || !$auth->hasPermission('frontdesk')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

/** Tabel + pembersihan data deposit yang sudah tidak berlaku. */
function bdPrepare(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_deposits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL,
        deposit_type VARCHAR(20) NOT NULL DEFAULT 'cash',
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        id_type VARCHAR(30) DEFAULT NULL,
        id_number VARCHAR(60) DEFAULT NULL,
        notes VARCHAR(255) DEFAULT NULL,
        received_by VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_booking (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("DELETE bd FROM booking_deposits bd
        LEFT JOIN bookings b ON b.id = bd.booking_id
        WHERE b.id IS NULL OR b.status IN ('checked_out', 'cancelled')
           OR bd.created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')");
}

try {
    $pdo = Database::getInstance()->getConnection();
    bdPrepare($pdo);
    $user = $auth->getCurrentUser();
    $action = $_REQUEST['action'] ?? 'list';

    if ($action === 'list') {
        $bookingId = (int)($_GET['booking_id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM booking_deposits WHERE booking_id = ? ORDER BY id DESC");
        $st->execute([$bookingId]);
        echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Metode tidak valid');
    }

    if ($action === 'save' || $action === 'update') {
        $editId = $action === 'update' ? (int)($_POST['id'] ?? 0) : 0;
        if ($editId) {
            $cur = $pdo->prepare("SELECT booking_id FROM booking_deposits WHERE id = ?");
            $cur->execute([$editId]);
            $_POST['booking_id'] = (int)$cur->fetchColumn();
            if (!$_POST['booking_id']) throw new Exception('Deposit tidak ditemukan');
        }
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $type = ($_POST['deposit_type'] ?? 'cash') === 'id_card' ? 'id_card' : 'cash';
        $amount = (float)preg_replace('/\D/', '', (string)($_POST['amount'] ?? '0'));
        $idType = trim((string)($_POST['id_type'] ?? ''));
        $idNumber = trim((string)($_POST['id_number'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $bk = $pdo->prepare("SELECT id, status FROM bookings WHERE id = ?");
        $bk->execute([$bookingId]);
        $booking = $bk->fetch(PDO::FETCH_ASSOC);
        if (!$booking) throw new Exception('Booking tidak ditemukan');
        if (in_array($booking['status'], ['checked_out', 'cancelled'], true)) {
            throw new Exception('Booking sudah check-out / dibatalkan');
        }
        if ($type === 'cash' && $amount <= 0) throw new Exception('Isi jumlah deposit uang');
        if ($type === 'id_card' && $idType === '') throw new Exception('Pilih jenis kartu identitas');

        $vals = [
            $type,
            $type === 'cash' ? $amount : 0,
            $type === 'id_card' ? mb_substr($idType, 0, 30) : null,
            $idNumber !== '' ? mb_substr($idNumber, 0, 60) : null,
            $notes !== '' ? mb_substr($notes, 0, 255) : null,
        ];
        if ($editId) {
            $pdo->prepare("UPDATE booking_deposits SET deposit_type = ?, amount = ?, id_type = ?, id_number = ?, notes = ? WHERE id = ?")
                ->execute(array_merge($vals, [$editId]));
            echo json_encode(['success' => true, 'id' => $editId, 'message' => 'Deposit diperbarui']);
            exit;
        }
        $pdo->prepare("INSERT INTO booking_deposits (booking_id, deposit_type, amount, id_type, id_number, notes, received_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute(array_merge([$bookingId], $vals, [mb_substr((string)($user['full_name'] ?? $user['username'] ?? ''), 0, 100)]));
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Deposit tersimpan']);
        exit;
    }

    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM booking_deposits WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
        echo json_encode(['success' => true]);
        exit;
    }

    throw new Exception('Aksi tidak dikenal');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
