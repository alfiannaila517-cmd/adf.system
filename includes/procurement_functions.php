<?php

/**
 * Procurement Module Functions
 * Narayana Hotel Management System
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/CloudinaryHelper.php';

/**
 * Generate Purchase Order Number
 * Format: PO-YYYYMM-XXXX
 * 
 * @return string Generated PO number
 */
function generatePONumber()
{
    $db = Database::getInstance();

    $prefix = 'PO-' . date('Ym') . '-';

    // Get the last PO number for this month
    $lastPO = $db->fetchOne("
        SELECT po_number 
        FROM purchase_orders_header 
        WHERE po_number LIKE ? 
        ORDER BY po_number DESC 
        LIMIT 1
    ", [$prefix . '%']);

    if ($lastPO) {
        // Extract the sequence number
        $lastNumber = (int)substr($lastPO['po_number'], -4);
        $newNumber = $lastNumber + 1;
    } else {
        $newNumber = 1;
    }

    return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
}

/**
 * Create a new Purchase Order
 * 
 * @param int $supplier_id Supplier ID
 * @param string $po_date Purchase Order date (Y-m-d format)
 * @param array $items Array of items with keys: item_name, quantity, unit_price, division_id
 * @param array $options Optional parameters: expected_delivery_date, notes, status
 * @return array ['success' => bool, 'po_id' => int, 'po_number' => string, 'message' => string]
 */
function createPurchaseOrder($supplier_id, $po_date, $items, $options = [])
{
    $db = Database::getInstance();

    try {
        // Validate inputs
        if (empty($supplier_id) || !is_numeric($supplier_id)) {
            throw new Exception("Invalid supplier ID");
        }

        if (empty($po_date)) {
            throw new Exception("Purchase Order date is required");
        }

        if (empty($items) || !is_array($items)) {
            throw new Exception("Items array is required and must not be empty");
        }

        // Verify supplier exists
        $supplier = $db->fetchOne("SELECT id FROM suppliers WHERE id = ? AND is_active = 1", [$supplier_id]);
        if (!$supplier) {
            throw new Exception("Supplier not found or inactive");
        }

        // Begin transaction
        $db->getConnection()->beginTransaction();

        // Calculate totals
        $total_amount = 0;
        $line_number = 1;
        $validated_items = [];

        foreach ($items as $item) {
            // Validate each item
            if (empty($item['item_name'])) {
                throw new Exception("Item name is required for line {$line_number}");
            }

            if (!isset($item['quantity']) || !is_numeric($item['quantity']) || $item['quantity'] <= 0) {
                throw new Exception("Valid quantity is required for line {$line_number}");
            }

            if (!isset($item['unit_price']) || !is_numeric($item['unit_price']) || $item['unit_price'] < 0) {
                throw new Exception("Valid unit price is required for line {$line_number}");
            }

            if (empty($item['division_id']) || !is_numeric($item['division_id'])) {
                throw new Exception("Division ID is required for line {$line_number}");
            }

            // Verify division exists
            $division = $db->fetchOne("SELECT id FROM divisions WHERE id = ?", [$item['division_id']]);
            if (!$division) {
                throw new Exception("Division not found for line {$line_number}");
            }

            // Calculate subtotal
            $subtotal = $item['quantity'] * $item['unit_price'];
            $total_amount += $subtotal;

            // Store validated item
            $validated_items[] = [
                'line_number' => $line_number,
                'item_name' => trim($item['item_name']),
                'item_description' => isset($item['item_description']) ? trim($item['item_description']) : null,
                'unit_of_measure' => isset($item['unit_of_measure']) ? trim($item['unit_of_measure']) : 'pcs',
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'subtotal' => $subtotal,
                'division_id' => $item['division_id'],
                'notes' => isset($item['notes']) ? trim($item['notes']) : null
            ];

            $line_number++;
        }

        // Get current user ID
        $auth = new Auth();
        $currentUser = $auth->getCurrentUser();
        $created_by = $currentUser['id'];

        // Fix: Validate created_by user exists (Handle session mismatch)
        $user_check = $db->fetchOne("SELECT id FROM users WHERE id = ?", [$created_by]);
        if (!$user_check) {
            // Try to find by username
            $user_by_name = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$currentUser['username']]);
            if ($user_by_name) {
                $created_by = $user_by_name['id'];
            } else {
                // Fallback to Admin (ID 1)
                $admin = $db->fetchOne("SELECT id FROM users WHERE id = 1 OR role = 'admin' LIMIT 1");
                $created_by = $admin ? $admin['id'] : 1;
            }
        }

        // Generate PO Number
        $po_number = generatePONumber();
        $businessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
        if ($businessId <= 0 && defined('ACTIVE_BUSINESS_ID') && function_exists('getNumericBusinessId')) {
            $resolvedBusinessId = getNumericBusinessId((string)ACTIVE_BUSINESS_ID);
            if (!empty($resolvedBusinessId)) {
                $businessId = (int)$resolvedBusinessId;
            }
        }
        if ($businessId <= 0 && !empty($_SESSION['active_business_id']) && function_exists('getNumericBusinessId')) {
            $resolvedBusinessId = getNumericBusinessId((string)$_SESSION['active_business_id']);
            if (!empty($resolvedBusinessId)) {
                $businessId = (int)$resolvedBusinessId;
            }
        }
        if ($businessId <= 0) {
            $businessId = null;
        }

        // Prepare header data
        $discount_amount = isset($options['discount_amount']) ? $options['discount_amount'] : 0;
        $tax_amount = isset($options['tax_amount']) ? $options['tax_amount'] : 0;
        $grand_total = $total_amount - $discount_amount + $tax_amount;

        // Probe actual columns to avoid INSERT failures on older-schema DBs
        $hdrCols = $db->fetchAll("SHOW COLUMNS FROM purchase_orders_header");
        $hdrColNames = array_column($hdrCols, 'Field');

        $header_data = [
            'business_id' => $businessId,
            'po_number'   => $po_number,
            'supplier_id' => $supplier_id,
            'po_date'     => $po_date,
            'status'      => isset($options['status']) ? $options['status'] : 'draft',
            'total_amount'=> $total_amount,
            'notes'       => isset($options['notes']) ? $options['notes'] : null,
            'created_by'  => $created_by,
        ];
        if (in_array('expected_delivery_date', $hdrColNames)) {
            $header_data['expected_delivery_date'] = isset($options['expected_delivery_date']) ? $options['expected_delivery_date'] : null;
        }
        if (in_array('discount_amount', $hdrColNames)) {
            $header_data['discount_amount'] = $discount_amount;
        }
        if (in_array('tax_amount', $hdrColNames)) {
            $header_data['tax_amount'] = $tax_amount;
        }
        if (in_array('grand_total', $hdrColNames)) {
            $header_data['grand_total'] = $grand_total;
        }

        // Insert header
        $po_header_id = $db->insert('purchase_orders_header', $header_data);

        if (!$po_header_id) {
            throw new Exception("Failed to create Purchase Order header");
        }

        // Insert details (probe columns once to avoid failures on older-schema DBs)
        $dtlCols    = $db->fetchAll("SHOW COLUMNS FROM purchase_orders_detail");
        $dtlColNames = array_column($dtlCols, 'Field');

        foreach ($validated_items as $item) {
            // Build detail row using only columns that exist in this DB's schema
            $detail_data = [
                'po_header_id' => $po_header_id,
                'item_name'    => $item['item_name'],
                'quantity'     => $item['quantity'],
                'unit_price'   => $item['unit_price'],
            ];
            if (in_array('received_quantity', $dtlColNames)) {
                $detail_data['received_quantity'] = 0;
            }
            // unit column: new schema = unit_of_measure, old schema = unit
            if (in_array('unit_of_measure', $dtlColNames)) {
                $detail_data['unit_of_measure'] = $item['unit_of_measure'];
            } elseif (in_array('unit', $dtlColNames)) {
                $detail_data['unit'] = $item['unit_of_measure'];
            }
            // subtotal column: new schema = subtotal, old schema = total_price
            if (in_array('subtotal', $dtlColNames)) {
                $detail_data['subtotal'] = $item['subtotal'];
            } elseif (in_array('total_price', $dtlColNames)) {
                $detail_data['total_price'] = $item['subtotal'];
            }
            if (in_array('line_number', $dtlColNames)) {
                $detail_data['line_number'] = $item['line_number'];
            }
            if (in_array('item_description', $dtlColNames)) {
                $detail_data['item_description'] = $item['item_description'];
            }
            if (in_array('division_id', $dtlColNames)) {
                $detail_data['division_id'] = $item['division_id'];
            }
            if (in_array('notes', $dtlColNames)) {
                $detail_data['notes'] = $item['notes'];
            }

            $detail_id = $db->insert('purchase_orders_detail', $detail_data);

            if (!$detail_id) {
                throw new Exception("Failed to insert item: {$item['item_name']}");
            }
        }

        // Commit transaction
        $db->getConnection()->commit();

        return [
            'success' => true,
            'po_id' => $po_header_id,
            'po_number' => $po_number,
            'total_amount' => $total_amount,
            'grand_total' => $grand_total,
            'items_count' => count($validated_items),
            'message' => "Purchase Order {$po_number} created successfully"
        ];
    } catch (Exception $e) {
        // Rollback on error
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }

        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Get Purchase Order by ID
 * 
 * @param int $po_id Purchase Order ID
 * @return array|null Purchase Order data with details
 */
function getPurchaseOrder($po_id)
{
    $db = Database::getInstance();

    // Get header
    $header = $db->fetchOne("
        SELECT 
            poh.*,
            s.supplier_name,
            s.supplier_code,
            u.full_name as created_by_name
        FROM purchase_orders_header poh
        LEFT JOIN suppliers s ON poh.supplier_id = s.id
        LEFT JOIN users u ON poh.created_by = u.id
        WHERE poh.id = ?
    ", [$po_id]);

    if (!$header) {
        return null;
    }

    // Get details
    $details = $db->fetchAll("
        SELECT 
            pod.*,
            d.division_name,
            d.division_code
        FROM purchase_orders_detail pod
        LEFT JOIN divisions d ON pod.division_id = d.id
        WHERE pod.po_header_id = ?
        ORDER BY pod.id
    ", [$po_id]);

    $header['items'] = $details;

    return $header;
}

/**
 * Update Purchase Order status
 * 
 * @param int $po_id Purchase Order ID
 * @param string $status New status (draft, submitted, approved, rejected, partially_received, completed, cancelled)
 * @param int $approved_by User ID who approved (optional)
 * @return array ['success' => bool, 'message' => string]
 */
function updatePurchaseOrderStatus($po_id, $status, $approved_by = null)
{
    $db = Database::getInstance();

    try {
        // Validate status
        $valid_statuses = ['draft', 'submitted', 'approved', 'rejected', 'partially_received', 'completed', 'cancelled'];
        if (!in_array($status, $valid_statuses)) {
            throw new Exception("Invalid status: {$status}");
        }

        // Check if PO exists
        $po = $db->fetchOne("SELECT id, status FROM purchase_orders_header WHERE id = ?", [$po_id]);
        if (!$po) {
            throw new Exception("Purchase Order not found");
        }

        $update_data = ['status' => $status];

        // If approving, set approved_by and approved_at
        if ($status === 'approved' && $approved_by) {
            $update_data['approved_by'] = $approved_by;
            $update_data['approved_at'] = date('Y-m-d H:i:s');
        }

        $result = $db->update('purchase_orders_header', $update_data, 'id = :id', ['id' => $po_id]);

        if ($result) {
            return [
                'success' => true,
                'message' => "Purchase Order status updated to {$status}"
            ];
        } else {
            throw new Exception("Failed to update status");
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

function generateGudangNasitaStockCode()
{
    $db = Database::getInstance();
    $prefix = 'GN-' . date('Ym') . '-';

    $lastStock = $db->fetchOne("\n        SELECT stock_code\n        FROM gudang_nasita_stock\n        WHERE stock_code LIKE ?\n        ORDER BY stock_code DESC\n        LIMIT 1\n    ", [$prefix . '%']);

    if ($lastStock && !empty($lastStock['stock_code'])) {
        $lastNumber = (int)substr($lastStock['stock_code'], -4);
        $newNumber = $lastNumber + 1;
    } else {
        $newNumber = 1;
    }

    return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
}

function getGudangNasitaStock($limit = 200)
{
    $db = Database::getInstance();

    return $db->fetchAll("\n        SELECT\n            gs.*,\n            COALESCE((SELECT SUM(quantity) FROM gudang_nasita_movements gm WHERE gm.stock_id = gs.id AND gm.movement_type = 'in_supplier'), 0) AS total_in,\n            COALESCE((SELECT SUM(quantity) FROM gudang_nasita_movements gm WHERE gm.stock_id = gs.id AND gm.movement_type = 'out_transfer'), 0) AS total_out\n        FROM gudang_nasita_stock gs\n        WHERE gs.is_active = 1\n        ORDER BY COALESCE(gs.category, 'lainnya') ASC, gs.item_name ASC\n        LIMIT {$limit}\n    ");
}

function getGudangNasitaTransfers($limit = 50)
{
    $db = Database::getInstance();

    return $db->fetchAll("\n        SELECT\n            gt.*,\n            u.full_name AS created_by_name,\n            r.full_name AS received_by_name,\n            COUNT(gti.id) AS items_count,\n            COALESCE(SUM(gti.quantity), 0) AS total_qty\n        FROM gudang_nasita_transfers gt\n        LEFT JOIN users u ON gt.created_by = u.id\n        LEFT JOIN users r ON gt.received_by = r.id\n        LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id\n        GROUP BY gt.id\n        ORDER BY gt.created_at DESC\n        LIMIT {$limit}\n    ");
}

function addGudangNasitaManualStock($itemName, $unit, $quantity, $createdBy, $options = [])
{
    $db = Database::getInstance();

    try {
        $itemName = trim((string)$itemName);
        $unit = trim((string)$unit);
        $quantity = (float)$quantity;
        $category = strtolower(trim((string)($options['category'] ?? '')));
        if ($category === '') {
            $category = 'lainnya';
        }

        if ($itemName === '') {
            throw new Exception('Nama item wajib diisi');
        }
        if ($unit === '') {
            $unit = 'pcs';
        }
        if ($quantity <= 0) {
            throw new Exception('Qty manual harus lebih dari 0');
        }

        $supplierName = trim((string)($options['supplier_name'] ?? ''));
        $notes = trim((string)($options['notes'] ?? ''));
        $reorderLevel = isset($options['reorder_level']) ? (float)$options['reorder_level'] : null;

        $db->getConnection()->beginTransaction();

        // Match by name only so unit differences don't create duplicate stock entries
        $stock = $db->fetchOne(
            "SELECT * FROM gudang_nasita_stock WHERE LOWER(item_name) = LOWER(?) AND is_active = 1 LIMIT 1",
            [$itemName]
        );

        if (!$stock) {
            $stockId = $db->insert('gudang_nasita_stock', [
                'stock_code' => generateGudangNasitaStockCode(),
                'item_name' => $itemName,
                'category' => $category,
                'unit' => $unit,
                'quantity' => 0,
                'reorder_level' => $reorderLevel !== null && $reorderLevel >= 0 ? $reorderLevel : 0,
                'supplier_name' => $supplierName !== '' ? $supplierName : null,
                'notes' => $notes !== '' ? $notes : 'Input stok manual awal'
            ]);
            $stock = $db->fetchOne('SELECT * FROM gudang_nasita_stock WHERE id = ? LIMIT 1', [$stockId]);
        }

        $updateData = [
            'quantity' => (float)$stock['quantity'] + $quantity,
            'supplier_name' => $supplierName !== '' ? $supplierName : ($stock['supplier_name'] ?? null),
            'notes' => $notes !== '' ? $notes : ($stock['notes'] ?? null),
            'category' => $category,
        ];
        if ($reorderLevel !== null && $reorderLevel >= 0) {
            $updateData['reorder_level'] = $reorderLevel;
        }

        $db->update('gudang_nasita_stock', $updateData, 'id = :id', ['id' => $stock['id']]);

        $referenceNumber = 'MAN-' . date('YmdHis');
        $db->insert('gudang_nasita_movements', [
            'stock_id' => $stock['id'],
            'movement_date' => date('Y-m-d'),
            'movement_type' => 'adjustment',
            'quantity' => $quantity,
            'reference_type' => 'manual_stock',
            'reference_id' => null,
            'reference_number' => $referenceNumber,
            'notes' => $notes !== '' ? $notes : 'Input stok manual awal',
            'created_by' => $createdBy
        ]);

        $db->getConnection()->commit();

        return [
            'success' => true,
            'message' => 'Stok manual berhasil ditambahkan',
            'stock_id' => (int)$stock['id'],
            'new_qty' => (float)$updateData['quantity']
        ];
    } catch (Exception $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

function receivePurchaseOrderToGudang($po_id, array $receivedItems, $receivedBy, $notes = '')
{
    $db = Database::getInstance();

    try {
        $po = getPurchaseOrder($po_id);
        if (!$po) {
            throw new Exception('Purchase Order not found');
        }

        $db->getConnection()->beginTransaction();

        $totalReceived = 0;
        $allCompleted = true;

        foreach ($po['items'] as $item) {
            $detailId = (int)$item['id'];
            $orderedQty = (float)$item['quantity'];
            $existingReceived = (float)($item['received_quantity'] ?? 0);
            $remainingQty = max(0, $orderedQty - $existingReceived);
            $receivedQty = isset($receivedItems[$detailId]) ? (float)$receivedItems[$detailId] : 0;

            if ($receivedQty <= 0) {
                if ($remainingQty > 0) {
                    $allCompleted = false;
                }
                continue;
            }

            if ($receivedQty > $remainingQty) {
                throw new Exception('Qty received melebihi sisa qty untuk item ' . $item['item_name']);
            }

            $unit = trim($item['unit_of_measure'] ?: 'pcs');
            // Match by name only so existing stock is updated regardless of unit mismatch
            $stock = $db->fetchOne("SELECT * FROM gudang_nasita_stock WHERE LOWER(item_name) = LOWER(?) AND is_active = 1 LIMIT 1", [$item['item_name']]);
            if (!$stock) {
                $stock = $db->fetchOne("SELECT * FROM gudang_nasita_stock WHERE LOWER(item_name) LIKE LOWER(?) AND is_active = 1 ORDER BY quantity DESC LIMIT 1", ['%' . trim($item['item_name']) . '%']);
            }

            if (!$stock) {
                $stockId = $db->insert('gudang_nasita_stock', [
                    'stock_code' => generateGudangNasitaStockCode(),
                    'item_name' => trim($item['item_name']),
                    'category' => 'lainnya',
                    'unit' => $unit,
                    'quantity' => 0,
                    'supplier_name' => $po['supplier_name'] ?? null,
                    'notes' => $notes ?: ('Auto created from PO ' . $po['po_number'])
                ]);
                $stock = $db->fetchOne('SELECT * FROM gudang_nasita_stock WHERE id = ? LIMIT 1', [$stockId]);
            }

            $newQty = (float)$stock['quantity'] + $receivedQty;
            $db->update('gudang_nasita_stock', [
                'quantity' => $newQty,
                'supplier_name' => $po['supplier_name'] ?? $stock['supplier_name'],
                'notes' => $notes ?: $stock['notes']
            ], 'id = :id', ['id' => $stock['id']]);

            $db->insert('gudang_nasita_movements', [
                'stock_id' => $stock['id'],
                'movement_date' => date('Y-m-d'),
                'movement_type' => 'in_supplier',
                'quantity' => $receivedQty,
                'reference_type' => 'purchase_order',
                'reference_id' => $po_id,
                'reference_number' => $po['po_number'],
                'notes' => $notes ?: ('Received from supplier ' . ($po['supplier_name'] ?? '')),
                'created_by' => $receivedBy
            ]);

            $newReceived = $existingReceived + $receivedQty;
            $db->update('purchase_orders_detail', [
                'received_quantity' => $newReceived
            ], 'id = :id', ['id' => $detailId]);

            $totalReceived += $receivedQty;
            if ($newReceived < $orderedQty) {
                $allCompleted = false;
            }
        }

        if ($totalReceived <= 0) {
            throw new Exception('Tidak ada qty yang diterima');
        }

        if ($allCompleted) {
            $db->update('purchase_orders_header', [
                'status' => 'completed',
            ], 'id = :id', ['id' => $po_id]);
        } else {
            $db->update('purchase_orders_header', [
                'status' => 'partially_received',
            ], 'id = :id', ['id' => $po_id]);
        }

        $db->getConnection()->commit();

        return [
            'success' => true,
            'message' => 'Barang berhasil dimasukkan ke Gudang Nasita',
            'total_received' => $totalReceived,
            'all_completed' => $allCompleted
        ];
    } catch (Exception $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }

        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

function generateGudangNasitaTransferNumber()
{
    $db = Database::getInstance();
    $prefix = 'GNT-' . date('Ym') . '-';

    $lastTransfer = $db->fetchOne("\n        SELECT transfer_number\n        FROM gudang_nasita_transfers\n        WHERE transfer_number LIKE ?\n        ORDER BY transfer_number DESC\n        LIMIT 1\n    ", [$prefix . '%']);

    if ($lastTransfer && !empty($lastTransfer['transfer_number'])) {
        $lastNumber = (int)substr($lastTransfer['transfer_number'], -4);
        $newNumber = $lastNumber + 1;
    } else {
        $newNumber = 1;
    }

    return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
}

function transferGudangNasitaStock($targetBusinessId, array $items, $createdBy, $notes = '', $sourcePoId = null, $businessName = null)
{
    $db = Database::getInstance();

    try {
        if (empty($items)) {
            throw new Exception('Minimal 1 item transfer wajib diisi');
        }

        $business = $db->fetchOne('SELECT id, business_name FROM businesses WHERE id = ? LIMIT 1', [$targetBusinessId]);
        // Fallback: use pre-resolved name when businesses table doesn't have the target
        if (!$business && $businessName !== null && $businessName !== '') {
            $business = ['id' => $targetBusinessId, 'business_name' => $businessName];
        }
        if (!$business) {
            throw new Exception('Tujuan bisnis tidak ditemukan');
        }

        $db->getConnection()->beginTransaction();

        $transferNumber = generateGudangNasitaTransferNumber();

        $transferId = $db->insert('gudang_nasita_transfers', [
            'transfer_number' => $transferNumber,
            'target_business_id' => $targetBusinessId,
            'target_business_name' => $business['business_name'],
            'source_po_id' => $sourcePoId,
            'status' => 'received',
            'notes' => $notes,
            'created_by' => $createdBy
        ]);

        $totalQty = 0;
        foreach ($items as $item) {
            $stockId = (int)($item['stock_id'] ?? 0);
            $qty = (float)($item['quantity'] ?? 0);
            if ($stockId <= 0 || $qty <= 0) {
                continue;
            }

            $stock = $db->fetchOne('SELECT * FROM gudang_nasita_stock WHERE id = ? LIMIT 1', [$stockId]);
            if (!$stock) {
                throw new Exception('Stock tidak ditemukan');
            }

            $available = (float)$stock['quantity'];
            if ($qty > $available) {
                throw new Exception('Stok tidak cukup untuk item ' . $stock['item_name']);
            }

            $remaining = $available - $qty;
            $db->update('gudang_nasita_stock', [
                'quantity' => $remaining
            ], 'id = :id', ['id' => $stockId]);

            $db->insert('gudang_nasita_transfer_items', [
                'transfer_id' => $transferId,
                'stock_id' => $stockId,
                'item_name' => $stock['item_name'],
                'unit' => $stock['unit'],
                'quantity' => $qty,
                'notes' => $item['notes'] ?? null
            ]);

            $db->insert('gudang_nasita_movements', [
                'stock_id' => $stockId,
                'movement_date' => date('Y-m-d'),
                'movement_type' => 'out_transfer',
                'quantity' => $qty,
                'reference_type' => 'transfer',
                'reference_id' => $transferId,
                'reference_number' => $transferNumber,
                'target_business_id' => $targetBusinessId,
                'notes' => $notes ?: ('Transfer ke ' . $business['business_name']),
                'created_by' => $createdBy
            ]);

            $totalQty += $qty;
        }

        if ($totalQty <= 0) {
            throw new Exception('Tidak ada item transfer yang valid');
        }

        $db->getConnection()->commit();

        return [
            'success' => true,
            'message' => 'Barang berhasil ditransfer dari Gudang Nasita',
            'transfer_id' => $transferId,
            'transfer_number' => $transferNumber,
            'total_qty' => $totalQty,
            'business_name' => $business['business_name']
        ];
    } catch (Exception $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }

        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Get all Purchase Orders with filters
 * 
 * @param array $filters Optional filters: status, supplier_id, date_from, date_to
 * @param int $limit Limit results (default 100)
 * @param int $offset Offset for pagination (default 0)
 * @return array Purchase Orders list
 */
function getPurchaseOrders($filters = [], $limit = 100, $offset = 0)
{
    $db = Database::getInstance();

    $where_conditions = [];
    $params = [];

    if (isset($filters['status']) && !empty($filters['status'])) {
        $where_conditions[] = "poh.status = :status";
        $params['status'] = $filters['status'];
    }

    if (isset($filters['supplier_id']) && !empty($filters['supplier_id'])) {
        $where_conditions[] = "poh.supplier_id = :supplier_id";
        $params['supplier_id'] = $filters['supplier_id'];
    }

    if (isset($filters['business_id']) && !empty($filters['business_id'])) {
        $where_conditions[] = "poh.business_id = :business_id";
        $params['business_id'] = $filters['business_id'];
    }

    // Match business_id OR NULL (covers POs created before session was properly set)
    if (isset($filters['business_id_or_null']) && !empty($filters['business_id_or_null'])) {
        $where_conditions[] = '(poh.business_id = :biz_id_or_null OR poh.business_id IS NULL)';
        $params['biz_id_or_null'] = $filters['business_id_or_null'];
    }

    // Exclude gudang-supplier POs (GDN-* prefix) from regular business PO view
    if (!empty($filters['exclude_gdn_prefix'])) {
        $where_conditions[] = "poh.po_number NOT LIKE 'GDN-%'";
    }

    if (isset($filters['date_from']) && !empty($filters['date_from'])) {
        $where_conditions[] = "poh.po_date >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }

    if (isset($filters['date_to']) && !empty($filters['date_to'])) {
        $where_conditions[] = "poh.po_date <= :date_to";
        $params['date_to'] = $filters['date_to'];
    }

    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    $query = "
        SELECT 
            poh.*,
            s.supplier_name,
            s.supplier_code,
            u.full_name as created_by_name,
            COUNT(pod.id) as items_count,
            cb.id as payment_id
        FROM purchase_orders_header poh
        LEFT JOIN suppliers s ON poh.supplier_id = s.id
        LEFT JOIN users u ON poh.created_by = u.id
        LEFT JOIN purchase_orders_detail pod ON poh.id = pod.po_header_id
        LEFT JOIN cash_book cb ON cb.reference_no = poh.po_number AND cb.source_type = 'purchase_order'
        {$where_clause}
        GROUP BY poh.id
        ORDER BY poh.po_date DESC, poh.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ";

    return $db->fetchAll($query, $params);
}

/**
 * Approve Purchase Order and Post to Cash Book
 * This function approves the PO, marks it as completed, and creates cash_book entry
 * 
 * @param int $po_id Purchase Order ID
 * @param int $approved_by User ID who approved
 * @param array $options Optional: payment_date, payment_notes
 * @return array ['success' => bool, 'message' => string, 'cash_book_id' => int]
 */
function approvePurchaseOrderAndPay($po_id, $approved_by, $options = [])
{
    $db = Database::getInstance();

    try {
        // Get PO details
        $po = getPurchaseOrder($po_id);
        if (!$po) {
            throw new Exception("Purchase Order not found");
        }

        // Check if already approved/completed
        if (in_array($po['status'], ['completed', 'cancelled'])) {
            throw new Exception("Purchase Order already {$po['status']}");
        }

        $db->getConnection()->beginTransaction();

        // 1. Fix: Validate approved_by user exists (Handle session mismatch)
        $user_check = $db->fetchOne("SELECT id FROM users WHERE id = ?", [$approved_by]);
        if (!$user_check) {
            // Fallback to Admin (ID 1)
            $admin = $db->fetchOne("SELECT id FROM users WHERE id = 1 OR role = 'admin' LIMIT 1");
            $approved_by = $admin ? $admin['id'] : 1;
        }

        // 2. Handle File Upload (Attachment)
        $attachment_path = null;
        if (isset($options['attachment_file']) && $options['attachment_file']['error'] === UPLOAD_ERR_OK) {
            $file_extension = strtolower(pathinfo($options['attachment_file']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf', 'gif'];

            if (in_array($file_extension, $allowed_extensions)) {
                $new_filename = 'PO_' . $po['po_number'] . '_' . time() . '.' . $file_extension;
                $cloudinary = CloudinaryHelper::getInstance();
                $uploadResult = $cloudinary->smartUpload($options['attachment_file'], 'uploads/purchase_attachments', $new_filename, 'attachments', 'po_' . $po['po_number']);
                if ($uploadResult['success']) {
                    $attachment_path = $uploadResult['path'];
                }
            }
        }

        // 3. Save Attachment to Separate Table (transaction_attachments)
        if ($attachment_path) {
            $db->insert('transaction_attachments', [
                'transaction_type' => 'purchase_order',
                'transaction_id' => $po_id,
                'file_path' => $attachment_path,
                'file_name' => $new_filename,
                'file_type' => $file_extension,
                'uploaded_by' => $approved_by
            ]);
        }

        // 4. Update PO Status to Completed
        $update_data = [
            'status' => 'completed',
            'approved_by' => $approved_by,
            'approved_at' => date('Y-m-d H:i:s')
        ];

        if ($attachment_path) {
            $update_data['attachment_path'] = $attachment_path; // Backward compatibility
        }

        $db->update('purchase_orders_header', $update_data, 'id = :id', ['id' => $po_id]);

        // 5. Create Cash Book Entry (Only if not exists)
        $existing_payment = $db->fetchOne(
            "SELECT id FROM cash_book WHERE source_type = 'purchase_order' AND reference_no = ?",
            [$po['po_number']]
        );

        $cash_book_id = 0;

        if ($existing_payment) {
            // Payment already exists, skip insert
            $cash_book_id = $existing_payment['id'];
        } else {
            // Prepare cash_book entry
            $payment_date = isset($options['payment_date']) ? $options['payment_date'] : date('Y-m-d');
            $payment_notes = isset($options['payment_notes']) ? $options['payment_notes'] :
                "Pembayaran PO #{$po['po_number']} - {$po['supplier_name']}";

            // Get expense category
            // Prefer explicit Payment category, otherwise default expense
            $expense_category = $db->fetchOne("SELECT id FROM categories WHERE category_name LIKE '%Payment Supplier%' OR category_name LIKE '%Pembayaran Supplier%' LIMIT 1");

            if (!$expense_category) {
                $expense_category = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'expense' LIMIT 1");
            }

            if (!$expense_category) {
                // Create default category
                try {
                    $category_id = $db->insert('categories', [
                        'category_name' => 'Pembayaran Supplier',
                        'category_type' => 'expense',
                        'description' => 'Pembayaran PO ke Supplier',
                        'is_active' => 1
                    ]);
                } catch (Exception $cat_ex) {
                    throw new Exception("Gagal create kategori: " . $cat_ex->getMessage());
                }
            } else {
                $category_id = $expense_category['id'];
            }

            // Get division
            $division_id = 1;
            if (isset($po['items'][0]['division_id']) && $po['items'][0]['division_id'] > 0) {
                $division_id = $po['items'][0]['division_id'];
            } else {
                $first_div = $db->fetchOne("SELECT id FROM divisions LIMIT 1");
                if ($first_div) {
                    $division_id = $first_div['id'];
                }
            }

            // Post to cash_book (pengeluaran)
            $cash_book_data = [
                'transaction_date' => $payment_date,
                'transaction_time' => date('H:i:s'),
                'description' => $payment_notes,
                'amount' => $po['total_amount'],
                'transaction_type' => 'expense',
                'payment_method' => 'cash',
                'category_id' => $category_id,
                'division_id' => $division_id,
                'created_by' => $approved_by,
                'source_type' => 'purchase_order',
                'reference_no' => $po['po_number'],
                'is_editable' => 0
            ];

            try {
                $cash_book_id = $db->insert('cash_book', $cash_book_data);
                if (!$cash_book_id) {
                    throw new Exception("Insert returned false");
                }
            } catch (Exception $cb_ex) {
                throw new Exception("Gagal post ke cash book: " . $cb_ex->getMessage());
            }
        }

        $db->getConnection()->commit();

        return [
            'success' => true,
            'message' => "Purchase Order approved and payment posted to cash book",
            'po_number' => $po['po_number'],
            'amount' => $po['total_amount'],
            'cash_book_id' => $cash_book_id
        ];
    } catch (Exception $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }

        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Delete Purchase Order (only if status is draft)
    $db = Database::getInstance();
    
    try {
        // Check if PO exists and is draft
        $po = $db->fetchOne("SELECT id, status, po_number FROM purchase_orders_header WHERE id = ?", [$po_id]);
        if (!$po) {
            throw new Exception("Purchase Order not found");
        }
        
        if ($po['status'] !== 'draft') {
            throw new Exception("Only draft Purchase Orders can be deleted. Current status: {$po['status']}");
        }
        
        $db->getConnection()->beginTransaction();
        
        // Delete details first (cascade will handle this, but being explicit)
        $db->delete('purchase_orders_detail', ['po_header_id' => $po_id]);
        
        // Delete header
        $result = $db->delete('purchase_orders_header', ['id' => $po_id]);
        
        if (!$result) {
            throw new Exception("Failed to delete Purchase Order");
        }
        
        $db->getConnection()->commit();
        
        return [
            'success' => true,
            'message' => "Purchase Order {$po['po_number']} deleted successfully"
        ];
        
    } catch (Exception $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }
        
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Store a finalized Purchase Invoice (Real Purchase Transaction)
 * This function records the actual purchase and automatically posts to General Ledger
 * 
 * @param string $invoice_number Invoice number from supplier
 * @param int $supplier_id Supplier ID
 * @param string $invoice_date Invoice date (Y-m-d format)
 * @param array $items Array of items with keys: item_name, quantity, unit_price, division_id
 * @param array $options Optional parameters: po_id, due_date, received_date, notes, discount_amount, tax_amount, attachment_path
 * @return array ['success' => bool, 'purchase_id' => int, 'invoice_number' => string, 'gl_entries' => array, 'message' => string]
 */
function storePurchase($invoice_number, $supplier_id, $invoice_date, $items, $options = [])
{
    $db = Database::getInstance();

    try {
        // Validate inputs
        if (empty($invoice_number)) {
            throw new Exception("Invoice number is required");
        }

        if (empty($supplier_id) || !is_numeric($supplier_id)) {
            throw new Exception("Invalid supplier ID");
        }

        if (empty($invoice_date)) {
            throw new Exception("Invoice date is required");
        }

        if (empty($items) || !is_array($items)) {
            throw new Exception("Items array is required and must not be empty");
        }

        // Check if invoice number already exists
        $existing = $db->fetchOne("SELECT id FROM purchases_header WHERE invoice_number = ?", [$invoice_number]);
        if ($existing) {
            throw new Exception("Invoice number {$invoice_number} already exists");
        }

        // Verify supplier exists
        $supplier = $db->fetchOne("SELECT id, supplier_name FROM suppliers WHERE id = ? AND is_active = 1", [$supplier_id]);
        if (!$supplier) {
            throw new Exception("Supplier not found or inactive");
        }

        // Begin transaction
        $db->getConnection()->beginTransaction();

        // Calculate totals and validate items
        $total_amount = 0;
        $line_number = 1;
        $validated_items = [];
        $division_totals = []; // Track expense per division

        foreach ($items as $item) {
            // Validate each item
            if (empty($item['item_name'])) {
                throw new Exception("Item name is required for line {$line_number}");
            }

            if (!isset($item['quantity']) || !is_numeric($item['quantity']) || $item['quantity'] <= 0) {
                throw new Exception("Valid quantity is required for line {$line_number}");
            }

            if (!isset($item['unit_price']) || !is_numeric($item['unit_price']) || $item['unit_price'] < 0) {
                throw new Exception("Valid unit price is required for line {$line_number}");
            }

            if (empty($item['division_id']) || !is_numeric($item['division_id'])) {
                throw new Exception("Division ID is required for line {$line_number}");
            }

            // Verify division exists
            $division = $db->fetchOne("SELECT id, division_name FROM divisions WHERE id = ?", [$item['division_id']]);
            if (!$division) {
                throw new Exception("Division not found for line {$line_number}");
            }

            // Calculate subtotal
            $subtotal = $item['quantity'] * $item['unit_price'];
            $total_amount += $subtotal;

            // Track division totals for GL posting
            if (!isset($division_totals[$item['division_id']])) {
                $division_totals[$item['division_id']] = [
                    'division_name' => $division['division_name'],
                    'amount' => 0
                ];
            }
            $division_totals[$item['division_id']]['amount'] += $subtotal;

            // Store validated item
            $validated_items[] = [
                'line_number' => $line_number,
                'item_name' => trim($item['item_name']),
                'item_description' => isset($item['item_description']) ? trim($item['item_description']) : null,
                'unit_of_measure' => isset($item['unit_of_measure']) ? trim($item['unit_of_measure']) : 'pcs',
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'subtotal' => $subtotal,
                'division_id' => $item['division_id'],
                'po_detail_id' => isset($item['po_detail_id']) ? $item['po_detail_id'] : null,
                'notes' => isset($item['notes']) ? trim($item['notes']) : null
            ];

            $line_number++;
        }

        // Get current user ID
        $auth = new Auth();
        $currentUser = $auth->getCurrentUser();
        $created_by = $currentUser['user_id'];

        // Prepare header data
        $discount_amount = isset($options['discount_amount']) ? $options['discount_amount'] : 0;
        $tax_amount = isset($options['tax_amount']) ? $options['tax_amount'] : 0;
        $grand_total = $total_amount - $discount_amount + $tax_amount;
        $received_date = isset($options['received_date']) ? $options['received_date'] : $invoice_date;

        $header_data = [
            'invoice_number' => trim($invoice_number),
            'po_id' => isset($options['po_id']) ? $options['po_id'] : null,
            'supplier_id' => $supplier_id,
            'invoice_date' => $invoice_date,
            'due_date' => isset($options['due_date']) ? $options['due_date'] : null,
            'received_date' => $received_date,
            'total_amount' => $total_amount,
            'discount_amount' => $discount_amount,
            'tax_amount' => $tax_amount,
            'grand_total' => $grand_total,
            'payment_status' => 'unpaid',
            'paid_amount' => 0,
            'gl_posted' => 0,
            'notes' => isset($options['notes']) ? $options['notes'] : null,
            'attachment_path' => isset($options['attachment_path']) ? $options['attachment_path'] : null,
            'created_by' => $created_by
        ];

        // Insert header
        $purchase_header_id = $db->insert('purchases_header', $header_data);

        if (!$purchase_header_id) {
            throw new Exception("Failed to create Purchase Invoice header");
        }

        // Insert details
        foreach ($validated_items as $item) {
            $item['purchase_header_id'] = $purchase_header_id;

            $detail_id = $db->insert('purchases_detail', $item);

            if (!$detail_id) {
                throw new Exception("Failed to insert item: {$item['item_name']}");
            }
        }

        // Auto-Post to General Ledger
        $gl_entries = [];
        $fiscal_year = date('Y', strtotime($invoice_date));
        $fiscal_period = date('m', strtotime($invoice_date));

        // Entry 1: DEBIT - Expense Account (per division)
        foreach ($division_totals as $division_id => $division_data) {
            $debit_entry = [
                'gl_date' => $invoice_date,
                'account_code' => '5101', // Office Supplies / Operating Expense (can be parameterized)
                'account_name' => 'Purchase Expense - ' . $division_data['division_name'],
                'description' => "Purchase Invoice {$invoice_number} from {$supplier['supplier_name']} - {$division_data['division_name']}",
                'debit' => $division_data['amount'],
                'credit' => 0,
                'transaction_type' => 'purchase',
                'transaction_ref_id' => $purchase_header_id,
                'transaction_ref_number' => $invoice_number,
                'division_id' => $division_id,
                'fiscal_year' => $fiscal_year,
                'fiscal_period' => $fiscal_period,
                'posted_by' => $created_by,
                'notes' => "Auto-posted from Purchase Invoice"
            ];

            $gl_id = $db->insert('general_ledger', $debit_entry);
            if (!$gl_id) {
                throw new Exception("Failed to post GL entry (Debit)");
            }

            $gl_entries[] = [
                'gl_id' => $gl_id,
                'type' => 'debit',
                'account' => '5101',
                'amount' => $division_data['amount'],
                'division_id' => $division_id
            ];
        }

        // Entry 2: CREDIT - Cash/Bank Account (Accounts Payable)
        $credit_entry = [
            'gl_date' => $invoice_date,
            'account_code' => '2101', // Accounts Payable
            'account_name' => 'Accounts Payable',
            'description' => "Purchase Invoice {$invoice_number} from {$supplier['supplier_name']}",
            'debit' => 0,
            'credit' => $grand_total,
            'transaction_type' => 'purchase',
            'transaction_ref_id' => $purchase_header_id,
            'transaction_ref_number' => $invoice_number,
            'division_id' => null, // Not division-specific
            'fiscal_year' => $fiscal_year,
            'fiscal_period' => $fiscal_period,
            'posted_by' => $created_by,
            'notes' => "Auto-posted from Purchase Invoice"
        ];

        $gl_id = $db->insert('general_ledger', $credit_entry);
        if (!$gl_id) {
            throw new Exception("Failed to post GL entry (Credit)");
        }

        $gl_entries[] = [
            'gl_id' => $gl_id,
            'type' => 'credit',
            'account' => '2101',
            'amount' => $grand_total,
            'division_id' => null
        ];

        // Update purchase header to mark as GL posted
        $db->update('purchases_header', [
            'gl_posted' => 1,
            'gl_posted_at' => date('Y-m-d H:i:s')
        ], ['id' => $purchase_header_id]);

        // If linked to PO, update PO received quantities
        if (isset($options['po_id']) && $options['po_id']) {
            foreach ($validated_items as $item) {
                if ($item['po_detail_id']) {
                    // Update received quantity in PO detail
                    $db->getConnection()->exec("
                        UPDATE purchase_orders_detail 
                        SET received_quantity = received_quantity + {$item['quantity']}
                        WHERE id = {$item['po_detail_id']}
                    ");
                }
            }

            // Check if all items in PO are fully received
            $po_status = $db->fetchOne("
                SELECT 
                    CASE 
                        WHEN SUM(received_quantity) >= SUM(quantity) THEN 'completed'
                        WHEN SUM(received_quantity) > 0 THEN 'partially_received'
                        ELSE 'approved'
                    END as new_status
                FROM purchase_orders_detail
                WHERE po_header_id = ?
            ", [$options['po_id']]);

            if ($po_status) {
                $db->update('purchase_orders_header', [
                    'status' => $po_status['new_status']
                ], 'id = :id', ['id' => $options['po_id']]);
            }
        }

        // Commit transaction
        $db->getConnection()->commit();

        return [
            'success' => true,
            'purchase_id' => $purchase_header_id,
            'invoice_number' => $invoice_number,
            'total_amount' => $total_amount,
            'grand_total' => $grand_total,
            'items_count' => count($validated_items),
            'gl_entries' => $gl_entries,
            'gl_posted' => true,
            'message' => "Purchase Invoice {$invoice_number} saved and posted to GL successfully"
        ];
    } catch (Exception $e) {
        // Rollback on error
        if ($db->getConnection()->inTransaction()) {
            $db->getConnection()->rollBack();
        }

        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Get Purchase Invoice by ID
 * 
 * @param int $purchase_id Purchase Invoice ID
 * @return array|null Purchase data with details and GL entries
 */
function getPurchase($purchase_id)
{
    $db = Database::getInstance();

    // Get header
    $header = $db->fetchOne("
        SELECT 
            ph.*,
            s.supplier_name,
            s.supplier_code,
            u.full_name as created_by_name,
            poh.po_number
        FROM purchases_header ph
        LEFT JOIN suppliers s ON ph.supplier_id = s.id
        LEFT JOIN users u ON ph.created_by = u.user_id
        LEFT JOIN purchase_orders_header poh ON ph.po_id = poh.id
        WHERE ph.id = ?
    ", [$purchase_id]);

    if (!$header) {
        return null;
    }

    // Get details
    $details = $db->fetchAll("
        SELECT 
            pd.*,
            d.division_name,
            d.division_code
        FROM purchases_detail pd
        LEFT JOIN divisions d ON pd.division_id = d.id
        WHERE pd.purchase_header_id = ?
        ORDER BY pd.line_number
    ", [$purchase_id]);

    $header['items'] = $details;

    // Get GL entries if posted
    if ($header['gl_posted']) {
        $gl_entries = $db->fetchAll("
            SELECT *
            FROM general_ledger
            WHERE transaction_type = 'purchase' 
                AND transaction_ref_id = ?
                AND reversed = 0
            ORDER BY id
        ", [$purchase_id]);

        $header['gl_entries'] = $gl_entries;
    }

    return $header;
}

/**
 * Get all Purchase Invoices with filters
 * 
 * @param array $filters Optional filters: payment_status, supplier_id, date_from, date_to, gl_posted
 * @param int $limit Limit results (default 100)
 * @param int $offset Offset for pagination (default 0)
 * @return array Purchase Invoices list
 */
function getPurchases($filters = [], $limit = 100, $offset = 0)
{
    $db = Database::getInstance();

    $where_conditions = [];
    $params = [];

    if (isset($filters['payment_status']) && !empty($filters['payment_status'])) {
        $where_conditions[] = "ph.payment_status = :payment_status";
        $params['payment_status'] = $filters['payment_status'];
    }

    if (isset($filters['supplier_id']) && !empty($filters['supplier_id'])) {
        $where_conditions[] = "ph.supplier_id = :supplier_id";
        $params['supplier_id'] = $filters['supplier_id'];
    }

    if (isset($filters['date_from']) && !empty($filters['date_from'])) {
        $where_conditions[] = "ph.invoice_date >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }

    if (isset($filters['date_to']) && !empty($filters['date_to'])) {
        $where_conditions[] = "ph.invoice_date <= :date_to";
        $params['date_to'] = $filters['date_to'];
    }

    if (isset($filters['gl_posted'])) {
        $where_conditions[] = "ph.gl_posted = :gl_posted";
        $params['gl_posted'] = $filters['gl_posted'];
    }

    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    $query = "
        SELECT 
            ph.*,
            s.supplier_name,
            s.supplier_code,
            u.full_name as created_by_name,
            poh.po_number,
            COUNT(pd.id) as items_count
        FROM purchases_header ph
        LEFT JOIN suppliers s ON ph.supplier_id = s.id
        LEFT JOIN users u ON ph.created_by = u.user_id
        LEFT JOIN purchase_orders_header poh ON ph.po_id = poh.id
        LEFT JOIN purchases_detail pd ON ph.id = pd.purchase_header_id
        {$where_clause}
        GROUP BY ph.id
        ORDER BY ph.invoice_date DESC, ph.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ";

    return $db->fetchAll($query, $params);
}
