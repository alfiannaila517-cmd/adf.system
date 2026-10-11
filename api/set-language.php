<?php

/**
 * API: Simpan bahasa tampilan (id / en) dari menu foto user atau halaman Display & Theme.
 * Disimpan di user_preferences.language dan session, sama seperti pilihan tema.
 */

error_reporting(0);
ini_set('display_errors', 0);
ob_start();

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/language.php';

ob_clean();
header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Sesi habis, silakan login ulang / Session expired']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

try {
    $code = strtolower(trim((string)($_POST['language'] ?? '')));
    if (!isset(langSupported()[$code])) {
        throw new Exception('Invalid language');
    }

    $db = Database::getInstance();
    $userId = (int)($auth->getCurrentUser()['id'] ?? 0);
    if ($userId <= 0) {
        throw new Exception('Unknown user');
    }

    try {
        $db->getConnection()->exec("CREATE TABLE IF NOT EXISTS user_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            branch_id VARCHAR(50) NOT NULL DEFAULT '',
            theme VARCHAR(50) DEFAULT 'dark',
            language VARCHAR(20) DEFAULT 'id',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_branch (user_id, branch_id),
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Throwable $e) {
        // tabel sudah ada
    }

    // Semua baris milik user ikut diubah agar bahasa berlaku di semua bisnis
    $exists = $db->fetchOne("SELECT id FROM user_preferences WHERE user_id = ? LIMIT 1", [$userId]);
    if ($exists) {
        $db->query("UPDATE user_preferences SET language = ?, updated_at = NOW() WHERE user_id = ?", [$code, $userId]);
    } else {
        $db->query("INSERT INTO user_preferences (user_id, branch_id, theme, language) VALUES (?, '', 'dark', ?)", [$userId, $code]);
    }
    $_SESSION['user_language'] = $code;

    echo json_encode(['success' => true, 'language' => $code]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}