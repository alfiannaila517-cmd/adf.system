<?php
/**
 * Quick Insert - Gudang Nasita Bisnis
 * Jika setup-gudang.php tidak bekerja, gunakan script ini
 */

require_once __DIR__ . '/config/database.php';

try {
    echo "[Insert Gudang Nasita Bisnis]\n";
    echo str_repeat("=", 50) . "\n\n";
    
    $db = Database::getInstance();
    $masterDb = Database::switchDatabase(DB_NAME);
    
    // Check if Gudang Nasita already exists
    $existing = $masterDb->fetchOne(
        "SELECT id FROM businesses WHERE slug = 'gudang-nasita' LIMIT 1"
    );
    
    if ($existing) {
        echo "✓ Gudang Nasita sudah ada (ID: {$existing['id']})\n";
        echo "Jika ingin mengubah, silakan edit di Developer Panel > Businesses\n";
        exit;
    }
    
    // Insert Gudang Nasita
    $masterDb->execute(
        "INSERT INTO businesses (
            business_name, business_code, slug, database_name, 
            business_type, is_active, status, created_at
        ) VALUES (
            'Gudang Nasita', 'gudang-nasita', 'gudang-nasita', 
            'adfb2574_adf', 'warehouse', 1, 'active', NOW()
        )"
    );
    
    $businessId = $masterDb->lastInsertId();
    
    echo "✓ Bisnis Gudang Nasita berhasil dibuat!\n";
    echo "  ID: {$businessId}\n";
    echo "  Name: Gudang Nasita\n";
    echo "  Slug: gudang-nasita\n";
    echo "  Database: adfb2574_adf\n";
    echo "  Type: warehouse\n\n";
    
    echo "Langkah berikutnya:\n";
    echo "1. Refresh browser Permissions page\n";
    echo "2. Gudang Nasita akan muncul di dropdown bisnis\n";
    echo "3. Pilih Gudang Nasita dan assign permissions\n";
    
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    http_response_code(500);
    exit(1);
}
?>
