<?php
if (($_GET['token'] ?? '') !== 'adf-deploy-2025-secure') { http_response_code(403); exit('forbidden'); }
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
header('Content-Type: application/json');
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . MASTER_DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $rows = $pdo->query("
        SELECT uba.*, u.username, u.full_name, b.business_name, b.business_code
        FROM user_business_assignment uba
        JOIN users u ON u.id = uba.user_id
        JOIN businesses b ON b.id = uba.business_id
        WHERE uba.user_id = 11
        ORDER BY uba.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $eatMeet = $pdo->query("SELECT id, business_code, business_name, owner_id FROM businesses WHERE id = 11")->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['lucca_assignments' => $rows, 'eat_meet' => $eatMeet], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
