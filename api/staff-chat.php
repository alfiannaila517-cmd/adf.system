<?php

/**
 * API: Staff Chat / Pengumuman
 * Broadcast satu arah dari admin ke semua staff (staff portal).
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once dirname(dirname(__FILE__)) . '/config/database.php';
require_once dirname(dirname(__FILE__)) . '/includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user = $auth->getCurrentUser();
$db = Database::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Auto-create table (self-healing)
try {
    $db->query("CREATE TABLE IF NOT EXISTS `staff_chat_messages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `message` TEXT NOT NULL,
        `created_by_name` VARCHAR(150) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
}

$isOwnerAdmin = in_array($user['role'] ?? '', ['owner', 'admin', 'developer']);

// ═══ List messages ═══
if ($action === 'list') {
    $rows = $db->fetchAll("SELECT id, message, created_by_name, created_at FROM staff_chat_messages ORDER BY id DESC LIMIT 50") ?: [];
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

// ═══ Send new announcement (admin/owner/developer only) ═══
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isOwnerAdmin) {
        echo json_encode(['success' => false, 'message' => 'Tidak diizinkan']);
        exit;
    }
    $message = trim($_POST['message'] ?? '');
    if ($message === '') {
        echo json_encode(['success' => false, 'message' => 'Pesan tidak boleh kosong']);
        exit;
    }
    $senderName = $user['full_name'] ?? 'Admin';
    $db->query("INSERT INTO staff_chat_messages (message, created_by_name) VALUES (?, ?)", [$message, $senderName]);
    echo json_encode(['success' => true]);
    exit;
}

// ═══ Delete announcement (admin/owner/developer only) ═══
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isOwnerAdmin) {
        echo json_encode(['success' => false, 'message' => 'Tidak diizinkan']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->query("DELETE FROM staff_chat_messages WHERE id = ?", [$id]);
    }
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
