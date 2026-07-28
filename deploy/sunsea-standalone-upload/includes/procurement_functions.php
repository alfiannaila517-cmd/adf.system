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
function generatePONumber() {
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
function createPurchaseOrder($supplier_id, $po_date, $items, $options = []) {
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
        
        // Prepare header data
        $discount_amount = isset($options['discount_amount']) ? $options['discount_amount'] : 0;
        $tax_amount = isset($options['tax_amount']) ? $options['tax_amount'] : 0;
        $grand_total = $total_amount - $discount_amount + $tax_amount;
        
        $header_data = [
            'po_number' => $po_number,
            'supplier_id' => $supplier_id,
            'po_date' => $po_date,
            'expected_delivery_date' => isset($options['expected_delivery_date']) ? $options['expected_delivery_date'] : null,
            'status' => isset($options['status']) ? $options['status'] : 'draft',
            'total_amount' => $total_amount,
            'discount_amount' => $discount_amount,
            'tax_amount' => $tax_amount,
            'grand_total' => $grand_total,
            'notes' => isset($options['notes']) ? $options['notes'] : null,
            'created_by' => $created_by
        ];
        
        // Insert header
        $po_header_id = $db->insert('purchase_orders_header', $header_data);
        
        if (!$po_header_id) {
            throw new Exception("Failed to create Purchase Order header");
        }
        
        // Insert details
        foreach ($validated_items as $item) {
            $detail_data = [
                'po_header_id' => $po_header_id,
                'line_number' => $item['line_number'],
                'item_name' => $item['item_name'],
                'item_description' => $item['item_description'],
                'unit_of_measure' => $item['unit_of_measure'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'subtotal' => $item['subtotal'],
                'division_id' => $item['division_id'],
                'received_quantity' => 0,
                'notes' => $item['notes']
            ];
            
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
function getPurchaseOrder($po_id) {
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
function updatePurchaseOrderStatus($po_id, $status, $approved_by = null) {
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

/**
 * Get all Purchase Orders with filters
 * 
 * @param array $filters Optional filters: status, supplier_id, date_from, date_to
 * @param int $limit Limit results (default 100)
 * @param int $offset Offset for pagination (default 0)
 * @return array Purchase Orders list
 */
function getPurchaseOrders($filters = [], $limit = 100, $offset = 0) {
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
            cb.id as payment_id,
            COALESCE(ta.file_path, NULL) as ta_attachment_path
        FROM purchase_orders_header poh
        LEFT JOIN suppliers s ON poh.supplier_id = s.id
        LEFT JOIN users u ON poh.created_by = u.id
        LEFT JOIN purchase_orders_detail pod ON poh.id = pod.po_header_id
        LEFT JOIN cash_book cb ON cb.reference_no = poh.po_number AND cb.source_type = 'purchase_order'
        LEFT JOIN transaction_attachments ta ON ta.transaction_type = 'purchase_order' AND ta.transaction_id = poh.id
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
function approvePurchaseOrderAndPay($po_id, $approved_by, $options = []) {
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
function storePurchase($invoice_number, $supplier_id, $invoice_date, $items, $options = []) {
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
function getPurchase($purchase_id) {
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
function getPurchases($filters = [], $limit = 100, $offset = 0) {
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
