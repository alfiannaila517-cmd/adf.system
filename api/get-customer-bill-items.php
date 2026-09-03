<?php

/**
 * API: GET BILL ITEMS FOR A CUSTOMER/SUPPLIER WITHIN A DATE RANGE
 * GET /api/get-customer-bill-items.php?customer_name=X&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Used to print a weekly/periodic recap of all dated items billed to one
 * customer/supplier ("toko"), summed to a grand total.
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

    $customerName = trim($_GET['customer_name'] ?? '');
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    if ($customerName === '') throw new Exception('customer_name required');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        throw new Exception('from/to harus format YYYY-MM-DD');
    }

    $db = Database::getInstance();
    ensureMonthlyBillsTables($db);

    $items = $db->fetchAll(
        "SELECT bi.id, bi.item_date, bi.item_name, bi.amount, mb.bill_name, mb.customer_name
         FROM bill_items bi
         JOIN monthly_bills mb ON mb.id = bi.bill_id
         WHERE mb.customer_name = ? AND bi.item_date BETWEEN ? AND ?
         ORDER BY bi.item_date ASC, bi.id ASC",
        [$customerName, $from, $to]
    );

    $total = 0;
    foreach ($items as $it) {
        $total += (float)$it['amount'];
    }

    echo json_encode([
        'success' => true,
        'customer_name' => $customerName,
        'from' => $from,
        'to' => $to,
        'items' => $items,
        'total' => $total
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
