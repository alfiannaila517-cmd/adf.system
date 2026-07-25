<?php
if (($_GET['token'] ?? '') !== 'adf-deploy-2025-secure') { http_response_code(403); exit('forbidden'); }
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
header('Content-Type: application/json');
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . MASTER_DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare("UPDATE businesses SET business_name = 'Eat Meet' WHERE id = 11 AND business_name = 'Eaat & Meet'");
    $stmt->execute();
    echo json_encode(['updated_rows' => $stmt->rowCount()]);
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
