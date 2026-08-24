<?php
/**
 * API: Update Booking Note
 * Lightweight endpoint to add/edit a guest request note (special_request) on a single
 * booking row, without touching price/room/date fields (unlike update-reservation.php).
 */

error_reporting(0);
ini_set('display_errors', 0);
ob_start();

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

ob_clean();
header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!$auth->hasPermission('frontdesk')) {
    echo json_encode(['success' => false, 'message' => 'No permission']);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

try {
    $bookingId = intval($_POST['booking_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if (!$bookingId) {
        throw new Exception('Booking ID is required');
    }

    $stmt = $conn->prepare("SELECT id FROM bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    if (!$stmt->fetch()) {
        throw new Exception('Booking not found');
    }

    $stmt = $conn->prepare("UPDATE bookings SET special_request = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$note, $bookingId]);

    echo json_encode(['success' => true, 'special_request' => $note]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
