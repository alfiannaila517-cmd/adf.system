<?php

/**
 * API: GET BILL ITEMS
 * GET /api/get-bill-items.php?bill_id=X
 *
 * Returns the per-date item breakdown of a monthly bill (e.g. supplier recap).
 */

if (ob_get_level()) ob_end_clean();
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

try {
    define('APP_ACCESS', true);
    require_once '../config/config.php';
    require_once '../config/database.php';
    require_once '../includes/auth.php';
    require_once '../includes/monthly_bills_migrate.php';

    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $billId = (int)($_GET['bill_id'] ?? 0);
    if (!$billId) throw new Exception('bill_id required');

    $db = Database::getInstance();
    ensureMonthlyBillsTables($db);

    $items = $db->fetchAll(
        "SELECT id, item_date, item_name, amount FROM bill_items WHERE bill_id = ? ORDER BY item_date ASC, id ASC",
        [$billId]
    );

    echo json_encode(['success' => true, 'items' => $items]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
