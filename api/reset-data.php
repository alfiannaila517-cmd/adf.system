<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

// Only admin or developer can reset data
if (!$auth->hasRole('admin') && !$auth->hasRole('developer')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak. Hanya admin atau developer yang bisa reset data.']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$resetType = $input['reset_type'] ?? '';

if (empty($resetType)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Tipe reset tidak valid.']);
    exit;
}

/**
 * Helper function to check if table exists
 */
function tableExists($conn, $tableName) {
    try {
        $result = $conn->query("SHOW TABLES LIKE '{$tableName}'");
        return $result->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Helper function to check if column exists in table
 */
function columnExists($conn, $tableName, $columnName) {
    try {
        $result = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        return $result->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Helper function to safely delete data from a table
 */
function safeDelete($conn, $table, $where = null, $params = []) {
    if (!tableExists($conn, $table)) {
        // Table doesn't exist - not an error, just no data to delete
        return ['deleted' => 0, 'error' => null];
    }
    
    try {
        // Count first
        $countSql = "SELECT COUNT(*) FROM `{$table}`" . ($where ? " WHERE {$where}" : "");
        $stmt = $conn->prepare($countSql);
        $stmt->execute($params);
        $count = $stmt->fetchColumn();
        
        // Delete
        $deleteSql = "DELETE FROM `{$table}`" . ($where ? " WHERE {$where}" : "");
        $stmt = $conn->prepare($deleteSql);
        $stmt->execute($params);
        
        return ['deleted' => $count, 'error' => null];
    } catch (Exception $e) {
        return ['deleted' => 0, 'error' => $e->getMessage()];
    }
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $deletedCount = 0;
    $tables = [];
    $errors = [];
    $businessId = defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : null;
    
    switch ($resetType) {
        case 'accounting':
            // Get business ID first
            $businessDbId = getMasterBusinessId();
            $tables = [];
            
            // Reset cash_book table in BUSINESS database
            // This is the main cash_book used by modules/cashbook/index.php
            if (tableExists($conn, 'cash_book')) {
                // Check if business_id column exists
                $hasBizIdCol = columnExists($conn, 'cash_book', 'business_id');
                
                if ($hasBizIdCol && $businessDbId) {
                    $result = safeDelete($conn, 'cash_book', 'business_id = :business_id', ['business_id' => $businessDbId]);
                } else {
                    // No business_id column - delete ALL records from business DB cash_book
                    $result = safeDelete($conn, 'cash_book');
                }
                $deletedCount = $result['deleted'];
                if ($result['error']) $errors[] = $result['error'];
                $tables[] = 'cash_book (business db: ' . $deletedCount . ' records)';
            } else {
                $deletedCount = 0;
                $errors[] = 'Table cash_book not found in business database';
            }
            
            // Also reset cash_balance table in business database if exists
            if (tableExists($conn, 'cash_balance')) {
                $result = safeDelete($conn, 'cash_balance');
                $deletedCount += $result['deleted'];
                $tables[] = 'cash_balance (business db)';
            }
            
            // Also reset cash accounting tables in MASTER database
            try {
                // Connect to master database (MASTER_DB_NAME already resolves to the correct
                // per-hosting-account DB name, e.g. adfb2574_adf on the main site - don't
                // hardcode it, or this breaks on other cPanel deployments like Sunsea).
                $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';

                $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $masterDbName, DB_USER, DB_PASS);
                $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Delete cash_book from MASTER database for this business (if table has business_id)
                try {
                    $chkTable = $masterDb->query("SHOW TABLES LIKE 'cash_book'");
                    if ($chkTable && $chkTable->rowCount() > 0) {
                        $chkCol = $masterDb->query("SHOW COLUMNS FROM cash_book LIKE 'business_id'");
                        $hasBizCol = $chkCol && $chkCol->rowCount() > 0;
                        
                        if ($hasBizCol && $businessDbId) {
                            $stmt = $masterDb->prepare("DELETE FROM cash_book WHERE business_id = ?");
                            $stmt->execute([$businessDbId]);
                            $deletedCashBook = $stmt->rowCount();
                        } else {
                            // No business_id column - delete all (only safe for single-business)
                            $stmt = $masterDb->query("DELETE FROM cash_book");
                            $deletedCashBook = $stmt->rowCount();
                        }
                        $deletedCount += $deletedCashBook;
                        $tables[] = 'cash_book (master db: ' . $deletedCashBook . ' records)';
                    }
                } catch (Exception $e) {
                    // cash_book might not exist in master
                    $errors[] = 'Master cash_book: ' . $e->getMessage();
                }
                
                // Delete cash_account_transactions for this business
                try {
                    $stmt = $masterDb->prepare("DELETE FROM cash_account_transactions WHERE cash_account_id IN (SELECT id FROM cash_accounts WHERE business_id = ?)");
                    $stmt->execute([$businessDbId]);
                    $deletedTransactions = $stmt->rowCount();
                    $deletedCount += $deletedTransactions;
                    $tables[] = 'cash_account_transactions (' . $deletedTransactions . ' records)';
                } catch (Exception $e) {
                    $errors[] = 'cash_account_transactions: ' . $e->getMessage();
                }
                
                // Reset current_balance to 0 for all accounts
                $updatedAccounts = 0;
                try {
                    $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = 0 WHERE business_id = ?");
                    $stmt->execute([$businessDbId]);
                    $updatedAccounts = $stmt->rowCount();
                } catch (Exception $e) {
                    $errors[] = 'cash_accounts reset: ' . $e->getMessage();
                }
                
                // Also reset cash_balance table in master if exists
                try {
                    $chkTable = $masterDb->query("SHOW TABLES LIKE 'cash_balance'");
                    if ($chkTable && $chkTable->rowCount() > 0) {
                        $masterDb->query("DELETE FROM cash_balance");
                        $tables[] = 'cash_balance (master db)';
                    }
                } catch (Exception $e) {}
                
                $message = "Data accounting berhasil direset. {$deletedCount} transaksi dihapus, {$updatedAccounts} akun kas di-reset. Tables: " . implode(', ', $tables);
                
            } catch (Exception $e) {
                $errors[] = "Error reset cash accounts: " . $e->getMessage();
                $message = "Data accounting berhasil direset. {$deletedCount} transaksi dihapus. Tables: " . implode(', ', $tables);
            }
            
            break;
            
        case 'bookings':
            // Reset bookings/reservations
            $bookingTables = ['bookings', 'reservations', 'booking_extras', 'booking_payments'];
            foreach ($bookingTables as $table) {
                if ($businessId && tableExists($conn, $table) && columnExists($conn, $table, 'business_id')) {
                    $result = safeDelete($conn, $table, 'business_id = :business_id', ['business_id' => $businessId]);
                } else {
                    $result = safeDelete($conn, $table);
                }
                $deletedCount += $result['deleted'];
                if ($result['error'] && strpos($result['error'], 'tidak ditemukan') === false) {
                    $errors[] = $result['error'];
                }
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data booking/reservasi berhasil direset. {$deletedCount} record dihapus.";
            break;
            
        case 'invoices':
            // Reset invoices / faktur penjualan
            // Note: Actual tables are sales_invoices_header & sales_invoices_detail
            $invoiceTables = ['sales_invoices_detail', 'sales_invoices_header', 'invoices', 'invoice_items', 'invoice_payments'];
            foreach ($invoiceTables as $table) {
                if ($businessId && tableExists($conn, $table) && columnExists($conn, $table, 'business_id')) {
                    $result = safeDelete($conn, $table, 'business_id = :business_id', ['business_id' => $businessId]);
                } else {
                    $result = safeDelete($conn, $table);
                }
                $deletedCount += $result['deleted'];
                if ($result['error'] && strpos($result['error'], 'tidak ditemukan') === false) {
                    $errors[] = $result['error'];
                }
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data faktur penjualan berhasil direset. {$deletedCount} record dihapus.";
            break;
            
        case 'procurement':
            // Reset PO & Procurement
            // Note: Actual tables are purchase_orders_header & purchase_orders_detail
            $poTables = ['purchase_orders_detail', 'purchase_orders_header', 'po_images', 'purchase_orders', 'purchase_order_items', 'goods_receipts', 'goods_receipt_items'];
            foreach ($poTables as $table) {
                if ($businessId && tableExists($conn, $table) && columnExists($conn, $table, 'business_id')) {
                    $result = safeDelete($conn, $table, 'business_id = :business_id', ['business_id' => $businessId]);
                } else {
                    $result = safeDelete($conn, $table);
                }
                $deletedCount += $result['deleted'];
                if ($result['error'] && strpos($result['error'], 'tidak ditemukan') === false) {
                    $errors[] = $result['error'];
                }
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data procurement (PO) berhasil direset. {$deletedCount} record dihapus.";
            break;
            
        case 'inventory':
            // Reset inventory/stock
            $invTables = ['inventory', 'stock_movements', 'stock_adjustments'];
            foreach ($invTables as $table) {
                if ($businessId && tableExists($conn, $table) && columnExists($conn, $table, 'business_id')) {
                    $result = safeDelete($conn, $table, 'business_id = :business_id', ['business_id' => $businessId]);
                } else {
                    $result = safeDelete($conn, $table);
                }
                $deletedCount += $result['deleted'];
                if ($result['error'] && strpos($result['error'], 'tidak ditemukan') === false) {
                    $errors[] = $result['error'];
                }
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data inventory berhasil direset. {$deletedCount} record dihapus.";
            break;
            
        case 'employees':
            // Reset employees
            $empTables = ['employees', 'staff', 'karyawan']; // Check multiple possible table names
            $foundTable = false;
            foreach ($empTables as $empTable) {
                if (tableExists($conn, $empTable)) {
                    $foundTable = true;
                    if ($businessId && columnExists($conn, $empTable, 'business_id')) {
                        $result = safeDelete($conn, $empTable, 'business_id = :business_id', ['business_id' => $businessId]);
                    } else {
                        $result = safeDelete($conn, $empTable);
                    }
                    $deletedCount += $result['deleted'];
                    if ($result['error']) $errors[] = $result['error'];
                    $tables[] = $empTable;
                    break;
                }
            }
            if (!$foundTable) {
                // No employees table found - not an error, just no data
                $deletedCount = 0;
            }
            $message = "Data karyawan berhasil direset. {$deletedCount} karyawan dihapus.";
            break;
            
        case 'users':
            // Reset users (except admin)
            $result = safeDelete($conn, 'users', "role != 'admin'");
            $deletedCount = $result['deleted'];
            if ($result['error']) $errors[] = $result['error'];
            $tables[] = 'users';
            $message = "Data user berhasil direset. {$deletedCount} user (selain admin) dihapus.";
            break;
            
        case 'guests':
            // Reset guests
            if ($businessId && tableExists($conn, 'guests') && columnExists($conn, 'guests', 'business_id')) {
                $result = safeDelete($conn, 'guests', 'business_id = :business_id', ['business_id' => $businessId]);
            } else {
                $result = safeDelete($conn, 'guests');
            }
            $deletedCount = $result['deleted'];
            if ($result['error']) $errors[] = $result['error'];
            $tables[] = 'guests';
            $message = "Data tamu berhasil direset. {$deletedCount} tamu dihapus.";
            break;
            
        case 'menu':
            // Reset menu items (for cafe/restaurant)
            $menuTables = ['menu_items', 'menu_categories'];
            foreach ($menuTables as $table) {
                if ($businessId && tableExists($conn, $table) && columnExists($conn, $table, 'business_id')) {
                    $result = safeDelete($conn, $table, 'business_id = :business_id', ['business_id' => $businessId]);
                } else {
                    $result = safeDelete($conn, $table);
                }
                $deletedCount += $result['deleted'];
                if ($result['error'] && strpos($result['error'], 'tidak ditemukan') === false) {
                    $errors[] = $result['error'];
                }
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data menu berhasil direset. {$deletedCount} item dihapus.";
            break;
            
        case 'orders':
            // Reset orders (for cafe/restaurant)
            $orderTables = ['orders', 'order_items'];
            foreach ($orderTables as $table) {
                if ($businessId && tableExists($conn, $table) && columnExists($conn, $table, 'business_id')) {
                    $result = safeDelete($conn, $table, 'business_id = :business_id', ['business_id' => $businessId]);
                } else {
                    $result = safeDelete($conn, $table);
                }
                $deletedCount += $result['deleted'];
                if ($result['error'] && strpos($result['error'], 'tidak ditemukan') === false) {
                    $errors[] = $result['error'];
                }
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data orders berhasil direset. {$deletedCount} order dihapus.";
            break;
            
        case 'reports':
            // Reset shift reports
            $reportTables = ['shift_reports', 'daily_reports', 'breakfast_records'];
            foreach ($reportTables as $table) {
                if ($businessId && tableExists($conn, $table) && columnExists($conn, $table, 'business_id')) {
                    $result = safeDelete($conn, $table, 'business_id = :business_id', ['business_id' => $businessId]);
                } else {
                    $result = safeDelete($conn, $table);
                }
                $deletedCount += $result['deleted'];
                if ($result['error'] && strpos($result['error'], 'tidak ditemukan') === false) {
                    $errors[] = $result['error'];
                }
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data reports berhasil direset. {$deletedCount} record dihapus.";
            break;
            
        case 'logs':
            // Reset activity logs
            $logTables = ['activity_logs', 'audit_logs', 'system_logs'];
            foreach ($logTables as $table) {
                $result = safeDelete($conn, $table);
                $deletedCount += $result['deleted'];
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data logs berhasil direset. {$deletedCount} log dihapus.";
            break;
            
        // ===============================================
        // CQC SPECIFIC RESET OPTIONS
        // ===============================================
        case 'cqc_cashbook':
            // Reset CQC cash_book from CQC DATABASE (adf_cqc)
            // CQC has its own cash_book table in adf_cqc database
            try {
                require_once __DIR__ . '/../modules/cqc-projects/db-helper.php';
                $cqcPdo = getCQCDatabaseConnection();
                
                // Delete ALL from cash_book in CQC database
                if (tableExists($cqcPdo, 'cash_book')) {
                    $stmt = $cqcPdo->prepare("DELETE FROM cash_book");
                    $stmt->execute();
                    $deletedCashbook = $stmt->rowCount();
                    $deletedCount += $deletedCashbook;
                    $tables[] = 'cash_book (adf_cqc: ' . $deletedCashbook . ' records)';
                }
                
                // Delete cash_balance if exists
                if (tableExists($cqcPdo, 'cash_balance')) {
                    $stmt = $cqcPdo->prepare("DELETE FROM cash_balance");
                    $stmt->execute();
                    $deletedCount += $stmt->rowCount();
                    $tables[] = 'cash_balance';
                }
                
                // Also reset from master database for CQC business (MASTER_DB_NAME already
                // resolves to the correct per-hosting-account DB name - don't hardcode it).
                $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';

                $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $masterDbName, DB_USER, DB_PASS);
                $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Get CQC business ID from master (should be 7)
                $businessDbId = getMasterBusinessId();
                
                // Delete cash_account_transactions for CQC business
                try {
                    $stmt = $masterDb->prepare("DELETE FROM cash_account_transactions WHERE cash_account_id IN (SELECT id FROM cash_accounts WHERE business_id = ?)");
                    $stmt->execute([$businessDbId]);
                    $deletedTransactions = $stmt->rowCount();
                    $deletedCount += $deletedTransactions;
                    $tables[] = 'cash_account_transactions (' . $deletedTransactions . ')';
                } catch (Exception $e) {}
                
                // Reset current_balance to 0 for all CQC accounts
                try {
                    $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = 0 WHERE business_id = ?");
                    $stmt->execute([$businessDbId]);
                } catch (Exception $e) {}
                
                $message = "Data Buku Kas CQC berhasil direset. {$deletedCount} transaksi dihapus. Tables: " . implode(', ', $tables);
                
            } catch (Exception $e) {
                $errors[] = "Error reset CQC cashbook: " . $e->getMessage();
                $message = "Error: " . $e->getMessage();
            }
            break;
            
        case 'cqc_projects':
            // Reset CQC projects and all related data
            try {
                // Get CQC database connection
                require_once __DIR__ . '/../modules/cqc-projects/db-helper.php';
                $cqcPdo = getCQCDatabaseConnection();
                
                // Delete expenses first (foreign key)
                $stmt = $cqcPdo->prepare("DELETE FROM cqc_project_expenses");
                $stmt->execute();
                $deletedCount += $stmt->rowCount();
                $tables[] = 'cqc_project_expenses';
                
                // Delete projects
                $stmt = $cqcPdo->prepare("DELETE FROM cqc_projects");
                $stmt->execute();
                $deletedCount += $stmt->rowCount();
                $tables[] = 'cqc_projects';
                
                $message = "Data Proyek CQC berhasil direset. {$deletedCount} record dihapus.";
                
            } catch (Exception $e) {
                $errors[] = "Error reset CQC projects: " . $e->getMessage();
                $message = "Error: " . $e->getMessage();
            }
            break;
            
        case 'cqc_expenses':
            // Reset only CQC project expenses (keep projects)
            try {
                // Get CQC database connection
                require_once __DIR__ . '/../modules/cqc-projects/db-helper.php';
                $cqcPdo = getCQCDatabaseConnection();
                
                // Delete all expenses
                $stmt = $cqcPdo->prepare("DELETE FROM cqc_project_expenses");
                $stmt->execute();
                $deletedCount = $stmt->rowCount();
                $tables[] = 'cqc_project_expenses';
                
                // Reset spent_idr to 0 for all projects
                $stmt = $cqcPdo->prepare("UPDATE cqc_projects SET spent_idr = 0");
                $stmt->execute();
                $updatedProjects = $stmt->rowCount();
                
                $message = "Data Pengeluaran Proyek CQC berhasil direset. {$deletedCount} expense dihapus, {$updatedProjects} proyek di-update.";
                
            } catch (Exception $e) {
                $errors[] = "Error reset CQC expenses: " . $e->getMessage();
                $message = "Error: " . $e->getMessage();
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Tipe reset tidak valid: ' . $resetType]);
            exit;
    }
    
    // Filter out empty tables (skip descriptive entries like "cash_book (master db: N records)"
    // since tableExists() would never match those and silently drop real info from the response).
    $tables = array_filter($tables, function($t) use ($conn) {
        if (strpos($t, ' ') !== false) {
            return true;
        }
        return tableExists($conn, $t);
    });
    
    $response = [
        'success' => true,
        'message' => $message,
        'deleted_count' => $deletedCount,
        'tables' => array_values($tables)
    ];
    
    if (!empty($errors)) {
        $response['warnings'] = $errors;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error saat reset data: ' . $e->getMessage()
    ]);
}
