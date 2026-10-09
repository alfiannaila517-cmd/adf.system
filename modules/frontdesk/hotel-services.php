<?php

/**
 * Hotel Services — Multi-item Invoice
 * Motor Rental, Laundry, Service, Airport Drop, Harbor Drop
 * Narayana Hotel Karimunjawa
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/CloudinaryHelper.php';
require_once '../../includes/InvoiceHelper.php';
require_once '../../includes/DriverPaymentHelper.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db          = Database::getInstance();
$pdo         = $db->getConnection();
$currentUser = $auth->getCurrentUser();
$businessId  = (int)($_SESSION['business_id'] ?? 1);
if (isset($_GET['business_id']) && is_numeric($_GET['business_id'])) {
    $reqBizId = (int)$_GET['business_id'];
    if ($reqBizId > 0) {
        $businessId = $reqBizId;
    }
}

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
    last_service_at DATETIME DEFAULT NULL,
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

// Track latest service-add timestamp for correct invoice date semantics.
try {
    $pdo->query("SELECT last_service_at FROM hotel_invoices LIMIT 1");
} catch (\Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE hotel_invoices ADD COLUMN last_service_at DATETIME DEFAULT NULL AFTER discount_amount");
        $pdo->exec("UPDATE hotel_invoices SET last_service_at = created_at WHERE last_service_at IS NULL");
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

// Driver/partner vehicle payment tracking (rental_cars commission mode, rental_car_bookings
// driver-payment flag, monthly_bills traceability columns)
ensureDriverPaymentSchema($pdo);

// Add driver_rate column to hotel_service_catalog (bayar ke driver per layanan)
try {
    $pdo->query("SELECT driver_rate FROM hotel_service_catalog LIMIT 1");
} catch (\Exception $e) {
    try {
        $pdo->exec("ALTER TABLE hotel_service_catalog ADD COLUMN driver_rate DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'harga bayar ke driver per layanan' AFTER default_price");
    } catch (\Exception $e2) {
        error_log('catalog driver_rate migration: ' . $e2->getMessage());
    }
}

// Narayana Trip guide master
$pdo->exec("CREATE TABLE IF NOT EXISTS narayana_trip_guides (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    business_id INT NOT NULL DEFAULT 1,
    guide_name  VARCHAR(120) NOT NULL,
    phone       VARCHAR(40) DEFAULT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_biz_guide (business_id, guide_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

try {
    $pdo->query("SELECT trip_type FROM hotel_invoice_items LIMIT 1");
} catch (\Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE hotel_invoice_items ADD COLUMN trip_type VARCHAR(20) DEFAULT NULL AFTER service_type");
    } catch (\Throwable $e2) {
        error_log('hotel_invoice_items trip_type migration: ' . $e2->getMessage());
    }
}

try {
    $pdo->query("SELECT guide_id FROM hotel_invoice_items LIMIT 1");
} catch (\Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE hotel_invoice_items ADD COLUMN guide_id INT DEFAULT NULL AFTER trip_type");
    } catch (\Throwable $e2) {
        error_log('hotel_invoice_items guide_id migration: ' . $e2->getMessage());
    }
}

try {
    $pdo->query("SELECT guide_name FROM hotel_invoice_items LIMIT 1");
} catch (\Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE hotel_invoice_items ADD COLUMN guide_name VARCHAR(120) DEFAULT NULL AFTER guide_id");
    } catch (\Throwable $e2) {
        error_log('hotel_invoice_items guide_name migration: ' . $e2->getMessage());
    }
}

try {
    $pdo->query("SELECT created_at FROM hotel_invoice_items LIMIT 1");
} catch (\Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE hotel_invoice_items ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    } catch (\Throwable $e2) {
        error_log('hotel_invoice_items created_at migration: ' . $e2->getMessage());
    }
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

// ── Split-tender payment breakdown (e.g. sebagian cash, sebagian kartu, dalam 1 invoice) ──
$pdo->exec("CREATE TABLE IF NOT EXISTS hotel_invoice_payments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id  INT NOT NULL,
    business_id INT NOT NULL,
    amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
    method      VARCHAR(20) NOT NULL DEFAULT 'cash',
    created_by  INT DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

// ── Kategori layanan & mitra ─────────────────────────────────────────────────────
// Kategori = kelompok utama di form input (Mobil → Harbor Drop / Rental Mobil / City Tour, dst.).
$hsCategories = [
    'mobil' => ['label' => 'Mobil', 'icon' => '🚗'],
    'motor' => ['label' => 'Motor', 'icon' => '🛵'],
    'trip'  => ['label' => 'Trip',  'icon' => '🚤'],
    'hotel' => ['label' => 'Hotel', 'icon' => '🏨'],
];
$hsDefaultCategory = function (string $typeKey): string {
    if (in_array($typeKey, ['airport_drop', 'harbor_drop', 'car_rental', 'city_tour'], true)) return 'mobil';
    if ($typeKey === 'motor_rental') return 'motor';
    if ($typeKey === 'narayana_trip') return 'trip';
    return 'hotel';
};
try {
    $pdo->query("SELECT category FROM hotel_service_types LIMIT 1");
} catch (\Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE hotel_service_types ADD COLUMN category VARCHAR(20) DEFAULT NULL AFTER type_icon");
    } catch (\Throwable $e2) {
        error_log('hotel_service_types category migration: ' . $e2->getMessage());
    }
}
try {
    $catFill = $pdo->prepare("SELECT id, type_key FROM hotel_service_types WHERE business_id=? AND (category IS NULL OR category='')");
    $catFill->execute([$businessId]);
    $catUpd = $pdo->prepare("UPDATE hotel_service_types SET category=? WHERE id=?");
    foreach ($catFill->fetchAll(PDO::FETCH_ASSOC) as $cf) {
        $catUpd->execute([$hsDefaultCategory((string)$cf['type_key']), $cf['id']]);
    }
} catch (\Throwable $e) {
}
$pdo->exec("CREATE TABLE IF NOT EXISTS hotel_service_partners (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    business_id  INT NOT NULL DEFAULT 1,
    partner_name VARCHAR(120) NOT NULL,
    category     VARCHAR(20) NOT NULL DEFAULT 'mobil',
    phone        VARCHAR(40) DEFAULT NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    sort_order   INT NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_biz_cat (business_id, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try {
    $pdo->query("SELECT partner_id FROM hotel_service_catalog LIMIT 1");
} catch (\Throwable $e) {
    try {
        $pdo->exec("ALTER TABLE hotel_service_catalog ADD COLUMN partner_id INT DEFAULT NULL COMMENT 'mitra default'");
    } catch (\Throwable $e2) {
        error_log('hotel_service_catalog partner_id migration: ' . $e2->getMessage());
    }
}

// Kolom pembagian driver/mitra (owner_amount, hotel_commission, driver_paid) wajib ada sebelum insert item.
try {
    ensureDriverTripPaymentColumns($pdo);
} catch (\Throwable $e) {
    error_log("ensureDriverTripPaymentColumns: " . $e->getMessage());
}
// Mitra per item invoice: dipilih saat input, dibaca apa adanya oleh menu Tagihan.
foreach (['partner_id' => 'INT DEFAULT NULL', 'partner_name' => 'VARCHAR(120) DEFAULT NULL', 'partner_amount' => 'DECIMAL(15,2) DEFAULT NULL'] as $hiiCol => $hiiDef) {
    try {
        $pdo->query("SELECT {$hiiCol} FROM hotel_invoice_items LIMIT 1");
    } catch (\Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE hotel_invoice_items ADD COLUMN {$hiiCol} {$hiiDef}");
        } catch (\Throwable $e2) {
            error_log("hotel_invoice_items {$hiiCol} migration: " . $e2->getMessage());
        }
    }
}

// ── Load service types from DB ─────────────────────────────────────────────────
$serviceTypes = [];
try {
    $stStmt = $pdo->prepare("SELECT type_key, type_label, type_icon, category FROM hotel_service_types WHERE business_id=? AND is_active=1 ORDER BY sort_order, type_label");
    $stStmt->execute([$businessId]);
    foreach ($stStmt->fetchAll(PDO::FETCH_ASSOC) as $st) {
        $serviceTypes[$st['type_key']] = [
            'label'    => $st['type_label'],
            'icon'     => $st['type_icon'],
            'category' => ($st['category'] ?? '') !== '' ? $st['category'] : $hsDefaultCategory((string)$st['type_key']),
        ];
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
    foreach ($serviceTypes as $k => $v) $serviceTypes[$k]['category'] = $hsDefaultCategory($k);
}

$statusColors    = ['pending' => '#b45309', 'confirmed' => '#1d4ed8', 'completed' => '#047857', 'cancelled' => '#b91c1c'];
$payStatusColors = ['unpaid' => '#b91c1c', 'partial' => '#b45309', 'paid' => '#047857'];

// ── Helper: find/create division by service type ──────────────────────────────
function getDivisionForService(PDO $pdo, string $serviceType): int
{
    static $cache = [];
    if (isset($cache[$serviceType])) return $cache[$serviceType];

    // Preferred division names — must match exactly what's in the DB (or close synonyms)
    // UPDATED: airport_drop & harbor_drop now go to Rent Car, narayana_trip to Narayana Trip division
    $nameMap = [
        'motor_rental'  => ['Motor Rental',  'MOTOR_RENTAL',  'MOTOR'],
        'car_rental'    => ['Rent Car',       'RENTCAR',       'Rent Car'],
        'laundry'       => ['Laundry',         'LAUNDRY',       'Housekeeping'],
        'service'       => ['General Service', 'GEN_SERVICE',   'Hotel'],
        'airport_drop'  => ['Rent Car',       'RENTCAR',       'Rent Car'],
        'harbor_drop'   => ['Rent Car',       'RENTCAR',       'Rent Car'],
        'narayana_trip' => ['Narayana Trip',  'NARAYANA_TRIP', 'Narayana Trip'],
        'lain_lain'     => ['Lain2',           'OTHERS',        'Hotel'],
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
        $helper = new CashbookHelper($db, $businessId, $userId);
        $hasCa  = $helper->hasCashAccountIdColumn();
        $bPdo   = $db->getConnection();
        $catId  = getHotelServiceCategoryId($bPdo);
        $now    = date('Y-m-d H:i:s');
        $invNo  = $invRow['invoice_number'];
        $guest  = $invRow['guest_name'];
        $totalAmt = (float)$invRow['total'];
        $paidTotal = (float)$invRow['paid_amount'];

        // Split-tender breakdown (cash + kartu dalam 1 nota); fallback to the single
        // legacy payment_method for invoices created before the breakdown table existed.
        $payStmt = $bPdo->prepare("SELECT amount, method FROM hotel_invoice_payments WHERE invoice_id=? ORDER BY id ASC");
        $payStmt->execute([$invRow['id']]);
        $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$payments) {
            $payments = [['amount' => (float)$invRow['paid_amount'], 'method' => $invRow['payment_method']]];
        }

        $lastTransId   = 0;
        $accountTotals = []; // account_id => ['account'=>row, 'amount'=>sum]

        foreach ($payments as $payIdx => $pay) {
            $payAmount = round((float)$pay['amount'], 2);
            if ($payAmount <= 0) continue;

            $account = $helper->getCashAccount($pay['method']);
            if (!$account) continue;
            $cbMethod = $helper->mapPaymentMethod($pay['method']);

            $insertedForPay = 0;
            $groupCount = count($itemGroups);
            foreach ($itemGroups as $gIdx => $group) {
                $svcType    = $group['service_type'];
                $svcLabel   = $serviceTypes[$svcType]['label'] ?? $svcType;
                $proportion = $totalAmt > 0 ? ($group['type_total'] / $totalAmt) : (1 / $groupCount);
                $isLastGroup = ($gIdx === $groupCount - 1);
                $svcAmount  = $isLastGroup
                    ? round($payAmount - $insertedForPay, 2)   // last item gets remainder to avoid rounding loss
                    : round($payAmount * $proportion, 2);
                if ($svcAmount <= 0) continue;
                $insertedForPay += $svcAmount;

                $divId = getDivisionForService($bPdo, $svcType);
                $desc  = "[{$invNo}] {$guest} - {$svcLabel} | Invoice Rp "
                    . number_format($totalAmt, 0, ',', '.') . " | Dibayar Rp "
                    . number_format($paidTotal, 0, ',', '.');

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

            if ($insertedForPay > 0) {
                if (!isset($accountTotals[$account['id']])) {
                    $accountTotals[$account['id']] = ['account' => $account, 'amount' => 0];
                }
                $accountTotals[$account['id']]['amount'] += $insertedForPay;
            }
        }

        // ── Master DB: one cash_account_transactions entry per account involved + balance update
        try {
            $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : (defined('DB_NAME') ? DB_NAME : null);
            if ($masterDbName && $accountTotals) {
                $mPdo = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname={$masterDbName};charset=" . DB_CHARSET,
                    DB_USER,
                    DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $masterDesc = "Hotel Services [{$invNo}] {$guest}";
                $hasTxCol = (bool)$mPdo->query("SHOW COLUMNS FROM cash_account_transactions LIKE 'transaction_id'")->fetch();
                foreach ($accountTotals as $accId => $data) {
                    $accAmt = round($data['amount'], 2);
                    if ($accAmt <= 0) continue;
                    if ($hasTxCol) {
                        $mPdo->prepare("INSERT INTO cash_account_transactions
                            (cash_account_id, transaction_id, transaction_date,
                             description, amount, transaction_type, reference_number, created_by, created_at)
                            VALUES (?, ?, DATE(?), ?, ?, 'income', ?, ?, NOW())")
                            ->execute([$accId, $lastTransId, $now, $masterDesc, $accAmt, $invNo, $userId]);
                    } else {
                        $mPdo->prepare("INSERT INTO cash_account_transactions
                            (cash_account_id, transaction_date,
                             description, amount, transaction_type, reference_number, created_by, created_at)
                            VALUES (?, DATE(?), ?, ?, 'income', ?, ?, NOW())")
                            ->execute([$accId, $now, $masterDesc, $accAmt, $invNo, $userId]);
                    }
                    $newBal = $data['account']['current_balance'] + $accAmt;
                    $mPdo->prepare("UPDATE cash_accounts SET current_balance = ? WHERE id = ?")->execute([$newBal, $accId]);
                }
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

/**
 * Bagian pemilik motor mitra (rumus sama dengan modul Rental Motor saat pengembalian):
 * owner = total × owner_commission_pct%. Motor milik hotel (tanpa pemilik/persen) → 0.
 * @return array{0: float, 1: float} [owner_amount, hotel_commission]
 */
function hsMotorOwnerSplit(array $motorRow, float $total): array
{
    $pct = (float)($motorRow['owner_commission_pct'] ?? 0);
    $hasOwner = trim((string)($motorRow['partner_owner'] ?? '')) !== '';
    $owner = ($hasOwner && $pct > 0) ? round($total * $pct / 100, 2) : 0.0;
    return [$owner, round($total - $owner, 2)];
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
            $driverTripItems  = [];
            foreach ($items as &$item) {
                $item['qty']         = max(0.5, (float)($item['qty']        ?? 1));
                $item['unit_price']  = max(0,   (float)($item['unit_price'] ?? 0));
                $item['motor_count'] = max(1, (int)($item['motor_count'] ?? 1));
                $item['start_dt']   = trim((string)($item['start_dt'] ?? '')) ?: null;
                $item['end_dt']     = trim((string)($item['end_dt'] ?? '')) ?: null;
                $item['deposit']    = max(0, (float)($item['deposit'] ?? 0));
                $item['trip_destination'] = trim((string)($item['trip_destination'] ?? '')) ?: null;
                $item['trip_type'] = trim((string)($item['trip_type'] ?? ''));
                $item['guide_id'] = (int)($item['guide_id'] ?? 0);
                $item['guide_name'] = trim((string)($item['guide_name'] ?? ''));
                $item['total']      = round($item['qty'] * $item['unit_price'], 2);
                $item['car_id']              = (int)($item['car_id'] ?? 0);
                $item['needs_driver_payment'] = !empty($item['needs_driver_payment']) ? 1 : 0;
                $item['commission_type']     = in_array($item['commission_type'] ?? '', ['percent', 'nominal'], true) ? $item['commission_type'] : 'percent';
                $item['commission_value']    = max(0, (float)($item['commission_value'] ?? 0));
                // Mitra kategori Mobil: nama diambil dari master mitra; nominal bayar ≤ total item.
                $item['partner_id'] = (int)($item['partner_id'] ?? 0) ?: null;
                $item['partner_name'] = null;
                $item['partner_amount'] = max(0, (float)($item['partner_amount'] ?? 0));
                if ($item['partner_id']) {
                    $pStmt = $pdo->prepare("SELECT partner_name FROM hotel_service_partners WHERE id=? AND business_id=? LIMIT 1");
                    $pStmt->execute([$item['partner_id'], $businessId]);
                    $pName = $pStmt->fetchColumn();
                    if ($pName === false) throw new Exception('Mitra tidak ditemukan');
                    $item['partner_name'] = (string)$pName;
                    $item['partner_amount'] = min(round($item['qty'] * $item['unit_price'], 2), $item['partner_amount']);
                } else {
                    $item['partner_amount'] = null;
                }
                $subtotal += $item['total'];
                if (!isset($serviceTypes[$item['service_type'] ?? ''])) {
                    throw new Exception('Invalid service type: ' . ($item['service_type'] ?? ''));
                }

                if (($item['service_type'] ?? '') === 'narayana_trip') {
                    if (!in_array($item['trip_type'], ['open_trip', 'private_trip'], true)) {
                        throw new Exception('Narayana Trip wajib pilih tipe Open Trip atau Private Trip');
                    }
                    if ($item['guide_id'] <= 0) {
                        throw new Exception('Narayana Trip wajib pilih nama guide');
                    }
                    $guideStmt = $pdo->prepare("SELECT id, guide_name FROM narayana_trip_guides WHERE id=? AND business_id=? AND is_active=1 LIMIT 1");
                    $guideStmt->execute([$item['guide_id'], $businessId]);
                    $guideRow = $guideStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$guideRow) {
                        throw new Exception('Guide Narayana Trip tidak ditemukan');
                    }
                    $item['guide_name'] = trim((string)$guideRow['guide_name']);
                    if (trim((string)($item['description'] ?? '')) === '') {
                        $tripTypeLabel = $item['trip_type'] === 'open_trip' ? 'Open Trip' : 'Private Trip';
                        $item['description'] = "Narayana Trip - {$tripTypeLabel} - Guide: {$item['guide_name']}";
                    }
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
                    $mLabel = $motorRow['motor_name'] . ' (' . $motorRow['plate_number'] . ')';
                    $mDesc = trim((string)($item['description'] ?? ''));
                    $item['description'] = $mDesc === '' ? $mLabel : (stripos($mDesc, (string)$motorRow['plate_number']) === false ? $mDesc . ' - ' . $mLabel : $mDesc);
                    $motorRentalItems[] = ['item' => $item, 'row' => $motorRow];
                }

                if (($item['service_type'] ?? '') === 'car_rental') {
                    $item['car_id'] = (int)($item['car_id'] ?? 0);
                    if (!$item['start_dt'] || !$item['end_dt']) {
                        throw new Exception('Rental mobil/taxi wajib isi mulai dan selesai');
                    }
                    // Car selection is optional - mitra handles vehicle selection
                    // User can just enter the billing without selecting armada
                    if ($item['car_id']) {
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
                        if ($item['needs_driver_payment'] && $item['commission_value'] <= 0) {
                            $item['commission_type']  = $carRow['commission_type'] ?: 'percent';
                            $item['commission_value'] = $item['commission_type'] === 'nominal' ? (float)$carRow['commission_nominal'] : (float)$carRow['owner_commission_pct'];
                        }
                        $carRentalItems[] = ['item' => $item, 'row' => $carRow];
                    } else {
                        // No armada selected - just add generic billing
                        if (trim((string)($item['description'] ?? '')) === '') {
                            $item['description'] = 'Rental Mobil / Taxi';
                        }
                        if ($item['trip_destination']) {
                            $item['description'] .= ' — Tujuan: ' . $item['trip_destination'];
                        }
                    }
                }

                if (in_array($item['service_type'] ?? '', ['airport_drop', 'harbor_drop'], true) && $item['car_id']) {
                    $carStmt = $pdo->prepare("SELECT * FROM rental_cars WHERE id=? AND business_id=?");
                    $carStmt->execute([$item['car_id'], $businessId]);
                    $carRow = $carStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$carRow) throw new Exception('Mobil/driver tidak ditemukan');
                    $item['start_dt'] = $item['start_dt'] ?: date('Y-m-d H:i:s');
                    $item['end_dt']   = $item['end_dt'] ?: $item['start_dt'];
                    if ($item['needs_driver_payment'] && $item['commission_value'] <= 0) {
                        $item['commission_type']  = $carRow['commission_type'] ?: 'percent';
                        $item['commission_value'] = $item['commission_type'] === 'nominal' ? (float)$carRow['commission_nominal'] : (float)$carRow['owner_commission_pct'];
                    }
                    $driverTripItems[] = ['item' => $item, 'row' => $carRow];
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
            // Aksi → Tambah Layanan: item digabung ke invoice yang dipilih (pembayaran lewat tombol Bayar).
            $targetInvId = (int)($_POST['target_invoice_id'] ?? 0);
            if ($targetInvId > 0) {
                $tStmt = $pdo->prepare("SELECT id, status, cashbook_synced FROM hotel_invoices WHERE id=? AND business_id=? LIMIT 1");
                $tStmt->execute([$targetInvId, $businessId]);
                $tRow = $tStmt->fetch(PDO::FETCH_ASSOC);
                if (!$tRow) throw new Exception('Invoice tujuan tidak ditemukan');
                if ($tRow['status'] === 'cancelled') throw new Exception('Invoice sudah dibatalkan, tidak bisa ditambah layanan');
                if ((int)$tRow['cashbook_synced'] === 1) throw new Exception('Invoice sudah diproses ke Buku Kas, buat invoice baru untuk layanan tambahan');
                $existingInvId = (int)$tRow['id'];
                $paidAmount = 0.0;
            }
            if (!$existingInvId && $bookingId) {
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
                    (invoice_id, service_type, trip_type, guide_id, guide_name, description, quantity, unit_price, total_price, owner_amount, hotel_commission, start_datetime, end_datetime, partner_id, partner_name, partner_amount)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                foreach ($items as $item) {
                    [$iOwner, $iHotel] = !empty($item['needs_driver_payment'])
                        ? calcDriverSplit((float)$item['total'], $item['commission_type'] ?? 'percent', (float)($item['commission_value'] ?? 0))
                        : [0.0, 0.0];
                    if (($item['service_type'] ?? '') === 'narayana_trip') {
                        // Guide billing defaults to full amount when no explicit split is provided.
                        if ($iOwner <= 0 && $iHotel <= 0) {
                            $iOwner = (float)$item['total'];
                            $iHotel = 0.0;
                        }
                    }
                    if (!empty($item['partner_id'])) {
                        $iOwner = (float)$item['partner_amount'];
                        $iHotel = (float)$item['total'] - $iOwner;
                    }
                    $iStmt->execute([
                        $invId,
                        $item['service_type'],
                        $item['trip_type'] ?: null,
                        $item['guide_id'] ?: null,
                        $item['guide_name'] ?: null,
                        $item['description'] ?: null,
                        $item['qty'],
                        $item['unit_price'],
                        $item['total'],
                        $iOwner,
                        $iHotel,
                        $item['start_dt'] ?: null,
                        $item['end_dt'] ?: null,
                        $item['partner_id'] ?? null,
                        $item['partner_name'] ?? null,
                        $item['partner_amount'] ?? null,
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
                        last_service_at = NOW(),
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
                     discount_rate, discount_amount, last_service_at, created_by, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
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
                        date('Y-m-d H:i:s'),
                        $currentUser['id'] ?? null
                    ]);
                $invId = (int)$pdo->lastInsertId();

                if ($paidAmount > 0) {
                    $pdo->prepare("INSERT INTO hotel_invoice_payments (invoice_id, business_id, amount, method, created_by) VALUES (?,?,?,?,?)")
                        ->execute([$invId, $businessId, $paidAmount, $payMethod, $currentUser['id'] ?? null]);
                }

                $iStmt = $pdo->prepare("INSERT INTO hotel_invoice_items
                    (invoice_id, service_type, trip_type, guide_id, guide_name, description, quantity, unit_price, total_price, owner_amount, hotel_commission, start_datetime, end_datetime, partner_id, partner_name, partner_amount)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                foreach ($items as $item) {
                    [$iOwner, $iHotel] = !empty($item['needs_driver_payment'])
                        ? calcDriverSplit((float)$item['total'], $item['commission_type'] ?? 'percent', (float)($item['commission_value'] ?? 0))
                        : [0.0, 0.0];
                    if (($item['service_type'] ?? '') === 'narayana_trip') {
                        if ($iOwner <= 0 && $iHotel <= 0) {
                            $iOwner = (float)$item['total'];
                            $iHotel = 0.0;
                        }
                    }
                    if (!empty($item['partner_id'])) {
                        $iOwner = (float)$item['partner_amount'];
                        $iHotel = (float)$item['total'] - $iOwner;
                    }
                    $iStmt->execute([
                        $invId,
                        $item['service_type'],
                        $item['trip_type'] ?: null,
                        $item['guide_id'] ?: null,
                        $item['guide_name'] ?: null,
                        $item['description'] ?: null,
                        $item['qty'],
                        $item['unit_price'],
                        $item['total'],
                        $iOwner,
                        $iHotel,
                        $item['start_dt'] ?: null,
                        $item['end_dt'] ?: null,
                        $item['partner_id'] ?? null,
                        $item['partner_name'] ?? null,
                        $item['partner_amount'] ?? null,
                    ]);
                }
            }

            foreach ($motorRentalItems as $motorRental) {
                $item = $motorRental['item'];
                $motorRow = $motorRental['row'];
                $pdo->prepare("INSERT INTO rental_motor_bookings
                    (business_id, motor_id, invoice_id, guest_name, guest_phone, room_number, booking_id,
                     start_datetime, end_datetime, daily_rate, total_price, motor_count, deposit, status, notes, created_by, owner_amount, hotel_commission)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
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
                        $item['motor_count'] ?? 1,
                        $item['deposit'],
                        'active',
                        $notes ?: null,
                        $currentUser['id'] ?? null,
                        hsMotorOwnerSplit($motorRow, (float)$item['total'])[0],
                        hsMotorOwnerSplit($motorRow, (float)$item['total'])[1],
                    ]);
                $pdo->prepare("UPDATE rental_motors SET status='rented', updated_at=NOW() WHERE id=?")
                    ->execute([(int)$motorRow['id']]);
            }

            foreach ($carRentalItems as $carRental) {
                $item = $carRental['item'];
                $carRow = $carRental['row'];
                [$ownerAmount, $hotelCommission] = $item['needs_driver_payment']
                    ? calcDriverSplit((float)$item['total'], $item['commission_type'], $item['commission_value'])
                    : [0, 0];
                $pdo->prepare("INSERT INTO rental_car_bookings
                    (business_id, car_id, invoice_id, guest_name, guest_phone, room_number, booking_id,
                     start_datetime, end_datetime, daily_rate, total_price, owner_amount, hotel_commission,
                     deposit, trip_destination, status, notes, created_by,
                     service_type, needs_driver_payment, commission_type, commission_value)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
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
                        $ownerAmount,
                        $hotelCommission,
                        $item['deposit'],
                        $item['trip_destination'],
                        'active',
                        $notes ?: null,
                        $currentUser['id'] ?? null,
                        'car_rental',
                        $item['needs_driver_payment'],
                        $item['commission_type'],
                        $item['commission_value'],
                    ]);
                $pdo->prepare("UPDATE rental_cars SET status='rented', updated_at=NOW() WHERE id=?")
                    ->execute([(int)$carRow['id']]);
            }

            foreach ($driverTripItems as $driverTrip) {
                $item = $driverTrip['item'];
                $carRow = $driverTrip['row'];
                [$ownerAmount, $hotelCommission] = $item['needs_driver_payment']
                    ? calcDriverSplit((float)$item['total'], $item['commission_type'], $item['commission_value'])
                    : [0, 0];
                $pdo->prepare("INSERT INTO rental_car_bookings
                    (business_id, car_id, invoice_id, guest_name, guest_phone, room_number, booking_id,
                     start_datetime, end_datetime, daily_rate, total_price, owner_amount, hotel_commission,
                     deposit, trip_destination, status, notes, created_by,
                     service_type, needs_driver_payment, commission_type, commission_value)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
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
                        $ownerAmount,
                        $hotelCommission,
                        0,
                        $item['trip_destination'],
                        'returned',
                        $notes ?: null,
                        $currentUser['id'] ?? null,
                        $item['service_type'],
                        $item['needs_driver_payment'],
                        $item['commission_type'],
                        $item['commission_value'],
                    ]);
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

            // Record this payment in the breakdown table (supports split cash+kartu in 1 invoice)
            $pdo->prepare("INSERT INTO hotel_invoice_payments (invoice_id, business_id, amount, method, created_by) VALUES (?,?,?,?,?)")
                ->execute([$id, $businessId, $amount, $method, $currentUser['id'] ?? null]);
            $methodCountStmt = $pdo->prepare("SELECT COUNT(DISTINCT method) FROM hotel_invoice_payments WHERE invoice_id=?");
            $methodCountStmt->execute([$id]);
            $storedMethod = ((int)$methodCountStmt->fetchColumn() > 1) ? 'split' : $method;

            $pdo->prepare("UPDATE hotel_invoices SET paid_amount=?, payment_status=?, payment_method=?, updated_at=NOW() WHERE id=? AND business_id=?")
                ->execute([$newPaid, $payStatus, $storedMethod, $id, $businessId]);

            // Motor Return Tracking: Instead of auto-returning, ask staff to confirm
            // whether motor is actually back. If not returned within 24h, system will notify.
            $motorsForConfirmation = [];
            $carsAutoReturned   = [];
            if ($payStatus === 'paid') {
                // Get motors that need return confirmation (don't auto-return yet)
                $motorRentals = $pdo->prepare("SELECT rb.id, rb.motor_id, rm.motor_name, rm.plate_number 
                    FROM rental_motor_bookings rb
                    JOIN rental_motors rm ON rb.motor_id = rm.id
                    WHERE rb.invoice_id=? AND rb.business_id=? AND rb.status IN ('active','overdue')");
                $motorRentals->execute([$id, $businessId]);
                foreach ($motorRentals->fetchAll(PDO::FETCH_ASSOC) as $mr) {
                    $motorsForConfirmation[] = [
                        'id' => (int)$mr['id'],
                        'motor_name' => $mr['motor_name'],
                        'plate_number' => $mr['plate_number']
                    ];
                }

                // Auto-return rental cars (user doesn't manually return cars via invoice like motors)
                $carRentals = $pdo->prepare("SELECT cb.id, cb.car_id FROM rental_car_bookings cb
                    WHERE cb.invoice_id=? AND cb.business_id=? AND cb.status IN ('active','overdue')");
                $carRentals->execute([$id, $businessId]);
                foreach ($carRentals->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                    $pdo->prepare("UPDATE rental_car_bookings SET status='returned', actual_return=NOW(), updated_at=NOW() WHERE id=?")
                        ->execute([$cr['id']]);
                    $pdo->prepare("UPDATE rental_cars SET status='available', updated_at=NOW() WHERE id=?")
                        ->execute([$cr['car_id']]);
                    $carsAutoReturned[] = (int)$cr['id'];
                }
            }

            // Cashbook NOT synced here — must use "Process Invoice" in preview
            ob_clean();
            echo json_encode(['success' => true, 'payment_status' => $payStatus, 'paid_amount' => $newPaid, 'cashbook' => false, 'motors_for_confirmation' => $motorsForConfirmation, 'cars_auto_returned' => $carsAutoReturned]);
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

            // Auto-generate Tagihan (Bills) entries for any driver/partner payments owed on this trip
            $driverBillsCreated = 0;
            try {
                $driverBookingsStmt = $pdo->prepare("SELECT * FROM rental_car_bookings WHERE invoice_id=? AND business_id=? AND needs_driver_payment=1 AND billed_to_tagihan=0");
                $driverBookingsStmt->execute([$id, $businessId]);
                foreach ($driverBookingsStmt->fetchAll(PDO::FETCH_ASSOC) as $driverBooking) {
                    $svcLabel = $serviceTypes[$driverBooking['service_type']]['label'] ?? ucfirst(str_replace('_', ' ', $driverBooking['service_type']));
                    if (createDriverPayableBill($pdo, $currentUser['id'] ?? null, $driverBooking, $svcLabel)) {
                        $driverBillsCreated++;
                    }
                }
            } catch (\Throwable $e) {
                error_log('createDriverPayableBill: ' . $e->getMessage());
            }

            ob_clean();
            echo json_encode(['success' => true, 'cashbook' => $cbOk, 'paid_amount' => $invRow['paid_amount'], 'driver_bills_created' => $driverBillsCreated]);
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
            $pdo->beginTransaction();

            $motorBookings = $pdo->prepare("SELECT id, motor_id, status FROM rental_motor_bookings WHERE invoice_id=? AND business_id=?");
            $motorBookings->execute([$id, $businessId]);
            foreach ($motorBookings->fetchAll(PDO::FETCH_ASSOC) as $booking) {
                $pdo->prepare("DELETE FROM rental_motor_bookings WHERE id=? AND business_id=?")
                    ->execute([$booking['id'], $businessId]);
                $activeCheck = $pdo->prepare("SELECT COUNT(*) FROM rental_motor_bookings WHERE motor_id=? AND status IN ('active','overdue') AND business_id=?");
                $activeCheck->execute([$booking['motor_id'], $businessId]);
                if ((int)$activeCheck->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE rental_motors SET status='available', updated_at=NOW() WHERE id=?")
                        ->execute([$booking['motor_id']]);
                }
            }

            $carBookings = $pdo->prepare("SELECT id, car_id, status FROM rental_car_bookings WHERE invoice_id=? AND business_id=?");
            $carBookings->execute([$id, $businessId]);
            foreach ($carBookings->fetchAll(PDO::FETCH_ASSOC) as $booking) {
                $pdo->prepare("DELETE FROM rental_car_bookings WHERE id=? AND business_id=?")
                    ->execute([$booking['id'], $businessId]);
                $activeCheck = $pdo->prepare("SELECT COUNT(*) FROM rental_car_bookings WHERE car_id=? AND status IN ('active','overdue') AND business_id=?");
                $activeCheck->execute([$booking['car_id'], $businessId]);
                if ((int)$activeCheck->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE rental_cars SET status='available', updated_at=NOW() WHERE id=?")
                        ->execute([$booking['car_id']]);
                }
            }

            $pdo->prepare("DELETE FROM hotel_invoice_items WHERE invoice_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM hotel_invoices WHERE id=? AND business_id=?")->execute([$id, $businessId]);
            $pdo->commit();
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
            $price      = max(0, (float)($_POST['default_price'] ?? 0));
            $driverRate = max(0, (float)($_POST['driver_rate'] ?? 0));
            $unit  = trim($_POST['unit'] ?? 'unit');
            $sort  = (int)($_POST['sort_order'] ?? 0);
            $partnerId = (int)($_POST['partner_id'] ?? 0) ?: null;
            if (!$name) throw new Exception('Item name is required');
            if (!isset($serviceTypes[$stype])) throw new Exception('Invalid service type');
            if ($driverRate > $price && $price > 0) throw new Exception('Bayar ke mitra tidak boleh melebihi harga ke tamu');
            if ($cid) {
                $pdo->prepare("UPDATE hotel_service_catalog SET service_type=?,item_name=?,default_price=?,driver_rate=?,partner_id=?,unit=?,sort_order=? WHERE id=? AND business_id=?")
                    ->execute([$stype, $name, $price, $driverRate, $partnerId, $unit, $sort, $cid, $businessId]);
            } else {
                $pdo->prepare("INSERT INTO hotel_service_catalog (business_id,service_type,item_name,default_price,driver_rate,partner_id,unit,sort_order) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$businessId, $stype, $name, $price, $driverRate, $partnerId, $unit, $sort]);
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
            $category  = (string)($_POST['category'] ?? '');
            if (!isset($hsCategories[$category])) $category = $hsDefaultCategory($typeKey);
            if (!$typeKey || !$typeLabel) throw new Exception('Key and Label are required');
            if ($stId) {
                $pdo->prepare("UPDATE hotel_service_types SET type_key=?,type_label=?,type_icon=?,category=?,sort_order=? WHERE id=? AND business_id=?")
                    ->execute([$typeKey, $typeLabel, $typeIcon, $category, $sortOrder, $stId, $businessId]);
            } else {
                $pdo->prepare("INSERT INTO hotel_service_types (business_id,type_key,type_label,type_icon,category,sort_order) VALUES (?,?,?,?,?,?)")
                    ->execute([$businessId, $typeKey, $typeLabel, $typeIcon, $category, $sortOrder]);
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

        // ── MITRA LAYANAN (simpan / hapus) ────────────────────────────────────────────
        if ($action === 'save_partner') {
            $pid = (int)($_POST['partner_id'] ?? 0);
            $pname = trim((string)($_POST['partner_name'] ?? ''));
            $pcat = (string)($_POST['category'] ?? 'mobil');
            $pphone = trim((string)($_POST['phone'] ?? ''));
            $pactive = !empty($_POST['is_active']) ? 1 : 0;
            if ($pname === '') throw new Exception('Nama mitra wajib diisi');
            if (!isset($hsCategories[$pcat])) $pcat = 'mobil';
            if ($pid > 0) {
                $pdo->prepare("UPDATE hotel_service_partners SET partner_name=?, category=?, phone=?, is_active=? WHERE id=? AND business_id=?")
                    ->execute([mb_substr($pname, 0, 120), $pcat, $pphone ?: null, $pactive, $pid, $businessId]);
            } else {
                $pdo->prepare("INSERT INTO hotel_service_partners (business_id, partner_name, category, phone, is_active) VALUES (?,?,?,?,?)")
                    ->execute([$businessId, mb_substr($pname, 0, 120), $pcat, $pphone ?: null, $pactive]);
                $pid = (int)$pdo->lastInsertId();
            }
            ob_clean();
            echo json_encode(['success' => true, 'id' => $pid]);
            exit;
        }
        if ($action === 'delete_partner') {
            $pid = (int)($_POST['partner_id'] ?? 0);
            if (!$pid) throw new Exception('Mitra tidak valid');
            // Mitra yang dipakai katalog dilepas dari katalog (bukan menghapus item katalog).
            $pdo->prepare("UPDATE hotel_service_catalog SET partner_id=NULL WHERE partner_id=? AND business_id=?")->execute([$pid, $businessId]);
            $pdo->prepare("DELETE FROM hotel_service_partners WHERE id=? AND business_id=?")->execute([$pid, $businessId]);
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        }

        // ── SAVE / UPDATE NARAYANA TRIP GUIDE ───────────────────────────────────────────
        if ($action === 'save_trip_guide') {
            $guideId = (int)($_POST['guide_id'] ?? 0);
            $guideName = trim($_POST['guide_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            if ($guideName === '') {
                throw new Exception('Nama guide wajib diisi');
            }
            if ($guideId > 0) {
                $pdo->prepare("UPDATE narayana_trip_guides SET guide_name=?, phone=?, sort_order=? WHERE id=? AND business_id=?")
                    ->execute([$guideName, $phone ?: null, $sortOrder, $guideId, $businessId]);
            } else {
                $pdo->prepare("INSERT INTO narayana_trip_guides (business_id, guide_name, phone, sort_order, is_active) VALUES (?,?,?,?,1)")
                    ->execute([$businessId, $guideName, $phone ?: null, $sortOrder]);
                $guideId = (int)$pdo->lastInsertId();
            }
            ob_clean();
            echo json_encode(['success' => true, 'id' => $guideId]);
            exit;
        }

        // ── DELETE NARAYANA TRIP GUIDE ─────────────────────────────────────────────────
        if ($action === 'delete_trip_guide') {
            $guideId = (int)($_POST['guide_id'] ?? 0);
            if (!$guideId) throw new Exception('Guide ID tidak valid');

            $usedCheck = $pdo->prepare("SELECT COUNT(*) FROM hotel_invoice_items hii
                JOIN hotel_invoices hi ON hi.id = hii.invoice_id
                WHERE hi.business_id=? AND hii.guide_id=?");
            $usedCheck->execute([$businessId, $guideId]);
            if ((int)$usedCheck->fetchColumn() > 0) {
                throw new Exception('Guide sudah dipakai pada invoice, tidak bisa dihapus');
            }

            $pdo->prepare("DELETE FROM narayana_trip_guides WHERE id=? AND business_id=?")
                ->execute([$guideId, $businessId]);
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
            $existingDriverTripByKey = [];
            foreach ($existingCarBookings as $row) {
                $svcType = $row['service_type'] ?: 'car_rental';
                if ($svcType === 'car_rental') {
                    $existingCarByAssetId[(int)$row['car_id']] = $row;
                } else {
                    $existingDriverTripByKey[$svcType . '_' . (int)$row['car_id']] = $row;
                }
            }

            $subtotal = 0;
            $motorRentalItems = [];
            $carRentalItems = [];
            $driverTripItems = [];
            foreach ($items as &$item) {
                $item['qty']        = max(0.5, (float)($item['qty'] ?? 1));
                $item['unit_price'] = max(0, (float)($item['unit_price'] ?? 0));
                $item['motor_count'] = max(1, (int)($item['motor_count'] ?? 1));
                $item['start_dt']   = trim((string)($item['start_dt'] ?? '')) ?: null;
                $item['end_dt']     = trim((string)($item['end_dt'] ?? '')) ?: null;
                $item['deposit']    = max(0, (float)($item['deposit'] ?? 0));
                $item['trip_destination'] = trim((string)($item['trip_destination'] ?? '')) ?: null;
                $item['trip_type'] = trim((string)($item['trip_type'] ?? ''));
                $item['guide_id'] = (int)($item['guide_id'] ?? 0);
                $item['guide_name'] = trim((string)($item['guide_name'] ?? ''));
                $item['total']      = round($item['qty'] * $item['unit_price'], 2);
                $item['car_id']              = (int)($item['car_id'] ?? 0);
                $item['needs_driver_payment'] = !empty($item['needs_driver_payment']) ? 1 : 0;
                $item['commission_type']     = in_array($item['commission_type'] ?? '', ['percent', 'nominal'], true) ? $item['commission_type'] : 'percent';
                $item['commission_value']    = max(0, (float)($item['commission_value'] ?? 0));
                // Mitra kategori Mobil: nama diambil dari master mitra; nominal bayar ≤ total item.
                $item['partner_id'] = (int)($item['partner_id'] ?? 0) ?: null;
                $item['partner_name'] = null;
                $item['partner_amount'] = max(0, (float)($item['partner_amount'] ?? 0));
                if ($item['partner_id']) {
                    $pStmt = $pdo->prepare("SELECT partner_name FROM hotel_service_partners WHERE id=? AND business_id=? LIMIT 1");
                    $pStmt->execute([$item['partner_id'], $businessId]);
                    $pName = $pStmt->fetchColumn();
                    if ($pName === false) throw new Exception('Mitra tidak ditemukan');
                    $item['partner_name'] = (string)$pName;
                    $item['partner_amount'] = min(round($item['qty'] * $item['unit_price'], 2), $item['partner_amount']);
                } else {
                    $item['partner_amount'] = null;
                }
                $subtotal += $item['total'];
                if (!isset($serviceTypes[$item['service_type'] ?? ''])) throw new Exception('Invalid service type');

                if (($item['service_type'] ?? '') === 'narayana_trip') {
                    if (!in_array($item['trip_type'], ['open_trip', 'private_trip'], true)) {
                        throw new Exception('Narayana Trip wajib pilih tipe Open Trip atau Private Trip');
                    }
                    if ($item['guide_id'] <= 0) {
                        throw new Exception('Narayana Trip wajib pilih nama guide');
                    }
                    $guideStmt = $pdo->prepare("SELECT id, guide_name FROM narayana_trip_guides WHERE id=? AND business_id=? AND is_active=1 LIMIT 1");
                    $guideStmt->execute([$item['guide_id'], $businessId]);
                    $guideRow = $guideStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$guideRow) {
                        throw new Exception('Guide Narayana Trip tidak ditemukan');
                    }
                    $item['guide_name'] = trim((string)$guideRow['guide_name']);
                    if (trim((string)($item['description'] ?? '')) === '') {
                        $tripTypeLabel = $item['trip_type'] === 'open_trip' ? 'Open Trip' : 'Private Trip';
                        $item['description'] = "Narayana Trip - {$tripTypeLabel} - Guide: {$item['guide_name']}";
                    }
                }

                if (($item['service_type'] ?? '') === 'motor_rental') {
                    $item['motor_id'] = (int)($item['motor_id'] ?? 0);
                    if (!$item['motor_id'] || !$item['start_dt'] || !$item['end_dt']) throw new Exception('Motor rental wajib pilih armada, mulai, dan selesai');
                    $motorStmt = $pdo->prepare("SELECT * FROM rental_motors WHERE id=? AND business_id=?");
                    $motorStmt->execute([$item['motor_id'], $businessId]);
                    $motorRow = $motorStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$motorRow) throw new Exception('Armada motor tidak ditemukan');
                    if ($motorRow['status'] !== 'available' && !isset($existingMotorByAssetId[$item['motor_id']])) throw new Exception("Motor {$motorRow['plate_number']} tidak tersedia");
                    $mLabel = $motorRow['motor_name'] . ' (' . $motorRow['plate_number'] . ')';
                    $mDesc = trim((string)($item['description'] ?? ''));
                    $item['description'] = $mDesc === '' ? $mLabel : (stripos($mDesc, (string)$motorRow['plate_number']) === false ? $mDesc . ' - ' . $mLabel : $mDesc);
                    $motorRentalItems[] = ['item' => $item, 'row' => $motorRow];
                }

                if (($item['service_type'] ?? '') === 'car_rental') {
                    $item['car_id'] = (int)($item['car_id'] ?? 0);
                    if (!$item['start_dt'] || !$item['end_dt']) throw new Exception('Rental mobil/taxi wajib isi mulai dan selesai');
                    if ($item['car_id']) {
                        $carStmt = $pdo->prepare("SELECT * FROM rental_cars WHERE id=? AND business_id=?");
                        $carStmt->execute([$item['car_id'], $businessId]);
                        $carRow = $carStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$carRow) throw new Exception('Armada mobil tidak ditemukan');
                        if ($carRow['status'] !== 'available' && !isset($existingCarByAssetId[$item['car_id']])) throw new Exception("Mobil {$carRow['plate_number']} tidak tersedia");
                        $baseDesc = $carRow['car_name'] . ' (' . $carRow['plate_number'] . ')';
                        if ($item['trip_destination']) $baseDesc .= ' — Tujuan: ' . $item['trip_destination'];
                        $item['description'] = trim((string)($item['description'] ?? '')) ?: $baseDesc;
                        if ($item['needs_driver_payment'] && $item['commission_value'] <= 0) {
                            $item['commission_type']  = $carRow['commission_type'] ?: 'percent';
                            $item['commission_value'] = $item['commission_type'] === 'nominal' ? (float)$carRow['commission_nominal'] : (float)$carRow['owner_commission_pct'];
                        }
                        $carRentalItems[] = ['item' => $item, 'row' => $carRow];
                    } else {
                        if (trim((string)($item['description'] ?? '')) === '') {
                            $item['description'] = 'Rental Mobil / Taxi';
                        }
                    }
                }

                if (in_array($item['service_type'] ?? '', ['airport_drop', 'harbor_drop'], true) && $item['car_id']) {
                    $carStmt = $pdo->prepare("SELECT * FROM rental_cars WHERE id=? AND business_id=?");
                    $carStmt->execute([$item['car_id'], $businessId]);
                    $carRow = $carStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$carRow) throw new Exception('Mobil/driver tidak ditemukan');
                    $item['start_dt'] = $item['start_dt'] ?: date('Y-m-d H:i:s');
                    $item['end_dt']   = $item['end_dt'] ?: $item['start_dt'];
                    if ($item['needs_driver_payment'] && $item['commission_value'] <= 0) {
                        $item['commission_type']  = $carRow['commission_type'] ?: 'percent';
                        $item['commission_value'] = $item['commission_type'] === 'nominal' ? (float)$carRow['commission_nominal'] : (float)$carRow['owner_commission_pct'];
                    }
                    $driverTripItems[] = ['item' => $item, 'row' => $carRow];
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

            // Don't clobber an existing split cash+kartu breakdown if the paid amount
            // wasn't actually changed by this edit (the edit form can only submit 1 method).
            $existingPayStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) as sum_amt, COUNT(DISTINCT method) as method_cnt FROM hotel_invoice_payments WHERE invoice_id=?");
            $existingPayStmt->execute([$id]);
            $existingPay = $existingPayStmt->fetch(PDO::FETCH_ASSOC);
            $keepExistingBreakdown = $existingPay && (int)$existingPay['method_cnt'] > 1 && abs((float)$existingPay['sum_amt'] - $paidAmount) < 0.01;
            if ($keepExistingBreakdown) {
                $payMethod = 'split';
            } elseif ($payMethod === 'split') {
                $payMethod = 'cash'; // 'split' is only valid when a real multi-method breakdown exists
            }

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE hotel_invoices SET guest_name=?,guest_phone=?,room_number=?,total=?,paid_amount=?,payment_status=?,payment_method=?,notes=?,tax_rate=?,tax_amount=?,service_charge_rate=?,service_charge_amount=?,discount_rate=?,discount_amount=?,updated_at=NOW() WHERE id=?")
                ->execute([$guestName, $guestPhone ?: null, $roomNumber ?: null, $total, $paidAmount, $payStatus, $payMethod, $notes ?: null, $taxRate, $taxAmount, $serviceChargeRate, $serviceChargeAmount, $discountRate, $discountAmount, $id]);
            if (!$keepExistingBreakdown) {
                $pdo->prepare("DELETE FROM hotel_invoice_payments WHERE invoice_id=?")->execute([$id]);
                if ($paidAmount > 0) {
                    $pdo->prepare("INSERT INTO hotel_invoice_payments (invoice_id, business_id, amount, method, created_by) VALUES (?,?,?,?,?)")
                        ->execute([$id, $businessId, $paidAmount, $payMethod, $currentUser['id'] ?? null]);
                }
            }
            $pdo->prepare("DELETE FROM hotel_invoice_items WHERE invoice_id=?")->execute([$id]);
            $iStmt = $pdo->prepare("INSERT INTO hotel_invoice_items
                (invoice_id,service_type,trip_type,guide_id,guide_name,description,quantity,unit_price,total_price,owner_amount,hotel_commission,start_datetime,end_datetime,partner_id,partner_name,partner_amount)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($items as $item) {
                [$iOwner, $iHotel] = !empty($item['needs_driver_payment'])
                    ? calcDriverSplit((float)$item['total'], $item['commission_type'] ?? 'percent', (float)($item['commission_value'] ?? 0))
                    : [0.0, 0.0];
                if (($item['service_type'] ?? '') === 'narayana_trip') {
                    if ($iOwner <= 0 && $iHotel <= 0) {
                        $iOwner = (float)$item['total'];
                        $iHotel = 0.0;
                    }
                }
                if (!empty($item['partner_id'])) {
                    $iOwner = (float)$item['partner_amount'];
                    $iHotel = (float)$item['total'] - $iOwner;
                }
                $iStmt->execute([
                    $id,
                    $item['service_type'],
                    $item['trip_type'] ?: null,
                    $item['guide_id'] ?: null,
                    $item['guide_name'] ?: null,
                    $item['description'] ?: null,
                    $item['qty'],
                    $item['unit_price'],
                    $item['total'],
                    $iOwner,
                    $iHotel,
                    $item['start_dt'] ?: null,
                    $item['end_dt'] ?: null,
                    $item['partner_id'] ?? null,
                    $item['partner_name'] ?? null,
                    $item['partner_amount'] ?? null
                ]);
            }

            $matchedMotorBookingIds = [];
            foreach ($motorRentalItems as $motorRental) {
                $item = $motorRental['item'];
                $motorRow = $motorRental['row'];
                $existingBooking = $existingMotorByAssetId[(int)$item['motor_id']] ?? null;
                if ($existingBooking) {
                    $matchedMotorBookingIds[] = (int)$existingBooking['id'];
                    // Booking yang sudah dikembalikan tetap 'returned'; hanya yang dibatalkan yang diaktifkan lagi.
                    $newStatus = $existingBooking['status'] === 'cancelled' ? 'active' : $existingBooking['status'];
                    [$mOwnerAmt, $mHotelAmt] = hsMotorOwnerSplit($motorRow, (float)$item['total']);
                    $pdo->prepare("UPDATE rental_motor_bookings
                        SET invoice_id=?, guest_name=?, guest_phone=?, room_number=?, booking_id=?,
                            start_datetime=?, end_datetime=?, daily_rate=?, total_price=?, motor_count=?, deposit=?,
                            status=?, notes=?, owner_amount=?, hotel_commission=?, updated_at=NOW()
                        WHERE id=? AND business_id=?")
                        ->execute([
                            $id,
                            $guestName,
                            $guestPhone ?: null,
                            $roomNumber ?: null,
                            $invoiceBookingId,
                            $item['start_dt'],
                            $item['end_dt'],
                            $item['unit_price'],
                            $item['total'],
                            $item['motor_count'] ?? 1,
                            $item['deposit'],
                            $newStatus,
                            $notes ?: null,
                            $mOwnerAmt,
                            $mHotelAmt,
                            $existingBooking['id'],
                            $businessId
                        ]);
                } else {
                    $pdo->prepare("INSERT INTO rental_motor_bookings
                        (business_id, motor_id, invoice_id, guest_name, guest_phone, room_number, booking_id,
                         start_datetime, end_datetime, daily_rate, total_price, motor_count, deposit, status, notes, created_by, owner_amount, hotel_commission)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([
                            $businessId,
                            (int)$motorRow['id'],
                            $id,
                            $guestName,
                            $guestPhone ?: null,
                            $roomNumber ?: null,
                            $invoiceBookingId,
                            $item['start_dt'],
                            $item['end_dt'],
                            $item['unit_price'],
                            $item['total'],
                            $item['motor_count'] ?? 1,
                            $item['deposit'],
                            'active',
                            $notes ?: null,
                            $currentUser['id'] ?? null,
                            hsMotorOwnerSplit($motorRow, (float)$item['total'])[0],
                            hsMotorOwnerSplit($motorRow, (float)$item['total'])[1],
                        ]);
                    $matchedMotorBookingIds[] = (int)$pdo->lastInsertId();
                    $newStatus = 'active';
                }
                if ($newStatus === 'active' || $newStatus === 'overdue') {
                    $pdo->prepare("UPDATE rental_motors SET status='rented', updated_at=NOW() WHERE id=?")
                        ->execute([(int)$motorRow['id']]);
                }
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
                [$ownerAmount, $hotelCommission] = $item['needs_driver_payment']
                    ? calcDriverSplit((float)$item['total'], $item['commission_type'], $item['commission_value'])
                    : [0, 0];
                if ($existingBooking) {
                    $matchedCarBookingIds[] = (int)$existingBooking['id'];
                    $newStatus = in_array($existingBooking['status'], ['returned', 'cancelled'], true) ? 'active' : $existingBooking['status'];
                    $pdo->prepare("UPDATE rental_car_bookings
                        SET invoice_id=?, guest_name=?, guest_phone=?, room_number=?, booking_id=?,
                            start_datetime=?, end_datetime=?, daily_rate=?, total_price=?, deposit=?,
                            trip_destination=?, status=?, notes=?, owner_amount=?, hotel_commission=?,
                            needs_driver_payment=?, commission_type=?, commission_value=?, updated_at=NOW()
                        WHERE id=? AND business_id=?")
                        ->execute([
                            $id,
                            $guestName,
                            $guestPhone ?: null,
                            $roomNumber ?: null,
                            $invoiceBookingId,
                            $item['start_dt'],
                            $item['end_dt'],
                            $item['unit_price'],
                            $item['total'],
                            $item['deposit'],
                            $item['trip_destination'],
                            $newStatus,
                            $notes ?: null,
                            $ownerAmount,
                            $hotelCommission,
                            $item['needs_driver_payment'],
                            $item['commission_type'],
                            $item['commission_value'],
                            $existingBooking['id'],
                            $businessId
                        ]);
                } else {
                    $pdo->prepare("INSERT INTO rental_car_bookings
                        (business_id, car_id, invoice_id, guest_name, guest_phone, room_number, booking_id,
                         start_datetime, end_datetime, daily_rate, total_price, owner_amount, hotel_commission,
                         deposit, trip_destination, status, notes, created_by,
                         service_type, needs_driver_payment, commission_type, commission_value)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([
                            $businessId,
                            (int)$carRow['id'],
                            $id,
                            $guestName,
                            $guestPhone ?: null,
                            $roomNumber ?: null,
                            $invoiceBookingId,
                            $item['start_dt'],
                            $item['end_dt'],
                            $item['unit_price'],
                            $item['total'],
                            $ownerAmount,
                            $hotelCommission,
                            $item['deposit'],
                            $item['trip_destination'],
                            'active',
                            $notes ?: null,
                            $currentUser['id'] ?? null,
                            'car_rental',
                            $item['needs_driver_payment'],
                            $item['commission_type'],
                            $item['commission_value'],
                        ]);
                    $matchedCarBookingIds[] = (int)$pdo->lastInsertId();
                }
                $pdo->prepare("UPDATE rental_cars SET status='rented', updated_at=NOW() WHERE id=?")
                    ->execute([(int)$carRow['id']]);
            }

            // Airport Drop / Harbor Drop driver-trip items (update existing or insert new)
            foreach ($driverTripItems as $driverTrip) {
                $item = $driverTrip['item'];
                $carRow = $driverTrip['row'];
                $key = ($item['service_type'] ?? '') . '_' . (int)$carRow['id'];
                $existingTrip = $existingDriverTripByKey[$key] ?? null;
                [$ownerAmount, $hotelCommission] = $item['needs_driver_payment']
                    ? calcDriverSplit((float)$item['total'], $item['commission_type'], $item['commission_value'])
                    : [0, 0];
                if ($existingTrip) {
                    $matchedCarBookingIds[] = (int)$existingTrip['id'];
                    $pdo->prepare("UPDATE rental_car_bookings
                        SET invoice_id=?, guest_name=?, guest_phone=?, room_number=?, booking_id=?,
                            start_datetime=?, end_datetime=?, daily_rate=?, total_price=?,
                            trip_destination=?, notes=?, owner_amount=?, hotel_commission=?,
                            needs_driver_payment=?, commission_type=?, commission_value=?, updated_at=NOW()
                        WHERE id=? AND business_id=?")
                        ->execute([
                            $id,
                            $guestName,
                            $guestPhone ?: null,
                            $roomNumber ?: null,
                            $invoiceBookingId,
                            $item['start_dt'],
                            $item['end_dt'],
                            $item['unit_price'],
                            $item['total'],
                            $item['trip_destination'],
                            $notes ?: null,
                            $ownerAmount,
                            $hotelCommission,
                            $item['needs_driver_payment'],
                            $item['commission_type'],
                            $item['commission_value'],
                            $existingTrip['id'],
                            $businessId
                        ]);
                } else {
                    $pdo->prepare("INSERT INTO rental_car_bookings
                        (business_id, car_id, invoice_id, guest_name, guest_phone, room_number, booking_id,
                         start_datetime, end_datetime, daily_rate, total_price, owner_amount, hotel_commission,
                         deposit, trip_destination, status, notes, created_by,
                         service_type, needs_driver_payment, commission_type, commission_value)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([
                            $businessId,
                            (int)$carRow['id'],
                            $id,
                            $guestName,
                            $guestPhone ?: null,
                            $roomNumber ?: null,
                            $invoiceBookingId,
                            $item['start_dt'],
                            $item['end_dt'],
                            $item['unit_price'],
                            $item['total'],
                            $ownerAmount,
                            $hotelCommission,
                            0,
                            $item['trip_destination'],
                            'returned',
                            $notes ?: null,
                            $currentUser['id'] ?? null,
                            $item['service_type'],
                            $item['needs_driver_payment'],
                            $item['commission_type'],
                            $item['commission_value'],
                        ]);
                    $matchedCarBookingIds[] = (int)$pdo->lastInsertId();
                }
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
    $where[] = 'DATE(COALESCE(hi.last_service_at, hi.created_at)) = ?';
    $params[] = $filterDate;
}
if ($search) {
    $where[] = '(hi.guest_name LIKE ? OR hi.invoice_number LIKE ? OR hi.room_number LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// First get the list of invoices
$stmt = $pdo->prepare("SELECT hi.*,
    COALESCE(hi.last_service_at, hi.created_at) as service_date,
    COUNT(hii.id) as item_count
    FROM hotel_invoices hi
    LEFT JOIN hotel_invoice_items hii ON hii.invoice_id = hi.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY hi.id ORDER BY COALESCE(hi.last_service_at, hi.created_at) DESC LIMIT 200");
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// For each invoice, get service type breakdown (count per type) + full item detail (for the row-click detail popup)
foreach ($invoices as &$inv) {
    $typeCountStmt = $pdo->prepare("SELECT hii.service_type, COUNT(*) as cnt
        FROM hotel_invoice_items hii
        WHERE hii.invoice_id = ?
        GROUP BY hii.service_type
        ORDER BY hii.service_type");
    $typeCountStmt->execute([$inv['id']]);
    $inv['service_type_counts'] = $typeCountStmt->fetchAll(PDO::FETCH_ASSOC);

    $itemDetailStmt = $pdo->prepare("SELECT service_type, description, quantity, unit_price, total_price, start_datetime, end_datetime
        FROM hotel_invoice_items WHERE invoice_id = ? ORDER BY id ASC");
    $itemDetailStmt->execute([$inv['id']]);
    $inv['items_detail'] = $itemDetailStmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($inv);

// Build a lightweight JSON payload (per invoice) for the row-click detail popup
$hsDetailsForJs = [];
foreach ($invoices as $inv) {
    $itemsForJs = [];
    foreach ($inv['items_detail'] as $it) {
        $svcInfo = $serviceTypes[$it['service_type']] ?? ['label' => $it['service_type'], 'icon' => '🔹'];
        $itemsForJs[] = [
            'icon'        => $svcInfo['icon'] ?? '🔹',
            'label'       => $svcInfo['label'] ?? $it['service_type'],
            'description' => $it['description'],
            'quantity'    => (float)$it['quantity'],
            'unit_price'  => (float)$it['unit_price'],
            'total_price' => (float)$it['total_price'],
        ];
    }
    $hsBalDue = max(0, (float)$inv['total'] - (float)$inv['paid_amount']);
    $hsDetailsForJs[$inv['id']] = [
        'invoice_number' => $inv['invoice_number'],
        'guest_name'     => $inv['guest_name'],
        'guest_phone'    => $inv['guest_phone'],
        'room_number'    => $inv['room_number'],
        'inhouse'        => !empty($inv['booking_id']) && isset($inhouseBookingIds[$inv['booking_id']]),
        'date'           => date('d M Y, H:i', strtotime($inv['service_date'] ?? $inv['created_at'])),
        'status'         => $inv['status'],
        'payment_status' => $inv['payment_status'],
        'discount_amount' => (float)$inv['discount_amount'],
        'discount_rate'  => (float)$inv['discount_rate'],
        'tax_amount'     => (float)$inv['tax_amount'],
        'service_charge_amount' => (float)$inv['service_charge_amount'],
        'total'          => (float)$inv['total'],
        'paid_amount'    => (float)$inv['paid_amount'],
        'balance_due'    => $hsBalDue,
        'items'          => $itemsForJs,
    ];
}

// Stats — today totals
$stats = $pdo->prepare("SELECT COUNT(*) as total,
    COALESCE(SUM(total),0) as revenue, COALESCE(SUM(paid_amount),0) as collected,
    SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN payment_status='unpaid' THEN 1 ELSE 0 END) as unpaid
    FROM hotel_invoices WHERE business_id=? AND DATE(COALESCE(last_service_at, created_at))=CURDATE()");
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
// booking_id set for checked-in guests, used to flag invoice rows with a green "in-house" dot
$inhouseBookingIds = array_flip(array_filter(array_column($inHouseGuests, 'booking_id')));

try {
    $motorStmt = $pdo->prepare("SELECT id, plate_number, motor_name, daily_rate, partner_owner, owner_phone, owner_commission_pct, driver_daily_rate FROM rental_motors WHERE business_id=? AND status='available' ORDER BY motor_name ASC, plate_number ASC");
    $motorStmt->execute([$businessId]);
    $availableMotors = $motorStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $availableMotors = [];
}

try {
    $carStmt = $pdo->prepare("SELECT id, plate_number, car_name, car_type, daily_rate, driver_daily_rate, partner_owner, owner_commission_pct, commission_type, commission_nominal FROM rental_cars WHERE business_id=? AND status='available' ORDER BY car_name ASC, plate_number ASC");
    $carStmt->execute([$businessId]);
    $availableCars = $carStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $availableCars = [];
}

try {
    $partnerStmt = $pdo->prepare("SELECT id, partner_name, category, phone, is_active FROM hotel_service_partners WHERE business_id=? ORDER BY category, partner_name");
    $partnerStmt->execute([$businessId]);
    $hsPartners = $partnerStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $hsPartners = [];
}

try {
    $guideStmt = $pdo->prepare("SELECT id, guide_name, phone, sort_order FROM narayana_trip_guides WHERE business_id=? AND is_active=1 ORDER BY sort_order, guide_name");
    $guideStmt->execute([$businessId]);
    $tripGuides = $guideStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $tripGuides = [];
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

        $usedMotorBk = [];
        foreach ($gRow['items'] as &$gItem) {
            if (($gItem['service_type'] ?? '') === 'narayana_trip') {
                if (!empty($gItem['trip_type'])) {
                    $gItem['trip_type'] = (string)$gItem['trip_type'];
                }
                if (!empty($gItem['guide_id'])) {
                    $gItem['guide_id'] = (int)$gItem['guide_id'];
                }
            }
            if (($gItem['service_type'] ?? '') === 'motor_rental') {
                foreach ($motorRentals as $mr) {
                    if (!empty($usedMotorBk[(int)$mr['id']])) continue;
                    if (strpos((string)($gItem['description'] ?? ''), (string)$mr['plate_number']) !== false) {
                        $usedMotorBk[(int)$mr['id']] = true;
                        $gItem['motor_id'] = (int)$mr['motor_id'];
                        $gItem['motor_name'] = $mr['motor_name'];
                        $gItem['plate_number'] = $mr['plate_number'];
                        $gItem['daily_rate'] = (float)$mr['daily_rate'];
                        $gItem['start_dt'] = $mr['start_datetime'];
                        $gItem['end_dt'] = $mr['end_datetime'];
                        $gItem['deposit'] = (float)$mr['deposit'];
                        // Calculate rental days
                        $start = new DateTime($mr['start_datetime']);
                        $end = new DateTime($mr['end_datetime']);
                        $interval = $start->diff($end);
                        $gItem['rental_days'] = max(1, (int)$interval->days) ?: 1;
                        break;
                    }
                }
            }
            if (($gItem['service_type'] ?? '') === 'car_rental') {
                foreach ($carRentals as $cr) {
                    if (strpos((string)($gItem['description'] ?? ''), (string)$cr['plate_number']) !== false) {
                        $gItem['car_id'] = (int)$cr['car_id'];
                        $gItem['car_name'] = $cr['car_name'];
                        $gItem['plate_number'] = $cr['plate_number'];
                        $gItem['car_type'] = $cr['car_type'] ?? '';
                        $gItem['daily_rate'] = (float)$cr['daily_rate'];
                        $gItem['start_dt'] = $cr['start_datetime'];
                        $gItem['end_dt'] = $cr['end_datetime'];
                        $gItem['deposit'] = (float)$cr['deposit'];
                        $gItem['trip_destination'] = $cr['trip_destination'];
                        $gItem['needs_driver_payment'] = (int)($cr['needs_driver_payment'] ?? 0);
                        $gItem['commission_type'] = $cr['commission_type'] ?? 'percent';
                        $gItem['commission_value'] = (float)($cr['commission_value'] ?? 0);
                        // Calculate rental days
                        $start = new DateTime($cr['start_datetime']);
                        $end = new DateTime($cr['end_datetime']);
                        $interval = $start->diff($end);
                        $gItem['rental_days'] = max(1, (int)$interval->days) ?: 1;
                        break;
                    }
                }
            }
            if (in_array($gItem['service_type'] ?? '', ['airport_drop', 'harbor_drop'], true)) {
                foreach ($carRentals as $cr) {
                    if ($cr['plate_number'] && strpos((string)($gItem['description'] ?? ''), (string)$cr['plate_number']) !== false) {
                        $gItem['car_id'] = (int)$cr['car_id'];
                        $gItem['car_name'] = $cr['car_name'];
                        $gItem['plate_number'] = $cr['plate_number'];
                        $gItem['trip_destination'] = $cr['trip_destination'];
                        $gItem['needs_driver_payment'] = (int)($cr['needs_driver_payment'] ?? 0);
                        $gItem['commission_type'] = $cr['commission_type'] ?? 'percent';
                        $gItem['commission_value'] = (float)($cr['commission_value'] ?? 0);
                        break;
                    }
                }
            }
        }
        unset($gItem);
        // Item motor yang tidak memuat plat (data lama): pasangkan dengan booking motor yang tersisa berurutan.
        foreach ($gRow['items'] as $gi => $gIt) {
            if (($gIt['service_type'] ?? '') !== 'motor_rental' || !empty($gIt['motor_id'])) continue;
            foreach ($motorRentals as $mr) {
                if (!empty($usedMotorBk[(int)$mr['id']]) || ($mr['status'] ?? '') === 'cancelled') continue;
                $usedMotorBk[(int)$mr['id']] = true;
                $start = new DateTime($mr['start_datetime']);
                $end = new DateTime($mr['end_datetime']);
                $gRow['items'][$gi] = array_merge($gIt, [
                    'motor_id' => (int)$mr['motor_id'],
                    'motor_name' => $mr['motor_name'],
                    'plate_number' => $mr['plate_number'],
                    'daily_rate' => (float)$mr['daily_rate'],
                    'start_dt' => $mr['start_datetime'],
                    'end_dt' => $mr['end_datetime'],
                    'deposit' => (float)$mr['deposit'],
                    'rental_days' => max(1, (int)$start->diff($end)->days),
                ]);
                break;
            }
        }
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
<!-- HS-VERSION:20260729-v3-split -->
<style>
    .hs-page {
        max-width: 100%;
        margin: 0;
        padding: 0.65rem 0.5rem 1rem;
    }

    .hs-topbar {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        margin-bottom: 0.85rem;
        flex-wrap: wrap;
        gap: 0.6rem;
        background: #ffffff;
        border: 1px solid #e8edf5;
        border-radius: 12px;
        padding: 0.7rem 0.9rem;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
    }

    .hs-head-main {
        min-width: 0;
    }

    .hs-topbar h2 {
        font-size: 0.98rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0;
        letter-spacing: 0.01em;
    }

    .hs-topmeta {
        margin-top: 0.2rem;
        font-size: 0.75rem;
        color: #64748b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .hs-top-actions {
        display: flex;
        gap: 0.45rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .hs-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
        gap: 0.55rem;
        margin-bottom: 0.65rem;
    }

    .hs-stat {
        background: white;
        border-radius: 8px;
        padding: 0.5rem 0.65rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
        border-top: 2px solid var(--c);
        border: 1px solid #eef2f7;
    }

    .hs-stat .val {
        font-size: 0.88rem;
        font-weight: 800;
        color: var(--c);
        line-height: 1.2;
    }

    .hs-stat .lbl {
        font-size: 0.66rem;
        color: var(--text-secondary);
        margin-top: 0.1rem;
    }

    .hs-filters {
        background: white;
        border-radius: 10px;
        padding: 0.7rem 0.85rem;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        margin-bottom: 1rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem;
        align-items: center;
        border: 1px solid #eef2f7;
    }

    .hs-filters input,
    .hs-filters select {
        padding: 0.38rem 0.55rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.875rem;
        background: white;
        color: var(--text-primary);
    }

    .hs-table-wrap {
        background: white;
        border-radius: 10px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        overflow: hidden;
        border: 1px solid #eef2f7;
    }

    .hs-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.76rem;
    }

    .hs-table th {
        background: rgba(30, 58, 138, 0.08);
        padding: 0.4rem 0.45rem;
        text-align: center;
        font-weight: 700;
        color: #1e3a8a;
        font-size: 0.64rem;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        border-bottom: 1px solid rgba(30, 58, 138, 0.15);
    }

    .hs-table td {
        padding: 0.4rem 0.45rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    .hs-table tr:last-child td {
        border-bottom: none;
    }

    .hs-table tr:hover td {
        background: #fafbff;
    }

    .hs-row-clickable {
        cursor: pointer;
    }

    .hs-inhouse-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #22c55e;
        box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.25);
        flex-shrink: 0;
    }

    /* Invoice detail popup */
    .hs-detail-modal {
        max-width: 460px;
    }

    .hs-detail-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-bottom: 0.15rem;
    }

    .hs-detail-head h3 {
        margin: 0;
        font-size: 1.02rem;
        color: #1e3a8a;
    }

    .hs-detail-sub {
        font-size: 0.78rem;
        color: var(--text-secondary);
        margin-bottom: 0.85rem;
    }

    .hs-detail-items {
        border: 1px solid #eef2f7;
        border-radius: 8px;
        overflow: hidden;
        margin-bottom: 0.85rem;
    }

    .hs-detail-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.6rem;
        padding: 0.55rem 0.75rem;
        border-bottom: 1px solid #f1f5f9;
        font-size: 0.82rem;
    }

    .hs-detail-item:last-child {
        border-bottom: none;
    }

    .hs-detail-item-name {
        font-weight: 600;
        color: #1e293b;
    }

    .hs-detail-item-desc {
        font-size: 0.7rem;
        color: var(--text-secondary);
        margin-top: 1px;
    }

    .hs-detail-item-amt {
        text-align: right;
        white-space: nowrap;
        font-weight: 700;
        color: #1e293b;
    }

    .hs-detail-item-qty {
        font-size: 0.68rem;
        color: var(--text-secondary);
        font-weight: 400;
    }

    .hs-detail-totals {
        background: rgba(30, 58, 138, 0.04);
        border-radius: 8px;
        padding: 0.65rem 0.85rem;
    }

    .hs-detail-total-row {
        display: flex;
        justify-content: space-between;
        font-size: 0.82rem;
        padding: 0.15rem 0;
        color: #475569;
    }

    .hs-detail-total-row.grand {
        border-top: 1px solid rgba(30, 58, 138, 0.15);
        margin-top: 0.3rem;
        padding-top: 0.45rem;
        font-size: 0.95rem;
        font-weight: 800;
        color: #1e3a8a;
    }

    .hs-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 62px;
        padding: 0.2rem 0.5rem;
        border-radius: 999px;
        font-size: 0.58rem;
        font-weight: 700;
        letter-spacing: 0.04em;
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
        padding: 0.1rem 0.35rem;
        border-radius: 10px;
        font-size: 0.64rem;
        font-weight: 600;
        background: #ede9fe;
        color: #5b21b6;
        margin: 0.08rem 0.08rem 0 0;
        white-space: nowrap;
    }

    .hs-rental-extra {
        display: none;
        flex-direction: column;
        gap: 0.5rem;
        margin-top: 0.5rem;
        padding-top: 0.5rem;
        border-top: 1px dashed #e2e8f0;
    }

    .hs-rental-extra.open {
        display: flex;
    }

    .hs-rental-row1 {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        align-items: flex-end;
    }

    .hs-dest-wrap {
        display: none;
    }

    .hs-dest-wrap span {
        display: block;
        font-size: 0.68rem;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        margin-bottom: 3px;
    }

    .hs-dest-wrap input {
        width: 100%;
        padding: 0.35rem 0.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.8rem;
        background: #fff;
        box-sizing: border-box;
    }

    .hs-action-btn {
        padding: 0.25rem 0.55rem;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-size: 0.75rem;
        font-weight: 600;
        transition: opacity 0.2s;
    }

    .hs-action-btn:hover {
        opacity: 0.8;
    }

    .hs-room-badge {
        display: inline-block;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
        padding: 0.15rem 0.4rem;
        border-radius: 5px;
        font-weight: 700;
        font-size: 0.64rem;
        white-space: nowrap;
    }

    .hs-price-breakdown {
        display: flex;
        flex-direction: column;
        gap: 0.12rem;
        font-size: 0.62rem;
        white-space: nowrap;
    }

    .hs-price-row {
        display: flex;
        justify-content: space-between;
        gap: 0.6rem;
        color: var(--text-secondary);
    }

    .hs-price-row.hs-price-disc {
        color: #dc2626;
    }

    .hs-price-row.hs-price-total {
        font-weight: 700;
        color: #059669;
        border-top: 1px dashed #e2e8f0;
        padding-top: 0.15rem;
        margin-top: 0.1rem;
    }

    /* Actions dropdown */
    .hs-action-dropdown {
        position: relative;
        display: inline-block;
    }

    .hs-action-dropdown-btn {
        padding: 0.18rem 0.4rem;
        background: #6366f1;
        color: white;
        border: 1px solid #4f46e5;
        border-radius: 5px;
        cursor: pointer;
        font-size: 0.6rem;
        font-weight: 600;
        white-space: nowrap;
        transition: background 0.15s;
    }

    .hs-action-dropdown-btn:hover {
        background: #4f46e5;
    }

    .hs-action-dropdown-menu {
        display: none;
        position: fixed;
        background: var(--card-bg, #fff);
        border: 1px solid rgba(99, 102, 241, 0.15);
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        min-width: 180px;
        z-index: 9999;
        overflow: hidden;
    }

    .hs-action-dropdown.open .hs-action-dropdown-menu {
        display: block;
    }

    .hs-action-dropdown-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        width: 100%;
        padding: 0.55rem 0.85rem;
        border: none;
        background: none;
        cursor: pointer;
        font-size: 0.78rem;
        color: var(--text-primary, #1e293b);
        text-align: left;
        text-decoration: none;
        transition: background 0.1s;
        white-space: nowrap;
        box-sizing: border-box;
    }

    .hs-action-dropdown-item:hover {
        background: rgba(99, 102, 241, 0.08);
    }

    .hs-action-dropdown-item.hs-item-pay {
        color: #15803d;
        font-weight: 600;
    }

    .hs-action-dropdown-item.hs-item-delete {
        color: #dc2626;
    }

    .hs-action-dropdown-divider {
        height: 1px;
        background: rgba(0, 0, 0, 0.08);
        margin: 2px 0;
    }

    .hs-action-dropdown-status {
        padding: 0.4rem 0.85rem 0.55rem;
    }

    .hs-action-dropdown-status span {
        display: block;
        font-size: 0.68rem;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        margin-bottom: 3px;
    }

    .hs-action-dropdown-status select {
        width: 100%;
        padding: 0.35rem 0.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.8rem;
        background: #fff;
        box-sizing: border-box;
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
        font-size: 0.95rem;
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
        font-size: 0.875rem;
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
        font-size: 0.875rem;
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
        font-size: 0.875rem;
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

    /* Item cards */
    .hs-items-wrap {
        margin-bottom: 0.5rem;
    }

    .hs-item-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 10px 12px;
        margin-bottom: 8px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    }

    .hs-ic-top {
        display: flex;
        gap: 6px;
        align-items: center;
        margin-bottom: 6px;
    }

    .hs-ic-top .iSvc {
        flex: 0 0 auto;
        min-width: 145px;
        max-width: 175px;
    }

    .hs-ic-top .iDesc {
        flex: 1;
        min-width: 0;
    }

    .hs-ic-top input,
    .hs-ic-top select {
        padding: 0.38rem 0.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.875rem;
        background: white;
        box-sizing: border-box;
    }

    .hs-ic-top input:focus,
    .hs-ic-top select:focus {
        outline: none;
        border-color: #6366f1;
    }

    .hs-ic-labeled {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .hs-ic-labeled>span {
        font-size: 0.75rem;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        white-space: nowrap;
    }

    .hs-ic-labeled input,
    .hs-ic-labeled select {
        padding: 0.35rem 0.45rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.875rem;
        background: white;
        box-sizing: border-box;
    }

    .hs-ic-labeled input:focus,
    .hs-ic-labeled select:focus {
        outline: none;
        border-color: #6366f1;
    }

    .hs-rental-row1 .iAsset {
        min-width: 155px;
    }

    .hs-rental-row1 .iDays {
        width: 65px;
    }

    .hs-rental-row1 .iDeposit {
        width: 105px;
    }

    .hs-ic-nums {
        display: flex;
        gap: 8px;
        align-items: flex-end;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px solid #f1f5f9;
        flex-wrap: wrap;
    }

    .hs-ic-nums .iQty {
        width: 65px;
    }

    .hs-ic-nums .iPrice {
        width: 120px;
    }

    .hs-ic-subtotal {
        display: flex;
        flex-direction: column;
        gap: 3px;
        margin-left: auto;
        text-align: right;
    }

    .hs-ic-subtotal>span {
        font-size: 0.67rem;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
    }

    .hs-ic-subtotal .iTotal {
        font-size: 0.9rem;
        font-weight: 700;
        color: #4338ca;
        white-space: nowrap;
    }

    .hs-driver-extra {
        display: none;
        flex-wrap: wrap;
        gap: 8px;
        align-items: flex-end;
        margin-top: 8px;
        padding: 8px 10px;
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 6px;
    }

    .hs-driver-extra .iDriverCarRow {
        width: 100%;
        margin-bottom: 2px;
    }

    .hs-driver-chk {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 0.875rem;
        color: #334155;
        cursor: pointer;
        white-space: nowrap;
    }

    .iCommWrap {
        display: flex;
        gap: 6px;
        align-items: center;
    }

    .iCommWrap select,
    .iCommWrap input {
        padding: 0.32rem 0.45rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.875rem;
        background: white;
    }

    .iCommWrap select {
        min-width: 150px;
    }

    .iCommWrap input {
        width: 80px;
    }

    .btn-add-item {
        background: #f0f4ff;
        color: #4338ca;
        border: 1px dashed #6366f1;
        border-radius: 7px;
        padding: 0.4rem 0.8rem;
        font-size: 0.875rem;
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
        font-size: 0.875rem;
        font-weight: 700;
    }

    .hs-total-preview {
        background: linear-gradient(135deg, #f0f4ff, #e8edff);
        border-radius: 8px;
        padding: 0.75rem 1rem;
        text-align: center;
        margin: 0.75rem 0;
        font-size: 0.95rem;
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
        font-size: 0.875rem;
    }

    .btn-hs-primary,
    .btn-hs-primary * {
        background: var(--primary, #6366f1);
        color: #ffffff !important;
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
        font-size: 2rem;
        margin-bottom: 0.5rem;
    }

    .sect-label {
        font-size: 0.875rem;
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
        font-size: 0.875rem;
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


    /* ===== Modal Pengaturan Hotel Services (redesign) ===== */
    #settingsModal .hss-modal {
        max-width: 960px !important;
        width: 100%;
        padding: 0 !important;
        border-radius: 16px !important;
        overflow: hidden !important;
        display: flex;
        flex-direction: column;
        max-height: 90vh !important;
    }
    #settingsModal .hss-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 14px 20px;
        background: linear-gradient(135deg, #1e3a8a, #2563eb);
    }
    body[data-theme] #settingsModal .hss-head h3 {
        margin: 0 !important;
        font-size: 0.98rem !important;
        font-weight: 700 !important;
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
    }
    body[data-theme] #settingsModal .hss-head small {
        display: block;
        font-size: 0.68rem !important;
        color: rgba(255, 255, 255, 0.8) !important;
        -webkit-text-fill-color: rgba(255, 255, 255, 0.8) !important;
    }
    body[data-theme] #settingsModal .hss-close {
        width: 32px;
        height: 32px;
        border-radius: 10px;
        border: 1px solid rgba(255, 255, 255, 0.3);
        background: rgba(255, 255, 255, 0.12);
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
        font-size: 1.2rem;
        line-height: 1;
        cursor: pointer;
    }
    #settingsModal .hss-tabs {
        display: flex;
        gap: 4px;
        margin: 12px 20px 0 !important;
        padding: 4px;
        border: 1px solid #e2e8f0 !important;
        border-radius: 12px;
        background: #f8fafc;
        overflow-x: auto;
    }
    body[data-theme] #settingsModal .hss-tabs .hs-tab {
        flex: 1;
        margin: 0 !important;
        padding: 7px 12px !important;
        border: 0 !important;
        border-radius: 9px;
        font-size: 0.76rem !important;
        font-weight: 700 !important;
        color: #64748b !important;
        white-space: nowrap;
        background: transparent;
    }
    body[data-theme] #settingsModal .hss-tabs .hs-tab.active {
        background: #fff;
        color: #1d4ed8 !important;
        box-shadow: 0 2px 8px -2px rgba(15, 23, 42, 0.18);
    }
    #settingsModal .hss-body {
        padding: 14px 20px 18px;
        overflow-y: auto;
        min-height: 0;
    }
    #settingsModal .hss-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
    }
    body[data-theme] #settingsModal .hss-bar span {
        font-size: 0.72rem !important;
        color: #64748b !important;
        line-height: 1.4;
    }
    body[data-theme] #settingsModal .hss-add {
        flex-shrink: 0;
        height: 32px;
        padding: 0 14px;
        border: 0;
        border-radius: 9px;
        background: linear-gradient(135deg, #1e3a8a, #2563eb);
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
        font-size: 0.74rem !important;
        font-weight: 700;
        cursor: pointer;
    }
    #settingsModal .hss-tbl-wrap {
        max-height: 56vh;
        overflow: auto;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
    }
    body[data-theme] #settingsModal .hss-tbl { font-size: 0.78rem !important; }
    body[data-theme] #settingsModal .hss-tbl th {
        position: sticky;
        top: 0;
        z-index: 1;
        padding: 8px 8px !important;
        background: #1e3a8a !important;
        border-bottom: 0 !important;
        font-size: 0.62rem !important;
        font-weight: 700 !important;
        letter-spacing: 0.06em;
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
    }
    body[data-theme] #settingsModal .hss-tbl td {
        padding: 5px 6px !important;
        border-bottom: 1px solid #f1f5f9 !important;
    }
    body[data-theme] #settingsModal .hss-tbl tr:last-child td { border-bottom: 0 !important; }
    body[data-theme] #settingsModal .hss-tbl td input[type="text"],
    body[data-theme] #settingsModal .hss-tbl td input[type="number"],
    body[data-theme] #settingsModal .hss-tbl td select {
        height: 30px;
        padding: 0 8px !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 8px !important;
        background: #fff !important;
        font-size: 0.76rem !important;
        color: #0f172a !important;
    }
    body[data-theme] #settingsModal .hss-tbl td input:focus,
    body[data-theme] #settingsModal .hss-tbl td select:focus {
        outline: none;
        border-color: #2563eb !important;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    #settingsModal .hss-tbl .r { text-align: right; }
    #settingsModal .hss-tbl .c { text-align: center; }
    #settingsModal .hss-tbl input.r { text-align: right; }
    #settingsModal .hss-tbl input.c { text-align: center; }
    #settingsModal .hss-tbl input[type="checkbox"] { width: 16px; height: 16px; accent-color: #2563eb; }
    body[data-theme] #settingsModal .cProfit { font-weight: 700; font-variant-numeric: tabular-nums; color: #047857 !important; }
    body[data-theme] #settingsModal .cProfit.neg { color: #b91c1c !important; }
    #settingsModal .hss-act { white-space: nowrap; text-align: right; }
    body[data-theme] #settingsModal .hss-act button {
        width: 28px;
        height: 28px;
        padding: 0 !important;
        border-radius: 8px !important;
        font-size: 0.8rem !important;
        font-weight: 800;
    }
    body[data-theme] #settingsModal .hss-empty {
        padding: 18px;
        text-align: center;
        font-size: 0.74rem;
        color: #94a3b8 !important;
    }
    #settingsModal .hss-tbl tr.saved td { background: #f0fdf4 !important; transition: background 0.3s; }
    /* Tab Invoice & Perusahaan */
    body[data-theme] #settingsModal #pane-inv .hs-field label {
        font-size: 0.62rem !important;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #64748b !important;
    }
    body[data-theme] #settingsModal #pane-inv .hs-field input,
    body[data-theme] #settingsModal #pane-inv .hs-field textarea {
        border-radius: 9px !important;
        font-size: 0.8rem !important;
    }

    /* ===== Kartu item invoice: Kategori → Tipe → Item → Mitra ===== */
    .hs-item-card.hs-v3 .hs-driver-extra,
    .hs-item-card.hs-v3 .hs-paket-wrap { display: none !important; }
    .hs-item-card.hs-v3 .hs-ic-top { display: grid !important; grid-template-columns: 120px 1fr 1.3fr 30px; gap: 6px; align-items: end; }
    .hs-item-card.hs-v3 .hs-ic-top > .iDesc { grid-column: 1 / -1; order: 9; }
    .hs-item-card.hs-v3 .hs-ic-top .hs-v3-f { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
    body[data-theme] .hs-item-card.hs-v3 .hs-v3-f > span,
    body[data-theme] .hs-item-card.hs-v3 .hs-partner-extra .hs-v3-f > span {
        font-size: 0.6rem !important;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #64748b !important;
    }
    .hs-item-card.hs-v3 .hs-ic-top select,
    .hs-item-card.hs-v3 .hs-ic-top input,
    .hs-item-card.hs-v3 .hs-partner-extra select,
    .hs-item-card.hs-v3 .hs-partner-extra input {
        width: 100%;
        height: 34px;
        box-sizing: border-box;
    }
    .hs-item-card.hs-v3 .hs-ic-top .btn-del-row { height: 34px; }
    .hs-item-card.hs-v3 .hs-partner-extra {
        display: none;
        grid-template-columns: 1.4fr 1fr 1fr;
        gap: 6px;
        margin-top: 8px;
        padding: 8px 10px;
        border-radius: 10px;
        border: 1px solid rgba(37, 99, 235, 0.22);
        background: rgba(37, 99, 235, 0.05);
    }
    .hs-item-card.hs-v3 .hs-partner-extra.open { display: grid; }
    .hs-item-card.hs-v3 .hs-partner-extra .hs-v3-f { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
    body[data-theme] .hs-item-card.hs-v3 .hs-partner-profit {
        display: flex;
        align-items: center;
        height: 34px;
        padding: 0 10px;
        border-radius: 8px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        font-weight: 800;
        font-size: 0.82rem !important;
        color: #047857 !important;
        font-variant-numeric: tabular-nums;
    }
    body[data-theme] .hs-item-card.hs-v3 .hs-partner-profit.neg { color: #b91c1c !important; }
    body[data-theme="dark"] .hs-item-card.hs-v3 .hs-partner-profit { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.1); color: #6ee7b7 !important; }
    .hs-item-card.hs-v3 .iPartner.need { border-color: #dc2626 !important; box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15); }
    @media (max-width: 640px) {
        .hs-item-card.hs-v3 .hs-ic-top { grid-template-columns: 1fr 1fr 30px; }
        .hs-item-card.hs-v3 .hs-ic-top .hs-v3-item { grid-column: 1 / -1; }
        .hs-item-card.hs-v3 .hs-partner-extra { grid-template-columns: 1fr 1fr; }
        .hs-item-card.hs-v3 .hs-partner-extra .hs-v3-f:first-child { grid-column: 1 / -1; }
    }

    /* ===== Form New / Edit Invoice (redesign, seragam dengan New Reservation) ===== */
    .hsf-modal {
        --hsf-line: #e2e8f0;
        --hsf-soft: #f8fafc;
        --hsf-ink: #0f172a;
        --hsf-muted: #64748b;
        max-width: 720px !important;
        padding: 0 1.25rem 0 !important;
        border-radius: 16px !important;
    }
    .hsf-modal .hsf-head {
        position: sticky;
        top: 0;
        z-index: 6;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin: 0 -1.25rem 12px;
        padding: 14px 20px;
        background: linear-gradient(135deg, #1e3a8a, #2563eb);
    }
    body[data-theme] .hsf-modal .hsf-head h3 {
        margin: 0 !important;
        font-size: 0.98rem !important;
        font-weight: 700 !important;
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
    }
    body[data-theme] .hsf-modal .hsf-head small {
        display: block;
        font-size: 0.68rem !important;
        color: rgba(255, 255, 255, 0.8) !important;
        -webkit-text-fill-color: rgba(255, 255, 255, 0.8) !important;
    }
    body[data-theme] .hsf-modal .hsf-close {
        width: 32px;
        height: 32px;
        border-radius: 10px;
        border: 1px solid rgba(255, 255, 255, 0.3);
        background: rgba(255, 255, 255, 0.12);
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
        font-size: 1.2rem;
        line-height: 1;
        cursor: pointer;
    }
    /* Judul bagian: huruf kecil kapital + garis */
    body[data-theme] .hsf-modal .sect-label {
        display: flex !important;
        align-items: center;
        gap: 8px;
        margin: 14px 0 8px !important;
        padding: 0 !important;
        border: 0 !important;
        background: none !important;
        font-size: 0.62rem !important;
        font-weight: 700 !important;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--hsf-muted) !important;
    }
    .hsf-modal .sect-label::after { content: ''; flex: 1; height: 1px; background: var(--hsf-line); }
    body[data-theme] .hsf-modal .hs-field label {
        margin-bottom: 4px !important;
        font-size: 0.62rem !important;
        font-weight: 700 !important;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--hsf-muted) !important;
    }
    body[data-theme] .hsf-modal input[type="text"],
    body[data-theme] .hsf-modal input[type="number"],
    body[data-theme] .hsf-modal select,
    body[data-theme] .hsf-modal textarea {
        min-height: 36px;
        padding: 0 10px !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 9px !important;
        background: #fff !important;
        font-size: 0.8rem !important;
        color: var(--hsf-ink) !important;
        box-sizing: border-box;
    }
    body[data-theme] .hsf-modal textarea { padding: 8px 10px !important; min-height: 60px; }
    body[data-theme] .hsf-modal input:focus,
    body[data-theme] .hsf-modal select:focus,
    body[data-theme] .hsf-modal textarea:focus {
        outline: none;
        border-color: #2563eb !important;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15) !important;
    }
    .hsf-modal .hs-form-row { gap: 10px !important; margin-bottom: 10px !important; }
    /* Pilihan tamu: segmented */
    body[data-theme] .hsf-modal .guest-toggle {
        display: grid !important;
        grid-template-columns: 1fr 1fr;
        gap: 4px;
        margin-bottom: 8px;
        padding: 4px;
        border-radius: 12px;
        border: 1px solid var(--hsf-line);
        background: var(--hsf-soft);
    }
    body[data-theme] .hsf-modal .guest-toggle button {
        height: 32px;
        border: 0 !important;
        border-radius: 9px !important;
        background: transparent !important;
        font-size: 0.76rem !important;
        font-weight: 700 !important;
        color: var(--hsf-muted) !important;
    }
    body[data-theme] .hsf-modal .guest-toggle button.active {
        background: #fff !important;
        color: #1d4ed8 !important;
        box-shadow: 0 2px 8px -2px rgba(15, 23, 42, 0.18);
    }
    /* Kartu item */
    body[data-theme] .hsf-modal .hs-item-card {
        padding: 12px !important;
        border: 1px solid var(--hsf-line) !important;
        border-radius: 12px !important;
        background: #fff !important;
        box-shadow: 0 6px 16px -14px rgba(15, 23, 42, 0.5);
    }
    body[data-theme] .hsf-modal .hs-item-card .hs-ic-labeled > span,
    body[data-theme] .hsf-modal .hs-item-card .hs-ic-subtotal > span,
    body[data-theme] .hsf-modal .hs-item-card .iQtyLabel {
        font-size: 0.6rem !important;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--hsf-muted) !important;
    }
    body[data-theme] .hsf-modal .hs-item-card .iTotal { font-size: 0.95rem !important; color: #1d4ed8 !important; }
    body[data-theme] .hsf-modal .btn-del-row {
        width: 34px;
        border-radius: 9px !important;
        border: 1px solid rgba(220, 38, 38, 0.25) !important;
        background: rgba(220, 38, 38, 0.06) !important;
        color: #b91c1c !important;
    }
    body[data-theme] .hsf-modal .btn-add-item {
        height: 36px;
        margin-top: 6px !important;
        border-radius: 10px !important;
        border: 1px dashed #93c5fd !important;
        background: rgba(37, 99, 235, 0.05) !important;
        color: #1d4ed8 !important;
        font-size: 0.78rem !important;
        font-weight: 700 !important;
    }
    /* Ringkasan total */
    body[data-theme] .hsf-modal .hs-total-preview {
        margin-top: 12px !important;
        padding: 12px 14px !important;
        border-radius: 12px !important;
        border: 1px solid var(--hsf-line) !important;
        background: var(--hsf-soft) !important;
    }
    body[data-theme] .hsf-modal .hs-total-preview > div { font-size: 0.78rem !important; }
    body[data-theme] .hsf-modal .hs-total-preview #tpGrand,
    body[data-theme] .hsf-modal .hs-total-preview #etpGrand { color: #047857 !important; font-size: 1.15rem !important; }
    /* Footer menempel di bawah */
    .hsf-modal .hs-modal-footer {
        position: sticky;
        bottom: 0;
        z-index: 6;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        margin: 14px -1.25rem 0 !important;
        padding: 12px 20px !important;
        border-top: 1px solid var(--hsf-line);
        background: var(--hsf-soft);
    }
    body[data-theme] .hsf-modal .hs-modal-footer .btn-hs {
        height: 38px;
        padding: 0 18px !important;
        border-radius: 10px !important;
        font-size: 0.8rem !important;
        font-weight: 700 !important;
    }
    body[data-theme] .hsf-modal .hs-modal-footer .btn-hs-secondary {
        border: 1px solid var(--hsf-line) !important;
        background: #fff !important;
        color: var(--hsf-muted) !important;
    }
    body[data-theme] .hsf-modal .hs-modal-footer .btn-hs-primary {
        border: 0 !important;
        background: linear-gradient(135deg, #1e3a8a, #2563eb) !important;
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
        box-shadow: 0 8px 18px -10px rgba(37, 99, 235, 0.8);
    }

    /* Mode Tambah Layanan */
    body[data-theme] .hsf-modal.hs-add-mode #hsCreateExtras,
    body[data-theme] .hsf-modal.hs-add-mode .guest-toggle,
    body[data-theme] .hsf-modal.hs-add-mode #inhouseSection,
    body[data-theme] .hsf-modal.hs-add-mode #manualSection,
    body[data-theme] .hsf-modal.hs-add-mode .hsf-guest-label { display: none !important; }
    body[data-theme] .hs-add-banner {
        margin: 4px 0 10px;
        padding: 10px 12px;
        border-radius: 12px;
        border: 1px solid rgba(37, 99, 235, 0.25);
        background: rgba(37, 99, 235, 0.06);
        font-size: 0.78rem !important;
        line-height: 1.5;
        color: #1e3a8a !important;
    }
    body[data-theme] .hs-add-banner b { color: #1e3a8a !important; }
    body[data-theme] .hs-action-dropdown-item.hs-item-addsvc { color: #1d4ed8 !important; font-weight: 700; }

    /* ===== Popup elegan (pengganti alert/confirm) ===== */
    .hs-pop {
        position: fixed;
        inset: 0;
        z-index: 200000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: rgba(15, 23, 42, 0.32);
        opacity: 0;
        transition: opacity 0.18s;
    }
    .hs-pop.show { opacity: 1; }
    .hs-pop-card {
        width: 100%;
        max-width: 340px;
        padding: 22px 22px 18px;
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 24px 60px -16px rgba(15, 23, 42, 0.45);
        text-align: center;
        transform: scale(0.88);
        transition: transform 0.25s cubic-bezier(.2, 1.3, .5, 1);
    }
    .hs-pop.show .hs-pop-card { transform: none; }
    .hs-pop-ic { display: block; width: 56px; height: 56px; margin: 0 auto 10px; }
    .hs-pop-ic path { fill: none; stroke: #fff; stroke-width: 4.5; stroke-linecap: round; stroke-linejoin: round; }
    .hs-pop-success .hs-pop-ic circle { fill: #059669; }
    .hs-pop-success .hs-pop-ic path { stroke-dasharray: 40; stroke-dashoffset: 40; animation: hsPopDraw 0.35s 0.12s ease-out forwards; }
    .hs-pop-error .hs-pop-ic circle { fill: #dc2626; }
    .hs-pop-warning .hs-pop-ic circle { fill: #d97706; }
    .hs-pop-info .hs-pop-ic circle { fill: #2563eb; }
    .hs-pop-confirm .hs-pop-ic circle { fill: #1e3a8a; }
    @keyframes hsPopDraw { to { stroke-dashoffset: 0; } }
    body[data-theme] .hs-pop .hs-pop-title {
        display: block;
        font-size: 0.98rem !important;
        font-weight: 800;
        color: #0f172a !important;
        -webkit-text-fill-color: #0f172a !important;
    }
    body[data-theme] .hs-pop .hs-pop-msg {
        margin: 6px 0 0 !important;
        font-size: 0.8rem !important;
        line-height: 1.5;
        color: #475569 !important;
        -webkit-text-fill-color: #475569 !important;
        white-space: pre-line;
    }
    .hs-pop-acts { display: flex; justify-content: center; gap: 8px; margin-top: 16px; }
    body[data-theme] .hs-pop .hs-pop-btn {
        min-width: 96px;
        height: 38px;
        padding: 0 16px;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        background: #fff;
        font-size: 0.8rem !important;
        font-weight: 700;
        color: #475569 !important;
        -webkit-text-fill-color: #475569 !important;
        cursor: pointer;
    }
    body[data-theme] .hs-pop .hs-pop-btn.primary {
        border: 0;
        background: linear-gradient(135deg, #1e3a8a, #2563eb);
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
    }
    body[data-theme] .hs-pop .hs-pop-btn.danger {
        border: 0;
        background: #dc2626;
        color: #fff !important;
        -webkit-text-fill-color: #fff !important;
    }
    /* Catalog table */
    .cat-tbl {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.875rem;
    }

    .cat-tbl th {
        background: #f8fafc;
        padding: 0.4rem 0.5rem;
        font-size: 0.75rem;
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
        font-size: 0.875rem;
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
        font-size: 0.875rem;
    }

    .cat-tbl .btn-cat-save {
        background: #dcfce7;
        color: #15803d;
        border: none;
        border-radius: 4px;
        padding: 0.25rem 0.5rem;
        cursor: pointer;
        font-size: 0.875rem;
    }

    .logo-preview {
        max-height: 60px;
        border-radius: 6px;
        margin-top: 0.4rem;
        display: block;
    }

    @media(max-width:580px) {
        .hs-page {
            padding: 0.5rem 0.55rem 0.75rem;
        }

        .hs-header-card {
            padding: 0.55rem 0.65rem;
        }

        .hs-topbar {
            grid-template-columns: 1fr;
        }

        .hs-top-actions {
            width: 100%;
            justify-content: flex-start;
        }

        .hs-form-row {
            grid-template-columns: 1fr;
        }

        .hs-stats {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    /* ===== Tabel invoice (rapi & presisi) ===== */
    .hs-table-wrap { overflow-x: auto; }
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 {
        table-layout: fixed;
        min-width: 1040px;
        border-collapse: separate;
        border-spacing: 0;
    }
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 th {
        padding: 10px 12px !important;
        background: #1e3a8a !important;
        border-bottom: 0 !important;
        font-size: 0.66rem !important;
        font-weight: 700 !important;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
        text-align: center;
        white-space: nowrap;
    }
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 td {
        height: 48px;
        padding: 8px 12px !important;
        border-bottom: 1px solid #f1f5f9 !important;
        font-size: 0.8rem !important;
        line-height: 1.35;
        color: #0f172a !important;
        vertical-align: middle;
    }
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 .l { text-align: left; }
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 .r { text-align: right; }
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 .c { text-align: center; }
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 tbody tr:hover td { background: #f8faff !important; }
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 td.hs-inv {
        font-weight: 700;
        font-size: 0.78rem !important;
        letter-spacing: 0.01em;
        color: #3730a3 !important;
        white-space: nowrap;
    }
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 thead tr th:first-child { border-top-left-radius: 10px; }
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 thead tr th:last-child { border-top-right-radius: 10px; }
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 tbody tr:nth-child(even) td { background: #fbfcfe; }
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 td.hs-phone {
        font-size: 0.76rem !important;
        font-variant-numeric: tabular-nums;
        color: #475569 !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    /* Jarak kolom Room – Service */
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 td:nth-child(5),
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 th:nth-child(5) { padding-left: 16px !important; }
    body[data-theme] .main-content .hs-v2 .hs-room-badge { display: inline-flex; padding: 3px 8px !important; font-size: 0.68rem !important; }
    /* Invoice dibatalkan: baris diredupkan */
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 tr.hs-row-cancelled td { opacity: 0.55; }
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 tr.hs-row-cancelled td:last-child,
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 tr.hs-row-cancelled td:nth-last-child(2) { opacity: 1; }
    .hs-v2 .hs-svc-one {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        max-width: 100%;
        padding: 3px 10px;
        border-radius: 999px;
        background: #eef2ff;
        border: 1px solid #e0e7ff;
    }
    body[data-theme] .main-content .hs-v2 .hs-svc-ic { flex-shrink: 0; font-size: 0.72rem !important; }
    body[data-theme] .main-content .hs-v2 .hs-svc-txt {
        min-width: 0;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        font-size: 0.72rem !important;
        font-weight: 600;
        color: #3730a3 !important;
    }
    .hs-v2 .hs-guest { display: flex; align-items: center; gap: 8px; min-width: 0; }
    body[data-theme] .main-content .hs-v2 .hs-guest-name {
        min-width: 0;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        font-size: 0.82rem !important;
        font-weight: 600;
        color: #0f172a !important;
    }
    body[data-theme] .main-content .hs-v2 .hs-guest-phone {
        flex-shrink: 0;
        padding: 2px 8px;
        border-radius: 999px;
        background: #f1f5f9;
        font-size: 0.7rem !important;
        font-variant-numeric: tabular-nums;
        color: #475569 !important;
        -webkit-text-fill-color: #475569 !important;
        white-space: nowrap;
    }
    .hs-v2 .hs-svcs { display: flex; flex-wrap: wrap; gap: 4px; }
    body[data-theme] .main-content .hs-v2 .hs-svc-pill {
        margin: 0 !important;
        padding: 3px 9px !important;
        border-radius: 999px !important;
        font-size: 0.7rem !important;
        white-space: nowrap;
    }
    body[data-theme] .main-content .hs-v2 .hs-num {
        font-variant-numeric: tabular-nums;
        font-weight: 600;
        white-space: nowrap;
    }
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 td.hs-paid { color: #047857 !important; }
    body[data-theme] .main-content .hs-v2 .hs-due { color: #b91c1c !important; font-weight: 700; }
    body[data-theme] .main-content .hs-v2 .hs-lunas {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 999px;
        background: #dcfce7;
        font-size: 0.7rem !important;
        font-weight: 700;
        color: #15803d !important;
        -webkit-text-fill-color: #15803d !important;
    }
    body[data-theme] .main-content .hs-v2 .hs-dash { color: #cbd5e1 !important; font-size: 0.75rem !important; }
    .hs-v2 .hs-stat { display: inline-flex; flex-direction: column; align-items: stretch; gap: 3px; padding: 0 !important; background: none !important; border: 0 !important; box-shadow: none !important; }
    body[data-theme] .main-content .hs-v2 .hs-stat .hs-badge { min-width: 84px; margin: 0 !important; }
    body[data-theme] .main-content .hs-v2 .hs-badge { justify-content: center; }
    body[data-theme] .main-content .hs-table-wrap table.hs-v2 td.hs-date {
        font-size: 0.76rem !important;
        color: #334155 !important;
        white-space: nowrap;
    }
    body[data-theme] .main-content .hs-v2 .hs-room-badge { min-width: 38px; justify-content: center; }

</style>

<style id="hsxStyle">
    /* Redesain halaman Hotel Services — seragam dengan tema sistem (biru navy), selektor ber-ID agar menang atas gaya lama */
    #hsx {
        --ink: #0f172a; --mute: #64748b; --faint: #94a3b8; --line: #e8edf3; --soft: #f8fafc; --card: #ffffff;
        --brand: #1e3a8a; --accent: #2563eb; --ok: #16a34a; --bad: #dc2626;
        --shadow: 0 1px 2px rgba(15,23,42,.04), 0 8px 22px -14px rgba(15,23,42,.18);
        max-width: 1600px; margin: 0 auto; padding: 1rem 1rem 1.5rem;
    }
    body[data-theme="dark"] #hsx {
        --ink: #f1f5f9; --mute: #94a3b8; --faint: #64748b; --line: rgba(148,163,184,.16); --soft: rgba(255,255,255,.03); --card: rgba(30,41,59,.72);
        --brand: #93c5fd; --accent: #60a5fa; --shadow: 0 12px 28px -16px rgba(0,0,0,.7);
    }
    /* Kartu kepala */
    #hsx .hs-header-card { background: var(--card) !important; border: 1px solid var(--line) !important; border-radius: 18px !important; box-shadow: var(--shadow) !important; padding: 1rem 1.1rem !important; margin-bottom: .8rem !important; }
    #hsx .hs-topbar { display: flex !important; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: .95rem !important; padding: 0 !important; background: none !important; border: 0 !important; }
    #hsx .hs-topbar h2 { margin: 0 !important; font-size: 1.15rem !important; font-weight: 800 !important; letter-spacing: -.01em; color: var(--ink) !important; -webkit-text-fill-color: var(--ink) !important; display: flex; align-items: center; gap: .55rem; }
    #hsx .hsx-logo { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; background: linear-gradient(135deg, #1e3a8a, #2563eb); box-shadow: 0 8px 16px -8px rgba(37,99,235,.7); flex-shrink: 0; }
    #hsx .hsx-logo svg { width: 18px; height: 18px; stroke: #fff !important; fill: none; }
    #hsx .hs-topmeta { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .5rem !important; font-size: 0 !important; }
    #hsx .hs-topmeta .hsx-chip { display: inline-flex; align-items: center; padding: .15rem .6rem; border-radius: 999px; font-size: .68rem !important; font-weight: 600; background: var(--soft); border: 1px solid var(--line); color: var(--mute) !important; -webkit-text-fill-color: var(--mute) !important; }
    #hsx .hs-top-actions { display: flex; gap: .5rem; align-items: center; }
    #hsx .btn-hs { height: 38px; padding: 0 1rem !important; border-radius: 11px !important; font-size: .8rem !important; font-weight: 700 !important; display: inline-flex; align-items: center; gap: .4rem; cursor: pointer; }
    #hsx .btn-hs-primary, body .btn-hs-primary { background: linear-gradient(135deg, #1e3a8a, #2563eb) !important; color: #fff !important; -webkit-text-fill-color: #fff !important; border: 0 !important; box-shadow: 0 8px 18px -10px rgba(37,99,235,.8) !important; }
    #hsx .btn-hs-primary:hover { filter: brightness(1.08); }
    #hsx .btn-hs-secondary { background: var(--card) !important; color: var(--ink) !important; -webkit-text-fill-color: var(--ink) !important; border: 1px solid var(--line) !important; box-shadow: none !important; }
    #hsx .btn-hs-secondary:hover { border-color: var(--accent) !important; }
    /* Statistik */
    #hsx .hs-stats { display: grid !important; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: .6rem; margin: 0 !important; padding: 0 !important; background: none !important; }
    #hsx .hs-stats .hs-stat { display: flex !important; flex-direction: column-reverse; justify-content: flex-end; gap: .15rem; padding: .7rem .85rem !important; border-radius: 13px !important; background: var(--soft) !important; border: 1px solid var(--line) !important; border-top: 3px solid var(--c, #2563eb) !important; box-shadow: none !important; text-align: left !important; }
    #hsx .hs-stats .hs-stat .val { font-size: 1.1rem !important; font-weight: 800 !important; letter-spacing: -.01em; color: var(--ink) !important; -webkit-text-fill-color: var(--ink) !important; }
    #hsx .hs-stats .hs-stat .lbl { font-size: .62rem !important; font-weight: 700 !important; text-transform: uppercase; letter-spacing: .06em; color: var(--mute) !important; -webkit-text-fill-color: var(--mute) !important; }
    /* Pendapatan per layanan */
    #hsx .hsx-rev { background: var(--card); border: 1px solid var(--line); border-radius: 16px; box-shadow: var(--shadow); padding: .8rem 1rem; margin-bottom: .8rem; }
    #hsx .hsx-rev-h { display: flex; align-items: center; justify-content: space-between; margin-bottom: .6rem; font-size: .66rem; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; color: var(--mute) !important; }
    #hsx .hsx-rev-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .55rem; }
    #hsx .hsx-rev-i { padding: .55rem .75rem; border-radius: 12px; background: var(--soft); border: 1px solid var(--line); border-left: 4px solid var(--c); }
    #hsx .hsx-rev-i .n { font-size: .7rem; font-weight: 700; color: var(--mute) !important; }
    #hsx .hsx-rev-i .v { margin-top: .1rem; font-size: .95rem; font-weight: 800; color: var(--ink) !important; }
    #hsx .hsx-rev-i .c { font-size: .62rem; color: var(--faint) !important; }
    /* Filter */
    #hsx .hs-filters { display: flex !important; flex-wrap: wrap; gap: .5rem; align-items: center; padding: .65rem .8rem !important; margin-bottom: .8rem !important; border-radius: 14px !important; background: var(--card) !important; border: 1px solid var(--line) !important; box-shadow: var(--shadow) !important; }
    #hsx .hs-filters input, #hsx .hs-filters select { height: 36px; padding: 0 .75rem !important; border-radius: 10px !important; border: 1px solid var(--line) !important; background: var(--soft) !important; color: var(--ink) !important; -webkit-text-fill-color: var(--ink) !important; font-size: .8rem !important; font-family: inherit; }
    #hsx .hs-filters input[type="text"] { flex: 1 1 220px; min-width: 180px; }
    #hsx .hs-filters input:focus, #hsx .hs-filters select:focus { outline: none; border-color: var(--accent) !important; box-shadow: 0 0 0 3px rgba(37,99,235,.15); }
    #hsx .hs-filters .btn-hs { height: 36px; }
    /* Tabel */
    #hsx .hs-table-wrap { background: var(--card) !important; border: 1px solid var(--line) !important; border-radius: 16px !important; box-shadow: var(--shadow) !important; overflow-x: auto; padding: 0 !important; }
    #hsx table.hs-v2 { border-radius: 16px; }
    #hsx table.hs-v2 thead th { font-size: .62rem !important; letter-spacing: .08em !important; padding: 12px 12px !important; }
    #hsx table.hs-v2 tbody td { padding: 12px 12px !important; border-top: 1px solid var(--line) !important; font-size: .8rem !important; vertical-align: middle !important; background: transparent !important; }
    #hsx table.hs-v2 tbody tr:nth-child(even) td { background: transparent !important; }
    #hsx table.hs-v2 tbody tr:hover td { background: rgba(37,99,235,.05) !important; }
    #hsx table.hs-v2 .hs-room-badge { background: linear-gradient(135deg, #1e3a8a, #2563eb) !important; color: #fff !important; -webkit-text-fill-color: #fff !important; border-radius: 8px !important; box-shadow: none !important; }
    #hsx table.hs-v2 .hs-svc-one { background: var(--soft) !important; border: 1px solid var(--line) !important; border-radius: 999px !important; }
    #hsx table.hs-v2 td.hs-date { color: var(--mute) !important; font-size: .74rem !important; white-space: nowrap; }
    #hsx table.hs-v2 .hs-guest-name { color: var(--ink) !important; font-weight: 700 !important; }
    #hsx table.hs-v2 .hs-lunas { background: rgba(22,163,74,.12) !important; color: #15803d !important; border-radius: 999px; padding: 2px 10px; font-weight: 700; font-size: .7rem; }
    /* Badge status: pipih, tanpa bayangan */
    #hsx .hs-badge { min-width: 78px !important; padding: .22rem .55rem !important; font-size: .56rem !important; box-shadow: none !important; border: 0 !important; }
    #hsx .hs-badge::before { display: none !important; }
    #hsx .hs-badge-text { text-shadow: none !important; }
    /* Tombol Aksi */
    #hsx .hs-action-dropdown-btn { height: 30px; padding: 0 .75rem !important; border-radius: 9px !important; background: var(--card) !important; border: 1px solid var(--line) !important; color: var(--brand) !important; -webkit-text-fill-color: var(--brand) !important; font-weight: 700 !important; font-size: .74rem !important; box-shadow: none !important; }
    #hsx .hs-action-dropdown-btn:hover { background: var(--accent) !important; border-color: var(--accent) !important; color: #fff !important; -webkit-text-fill-color: #fff !important; }
    #hsx table.hs-v2 col:last-child { width: 96px !important; }
    #hsx table.hs-v2 { min-width: 1060px !important; }
    #hsx .hs-empty { padding: 3rem 1rem !important; text-align: center; color: var(--mute) !important; }
    /* ── Mode ringkas: semua kontainer dikecilkan agar halaman rapat & elegan ── */
    #hsx { max-width: 1440px; padding: .55rem .7rem 1rem; }
    #hsx .hs-header-card { padding: .6rem .8rem !important; border-radius: 14px !important; margin-bottom: .5rem !important; }
    #hsx .hs-topbar { margin-bottom: .55rem !important; gap: .6rem; }
    #hsx .hs-topbar h2 { font-size: .95rem !important; gap: .45rem; }
    #hsx .hsx-logo { width: 28px; height: 28px; border-radius: 8px; }
    #hsx .hsx-logo svg { width: 15px; height: 15px; }
    #hsx .hs-topmeta { margin-top: .3rem !important; gap: .25rem; }
    #hsx .hs-topmeta .hsx-chip { padding: .06rem .5rem; font-size: .6rem !important; }
    #hsx .btn-hs { height: 30px; padding: 0 .8rem !important; border-radius: 9px !important; font-size: .72rem !important; }
    #hsx .hs-stats { gap: .4rem; }
    #hsx .hs-stats .hs-stat { padding: .4rem .65rem !important; border-radius: 10px !important; border-top: 1px solid var(--line) !important; border-left: 3px solid var(--c, #2563eb) !important; gap: .05rem; }
    #hsx .hs-stats .hs-stat .val { font-size: .92rem !important; }
    #hsx .hs-stats .hs-stat .lbl { font-size: .54rem !important; }
    #hsx .hsx-rev { padding: .5rem .7rem; border-radius: 14px; margin-bottom: .5rem; }
    #hsx .hsx-rev-h { margin-bottom: .4rem; font-size: .58rem; }
    #hsx .hsx-rev-grid { grid-template-columns: repeat(auto-fit, minmax(140px, 200px)); gap: .4rem; }
    #hsx .hsx-rev-i { padding: .3rem .6rem; border-radius: 10px; border-left-width: 3px; }
    #hsx .hsx-rev-i .n { font-size: .62rem; } #hsx .hsx-rev-i .v { font-size: .82rem; margin-top: 0; } #hsx .hsx-rev-i .c { font-size: .55rem; }
    #hsx .hs-filters { padding: .4rem .55rem !important; margin-bottom: .5rem !important; border-radius: 12px !important; gap: .4rem; }
    #hsx .hs-filters input, #hsx .hs-filters select { height: 30px; padding: 0 .6rem !important; border-radius: 9px !important; font-size: .74rem !important; }
    #hsx .hs-filters .btn-hs { height: 30px; }
    #hsx .hs-table-wrap { border-radius: 14px !important; }
    #hsx table.hs-v2 { min-width: 940px !important; }
    #hsx table.hs-v2 thead th { padding: 8px 10px !important; font-size: .56rem !important; }
    #hsx table.hs-v2 tbody td { padding: 6px 10px !important; font-size: .74rem !important; }
    #hsx table.hs-v2 td.hs-date { font-size: .68rem !important; }
    #hsx table.hs-v2 .hs-room-badge { font-size: .66rem !important; padding: 1px 9px !important; min-width: 0 !important; }
    #hsx table.hs-v2 .hs-svc-one { padding: 1px 8px !important; font-size: .66rem !important; }
    #hsx table.hs-v2 .hs-lunas { font-size: .62rem; padding: 1px 8px; }
    #hsx .hs-stat .hs-badge, #hsx table.hs-v2 .hs-stat .hs-badge { min-width: 62px !important; padding: .1rem .4rem !important; font-size: .5rem !important; }
    #hsx table.hs-v2 .hs-stat { gap: 2px !important; }
    #hsx .hs-action-dropdown-btn { height: 24px; padding: 0 .6rem !important; font-size: .66rem !important; border-radius: 8px !important; }
    @media (max-width: 1100px) { #hsx .hs-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 640px) {
        #hsx { padding: .65rem .5rem 1rem; }
        #hsx .hs-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        #hsx .hs-top-actions { width: 100%; }
        #hsx .hs-top-actions .btn-hs { flex: 1; justify-content: center; }
    }
</style>

<div class="hs-page" id="hsx">

    <div class="hs-header-card">
        <div class="hs-topbar">
            <div class="hs-head-main">
                <h2><span class="hsx-logo"><svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18h18"/><path d="M5 18a7 7 0 0 1 14 0"/><path d="M12 7V5"/><path d="M10 5h4"/></svg></span>Hotel Services</h2>
                <div class="hs-topmeta"><?php foreach (['Motor Rental', 'Laundry', 'Service', 'Airport Drop', 'Harbor Drop', 'Narayana Trip', 'Lain-lain'] as $hsxC): ?><span class="hsx-chip"><?php echo $hsxC; ?></span><?php endforeach; ?></div>
            </div>
            <div class="hs-top-actions">
                <button class="btn-hs btn-hs-secondary" onclick="openSettingsModal()">⚙️ Pengaturan</button>
                <button class="btn-hs btn-hs-primary" id="btnNewInvoice">+ Invoice Baru</button>
            </div>
        </div>
        <script>
            // Fallback: attach button directly in case main script block fails to execute
            (function() {
                function tryAttach() {
                    var btn = document.getElementById('btnNewInvoice');
                    var modal = document.getElementById('createModal');
                    if (btn && modal) {
                        btn.onclick = function() {
                            if (typeof openCreateModal === 'function') {
                                openCreateModal();
                            } else {
                                modal.classList.add('open');
                            }
                        };
                    }
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', tryAttach);
                } else {
                    tryAttach();
                }
            })();
        </script>

        <!-- Stats -->
        <div class="hs-stats">
            <div class="hs-stat" style="--c:#6366f1">
                <div class="val"><?php echo $today['total']; ?></div>
                <div class="lbl">Invoice Hari Ini</div>
            </div>
            <div class="hs-stat" style="--c:#10b981">
                <div class="val">Rp <?php echo number_format($today['revenue'], 0, ',', '.'); ?></div>
                <div class="lbl">Pendapatan Hari Ini</div>
            </div>
            <div class="hs-stat" style="--c:#3b82f6">
                <div class="val">Rp <?php echo number_format($today['collected'], 0, ',', '.'); ?></div>
                <div class="lbl">Terkumpul</div>
            </div>
            <div class="hs-stat" style="--c:#ef4444">
                <div class="val"><?php echo $today['unpaid']; ?></div>
                <div class="lbl">Belum Lunas</div>
            </div>
            <div class="hs-stat" style="--c:#8b5cf6">
                <div class="val"><?php echo $today['completed']; ?></div>
                <div class="lbl">Selesai</div>
            </div>
        </div>
    </div>

    <!-- Revenue per Service Type (this month) -->
    <?php if (!empty($svcRevStats)): ?>
        <div class="hsx-rev">
            <div class="hsx-rev-h"><span>Pendapatan per layanan · bulan ini</span></div>
            <div class="hsx-rev-grid">
                <?php
                $svcColors = ['motor_rental' => '#f59e0b', 'car_rental' => '#0f766e', 'laundry' => '#3b82f6', 'service' => '#10b981', 'airport_drop' => '#8b5cf6', 'harbor_drop' => '#06b6d4', 'narayana_trip' => '#ec4899', 'lain_lain' => '#78716c'];
                foreach ($svcRevStats as $sr):
                    $svcKey  = $sr['service_type'];
                    $svcInfo = $serviceTypes[$svcKey] ?? ['label' => $svcKey, 'icon' => '🔹'];
                    $color   = $svcColors[$svcKey] ?? '#6366f1';
                ?>
                    <div class="hsx-rev-i" style="--c:<?php echo $color; ?>">
                        <div class="n"><?php echo $svcInfo['icon']; ?> <?php echo htmlspecialchars($svcInfo['label']); ?></div>
                        <div class="v">Rp <?php echo number_format($sr['total_revenue'], 0, ',', '.'); ?></div>
                        <div class="c"><?php echo $sr['invoice_count']; ?> invoice</div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="GET" class="hs-filters">
        <input type="text" name="q" placeholder="🔍 Cari tamu / nomor invoice..." value="<?php echo htmlspecialchars($search); ?>">
        <select name="status">
            <option value="">Semua status</option>
            <?php foreach (['pending', 'confirmed', 'completed', 'cancelled'] as $s): ?>
                <option value="<?php echo $s; ?>" <?php echo $filterStatus === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date" value="<?php echo htmlspecialchars($filterDate); ?>">
        <button type="submit" class="btn-hs btn-hs-primary">Filter</button>
        <?php if ($filterStatus || $filterDate || $search): ?>
            <a href="hotel-services.php" class="btn-hs btn-hs-secondary" style="text-decoration:none">Hapus filter</a>
        <?php endif; ?>
    </form>

    <!-- Table -->
    <div class="hs-table-wrap">
        <?php if (empty($invoices)): ?>
            <div class="hs-empty">
                <div class="em-icon">🛎️</div>
                <div style="font-weight:700;margin-bottom:0.25rem">Belum ada invoice layanan</div>
                <div style="font-size:0.8rem">Klik "+ Invoice Baru" untuk membuat yang pertama</div>
            </div>
        <?php else: ?>
            <table class="hs-table hs-v2">
                <colgroup>
                    <col style="width:104px">
                    <col>
                    <col style="width:128px">
                    <col style="width:84px">
                    <col style="width:170px">
                    <col style="width:100px">
                    <col style="width:100px">
                    <col style="width:100px">
                    <col style="width:108px">
                    <col style="width:76px">
                </colgroup>
                <thead>
                    <tr>
                        <th class="l">Tanggal</th>
                        <th class="l">Tamu</th>
                        <th class="l">Telepon</th>
                        <th>Kamar</th>
                        <th class="l">Layanan</th>
                        <th class="r">Total</th>
                        <th class="r">Dibayar</th>
                        <th class="r">Sisa</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv):
                        $hsBalanceDue = max(0, (float)$inv['total'] - (float)$inv['paid_amount']);
                        // Layanan cukup 1 baris: layanan pertama + "/ …" bila lebih dari satu jenis.
                        $hsSvcs = $inv['service_type_counts'] ?? [];
                        $hsFirst = $hsSvcs ? ($serviceTypes[$hsSvcs[0]['service_type']] ?? ['label' => $hsSvcs[0]['service_type'], 'icon' => '🔹']) : null;
                        $hsSvcAll = implode(', ', array_map(fn($t) => ($serviceTypes[$t['service_type']]['label'] ?? $t['service_type']) . ' ×' . (int)$t['cnt'], $hsSvcs));
                    ?>
                        <tr class="hs-row-clickable<?php echo $inv['status'] === 'cancelled' ? ' hs-row-cancelled' : ''; ?>" onclick="showInvoiceDetail(<?php echo $inv['id']; ?>)" title="<?php echo htmlspecialchars($inv['invoice_number']); ?>">
                            <td class="hs-date"><?php echo date('d M Y', strtotime($inv['service_date'] ?? $inv['created_at'])); ?></td>
                            <td>
                                <div class="hs-guest">
                                    <?php if (!empty($inv['booking_id']) && isset($inhouseBookingIds[$inv['booking_id']])): ?>
                                        <span class="hs-inhouse-dot" title="Tamu masih in-house"></span>
                                    <?php endif; ?>
                                    <span class="hs-guest-name" title="<?php echo htmlspecialchars($inv['guest_name']); ?>"><?php echo htmlspecialchars($inv['guest_name']); ?></span>
                                </div>
                            </td>
                            <td class="hs-phone"><?php echo $inv['guest_phone'] ? htmlspecialchars($inv['guest_phone']) : '<span class="hs-dash">—</span>'; ?></td>
                            <td class="c">
                                <?php if ($inv['room_number']): ?>
                                    <span class="hs-room-badge"><?php echo htmlspecialchars($inv['room_number']); ?></span>
                                <?php else: ?>
                                    <span class="hs-dash">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($hsFirst): ?>
                                    <span class="hs-svc-one" title="<?php echo htmlspecialchars($hsSvcAll); ?>">
                                        <span class="hs-svc-ic"><?php echo $hsFirst['icon'] ?? ''; ?></span>
                                        <span class="hs-svc-txt"><?php echo htmlspecialchars($hsFirst['label'] ?? ''); ?><?php echo count($hsSvcs) > 1 ? ' / …' : ''; ?></span>
                                    </span>
                                <?php else: ?>
                                    <span class="hs-dash">No items</span>
                                <?php endif; ?>
                            </td>
                            <td class="r hs-num"><?php echo number_format($inv['total'], 0, ',', '.'); ?></td>
                            <td class="r hs-num hs-paid"><?php echo (float)$inv['paid_amount'] > 0 ? number_format($inv['paid_amount'], 0, ',', '.') : '<span class="hs-dash">—</span>'; ?></td>
                            <td class="r">
                                <?php if ($hsBalanceDue > 0): ?>
                                    <span class="hs-num hs-due"><?php echo number_format($hsBalanceDue, 0, ',', '.'); ?></span>
                                <?php else: ?>
                                    <span class="hs-lunas">Lunas</span>
                                <?php endif; ?>
                            </td>
                            <td class="c">
                                <div class="hs-stat">
                                    <span class="hs-badge" style="background:<?php echo $payStatusColors[$inv['payment_status']]; ?>"><span class="hs-badge-text"><?php echo strtoupper($inv['payment_status']); ?></span></span>
                                    <span class="hs-badge" style="background:<?php echo $statusColors[$inv['status']]; ?>"><span class="hs-badge-text"><?php echo strtoupper($inv['status']); ?></span></span>
                                </div>
                            </td>
                            <td class="c" onclick="event.stopPropagation()">
                                <div class="hs-action-dropdown">
                                    <button type="button" class="hs-action-dropdown-btn" onclick="toggleHsActionMenu(event)">Aksi ▾</button>
                                    <div class="hs-action-dropdown-menu">
                                        <?php if ($inv['payment_status'] !== 'paid'): ?>
                                            <button class="hs-action-dropdown-item hs-item-pay"
                                                onclick="openPayModal(<?php echo $inv['id']; ?>,<?php echo $inv['total'] - $inv['paid_amount']; ?>,'<?php echo htmlspecialchars($inv['invoice_number'], ENT_QUOTES); ?>')">💳 Bayar</button>
                                        <?php endif; ?>
                                        <?php if ($inv['status'] !== 'cancelled' && empty($inv['cashbook_synced'])):
                                            $addSvcData = ['id' => (int)$inv['id'], 'no' => (string)$inv['invoice_number'], 'guest' => (string)$inv['guest_name'], 'phone' => (string)($inv['guest_phone'] ?? ''), 'room' => (string)($inv['room_number'] ?? ''), 'booking' => (int)($inv['booking_id'] ?? 0)]; ?>
                                            <button class="hs-action-dropdown-item hs-item-addsvc" onclick='openAddServiceModal(<?php echo htmlspecialchars(json_encode($addSvcData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)'>➕ Tambah Layanan</button>
                                        <?php endif; ?>
                                        <a href="hotel-service-invoice.php?id=<?php echo $inv['id']; ?>" target="_blank" class="hs-action-dropdown-item">🖨️ Invoice</a>
                                        <?php if ($auth->canEdit('frontdesk')): ?>
                                            <button class="hs-action-dropdown-item" onclick="openEditModal(<?php echo $inv['id']; ?>)">✏️ Edit</button>
                                        <?php endif; ?>
                                        <?php if ($auth->canEdit('frontdesk')): ?>
                                            <div class="hs-action-dropdown-divider"></div>
                                            <div class="hs-action-dropdown-status">
                                                <span>Ubah Status</span>
                                                <select onchange="updateStatus(<?php echo $inv['id']; ?>,this.value,this);this.blur()">
                                                    <?php foreach (['pending', 'confirmed', 'completed', 'cancelled'] as $s): ?>
                                                        <option value="<?php echo $s; ?>" <?php echo $inv['status'] === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($auth->canDelete('frontdesk')): ?>
                                            <div class="hs-action-dropdown-divider"></div>
                                            <button class="hs-action-dropdown-item hs-item-delete" onclick="deleteInvoice(<?php echo $inv['id']; ?>,'<?php echo htmlspecialchars($inv['invoice_number'], ENT_QUOTES); ?>')">🗑️ Hapus</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- ══ INVOICE DETAIL POPUP ════════════════════════════════════════════════════ -->
<div id="invoiceDetailOverlay" class="hs-modal-overlay" onclick="if(event.target===this)closeInvoiceDetail()">
    <div class="hs-modal hs-detail-modal" id="invoiceDetailModal"></div>
</div>

<!-- ══ CREATE MODAL ════════════════════════════════════════════════════════════ -->
<div id="createModal" class="hs-modal-overlay" onclick="if(event.target===this)closeCreateModal()">
    <div class="hs-modal hsf-modal">
        <div class="hsf-head">
            <div>
                <h3>New Service Invoice</h3>
                <small>Layanan tamu: mobil, motor, trip, laundry &amp; lainnya</small>
            </div>
            <button type="button" class="hsf-close" onclick="closeCreateModal()" aria-label="Tutup">&times;</button>
        </div>

        <!-- Guest -->
        <div style="margin-bottom:0.75rem">
            <span class="sect-label">Guest</span>
            <div class="guest-toggle">
                <button type="button" id="btnInhouse" class="active" onclick="setGuestMode('inhouse')">Tamu In-house</button>
                <button type="button" id="btnManual" onclick="setGuestMode('manual')">Input Manual</button>
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
        <div id="itemsBody" class="hs-items-wrap"></div>
        <button type="button" class="btn-add-item" onclick="addItemRow()">+ Add Service Item</button>

        <div id="hsAddBanner" class="hs-add-banner" style="display:none"></div>
        <div id="hsCreateExtras">
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

        </div>
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
            <button class="btn-hs btn-hs-primary" id="createBtn" onclick="submitCreate()">Create Invoice</button>
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
        <label style="display:flex;align-items:center;gap:0.4rem;font-size:0.78rem;color:#64748b;margin:0.4rem 0 0.2rem">
            <input type="checkbox" id="pSplitToggle" onchange="toggleSplitPay()"> Split pembayaran (sebagian cash, sebagian kartu/transfer)?
        </label>
        <div id="pSplitRow" class="hs-form-row" style="display:none">
            <div class="hs-field"><label>Amount ke-2 (Rp)</label><input type="number" id="pAmount2" value="0" min="0"></div>
            <div class="hs-field"><label>Method ke-2</label>
                <select id="pMethod2">
                    <option value="transfer">Transfer</option>
                    <option value="cash">Cash</option>
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
    <div class="hs-modal hss-modal">
        <div class="hss-head">
            <div>
                <h3>Pengaturan Hotel Services</h3>
                <small>Mitra, katalog harga, tipe layanan &amp; data invoice</small>
            </div>
            <button type="button" class="hss-close" onclick="closeSettingsModal()" aria-label="Tutup">&times;</button>
        </div>
        <div class="hs-tabs hss-tabs">
            <button class="hs-tab active" id="tab-inv" onclick="switchTab('inv')">Invoice &amp; Perusahaan</button>
            <button class="hs-tab" id="tab-partner" onclick="switchTab('partner')">Mitra</button>
            <button class="hs-tab" id="tab-catalog" onclick="switchTab('catalog')">Katalog Harga</button>
            <button class="hs-tab" id="tab-svctype" onclick="switchTab('svctype')">Tipe Layanan</button>
            <button class="hs-tab" id="tab-guide" onclick="switchTab('guide')">Guide Trip</button>
        </div>
        <div class="hss-body">

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
                <button class="btn-hs btn-hs-secondary" onclick="closeSettingsModal()">Batal</button>
                <button class="btn-hs btn-hs-primary" id="btnSaveSettings" onclick="saveSettings()">Simpan Pengaturan</button>
            </div>
        </div>

        <!-- TAB: Mitra -->
        <div class="hs-tab-pane" id="pane-partner">
            <div class="hss-bar">
                <span>Daftar mitra penyedia layanan (sopir/pemilik mobil, rental motor, dll.). Dipakai di katalog &amp; Tagihan.</span>
                <button class="hss-add" onclick="addPartnerRow()">+ Tambah Mitra</button>
            </div>
            <div class="hss-tbl-wrap">
                <table class="cat-tbl hss-tbl">
                    <thead>
                        <tr>
                            <th>Nama Mitra</th>
                            <th style="width:130px">Kategori</th>
                            <th style="width:150px">Telepon</th>
                            <th style="width:70px" class="c">Aktif</th>
                            <th style="width:76px"></th>
                        </tr>
                    </thead>
                    <tbody id="partnerBody">
                        <?php foreach ($hsPartners as $p): ?>
                            <tr id="ptr<?php echo (int)$p['id']; ?>">
                                <td><input type="text" class="pName" value="<?php echo htmlspecialchars($p['partner_name'], ENT_QUOTES); ?>"></td>
                                <td><select class="pCat">
                                        <?php foreach ($hsCategories as $ck => $cv): if ($ck === 'hotel') continue; ?>
                                            <option value="<?php echo $ck; ?>" <?php echo $p['category'] === $ck ? 'selected' : ''; ?>><?php echo $cv['icon'] . ' ' . $cv['label']; ?></option>
                                        <?php endforeach; ?>
                                    </select></td>
                                <td><input type="text" class="pPhone" value="<?php echo htmlspecialchars((string)($p['phone'] ?? ''), ENT_QUOTES); ?>" placeholder="08xx"></td>
                                <td class="c"><input type="checkbox" class="pActive" <?php echo (int)$p['is_active'] ? 'checked' : ''; ?>></td>
                                <td class="hss-act">
                                    <button class="btn-cat-save" title="Simpan" onclick="savePartnerRow(<?php echo (int)$p['id']; ?>)">✓</button>
                                    <button class="btn-cat-del" title="Hapus" onclick="deletePartnerRow(<?php echo (int)$p['id']; ?>)">✕</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (!$hsPartners): ?><div class="hss-empty" id="partnerEmpty">Belum ada mitra. Klik "+ Tambah Mitra".</div><?php endif; ?>
            </div>
        </div>

        <!-- TAB: Katalog Harga -->
        <div class="hs-tab-pane" id="pane-catalog">
            <div class="hss-bar">
                <span>Harga ke tamu, bayar ke mitra &amp; mitra default per item. Untung hotel = harga tamu − bayar mitra.</span>
                <button class="hss-add" onclick="addCatalogRow()">+ Tambah Item</button>
            </div>
            <div class="hss-tbl-wrap">
                <table class="cat-tbl hss-tbl">
                    <thead>
                        <tr>
                            <th style="width:150px">Tipe Layanan</th>
                            <th>Nama Item</th>
                            <th style="width:104px" class="r">Harga Tamu</th>
                            <th style="width:104px" class="r">Bayar Mitra</th>
                            <th style="width:92px" class="r">Untung</th>
                            <th style="width:150px">Mitra Default</th>
                            <th style="width:70px">Satuan</th>
                            <th style="width:76px"></th>
                        </tr>
                    </thead>
                    <tbody id="catalogBody">
                        <?php foreach ($catalogRows as $cr):
                            $crCat = $serviceTypes[$cr['service_type']]['category'] ?? 'hotel'; ?>
                            <tr id="ctr<?php echo $cr['id']; ?>" data-partner="<?php echo (int)($cr['partner_id'] ?? 0); ?>">
                                <td><select class="cSType" onchange="catRowTypeChanged(this)">
                                        <?php foreach ($hsCategories as $ck => $cv): ?>
                                            <optgroup label="<?php echo $cv['icon'] . ' ' . $cv['label']; ?>">
                                                <?php foreach ($serviceTypes as $sk => $sv): if (($sv['category'] ?? 'hotel') !== $ck) continue; ?>
                                                    <option value="<?php echo $sk; ?>" <?php echo $cr['service_type'] === $sk ? 'selected' : ''; ?>><?php echo $sv['icon'] . ' ' . $sv['label']; ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select></td>
                                <td><input type="text" class="cName" value="<?php echo htmlspecialchars($cr['item_name'], ENT_QUOTES); ?>"></td>
                                <td><input type="number" class="cPrice r" value="<?php echo (float)$cr['default_price']; ?>" min="0" oninput="catRowCalc(this)"></td>
                                <td><input type="number" class="cDriverRate r" value="<?php echo (float)($cr['driver_rate'] ?? 0); ?>" min="0" oninput="catRowCalc(this)"></td>
                                <td class="r"><span class="cProfit"></span></td>
                                <td><select class="cPartner"></select></td>
                                <td><input type="text" class="cUnit" value="<?php echo htmlspecialchars($cr['unit'] ?? 'unit', ENT_QUOTES); ?>"></td>
                                <td class="hss-act">
                                    <input type="hidden" class="cSort" value="<?php echo (int)$cr['sort_order']; ?>">
                                    <button class="btn-cat-save" title="Simpan" onclick="saveCatalogRow(<?php echo $cr['id']; ?>)">✓</button>
                                    <button class="btn-cat-del" title="Hapus" onclick="deleteCatalogRow(<?php echo $cr['id']; ?>)">✕</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB: Tipe Layanan -->
        <div class="hs-tab-pane" id="pane-svctype">
            <div class="hss-bar">
                <span>Tipe layanan dikelompokkan per kategori (Mobil, Motor, Trip, Hotel). Key unik: huruf kecil &amp; underscore.</span>
                <button class="hss-add" onclick="addSvcTypeRow()">+ Tambah Tipe</button>
            </div>
            <div class="hss-tbl-wrap">
                <table class="cat-tbl hss-tbl">
                    <thead>
                        <tr>
                            <th style="width:56px" class="c">Icon</th>
                            <th>Label</th>
                            <th style="width:150px">Key</th>
                            <th style="width:130px">Kategori</th>
                            <th style="width:64px" class="c">Urut</th>
                            <th style="width:76px"></th>
                        </tr>
                    </thead>
                    <tbody id="svcTypeBody">
                        <?php
                        $allSvcTypes = $pdo->prepare("SELECT * FROM hotel_service_types WHERE business_id=? ORDER BY sort_order, type_label");
                        $allSvcTypes->execute([$businessId]);
                        foreach ($allSvcTypes->fetchAll(PDO::FETCH_ASSOC) as $st):
                            $stCat = ($st['category'] ?? '') !== '' ? $st['category'] : $hsDefaultCategory((string)$st['type_key']); ?>
                            <tr id="str<?php echo $st['id']; ?>">
                                <td class="c"><input type="text" class="stIcon c" value="<?php echo htmlspecialchars($st['type_icon'], ENT_QUOTES); ?>"></td>
                                <td><input type="text" class="stLabel" value="<?php echo htmlspecialchars($st['type_label'], ENT_QUOTES); ?>"></td>
                                <td><input type="text" class="stKey" value="<?php echo htmlspecialchars($st['type_key'], ENT_QUOTES); ?>"></td>
                                <td><select class="stCat">
                                        <?php foreach ($hsCategories as $ck => $cv): ?>
                                            <option value="<?php echo $ck; ?>" <?php echo $stCat === $ck ? 'selected' : ''; ?>><?php echo $cv['icon'] . ' ' . $cv['label']; ?></option>
                                        <?php endforeach; ?>
                                    </select></td>
                                <td class="c"><input type="number" class="stSort c" value="<?php echo (int)$st['sort_order']; ?>"></td>
                                <td class="hss-act">
                                    <button class="btn-cat-save" title="Simpan" onclick="saveSvcType(<?php echo $st['id']; ?>)">✓</button>
                                    <button class="btn-cat-del" title="Hapus" onclick="deleteSvcType(<?php echo $st['id']; ?>)">✕</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB: Guide Narayana Trip -->
        <div class="hs-tab-pane" id="pane-guide">
            <div class="hss-bar">
                <span>Guide untuk layanan kategori Trip. Nama guide dipakai sebagai mitra di Tagihan.</span>
                <button class="hss-add" onclick="addGuideRow()">+ Tambah Guide</button>
            </div>
            <div class="hss-tbl-wrap">
                <table class="cat-tbl hss-tbl">
                    <thead>
                        <tr>
                            <th>Nama Guide</th>
                            <th style="width:170px">Telepon</th>
                            <th style="width:64px" class="c">Urut</th>
                            <th style="width:76px"></th>
                        </tr>
                    </thead>
                    <tbody id="guideBody">
                        <?php foreach ($tripGuides as $g): ?>
                            <tr id="gtr<?php echo (int)$g['id']; ?>">
                                <td><input type="text" class="gName" value="<?php echo htmlspecialchars($g['guide_name'], ENT_QUOTES); ?>"></td>
                                <td><input type="text" class="gPhone" value="<?php echo htmlspecialchars((string)($g['phone'] ?? ''), ENT_QUOTES); ?>"></td>
                                <td class="c"><input type="number" class="gSort c" value="<?php echo (int)($g['sort_order'] ?? 0); ?>"></td>
                                <td class="hss-act">
                                    <button class="btn-cat-save" title="Simpan" onclick="saveGuideRow(<?php echo (int)$g['id']; ?>)">✓</button>
                                    <button class="btn-cat-del" title="Hapus" onclick="deleteGuideRow(<?php echo (int)$g['id']; ?>)">✕</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        </div>
    </div>
</div>

<!-- ══ EDIT INVOICE MODAL ════════════════════════════════════════════════════════════════════ -->
<div id="editModal" class="hs-modal-overlay" onclick="if(event.target===this)closeEditModal()">
    <div class="hs-modal hsf-modal">
        <div class="hsf-head">
            <div>
                <h3>Edit Invoice</h3>
                <small>Ubah tamu, layanan, mitra &amp; pembayaran</small>
            </div>
            <button type="button" class="hsf-close" onclick="closeEditModal()" aria-label="Tutup">&times;</button>
        </div>
        <input type="hidden" id="eInvId">
        <div id="eInvNo" style="font-size:0.78rem;color:#6366f1;font-weight:700;margin-bottom:0.75rem"></div>

        <div class="hs-form-row">
            <div class="hs-field"><label>Nama Tamu</label><input type="text" id="eGuestName"></div>
            <div class="hs-field"><label>Telepon</label><input type="text" id="ePhone"></div>
        </div>
        <div class="hs-field" style="margin-bottom:0.75rem"><label>Nomor Kamar</label><input type="text" id="eRoom" style="width:200px"></div>

        <span class="sect-label">Service Items *</span>
        <div id="eItemsBody" class="hs-items-wrap"></div>
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
                    <option value="split" disabled>Split (Cash + Kartu)</option>
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
    // Block 1: PHP-generated data only (isolated so any error here doesn't break functions)
    try {
        window.SVC_KEYS = <?php echo json_encode(array_keys($serviceTypes), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?: '[]'; ?>;
        window.SVC_LABELS = <?php echo json_encode(array_values(array_map(fn($v) => ($v['icon'] ?? '') . ' ' . ($v['label'] ?? ''), $serviceTypes)), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?: '[]'; ?>;
        window.CATALOG_DATA = <?php
                                $catalogByType = [];
                                foreach ($catalogRows as $cr) {
                                    $catalogByType[$cr['service_type']][] = [
                                        'name'        => $cr['item_name'],
                                        'price'       => (float)$cr['default_price'],
                                        'driver_rate' => (float)($cr['driver_rate'] ?? 0),
                                        'partner_id'  => (int)($cr['partner_id'] ?? 0),
                                        'unit'        => $cr['unit'] ?? 'unit',
                                    ];
                                }
                                echo json_encode($catalogByType, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?: '{}';
                                ?>;
        window.RENTAL_MOTORS = <?php echo json_encode(array_map(fn($m) => ['id' => (int)$m['id'], 'label' => ($m['motor_name'] ?? '') . ' (' . ($m['plate_number'] ?? '') . ')', 'daily_rate' => (float)$m['daily_rate'], 'partner_owner' => $m['partner_owner'] ?? '', 'owner_phone' => $m['owner_phone'] ?? '', 'commission_type' => $m['commission_type'] ?? 'percent', 'commission_pct' => (float)($m['owner_commission_pct'] ?? 0), 'driver_daily_rate' => (float)($m['driver_daily_rate'] ?? 0)], $availableMotors), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?: '[]'; ?>;
        window.RENTAL_CARS = <?php echo json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'label' => ($c['car_name'] ?? '') . ' (' . ($c['plate_number'] ?? '') . ')' . (!empty($c['car_type']) ? ' - ' . $c['car_type'] : ''), 'daily_rate' => (float)$c['daily_rate'], 'partner_owner' => $c['partner_owner'] ?? '', 'commission_type' => $c['commission_type'] ?? 'percent', 'commission_pct' => (float)($c['owner_commission_pct'] ?? 0), 'commission_nominal' => (float)($c['commission_nominal'] ?? 0), 'driver_daily_rate' => (float)($c['driver_daily_rate'] ?? 0)], $availableCars), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?: '[]'; ?>;
        window.TRIP_GUIDES = <?php echo json_encode(array_map(fn($g) => ['id' => (int)$g['id'], 'name' => $g['guide_name'], 'phone' => $g['phone'] ?? '', 'sort_order' => (int)($g['sort_order'] ?? 0)], $tripGuides), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?: '[]'; ?>;
        window.SVC_OPTIONS = <?php echo json_encode(array_map(fn($k, $v) => ['val' => $k, 'lbl' => ($v['icon'] ?? '') . ' ' . ($v['label'] ?? ''), 'cat' => $v['category'] ?? 'hotel'], array_keys($serviceTypes), $serviceTypes), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?: '[]'; ?>;
        window.HS_CATEGORIES = <?php echo json_encode($hsCategories, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?: '{}'; ?>;
        window.HS_PARTNERS = <?php echo json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['partner_name'], 'cat' => $p['category'], 'phone' => $p['phone'] ?? '', 'active' => (int)$p['is_active']], $hsPartners), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?: '[]'; ?>;
        window.CATALOG_LIST = <?php echo json_encode(array_map(fn($r) => ['stype' => $r['service_type'], 'name' => $r['item_name'], 'price' => (float)$r['default_price'], 'unit' => $r['unit'] ?? 'unit'], $catalogRows), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?: '[]'; ?>;
        window.ACTIVE_BIZ_ID = <?php echo (int)$businessId; ?>;
        window.HS_DETAILS = <?php echo json_encode($hsDetailsForJs, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?: '{}'; ?>;
    } catch (e) {
        console.error('[hs-data] failed:', e);
    }
</script>
<script>
    function toggleHsActionMenu(e) {
        e = e || window.event;
        e.stopPropagation();
        e.preventDefault();
        var btn = e.currentTarget || e.target;
        var dropdown = btn.closest('.hs-action-dropdown');
        var wasOpen = dropdown.classList.contains('open');
        document.querySelectorAll('.hs-action-dropdown.open').forEach(function(d) {
            d.classList.remove('open');
        });
        if (!wasOpen) {
            var menu = dropdown.querySelector('.hs-action-dropdown-menu');
            var rect = btn.getBoundingClientRect();
            menu.style.top = (rect.bottom + 4) + 'px';
            menu.style.left = Math.max(8, rect.right - 190) + 'px';
            dropdown.classList.add('open');
        }
    }
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.hs-action-dropdown')) {
            document.querySelectorAll('.hs-action-dropdown.open').forEach(function(d) {
                d.classList.remove('open');
            });
        }
    });
    document.addEventListener('scroll', function() {
        document.querySelectorAll('.hs-action-dropdown.open').forEach(function(d) {
            d.classList.remove('open');
        });
    }, true);

    function fmtRp(n) {
        return 'Rp ' + Math.round(n).toLocaleString('id-ID');
    }

    function showInvoiceDetail(id) {
        var d = (window.HS_DETAILS || {})[id];
        if (!d) return;
        var box = document.getElementById('invoiceDetailModal');
        var itemsHtml = (d.items || []).map(function(it) {
            return '<div class="hs-detail-item">' +
                '<div><div class="hs-detail-item-name">' + it.icon + ' ' + it.label + '</div>' +
                (it.description ? '<div class="hs-detail-item-desc">' + it.description + '</div>' : '') + '</div>' +
                '<div class="hs-detail-item-amt">' + fmtRp(it.total_price) + '<div class="hs-detail-item-qty">' + it.quantity + ' x ' + fmtRp(it.unit_price) + '</div></div>' +
                '</div>';
        }).join('') || '<div class="hs-detail-item"><span style="color:#d1d5db">No items</span></div>';

        var subtotal = d.total - d.tax_amount - d.service_charge_amount + d.discount_amount;
        var totalsHtml = '<div class="hs-detail-total-row"><span>Harga Asli</span><span>' + fmtRp(subtotal) + '</span></div>' +
            (d.discount_amount > 0 ? '<div class="hs-detail-total-row"><span>Diskon</span><span>-' + fmtRp(d.discount_amount) + '</span></div>' : '') +
            (d.service_charge_amount > 0 ? '<div class="hs-detail-total-row"><span>Service Charge</span><span>' + fmtRp(d.service_charge_amount) + '</span></div>' : '') +
            (d.tax_amount > 0 ? '<div class="hs-detail-total-row"><span>PPN</span><span>' + fmtRp(d.tax_amount) + '</span></div>' : '') +
            '<div class="hs-detail-total-row"><span>Sudah Dibayar</span><span style="color:#10b981;font-weight:700">' + fmtRp(d.paid_amount) + '</span></div>' +
            '<div class="hs-detail-total-row grand"><span>' + (d.balance_due > 0 ? 'Sisa Tagihan' : 'Total (Lunas)') + '</span><span>' + (d.balance_due > 0 ? fmtRp(d.balance_due) : fmtRp(d.total)) + '</span></div>';

        box.innerHTML =
            '<div class="hs-detail-head"><h3>🧾 ' + d.invoice_number + '</h3>' +
            '<button type="button" onclick="closeInvoiceDetail()" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#94a3b8;line-height:1">✕</button></div>' +
            '<div class="hs-detail-sub">' +
            (d.inhouse ? '<span class="hs-inhouse-dot" style="margin-right:4px"></span>' : '') +
            '<strong>' + d.guest_name + '</strong>' + (d.room_number ? ' · Room ' + d.room_number : '') + (d.guest_phone ? ' · ' + d.guest_phone : '') +
            '<br>' + d.date + ' · ' + d.status.toUpperCase() + '</div>' +
            '<div class="hs-detail-items">' + itemsHtml + '</div>' +
            '<div class="hs-detail-totals">' + totalsHtml + '</div>';
        document.getElementById('invoiceDetailOverlay').classList.add('open');
    }

    function closeInvoiceDetail() {
        document.getElementById('invoiceDetailOverlay').classList.remove('open');
    }
</script>
<script src="../../assets/js/hotel-services-fn.js?v=20261011"></script>

<?php include '../../includes/footer.php'; ?>