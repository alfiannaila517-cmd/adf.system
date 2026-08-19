<?php
// ONE-TIME correction: hotel_invoice_items.created_at was bulk-backfilled by the ALTER TABLE
// migration to the exact migration-run timestamp for ALL pre-existing rows, so old invoices
// showed "today" instead of a sensible date. This resets those specific rows' created_at to
// their parent invoice's own created_at (the best available proxy for old data, since the
// true per-item add date was never recorded before this feature existed).
// Usage: fix-invoice-item-dates.php?token=fix-inv-dates-2026-08-19&business=narayana-hotel&confirm=1
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain');

if (($_GET['token'] ?? '') !== 'fix-inv-dates-2026-08-19') {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

$MIGRATION_TIMESTAMP = '2026-08-19 18:28:52';
$slug = (string)($_GET['business'] ?? 'narayana-hotel');

try {
    $cfgPath = __DIR__ . '/config/businesses/' . $slug . '.php';
    if (!file_exists($cfgPath)) {
        echo "Business config not found: $slug\n";
        exit;
    }
    $cfg = require $cfgPath;
    $dbName = (string)($cfg['database'] ?? '');
    $originDb = Database::getCurrentDatabase();
    $db = ($dbName && $dbName !== $originDb) ? Database::switchDatabase($dbName) : Database::getInstance();

    $affected = $db->fetchOne(
        "SELECT COUNT(*) AS c FROM hotel_invoice_items WHERE created_at = ?",
        [$MIGRATION_TIMESTAMP]
    );
    echo "Rows with migration-backfilled created_at ({$MIGRATION_TIMESTAMP}): " . ($affected['c'] ?? 0) . "\n";

    if (($_GET['confirm'] ?? '') === '1') {
        $pdo = $db->getConnection();
        $stmt = $pdo->prepare(
            "UPDATE hotel_invoice_items hii
             JOIN hotel_invoices hi ON hi.id = hii.invoice_id
             SET hii.created_at = hi.created_at
             WHERE hii.created_at = ?"
        );
        $stmt->execute([$MIGRATION_TIMESTAMP]);
        echo "Updated rows: " . $stmt->rowCount() . "\n";
    } else {
        echo "Dry run only. Add &confirm=1 to apply.\n";
    }

    if ($dbName && $dbName !== $originDb) {
        Database::switchDatabase($originDb);
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
