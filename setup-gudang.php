<?php
/**
 * Setup Script - Gudang Nasita Database Tables
 * Run this once to initialize Gudang Nasita warehouse system
 */

require_once __DIR__ . '/config/database.php';

try {
    echo "[Gudang Nasita Setup]\n";
    echo "=" . str_repeat("-", 50) . "\n\n";
    
    $db = Database::getInstance();
    $masterDb = Database::switchDatabase(DB_NAME);
    
    // SQL Setup
    $sql_file = __DIR__ . '/sql/setup-gudang-nasita.sql';
    if (!file_exists($sql_file)) {
        throw new Exception("SQL file not found: " . $sql_file);
    }
    
    $sql_content = file_get_contents($sql_file);
    $statements = array_filter(array_map('trim', explode(';', $sql_content)));
    
    $success_count = 0;
    $skip_count = 0;
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos(trim($statement), '--') === 0) {
            continue;
        }
        
        try {
            $db->execute($statement);
            $success_count++;
            echo "✓ Executed: " . substr(trim($statement), 0, 60) . "...\n";
        } catch (Exception $e) {
            // Ignore "table already exists" type errors
            if (strpos($e->getMessage(), 'already exists') !== false) {
                $skip_count++;
                echo "⊘ Skipped (already exists): " . substr(trim($statement), 0, 50) . "...\n";
            } else {
                echo "✗ ERROR: " . $e->getMessage() . "\n";
                throw $e;
            }
        }
    }
    
    echo "\n" . str_repeat("-", 50) . "\n";
    echo "Setup completed!\n";
    echo "✓ Executed: {$success_count} statements\n";
    echo "⊘ Skipped: {$skip_count} statements (already exist)\n";
    echo "\nGudang Nasita system is ready!\n";
    echo "Business added: Gudang Nasita (warehouse)\n";
    echo "\nNext steps:\n";
    echo "1. Go to Developer Panel > User Permissions\n";
    echo "2. Select 'Gudang Nasita' from business dropdown\n";
    echo "3. Assign warehouse permissions to your user\n";
    echo "4. Login & select 'Gudang Nasita' from business dropdown\n";
    echo "5. Menu 'Gudang Nasita' will appear in sidebar\n";
    
} catch (Exception $e) {
    echo "\n✗ Setup failed: " . $e->getMessage() . "\n";
    http_response_code(500);
    exit(1);
}
?>
