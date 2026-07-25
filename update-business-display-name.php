<?php
/**
 * One-off: rename Sunsea business display name to "Explore Karimunjawa"
 * (display/brand text only - business_id, folder, database name unchanged)
 * Run once from browser, then delete this file.
 */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'modules/sunsea/db-helper.php';

$NEW_NAME = 'Explore Karimunjawa';

try {
    // 1. Master DB: businesses.business_name (shown in Switch Business dropdown)
    $masterPdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $stmt = $masterPdo->prepare("UPDATE businesses SET business_name = ? WHERE business_code = 'SUNSEA'");
    $stmt->execute([$NEW_NAME]);
    echo "Master businesses.business_name updated: " . $stmt->rowCount() . " row(s)<br>";

    // 2. Sunsea's own DB: settings.company_name (used by sidebar/invoice/quotation)
    $sunseaPdo = getSunseaConnection();
    $stmt2 = $sunseaPdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'company_name'");
    $stmt2->execute([$NEW_NAME]);
    echo "Sunsea settings.company_name updated: " . $stmt2->rowCount() . " row(s)<br>";

    // If no row existed yet, insert it
    $check = $sunseaPdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'company_name'");
    $check->execute();
    if ((int)$check->fetchColumn() === 0) {
        $sunseaPdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('company_name', ?)")->execute([$NEW_NAME]);
        echo "Inserted company_name setting.<br>";
    }

    echo "<br><strong>Done. Delete this file now.</strong>";
} catch (Exception $e) {
    echo "Error: " . htmlspecialchars($e->getMessage());
}
