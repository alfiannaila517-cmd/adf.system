<?php

/**
 * API: Update profil sendiri (nama lengkap) dari menu foto user di top bar.
 * Nama disamakan di database bisnis aktif, database pusat, dan semua database bisnis (berdasarkan username),
 * sama seperti sinkron Ganti Password. Foto profil diunggah lewat form avatar di header.
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
    echo json_encode(['success' => false, 'message' => 'Sesi habis, silakan login ulang']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metode tidak valid']);
    exit;
}

try {
    $db = Database::getInstance();
    $currentUser = $auth->getCurrentUser();
    $userId = (int)($currentUser['id'] ?? 0);
    if ($userId <= 0) {
        throw new Exception('User tidak dikenali');
    }

    $name = trim((string)preg_replace('/\s+/u', ' ', (string)($_POST['full_name'] ?? '')));
    $len = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($len < 2) {
        throw new Exception('Nama minimal 2 karakter');
    }
    if ($len > 100) {
        throw new Exception('Nama maksimal 100 karakter');
    }

    $user = $db->fetchOne("SELECT id, username FROM users WHERE id = ?", [$userId]);
    if (!$user) {
        throw new Exception('User tidak ditemukan');
    }
    $username = (string)$user['username'];

    // 1) database bisnis aktif
    $db->query("UPDATE users SET full_name = ? WHERE id = ?", [$name, $userId]);

    // 2) database pusat + semua database bisnis (disamakan lewat username); gagal di sini tidak membatalkan perubahan
    try {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $isProduction = (strpos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false);
        $masterDbName = $isProduction ? 'adfb2574_adf' : 'adf_system';
        $masterPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $masterDbName . ';charset=utf8mb4', DB_USER, DB_PASS);
        $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $masterPdo->prepare("UPDATE users SET full_name = ? WHERE username = ?")->execute([$name, $username]);

        $businesses = $masterPdo->query("SELECT database_name FROM businesses WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        $dbMapping = [
            'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
            'adf_benscafe' => 'adfb2574_Adf_Bens',
        ];
        foreach ($businesses as $biz) {
            try {
                $bizDbName = $biz['database_name'];
                if ($isProduction && isset($dbMapping[$bizDbName])) {
                    $bizDbName = $dbMapping[$bizDbName];
                }
                $bizPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $bizDbName . ';charset=utf8mb4', DB_USER, DB_PASS);
                $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $bizPdo->prepare("UPDATE users SET full_name = ? WHERE username = ?")->execute([$name, $username]);
            } catch (\Throwable $e) {
                // database bisnis tidak bisa diakses: lewati
            }
        }
    } catch (\Throwable $e) {
        error_log('account-name sync: ' . $e->getMessage());
    }

    $_SESSION['full_name'] = $name;

    try {
        $db->query(
            "INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (?, ?, ?, NOW())",
            [$userId, 'update_profile', 'User mengubah nama profil menjadi ' . $name]
        );
    } catch (\Throwable $e) {
        // log opsional
    }

    echo json_encode(['success' => true, 'message' => 'Nama berhasil disimpan', 'full_name' => $name], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
