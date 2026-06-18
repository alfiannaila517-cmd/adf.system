<?php

/**
 * Hotel Services — Multi-item Invoice
 * Motor Rental, Laundry, Service, Airport Drop, Harbor Drop
 * Narayana Hotel Karimunjawa
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/CloudinaryHelper.php';
require_once '../../includes/InvoiceHelper.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db          = Database::getInstance();
$pdo         = $db->getConnection();
$currentUser = $auth->getCurrentUser();
$businessId  = $_SESSION['business_id'] ?? 1;

// ── Auto-create tables ─────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS hotel_invoices (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    business_id     INT NOT NULL DEFAULT 1,
    invoice_number  VARCHAR(30) NOT NULL UNIQUE,
    booking_id      INT DEFAULT NULL,
    guest_name      VARCHAR(120) NOT NULL,
    guest_phone     VARCHAR(30)  DEFAULT NULL,
    room_number     VARCHAR(20)  DEFAULT NULL,
    total           DECIMAL(15,2) NOT NULL DEFAULT 0,
    paid_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
    payment_status  ENUM('unpaid','paid','partial') NOT NULL DEFAULT 'unpaid',
    payment_method  VARCHAR(20)  NOT NULL DEFAULT 'cash',
    status          ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'confirmed',
    notes           TEXT         DEFAULT NULL,
    tax_rate        DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
    service_charge_rate   DECIMAL(5,2) NOT NULL DEFAULT 0,
    service_charge_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    discount_rate         DECIMAL(5,2) NOT NULL DEFAULT 0,
    discount_amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_by      INT          DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    cashbook_synced  TINYINT(1)   NOT NULL DEFAULT 0,
    KEY idx_biz (business_id),
    KEY idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add cashbook_synced to existing tables that predate this column
try {
    $pdo->query("SELECT cashbook_synced FROM hotel_invoices LIMIT 1");
} catch (\Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE hotel_invoices ADD COLUMN cashbook_synced TINYINT(1) NOT NULL DEFAULT 0");
    } catch (\Throwable $e2) {
    }
}
// Add tax columns to existing tables
try {
    $pdo->query("SELECT tax_rate FROM hotel_invoices LIMIT 1");
} catch (\Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE hotel_invoices ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0, ADD COLUMN tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0");
    } catch (\Throwable $e2) {
    }
}
// Add service_charge & discount columns to existing tables
try {
    $pdo->query("SELECT service_charge_rate FROM hotel_invoices LIMIT 1");
} catch (\Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE hotel_invoices ADD COLUMN service_charge_rate DECIMAL(5,2) NOT NULL DEFAULT 0, ADD COLUMN service_charge_amount DECIMAL(15,2) NOT NULL DEFAULT 0, ADD COLUMN discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0, ADD COLUMN discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0");
    } catch (\Throwable $e2) {
    }
}

// Migrate service_type from ENUM to VARCHAR for dynamic types
try {
    $colInfo = $pdo->query("SHOW COLUMNS FROM hotel_invoice_items LIKE 'service_type'")->fetch(PDO::FETCH_ASSOC);
    if ($colInfo && strpos($colInfo['Type'], 'enum') === 0) {
        $pdo->exec("ALTER TABLE hotel_invoice_items MODIFY service_type VARCHAR(50) NOT NULL");
    }
} catch (\Throwable $e) {
}
try {
    $colInfo2 = $pdo->query("SHOW COLUMNS FROM hotel_service_catalog LIKE 'service_type'")->fetch(PDO::FETCH_ASSOC);
    if ($colInfo2 && strpos($colInfo2['Type'], 'enum') === 0) {
        $pdo->exec("ALTER TABLE hotel_service_catalog MODIFY service_type VARCHAR(50) NOT NULL");
    }
} catch (\Throwable $e) {
}

$pdo->exec("CREATE TABLE IF NOT EXISTS hotel_invoice_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT NOT NULL,
    service_type    VARCHAR(50) NOT NULL,
    description     VARCHAR(255) DEFAULT NULL,
    quantity        DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price      DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_price     DECIMAL(15,2) NOT NULL DEFAULT 0,
    start_datetime  DATETIME     DEFAULT NULL,
    end_datetime    DATETIME     DEFAULT NULL,
    KEY idx_inv (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS hotel_service_catalog (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    business_id   INT NOT NULL DEFAULT 1,
    service_type  VARCHAR(50) NOT NULL,
    item_name     VARCHAR(120) NOT NULL,
    default_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    unit          VARCHAR(30)  DEFAULT 'unit',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order    INT          NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_biz_svc (business_id, service_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Dynamic service types table ────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS hotel_service_types (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    business_id   INT NOT NULL DEFAULT 1,
    type_key      VARCHAR(50) NOT NULL,
    type_label    VARCHAR(100) NOT NULL,
    type_icon     VARCHAR(10) DEFAULT '🔹',
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    sort_order    INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_biz_key (business_id, type_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Seed default service types if empty
try {
    $svcCount = $pdo->prepare("SELECT COUNT(*) FROM hotel_service_types WHERE business_id=?");
    $svcCount->execute([$businessId]);
    if ((int)$svcCount->fetchColumn() === 0) {
        $defaults = [
            ['motor_rental', 'Motor Rental', '🏍️', 1],
            ['car_rental', 'Rental Mobil / Taxi', '🚗', 2],
            ['laundry', 'Laundry', '👕', 3],
            ['service', 'Service', '🔧', 4],
            ['airport_drop', 'Airport Drop', '✈️', 5],
            ['harbor_drop', 'Harbor Drop', '⚓', 6],
            ['narayana_trip', 'Narayana Trip', '🚤', 7],
            ['lain_lain', 'Lain-lain', '📦', 8],
        ];
        $seedStmt = $pdo->prepare("INSERT INTO hotel_service_types (business_id, type_key, type_label, type_icon, sort_order) VALUES (?,?,?,?,?)");
        foreach ($defaults as $d) {
            $seedStmt->execute([$businessId, $d[0], $d[1], $d[2], $d[3]]);
        }
    }
} catch (\Throwable $e) {
}

try {
    $ensureTypeStmt = $pdo->prepare("SELECT COUNT(*) FROM hotel_service_types WHERE business_id=? AND type_key=?");
    $ensureTypeStmt->execute([$businessId, 'car_rental']);
    if ((int)$ensureTypeStmt->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO hotel_service_types (business_id, type_key, type_label, type_icon, sort_order) VALUES (?,?,?,?,?)")
            ->execute([$businessId, 'car_rental', 'Rental Mobil / Taxi', '🚗', 2]);
    }
} catch (\Throwable $e) {
}

// ── Load service types from DB ─────────────────────────────────────────────────
$serviceTypes = [];
try {
    $stStmt = $pdo->prepare("SELECT type_key, type_label, type_icon FROM hotel_service_types WHERE business_id=? AND is_active=1 ORDER BY sort_order, type_label");
    $stStmt->execute([$businessId]);
    foreach ($stStmt->fetchAll(PDO::FETCH_ASSOC) as $st) {
        $serviceTypes[$st['type_key']] = ['label' => $st['type_label'], 'icon' => $st['type_icon']];
    }
} catch (\Throwable $e) {
}
// Fallback if DB is empty
if (empty($serviceTypes)) {
    $serviceTypes = [
        'motor_rental'   => ['label' => 'Motor Rental',   'icon' => '🏍️'],
        'car_rental'     => ['label' => 'Rental Mobil / Taxi', 'icon' => '🚗'],
        'laundry'        => ['label' => 'Laundry',         'icon' => '👕'],
        'service'        => ['label' => 'Service',         'icon' => '🔧'],
        'airport_drop'   => ['label' => 'Airport Drop',    'icon' => '✈️'],
        'harbor_drop'    => ['label' => 'Harbor Drop',     'icon' => '⚓'],
        'narayana_trip'  => ['label' => 'Narayana Trip',   'icon' => '🚤'],
        'lain_lain'      => ['label' => 'Lain-lain',       'icon' => '📦'],
    ];
}

$statusColors    = ['pending' => '#b45309', 'confirmed' => '#1d4ed8', 'completed' => '#047857', 'cancelled' => '#b91c1c'];
$payStatusColors = ['unpaid' => '#b91c1c', 'partial' => '#b45309', 'paid' => '#047857'];

// ── Helper: find/create division by service type ──────────────────────────────
function getDivisionForService(PDO $pdo, string $serviceType): int
{
    static $cache = [];
    if (isset($cache[$serviceType])) return $cache[$serviceType];

    // Preferred division names — must match exactly what's in the DB (or close synonyms)
    $nameMap = [
        'motor_rental'  => ['Motor Rental',  'MOTOR_RENTAL',  'Motor'],
        'car_rental'    => ['Car Rental',    'CAR_RENTAL',    'Transport'],
        'laundry'       => ['Laundry',        'LAUNDRY',       'Housekeeping'],
        'service'       => ['General Service', 'GEN_SERVICE',   'Hotel'],
        'airport_drop'  => ['Airport Drop',   'AIRPORT_DROP',  'Hotel'],
        'harbor_drop'   => ['Harbor Drop',    'HARBOR_DROP',   'Hotel'],
        'narayana_trip' => ['Narayana Trip',  'NARAYANA_TRIP', 'Hotel'],
        'lain_lain'     => ['Lain2',          'OTHERS',        'Hotel'],
    ];
    $entry    = $nameMap[$serviceType] ?? ['Hotel Services', 'HOTEL_SVC', 'Hotel'];
    $prefName = $entry[0]; // preferred division name
    $prefCode = $entry[1]; // code to use when inserting
    $fallback = $entry[2]; // fallback name if preferred doesn't exist

    $resolve = function (string $name) use ($pdo): ?int {
        $stmt = $pdo->prepare("SELECT id FROM divisions WHERE LOWER(division_name) = LOWER(?) LIMIT 1");
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return (int)$row['id'];
        // Also try by division_code
        $stmt = $pdo->prepare("SELECT id FROM divisions WHERE UPPER(division_code) = UPPER(?) LIMIT 1");
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    };

    // 1. Try preferred name exact match
    $id = $resolve($prefName);
    // 2. Try preferred code
    if (!$id) $id = $resolve($prefCode);
    // 3. Try fallback name
    if (!$id) $id = $resolve($fallback);

    // 4. INSERT new division (with all required columns)
    if (!$id) {
        try {
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO divisions (division_name, division_code, division_type, is_active, created_at)
                 VALUES (?, ?, 'income', 1, NOW())"
            );
            $stmt->execute([$prefName, $prefCode]);
            $id = (int)$pdo->lastInsertId();
            if (!$id) $id = $resolve($prefName); // IGNORE may have hit a race condition
        } catch (\Throwable $e) {
        }
    }

    // 5. Absolute fallback: first income division, then first any division
    if (!$id) {
        try {
            $row = $pdo->query("SELECT id FROM divisions WHERE division_type IN ('income','both') ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$row) $row = $pdo->query("SELECT id FROM divisions ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $id = (int)($row['id'] ?? 1);
        } catch (\Throwable $e) {
            $id = 1;
        }
    }

    $cache[$serviceType] = $id;
    return $id;
}

// ── Helper: find/create 'Hotel Service' income category ───────────────────────
function getHotelServiceCategoryId(PDO $pdo): int
{
    // 1. Exact match (case-insensitive)
    try {
        $row = $pdo->query("SELECT id FROM categories WHERE LOWER(category_name) = 'hotel service' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) return (int)$row['id'];
        $row = $pdo->query("SELECT id FROM categories WHERE LOWER(category_name) LIKE '%hotel service%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) return (int)$row['id'];
    } catch (\Throwable $e1) {
    }

    // 2. Find a valid division_id (required by some schemas)
    $divId = null;
    try {
        // Prefer a hotel/income division
        $dRow = $pdo->query("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%hotel%' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$dRow) $dRow = $pdo->query("SELECT id FROM divisions WHERE division_type IN ('income','both') ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$dRow) $dRow = $pdo->query("SELECT id FROM divisions ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($dRow) $divId = (int)$dRow['id'];
    } catch (\Throwable $ed) {
    }

    // 3. Try INSERT with division_id + category_type
    if ($divId !== null) {
        try {
            $st = $pdo->prepare("INSERT IGNORE INTO categories (category_name, category_type, division_id, created_at) VALUES ('Hotel Service', 'income', :div, NOW())");
            $st->execute([':div' => $divId]);
            $newId = (int)$pdo->lastInsertId();
            if ($newId > 0) return $newId;
            $row = $pdo->query("SELECT id FROM categories WHERE LOWER(category_name) = 'hotel service' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($row) return (int)$row['id'];
        } catch (\Throwable $e2) {
        }
    }

    // 4. Try INSERT without division_id (older schema without FK constraint)
    try {
        $pdo->exec("INSERT IGNORE INTO categories (category_name, category_type, created_at) VALUES ('Hotel Service', 'income', NOW())");
        $newId = (int)$pdo->lastInsertId();
        if ($newId > 0) return $newId;
        $row = $pdo->query("SELECT id FROM categories WHERE LOWER(category_name) = 'hotel service' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) return (int)$row['id'];
    } catch (\Throwable $e3) {
    }

    // 5. Try INSERT without category_type (even older schema)
    try {
        if ($divId !== null) {
            $st = $pdo->prepare("INSERT IGNORE INTO categories (category_name, division_id, created_at) VALUES ('Hotel Service', :div, NOW())");
            $st->execute([':div' => $divId]);
        } else {
            $pdo->exec("INSERT IGNORE INTO categories (category_name, created_at) VALUES ('Hotel Service', NOW())");
        }
        $newId = (int)$pdo->lastInsertId();
        if ($newId > 0) return $newId;
        $row = $pdo->query("SELECT id FROM categories WHERE LOWER(category_name) = 'hotel service' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) return (int)$row['id'];
    } catch (\Throwable $e4) {
    }

    // 6. Absolute fallback: first income category
    try {
        $row = $pdo->query("SELECT id FROM categories WHERE category_type = 'income' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) return (int)$row['id'];
        $row = $pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) return (int)$row['id'];
    } catch (\Throwable $e5) {
    }
    return 1;
}

// ── Helper: sync invoice payment to cashbook (called from process_invoice) ─────
function syncInvoiceToCashbook($db, $businessId, $userId, array $invRow, array $itemGroups, array $serviceTypes): bool
{
    try {
        require_once '../../includes/CashbookHelper.php';
        $helper  = new CashbookHelper($db, $businessId, $userId);
        $account = $helper->getCashAccount($invRow['payment_method']);
        if (!$account) return false;

        $cbMethod  = $helper->mapPaymentMethod($invRow['payment_method']);
        $hasCa     = $helper->hasCashAccountIdColumn();
        $bPdo      = $db->getConnection();
        $catId     = getHotelServiceCategoryId($bPdo);
        $now       = date('Y-m-d H:i:s');
        $invNo     = $invRow['invoice_number'];
        $guest     = $invRow['guest_name'];
        $paidAmt   = (float)$invRow['paid_amount'];
        $totalAmt  = (float)$invRow['total'];
        $totalInserted = 0;
        $lastTransId   = 0;

        foreach ($itemGroups as $group) {
            $svcType   = $group['service_type'];
            $svcLabel  = $serviceTypes[$svcType]['label'] ?? $svcType;
            $proportion = $totalAmt > 0 ? ($group['type_total'] / $totalAmt) : (1 / count($itemGroups));
            $svcAmount  = $group === end($itemGroups)
                ? round($paidAmt - $totalInserted, 2)   // last item gets remainder to avoid rounding loss
                : round($paidAmt * $proportion, 2);
            if ($svcAmount <= 0) continue;
            $totalInserted += $svcAmount;

            $divId = getDivisionForService($bPdo, $svcType);
            $desc  = "[{$invNo}] {$guest} - {$svcLabel}";

            if ($hasCa) {
                $stmt = $bPdo->prepare("INSERT INTO cash_book
                    (transaction_date, transaction_time, division_id, category_id,
                     description, transaction_type, amount, payment_method,
                     cash_account_id, is_editable, created_by, created_at)
                    VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, ?, 1, ?, NOW())");
                $stmt->execute([$now, $now, $divId, $catId, $desc, $svcAmount, $cbMethod, $account['id'], $userId]);
            } else {
                $stmt = $bPdo->prepare("INSERT INTO cash_book
                    (transaction_date, transaction_time, division_id, category_id,
                     description, transaction_type, amount, payment_method,
                     is_editable, created_by, created_at)
                    VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, 1, ?, NOW())");
                $stmt->execute([$now, $now, $divId, $catId, $desc, $svcAmount, $cbMethod, $userId]);
            }
            $lastTransId = (int)$bPdo->lastInsertId();
        }

        // ── Master DB: one cash_account_transactions entry for total + balance update
        try {
            $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : (defined('DB_NAME') ? DB_NAME : null);
            if ($masterDbName && $totalInserted > 0) {
                $mPdo = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname={$masterDbName};charset=" . DB_CHARSET,
                    DB_USER,
                    DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $masterDesc = "Hotel Services [{$invNo}] {$guest}";
                $hasTxCol = (bool)$mPdo->query("SHOW COLUMNS FROM cash_account_transactions LIKE 'transaction_id'")->fetch();
                if ($hasTxCol) {
                    $mPdo->prepare("INSERT INTO cash_account_transactions
                        (cash_account_id, transaction_id, transaction_date,
                         description, amount, transaction_type, reference_number, created_by, created_at)
                        VALUES (?, ?, DATE(?), ?, ?, 'income', ?, ?, NOW())")
                        ->execute([$account['id'], $lastTransId, $now, $masterDesc, $paidAmt, $invNo, $userId]);
                } else {
                    $mPdo->prepare("INSERT INTO cash_account_transactions
                        (cash_account_id, transaction_date,
                         description, amount, transaction_type, reference_number, created_by, created_at)
                        VALUES (?, DATE(?), ?, ?, 'income', ?, ?, NOW())")
                        ->execute([$account['id'], $now, $masterDesc, $paidAmt, $invNo, $userId]);
                }
                $newBal = $account['current_balance'] + $paidAmt;
                $mPdo->prepare("UPDATE cash_accounts SET current_balance = ? WHERE id = ?")->execute([$newBal, $account['id']]);
            }
        } catch (\Throwable $me) {
            error_log("Hotel svc cashbook master sync: " . $me->getMessage());
        }
        return true;
    } catch (\Throwable $e) {
        error_log("Hotel svc cashbook error: " . $e->getMessage());
        return false;
    }
}

// ── AJAX handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    ob_start();
    try {
        $action = $_POST['action'];

        // ── CREATE ──────────────────────────────────────────────────────────────
        if ($action === 'create') {
            $guestName  = trim($_POST['guest_name'] ?? '');
            $guestPhone = trim($_POST['guest_phone'] ?? '');
            $roomNumber = trim($_POST['room_number'] ?? '');
            $bookingId  = (int)($_POST['booking_id'] ?? 0) ?: null;
            $payMethod  = $_POST['payment_method'] ?? 'cash';
            $paidAmount = max(0, (float)($_POST['paid_amount'] ?? 0));
            $notes      = trim($_POST['notes'] ?? '');
            $taxRate    = max(0, min(100, (float)($_POST['tax_rate'] ?? 0)));
            $serviceChargeRate = max(0, min(100, (float)($_POST['service_charge_rate'] ?? 0)));
            $discountRate      = max(0, min(100, (float)($_POST['discount_rate'] ?? 0)));

            if (!$guestName) throw new Exception('Guest name is required');

            $items = json_decode($_POST['items'] ?? '[]', true);
            if (empty($items)) throw new Exception('At least one service item is required');

            $subtotal = 0;
            $motorRentalItems = [];
            $carRentalItems   = [];
            foreach ($items as &$item) {
                $item['qty']        = max(0.5, (float)($item['qty']        ?? 1));
                $item['unit_price'] = max(0,   (float)($item['unit_price'] ?? 0));
                $item['start_dt']   = trim((string)($item['start_dt'] ?? '')) ?: null;
                $item['end_dt']     = trim((string)($item['end_dt'] ?? '')) ?: null;
                $item['deposit']    = max(0, (float)($item['deposit'] ?? 0));
                $item['trip_destination'] = trim((string)($item['trip_destination'] ?? '')) ?: null;
                $item['total']      = round($item['qty'] * $item['unit_price'], 2);
                $subtotal += $item['total'];
                if (!isset($serviceTypes[$item['service_type'] ?? ''])) {
                    throw new Exception('Invalid service type: ' . ($item['service_type'] ?? ''));
                }

                if (($item['service_type'] ?? '') === 'motor_rental') {
                    $item['motor_id'] = (int)($item['motor_id'] ?? 0);
                    if (!$item['motor_id'] || !$item['start_dt'] || !$item['end_dt']) {
                        throw new Exception('Motor rental wajib pilih armada, mulai, dan selesai');
                    }
                    $motorStmt = $pdo->prepare("SELECT * FROM rental_motors WHERE id=? AND business_id=?");
                    $motorStmt->execute([$item['motor_id'], $businessId]);
                    $motorRow = $motorStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$motorRow) throw new Exception('Armada motor tidak ditemukan');
                    if ($motorRow['status'] !== 'available') throw new Exception("Motor {$motorRow['plate_number']} tidak tersedia");
                    $item['description'] = trim((string)($item['description'] ?? '')) ?: ($motorRow['motor_name'] . ' (' . $motorRow['plate_number'] . ')');
                    $motorRentalItems[] = ['item' => $item, 'row' => $motorRow];
                }

                if (($item['service_type'] ?? '') === 'car_rental') {
                    $item['car_id'] = (int)($item['car_id'] ?? 0);
                    if (!$item['car_id'] || !$item['start_dt'] || !$item['end_dt']) {
                        throw new Exception('Rental mobil/taxi wajib pilih armada, mulai, dan selesai');
                    }
                    $carStmt = $pdo->prepare("SELECT * FROM rental_cars WHERE id=? AND business_id=?");
                    $carStmt->execute([$item['car_id'], $businessId]);
                    $carRow = $carStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$carRow) throw new Exception('Armada mobil tidak ditemukan');
                    if ($carRow['status'] !== 'available') throw new Exception("Mobil {$carRow['plate_number']} tidak tersedia");
                    $baseDesc = $carRow['car_name'] . ' (' . $carRow['plate_number'] . ')';
                    if ($item['trip_destination']) {
                        $baseDesc .= ' — Tujuan: ' . $item['trip_destination'];
                    }
                    $item['description'] = trim((string)($item['description'] ?? '')) ?: $baseDesc;
                    $carRentalItems[] = ['item' => $item, 'row' => $carRow];
                }
            }
            unset($item);

            $serviceChargeAmount = round($subtotal * $serviceChargeRate / 100, 2);
            $discountAmount      = round($subtotal * $discountRate / 100, 2);
            $afterChargeDiscount = $subtotal + $serviceChargeAmount - $discountAmount;
            $taxAmount           = round($afterChargeDiscount * $taxRate / 100, 2);
            $total               = $afterChargeDiscount + $taxAmount;

            $paidAmount = min($paidAmount, $total);
            $remaining  = $total - $paidAmount;
            $payStatus  = ($paidAmount <= 0) ? 'unpaid' : ($remaining <= 0 ? 'paid' : 'partial');

            // Invoice number
            // Check if guest already has unpaid consolidated invoice, reuse it instead
            $existingInvId = null;
            if ($bookingId) {
                $existingStmt = $pdo->prepare("
                    SELECT id FROM hotel_invoices
                    WHERE business_id = ? AND booking_id = ?
                      AND payment_status IN ('unpaid','partial')
                      AND status = 'confirmed'
                      AND cashbook_synced = 0
                    LIMIT 1
                ");
                $existingStmt->execute([$businessId, $bookingId]);
                $existingInvId = (int)$existingStmt->fetchColumn() ?: null;
            }
            if (!$existingInvId) {
                $existingStmt = $pdo->prepare("
                    SELECT id FROM hotel_invoices
                    WHERE business_id = ? AND guest_name = ?
                      AND payment_status IN ('unpaid','partial')
                      AND status = 'confirmed'
                      AND cashbook_synced = 0
                      AND (booking_id IS NULL OR booking_id = ? OR ? IS NULL)
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $existingStmt->execute([$businessId, $guestName, $bookingId, $bookingId]);
                $existingInvId = (int)$existingStmt->fetchColumn() ?: null;
            }

            $pdo->beginTransaction();
            
            if ($existingInvId) {
                // Reuse existing invoice - add items to it
                $invId = $existingInvId;
                $invLoad = $pdo->prepare("SELECT invoice_number, paid_amount, tax_rate, service_charge_rate, discount_rate FROM hotel_invoices WHERE id=? AND business_id=? LIMIT 1");
                $invLoad->execute([$invId, $businessId]);
                $existingInvoice = $invLoad->fetch(PDO::FETCH_ASSOC);
                if (!$existingInvoice) throw new Exception('Invoice existing tidak ditemukan');
                $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(total_price),0) FROM hotel_invoice_items WHERE invoice_id=?");
                $sumStmt->execute([$invId]);
                $existingSubtotal = (float)$sumStmt->fetchColumn();
                // Update totals based on new items
                $iStmt = $pdo->prepare("INSERT INTO hotel_invoice_items
                    (invoice_id, service_type, description, quantity, unit_price, total_price, start_datetime, end_datetime)
                    VALUES (?,?,?,?,?,?,?,?)");
                foreach ($items as $item) {
                    $iStmt->execute([
                        $invId,
                        $item['service_type'],
                        $item['description'] ?: null,
                        $item['qty'],
                        $item['unit_price'],
                        $item['total'],
                        $item['start_dt'] ?: null,
                        $item['end_dt'] ?: null,
                    ]);
                }
                $mergedSubtotal = $existingSubtotal + $subtotal;
                $mergedServiceChargeRate = (float)($existingInvoice['service_charge_rate'] ?? 0);
                $mergedDiscountRate = (float)($existingInvoice['discount_rate'] ?? 0);
                $mergedTaxRate = (float)($existingInvoice['tax_rate'] ?? 0);
                $mergedServiceCharge = round($mergedSubtotal * $mergedServiceChargeRate / 100, 2);
                $mergedDiscount = round($mergedSubtotal * $mergedDiscountRate / 100, 2);
                $mergedAfterChargeDiscount = $mergedSubtotal + $mergedServiceCharge - $mergedDiscount;
                $mergedTax = round($mergedAfterChargeDiscount * $mergedTaxRate / 100, 2);
                $mergedTotal = $mergedAfterChargeDiscount + $mergedTax;
                $mergedPaid = min((float)($existingInvoice['paid_amount'] ?? 0), $mergedTotal);
                $mergedRemaining = $mergedTotal - $mergedPaid;
                $mergedPayStatus = ($mergedPaid <= 0) ? 'unpaid' : ($mergedRemaining <= 0 ? 'paid' : 'partial');
                $pdo->prepare("UPDATE hotel_invoices 
                    SET total = ?,
                        payment_status = ?,
                        tax_amount = ?,
                        service_charge_amount = ?,
                        discount_amount = ?,
                        updated_at = NOW()
                    WHERE id = ? AND cashbook_synced = 0")
                    ->execute([$mergedTotal, $mergedPayStatus, $mergedTax, $mergedServiceCharge, $mergedDiscount, $invId]);
                $invNo = (string)$existingInvoice['invoice_number'];
            } else {
                // Create new invoice
                $prefix = 'HSV-' . date('Ym') . '-';
                $last   = $pdo->query("SELECT invoice_number FROM hotel_invoices WHERE invoice_number LIKE '{$prefix}%' ORDER BY invoice_number DESC LIMIT 1")->fetchColumn();
                $seq    = $last ? ((int)substr($last, -4) + 1) : 1;
                $invNo  = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

                $pdo->prepare("INSERT INTO hotel_invoices
                    (business_id, invoice_number, booking_id, guest_name, guest_phone, room_number,
                     total, paid_amount, payment_status, payment_method, status, notes,
                     tax_rate, tax_amount, service_charge_rate, service_charge_amount,
                     discount_rate, discount_amount, created_by, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                    ->execute([
                        $businessId,
                        $invNo,
                        $bookingId,
                        $guestName,
                        $guestPhone ?: null,
                        $roomNumber ?: null,
                        $total,
                        $paidAmount,
                        $payStatus,
                        $payMethod,
                        'confirmed',
                        $notes ?: null,
                        $taxRate,
                        $taxAmount,
                        $serviceChargeRate,
                        $serviceChargeAmount,
                        $discountRate,
                        $discountAmount,
                        $currentUser['id'] ?? null
                    ]);
                $invId = (int)$pdo->lastInsertId();

                $iStmt = $pdo->prepare("INSERT INTO hotel_invoice_items
                    (invoice_id, service_type, description, quantity, unit_price, total_price, start_datetime, end_datetime)
                    VALUES (?,?,?,?,?,?,?,?)");
                foreach ($items as $item) {
                    $iStmt->execute([
                        $invId,
                        $item['service_type'],
                        $item['description'] ?: null,
                        $item['qty'],
                        $item['unit_price'],
                        $item['total'],
                        $item['start_dt'] ?: null,
                        $item['end_dt'] ?: null,
                    ]);
                }
            }

            foreach ($motorRentalItems as $motorRental) {
                $item = $motorRental['item'];
                $motorRow = $motorRental['row'];
                $pdo->prepare("INSERT INTO rental_motor_bookings
                    (business_id, motor_id, invoice_id, guest_name, guest_phone, room_number, booking_id,
                     start_datetime, end_datetime, daily_rate, total_price, deposit, status, notes, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $businessId,
                        (int)$motorRow['id'],
                        $invId,
                        $guestName,
                        $guestPhone ?: null,
                        $roomNumber ?: null,
                        $bookingId,
                        $item['start_dt'],
                        $item['end_dt'],
                        $item['unit_price'],
                        $item['total'],
                        $item['deposit'],
                        'active',
                        $notes ?: null,
                        $currentUser['id'] ?? null
                    ]);
                $pdo->prepare("UPDATE rental_motors SET status='rented', updated_at=NOW() WHERE id=?")
                    ->execute([(int)$motorRow['id']]);
            }

            foreach ($carRentalItems as $carRental) {
                $item = $carRental['item'];
                $carRow = $carRental['row'];
                $pdo->prepare("INSERT INTO rental_car_bookings
                    (business_id, car_id, invoice_id, guest_name, guest_phone, room_number, booking_id,
                     start_datetime, end_datetime, daily_rate, total_price, owner_amount, hotel_commission,
                     deposit, trip_destination, status, notes, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $businessId,
                        (int)$carRow['id'],
                        $invId,
                        $guestName,
                        $guestPhone ?: null,
                        $roomNumber ?: null,
                        $bookingId,
                        $item['start_dt'],
                        $item['end_dt'],
                        $item['unit_price'],
                        $item['total'],
                        0,
                        0,
                        $item['deposit'],
                        $item['trip_destination'],
                        'active',
                        $notes ?: null,
                        $currentUser['id'] ?? null
                    ]);
                $pdo->prepare("UPDATE rental_cars SET status='rented', updated_at=NOW() WHERE id=?")
                    ->execute([(int)$carRow['id']]);
            }
            $pdo->commit();

            // Cashbook is NOT synced on save — staff must click "Process Invoice" in preview
            ob_clean();
            echo json_encode(['success' => true, 'invoice_number' => $invNo, 'id' => $invId, 'cashbook' => false]);
            exit;
        }

        // ── ADD RENTAL CAR TO INVOICE ───────────────────────────────────────────
        if ($action === 'add_rental_car') {
            $invoiceId  = (int)($_POST['invoice_id'] ?? 0);
            $carId      = (int)($_POST['car_id'] ?? 0);
            $startDt    = trim($_POST['start_datetime'] ?? '');
            $endDt      = trim($_POST['end_datetime'] ?? '');
            $dailyRate  = max(0, (float)($_POST['daily_rate'] ?? 0));
            $deposit    = max(0, (float)($_POST['deposit'] ?? 0));
            $tripDest   = trim($_POST['trip_destination'] ?? '');
            $notes      = trim($_POST['notes'] ?? '');
            
            if (!$invoiceId) throw new Exception('Invoice ID required');
            if (!$carId) throw new Exception('Car ID required');
            if (!$startDt || !$endDt) throw new Exception('Start and end dates required');

            // Get invoice details
            $inv = $pdo->prepare("SELECT * FROM hotel_invoices WHERE id=? AND business_id=? AND cashbook_synced=0");
            $inv->execute([$invoiceId, $businessId]);
            $invRow = $inv->fetch(PDO::FETCH_ASSOC);
            if (!$invRow) throw new Exception('Invoice not found or already synced');

            // Get car details
            $car = $pdo->prepare("SELECT * FROM rental_cars WHERE id=? AND business_id=?");
            $car->execute([$carId, $businessId]);
            $carRow = $car->fetch(PDO::FETCH_ASSOC);
            if (!$carRow) throw new Exception('Car not found');
            if ($carRow['status'] === 'rented') throw new Exception("Car {$carRow['plate_number']} is currently rented");

            $start = new DateTime($startDt);
            $end = new DateTime($endDt);
            if ($end <= $start) throw new Exception('End date must be after start date');

            $plannedSeconds = max(0, $end->getTimestamp() - $start->getTimestamp());
            $plannedDays = max(1, (int)ceil($plannedSeconds / 86400));
            $plannedTotal = max(0, round($plannedDays * $dailyRate, 2));
            $ownerPct = (float)($carRow['owner_commission_pct'] ?? 0);
            $ownerAmount = round($plannedTotal * ($ownerPct / 100), 2);
            $hotelCommission = $plannedTotal - $ownerAmount;

            $pdo->beginTransaction();

            // Create rental car booking linked to this invoice
            $pdo->prepare("INSERT INTO rental_car_bookings
                (business_id, car_id, invoice_id, guest_name, guest_phone, room_number, booking_id,
                 start_datetime, end_datetime, daily_rate, total_price, owner_amount, hotel_commission,
                 deposit, trip_destination, status, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $businessId,
                    $carId,
                    $invoiceId,
                    $invRow['guest_name'],
                    $invRow['guest_phone'],
                    $invRow['room_number'],
                    $invRow['booking_id'],
                    $startDt,
                    $endDt,
                    $dailyRate,
                    $plannedTotal,
                    $ownerAmount,
                    $hotelCommission,
                    $deposit,
                    $tripDest ?: null,
                    'active',
                    $notes ?: null,
                    $currentUser['id'] ?? null
                ]);
            $rentalId = (int)$pdo->lastInsertId();

            // Add invoice item for this car rental
            addInvoiceItem(
                $pdo,
                $invoiceId,
                'car_rental',
                "{$carRow['car_name']} ({$carRow['plate_number']})" .
                ($tripDest ? " — Tujuan: {$tripDest}" : ''),
                $plannedDays,
                $dailyRate,  // unit_price
                $startDt,
                $endDt
            );

            // Update car status to rented
            $pdo->prepare("UPDATE rental_cars SET status='rented', updated_at=NOW() WHERE id=?")->execute([$carId]);

            $pdo->commit();
            ob_clean();
            echo json_encode(['success' => true, 'rental_id' => $rentalId, 'message' => 'Rental car added to invoice']);
            exit;
        }

        // ── ADD PAYMENT ─────────────────────────────────────────────────────────
        if ($action === 'add_payment') {
            $id     = (int)($_POST['id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $method = $_POST['method'] ?? 'cash';
            if (!$id || $amount <= 0) throw new Exception('Invalid data');

            $inv = $pdo->prepare("SELECT hi.*, GROUP_CONCAT(hii.service_type SEPARATOR ',') as svc_types
                FROM hotel_invoices hi
                LEFT JOIN hotel_invoice_items hii ON hii.invoice_id = hi.id
                WHERE hi.id=? AND hi.business_id=? GROUP BY hi.id");
            $inv->execute([$id, $businessId]);
            $r = $inv->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new Exception('Invoice not found');

            $newPaid  = min($r['paid_amount'] + $amount, $r['total']);
            $remain   = $r['total'] - $newPaid;
            $payStatus = ($newPaid <= 0) ? 'unpaid' : ($remain <= 0 ? 'paid' : 'partial');

            $pdo->prepare("UPDATE hotel_invoices SET paid_amount=?, payment_status=?, payment_method=?, updated_at=NOW() WHERE id=? AND business_id=?")
                ->execute([$newPaid, $payStatus, $method, $id, $businessId]);

            // Cashbook NOT synced here — must use "Process Invoice" in preview
            ob_clean();
            echo json_encode(['success' => true, 'payment_status' => $payStatus, 'paid_amount' => $newPaid, 'cashbook' => false]);
            exit;
        }

        // ── PROCESS INVOICE (syncs payment to cashbook per service type) ─────────
        if ($action === 'process_invoice') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('Invalid ID');

            $invStmt = $pdo->prepare("SELECT * FROM hotel_invoices WHERE id=? AND business_id=?");
            $invStmt->execute([$id, $businessId]);
            $invRow = $invStmt->fetch(PDO::FETCH_ASSOC);
            if (!$invRow) throw new Exception('Invoice not found');
            if ($invRow['cashbook_synced'] ?? 0) {
                ob_clean();
                echo json_encode(['success' => true, 'already' => true, 'message' => 'Already processed']);
                exit;
            }

            // Group items by service type with proportion totals
            $grpStmt = $pdo->prepare("
                SELECT service_type, SUM(total_price) as type_total
                FROM hotel_invoice_items WHERE invoice_id=?
                GROUP BY service_type ORDER BY service_type");
            $grpStmt->execute([$id]);
            $itemGroups = $grpStmt->fetchAll(PDO::FETCH_ASSOC);

            $cbOk = false;
            if ((float)$invRow['paid_amount'] > 0 && !empty($itemGroups)) {
                $cbOk = syncInvoiceToCashbook(
                    $db,
                    $businessId,
                    $currentUser['id'] ?? 1,
                    $invRow,
                    $itemGroups,
                    $serviceTypes
                );
            }

            // Only mark as synced if cashbook sync actually succeeded (or no payment to sync)
            if ($cbOk || (float)$invRow['paid_amount'] <= 0) {
                $pdo->prepare("UPDATE hotel_invoices SET cashbook_synced=1, updated_at=NOW() WHERE id=?")->execute([$id]);
            }

            ob_clean();
            echo json_encode(['success' => true, 'cashbook' => $cbOk, 'paid_amount' => $invRow['paid_amount']]);
            exit;
        }

        // ── UPDATE STATUS ────────────────────────────────────────────────────────
        if ($action === 'update_status' || $action === 'update_invoice') {
            if (!$auth->canEdit('frontdesk')) {
                echo json_encode(['success' => false, 'message' => '⛔ Anda tidak memiliki izin untuk mengedit.']);
                exit;
            }
        }
        if ($action === 'delete') {
            if (!$auth->canDelete('frontdesk')) {
                echo json_encode(['success' => false, 'message' => '⛔ Anda tidak memiliki izin untuk menghapus.']);
                exit;
            }
        }

        if ($action === 'update_status') {
            $id     = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $allowed = ['pending', 'confirmed', 'completed', 'cancelled'];
            if (!$id || !in_array($status, $allowed)) throw new Exception('Invalid');
            $pdo->prepare("UPDATE hotel_invoices SET status=?, updated_at=NOW() WHERE id=? AND business_id=?")
                ->execute([$status, $id, $businessId]);
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        }

        // ── DELETE ───────────────────────────────────────────────────────────────
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('Invalid ID');
            $pdo->prepare("DELETE FROM hotel_invoice_items WHERE invoice_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM hotel_invoices WHERE id=? AND business_id=?")->execute([$id, $businessId]);
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        }

        // ── SAVE HOTEL SETTINGS (لوغو + detail perusahaan) ───────────────────────────
        if ($action === 'save_hs_settings') {
            $allowed = [
                'company_name',
                'company_address',
                'company_phone',
                'company_email',
                'company_website',
                'company_logo',
                'payment_info_bank',
                'payment_info_account',
                'payment_info_name',
                'payment_info_note'
            ];
            $saved = 0;
            foreach ($allowed as $key) {
                if (isset($_POST[$key])) {
                    $val = trim($_POST[$key]);
                    $ex  = $pdo->prepare("SELECT id FROM settings WHERE setting_key=? LIMIT 1");
                    $ex->execute([$key]);
                    if ($ex->fetch()) {
                        $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key=?")->execute([$val, $key]);
                    } else {
                        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)")->execute([$key, $val]);
                    }
                    $saved++;
                }
            }
            // Handle logo upload
            if (!empty($_FILES['logo_file']['tmp_name'])) {
                $ext  = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
                if (!in_array($ext, $allowed_ext)) throw new Exception('Invalid logo file type');
                $fname = 'logo_hotel_svc_' . uniqid() . '.' . $ext;
                $cloudinary = CloudinaryHelper::getInstance();
                $uploadResult = $cloudinary->smartUpload($_FILES['logo_file'], 'uploads/logos', $fname, 'logos', 'hotel_svc_logo');
                if ($uploadResult['success']) {
                    $logoVal = $uploadResult['is_cloud'] ? $uploadResult['path'] : BASE_URL . '/uploads/logos/' . $fname;
                    $ex2 = $pdo->prepare("SELECT id FROM settings WHERE setting_key='company_logo' LIMIT 1");
                    $ex2->execute();
                    if ($ex2->fetch()) {
                        $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='company_logo'")->execute([$logoVal]);
                    } else {
                        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('company_logo',?)")->execute([$logoVal]);
                    }
                    $saved++;
                }
            }
            ob_clean();
            echo json_encode(['success' => true, 'saved' => $saved]);
            exit;
        }

        // ── SAVE / UPDATE CATALOG ITEM ─────────────────────────────────────────────────
        if ($action === 'save_catalog_item') {
            $cid   = (int)($_POST['cid'] ?? 0);
            $stype = $_POST['service_type'] ?? '';
            $name  = trim($_POST['item_name'] ?? '');
            $price = max(0, (float)($_POST['default_price'] ?? 0));
            $unit  = trim($_POST['unit'] ?? 'unit');
            $sort  = (int)($_POST['sort_order'] ?? 0);
            if (!$name) throw new Exception('Item name is required');
            if (!isset($serviceTypes[$stype])) throw new Exception('Invalid service type');
            if ($cid) {
                $pdo->prepare("UPDATE hotel_service_catalog SET service_type=?,item_name=?,default_price=?,unit=?,sort_order=? WHERE id=? AND business_id=?")
                    ->execute([$stype, $name, $price, $unit, $sort, $cid, $businessId]);
            } else {
                $pdo->prepare("INSERT INTO hotel_service_catalog (business_id,service_type,item_name,default_price,unit,sort_order) VALUES (?,?,?,?,?,?)")
                    ->execute([$businessId, $stype, $name, $price, $unit, $sort]);
                $cid = (int)$pdo->lastInsertId();
            }
            ob_clean();
            echo json_encode(['success' => true, 'id' => $cid]);
            exit;
        }

        // ── DELETE CATALOG ITEM ─────────────────────────────────────────────────────────────
        if ($action === 'delete_catalog_item') {
            $cid = (int)($_POST['cid'] ?? 0);
            if (!$cid) throw new Exception('Invalid item ID');
            $pdo->prepare("DELETE FROM hotel_service_catalog WHERE id=? AND business_id=?")->execute([$cid, $businessId]);
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        }

        // ── SAVE / UPDATE SERVICE TYPE ──────────────────────────────────────────────────
        if ($action === 'save_service_type') {
            $stId      = (int)($_POST['st_id'] ?? 0);
            $typeKey   = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['type_key'] ?? '')));
            $typeLabel = trim($_POST['type_label'] ?? '');
            $typeIcon  = trim($_POST['type_icon'] ?? '🔹');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            if (!$typeKey || !$typeLabel) throw new Exception('Key and Label are required');
            if ($stId) {
                $pdo->prepare("UPDATE hotel_service_types SET type_key=?,type_label=?,type_icon=?,sort_order=? WHERE id=? AND business_id=?")
                    ->execute([$typeKey, $typeLabel, $typeIcon, $sortOrder, $stId, $businessId]);
            } else {
                $pdo->prepare("INSERT INTO hotel_service_types (business_id,type_key,type_label,type_icon,sort_order) VALUES (?,?,?,?,?)")
                    ->execute([$businessId, $typeKey, $typeLabel, $typeIcon, $sortOrder]);
                $stId = (int)$pdo->lastInsertId();
            }
            ob_clean();
            echo json_encode(['success' => true, 'id' => $stId]);
            exit;
        }

        // ── DELETE SERVICE TYPE ─────────────────────────────────────────────────────────────
        if ($action === 'delete_service_type') {
            $stId = (int)($_POST['st_id'] ?? 0);
            if (!$stId) throw new Exception('Invalid ID');
            // Prevent deleting if used in existing items
            $usedCheck = $pdo->prepare("SELECT type_key FROM hotel_service_types WHERE id=? AND business_id=?");
            $usedCheck->execute([$stId, $businessId]);
            $typeRow = $usedCheck->fetch(PDO::FETCH_ASSOC);
            if ($typeRow) {
                $usedInItems = $pdo->prepare("SELECT COUNT(*) FROM hotel_invoice_items ii JOIN hotel_invoices i ON ii.invoice_id=i.id WHERE i.business_id=? AND ii.service_type=?");
                $usedInItems->execute([$businessId, $typeRow['type_key']]);
                if ((int)$usedInItems->fetchColumn() > 0) {
                    throw new Exception('Cannot delete: service type is used in existing invoices');
                }
            }
            $pdo->prepare("DELETE FROM hotel_service_types WHERE id=? AND business_id=?")->execute([$stId, $businessId]);
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        }

        // ── GET SERVICE TYPES (AJAX) ────────────────────────────────────────────────────────
        if ($action === 'get_service_types') {
            $stRows = $pdo->prepare("SELECT * FROM hotel_service_types WHERE business_id=? ORDER BY sort_order, type_label");
            $stRows->execute([$businessId]);
            ob_clean();
            echo json_encode(['success' => true, 'data' => $stRows->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        // ── UPDATE INVOICE ────────────────────────────────────────────────────────────────
        if ($action === 'update_invoice') {
            $id         = (int)($_POST['id'] ?? 0);
            $guestName  = trim($_POST['guest_name'] ?? '');
            $guestPhone = trim($_POST['guest_phone'] ?? '');
            $roomNumber = trim($_POST['room_number'] ?? '');
            $payMethod  = $_POST['payment_method'] ?? 'cash';
            $paidAmount = max(0, (float)($_POST['paid_amount'] ?? 0));
            $notes      = trim($_POST['notes'] ?? '');
            $taxRate    = max(0, min(100, (float)($_POST['tax_rate'] ?? 0)));
            $serviceChargeRate = max(0, min(100, (float)($_POST['service_charge_rate'] ?? 0)));
            $discountRate      = max(0, min(100, (float)($_POST['discount_rate'] ?? 0)));
            if (!$id || !$guestName) throw new Exception('Invalid data');

            $items = json_decode($_POST['items'] ?? '[]', true);
            if (empty($items)) throw new Exception('At least one service item is required');

            // Verify invoice belongs to this business
            $chk = $pdo->prepare("SELECT id, booking_id FROM hotel_invoices WHERE id=? AND business_id=? AND cashbook_synced=0");
            $chk->execute([$id, $businessId]);
            $invoiceRow = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$invoiceRow) throw new Exception('Invoice not found or already processed (cannot edit processed invoices)');
            $invoiceBookingId = (int)($invoiceRow['booking_id'] ?? 0) ?: null;

            $existingMotorRows = $pdo->prepare("SELECT rb.*, rm.status as asset_status, rm.plate_number, rm.motor_name
                FROM rental_motor_bookings rb
                JOIN rental_motors rm ON rb.motor_id = rm.id
                WHERE rb.invoice_id=? AND rb.business_id=?");
            $existingMotorRows->execute([$id, $businessId]);
            $existingMotorBookings = $existingMotorRows->fetchAll(PDO::FETCH_ASSOC);
            $existingMotorByAssetId = [];
            foreach ($existingMotorBookings as $row) {
                $existingMotorByAssetId[(int)$row['motor_id']] = $row;
            }

            $existingCarRows = $pdo->prepare("SELECT cb.*, rc.status as asset_status, rc.plate_number, rc.car_name
                FROM rental_car_bookings cb
                JOIN rental_cars rc ON cb.car_id = rc.id
                WHERE cb.invoice_id=? AND cb.business_id=?");
            $existingCarRows->execute([$id, $businessId]);
            $existingCarBookings = $existingCarRows->fetchAll(PDO::FETCH_ASSOC);
            $existingCarByAssetId = [];
            foreach ($existingCarBookings as $row) {
                $existingCarByAssetId[(int)$row['car_id']] = $row;
            }

            $subtotal = 0;
            $motorRentalItems = [];
            $carRentalItems = [];
            foreach ($items as &$item) {
                $item['qty']        = max(0.5, (float)($item['qty'] ?? 1));
                $item['unit_price'] = max(0, (float)($item['unit_price'] ?? 0));
                $item['start_dt']   = trim((string)($item['start_dt'] ?? '')) ?: null;
                $item['end_dt']     = trim((string)($item['end_dt'] ?? '')) ?: null;
                $item['deposit']    = max(0, (float)($item['deposit'] ?? 0));
                $item['trip_destination'] = trim((string)($item['trip_destination'] ?? '')) ?: null;
                $item['total']      = round($item['qty'] * $item['unit_price'], 2);
                $subtotal += $item['total'];
                if (!isset($serviceTypes[$item['service_type'] ?? ''])) throw new Exception('Invalid service type');

                if (($item['service_type'] ?? '') === 'motor_rental') {
                    $item['motor_id'] = (int)($item['motor_id'] ?? 0);
                    if (!$item['motor_id'] || !$item['start_dt'] || !$item['end_dt']) throw new Exception('Motor rental wajib pilih armada, mulai, dan selesai');
                    $motorStmt = $pdo->prepare("SELECT * FROM rental_motors WHERE id=? AND business_id=?");
                    $motorStmt->execute([$item['motor_id'], $businessId]);
                    $motorRow = $motorStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$motorRow) throw new Exception('Armada motor tidak ditemukan');
                    if ($motorRow['status'] !== 'available' && !isset($existingMotorByAssetId[$item['motor_id']])) throw new Exception("Motor {$motorRow['plate_number']} tidak tersedia");
                    $item['description'] = trim((string)($item['description'] ?? '')) ?: ($motorRow['motor_name'] . ' (' . $motorRow['plate_number'] . ')');
                    $motorRentalItems[] = ['item' => $item, 'row' => $motorRow];
                }

                if (($item['service_type'] ?? '') === 'car_rental') {
                    $item['car_id'] = (int)($item['car_id'] ?? 0);
                    if (!$item['car_id'] || !$item['start_dt'] || !$item['end_dt']) throw new Exception('Rental mobil/taxi wajib pilih armada, mulai, dan selesai');
                    $carStmt = $pdo->prepare("SELECT * FROM rental_cars WHERE id=? AND business_id=?");
                    $carStmt->execute([$item['car_id'], $businessId]);
                    $carRow = $carStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$carRow) throw new Exception('Armada mobil tidak ditemukan');
                    if ($carRow['status'] !== 'available' && !isset($existingCarByAssetId[$item['car_id']])) throw new Exception("Mobil {$carRow['plate_number']} tidak tersedia");
                    $baseDesc = $carRow['car_name'] . ' (' . $carRow['plate_number'] . ')';
                    if ($item['trip_destination']) $baseDesc .= ' — Tujuan: ' . $item['trip_destination'];
                    $item['description'] = trim((string)($item['description'] ?? '')) ?: $baseDesc;
                    $carRentalItems[] = ['item' => $item, 'row' => $carRow];
                }
            }
            unset($item);

            $serviceChargeAmount = round($subtotal * $serviceChargeRate / 100, 2);
            $discountAmount      = round($subtotal * $discountRate / 100, 2);
            $afterChargeDiscount = $subtotal + $serviceChargeAmount - $discountAmount;
            $taxAmount           = round($afterChargeDiscount * $taxRate / 100, 2);
            $total               = $afterChargeDiscount + $taxAmount;
            $paidAmount = min($paidAmount, $total);
            $remaining  = $total - $paidAmount;
            $payStatus  = ($paidAmount <= 0) ? 'unpaid' : ($remaining <= 0 ? 'paid' : 'partial');

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE hotel_invoices SET guest_name=?,guest_phone=?,room_number=?,total=?,paid_amount=?,payment_status=?,payment_method=?,notes=?,tax_rate=?,tax_amount=?,service_charge_rate=?,service_charge_amount=?,discount_rate=?,discount_amount=?,updated_at=NOW() WHERE id=?")
                ->execute([$guestName, $guestPhone ?: null, $roomNumber ?: null, $total, $paidAmount, $payStatus, $payMethod, $notes ?: null, $taxRate, $taxAmount, $serviceChargeRate, $serviceChargeAmount, $discountRate, $discountAmount, $id]);
            $pdo->prepare("DELETE FROM hotel_invoice_items WHERE invoice_id=?")->execute([$id]);
            $iStmt = $pdo->prepare("INSERT INTO hotel_invoice_items (invoice_id,service_type,description,quantity,unit_price,total_price,start_datetime,end_datetime) VALUES (?,?,?,?,?,?,?,?)");
            foreach ($items as $item) {
                $iStmt->execute([$id, $item['service_type'], $item['description'] ?: null, $item['qty'], $item['unit_price'], $item['total'], $item['start_dt'] ?: null, $item['end_dt'] ?: null]);
            }

            $matchedMotorBookingIds = [];
            foreach ($motorRentalItems as $motorRental) {
                $item = $motorRental['item'];
                $motorRow = $motorRental['row'];
                $existingBooking = $existingMotorByAssetId[(int)$item['motor_id']] ?? null;
                if ($existingBooking) {
                    $matchedMotorBookingIds[] = (int)$existingBooking['id'];
                    $newStatus = in_array($existingBooking['status'], ['returned', 'cancelled'], true) ? 'active' : $existingBooking['status'];
                    $pdo->prepare("UPDATE rental_motor_bookings
                        SET invoice_id=?, guest_name=?, guest_phone=?, room_number=?, booking_id=?,
                            start_datetime=?, end_datetime=?, daily_rate=?, total_price=?, deposit=?,
                            status=?, notes=?, updated_at=NOW()
                        WHERE id=? AND business_id=?")
                        ->execute([$id, $guestName, $guestPhone ?: null, $roomNumber ?: null, $invoiceBookingId,
                            $item['start_dt'], $item['end_dt'], $item['unit_price'], $item['total'], $item['deposit'],
                            $newStatus, $notes ?: null, $existingBooking['id'], $businessId]);
                } else {
                    $pdo->prepare("INSERT INTO rental_motor_bookings
                        (business_id, motor_id, invoice_id, guest_name, guest_phone, room_number, booking_id,
                         start_datetime, end_datetime, daily_rate, total_price, deposit, status, notes, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$businessId, (int)$motorRow['id'], $id, $guestName, $guestPhone ?: null, $roomNumber ?: null, $invoiceBookingId,
                            $item['start_dt'], $item['end_dt'], $item['unit_price'], $item['total'], $item['deposit'], 'active', $notes ?: null, $currentUser['id'] ?? null]);
                    $matchedMotorBookingIds[] = (int)$pdo->lastInsertId();
                }
                $pdo->prepare("UPDATE rental_motors SET status='rented', updated_at=NOW() WHERE id=?")
                    ->execute([(int)$motorRow['id']]);
            }

            foreach ($existingMotorBookings as $booking) {
                if (in_array((int)$booking['id'], $matchedMotorBookingIds, true)) continue;
                if (in_array($booking['status'], ['active', 'overdue'], true)) {
                    $pdo->prepare("UPDATE rental_motor_bookings SET status='cancelled', invoice_id=NULL, updated_at=NOW() WHERE id=? AND business_id=?")
                        ->execute([$booking['id'], $businessId]);
                    $activeCheck = $pdo->prepare("SELECT COUNT(*) FROM rental_motor_bookings WHERE motor_id=? AND status IN ('active','overdue') AND id<>? AND business_id=?");
                    $activeCheck->execute([$booking['motor_id'], $booking['id'], $businessId]);
                    if ((int)$activeCheck->fetchColumn() === 0) {
                        $pdo->prepare("UPDATE rental_motors SET status='available', updated_at=NOW() WHERE id=?")
                            ->execute([$booking['motor_id']]);
                    }
                } else {
                    $pdo->prepare("UPDATE rental_motor_bookings SET invoice_id=NULL, updated_at=NOW() WHERE id=? AND business_id=?")
                        ->execute([$booking['id'], $businessId]);
                }
            }

            $matchedCarBookingIds = [];
            foreach ($carRentalItems as $carRental) {
                $item = $carRental['item'];
                $carRow = $carRental['row'];
                $existingBooking = $existingCarByAssetId[(int)$item['car_id']] ?? null;
                if ($existingBooking) {
                    $matchedCarBookingIds[] = (int)$existingBooking['id'];
                    $newStatus = in_array($existingBooking['status'], ['returned', 'cancelled'], true) ? 'active' : $existingBooking['status'];
                    $pdo->prepare("UPDATE rental_car_bookings
                        SET invoice_id=?, guest_name=?, guest_phone=?, room_number=?, booking_id=?,
                            start_datetime=?, end_datetime=?, daily_rate=?, total_price=?, deposit=?,
                            trip_destination=?, status=?, notes=?, updated_at=NOW()
                        WHERE id=? AND business_id=?")
                        ->execute([$id, $guestName, $guestPhone ?: null, $roomNumber ?: null, $invoiceBookingId,
                            $item['start_dt'], $item['end_dt'], $item['unit_price'], $item['total'], $item['deposit'],
                            $item['trip_destination'], $newStatus, $notes ?: null, $existingBooking['id'], $businessId]);
                } else {
                    $pdo->prepare("INSERT INTO rental_car_bookings
                        (business_id, car_id, invoice_id, guest_name, guest_phone, room_number, booking_id,
                         start_datetime, end_datetime, daily_rate, total_price, owner_amount, hotel_commission,
                         deposit, trip_destination, status, notes, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$businessId, (int)$carRow['id'], $id, $guestName, $guestPhone ?: null, $roomNumber ?: null, $invoiceBookingId,
                            $item['start_dt'], $item['end_dt'], $item['unit_price'], $item['total'], 0, 0,
                            $item['deposit'], $item['trip_destination'], 'active', $notes ?: null, $currentUser['id'] ?? null]);
                    $matchedCarBookingIds[] = (int)$pdo->lastInsertId();
                }
                $pdo->prepare("UPDATE rental_cars SET status='rented', updated_at=NOW() WHERE id=?")
                    ->execute([(int)$carRow['id']]);
            }

            foreach ($existingCarBookings as $booking) {
                if (in_array((int)$booking['id'], $matchedCarBookingIds, true)) continue;
                if (in_array($booking['status'], ['active', 'overdue'], true)) {
                    $pdo->prepare("UPDATE rental_car_bookings SET status='cancelled', invoice_id=NULL, updated_at=NOW() WHERE id=? AND business_id=?")
                        ->execute([$booking['id'], $businessId]);
                    $activeCheck = $pdo->prepare("SELECT COUNT(*) FROM rental_car_bookings WHERE car_id=? AND status IN ('active','overdue') AND id<>? AND business_id=?");
                    $activeCheck->execute([$booking['car_id'], $booking['id'], $businessId]);
                    if ((int)$activeCheck->fetchColumn() === 0) {
                        $pdo->prepare("UPDATE rental_cars SET status='available', updated_at=NOW() WHERE id=?")
                            ->execute([$booking['car_id']]);
                    }
                } else {
                    $pdo->prepare("UPDATE rental_car_bookings SET invoice_id=NULL, updated_at=NOW() WHERE id=? AND business_id=?")
                        ->execute([$booking['id'], $businessId]);
                }
            }
            $pdo->commit();
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        }

        throw new Exception('Unknown action');
    } catch (Exception $e) {
        ob_clean();
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ── Fetch list ─────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$filterDate   = $_GET['date']   ?? '';
$search       = trim($_GET['q'] ?? '');

$where  = ["hi.business_id = ?"];
$params = [$businessId];
if ($filterStatus) {
    $where[] = 'hi.status = ?';
    $params[] = $filterStatus;
}
if ($filterDate) {
    $where[] = 'DATE(hi.created_at) = ?';
    $params[] = $filterDate;
}
if ($search) {
    $where[] = '(hi.guest_name LIKE ? OR hi.invoice_number LIKE ? OR hi.room_number LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$stmt = $pdo->prepare("SELECT hi.*,
    GROUP_CONCAT(DISTINCT hii.service_type ORDER BY hii.id SEPARATOR ',') as service_types,
    COUNT(hii.id) as item_count
    FROM hotel_invoices hi
    LEFT JOIN hotel_invoice_items hii ON hii.invoice_id = hi.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY hi.id ORDER BY hi.created_at DESC LIMIT 200");
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats — today totals
$stats = $pdo->prepare("SELECT COUNT(*) as total,
    COALESCE(SUM(total),0) as revenue, COALESCE(SUM(paid_amount),0) as collected,
    SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN payment_status='unpaid' THEN 1 ELSE 0 END) as unpaid
    FROM hotel_invoices WHERE business_id=? AND DATE(created_at)=CURDATE()");
$stats->execute([$businessId]);
$today = $stats->fetch(PDO::FETCH_ASSOC);

// Revenue per service type — this month (paid/partial invoices)
$svcRevStmt = $pdo->prepare("
    SELECT hii.service_type,
           COUNT(DISTINCT hii.invoice_id) AS invoice_count,
           SUM(hii.total_price)           AS total_revenue
    FROM hotel_invoice_items hii
    JOIN hotel_invoices hi ON hii.invoice_id = hi.id
    WHERE hi.business_id = ?
      AND hi.payment_status IN ('paid','partial')
      AND YEAR(hi.created_at)  = YEAR(CURDATE())
      AND MONTH(hi.created_at) = MONTH(CURDATE())
    GROUP BY hii.service_type
    ORDER BY total_revenue DESC
");
$svcRevStmt->execute([$businessId]);
$svcRevStats = $svcRevStmt->fetchAll(PDO::FETCH_ASSOC);

// In-house guests
try {
    $inHouseGuests = $pdo->query("SELECT b.id as booking_id, g.guest_name, r.room_number, g.phone
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.status = 'checked_in'
        ORDER BY r.room_number ASC LIMIT 100")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $inHouseGuests = [];
}

try {
    $motorStmt = $pdo->prepare("SELECT id, plate_number, motor_name, daily_rate FROM rental_motors WHERE business_id=? AND status='available' ORDER BY motor_name ASC, plate_number ASC");
    $motorStmt->execute([$businessId]);
    $availableMotors = $motorStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $availableMotors = [];
}

try {
    $carStmt = $pdo->prepare("SELECT id, plate_number, car_name, car_type, daily_rate FROM rental_cars WHERE business_id=? AND status='available' ORDER BY car_name ASC, plate_number ASC");
    $carStmt->execute([$businessId]);
    $availableCars = $carStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $availableCars = [];
}

// ── GET: load invoice for edit modal ─────────────────────────────────────────
if (isset($_GET['get_invoice']) && isset($_GET['id'])) {
    $gid = (int)$_GET['id'];
    ob_clean();
    try {
        $gInv = $pdo->prepare("SELECT * FROM hotel_invoices WHERE id=? AND business_id=?");
        $gInv->execute([$gid, $businessId]);
        $gRow = $gInv->fetch(PDO::FETCH_ASSOC);
        if (!$gRow) throw new Exception('Not found');
        $gItems = $pdo->prepare("SELECT * FROM hotel_invoice_items WHERE invoice_id=? ORDER BY id");
        $gItems->execute([$gid]);
        $gRow['items'] = $gItems->fetchAll(PDO::FETCH_ASSOC);
        $motorMapStmt = $pdo->prepare("SELECT rb.*, rm.plate_number, rm.motor_name
            FROM rental_motor_bookings rb
            JOIN rental_motors rm ON rb.motor_id = rm.id
            WHERE rb.invoice_id=? AND rb.business_id=?");
        $motorMapStmt->execute([$gid, $businessId]);
        $motorRentals = $motorMapStmt->fetchAll(PDO::FETCH_ASSOC);

        $carMapStmt = $pdo->prepare("SELECT cb.*, rc.plate_number, rc.car_name, rc.car_type
            FROM rental_car_bookings cb
            JOIN rental_cars rc ON cb.car_id = rc.id
            WHERE cb.invoice_id=? AND cb.business_id=?");
        $carMapStmt->execute([$gid, $businessId]);
        $carRentals = $carMapStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($gRow['items'] as &$gItem) {
            if (($gItem['service_type'] ?? '') === 'motor_rental') {
                foreach ($motorRentals as $mr) {
                    if (strpos((string)($gItem['description'] ?? ''), (string)$mr['plate_number']) !== false) {
                        $gItem['motor_id'] = (int)$mr['motor_id'];
                        $gItem['start_dt'] = $mr['start_datetime'];
                        $gItem['end_dt'] = $mr['end_datetime'];
                        $gItem['deposit'] = (float)$mr['deposit'];
                        break;
                    }
                }
            }
            if (($gItem['service_type'] ?? '') === 'car_rental') {
                foreach ($carRentals as $cr) {
                    if (strpos((string)($gItem['description'] ?? ''), (string)$cr['plate_number']) !== false) {
                        $gItem['car_id'] = (int)$cr['car_id'];
                        $gItem['start_dt'] = $cr['start_datetime'];
                        $gItem['end_dt'] = $cr['end_datetime'];
                        $gItem['deposit'] = (float)$cr['deposit'];
                        $gItem['trip_destination'] = $cr['trip_destination'];
                        break;
                    }
                }
            }
        }
        unset($gItem);
        $gRow['success'] = true;
        echo json_encode($gRow);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Load catalog items for JS
try {
    $catalogItems = $pdo->prepare("SELECT * FROM hotel_service_catalog WHERE business_id=? AND is_active=1 ORDER BY service_type, sort_order, item_name");
    $catalogItems->execute([$businessId]);
    $catalogRows = $catalogItems->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $catalogRows = [];
}

// Load current settings for settings modal
$hsSettings = [];
try {
    $settingsRows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'company_%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($settingsRows as $r) {
        $hsSettings[$r['setting_key']] = $r['setting_value'];
    }
} catch (\Throwable $e) {
}

include '../../includes/header.php';
?>
<style>
    .hs-page {
        padding: 1.25rem;
    }

    .hs-topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.25rem;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .hs-topbar h2 {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0;
    }

    .hs-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1.25rem;
    }

    .hs-stat {
        background: white;
        border-radius: 10px;
        padding: 0.85rem 1rem;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.07);
        border-top: 3px solid var(--c);
    }

    .hs-stat .val {
        font-size: 1.25rem;
        font-weight: 800;
        color: var(--c);
    }

    .hs-stat .lbl {
        font-size: 0.72rem;
        color: var(--text-secondary);
        margin-top: 0.15rem;
    }

    .hs-filters {
        background: white;
        border-radius: 10px;
        padding: 0.85rem 1rem;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.07);
        margin-bottom: 1rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem;
        align-items: center;
    }

    .hs-filters input,
    .hs-filters select {
        padding: 0.4rem 0.6rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.8rem;
        background: white;
        color: var(--text-primary);
    }

    .hs-table-wrap {
        background: white;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.07);
        overflow: hidden;
    }

    .hs-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }

    .hs-table th {
        background: #f8fafc;
        padding: 0.65rem 0.85rem;
        text-align: left;
        font-weight: 600;
        color: var(--text-secondary);
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        border-bottom: 1px solid #e2e8f0;
    }

    .hs-table td {
        padding: 0.65rem 0.85rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    .hs-table tr:last-child td {
        border-bottom: none;
    }

    .hs-table tr:hover td {
        background: #fafbff;
    }

    .hs-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 78px;
        padding: 0.28rem 0.72rem;
        border-radius: 999px;
        font-size: 0.66rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #ffffff !important;
        border: 1px solid rgba(255, 255, 255, 0.22);
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.18);
        line-height: 1;
        white-space: nowrap;
        position: relative;
        isolation: isolate;
        overflow: hidden;
    }

    .hs-badge::before {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.18), rgba(255, 255, 255, 0));
        z-index: 1;
    }

    .hs-badge-text {
        position: relative;
        z-index: 2;
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
        text-shadow: 0 1px 1px rgba(0, 0, 0, 0.28);
    }

    .hs-svc-pill {
        display: inline-block;
        padding: 0.15rem 0.45rem;
        border-radius: 12px;
        font-size: 0.68rem;
        font-weight: 600;
        background: #ede9fe;
        color: #5b21b6;
        margin: 0.1rem 0.1rem 0 0;
        white-space: nowrap;
    }

    .hs-rental-extra {
        display: none;
        margin-top: 0.35rem;
        padding: 0.45rem;
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
        background: #f8fafc;
        gap: 0.4rem;
    }

    .hs-rental-extra.open {
        display: grid;
    }

    .hs-rental-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.35rem;
    }

    .hs-rental-extra input,
    .hs-rental-extra select {
        width: 100%;
        padding: 0.38rem 0.5rem;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.76rem;
        box-sizing: border-box;
        background: #fff;
    }

    .hs-action-btn {
        padding: 0.25rem 0.55rem;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-size: 0.72rem;
        font-weight: 600;
        transition: opacity 0.2s;
    }

    .hs-action-btn:hover {
        opacity: 0.8;
    }

    /* Modal */
    .hs-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.55);
        z-index: 99999;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .hs-modal-overlay.open {
        display: flex;
    }

    .hs-modal {
        background: white;
        border-radius: 14px;
        padding: 1.5rem;
        width: 100%;
        max-width: 660px;
        max-height: 92vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }

    .hs-modal h3 {
        margin: 0 0 1rem;
        font-size: 1.05rem;
        font-weight: 700;
    }

    .hs-form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .hs-form-row.full {
        grid-template-columns: 1fr;
    }

    .hs-field label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 0.3rem;
    }

    .hs-field input,
    .hs-field select,
    .hs-field textarea {
        width: 100%;
        padding: 0.5rem 0.65rem;
        border: 1px solid #e2e8f0;
        border-radius: 7px;
        font-size: 0.85rem;
        color: var(--text-primary);
        background: white;
        box-sizing: border-box;
    }

    .hs-field textarea {
        resize: vertical;
        min-height: 55px;
    }

    .hs-field input:focus,
    .hs-field select:focus,
    .hs-field textarea:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.15);
    }

    /* Guest toggle */
    .guest-toggle {
        display: flex;
        gap: 0.4rem;
        margin-bottom: 0.6rem;
    }

    .guest-toggle button {
        flex: 1;
        padding: 0.4rem 0.6rem;
        border: 2px solid #e2e8f0;
        border-radius: 7px;
        background: white;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s;
        color: #374151;
    }

    .guest-toggle button.active {
        border-color: #6366f1;
        background: #ede9fe;
        color: #4c1d95;
    }

    /* Items table */
    .items-tbl {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 0.5rem;
        font-size: 0.8rem;
    }

    .items-tbl th {
        background: #f8fafc;
        padding: 0.45rem 0.5rem;
        font-size: 0.7rem;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
    }

    .items-tbl td {
        padding: 0.35rem 0.3rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    .items-tbl td input,
    .items-tbl td select {
        padding: 0.35rem 0.4rem;
        border: 1px solid #e2e8f0;
        border-radius: 5px;
        font-size: 0.78rem;
        background: white;
        box-sizing: border-box;
        width: 100%;
    }

    .items-tbl td input:focus,
    .items-tbl td select:focus {
        outline: none;
        border-color: #6366f1;
    }

    .btn-add-item {
        background: #f0f4ff;
        color: #4338ca;
        border: 1px dashed #6366f1;
        border-radius: 7px;
        padding: 0.4rem 0.8rem;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        width: 100%;
        margin-bottom: 0.75rem;
    }

    .btn-add-item:hover {
        background: #ede9fe;
    }

    .btn-del-row {
        background: #fee2e2;
        color: #b91c1c;
        border: none;
        border-radius: 4px;
        padding: 0.25rem 0.45rem;
        cursor: pointer;
        font-size: 0.78rem;
        font-weight: 700;
    }

    .hs-total-preview {
        background: linear-gradient(135deg, #f0f4ff, #e8edff);
        border-radius: 8px;
        padding: 0.75rem 1rem;
        text-align: center;
        margin: 0.75rem 0;
        font-size: 1.1rem;
        font-weight: 700;
        color: #4338ca;
    }

    .hs-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 0.6rem;
        margin-top: 1rem;
    }

    .btn-hs {
        padding: 0.5rem 1.25rem;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        font-size: 0.85rem;
    }

    .btn-hs-primary {
        background: var(--primary, #6366f1);
        color: white;
    }

    .btn-hs-secondary {
        background: #f3f4f6;
        color: #374151;
        border: 1px solid #e5e7eb;
    }

    .hs-empty {
        text-align: center;
        padding: 3rem 1rem;
        color: var(--text-secondary);
    }

    .hs-empty .em-icon {
        font-size: 2.5rem;
        margin-bottom: 0.5rem;
    }

    .sect-label {
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--text-secondary);
        margin-bottom: 0.4rem;
        display: block;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    /* Tabs */
    .hs-tabs {
        display: flex;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 1rem;
        gap: 0;
    }

    .hs-tab {
        padding: 0.5rem 1rem;
        font-size: 0.82rem;
        font-weight: 600;
        cursor: pointer;
        color: #64748b;
        border-bottom: 2px solid transparent;
        margin-bottom: -2px;
        background: none;
        border-top: none;
        border-left: none;
        border-right: none;
    }

    .hs-tab.active {
        color: #4338ca;
        border-bottom-color: #6366f1;
    }

    .hs-tab-pane {
        display: none;
    }

    .hs-tab-pane.active {
        display: block;
    }

    /* Catalog table */
    .cat-tbl {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }

    .cat-tbl th {
        background: #f8fafc;
        padding: 0.4rem 0.5rem;
        font-size: 0.7rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        border-bottom: 2px solid #e2e8f0;
        text-align: left;
    }

    .cat-tbl td {
        padding: 0.4rem 0.5rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    .cat-tbl td input,
    .cat-tbl td select {
        width: 100%;
        padding: 0.3rem 0.4rem;
        border: 1px solid #e2e8f0;
        border-radius: 5px;
        font-size: 0.78rem;
        background: white;
        box-sizing: border-box;
    }

    .cat-tbl .btn-cat-del {
        background: #fee2e2;
        color: #b91c1c;
        border: none;
        border-radius: 4px;
        padding: 0.25rem 0.5rem;
        cursor: pointer;
        font-size: 0.75rem;
    }

    .cat-tbl .btn-cat-save {
        background: #dcfce7;
        color: #15803d;
        border: none;
        border-radius: 4px;
        padding: 0.25rem 0.5rem;
        cursor: pointer;
        font-size: 0.75rem;
    }

    .logo-preview {
        max-height: 60px;
        border-radius: 6px;
        margin-top: 0.4rem;
        display: block;
    }

    @media(max-width:580px) {
        .hs-form-row {
            grid-template-columns: 1fr;
        }

        .hs-stats {
            grid-template-columns: repeat(2, 1fr);
        }
    }
</style>

<div class="hs-page">

    <div class="hs-topbar">
        <div>
            <h2>🛎️ Hotel Services</h2>
            <div style="font-size:0.75rem;color:var(--text-secondary)">Motor Rental · Laundry · Service · Airport Drop · Harbor Drop · Narayana Trip · Lain-lain</div>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
            <button class="btn-hs btn-hs-secondary" onclick="openSettingsModal()">⚙️ Pengaturan</button>
            <button class="btn-hs btn-hs-primary" onclick="openCreateModal()">+ New Invoice</button>
        </div>
    </div>

    <!-- Stats -->
    <div class="hs-stats">
        <div class="hs-stat" style="--c:#6366f1">
            <div class="val"><?php echo $today['total']; ?></div>
            <div class="lbl">Invoices Today</div>
        </div>
        <div class="hs-stat" style="--c:#10b981">
            <div class="val">Rp <?php echo number_format($today['revenue'], 0, ',', '.'); ?></div>
            <div class="lbl">Revenue Today</div>
        </div>
        <div class="hs-stat" style="--c:#3b82f6">
            <div class="val">Rp <?php echo number_format($today['collected'], 0, ',', '.'); ?></div>
            <div class="lbl">Collected</div>
        </div>
        <div class="hs-stat" style="--c:#ef4444">
            <div class="val"><?php echo $today['unpaid']; ?></div>
            <div class="lbl">Unpaid</div>
        </div>
        <div class="hs-stat" style="--c:#8b5cf6">
            <div class="val"><?php echo $today['completed']; ?></div>
            <div class="lbl">Completed</div>
        </div>
    </div>

    <!-- Revenue per Service Type (this month) -->
    <?php if (!empty($svcRevStats)): ?>
        <div style="background:white;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,0.07);padding:0.85rem 1rem;margin-bottom:1rem;">
            <div style="font-size:0.72rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.65rem;">
                📊 Revenue per Service — This Month
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:0.6rem;">
                <?php
                $svcColors = ['motor_rental' => '#f59e0b', 'car_rental' => '#0f766e', 'laundry' => '#3b82f6', 'service' => '#10b981', 'airport_drop' => '#8b5cf6', 'harbor_drop' => '#06b6d4', 'narayana_trip' => '#ec4899', 'lain_lain' => '#78716c'];
                foreach ($svcRevStats as $sr):
                    $svcKey  = $sr['service_type'];
                    $svcInfo = $serviceTypes[$svcKey] ?? ['label' => $svcKey, 'icon' => '🔹'];
                    $color   = $svcColors[$svcKey] ?? '#6366f1';
                ?>
                    <div style="flex:1;min-width:130px;border-left:3px solid <?php echo $color; ?>;padding:0.5rem 0.75rem;background:#fafbff;border-radius:0 7px 7px 0;">
                        <div style="font-size:0.8rem;font-weight:700;color:<?php echo $color; ?>"><?php echo $svcInfo['icon']; ?> <?php echo htmlspecialchars($svcInfo['label']); ?></div>
                        <div style="font-size:0.95rem;font-weight:800;color:#1e293b;margin-top:0.15rem">Rp <?php echo number_format($sr['total_revenue'], 0, ',', '.'); ?></div>
                        <div style="font-size:0.68rem;color:var(--text-secondary)"><?php echo $sr['invoice_count']; ?> invoice<?php echo $sr['invoice_count'] != 1 ? 's' : ''; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="GET" class="hs-filters">
        <input type="text" name="q" placeholder="🔍 Guest / Invoice..." value="<?php echo htmlspecialchars($search); ?>">
        <select name="status">
            <option value="">All Status</option>
            <?php foreach (['pending', 'confirmed', 'completed', 'cancelled'] as $s): ?>
                <option value="<?php echo $s; ?>" <?php echo $filterStatus === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date" value="<?php echo htmlspecialchars($filterDate); ?>">
        <button type="submit" class="btn-hs btn-hs-primary" style="padding:0.4rem 0.9rem;font-size:0.8rem">Filter</button>
        <?php if ($filterStatus || $filterDate || $search): ?>
            <a href="hotel-services.php" class="btn-hs btn-hs-secondary" style="padding:0.4rem 0.9rem;font-size:0.8rem;text-decoration:none">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Table -->
    <div class="hs-table-wrap">
        <?php if (empty($invoices)): ?>
            <div class="hs-empty">
                <div class="em-icon">🛎️</div>
                <div style="font-weight:600;margin-bottom:0.25rem">No service invoices yet</div>
                <div style="font-size:0.8rem">Click "+ New Invoice" to create your first one</div>
            </div>
        <?php else: ?>
            <table class="hs-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Guest</th>
                        <th>Room</th>
                        <th>Services</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Pay Status</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv):
                        $svcList = array_filter(explode(',', $inv['service_types'] ?? '')); ?>
                        <tr>
                            <td style="font-weight:700;color:#4338ca;white-space:nowrap"><?php echo htmlspecialchars($inv['invoice_number']); ?></td>
                            <td>
                                <div style="font-weight:600"><?php echo htmlspecialchars($inv['guest_name']); ?></div>
                                <?php if ($inv['guest_phone']): ?><div style="font-size:0.7rem;color:var(--text-secondary)"><?php echo htmlspecialchars($inv['guest_phone']); ?></div><?php endif; ?>
                            </td>
                            <td><?php echo $inv['room_number'] ? htmlspecialchars($inv['room_number']) : '<span style="color:#d1d5db">—</span>'; ?></td>
                            <td>
                                <?php foreach (array_unique($svcList) as $svc): ?>
                                    <span class="hs-svc-pill"><?php echo $serviceTypes[$svc]['icon'] ?? ''; ?> <?php echo $serviceTypes[$svc]['label'] ?? $svc; ?></span>
                                <?php endforeach; ?>
                                <?php if ((int)$inv['item_count'] > 1): ?><div style="font-size:0.68rem;color:#6b7280;margin-top:2px"><?php echo $inv['item_count']; ?> items</div><?php endif; ?>
                            </td>
                            <td style="font-weight:700;white-space:nowrap">Rp <?php echo number_format($inv['total'], 0, ',', '.'); ?></td>
                            <td style="color:#10b981;font-weight:600;white-space:nowrap">Rp <?php echo number_format($inv['paid_amount'], 0, ',', '.'); ?></td>
                            <td><span class="hs-badge" style="background:<?php echo $payStatusColors[$inv['payment_status']]; ?>"><span class="hs-badge-text"><?php echo strtoupper($inv['payment_status']); ?></span></span></td>
                            <td><span class="hs-badge" style="background:<?php echo $statusColors[$inv['status']]; ?>"><span class="hs-badge-text"><?php echo strtoupper($inv['status']); ?></span></span></td>
                            <td style="font-size:0.72rem;color:var(--text-secondary);white-space:nowrap"><?php echo date('d M Y', strtotime($inv['created_at'])); ?></td>
                            <td>
                                <div style="display:flex;gap:0.3rem;flex-wrap:wrap;min-width:160px">
                                    <?php if ($auth->canEdit('frontdesk')): ?>
                                        <button class="hs-action-btn" style="background:#e0f2fe;color:#0277bd;text-decoration:none" onclick="openEditModal(<?php echo $inv['id']; ?>)">✏️ Edit</button>
                                    <?php endif; ?>
                                    <a href="hotel-service-invoice.php?id=<?php echo $inv['id']; ?>" target="_blank" class="hs-action-btn" style="background:#e0f2fe;color:#0277bd;text-decoration:none">🖨️ Invoice</a>
                                    <?php if ($inv['payment_status'] !== 'paid'): ?>
                                        <button class="hs-action-btn" style="background:#dcfce7;color:#15803d"
                                            onclick="openPayModal(<?php echo $inv['id']; ?>,<?php echo $inv['total'] - $inv['paid_amount']; ?>,'<?php echo htmlspecialchars($inv['invoice_number'], ENT_QUOTES); ?>')">💳 Pay</button>
                                    <?php endif; ?>
                                    <?php if ($auth->canEdit('frontdesk')): ?>
                                        <select class="hs-action-btn" style="background:#f3f4f6;color:#374151"
                                            onchange="updateStatus(<?php echo $inv['id']; ?>,this.value);this.blur()">
                                            <?php foreach (['pending', 'confirmed', 'completed', 'cancelled'] as $s): ?>
                                                <option value="<?php echo $s; ?>" <?php echo $inv['status'] === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                    <?php if ($auth->canDelete('frontdesk')): ?>
                                        <button class="hs-action-btn" style="background:#fee2e2;color:#b91c1c"
                                            onclick="deleteInvoice(<?php echo $inv['id']; ?>,'<?php echo htmlspecialchars($inv['invoice_number'], ENT_QUOTES); ?>')">✕</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- ══ CREATE MODAL ════════════════════════════════════════════════════════════ -->
<div id="createModal" class="hs-modal-overlay" onclick="if(event.target===this)closeCreateModal()">
    <div class="hs-modal">
        <h3>🛎️ New Service Invoice</h3>

        <!-- Guest -->
        <div style="margin-bottom:0.75rem">
            <span class="sect-label">Guest</span>
            <div class="guest-toggle">
                <button type="button" id="btnInhouse" class="active" onclick="setGuestMode('inhouse')">🏨 In-house Guest</button>
                <button type="button" id="btnManual" onclick="setGuestMode('manual')">✏️ Enter Manually</button>
            </div>
            <div id="inhouseSection">
                <select id="fGuestSelect" onchange="fillFromInhouse()" style="width:100%;padding:0.5rem 0.65rem;border:1px solid #e2e8f0;border-radius:7px;font-size:0.85rem;background:white;box-sizing:border-box">
                    <option value="">— Select in-house guest —</option>
                    <?php foreach ($inHouseGuests as $g): ?>
                        <option value="<?php echo $g['booking_id']; ?>"
                            data-name="<?php echo htmlspecialchars($g['guest_name'] ?? ''); ?>"
                            data-room="<?php echo htmlspecialchars($g['room_number'] ?? ''); ?>"
                            data-phone="<?php echo htmlspecialchars($g['phone'] ?? ''); ?>">
                            Room <?php echo htmlspecialchars($g['room_number'] ?? '?'); ?> — <?php echo htmlspecialchars($g['guest_name'] ?? ''); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="manualSection" style="display:none">
                <input type="text" id="fGuestName" placeholder="Enter guest name" style="width:100%;padding:0.5rem 0.65rem;border:1px solid #e2e8f0;border-radius:7px;font-size:0.85rem;box-sizing:border-box">
            </div>
            <input type="hidden" id="fBookingId">
        </div>

        <!-- Phone + Room -->
        <div class="hs-form-row">
            <div class="hs-field"><label>Phone</label><input type="text" id="fPhone" placeholder="Optional"></div>
            <div class="hs-field"><label>Room Number</label><input type="text" id="fRoom" placeholder="e.g. 101"></div>
        </div>

        <!-- Service items -->
        <span class="sect-label">Service Items *</span>
        <div style="overflow-x:auto;margin-bottom:0.4rem">
            <table class="items-tbl">
                <thead>
                    <tr>
                        <th style="min-width:140px">Service Type</th>
                        <th style="min-width:160px">Description</th>
                        <th style="width:65px">Qty</th>
                        <th style="width:115px">Unit Price</th>
                        <th style="width:105px;text-align:right">Subtotal</th>
                        <th style="width:34px"></th>
                    </tr>
                </thead>
                <tbody id="itemsBody"></tbody>
            </table>
        </div>
        <button type="button" class="btn-add-item" onclick="addItemRow()">+ Add Service Item</button>

        <!-- Tax, Service Charge, Discount -->
        <span class="sect-label">Tax, Service Charge & Discount</span>
        <div class="hs-form-row" style="margin-bottom:0.5rem">
            <div class="hs-field">
                <label>Tarif PPN</label>
                <select id="fTaxRate" onchange="onTaxRateChange()">
                    <option value="0">Tanpa PPN (0%)</option>
                    <option value="5">5%</option>
                    <option value="10">10%</option>
                    <option value="11">11% (Standar)</option>
                    <option value="custom">Custom...</option>
                </select>
            </div>
            <div class="hs-field" id="customTaxWrap" style="display:none">
                <label>Custom PPN (%)</label>
                <input type="number" id="fTaxCustom" value="0" min="0" max="100" step="0.5" placeholder="e.g. 5.5" oninput="refreshTotal()">
            </div>
        </div>
        <div class="hs-form-row" style="margin-bottom:0.5rem">
            <div class="hs-field">
                <label>Service Charge (%)</label>
                <input type="number" id="fServiceCharge" value="0" min="0" max="100" step="0.5" oninput="refreshTotal()">
            </div>
            <div class="hs-field">
                <label>Discount (%)</label>
                <input type="number" id="fDiscount" value="0" min="0" max="100" step="0.5" oninput="refreshTotal()">
            </div>
        </div>

        <!-- Payment -->
        <span class="sect-label">Pembayaran / DP</span>
        <div class="hs-form-row">
            <div class="hs-field">
                <label>Metode Bayar</label>
                <select id="fPayMethod">
                    <option value="cash">Cash</option>
                    <option value="transfer">Transfer</option>
                    <option value="qris">QRIS</option>
                    <option value="card">Card</option>
                </select>
            </div>
            <div class="hs-field">
                <label>DP / Down Payment (Rp)</label>
                <input type="number" id="fPaid" value="0" min="0" oninput="enforceMaxPaid()" placeholder="0 = belum bayar">
            </div>
        </div>
        <label style="font-size:0.8rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:0.4rem;margin-bottom:0.75rem">
            <input type="checkbox" id="fFullPay" onchange="toggleFullPay(this.checked)"> Bayar Penuh (Lunas)
        </label>

        <!-- Notes -->
        <div class="hs-field"><label>Notes</label><textarea id="fNotes" rows="2" placeholder="Special instructions..."></textarea></div>

        <div class="hs-total-preview" id="totalPreview" style="text-align:left;line-height:1.7">
            <div style="font-size:0.82rem;color:#6b7280">Subtotal: <span id="tpSubtotal">Rp 0</span></div>
            <div style="font-size:0.82rem;color:#3b82f6" id="tpScRow" style="display:none">Service Charge: <span id="tpSc">Rp 0</span></div>
            <div style="font-size:0.82rem;color:#ef4444" id="tpDiscRow" style="display:none">Discount: <span id="tpDisc">- Rp 0</span></div>
            <div style="font-size:0.82rem;color:#f59e0b" id="tpTaxRow" style="display:none">PPN: <span id="tpTax">Rp 0</span></div>
            <div style="font-size:1.05rem;font-weight:800;color:#4338ca;border-top:1px solid #dde3ff;padding-top:4px;margin-top:2px">Grand Total: <span id="tpGrand">Rp 0</span></div>
            <div style="font-size:0.82rem;color:#10b981" id="tpDpRow" style="display:none">DP Dibayar: <span id="tpDp">Rp 0</span></div>
            <div style="font-size:0.82rem;color:#ef4444" id="tpSisaRow" style="display:none">Sisa: <span id="tpSisa">Rp 0</span></div>
        </div>

        <div class="hs-modal-footer">
            <button class="btn-hs btn-hs-secondary" onclick="closeCreateModal()">Cancel</button>
            <button class="btn-hs btn-hs-primary" id="createBtn" onclick="submitCreate()">✅ Create Invoice</button>
        </div>
    </div>
</div>

<!-- ══ PAY MODAL ══════════════════════════════════════════════════════════════ -->
<div id="payModal" class="hs-modal-overlay" onclick="if(event.target===this)closePayModal()">
    <div class="hs-modal" style="max-width:360px">
        <h3>💳 Add Payment</h3>
        <input type="hidden" id="pInvId">
        <div id="pInvNo" style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:0.5rem"></div>
        <div class="hs-field" style="margin-bottom:0.75rem">
            <label>Remaining Balance</label>
            <div id="pRemaining" style="font-size:1.2rem;font-weight:700;color:#ef4444;padding:0.4rem 0"></div>
        </div>
        <div class="hs-form-row">
            <div class="hs-field"><label>Amount (Rp)</label><input type="number" id="pAmount" value="0" min="0"></div>
            <div class="hs-field"><label>Method</label>
                <select id="pMethod">
                    <option value="cash">Cash</option>
                    <option value="transfer">Transfer</option>
                    <option value="qris">QRIS</option>
                    <option value="card">Card</option>
                </select>
            </div>
        </div>
        <div class="hs-modal-footer">
            <button class="btn-hs btn-hs-secondary" onclick="closePayModal()">Cancel</button>
            <button class="btn-hs btn-hs-primary" id="payBtn" onclick="submitPay()">💾 Save &amp; Sync to Cashbook</button>
        </div>
    </div>
</div>

<!-- ══ SETTINGS MODAL ══════════════════════════════════════════════════════════════════════ -->
<div id="settingsModal" class="hs-modal-overlay" onclick="if(event.target===this)closeSettingsModal()">
    <div class="hs-modal" style="max-width:700px">
        <h3>⚙️ Pengaturan Hotel Services</h3>
        <div class="hs-tabs">
            <button class="hs-tab active" id="tab-inv" onclick="switchTab('inv')"> 🏨 Invoice &amp; Perusahaan</button>
            <button class="hs-tab" id="tab-catalog" onclick="switchTab('catalog')">📂 Katalog Harga</button>
            <button class="hs-tab" id="tab-svctype" onclick="switchTab('svctype')">🏷️ Tipe Layanan</button>
        </div>

        <!-- TAB 1: Invoice & Company -->
        <div class="hs-tab-pane active" id="pane-inv">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                <div class="hs-field"><label>Nama Perusahaan</label><input type="text" id="sCmpName" value="<?php echo htmlspecialchars($hsSettings['company_name'] ?? 'Narayana Hotel Karimunjawa', ENT_QUOTES); ?>"></div>
                <div class="hs-field"><label>Website</label><input type="text" id="sCmpWeb" value="<?php echo htmlspecialchars($hsSettings['company_website'] ?? 'www.narayanakarimunjawa.com', ENT_QUOTES); ?>"></div>
                <div class="hs-field"><label>Telepon</label><input type="text" id="sCmpPhone" value="<?php echo htmlspecialchars($hsSettings['company_phone'] ?? '', ENT_QUOTES); ?>"></div>
                <div class="hs-field"><label>Email</label><input type="email" id="sCmpEmail" value="<?php echo htmlspecialchars($hsSettings['company_email'] ?? '', ENT_QUOTES); ?>"></div>
                <div class="hs-field" style="grid-column:1/-1"><label>Alamat</label><textarea id="sCmpAddr" rows="2"><?php echo htmlspecialchars($hsSettings['company_address'] ?? 'Karimunjawa, Jepara, Central Java, Indonesia', ENT_QUOTES); ?></textarea></div>
            </div>
            <div class="hs-field" style="margin-top:0.75rem">
                <label>Logo Perusahaan (upload gambar baru)</label>
                <input type="file" id="sLogoFile" accept="image/*" onchange="previewLogo(this)">
                <?php if (!empty($hsSettings['company_logo'])): ?>
                    <img id="logoPreview" src="<?php echo htmlspecialchars($hsSettings['company_logo']); ?>" class="logo-preview">
                <?php else: ?>
                    <img id="logoPreview" src="" class="logo-preview" style="display:none">
                <?php endif; ?>
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.25rem">Format: JPG, PNG, SVG, WebP. Logo saat ini: <em><?php echo htmlspecialchars(basename($hsSettings['company_logo'] ?? 'belum diatur')); ?></em></div>
            </div>

            <!-- Payment Info -->
            <div style="margin-top:1.1rem;padding-top:0.9rem;border-top:2px solid #e2e8f0">
                <div style="font-size:0.7rem;font-weight:700;color:#1a3457;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.65rem">🏦 Payment Details (shown on invoice)</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                    <div class="hs-field"><label>Bank Name</label><input type="text" id="sPayBank" placeholder="e.g. BCA / Mandiri / BNI" value="<?php echo htmlspecialchars($hsSettings['payment_info_bank'] ?? '', ENT_QUOTES); ?>"></div>
                    <div class="hs-field"><label>Account Number</label><input type="text" id="sPayAccount" placeholder="e.g. 1234567890" value="<?php echo htmlspecialchars($hsSettings['payment_info_account'] ?? '', ENT_QUOTES); ?>"></div>
                    <div class="hs-field"><label>Account Holder Name</label><input type="text" id="sPayName" placeholder="e.g. Narayana Hotel" value="<?php echo htmlspecialchars($hsSettings['payment_info_name'] ?? '', ENT_QUOTES); ?>"></div>
                    <div class="hs-field"><label>Additional Note</label><input type="text" id="sPayNote" placeholder="e.g. Transfer reference: Invoice No." value="<?php echo htmlspecialchars($hsSettings['payment_info_note'] ?? '', ENT_QUOTES); ?>"></div>
                </div>
            </div>
            <div class="hs-modal-footer">
                <button class="btn-hs btn-hs-secondary" onclick="closeSettingsModal()">Cancel</button>
                <button class="btn-hs btn-hs-primary" id="btnSaveSettings" onclick="saveSettings()">💾 Save Settings</button>
            </div>
        </div>

        <!-- TAB 2: Catalog Harga -->
        <div class="hs-tab-pane" id="pane-catalog">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.65rem">
                <span style="font-size:0.78rem;color:#64748b">Database item layanan &amp; harga default. Klik item saat tambah invoice untuk isi otomatis.</span>
                <button class="btn-hs btn-hs-primary" style="font-size:0.78rem;padding:0.35rem 0.85rem" onclick="addCatalogRow()">+ Tambah Item</button>
            </div>
            <div style="overflow-x:auto;max-height:55vh;overflow-y:auto">
                <table class="cat-tbl">
                    <thead>
                        <tr>
                            <th style="min-width:130px">Tipe Layanan</th>
                            <th style="min-width:140px">Nama Item</th>
                            <th style="width:110px">Harga Default</th>
                            <th style="width:75px">Satuan</th>
                            <th style="width:50px">Urut</th>
                            <th style="width:80px"></th>
                        </tr>
                    </thead>
                    <tbody id="catalogBody"><?php
                                            foreach ($catalogRows as $cr): ?>
                            <tr id="ctr<?php echo $cr['id']; ?>">
                                <td><select class="cSType">
                                        <?php foreach ($serviceTypes as $sk => $sv): ?>
                                            <option value="<?php echo $sk; ?>" <?php echo $cr['service_type'] === $sk ? 'selected' : ''; ?>><?php echo $sv['icon'] . ' ' . $sv['label']; ?></option>
                                        <?php endforeach; ?>
                                    </select></td>
                                <td><input type="text" class="cName" value="<?php echo htmlspecialchars($cr['item_name'], ENT_QUOTES); ?>"></td>
                                <td><input type="number" class="cPrice" value="<?php echo $cr['default_price']; ?>" min="0"></td>
                                <td><input type="text" class="cUnit" value="<?php echo htmlspecialchars($cr['unit'] ?? 'unit', ENT_QUOTES); ?>"></td>
                                <td><input type="number" class="cSort" value="<?php echo $cr['sort_order']; ?>" style="width:45px"></td>
                                <td style="display:flex;gap:3px">
                                    <button class="btn-cat-save" onclick="saveCatalogRow(<?php echo $cr['id']; ?>)">💾</button>
                                    <button class="btn-cat-del" onclick="deleteCatalogRow(<?php echo $cr['id']; ?>)">✕</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB 3: Tipe Layanan -->
        <div class="hs-tab-pane" id="pane-svctype">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.65rem">
                <span style="font-size:0.78rem;color:#64748b">Kelola tipe layanan yang tersedia di invoice. Key harus unik (huruf kecil, underscore).</span>
                <button class="btn-hs btn-hs-primary" style="font-size:0.78rem;padding:0.35rem 0.85rem" onclick="addSvcTypeRow()">+ Tambah Tipe</button>
            </div>
            <div style="overflow-x:auto;max-height:55vh;overflow-y:auto">
                <table class="cat-tbl">
                    <thead>
                        <tr>
                            <th style="width:40px">Icon</th>
                            <th style="min-width:110px">Key</th>
                            <th style="min-width:140px">Label</th>
                            <th style="width:50px">Urut</th>
                            <th style="width:80px"></th>
                        </tr>
                    </thead>
                    <tbody id="svcTypeBody">
                        <?php
                        $allSvcTypes = $pdo->prepare("SELECT * FROM hotel_service_types WHERE business_id=? ORDER BY sort_order, type_label");
                        $allSvcTypes->execute([$businessId]);
                        foreach ($allSvcTypes->fetchAll(PDO::FETCH_ASSOC) as $st): ?>
                            <tr id="str<?php echo $st['id']; ?>">
                                <td><input type="text" class="stIcon" value="<?php echo htmlspecialchars($st['type_icon'], ENT_QUOTES); ?>" style="width:40px;text-align:center"></td>
                                <td><input type="text" class="stKey" value="<?php echo htmlspecialchars($st['type_key'], ENT_QUOTES); ?>"></td>
                                <td><input type="text" class="stLabel" value="<?php echo htmlspecialchars($st['type_label'], ENT_QUOTES); ?>"></td>
                                <td><input type="number" class="stSort" value="<?php echo $st['sort_order']; ?>" style="width:45px"></td>
                                <td style="display:flex;gap:3px">
                                    <button class="btn-cat-save" onclick="saveSvcType(<?php echo $st['id']; ?>)">💾</button>
                                    <button class="btn-cat-del" onclick="deleteSvcType(<?php echo $st['id']; ?>)">✕</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- ══ EDIT INVOICE MODAL ════════════════════════════════════════════════════════════════════ -->
<div id="editModal" class="hs-modal-overlay" onclick="if(event.target===this)closeEditModal()">
    <div class="hs-modal">
        <h3>✏️ Edit Invoice</h3>
        <input type="hidden" id="eInvId">
        <div id="eInvNo" style="font-size:0.78rem;color:#6366f1;font-weight:700;margin-bottom:0.75rem"></div>

        <div class="hs-form-row">
            <div class="hs-field"><label>Nama Tamu</label><input type="text" id="eGuestName"></div>
            <div class="hs-field"><label>Telepon</label><input type="text" id="ePhone"></div>
        </div>
        <div class="hs-field" style="margin-bottom:0.75rem"><label>Nomor Kamar</label><input type="text" id="eRoom" style="width:200px"></div>

        <span class="sect-label">Service Items *</span>
        <div style="overflow-x:auto;margin-bottom:0.4rem">
            <table class="items-tbl">
                <thead>
                    <tr>
                        <th style="min-width:140px">Tipe Layanan</th>
                        <th style="min-width:160px">Deskripsi</th>
                        <th style="width:65px">Qty</th>
                        <th style="width:115px">Harga Satuan</th>
                        <th style="width:105px;text-align:right">Subtotal</th>
                        <th style="width:34px"></th>
                    </tr>
                </thead>
                <tbody id="eItemsBody"></tbody>
            </table>
        </div>
        <button type="button" class="btn-add-item" onclick="eAddItemRow()">+ Tambah Item</button>

        <span class="sect-label">Tax, Service Charge & Discount</span>
        <div class="hs-form-row" style="margin-bottom:0.5rem">
            <div class="hs-field">
                <label>Tarif PPN</label>
                <select id="eTaxRate" onchange="eOnTaxRateChange()">
                    <option value="0">Tanpa PPN (0%)</option>
                    <option value="5">5%</option>
                    <option value="10">10%</option>
                    <option value="11">11% (Standar)</option>
                    <option value="custom">Custom...</option>
                </select>
            </div>
            <div class="hs-field" id="eCustomTaxWrap" style="display:none">
                <label>Custom PPN (%)</label>
                <input type="number" id="eTaxCustom" value="0" min="0" max="100" step="0.5" oninput="eRefreshTotal()">
            </div>
        </div>
        <div class="hs-form-row" style="margin-bottom:0.5rem">
            <div class="hs-field">
                <label>Service Charge (%)</label>
                <input type="number" id="eServiceCharge" value="0" min="0" max="100" step="0.5" oninput="eRefreshTotal()">
            </div>
            <div class="hs-field">
                <label>Discount (%)</label>
                <input type="number" id="eDiscount" value="0" min="0" max="100" step="0.5" oninput="eRefreshTotal()">
            </div>
        </div>

        <span class="sect-label">Pembayaran / DP</span>
        <div class="hs-form-row">
            <div class="hs-field"><label>Metode Bayar</label>
                <select id="ePayMethod">
                    <option value="cash">Cash</option>
                    <option value="transfer">Transfer</option>
                    <option value="qris">QRIS</option>
                    <option value="card">Card</option>
                </select>
            </div>
            <div class="hs-field"><label>DP / Down Payment (Rp)</label>
                <input type="number" id="ePaid" value="0" min="0" oninput="eRefreshTotal()">
            </div>
        </div>
        <div class="hs-field" style="margin-bottom:0.75rem"><label>Catatan</label><textarea id="eNotes" rows="2"></textarea></div>

        <div class="hs-total-preview" id="eTotalPreview" style="text-align:left;line-height:1.7">
            <div style="font-size:0.82rem;color:#6b7280">Subtotal: <span id="etpSub">Rp 0</span></div>
            <div style="font-size:0.82rem;color:#3b82f6" id="etpScRow" style="display:none">Service Charge: <span id="etpSc">Rp 0</span></div>
            <div style="font-size:0.82rem;color:#ef4444" id="etpDiscRow" style="display:none">Discount: <span id="etpDisc">- Rp 0</span></div>
            <div style="font-size:0.82rem;color:#f59e0b" id="etpTaxRow">PPN: <span id="etpTax">Rp 0</span></div>
            <div style="font-size:1.05rem;font-weight:800;color:#4338ca;border-top:1px solid #dde3ff;padding-top:4px">Grand Total: <span id="etpGrand">Rp 0</span></div>
        </div>

        <div class="hs-modal-footer">
            <button class="btn-hs btn-hs-secondary" onclick="closeEditModal()">Batal</button>
            <button class="btn-hs btn-hs-primary" id="editBtn" onclick="submitEdit()">💾 Simpan Perubahan</button>
        </div>
    </div>
</div>

<script>
    const SVC_KEYS = <?php echo json_encode(array_keys($serviceTypes)); ?>;
    const SVC_LABELS = <?php echo json_encode(array_values(array_map(fn($v) => $v['icon'] . ' ' . $v['label'], $serviceTypes))); ?>;
    // Catalog data grouped by service_type: { motor_rental: [{name,price,unit}, ...], ... }
    const CATALOG_DATA = <?php
                            $catalogByType = [];
                            foreach ($catalogRows as $cr) {
                                $catalogByType[$cr['service_type']][] = [
                                    'name'  => $cr['item_name'],
                                    'price' => (float)$cr['default_price'],
                                    'unit'  => $cr['unit'] ?? 'unit',
                                ];
                            }
                            echo json_encode($catalogByType);
                            ?>;
    const RENTAL_MOTORS = <?php echo json_encode(array_map(fn($m) => ['id' => (int)$m['id'], 'label' => $m['motor_name'] . ' (' . $m['plate_number'] . ')', 'daily_rate' => (float)$m['daily_rate']], $availableMotors)); ?>;
    const RENTAL_CARS = <?php echo json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'label' => $c['car_name'] . ' (' . $c['plate_number'] . ')' . (!empty($c['car_type']) ? ' - ' . $c['car_type'] : ''), 'daily_rate' => (float)$c['daily_rate']], $availableCars)); ?>;

    // ── Guest mode ────────────────────────────────────────────────────────────────
    function setGuestMode(mode) {
        const ih = mode === 'inhouse';
        document.getElementById('inhouseSection').style.display = ih ? '' : 'none';
        document.getElementById('manualSection').style.display = ih ? 'none' : '';
        document.getElementById('btnInhouse').classList.toggle('active', ih);
        document.getElementById('btnManual').classList.toggle('active', !ih);
        if (!ih) {
            document.getElementById('fBookingId').value = '';
        }
    }

    function fillFromInhouse() {
        const sel = document.getElementById('fGuestSelect');
        const opt = sel.options[sel.selectedIndex];
        document.getElementById('fBookingId').value = opt.value || '';
        document.getElementById('fPhone').value = opt.dataset.phone || '';
        document.getElementById('fRoom').value = opt.dataset.room || '';
    }

    function getGuestName() {
        const ih = document.getElementById('inhouseSection').style.display !== 'none';
        if (ih) {
            const sel = document.getElementById('fGuestSelect');
            return sel.options[sel.selectedIndex].dataset.name || '';
        }
        return document.getElementById('fGuestName').value.trim();
    }

    // ── Items ─────────────────────────────────────────────────────────────────────
    let rowCnt = 0;

    function buildSvcOpts(selected) {
        return SVC_KEYS.map((k, i) =>
            `<option value="${k}" ${k===selected?'selected':''}>${SVC_LABELS[i]}</option>`
        ).join('');
    }

    function buildRentalAssetOpts(items, selected) {
        let html = '<option value="">Pilih armada...</option>';
        items.forEach(item => {
            html += `<option value="${item.id}" data-rate="${item.daily_rate}" ${String(item.id)===String(selected||'')?'selected':''}>${item.label}</option>`;
        });
        return html;
    }

    function isRentalService(svc) {
        return svc === 'motor_rental' || svc === 'car_rental';
    }

    function rentalDefaultDate(offsetDays) {
        const dt = new Date();
        dt.setDate(dt.getDate() + offsetDays);
        dt.setMinutes(dt.getMinutes() - dt.getTimezoneOffset());
        return dt.toISOString().slice(0, 16);
    }

    function addItemRow(svc, desc, qty, price) {
        rowCnt++;
        const id = 'r' + rowCnt;
        const tr = document.createElement('tr');
        tr.id = id;
        tr.innerHTML =
            `<td><select class="iSvc" onchange="onSvcChange('${id}')">${buildSvcOpts(svc||'')}</select></td>` +
            `<td>` +
            `<input type="text" class="iDesc" placeholder="e.g. Honda Beat 2 days" value="${(desc||'').replace(/"/g,'&quot;')}">` +
            `<div class="hs-rental-extra">` +
            `<select class="iAsset" onchange="onRentalAssetChange('${id}')"></select>` +
            `<div class="hs-rental-grid">` +
            `<input type="datetime-local" class="iStart" onchange="syncRentalDuration('${id}')">` +
            `<input type="datetime-local" class="iEnd" onchange="syncRentalDuration('${id}')">` +
            `</div>` +
            `<div class="hs-rental-grid">` +
            `<input type="number" class="iDeposit" value="0" min="0" placeholder="Deposit (Rp)">` +
            `<input type="text" class="iDest" placeholder="Tujuan / catatan mobil">` +
            `</div>` +
            `</div>` +
            `</td>` +
            `<td><input type="number" class="iQty" value="${qty||1}" min="0.5" step="0.5" style="width:60px" oninput="rcalc('${id}')"></td>` +
            `<td><input type="number" class="iPrice" value="${price||0}" min="0" style="width:105px" oninput="rcalc('${id}')"></td>` +
            `<td style="font-weight:700;color:#4338ca;text-align:right;white-space:nowrap" class="iTotal">Rp 0</td>` +
            `<td><button type="button" class="btn-del-row" onclick="delRow('${id}')">✕</button></td>`;
        document.getElementById('itemsBody').appendChild(tr);
        // If no price given and catalog has entries for this service type, auto-fill
        if (!price) onSvcChange(id, true);
        else rcalc(id);
    }

    function onSvcChange(id, isNew) {
        const tr = document.getElementById(id);
        if (!tr) return;
        const svc = tr.querySelector('.iSvc').value;
        const priceInput = tr.querySelector('.iPrice');
        const descInput = tr.querySelector('.iDesc');
        const rentalWrap = tr.querySelector('.hs-rental-extra');
        const assetSelect = tr.querySelector('.iAsset');
        const destInput = tr.querySelector('.iDest');
        const items = CATALOG_DATA[svc];
        const rentalItems = svc === 'motor_rental' ? RENTAL_MOTORS : (svc === 'car_rental' ? RENTAL_CARS : []);
        if (isRentalService(svc)) {
            rentalWrap.classList.add('open');
            assetSelect.innerHTML = buildRentalAssetOpts(rentalItems, assetSelect.value);
            destInput.style.display = svc === 'car_rental' ? '' : 'none';
            if (!tr.querySelector('.iStart').value) tr.querySelector('.iStart').value = rentalDefaultDate(0);
            if (!tr.querySelector('.iEnd').value) tr.querySelector('.iEnd').value = rentalDefaultDate(1);
        } else {
            rentalWrap.classList.remove('open');
            assetSelect.innerHTML = '<option value="">Pilih armada...</option>';
            tr.querySelector('.iStart').value = '';
            tr.querySelector('.iEnd').value = '';
            tr.querySelector('.iDeposit').value = 0;
            tr.querySelector('.iDest').value = '';
        }
        if (items && items.length > 0) {
            // On new row: fill only if still empty/zero
            // On manual service-type change: always sync from catalog
            if (isNew) {
                if (parseFloat(priceInput.value) === 0) priceInput.value = items[0].price;
                if (!descInput.value.trim()) descInput.value = items[0].name;
            } else {
                // User switched type → always update price & description from catalog
                priceInput.value = items[0].price;
                descInput.value = items[0].name;
            }
        } else if (!isNew) {
            // Switched to a type with no catalog entry — clear price so user must enter manually
            priceInput.value = 0;
        }
        if (isRentalService(svc)) {
            onRentalAssetChange(id, !!isNew);
            syncRentalDuration(id);
        }
        rcalc(id);
    }

    function onRentalAssetChange(id, keepManualDesc) {
        const tr = document.getElementById(id);
        if (!tr) return;
        const svc = tr.querySelector('.iSvc').value;
        const assetSelect = tr.querySelector('.iAsset');
        const selectedId = assetSelect.value;
        const source = svc === 'motor_rental' ? RENTAL_MOTORS : RENTAL_CARS;
        const chosen = source.find(item => String(item.id) === String(selectedId));
        if (!chosen) return;
        const priceInput = tr.querySelector('.iPrice');
        const descInput = tr.querySelector('.iDesc');
        if (!priceInput.value || parseFloat(priceInput.value) === 0 || !keepManualDesc) {
            priceInput.value = chosen.daily_rate;
        }
        if (!descInput.value.trim() || descInput.dataset.autoFilled === '1' || !keepManualDesc) {
            descInput.value = chosen.label;
            descInput.dataset.autoFilled = '1';
        }
        syncRentalDuration(id);
    }

    function syncRentalDuration(id) {
        const tr = document.getElementById(id);
        if (!tr) return;
        const svc = tr.querySelector('.iSvc').value;
        if (!isRentalService(svc)) return;
        const startVal = tr.querySelector('.iStart').value;
        const endVal = tr.querySelector('.iEnd').value;
        if (!startVal || !endVal) return;
        const start = new Date(startVal);
        const end = new Date(endVal);
        const diffHours = (end - start) / (1000 * 60 * 60);
        if (Number.isFinite(diffHours) && diffHours > 0) {
            tr.querySelector('.iQty').value = Math.max(1, Math.ceil(diffHours / 24));
            rcalc(id);
        }
    }

    function delRow(id) {
        const el = document.getElementById(id);
        if (el) el.remove();
        refreshTotal();
    }

    function rcalc(id) {
        const tr = document.getElementById(id);
        if (!tr) return;
        const t = (parseFloat(tr.querySelector('.iQty').value) || 0) * (parseFloat(tr.querySelector('.iPrice').value) || 0);
        tr.querySelector('.iTotal').textContent = 'Rp ' + Math.round(t).toLocaleString('id-ID');
        refreshTotal();
    }

    function subtotal() {
        let t = 0;
        document.querySelectorAll('#itemsBody tr').forEach(tr => {
            t += (parseFloat(tr.querySelector('.iQty')?.value) || 0) * (parseFloat(tr.querySelector('.iPrice')?.value) || 0);
        });
        return t;
    }

    function getTaxRate() {
        const sel = document.getElementById('fTaxRate');
        if (!sel) return 0;
        if (sel.value === 'custom') return parseFloat(document.getElementById('fTaxCustom')?.value) || 0;
        return parseFloat(sel.value) || 0;
    }

    function grandTotal() {
        const sub = subtotal();
        const scRate = parseFloat(document.getElementById('fServiceCharge')?.value) || 0;
        const discRate = parseFloat(document.getElementById('fDiscount')?.value) || 0;
        const sc = sub * (scRate / 100);
        const disc = sub * (discRate / 100);
        const afterCD = sub + sc - disc;
        const rate = getTaxRate();
        return afterCD + afterCD * (rate / 100);
    }

    function onTaxRateChange() {
        const sel = document.getElementById('fTaxRate');
        document.getElementById('customTaxWrap').style.display = sel.value === 'custom' ? '' : 'none';
        refreshTotal();
    }

    function refreshTotal() {
        const sub = subtotal();
        const rate = getTaxRate();
        const scRate = parseFloat(document.getElementById('fServiceCharge')?.value) || 0;
        const discRate = parseFloat(document.getElementById('fDiscount')?.value) || 0;
        const sc = sub * (scRate / 100);
        const disc = sub * (discRate / 100);
        const afterCD = sub + sc - disc;
        const tax = afterCD * (rate / 100);
        const tot = afterCD + tax;
        const dp = parseFloat(document.getElementById('fPaid')?.value) || 0;
        const sisa = Math.max(0, tot - dp);
        const fmt = v => 'Rp ' + Math.round(v).toLocaleString('id-ID');

        document.getElementById('tpSubtotal').textContent = fmt(sub);
        document.getElementById('tpGrand').textContent = fmt(tot);

        const scRow = document.getElementById('tpScRow');
        scRow.style.display = scRate > 0 ? '' : 'none';
        document.getElementById('tpSc').textContent = `${fmt(sc)} (${scRate}%)`;

        const discRow = document.getElementById('tpDiscRow');
        discRow.style.display = discRate > 0 ? '' : 'none';
        document.getElementById('tpDisc').textContent = `- ${fmt(disc)} (${discRate}%)`;

        const taxRow = document.getElementById('tpTaxRow');
        taxRow.style.display = rate > 0 ? '' : 'none';
        document.getElementById('tpTax').textContent = `${fmt(tax)} (${rate}%)`;

        const dpEl = document.getElementById('fPaid');
        const hasDp = dpEl && parseFloat(dpEl.value) > 0;
        document.getElementById('tpDpRow').style.display = hasDp ? '' : 'none';
        document.getElementById('tpSisaRow').style.display = (hasDp && sisa > 0) ? '' : 'none';
        document.getElementById('tpDp').textContent = fmt(dp);
        document.getElementById('tpSisa').textContent = fmt(sisa);

        enforceMaxPaid();
        if (document.getElementById('fFullPay').checked)
            document.getElementById('fPaid').value = Math.round(tot);
    }

    function enforceMaxPaid() {
        const mx = grandTotal(),
            inp = document.getElementById('fPaid');
        if (parseFloat(inp.value) > mx) inp.value = Math.round(mx);
    }

    function toggleFullPay(checked) {
        document.getElementById('fPaid').value = checked ? Math.round(grandTotal()) : 0;
        refreshTotal();
    }

    // ── Open/Close ────────────────────────────────────────────────────────────────
    function openCreateModal() {
        document.getElementById('createModal').classList.add('open');
        ['fGuestName', 'fPhone', 'fRoom', 'fNotes'].forEach(id => {
            const e = document.getElementById(id);
            if (e) e.value = '';
        });
        document.getElementById('fGuestSelect').value = '';
        document.getElementById('fBookingId').value = '';
        document.getElementById('fPaid').value = 0;
        document.getElementById('fFullPay').checked = false;
        document.getElementById('fTaxRate').value = '0';
        document.getElementById('customTaxWrap').style.display = 'none';
        if (document.getElementById('fTaxCustom')) document.getElementById('fTaxCustom').value = 0;
        document.getElementById('fServiceCharge').value = 0;
        document.getElementById('fDiscount').value = 0;
        document.getElementById('itemsBody').innerHTML = '';
        rowCnt = 0;
        setGuestMode('inhouse');
        addItemRow();
        refreshTotal();
    }

    function closeCreateModal() {
        document.getElementById('createModal').classList.remove('open');
    }

    // ── Submit create ─────────────────────────────────────────────────────────────
    function submitCreate() {
        const guestName = getGuestName();
        if (!guestName) {
            alert('Please select or enter a guest name');
            return;
        }

        const rows = document.querySelectorAll('#itemsBody tr');
        if (!rows.length) {
            alert('Add at least one service item');
            return;
        }

        const items = [];
        for (const tr of rows) {
            const svc = tr.querySelector('.iSvc').value;
            if (!svc) {
                alert('Select service type for all rows');
                return;
            }
            const motorId = svc === 'motor_rental' ? parseInt(tr.querySelector('.iAsset').value || '0', 10) : 0;
            const carId = svc === 'car_rental' ? parseInt(tr.querySelector('.iAsset').value || '0', 10) : 0;
            const startDt = tr.querySelector('.iStart').value || '';
            const endDt = tr.querySelector('.iEnd').value || '';
            if ((svc === 'motor_rental' || svc === 'car_rental') && (!startDt || !endDt || (!motorId && !carId))) {
                alert('Item rental motor/mobil wajib pilih armada dan periode sewa');
                return;
            }
            items.push({
                service_type: svc,
                description: tr.querySelector('.iDesc').value.trim(),
                qty: parseFloat(tr.querySelector('.iQty').value) || 1,
                unit_price: parseFloat(tr.querySelector('.iPrice').value) || 0,
                motor_id: motorId || null,
                car_id: carId || null,
                start_dt: startDt || null,
                end_dt: endDt || null,
                deposit: parseFloat(tr.querySelector('.iDeposit').value) || 0,
                trip_destination: tr.querySelector('.iDest').value.trim() || null
            });
        }

        const btn = document.getElementById('createBtn');
        btn.disabled = true;
        btn.textContent = 'Creating...';

        const fd = new FormData();
        fd.append('action', 'create');
        fd.append('guest_name', guestName);
        fd.append('guest_phone', document.getElementById('fPhone').value.trim());
        fd.append('room_number', document.getElementById('fRoom').value.trim());
        fd.append('booking_id', document.getElementById('fBookingId').value || '');
        fd.append('items', JSON.stringify(items));
        fd.append('payment_method', document.getElementById('fPayMethod').value);
        fd.append('paid_amount', document.getElementById('fPaid').value || 0);
        fd.append('tax_rate', getTaxRate());
        fd.append('service_charge_rate', document.getElementById('fServiceCharge').value || 0);
        fd.append('discount_rate', document.getElementById('fDiscount').value || 0);
        fd.append('notes', document.getElementById('fNotes').value.trim());

        fetch('hotel-services.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    closeCreateModal();
                    const cbMsg = res.cashbook ? '\n✅ Tercatat di Buku Kas' : '';
                    alert('Invoice ' + res.invoice_number + ' created!' + cbMsg);
                    location.reload();
                } else {
                    alert('Error: ' + (res.message || 'Unknown'));
                    btn.disabled = false;
                    btn.textContent = '✅ Create Invoice';
                }
            })
            .catch(() => {
                alert('Network error');
                btn.disabled = false;
                btn.textContent = '✅ Create Invoice';
            });
    }

    // ── Status ────────────────────────────────────────────────────────────────────
    function updateStatus(id, status) {
        const fd = new FormData();
        fd.append('action', 'update_status');
        fd.append('id', id);
        fd.append('status', status);
        fetch('hotel-services.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            })
            .then(r => r.json()).then(res => {
                if (!res.success) alert('Failed to update status');
            });
    }

    // ── Delete ────────────────────────────────────────────────────────────────────
    function deleteInvoice(id, code) {
        if (!confirm('Delete invoice ' + code + '? Cannot be undone.')) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        fetch('hotel-services.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            })
            .then(r => r.json()).then(res => {
                if (res.success) location.reload();
                else alert('Delete failed');
            });
    }

    // ── Pay modal ─────────────────────────────────────────────────────────────────
    function openPayModal(id, remaining, invNo) {
        document.getElementById('pInvId').value = id;
        document.getElementById('pInvNo').textContent = 'Invoice: ' + invNo;
        document.getElementById('pRemaining').textContent = 'Rp ' + Math.round(remaining).toLocaleString('id-ID');
        document.getElementById('pAmount').value = Math.round(remaining);
        document.getElementById('payModal').classList.add('open');
    }

    function closePayModal() {
        document.getElementById('payModal').classList.remove('open');
    }

    function submitPay() {
        const id = document.getElementById('pInvId').value;
        const amount = document.getElementById('pAmount').value;
        if (!amount || parseFloat(amount) <= 0) {
            alert('Enter valid amount');
            return;
        }
        const btn = document.getElementById('payBtn');
        btn.disabled = true;
        btn.textContent = 'Saving...';
        const fd = new FormData();
        fd.append('action', 'add_payment');
        fd.append('id', id);
        fd.append('amount', amount);
        fd.append('method', document.getElementById('pMethod').value);
        fetch('hotel-services.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    closePayModal();
                    alert('Payment saved! ' + (res.cashbook ? '✅ Tercatat di Buku Kas' : '⚠️ Gagal sync ke Buku Kas'));
                    location.reload();
                } else {
                    alert('Error: ' + (res.message || 'Unknown'));
                    btn.disabled = false;
                    btn.textContent = '💾 Save & Sync to Cashbook';
                }
            });
    }

    // ── SETTINGS ─────────────────────────────────────────────────────────────────
    function openSettingsModal() {
        document.getElementById('settingsModal').classList.add('open');
        switchTab('inv');
    }

    function closeSettingsModal() {
        document.getElementById('settingsModal').classList.remove('open');
    }

    function switchTab(t) {
        ['inv', 'catalog', 'svctype'].forEach(id => {
            document.getElementById('tab-' + id).classList.toggle('active', id === t);
            document.getElementById('pane-' + id).classList.toggle('active', id === t);
        });
    }

    function previewLogo(inp) {
        const prev = document.getElementById('logoPreview');
        if (inp.files && inp.files[0]) {
            const reader = new FileReader();
            reader.onload = e => {
                prev.src = e.target.result;
                prev.style.display = 'block';
            };
            reader.readAsDataURL(inp.files[0]);
        }
    }

    function saveSettings() {
        const btn = document.getElementById('btnSaveSettings');
        btn.disabled = true;
        btn.textContent = 'Saving...';
        const fd = new FormData();
        fd.append('action', 'save_hs_settings');
        fd.append('company_name', document.getElementById('sCmpName').value.trim());
        fd.append('company_website', document.getElementById('sCmpWeb').value.trim());
        fd.append('company_phone', document.getElementById('sCmpPhone').value.trim());
        fd.append('company_email', document.getElementById('sCmpEmail').value.trim());
        fd.append('company_address', document.getElementById('sCmpAddr').value.trim());
        fd.append('payment_info_bank', document.getElementById('sPayBank').value.trim());
        fd.append('payment_info_account', document.getElementById('sPayAccount').value.trim());
        fd.append('payment_info_name', document.getElementById('sPayName').value.trim());
        fd.append('payment_info_note', document.getElementById('sPayNote').value.trim());
        const logoFile = document.getElementById('sLogoFile').files[0];
        if (logoFile) fd.append('logo_file', logoFile);
        fetch('hotel-services.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    alert('✅ Settings saved!');
                    closeSettingsModal();
                    location.reload();
                } else {
                    alert('Error: ' + (res.message || 'unknown'));
                }
                btn.disabled = false;
                btn.textContent = '💾 Save Settings';
            }).catch(() => {
                alert('Network error');
                btn.disabled = false;
                btn.textContent = '💾 Save Settings';
            });
    }

    // ── CATALOG ───────────────────────────────────────────────────────────────────
    let catRowCnt = 0;
    const SVC_OPTIONS = <?php echo json_encode(array_map(fn($k, $v) => ['val' => $k, 'lbl' => $v['icon'] . ' ' . $v['label']], array_keys($serviceTypes), $serviceTypes)); ?>;

    function buildSvcOptsFor(selected = '') {
        return SVC_OPTIONS.map(o => `<option value="${o.val}" ${o.val===selected?'selected':''}>${o.lbl}</option>`).join('');
    }

    function addCatalogRow() {
        catRowCnt++;
        const id = 'new_' + catRowCnt;
        const tr = document.createElement('tr');
        tr.id = 'ctr' + id;
        tr.innerHTML =
            `<td><select class="cSType">${buildSvcOptsFor()}</select></td>` +
            `<td><input type="text" class="cName" placeholder="ex: Honda Beat 1 Hari"></td>` +
            `<td><input type="number" class="cPrice" value="0" min="0"></td>` +
            `<td><input type="text" class="cUnit" value="unit"></td>` +
            `<td><input type="number" class="cSort" value="0" style="width:45px"></td>` +
            `<td style="display:flex;gap:3px">` +
            `<button class="btn-cat-save" onclick="saveCatalogRow('${id}')">💾</button>` +
            `<button class="btn-cat-del" onclick="document.getElementById('ctr${id}').remove()">✕</button>` +
            `</td>`;
        document.getElementById('catalogBody').prepend(tr);
    }

    function saveCatalogRow(cid) {
        const tr = document.getElementById('ctr' + cid);
        if (!tr) return;
        const fd = new FormData();
        fd.append('action', 'save_catalog_item');
        fd.append('cid', isNaN(cid) ? 0 : cid);
        fd.append('service_type', tr.querySelector('.cSType').value);
        fd.append('item_name', tr.querySelector('.cName').value.trim());
        fd.append('default_price', tr.querySelector('.cPrice').value);
        fd.append('unit', tr.querySelector('.cUnit').value.trim() || 'unit');
        fd.append('sort_order', tr.querySelector('.cSort').value);
        fetch('hotel-services.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    tr.id = 'ctr' + res.id;
                    tr.querySelectorAll('button')[0].setAttribute('onclick', 'saveCatalogRow(' + res.id + ')');
                    tr.querySelectorAll('button')[1].setAttribute('onclick', 'deleteCatalogRow(' + res.id + ')');
                    tr.style.background = '#f0fdf4';
                    setTimeout(() => tr.style.background = '', 1500);
                } else {
                    alert('Error: ' + (res.message || 'failed'));
                }
            });
    }

    function deleteCatalogRow(cid) {
        if (!confirm('Hapus item ini dari katalog?')) return;
        const fd = new FormData();
        fd.append('action', 'delete_catalog_item');
        fd.append('cid', cid);
        fetch('hotel-services.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    const el = document.getElementById('ctr' + cid);
                    if (el) el.remove();
                } else alert('Error');
            });
    }

    const CATALOG = <?php echo json_encode(array_map(fn($r) => ['stype' => $r['service_type'], 'name' => $r['item_name'], 'price' => (float)$r['default_price'], 'unit' => $r['unit']], $catalogRows)); ?>;

    // ── EDIT INVOICE ──────────────────────────────────────────────────────────────
    let eRowCnt = 0;

    function openEditModal(id) {
        fetch('hotel-services.php?get_invoice=1&id=' + id, {
                credentials: 'include'
            })
            .then(r => r.json())
            .then(inv => {
                if (!inv.success) {
                    alert(inv.message || 'Cannot load invoice');
                    return;
                }
                document.getElementById('eInvId').value = inv.id;
                document.getElementById('eInvNo').textContent = 'Invoice: ' + inv.invoice_number;
                document.getElementById('eGuestName').value = inv.guest_name || '';
                document.getElementById('ePhone').value = inv.guest_phone || '';
                document.getElementById('eRoom').value = inv.room_number || '';
                document.getElementById('ePayMethod').value = inv.payment_method || 'cash';
                document.getElementById('ePaid').value = inv.paid_amount || 0;
                document.getElementById('eNotes').value = inv.notes || '';
                const tr2 = parseFloat(inv.tax_rate) || 0;
                const taxSel = document.getElementById('eTaxRate');
                if (['0', '5', '10', '11'].includes(String(tr2))) {
                    taxSel.value = String(tr2);
                    document.getElementById('eCustomTaxWrap').style.display = 'none';
                } else {
                    taxSel.value = 'custom';
                    document.getElementById('eCustomTaxWrap').style.display = '';
                    document.getElementById('eTaxCustom').value = tr2;
                }
                document.getElementById('eServiceCharge').value = parseFloat(inv.service_charge_rate) || 0;
                document.getElementById('eDiscount').value = parseFloat(inv.discount_rate) || 0;
                document.getElementById('eItemsBody').innerHTML = '';
                eRowCnt = 0;
                (inv.items || []).forEach(it => eAddItemRow(it));
                eRefreshTotal();
                document.getElementById('editModal').classList.add('open');
            })
            .catch(() => alert('Network error loading invoice'));
    }

    function closeEditModal() {
        document.getElementById('editModal').classList.remove('open');
    }

    function eAddItemRow(itemOrSvc, desc, qty, price) {
        const item = typeof itemOrSvc === 'object' && itemOrSvc !== null ? itemOrSvc : {
            service_type: itemOrSvc,
            description: desc,
            quantity: qty,
            unit_price: price,
            motor_id: null,
            car_id: null,
            start_dt: null,
            end_dt: null,
            deposit: 0,
            trip_destination: null
        };
        eRowCnt++;
        const id2 = 'er' + eRowCnt;
        const tr3 = document.createElement('tr');
        tr3.id = id2;
        tr3.innerHTML =
            `<td><select class="iSvc" onchange="eOnSvcChange('${id2}')">${buildSvcOpts(item.service_type||'')}</select></td>` +
            `<td>` +
            `<input type="text" class="iDesc" value="${(item.description||'').replace(/"/g,'&quot;')}" placeholder="Description">` +
            `<div class="hs-rental-extra">` +
            `<select class="iAsset" onchange="eOnRentalAssetChange('${id2}')"></select>` +
            `<div class="hs-rental-grid">` +
            `<input type="datetime-local" class="iStart" value="${item.start_dt ? String(item.start_dt).replace(' ', 'T').slice(0,16) : ''}" onchange="eSyncRentalDuration('${id2}')">` +
            `<input type="datetime-local" class="iEnd" value="${item.end_dt ? String(item.end_dt).replace(' ', 'T').slice(0,16) : ''}" onchange="eSyncRentalDuration('${id2}')">` +
            `</div>` +
            `<div class="hs-rental-grid">` +
            `<input type="number" class="iDeposit" value="${item.deposit||0}" min="0" placeholder="Deposit (Rp)">` +
            `<input type="text" class="iDest" value="${(item.trip_destination||'').replace(/"/g,'&quot;')}" placeholder="Tujuan / catatan mobil">` +
            `</div>` +
            `</div>` +
            `</td>` +
            `<td><input type="number" class="iQty" value="${item.quantity||1}" min="0.5" step="0.5" style="width:60px" oninput="ercalc('${id2}')"></td>` +
            `<td><input type="number" class="iPrice" value="${item.unit_price||0}" min="0" style="width:105px" oninput="ercalc('${id2}')"></td>` +
            `<td style="font-weight:700;color:#4338ca;text-align:right;white-space:nowrap" class="iTotal">Rp 0</td>` +
            `<td><button type="button" class="btn-del-row" onclick="eDelRow('${id2}')">✕</button></td>`;
        document.getElementById('eItemsBody').appendChild(tr3);
        if (item.service_type === 'motor_rental') {
            tr3.querySelector('.iAsset').innerHTML = buildRentalAssetOpts(RENTAL_MOTORS, item.motor_id);
        }
        if (item.service_type === 'car_rental') {
            tr3.querySelector('.iAsset').innerHTML = buildRentalAssetOpts(RENTAL_CARS, item.car_id);
        }
        eOnSvcChange(id2, !(item.unit_price > 0));
        ercalc(id2);
    }

    function eOnSvcChange(id2, isNew) {
        const tr3 = document.getElementById(id2);
        if (!tr3) return;
        const svc = tr3.querySelector('.iSvc').value;
        const priceInput = tr3.querySelector('.iPrice');
        const descInput = tr3.querySelector('.iDesc');
        const rentalWrap = tr3.querySelector('.hs-rental-extra');
        const assetSelect = tr3.querySelector('.iAsset');
        const destInput = tr3.querySelector('.iDest');
        const items = CATALOG_DATA[svc];
        const rentalItems = svc === 'motor_rental' ? RENTAL_MOTORS : (svc === 'car_rental' ? RENTAL_CARS : []);
        if (isRentalService(svc)) {
            rentalWrap.classList.add('open');
            assetSelect.innerHTML = buildRentalAssetOpts(rentalItems, assetSelect.value);
            destInput.style.display = svc === 'car_rental' ? '' : 'none';
        } else {
            rentalWrap.classList.remove('open');
            assetSelect.innerHTML = '<option value="">Pilih armada...</option>';
            tr3.querySelector('.iStart').value = '';
            tr3.querySelector('.iEnd').value = '';
            tr3.querySelector('.iDeposit').value = 0;
            tr3.querySelector('.iDest').value = '';
        }
        if (items && items.length > 0) {
            if (isNew) {
                if (parseFloat(priceInput.value) === 0) priceInput.value = items[0].price;
                if (!descInput.value.trim()) descInput.value = items[0].name;
            } else {
                priceInput.value = items[0].price;
                descInput.value = items[0].name;
            }
        } else if (!isNew) {
            priceInput.value = 0;
        }
        if (isRentalService(svc)) {
            eOnRentalAssetChange(id2, !!isNew);
            eSyncRentalDuration(id2);
        }
        ercalc(id2);
    }

    function eOnRentalAssetChange(id2, keepManualDesc) {
        const tr3 = document.getElementById(id2);
        if (!tr3) return;
        const svc = tr3.querySelector('.iSvc').value;
        const assetSelect = tr3.querySelector('.iAsset');
        const selectedId = assetSelect.value;
        const source = svc === 'motor_rental' ? RENTAL_MOTORS : RENTAL_CARS;
        const chosen = source.find(item => String(item.id) === String(selectedId));
        if (!chosen) return;
        const priceInput = tr3.querySelector('.iPrice');
        const descInput = tr3.querySelector('.iDesc');
        if (!priceInput.value || parseFloat(priceInput.value) === 0 || !keepManualDesc) {
            priceInput.value = chosen.daily_rate;
        }
        if (!descInput.value.trim() || descInput.dataset.autoFilled === '1' || !keepManualDesc) {
            descInput.value = chosen.label;
            descInput.dataset.autoFilled = '1';
        }
        eSyncRentalDuration(id2);
    }

    function eSyncRentalDuration(id2) {
        const tr3 = document.getElementById(id2);
        if (!tr3) return;
        const svc = tr3.querySelector('.iSvc').value;
        if (!isRentalService(svc)) return;
        const startVal = tr3.querySelector('.iStart').value;
        const endVal = tr3.querySelector('.iEnd').value;
        if (!startVal || !endVal) return;
        const start = new Date(startVal);
        const end = new Date(endVal);
        const diffHours = (end - start) / (1000 * 60 * 60);
        if (Number.isFinite(diffHours) && diffHours > 0) {
            tr3.querySelector('.iQty').value = Math.max(1, Math.ceil(diffHours / 24));
            ercalc(id2);
        }
    }

    function eDelRow(id) {
        const el = document.getElementById(id);
        if (el) el.remove();
        eRefreshTotal();
    }

    function ercalc(id) {
        const tr = document.getElementById(id);
        if (!tr) return;
        const t = (parseFloat(tr.querySelector('.iQty').value) || 0) * (parseFloat(tr.querySelector('.iPrice').value) || 0);
        tr.querySelector('.iTotal').textContent = 'Rp ' + Math.round(t).toLocaleString('id-ID');
        eRefreshTotal();
    }

    function eOnTaxRateChange() {
        const sel = document.getElementById('eTaxRate');
        document.getElementById('eCustomTaxWrap').style.display = sel.value === 'custom' ? '' : 'none';
        eRefreshTotal();
    }

    function eRefreshTotal() {
        let s = 0;
        document.querySelectorAll('#eItemsBody tr').forEach(tr => {
            s += (parseFloat(tr.querySelector('.iQty')?.value) || 0) * (parseFloat(tr.querySelector('.iPrice')?.value) || 0);
        });
        const sel = document.getElementById('eTaxRate');
        let r = sel ? ((sel.value === 'custom' ? (parseFloat(document.getElementById('eTaxCustom')?.value) || 0) : (parseFloat(sel.value) || 0))) : 0;
        const scRate = parseFloat(document.getElementById('eServiceCharge')?.value) || 0;
        const discRate = parseFloat(document.getElementById('eDiscount')?.value) || 0;
        const sc = s * (scRate / 100),
            disc = s * (discRate / 100);
        const afterCD = s + sc - disc;
        const tax = afterCD * (r / 100),
            tot = afterCD + tax;
        const fmt = v => 'Rp ' + Math.round(v).toLocaleString('id-ID');
        document.getElementById('etpSub').textContent = fmt(s);
        document.getElementById('etpGrand').textContent = fmt(tot);
        document.getElementById('etpScRow').style.display = scRate > 0 ? '' : 'none';
        document.getElementById('etpSc').textContent = fmt(sc) + ' (' + scRate + '%)';
        document.getElementById('etpDiscRow').style.display = discRate > 0 ? '' : 'none';
        document.getElementById('etpDisc').textContent = '- ' + fmt(disc) + ' (' + discRate + '%)';
        document.getElementById('etpTaxRow').style.display = r > 0 ? '' : 'none';
        document.getElementById('etpTax').textContent = fmt(tax) + ' (' + r + '%)';
    }

    function submitEdit() {
        const id = document.getElementById('eInvId').value;
        const guestName = document.getElementById('eGuestName').value.trim();
        if (!guestName) {
            alert('Nama tamu wajib diisi');
            return;
        }
        const rows = document.querySelectorAll('#eItemsBody tr');
        if (!rows.length) {
            alert('Minimal 1 item layanan');
            return;
        }
        const items = [];
        for (const tr of rows) {
            const svc = tr.querySelector('.iSvc').value;
            const motorId = svc === 'motor_rental' ? parseInt(tr.querySelector('.iAsset').value || '0', 10) : 0;
            const carId = svc === 'car_rental' ? parseInt(tr.querySelector('.iAsset').value || '0', 10) : 0;
            const startDt = tr.querySelector('.iStart').value || '';
            const endDt = tr.querySelector('.iEnd').value || '';
            if ((svc === 'motor_rental' || svc === 'car_rental') && (!startDt || !endDt || (!motorId && !carId))) {
                alert('Item rental motor/mobil wajib pilih armada dan periode sewa');
                return;
            }
            items.push({
                service_type: svc,
                description: tr.querySelector('.iDesc').value.trim(),
                qty: parseFloat(tr.querySelector('.iQty').value) || 1,
                unit_price: parseFloat(tr.querySelector('.iPrice').value) || 0,
                motor_id: motorId || null,
                car_id: carId || null,
                start_dt: startDt || null,
                end_dt: endDt || null,
                deposit: parseFloat(tr.querySelector('.iDeposit').value) || 0,
                trip_destination: tr.querySelector('.iDest').value.trim() || null
            });
        }
        const sel = document.getElementById('eTaxRate');
        const taxR = sel.value === 'custom' ? (parseFloat(document.getElementById('eTaxCustom')?.value) || 0) : (parseFloat(sel.value) || 0);
        const btn = document.getElementById('editBtn');
        btn.disabled = true;
        btn.textContent = 'Menyimpan...';
        const fd = new FormData();
        fd.append('action', 'update_invoice');
        fd.append('id', id);
        fd.append('guest_name', guestName);
        fd.append('guest_phone', document.getElementById('ePhone').value.trim());
        fd.append('room_number', document.getElementById('eRoom').value.trim());
        fd.append('payment_method', document.getElementById('ePayMethod').value);
        fd.append('paid_amount', document.getElementById('ePaid').value || 0);
        fd.append('tax_rate', taxR);
        fd.append('service_charge_rate', document.getElementById('eServiceCharge').value || 0);
        fd.append('discount_rate', document.getElementById('eDiscount').value || 0);
        fd.append('notes', document.getElementById('eNotes').value.trim());
        fd.append('items', JSON.stringify(items));
        fetch('hotel-services.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    closeEditModal();
                    location.reload();
                } else {
                    alert('Error: ' + (res.message || 'Unknown'));
                    btn.disabled = false;
                    btn.textContent = '💾 Simpan Perubahan';
                }
            }).catch(() => {
                alert('Network error');
                btn.disabled = false;
                btn.textContent = '💾 Simpan Perubahan';
            });
    }

    // ── SERVICE TYPE MANAGEMENT ──────────────────────────────────────────────────
    let stRowCnt = 0;

    function addSvcTypeRow() {
        stRowCnt++;
        const id = 'new_' + stRowCnt;
        const tr = document.createElement('tr');
        tr.id = 'str' + id;
        tr.innerHTML =
            `<td><input type="text" class="stIcon" value="🔹" style="width:40px;text-align:center"></td>` +
            `<td><input type="text" class="stKey" placeholder="e.g. spa_treatment"></td>` +
            `<td><input type="text" class="stLabel" placeholder="e.g. Spa Treatment"></td>` +
            `<td><input type="number" class="stSort" value="0" style="width:45px"></td>` +
            `<td style="display:flex;gap:3px">` +
            `<button class="btn-cat-save" onclick="saveSvcType('${id}')">💾</button>` +
            `<button class="btn-cat-del" onclick="document.getElementById('str${id}').remove()">✕</button>` +
            `</td>`;
        document.getElementById('svcTypeBody').prepend(tr);
    }

    function saveSvcType(stId) {
        const tr = document.getElementById('str' + stId);
        if (!tr) return;
        const fd = new FormData();
        fd.append('action', 'save_service_type');
        fd.append('st_id', isNaN(stId) ? 0 : stId);
        fd.append('type_icon', tr.querySelector('.stIcon').value.trim() || '🔹');
        fd.append('type_key', tr.querySelector('.stKey').value.trim());
        fd.append('type_label', tr.querySelector('.stLabel').value.trim());
        fd.append('sort_order', tr.querySelector('.stSort').value || 0);
        fetch('hotel-services.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    tr.id = 'str' + res.id;
                    tr.querySelectorAll('button')[0].setAttribute('onclick', 'saveSvcType(' + res.id + ')');
                    tr.querySelectorAll('button')[1].setAttribute('onclick', 'deleteSvcType(' + res.id + ')');
                    tr.style.background = '#f0fdf4';
                    setTimeout(() => tr.style.background = '', 1500);
                    alert('✅ Tipe layanan tersimpan! Refresh halaman untuk melihat perubahan di dropdown.');
                } else {
                    alert('Error: ' + (res.message || 'failed'));
                }
            });
    }

    function deleteSvcType(stId) {
        if (!confirm('Hapus tipe layanan ini?')) return;
        const fd = new FormData();
        fd.append('action', 'delete_service_type');
        fd.append('st_id', stId);
        fetch('hotel-services.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    const el = document.getElementById('str' + stId);
                    if (el) el.remove();
                } else alert('Error: ' + (res.message || 'Cannot delete'));
            });
    }
</script>

<?php include '../../includes/footer.php'; ?>