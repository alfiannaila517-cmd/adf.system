<?php

/**
 * API: Update Room Status
 * Used by housekeeping to mark a room as available after cleaning.
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

try {
    if (empty($_POST)) {
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($jsonInput)) {
            $_POST = $jsonInput;
        }
    }

    $roomId = (int)($_POST['room_id'] ?? 0);
    $status = strtolower(trim($_POST['status'] ?? ''));

    if (!$roomId) {
        throw new Exception('Room ID is required');
    }

    $allowedStatuses = ['available', 'cleaning', 'maintenance', 'blocked'];
    if (!in_array($status, $allowedStatuses, true)) {
        throw new Exception('Invalid room status');
    }

    $room = $db->fetchOne("SELECT id, room_number, status FROM rooms WHERE id = ?", [$roomId]);
    if (!$room) {
        throw new Exception('Room not found');
    }

    $db->query("
        UPDATE rooms
        SET status = ?,
            current_guest_id = CASE WHEN ? = 'available' THEN NULL ELSE current_guest_id END,
            updated_at = NOW()
        WHERE id = ?
    ", [$status, $status, $roomId]);

    echo json_encode([
        'success' => true,
        'message' => 'Room ' . $room['room_number'] . ' updated to ' . $status,
        'room' => [
            'id' => (int)$room['id'],
            'room_number' => $room['room_number'],
            'status' => $status,
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
