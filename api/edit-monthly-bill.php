<?php

/**
 * API: EDIT/UPDATE MONTHLY BILL
 * POST /api/edit-monthly-bill.php
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/monthly_bills_migrate.php';

ob_start();
error_reporting(0);
ini_set('display_errors', '0');

while (ob_get_level()) ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
ensureMonthlyBillsTables($db);

try {
    $billId = (int)$_POST['bill_id'];
    if (!$billId) throw new Exception('Bill ID required');

    // Get existing bill
    $bill = $db->fetchOne("SELECT * FROM monthly_bills WHERE id = ? LIMIT 1", [$billId]);
    if (!$bill) throw new Exception('Bill tidak ditemukan');

    // Only allow edit if not paid yet (or partially paid)
    if ($bill['status'] === 'cancelled') {
        throw new Exception('Tagihan yang dibatalkan tidak bisa di-edit');
    }

    // Update fields (hanya field tertentu yang bisa di-edit)
    $updates = [];
    $params = [];

    if (isset($_POST['bill_name'])) {
        $updates[] = "bill_name = ?";
        $params[] = trim($_POST['bill_name']);
    }

    if (isset($_POST['customer_name'])) {
        $updates[] = "customer_name = ?";
        $params[] = trim($_POST['customer_name']) ?: null;
    }

    if (isset($_POST['amount']) && $_POST['amount'] > 0) {
        $updates[] = "amount = ?";
        $params[] = (float)$_POST['amount'];
    }

    // Optional per-date item breakdown (e.g. supplier recap) - replaces existing items and recomputes amount
    $items = null;
    if (isset($_POST['items'])) {
        $decoded = json_decode($_POST['items'], true);
        $items = [];
        if (is_array($decoded)) {
            foreach ($decoded as $it) {
                $itemAmount = (float)($it['amount'] ?? 0);
                $itemName = trim($it['item_name'] ?? '');
                if ($itemName === '' && $itemAmount <= 0) continue;
                $items[] = [
                    'item_date' => !empty($it['item_date']) ? $it['item_date'] : null,
                    'item_name' => $itemName,
                    'amount' => $itemAmount
                ];
            }
        }
        if (!empty($items)) {
            // items sum overrides whatever was in the amount field above
            $updates[] = "amount = ?";
            $params[] = array_sum(array_column($items, 'amount'));
        }
    }

    if (isset($_POST['bill_month']) && preg_match('/^\d{4}-\d{2}$/', $_POST['bill_month'])) {
        $updates[] = "bill_month = ?";
        $params[] = $_POST['bill_month'] . '-01';
    }

    if (isset($_POST['division_id'])) {
        $updates[] = "division_id = ?";
        $params[] = (int)$_POST['division_id'] ?: null;
    }

    if (isset($_POST['category_id'])) {
        $updates[] = "category_id = ?";
        $params[] = (int)$_POST['category_id'] ?: null;
    }

    if (isset($_POST['is_recurring'])) {
        $updates[] = "is_recurring = ?";
        $params[] = (int)$_POST['is_recurring'];
    }

    if (isset($_POST['due_date'])) {
        $updates[] = "due_date = ?";
        $params[] = $_POST['due_date'] ?: null;
    }

    if (isset($_POST['notes'])) {
        $updates[] = "notes = ?";
        $params[] = trim($_POST['notes']);
    }

    if (empty($updates)) {
        throw new Exception('Tidak ada field untuk di-update');
    }

    $params[] = $billId;
    $query = "UPDATE monthly_bills SET " . implode(', ', $updates) . " WHERE id = ?";

    $result = $db->query($query, $params);
    if (!$result) {
        throw new Exception('Failed to update bill');
    }

    if ($items !== null) {
        $db->query("DELETE FROM bill_items WHERE bill_id = ?", [$billId]);
        foreach ($items as $it) {
            $db->query(
                "INSERT INTO bill_items (bill_id, item_date, item_name, amount) VALUES (?, ?, ?, ?)",
                [$billId, $it['item_date'], $it['item_name'], $it['amount']]
            );
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Tagihan berhasil diperbarui'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
