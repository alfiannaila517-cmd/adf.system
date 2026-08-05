<?php
/**
 * Fix Duplicate Divisions
 * Remove duplicate divisions (RENTCAR, RC, CR) to sync with actual invoice data
 * Keep only: CAR_RENTAL (Car Rental)
 */

require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();
$results = [];

try {
    // Deactivate duplicate divisions
    $duplicateCodes = ['RENTCAR', 'RC', 'CR'];
    
    foreach ($duplicateCodes as $code) {
        $db->query(
            "UPDATE divisions SET is_active = 0 WHERE division_code = ? AND is_active = 1",
            [$code]
        );
        $results[] = "✓ Deactivated division: {$code}";
    }

    // Show remaining active divisions
    $activeDivisions = $db->fetchAll(
        "SELECT id, division_code, division_name, is_active FROM divisions WHERE is_active = 1 ORDER BY division_code"
    );

    echo "<h2>✅ DIVISIONS CLEANUP COMPLETE</h2>";
    echo "<p>Duplicate divisions deactivated. Active divisions:</p>";
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>ID</th><th>Code</th><th>Name</th><th>Active</th></tr>";
    
    foreach ($activeDivisions as $div) {
        echo "<tr>";
        echo "<td>{$div['id']}</td>";
        echo "<td>{$div['division_code']}</td>";
        echo "<td>{$div['division_name']}</td>";
        echo "<td>" . ($div['is_active'] ? '✓ Yes' : '✗ No') . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    echo "<h3>Operations Performed:</h3>";
    foreach ($results as $r) {
        echo "<p>{$r}</p>";
    }

    echo "<p><a href='modules/divisions/index.php'>← Back to Divisions</a></p>";

} catch (\Throwable $e) {
    echo "<h2>❌ ERROR</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
