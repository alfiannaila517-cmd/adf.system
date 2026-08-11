<?php
// One-shot migration for Gudang Nasita stock schema compatibility.
// Usage:
//   /c/xampp/php/php.exe tools/migrate_gudang_nasita_stock_code.php
//   /c/xampp/php/php.exe tools/migrate_gudang_nasita_stock_code.php <db_name>

require_once __DIR__ . '/../config/config.php';

function connectDbByCandidates(array $candidates)
{
    foreach ($candidates as $dbName) {
        if (!is_string($dbName) || trim($dbName) === '') {
            continue;
        }
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=' . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            return [$pdo, $dbName];
        } catch (Throwable $e) {
            // Try next candidate.
        }
    }

    return [null, null];
}

function hasColumn(PDO $pdo, $table, $column)
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch();
}

function hasIndex(PDO $pdo, $table, $indexName)
{
    $stmt = $pdo->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = ?");
    $stmt->execute([$indexName]);
    return (bool)$stmt->fetch();
}

$argDb = $argv[1] ?? '';
$cfgPath = __DIR__ . '/../config/businesses/gudang-nasita.php';
$cfgDb = '';
if (file_exists($cfgPath)) {
    $cfg = require $cfgPath;
    $cfgDb = (string)($cfg['database'] ?? '');
}

$candidates = [];
if ($argDb !== '') {
    $candidates[] = $argDb;
}
if ($cfgDb !== '') {
    $candidates[] = $cfgDb;
    if ($cfgDb === 'adfb2574_adf') {
        $candidates[] = 'adf_system';
    }
}
$candidates[] = DB_NAME;
$candidates[] = 'adf_system';
$candidates = array_values(array_unique(array_filter($candidates)));

[$pdo, $dbName] = connectDbByCandidates($candidates);
if (!$pdo) {
    fwrite(STDERR, "ERROR: Tidak bisa connect ke DB kandidat: " . implode(', ', $candidates) . PHP_EOL);
    exit(1);
}

echo "Connected DB: {$dbName}" . PHP_EOL;

$table = 'gudang_nasita_stock';
$exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
if (!$exists) {
    fwrite(STDERR, "ERROR: Table {$table} tidak ditemukan di DB {$dbName}." . PHP_EOL);
    exit(1);
}

echo "Table {$table}: OK" . PHP_EOL;

if (!hasColumn($pdo, $table, 'stock_code')) {
    echo "Add column stock_code..." . PHP_EOL;
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `stock_code` VARCHAR(30) NULL AFTER `id`");
} else {
    echo "Column stock_code sudah ada." . PHP_EOL;
}

$pdo->exec("UPDATE `{$table}` SET `stock_code` = CONCAT('GN-', DATE_FORMAT(NOW(), '%Y%m'), '-', LPAD(id, 4, '0')) WHERE `stock_code` IS NULL OR `stock_code` = ''");
echo "Backfill stock_code selesai." . PHP_EOL;

if (!hasIndex($pdo, $table, 'idx_stock_code')) {
    echo "Add unique index idx_stock_code..." . PHP_EOL;
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `idx_stock_code` (`stock_code`)");
    } catch (Throwable $e) {
        // Index add can fail if duplicate codes exist from unusual historical data.
        fwrite(STDERR, "WARNING: Gagal add unique index idx_stock_code: " . $e->getMessage() . PHP_EOL);
    }
} else {
    echo "Index idx_stock_code sudah ada." . PHP_EOL;
}

$cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll();
echo PHP_EOL . "Kolom final {$table}:" . PHP_EOL;
foreach ($cols as $c) {
    echo '- ' . $c['Field'] . ' | ' . $c['Type'] . ' | ' . $c['Null'] . ' | ' . $c['Key'] . PHP_EOL;
}

echo PHP_EOL . "DONE: Migration Gudang Nasita stock_code selesai." . PHP_EOL;
