<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';
require_once '../../includes/business_helper.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Rekaman Stock Masuk';

$activeBusinessSlug = strtolower((string)($_SESSION['active_business_id'] ?? ''));
$activeBusinessConfig = getActiveBusinessConfig();

if ($activeBusinessSlug === '' || is_numeric($activeBusinessSlug)) {
    $configuredSlug = strtolower(trim((string)($activeBusinessConfig['business_id'] ?? '')));
    if ($configuredSlug !== '') {
        $activeBusinessSlug = $configuredSlug;
    }
}

$activeBusinessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
$activeBusinessName = (string)($activeBusinessConfig['name'] ?? '');

$targetBusinessNames = array_values(array_unique(array_filter([
    trim($activeBusinessName),
    trim((string)($activeBusinessConfig['name'] ?? '')),
])));

if (in_array(preg_replace('/[^a-z0-9]/', '', $activeBusinessSlug), ['eatmeet', 'eaatmeet'], true)) {
    $targetBusinessNames = array_values(array_unique(array_merge($targetBusinessNames, ['Eat Meet', 'Eaat Meet', 'Eat & Meet'])));
}

$bizNameForMatch = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $activeBusinessName) . '%';

$transferBusinessConfigs = [
    'narayana-hotel' => __DIR__ . '/../../config/businesses/narayana-hotel.php',
    'bens-cafe' => __DIR__ . '/../../config/businesses/bens-cafe.php',
    'eaat-meet' => __DIR__ . '/../../config/businesses/eaat-meet.php',
    'eat-meet' => __DIR__ . '/../../config/businesses/eaat-meet.php',
];

$transferBusinessOptions = [];
foreach ($transferBusinessConfigs as $slug => $cfgPath) {
    if (!file_exists($cfgPath)) {
        continue;
    }
    $cfg = require $cfgPath;
    if (!empty($cfg['name'])) {
        $transferBusinessOptions[$slug] = [
            'slug' => $slug,
            'name' => (string)$cfg['name'],
        ];
    }
}

if ($activeBusinessId > 0) {
    if ($activeBusinessName === '') {
        $businessRow = $db->fetchOne('SELECT business_name FROM businesses WHERE id = ? LIMIT 1', [$activeBusinessId]);
        $activeBusinessName = $businessRow['business_name'] ?? '';
    }
}

// Local baseline table allows reset-to-zero per business without deleting transfer history.
if ($activeBusinessId > 0) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS business_stock_reset_baseline (
            id INT AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            baseline_qty DECIMAL(15,2) NOT NULL DEFAULT 0,
            updated_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_business_item_unit (business_id, item_name, unit)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('business-stock-incoming baseline table error: ' . $e->getMessage());
    }

    try {
        $db->query("CREATE TABLE IF NOT EXISTS business_manual_stock_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            category VARCHAR(100) NULL,
            unit VARCHAR(50) NOT NULL,
            quantity DECIMAL(15,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_business_item (business_id, item_name, unit),
            INDEX idx_business_category (business_id, category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Backward-safe migration for environments where table existed before category column.
        $manualCols = $db->fetchAll('SHOW COLUMNS FROM business_manual_stock_entries');
        $hasCategoryColumn = false;
        foreach ($manualCols as $col) {
            if (strtolower((string)($col['Field'] ?? '')) === 'category') {
                $hasCategoryColumn = true;
                break;
            }
        }

        if (!$hasCategoryColumn) {
            $db->query('ALTER TABLE business_manual_stock_entries ADD COLUMN category VARCHAR(100) NULL AFTER item_name');
            $db->query('ALTER TABLE business_manual_stock_entries ADD INDEX idx_business_category (business_id, category)');
        }
    } catch (Throwable $e) {
        error_log('business-stock-incoming manual stock table error: ' . $e->getMessage());
    }
}

$incomingTransfers = [];
$rawStockSummary = [];
$stockSummary = [];
$baselineMap = [];
$rawStockMap = [];
$manualStockMap = [];
$interTransferInMap = [];
$interTransferOutMap = [];
$dailyOutMap = [];
$dailyOutRows = [];
$masterPdo = null;
$gudangDbNameResolved = '';
$stockMetaMap = [];
$manualCatalogByName = [];
$manualItemSuggestions = [];

// Build map by item+unit for precise adjustments
$buildKey = function ($itemName, $unit) {
    return strtolower(trim((string)$itemName)) . '||' . strtolower(trim((string)$unit));
};

$normalizeItemName = function ($value) {
    $value = preg_replace('/\s+/', ' ', trim((string)$value));
    return strtolower($value ?? '');
};

$getMapQty = function ($map, $key) {
    return isset($map[$key]) ? (float)$map[$key] : 0;
};

$registerStockMeta = function ($itemName, $unit) use (&$stockMetaMap, $buildKey) {
    $itemName = (string)$itemName;
    $unit = (string)$unit;
    $key = $buildKey($itemName, $unit);
    if ($key !== '||' && !isset($stockMetaMap[$key])) {
        $stockMetaMap[$key] = [
            'item_name' => $itemName,
            'unit' => $unit,
        ];
    }
};

$computeVisibleQty = function ($itemName, $unit) use (&$rawStockMap, &$manualStockMap, &$baselineMap, &$dailyOutMap, $buildKey, $getMapQty) {
    $key = $buildKey($itemName, $unit);
    $gross = $getMapQty($rawStockMap, $key) + $getMapQty($manualStockMap, $key);
    $visible = $gross - $getMapQty($baselineMap, $key) - $getMapQty($dailyOutMap, $key);
    return $visible > 0 ? $visible : 0;
};

// Master DB table for inter-business transfers
try {
    $masterDsn = 'mysql:host=' . DB_HOST . ';dbname=' . (defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME) . ';charset=' . DB_CHARSET;
    $masterPdo = new PDO($masterDsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $masterPdo->exec("CREATE TABLE IF NOT EXISTS business_inter_stock_transfers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transfer_number VARCHAR(60) NOT NULL UNIQUE,
        source_business_slug VARCHAR(60) NOT NULL,
        source_business_name VARCHAR(255) NULL,
        target_business_slug VARCHAR(60) NOT NULL,
        target_business_name VARCHAR(255) NULL,
        item_name VARCHAR(255) NOT NULL,
        unit VARCHAR(50) NOT NULL,
        quantity DECIMAL(15,2) NOT NULL DEFAULT 0,
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_source_item (source_business_slug, item_name, unit),
        INDEX idx_target_item (target_business_slug, item_name, unit)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    $masterPdo = null;
    error_log('business-stock-incoming master transfer table error: ' . $e->getMessage());
}

if ($activeBusinessId > 0) {
    // gudang_nasita_transfers lives in Gudang DB (cross-DB read)
    $gudangCfgPath = __DIR__ . '/../../config/businesses/gudang-nasita.php';
    if (file_exists($gudangCfgPath)) {
        $gudangCfg = require $gudangCfgPath;
        $gudangDbName = (string)($gudangCfg['database'] ?? '');
        $gudangDbNameResolved = $gudangDbName;

        if ($gudangDbName !== '') {
            try {
                $originDbName = Database::getCurrentDatabase();
                $gudangDb = Database::switchDatabase($gudangDbName);

                $hasTargetBusinessId = false;
                try {
                    $transferCols = $gudangDb->fetchAll('SHOW COLUMNS FROM gudang_nasita_transfers');
                    foreach ($transferCols as $col) {
                        if (strtolower((string)($col['Field'] ?? '')) === 'target_business_id') {
                            $hasTargetBusinessId = true;
                            break;
                        }
                    }
                } catch (Throwable $e) {
                }

                $targetNamePredicates = [];
                $targetNameParams = [];
                foreach ($targetBusinessNames as $targetName) {
                    $targetNamePredicates[] = 'LOWER(TRIM(gt.target_business_name)) LIKE LOWER(?)';
                    $targetNameParams[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $targetName) . '%';
                }
                $targetNameSql = $targetNamePredicates ? implode(' OR ', $targetNamePredicates) : '1 = 0';
                $targetFilterSql = $hasTargetBusinessId
                    ? '(gt.target_business_id = ? OR (' . $targetNameSql . '))'
                    : '(' . $targetNameSql . ')';
                $targetFilterParams = $hasTargetBusinessId
                    ? array_merge([$activeBusinessId], $targetNameParams)
                    : $targetNameParams;

                if ($hasTargetBusinessId) {
                    $incomingTransfers = $gudangDb->fetchAll(
                        "SELECT
                            gt.id,
                            gt.transfer_number,
                            gt.target_business_name,
                            gt.status,
                            gt.notes,
                            gt.created_at,
                            gt.received_by,
                            u.full_name AS created_by_name,
                            r.full_name AS received_by_name,
                            COUNT(gti.id) AS items_count,
                                     COALESCE(SUM(gti.quantity), 0) AS total_qty,
                                     COALESCE(SUM(COALESCE(gti.subtotal, gti.quantity * COALESCE(gti.unit_price, 0))), 0) AS total_value
                         FROM gudang_nasita_transfers gt
                         LEFT JOIN users u ON gt.created_by = u.id
                         LEFT JOIN users r ON gt.received_by = r.id
                         LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
                         WHERE {$targetFilterSql}
                         GROUP BY gt.id
                         ORDER BY gt.created_at DESC
                         LIMIT 100",
                        $targetFilterParams
                    );

                    $rawStockSummary = $gudangDb->fetchAll(
                        "SELECT
                            gs.item_name,
                            gs.unit,
                            COALESCE(SUM(CASE
                                WHEN gs.quantity IS NOT NULL THEN gs.quantity
                                WHEN gs.jumlah_stok IS NOT NULL THEN gs.jumlah_stok
                                ELSE 0
                            END), 0) AS total_received
                         FROM gudang_nasita_stock gs
                         WHERE COALESCE(gs.is_active, 1) = 1
                         GROUP BY gs.item_name, gs.unit
                         ORDER BY gs.item_name ASC"
                    );
                } else {
                    $incomingTransfers = $gudangDb->fetchAll(
                        "SELECT
                            gt.id,
                            gt.transfer_number,
                            gt.target_business_name,
                            gt.status,
                            gt.notes,
                            gt.created_at,
                            gt.received_by,
                            u.full_name AS created_by_name,
                            r.full_name AS received_by_name,
                            COUNT(gti.id) AS items_count,
                                     COALESCE(SUM(gti.quantity), 0) AS total_qty,
                                     COALESCE(SUM(COALESCE(gti.subtotal, gti.quantity * COALESCE(gti.unit_price, 0))), 0) AS total_value
                         FROM gudang_nasita_transfers gt
                         LEFT JOIN users u ON gt.created_by = u.id
                         LEFT JOIN users r ON gt.received_by = r.id
                         LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
                         WHERE {$targetFilterSql}
                         GROUP BY gt.id
                         ORDER BY gt.created_at DESC
                         LIMIT 100",
                        $targetFilterParams
                    );

                    $rawStockSummary = $gudangDb->fetchAll(
                        "SELECT
                            gs.item_name,
                            gs.unit,
                            COALESCE(SUM(CASE
                                WHEN gs.quantity IS NOT NULL THEN gs.quantity
                                WHEN gs.jumlah_stok IS NOT NULL THEN gs.jumlah_stok
                                ELSE 0
                            END), 0) AS total_received
                         FROM gudang_nasita_stock gs
                         WHERE COALESCE(gs.is_active, 1) = 1
                         GROUP BY gs.item_name, gs.unit
                         ORDER BY gs.item_name ASC"
                    );
                }

                // Current warehouse stock is authoritative for available stock; transfer history stays as a separate audit trail only.
                if (empty($rawStockSummary)) {
                    $rawStockSummary = [];
                }

                if (!empty($originDbName)) {
                    Database::switchDatabase($originDbName);
                    $db = Database::getInstance();
                }
            } catch (Throwable $e) {
                error_log('business-stock-incoming cross-db error: ' . $e->getMessage());
            }
        }
    }
}

foreach ($rawStockSummary as $row) {
    $itemName = (string)($row['item_name'] ?? '');
    $unit = (string)($row['unit'] ?? 'pcs');
    $key = $buildKey($itemName, $unit);
    $rawStockMap[$key] = (float)($row['total_received'] ?? 0);
    $registerStockMeta($itemName, $unit);
}

if ($activeBusinessId > 0) {
    try {
        $manualRows = $db->fetchAll(
            'SELECT item_name, unit, COALESCE(SUM(quantity),0) AS total_manual FROM business_manual_stock_entries WHERE business_id = ? GROUP BY item_name, unit',
            [$activeBusinessId]
        );

        foreach ($manualRows as $mRow) {
            $itemName = (string)($mRow['item_name'] ?? '');
            $unit = (string)($mRow['unit'] ?? 'pcs');
            $key = $buildKey($itemName, $unit);
            $manualStockMap[$key] = (float)($mRow['total_manual'] ?? 0);
            $registerStockMeta($itemName, $unit);
        }
    } catch (Throwable $e) {
        $manualStockMap = [];
    }

    try {
        $manualEntryRows = $db->fetchAll(
            'SELECT item_name, category, unit FROM business_manual_stock_entries WHERE business_id = ? ORDER BY created_at DESC, id DESC',
            [$activeBusinessId]
        );

        foreach ($manualEntryRows as $entryRow) {
            $normalizedName = $normalizeItemName($entryRow['item_name'] ?? '');
            if ($normalizedName === '' || isset($manualCatalogByName[$normalizedName])) {
                continue;
            }

            $manualCatalogByName[$normalizedName] = [
                'item_name' => trim((string)($entryRow['item_name'] ?? '')),
                'category' => trim((string)($entryRow['category'] ?? '')),
                'unit' => trim((string)($entryRow['unit'] ?? '')) !== '' ? trim((string)$entryRow['unit']) : 'pcs',
            ];
        }
    } catch (Throwable $e) {
        $manualCatalogByName = [];
    }
}

if ($activeBusinessId > 0) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS business_stock_daily_out (
            id INT AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            quantity DECIMAL(15,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_business_item_unit (business_id, item_name, unit),
            INDEX idx_business_created_at (business_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('business-stock-incoming daily out table error: ' . $e->getMessage());
    }

    try {
        $baselineRows = $db->fetchAll(
            'SELECT item_name, unit, baseline_qty FROM business_stock_reset_baseline WHERE business_id = ?',
            [$activeBusinessId]
        );

        foreach ($baselineRows as $bRow) {
            $key = $buildKey($bRow['item_name'] ?? '', $bRow['unit'] ?? '');
            $baselineMap[$key] = (float)($bRow['baseline_qty'] ?? 0);
            $registerStockMeta($bRow['item_name'] ?? '', $bRow['unit'] ?? '');
        }
    } catch (Throwable $e) {
        $baselineMap = [];
    }

    try {
        $dailyOutRows = $db->fetchAll(
            'SELECT * FROM business_stock_daily_out WHERE business_id = ? AND DATE(created_at) = CURDATE() ORDER BY created_at DESC',
            [$activeBusinessId]
        );

        foreach ($dailyOutRows as $dailyRow) {
            $itemName = trim((string)($dailyRow['item_name'] ?? ''));
            $unit = trim((string)($dailyRow['unit'] ?? 'pcs'));
            $qty = (float)($dailyRow['quantity'] ?? 0);
            if ($itemName === '' || $qty <= 0) {
                continue;
            }
            $key = $buildKey($itemName, $unit);
            $dailyOutMap[$key] = ($dailyOutMap[$key] ?? 0) + $qty;
            $registerStockMeta($itemName, $unit);
        }
    } catch (Throwable $e) {
        $dailyOutRows = [];
        $dailyOutMap = [];
    }
}

if ($masterPdo && $activeBusinessSlug !== '') {
    try {
        $stmtIn = $masterPdo->prepare("SELECT item_name, unit, SUM(quantity) AS qty
            FROM business_inter_stock_transfers
            WHERE target_business_slug = ?
            GROUP BY item_name, unit");
        $stmtIn->execute([$activeBusinessSlug]);
        foreach ($stmtIn->fetchAll() as $row) {
            $interTransferInMap[$buildKey($row['item_name'] ?? '', $row['unit'] ?? '')] = (float)($row['qty'] ?? 0);
            $registerStockMeta($row['item_name'] ?? '', $row['unit'] ?? '');
        }

        $stmtOut = $masterPdo->prepare("SELECT item_name, unit, SUM(quantity) AS qty
            FROM business_inter_stock_transfers
            WHERE source_business_slug = ?
            GROUP BY item_name, unit");
        $stmtOut->execute([$activeBusinessSlug]);
        foreach ($stmtOut->fetchAll() as $row) {
            $interTransferOutMap[$buildKey($row['item_name'] ?? '', $row['unit'] ?? '')] = (float)($row['qty'] ?? 0);
            $registerStockMeta($row['item_name'] ?? '', $row['unit'] ?? '');
        }
    } catch (Throwable $e) {
        error_log('business-stock-incoming transfer aggregate error: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_business_stock_zero') {
    if ($activeBusinessId > 0) {
        try {
            foreach ($rawStockSummary as $row) {
                $itemName = trim((string)($row['item_name'] ?? ''));
                $unit = trim((string)($row['unit'] ?? 'pcs'));
                $qty = $computeVisibleQty($itemName, $unit);
                if ($itemName === '') {
                    continue;
                }

                $db->query(
                    "INSERT INTO business_stock_reset_baseline (business_id, item_name, unit, baseline_qty, updated_by)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE baseline_qty = VALUES(baseline_qty), updated_by = VALUES(updated_by)",
                    [$activeBusinessId, $itemName, $unit, $qty, (int)($currentUser['id'] ?? 0)]
                );
            }
            $_SESSION['success'] = 'Stok bisnis berhasil di-reset ke 0.';
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Gagal reset stok bisnis: ' . $e->getMessage();
        }
    }

    header('Location: business-stock-incoming.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_manual_stock_business') {
    $itemName = trim((string)($_POST['item_name'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    $unit = trim((string)($_POST['unit'] ?? 'pcs'));
    $qty = (float)($_POST['quantity'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));

    $normalizedInputName = $normalizeItemName($itemName);
    if ($normalizedInputName !== '') {
        foreach ($stockMetaMap as $meta) {
            $candidateName = (string)($meta['item_name'] ?? '');
            if ($normalizeItemName($candidateName) === $normalizedInputName) {
                $itemName = $candidateName;
                if ($unit === '' || strtolower($unit) === 'pcs') {
                    $metaUnit = trim((string)($meta['unit'] ?? ''));
                    if ($metaUnit !== '') {
                        $unit = $metaUnit;
                    }
                }
                break;
            }
        }

        if (($category === '' || strtolower($category) === 'lainnya') && isset($manualCatalogByName[$normalizedInputName])) {
            $knownCategory = trim((string)($manualCatalogByName[$normalizedInputName]['category'] ?? ''));
            if ($knownCategory !== '') {
                $category = $knownCategory;
            }
        }
    }

    if ($activeBusinessId <= 0 || $itemName === '' || $qty <= 0) {
        $_SESSION['error'] = 'Data stok manual tidak valid.';
    } else {
        try {
            $db->query(
                'INSERT INTO business_manual_stock_entries (business_id, item_name, category, unit, quantity, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$activeBusinessId, $itemName, $category !== '' ? $category : null, $unit !== '' ? $unit : 'pcs', $qty, $notes, (int)($currentUser['id'] ?? 0)]
            );
            $_SESSION['success'] = 'Stok manual berhasil ditambahkan.';
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Gagal menambah stok manual: ' . $e->getMessage();
        }
    }

    header('Location: business-stock-incoming.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_daily_stock_out_business') {
    $itemName = trim((string)($_POST['item_name'] ?? ''));
    $unit = trim((string)($_POST['unit'] ?? 'pcs'));
    $qty = (float)($_POST['quantity'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($activeBusinessId <= 0 || $itemName === '' || $qty <= 0) {
        $_SESSION['error'] = 'Data stock keluar tidak valid.';
    } else {
        try {
            $normalizedName = $normalizeItemName($itemName);
            foreach ($stockMetaMap as $meta) {
                if ($normalizeItemName((string)($meta['item_name'] ?? '')) === $normalizedName) {
                    $itemName = (string)($meta['item_name'] ?? $itemName);
                    if ($unit === '' || strtolower($unit) === 'pcs') {
                        $metaUnit = trim((string)($meta['unit'] ?? ''));
                        if ($metaUnit !== '') {
                            $unit = $metaUnit;
                        }
                    }
                    break;
                }
            }

            $warehouseEntryNote = $notes !== '' ? 'Bisnis: ' . $notes : 'Pengeluaran stok harian bisnis';
            $warehouseResult = recordGudangNasitaDailyStockOut($itemName, $qty, (int)($currentUser['id'] ?? 0), [
                'notes' => $warehouseEntryNote,
            ]);

            if (!($warehouseResult['success'] ?? false)) {
                throw new Exception($warehouseResult['message'] ?? 'Gudang Nasita tidak bisa mengurangi stok untuk item ini.');
            }

            $db->insert('business_stock_daily_out', [
                'business_id' => $activeBusinessId,
                'item_name' => $itemName,
                'unit' => $unit !== '' ? $unit : 'pcs',
                'quantity' => $qty,
                'notes' => $notes !== '' ? $notes : 'Pengeluaran stok harian',
                'created_by' => (int)($currentUser['id'] ?? 0),
            ]);
            $_SESSION['success'] = 'Stock keluar berhasil dicatat dan stok Gudang Nasita telah berkurang.';
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Gagal catat stock keluar: ' . $e->getMessage();
        }
    }

    header('Location: business-stock-incoming.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_transfer_history') {
    $transferId = isset($_POST['transfer_id']) ? (int)$_POST['transfer_id'] : 0;

    if ($transferId <= 0 || $gudangDbNameResolved === '') {
        $_SESSION['error'] = 'Data transfer tidak valid.';
        header('Location: business-stock-incoming.php');
        exit;
    }

    try {
        $originDbName = Database::getCurrentDatabase();
        $gudangDb = Database::switchDatabase($gudangDbNameResolved);

        $hasTargetBusinessId = false;
        $transferCols = $gudangDb->fetchAll('SHOW COLUMNS FROM gudang_nasita_transfers');
        foreach ($transferCols as $col) {
            if (strtolower((string)($col['Field'] ?? '')) === 'target_business_id') {
                $hasTargetBusinessId = true;
                break;
            }
        }

        $bizNameForMatch = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $activeBusinessName) . '%';
        if ($hasTargetBusinessId) {
            $transferRow = $gudangDb->fetchOne(
                'SELECT id, transfer_number FROM gudang_nasita_transfers WHERE id = ? AND (target_business_id = ? OR target_business_name LIKE ?) LIMIT 1',
                [$transferId, $activeBusinessId, $bizNameForMatch]
            );
        } else {
            $transferRow = $gudangDb->fetchOne(
                'SELECT id, transfer_number FROM gudang_nasita_transfers WHERE id = ? AND target_business_name LIKE ? LIMIT 1',
                [$transferId, $bizNameForMatch]
            );
        }

        if (!$transferRow) {
            throw new Exception('Histori transfer tidak ditemukan untuk bisnis ini.');
        }

        $conn = $gudangDb->getConnection();
        $conn->beginTransaction();
        $gudangDb->query('DELETE FROM gudang_nasita_transfer_items WHERE transfer_id = ?', [$transferId]);
        $gudangDb->query('DELETE FROM gudang_nasita_transfers WHERE id = ?', [$transferId]);
        $conn->commit();

        if (!empty($originDbName)) {
            Database::switchDatabase($originDbName);
            $db = Database::getInstance();
        }

        $_SESSION['success'] = 'Histori transfer ' . ($transferRow['transfer_number'] ?? '') . ' berhasil dihapus.';
    } catch (Throwable $e) {
        try {
            if (isset($gudangDb) && $gudangDb->getConnection()->inTransaction()) {
                $gudangDb->getConnection()->rollBack();
            }
        } catch (Throwable $rollbackError) {
        }

        $_SESSION['error'] = 'Gagal hapus histori transfer: ' . $e->getMessage();
    }

    header('Location: business-stock-incoming.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_stock_item') {
    $itemName = trim((string)($_POST['item_name'] ?? ''));
    $unit = trim((string)($_POST['unit'] ?? 'pcs'));

    if ($activeBusinessId <= 0 || $itemName === '') {
        $_SESSION['error'] = 'Data item tidak valid untuk dihapus.';
    } else {
        try {
            $key = $buildKey($itemName, $unit);
            // Set baseline to total gross so visible qty becomes 0 regardless of current value
            $totalGross = $getMapQty($rawStockMap, $key)
                + $getMapQty($manualStockMap, $key)
                + $getMapQty($interTransferInMap, $key)
                - $getMapQty($interTransferOutMap, $key);
            $newBaseline = max($totalGross, $getMapQty($baselineMap, $key));

            $db->query(
                "INSERT INTO business_stock_reset_baseline (business_id, item_name, unit, baseline_qty, updated_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE baseline_qty = VALUES(baseline_qty), updated_by = VALUES(updated_by)",
                [$activeBusinessId, $itemName, $unit, $newBaseline, (int)($currentUser['id'] ?? 0)]
            );
            $_SESSION['success'] = 'Item stok bisnis berhasil dihapus.';
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Gagal hapus item stok: ' . $e->getMessage();
        }
    }

    header('Location: business-stock-incoming.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'transfer_stock_business') {
    $itemName = trim((string)($_POST['item_name'] ?? ''));
    $unit = trim((string)($_POST['unit'] ?? 'pcs'));
    $targetSlug = strtolower(trim((string)($_POST['target_business_slug'] ?? '')));
    $qty = (float)($_POST['transfer_qty'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($activeBusinessSlug === '' || $itemName === '' || $qty <= 0 || $targetSlug === '' || $targetSlug === $activeBusinessSlug) {
        $_SESSION['error'] = 'Data transfer tidak valid.';
    } elseif (!$masterPdo) {
        $_SESSION['error'] = 'Fitur transfer antar bisnis belum siap (koneksi master DB gagal).';
    } else {
        $availableQty = $computeVisibleQty($itemName, $unit);
        if ($qty > $availableQty) {
            $_SESSION['error'] = 'Qty transfer melebihi stok tersedia (' . number_format($availableQty, 2) . ').';
        } else {
            try {
                $targetCfgPath = $transferBusinessConfigs[$targetSlug] ?? '';
                $targetName = $targetSlug;
                if ($targetCfgPath && file_exists($targetCfgPath)) {
                    $targetCfg = require $targetCfgPath;
                    $targetName = (string)($targetCfg['name'] ?? $targetSlug);
                }

                $prefix = 'BST-' . date('Ym') . '-';
                $last = $masterPdo->prepare("SELECT transfer_number FROM business_inter_stock_transfers WHERE transfer_number LIKE ? ORDER BY transfer_number DESC LIMIT 1");
                $last->execute([$prefix . '%']);
                $lastNo = $last->fetchColumn();
                $next = 1;
                if ($lastNo) {
                    $next = ((int)substr((string)$lastNo, -4)) + 1;
                }
                $transferNo = $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);

                $ins = $masterPdo->prepare("INSERT INTO business_inter_stock_transfers
                    (transfer_number, source_business_slug, source_business_name, target_business_slug, target_business_name, item_name, unit, quantity, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([
                    $transferNo,
                    $activeBusinessSlug,
                    $activeBusinessName,
                    $targetSlug,
                    $targetName,
                    $itemName,
                    $unit,
                    $qty,
                    $notes,
                    (int)($currentUser['id'] ?? 0)
                ]);

                $_SESSION['success'] = 'Transfer stok berhasil: ' . $transferNo;
            } catch (Throwable $e) {
                $_SESSION['error'] = 'Gagal transfer stok antar bisnis: ' . $e->getMessage();
            }
        }
    }

    header('Location: business-stock-incoming.php');
    exit;
}

if ($activeBusinessId > 0) {
    foreach ($stockMetaMap as $meta) {
        $itemName = (string)($meta['item_name'] ?? '');
        $unit = (string)($meta['unit'] ?? 'pcs');
        $key = $buildKey($itemName, $unit);
        $receivedQty = $getMapQty($rawStockMap, $key);
        $currentQty = $computeVisibleQty($itemName, $unit);

        if ($currentQty <= 0 && $receivedQty <= 0) {
            continue;
        }

        $stockSummary[] = [
            'item_name' => $itemName,
            'unit' => $unit,
            'total_received' => $receivedQty,
            'current_qty' => $currentQty,
        ];
    }

    usort($stockSummary, function ($a, $b) {
        return strcasecmp((string)$a['item_name'], (string)$b['item_name']);
    });
}

$dailyOutTotalQty = 0;
foreach ($dailyOutRows as $dailyOutRow) {
    $dailyOutTotalQty += (float)($dailyOutRow['quantity'] ?? 0);
}

if (isset($_GET['print_stock_out_business']) && (string)$_GET['print_stock_out_business'] === '1') {
    $printFromDate = trim((string)($_GET['from_date'] ?? ''));
    $printToDate = trim((string)($_GET['to_date'] ?? ''));
    if ($printFromDate === '') {
        $printFromDate = date('Y-m-d');
    }
    if ($printToDate === '') {
        $printToDate = $printFromDate;
    }

    $printDailyOutRows = $db->fetchAll(
        'SELECT * FROM business_stock_daily_out WHERE business_id = ? AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC',
        [$activeBusinessId, $printFromDate, $printToDate]
    );

    $printDailyOutTotalQty = 0;
    foreach ($printDailyOutRows as $row) {
        $printDailyOutTotalQty += (float)($row['quantity'] ?? 0);
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Cetak Pengeluaran Stock Harian</title>';
    echo '<style>body{font-family:Arial,sans-serif;font-size:12px;margin:20px;}h2{margin:0 0 4px;}table{width:100%;border-collapse:collapse;margin-top:12px;}th,td{border:1px solid #999;padding:6px 8px;text-align:left;}th{background:#f0f0f0;}.text-right{text-align:right;}@media print{button{display:none}}</style>';
    echo '</head><body>';
    echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">';
    echo '<div><h2>PENGELUARAN STOCK HARIAN</h2><strong>' . htmlspecialchars($activeBusinessName ?: 'Bisnis') . '</strong><br>Periode: ' . htmlspecialchars($printFromDate) . ' s/d ' . htmlspecialchars($printToDate) . '<br>Dicetak: ' . date('d M Y H:i') . '</div>';
    echo '<div style="text-align:right;"><strong>Total Qty:</strong> ' . number_format($printDailyOutTotalQty, 2) . '<br><strong>Jumlah Catatan:</strong> ' . count($printDailyOutRows) . '</div>';
    echo '</div>';
    echo '<table><thead><tr><th>No</th><th>Item</th><th>Unit</th><th>Qty</th><th>Catatan</th><th>Waktu</th></tr></thead><tbody>';
    if (empty($printDailyOutRows)) {
        echo '<tr><td colspan="6" style="text-align:center;">Belum ada pengeluaran stok untuk periode yang dipilih.</td></tr>';
    } else {
        foreach ($printDailyOutRows as $idx => $row) {
            echo '<tr>';
            echo '<td>' . ($idx + 1) . '</td>';
            echo '<td>' . htmlspecialchars((string)($row['item_name'] ?? '-')) . '</td>';
            echo '<td>' . htmlspecialchars((string)($row['unit'] ?? 'pcs')) . '</td>';
            echo '<td class="text-right">' . number_format((float)($row['quantity'] ?? 0), 2) . '</td>';
            echo '<td>' . htmlspecialchars((string)($row['notes'] ?? '-')) . '</td>';
            echo '<td>' . date('d M Y H:i', strtotime((string)($row['created_at'] ?? date('Y-m-d H:i:s')))) . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo '<br><button onclick="window.print()">Cetak</button>';
    echo '</body></html>';
    exit;
}

// Build autocomplete source from manual entries + existing stock names.
foreach ($manualCatalogByName as $normalizedName => $entry) {
    if ($normalizedName !== '') {
        $manualItemSuggestions[$normalizedName] = $entry;
    }
}

foreach ($stockMetaMap as $meta) {
    $name = trim((string)($meta['item_name'] ?? ''));
    $unit = trim((string)($meta['unit'] ?? ''));
    $normalizedName = $normalizeItemName($name);
    if ($normalizedName === '') {
        continue;
    }

    if (!isset($manualItemSuggestions[$normalizedName])) {
        $manualItemSuggestions[$normalizedName] = [
            'item_name' => $name,
            'category' => '',
            'unit' => $unit !== '' ? $unit : 'pcs',
        ];
    }
}

uasort($manualItemSuggestions, function ($a, $b) {
    return strcasecmp((string)($a['item_name'] ?? ''), (string)($b['item_name'] ?? ''));
});

$manualItemMetaJs = [];
foreach ($manualItemSuggestions as $normalizedName => $entry) {
    $manualItemMetaJs[$normalizedName] = [
        'item_name' => (string)($entry['item_name'] ?? ''),
        'category' => (string)($entry['category'] ?? ''),
        'unit' => (string)($entry['unit'] ?? 'pcs'),
    ];
}

$businessStockItemMetaJs = [];
foreach ($stockSummary as $stockRow) {
    $itemName = trim((string)($stockRow['item_name'] ?? ''));
    $unit = trim((string)($stockRow['unit'] ?? 'pcs'));
    if ($itemName === '') {
        continue;
    }

    $normalizedName = $normalizeItemName($itemName);
    $businessStockItemMetaJs[$normalizedName] = [
        'item_name' => $itemName,
        'unit' => $unit !== '' ? $unit : 'pcs',
    ];
}

$totalQtyVisible = 0;
$totalQtyReceived = 0;
foreach ($stockSummary as $row) {
    $totalQtyVisible += (float)($row['current_qty'] ?? 0);
}
foreach ($incomingTransfers as $transferRow) {
    $totalQtyReceived += (float)($transferRow['total_qty'] ?? 0);
}

$totalValueReceived = 0;
$monthValueReceived = 0;
$monthStart = strtotime(date('Y-m-01 00:00:00'));
$monthEnd = strtotime(date('Y-m-t 23:59:59'));
foreach ($incomingTransfers as $transferRow) {
    $transferValue = (float)($transferRow['total_value'] ?? 0);
    $totalValueReceived += $transferValue;

    $transferTime = strtotime((string)($transferRow['created_at'] ?? ''));
    if ($transferTime >= $monthStart && $transferTime <= $monthEnd) {
        $monthValueReceived += $transferValue;
    }
}

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
    <div>
        <h2 style="font-size: 1.5rem; font-weight: 800; letter-spacing: -0.02em; color: var(--text-primary); margin-bottom: 0.25rem;">Stok &amp; Penerimaan Barang</h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">Stok terpisah untuk bisnis aktif<?php echo $activeBusinessName ? ' &mdash; ' . htmlspecialchars($activeBusinessName) : ''; ?></p>
    </div>
    <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
        <a href="purchase-orders.php" class="btn btn-secondary">
            <i data-feather="file-text" style="width: 16px; height: 16px;"></i>
            Purchase Orders
        </a>
        <button type="button" class="btn btn-warning" onclick="openDailyOutModal()">
            <i data-feather="minus-square" style="width: 16px; height: 16px;"></i>
            Stock Keluar
        </button>
        <form method="GET" target="_blank" style="display:flex; gap:0.45rem; align-items:center; flex-wrap:wrap; margin:0;">
            <input type="hidden" name="print_stock_out_business" value="1">
            <input type="date" name="from_date" class="form-control" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" style="width: 130px; min-height: 38px;">
            <span style="font-size:0.75rem; color:var(--text-muted);">s/d</span>
            <input type="date" name="to_date" class="form-control" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" style="width: 130px; min-height: 38px;">
            <button type="submit" class="btn btn-secondary">
                <i data-feather="printer" style="width: 16px; height: 16px;"></i>
                Print
            </button>
        </form>
        <button type="button" class="btn btn-primary" onclick="openManualStockModal()">
            <i data-feather="plus-square" style="width: 16px; height: 16px;"></i>
            Tambah Stok Manual
        </button>
        <form method="POST" onsubmit="return confirm('Reset stok bisnis ini ke 0? Histori transfer tetap aman.')" style="display:inline;">
            <input type="hidden" name="action" value="reset_business_stock_zero">
            <button type="submit" class="btn btn-danger">
                <i data-feather="rotate-ccw" style="width: 16px; height: 16px;"></i>
                Reset Stok ke 0
            </button>
        </form>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success" style="margin-bottom:1rem;"><?php echo htmlspecialchars($_SESSION['success']);
                                                                    unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger" style="margin-bottom:1rem;"><?php echo htmlspecialchars($_SESSION['error']);
                                                                unset($_SESSION['error']); ?></div>
<?php endif; ?>

<?php if ($activeBusinessId <= 0): ?>
    <div class="alert alert-warning">Tidak ada bisnis aktif di sesi ini.</div>
<?php else: ?>

    <div style="display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:0.9rem; margin-bottom:1rem;">
        <div class="card" style="padding:0.9rem 1rem; border:1px solid #dbeafe; background:linear-gradient(145deg,#eff6ff,#ffffff);">
            <div style="font-size:0.75rem; color:#475569; margin-bottom:0.3rem;">Total Item Aktif</div>
            <div style="font-size:1.45rem; font-weight:800; color:#0f172a;"><?php echo count($stockSummary); ?></div>
        </div>
        <div class="card" style="padding:0.9rem 1rem; border:1px solid #dcfce7; background:linear-gradient(145deg,#f0fdf4,#ffffff);">
            <div style="font-size:0.75rem; color:#166534; margin-bottom:0.3rem;">Stock Tersedia</div>
            <div style="font-size:1.45rem; font-weight:800; color:#14532d;"><?php echo number_format($totalQtyVisible, 2); ?></div>
            <div style="font-size:0.72rem; color:#4b5563; margin-top:0.2rem;">Gudang Nasita + Stok Manual Bisnis</div>
        </div>
        <div class="card" style="padding:0.9rem 1rem; border:1px solid #fef3c7; background:linear-gradient(145deg,#fffbeb,#ffffff);">
            <div style="font-size:0.75rem; color:#92400e; margin-bottom:0.3rem;">Histori Transfer</div>
            <div style="font-size:1.45rem; font-weight:800; color:#78350f;"><?php echo count($incomingTransfers); ?></div>
        </div>
        <div class="card" style="padding:0.9rem 1rem; border:1px solid #dcfce7; background:linear-gradient(145deg,#f0fdf4,#ffffff);">
            <div style="font-size:0.75rem; color:#166534; margin-bottom:0.3rem;">Nilai Bulan Ini</div>
            <div style="font-size:1.45rem; font-weight:800; color:#0f9d6a;">Rp <?php echo number_format($monthValueReceived, 0, ',', '.'); ?></div>
            <div style="font-size:0.72rem; color:#4b5563; margin-top:0.2rem;">Total histori: Rp <?php echo number_format($totalValueReceived, 0, ',', '.'); ?></div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 1.25rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 style="font-size:1rem; font-weight:700; margin:0;">Stok Bisnis (Stock Tersedia)</h3>
            <span style="font-size:0.8rem; color:var(--text-muted);"><?php echo count($stockSummary); ?> item | Khusus bisnis aktif</span>
        </div>
        <div style="display:flex; gap:0.55rem; align-items:center; margin-bottom:0.9rem; flex-wrap:wrap;">
            <div style="position:relative; min-width:280px; flex:1;">
                <i data-feather="search" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); width:14px; height:14px; color:#64748b;"></i>
                <input type="text" id="stockSearchInput" class="form-control" placeholder="Cari stok: nama barang / unit" style="padding-left:2rem;">
            </div>
            <button type="button" class="btn btn-secondary" style="height:38px;" onclick="clearStockSearch()">Reset Cari</button>
            <span id="stockSearchCounter" style="font-size:0.8rem; color:#64748b;">Menampilkan <?php echo count($stockSummary); ?> item</span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nama Item</th>
                        <th>Unit</th>
                        <th class="text-right">Stock Tersedia</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockSummary)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:2rem; color:var(--text-muted);">Belum ada stok masuk dari gudang.</td>
                        </tr>
                        <?php else: foreach ($stockSummary as $item): ?>
                            <tr class="stock-row" data-search="<?php echo htmlspecialchars(strtolower(trim((string)$item['item_name']) . ' ' . trim((string)$item['unit']))); ?>">
                                <td style="font-weight:600;">
                                    <?php echo htmlspecialchars($item['item_name']); ?>
                                    <button type="button" class="btn btn-sm btn-secondary" style="height:26px; margin-left:0.45rem; padding:0 0.5rem;" onclick="openManualStockModalPreset('<?php echo htmlspecialchars(addslashes($item['item_name'])); ?>','<?php echo htmlspecialchars(addslashes($item['unit'])); ?>')">
                                        <i data-feather="plus-circle" style="width:12px; height:12px;"></i>
                                        Tambah
                                    </button>
                                </td>
                                <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                <td class="text-right" style="font-weight:700; color:#0f9d6a;">
                                    <div><?php echo number_format((float)($item['current_qty'] ?? 0), 2); ?></div>
                                    <div style="font-size:0.72rem; color:#64748b; font-weight:500;">Gudang + manual: <?php echo number_format((float)$item['total_received'], 2); ?></div>
                                </td>
                                <td class="text-center">
                                    <div style="display:flex; gap:0.4rem; justify-content:center; flex-wrap:wrap;">
                                        <button type="button" class="btn btn-sm btn-primary" style="height:32px; padding:0 0.7rem;" onclick="openTransferModal('<?php echo htmlspecialchars(addslashes($item['item_name'])); ?>','<?php echo htmlspecialchars(addslashes($item['unit'])); ?>','<?php echo htmlspecialchars((string)number_format((float)($item['current_qty'] ?? 0), 2, '.', '')); ?>')">
                                            <i data-feather="send" style="width:13px; height:13px;"></i>
                                            Transfer
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus item stok ini dari bisnis aktif?')">
                                            <input type="hidden" name="action" value="delete_stock_item">
                                            <input type="hidden" name="item_name" value="<?php echo htmlspecialchars($item['item_name']); ?>">
                                            <input type="hidden" name="unit" value="<?php echo htmlspecialchars($item['unit']); ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" style="height:32px; padding:0 0.7rem;" title="Hapus item">
                                                <i data-feather="trash-2" style="width:13px; height:13px;"></i>
                                                Hapus
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-bottom:1.25rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:1rem;">
            <h3 style="font-size:1rem; font-weight:700; margin:0;">Pengeluaran Harian</h3>
            <div style="font-size:0.8rem; color:var(--text-muted);">Total hari ini: <?php echo number_format($dailyOutTotalQty, 2); ?> qty</div>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Unit</th>
                        <th class="text-right">Qty</th>
                        <th>Catatan</th>
                        <th>Waktu</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dailyOutRows)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding:2rem; color:var(--text-muted);">Belum ada pengeluaran stok hari ini.</td>
                        </tr>
                        <?php else: foreach ($dailyOutRows as $dailyOutEntry): ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo htmlspecialchars((string)($dailyOutEntry['item_name'] ?? '-')); ?></td>
                                <td><?php echo htmlspecialchars((string)($dailyOutEntry['unit'] ?? 'pcs')); ?></td>
                                <td class="text-right" style="font-weight:700; color:#d97706;"><?php echo number_format((float)($dailyOutEntry['quantity'] ?? 0), 2); ?></td>
                                <td><?php echo htmlspecialchars((string)($dailyOutEntry['notes'] ?? '-')); ?></td>
                                <td style="font-size:0.82rem; color:var(--text-muted);"><?php echo date('d M Y H:i', strtotime((string)($dailyOutEntry['created_at'] ?? date('Y-m-d H:i:s')))); ?></td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3 style="font-size:1rem; font-weight:700; margin-bottom:1rem;">Histori Penerimaan dari Gudang</h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>No Transfer</th>
                        <th>Tanggal</th>
                        <th>Item</th>
                        <th class="text-right">Total Qty</th>
                        <th class="text-right">Total Nilai</th>
                        <th>Dikirim Oleh</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($incomingTransfers)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:2rem; color:var(--text-muted);">Belum ada penerimaan barang dari gudang.</td>
                        </tr>
                        <?php else: foreach ($incomingTransfers as $transfer): ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($transfer['transfer_number']); ?></td>
                                <td style="font-size:0.875rem;"><?php echo !empty($transfer['created_at']) ? date('d M Y H:i', strtotime($transfer['created_at'])) : '-'; ?></td>
                                <td><?php echo (int)$transfer['items_count']; ?> item</td>
                                <td class="text-right" style="font-weight:600;"><?php echo number_format((float)$transfer['total_qty'], 2); ?></td>
                                <td class="text-right" style="font-weight:700; color:#0f9d6a;">Rp <?php echo number_format((float)$transfer['total_value'], 0, ',', '.'); ?></td>
                                <td style="font-size:0.875rem;"><?php echo htmlspecialchars($transfer['created_by_name'] ?? '-'); ?></td>
                                <td class="text-center">
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus histori transfer ini? Stok bisnis akan ikut berkurang.')">
                                        <input type="hidden" name="action" value="delete_transfer_history">
                                        <input type="hidden" name="transfer_id" value="<?php echo (int)$transfer['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="height:30px; padding:0 0.6rem;" title="Hapus histori">
                                            <i data-feather="trash-2" style="width:12px; height:12px;"></i>
                                            Hapus
                                        </button>
                                    </form>
                                </td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<style>
    .manual-stock-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        background:
            radial-gradient(circle at 20% 15%, rgba(2, 132, 199, 0.18), transparent 40%),
            radial-gradient(circle at 85% 85%, rgba(16, 185, 129, 0.16), transparent 44%),
            rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(3px);
    }

    .manual-stock-panel {
        width: 100%;
        max-width: 640px;
        margin: 0;
        border-radius: 18px;
        overflow: hidden;
        border: 1px solid #d6e7f8;
        box-shadow: 0 30px 70px rgba(15, 23, 42, 0.3);
        background: #ffffff;
    }

    .manual-stock-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        padding: 1.1rem 1.3rem;
        background: linear-gradient(140deg, #0284c7, #0369a1 64%, #065f86);
        color: #ffffff;
    }

    .manual-stock-title {
        font-size: 1.08rem;
        font-weight: 800;
        letter-spacing: 0.01em;
        line-height: 1.25;
        margin-bottom: 0.2rem;
        color: #ffffff !important;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.14);
    }

    .manual-stock-subtitle {
        font-size: 0.83rem;
        opacity: 0.95;
        line-height: 1.35;
        color: #f0f9ff !important;
    }

    .manual-stock-head * {
        color: #ffffff;
    }

    .manual-stock-close {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border: 0;
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.15);
        color: #ffffff;
        font-size: 1.2rem;
        cursor: pointer;
        transition: background 0.2s ease;
    }

    .manual-stock-close:hover {
        background: rgba(255, 255, 255, 0.26);
    }

    .manual-stock-body {
        padding: 1.15rem 1.3rem 1.2rem;
        background: linear-gradient(180deg, #f8fbff, #ffffff 26%);
    }

    .manual-stock-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #075985;
        background: #dff4ff;
        border: 1px solid #bfe7fb;
        border-radius: 999px;
        padding: 0.24rem 0.55rem;
        margin-bottom: 0.85rem;
    }

    .manual-stock-grid {
        display: grid;
        grid-template-columns: minmax(190px, 1fr) minmax(130px, 0.8fr) 110px 120px;
        gap: 0.78rem;
        margin-bottom: 0.95rem;
    }

    .manual-stock-body .form-label {
        display: block;
        color: #1e293b;
        font-size: 0.82rem;
        font-weight: 700;
        margin-bottom: 0.34rem;
    }

    .manual-stock-body .form-control {
        border: 1px solid #bfd4e6;
        border-radius: 10px;
        background: #ffffff;
        color: #0f172a;
        min-height: 40px;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .manual-stock-body .form-control:focus {
        border-color: #0284c7;
        box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.14);
        outline: none;
    }

    .manual-stock-notes {
        margin-bottom: 1rem;
    }

    .manual-stock-notes textarea.form-control {
        min-height: 84px;
        resize: vertical;
    }

    .manual-stock-help {
        font-size: 0.78rem;
        color: #64748b;
        margin-top: 0.32rem;
    }

    .manual-stock-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.85rem;
        padding-top: 0.2rem;
    }

    .manual-stock-actions-note {
        font-size: 0.78rem;
        color: #64748b;
    }

    .manual-stock-actions .btn {
        min-width: 116px;
        height: 38px;
        border-radius: 10px;
        font-weight: 700;
    }

    @media (max-width: 700px) {
        .manual-stock-panel {
            max-width: 100%;
            border-radius: 14px;
        }

        .manual-stock-head,
        .manual-stock-body {
            padding-left: 0.95rem;
            padding-right: 0.95rem;
        }

        .manual-stock-grid {
            grid-template-columns: 1fr;
        }

        .manual-stock-actions {
            flex-direction: column;
            align-items: stretch;
        }

        .manual-stock-actions .btn {
            width: 100%;
        }

        .manual-stock-actions-right {
            display: grid;
            gap: 0.5rem;
        }
    }
</style>

<div id="manualStockModal" class="manual-stock-modal">
    <div class="card manual-stock-panel">
        <div class="manual-stock-head">
            <div>
                <div class="manual-stock-title" style="color:#ffffff !important;">Tambah Stok Manual</div>
                <div class="manual-stock-subtitle" style="color:#ffffff !important; opacity:0.95;">Gunakan untuk stok awal atau barang lokal yang tidak datang dari Gudang Nasita.</div>
            </div>
            <button type="button" class="manual-stock-close" onclick="closeManualStockModal()" aria-label="Tutup popup">&times;</button>
        </div>

        <form method="POST" class="manual-stock-body" onsubmit="return confirm('Tambah stok manual sekarang?')">
            <input type="hidden" name="action" value="add_manual_stock_business">

            <div class="manual-stock-badge">
                <i data-feather="edit-3" style="width:12px; height:12px;"></i>
                Input Manual
            </div>

            <div class="manual-stock-grid">
                <div>
                    <label class="form-label">Nama item</label>
                    <input type="text" name="item_name" id="manual_item_name" class="form-control" list="manualStockItemList" autocomplete="off" placeholder="Contoh: Gula Pasir" required>
                </div>
                <div>
                    <label class="form-label">Kategori</label>
                    <input type="text" name="category" id="manual_item_category" class="form-control" list="manualStockCategoryList" placeholder="Bahan" required>
                </div>
                <div>
                    <label class="form-label">Unit</label>
                    <input type="text" name="unit" id="manual_item_unit" class="form-control" value="pcs" required>
                </div>
                <div>
                    <label class="form-label">Qty</label>
                    <input type="number" name="quantity" class="form-control" min="0.01" step="0.01" required>
                </div>
            </div>

            <div class="form-group manual-stock-notes">
                <label class="form-label">Catatan (opsional)</label>
                <textarea name="notes" class="form-control" placeholder="Misal: stok awal existing di outlet"></textarea>
                <div class="manual-stock-help">Catatan membantu tim melacak asal stok manual.</div>
            </div>

            <datalist id="manualStockCategoryList">
                <option value="Bahan Makanan"></option>
                <option value="Minuman"></option>
                <option value="Bumbu"></option>
                <option value="Kebersihan"></option>
                <option value="Perlengkapan"></option>
                <option value="Lainnya"></option>
            </datalist>

            <datalist id="manualStockItemList">
                <?php foreach ($manualItemSuggestions as $entry): ?>
                    <option value="<?php echo htmlspecialchars((string)($entry['item_name'] ?? '')); ?>"></option>
                <?php endforeach; ?>
            </datalist>

            <div class="manual-stock-actions">
                <div class="manual-stock-actions-note">Perubahan stok langsung tercatat ke bisnis aktif.</div>
                <div class="manual-stock-actions-right" style="display:flex; gap:0.5rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeManualStockModal()">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i data-feather="plus" style="width:14px; height:14px;"></i>
                        Simpan Stok
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="transferStockModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9999; align-items:center; justify-content:center; padding:1rem;">
    <div class="card" style="max-width:560px; width:100%; margin:0; border-radius:1rem; overflow:hidden; box-shadow:0 24px 64px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:center; padding:1rem 1.25rem; background:linear-gradient(135deg,#4f46e5,#3730a3); color:#fff;">
            <div>
                <div style="font-size:1rem; font-weight:700;">Transfer Stock Antar Bisnis</div>
                <div style="font-size:0.82rem; opacity:0.9;" id="transferModalItemLabel">-</div>
            </div>
            <button type="button" onclick="closeTransferModal()" style="background:transparent; border:none; color:#fff; font-size:1.4rem; cursor:pointer;">&times;</button>
        </div>

        <form method="POST" style="padding:1rem 1.25rem;" onsubmit="return confirm('Kirim stok ke bisnis tujuan?')">
            <input type="hidden" name="action" value="transfer_stock_business">
            <input type="hidden" name="item_name" id="transfer_item_name" value="">
            <input type="hidden" name="unit" id="transfer_unit" value="">

            <div style="display:grid; grid-template-columns:1fr 140px; gap:0.75rem; margin-bottom:0.85rem;">
                <div>
                    <label class="form-label">Tujuan bisnis</label>
                    <select name="target_business_slug" class="form-control" required>
                        <option value="">Pilih bisnis tujuan</option>
                        <?php foreach ($transferBusinessOptions as $slug => $biz): ?>
                            <?php if (strtolower($slug) === $activeBusinessSlug): continue;
                            endif; ?>
                            <option value="<?php echo htmlspecialchars($slug); ?>"><?php echo htmlspecialchars($biz['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Qty</label>
                    <input type="number" name="transfer_qty" id="transfer_qty" min="0.01" step="0.01" class="form-control" placeholder="Qty" required>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:0.75rem;">
                <label class="form-label">Catatan (opsional)</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Misal: transfer operasional antar outlet"></textarea>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; gap:0.75rem;">
                <div id="transferQtyInfo" style="font-size:0.82rem; color:#64748b;">Stok tersedia: -</div>
                <div style="display:flex; gap:0.5rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeTransferModal()">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i data-feather="send" style="width:14px; height:14px;"></i>
                        Transfer Stock
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    var manualItemMeta = <?php echo json_encode($manualItemMetaJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var businessStockItemMeta = <?php echo json_encode($businessStockItemMetaJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    function normalizeManualName(value) {
        return String(value || '').toLowerCase().trim().replace(/\s+/g, ' ');
    }

    function applyDailyOutMeta() {
        var itemInput = document.getElementById('dailyOutItemName');
        var unitInput = document.getElementById('dailyOutUnit');
        if (!itemInput || !unitInput) {
            return;
        }

        var key = normalizeManualName(itemInput.value || '');
        if (!key || !businessStockItemMeta[key]) {
            return;
        }

        var meta = businessStockItemMeta[key];
        itemInput.value = meta.item_name || itemInput.value;
        if (meta.unit) {
            unitInput.value = meta.unit;
        }
    }

    function applyExistingManualItemMeta() {
        var nameInput = document.getElementById('manual_item_name');
        var categoryInput = document.getElementById('manual_item_category');
        var unitInput = document.getElementById('manual_item_unit');

        if (!nameInput || !categoryInput || !unitInput) {
            return;
        }

        var key = normalizeManualName(nameInput.value);
        if (!key || !manualItemMeta[key]) {
            return;
        }

        var existing = manualItemMeta[key];
        if (existing.item_name) {
            nameInput.value = existing.item_name;
        }
        if (existing.category && categoryInput.value.trim() === '') {
            categoryInput.value = existing.category;
        }
        if (existing.unit && (unitInput.value.trim() === '' || unitInput.value.toLowerCase().trim() === 'pcs')) {
            unitInput.value = existing.unit;
        }
    }

    function openManualStockModal() {
        var modal = document.getElementById('manualStockModal');
        modal.style.display = 'flex';

        var nameInput = document.getElementById('manual_item_name');
        if (nameInput) {
            setTimeout(function() {
                nameInput.focus();
            }, 80);
        }
    }

    function openManualStockModalPreset(itemName, unit) {
        openManualStockModal();

        var nameInput = document.getElementById('manual_item_name');
        var unitInput = document.getElementById('manual_item_unit');
        if (nameInput) {
            nameInput.value = itemName || '';
            applyExistingManualItemMeta();
        }
        if (unitInput && unit) {
            unitInput.value = unit;
        }
    }

    function closeManualStockModal() {
        var modal = document.getElementById('manualStockModal');
        modal.style.display = 'none';
    }

    (function bindManualStockAutocomplete() {
        var nameInput = document.getElementById('manual_item_name');
        if (!nameInput) {
            return;
        }

        nameInput.addEventListener('change', applyExistingManualItemMeta);
        nameInput.addEventListener('blur', applyExistingManualItemMeta);
    })();

    function filterStockRows() {
        var input = document.getElementById('stockSearchInput');
        var rows = document.querySelectorAll('.stock-row');
        var counter = document.getElementById('stockSearchCounter');
        if (!input || !rows.length) {
            return;
        }

        var term = normalizeManualName(input.value || '');
        var visibleCount = 0;

        rows.forEach(function(row) {
            var hay = String(row.getAttribute('data-search') || '').toLowerCase();
            var match = term === '' || hay.indexOf(term) !== -1;
            row.style.display = match ? '' : 'none';
            if (match) {
                visibleCount++;
            }
        });

        if (counter) {
            counter.textContent = 'Menampilkan ' + visibleCount + ' item';
        }
    }

    function clearStockSearch() {
        var input = document.getElementById('stockSearchInput');
        if (input) {
            input.value = '';
            filterStockRows();
            input.focus();
        }
    }

    (function bindStockSearch() {
        var input = document.getElementById('stockSearchInput');
        if (!input) {
            return;
        }

        input.addEventListener('input', filterStockRows);
        filterStockRows();
    })();

    function openTransferModal(itemName, unit, maxQty) {
        var modal = document.getElementById('transferStockModal');
        var itemInput = document.getElementById('transfer_item_name');
        var unitInput = document.getElementById('transfer_unit');
        var qtyInput = document.getElementById('transfer_qty');
        var label = document.getElementById('transferModalItemLabel');
        var qtyInfo = document.getElementById('transferQtyInfo');

        itemInput.value = itemName;
        unitInput.value = unit;
        qtyInput.value = '';
        qtyInput.max = maxQty;
        label.textContent = itemName + ' (' + unit + ')';
        qtyInfo.textContent = 'Stok tersedia: ' + maxQty + ' ' + unit;

        modal.style.display = 'flex';
    }

    function closeTransferModal() {
        var modal = document.getElementById('transferStockModal');
        modal.style.display = 'none';
    }

    function openDailyOutModal() {
        var modal = document.getElementById('dailyOutBusinessModal');
        if (!modal) {
            return;
        }

        modal.style.display = 'flex';

        var itemInput = document.getElementById('dailyOutItemName');
        if (itemInput) {
            setTimeout(function() {
                itemInput.focus();
            }, 80);
        }
    }

    function closeDailyOutModal() {
        var modal = document.getElementById('dailyOutBusinessModal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    (function bindDailyOutAutocomplete() {
        var itemInput = document.getElementById('dailyOutItemName');
        var unitInput = document.getElementById('dailyOutUnit');
        if (!itemInput || !unitInput) {
            return;
        }

        itemInput.addEventListener('change', applyDailyOutMeta);
        itemInput.addEventListener('blur', applyDailyOutMeta);
    })();

    window.addEventListener('click', function(e) {
        var manualModal = document.getElementById('manualStockModal');
        var modal = document.getElementById('transferStockModal');
        var dailyModal = document.getElementById('dailyOutBusinessModal');
        if (e.target === manualModal) {
            closeManualStockModal();
        }
        if (e.target === modal) {
            closeTransferModal();
        }
        if (e.target === dailyModal) {
            closeDailyOutModal();
        }
    });
</script>

<div id="dailyOutBusinessModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:2055; align-items:center; justify-content:center; padding:1rem;">
    <div class="card" style="width:min(420px,100%);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <div>
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">Stock Keluar</div>
                <h3 style="font-size:1.05rem; margin:0.15rem 0 0; font-weight:700;">Catat Pengeluaran Harian</h3>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closeDailyOutModal()">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="record_daily_stock_out_business">
            <div style="margin-bottom:0.85rem;">
                <label class="form-label">Nama Item *</label>
                <input type="text" id="dailyOutItemName" name="item_name" class="form-control" list="dailyOutItemList" autocomplete="off" required placeholder="Ketik 1-3 huruf...">
                <datalist id="dailyOutItemList">
                    <?php foreach ($businessStockItemMetaJs as $item): ?>
                        <option value="<?php echo htmlspecialchars((string)($item['item_name'] ?? '')); ?>">
                        <?php endforeach; ?>
                </datalist>
            </div>
            <div style="margin-bottom:0.85rem;">
                <label class="form-label">Unit *</label>
                <input type="text" name="unit" id="dailyOutUnit" class="form-control" value="pcs" required>
            </div>
            <div style="margin-bottom:0.85rem;">
                <label class="form-label">Qty Keluar *</label>
                <input type="number" name="quantity" class="form-control" step="0.01" min="0.01" required placeholder="0">
            </div>
            <div style="margin-bottom:1rem;">
                <label class="form-label">Catatan</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="Misal: penggunaan operasional, rusak, atau kebutuhan harian"></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeDailyOutModal()">Batal</button>
                <button type="submit" class="btn btn-warning" style="font-weight:700; color:#111827;">Simpan Stock Keluar</button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>