<?php
// TEMP: sync Eat Meet (business_id=11) business_menu_config to match Bens Cafe (business_id=2)
// Remove after use.
if (($_GET['token'] ?? '') !== 'adf-deploy-2025-secure') { http_response_code(403); exit('forbidden'); }
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json');

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . MASTER_DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $bensId = $pdo->query("SELECT id FROM businesses WHERE business_name LIKE '%Bens%'")->fetchColumn();
    $eatMeet = $pdo->query("SELECT * FROM businesses WHERE database_name LIKE '%eat_meet%' OR business_name LIKE '%Eat Meet%'")->fetch(PDO::FETCH_ASSOC);

    if (!$bensId || !$eatMeet) {
        echo json_encode(['error' => 'business not found', 'bensId' => $bensId, 'eatMeet' => $eatMeet]);
        exit;
    }
    $eatMeetId = $eatMeet['id'];

    if (($_GET['apply'] ?? '') === '1') {
        $pdo->prepare("DELETE FROM business_menu_config WHERE business_id = ?")->execute([$eatMeetId]);
        $rows = $pdo->prepare("SELECT menu_id, is_enabled FROM business_menu_config WHERE business_id = ?");
        $rows->execute([$bensId]);
        $ins = $pdo->prepare("INSERT INTO business_menu_config (business_id, menu_id, is_enabled) VALUES (?, ?, ?)");
        $count = 0;
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ins->execute([$eatMeetId, $r['menu_id'], $r['is_enabled']]);
            $count++;
        }
        echo json_encode(['applied' => true, 'rows_copied' => $count, 'eat_meet_business' => $eatMeet]);
    } else {
        $rows = $pdo->prepare("SELECT m.menu_code, m.menu_name, bmc.is_enabled FROM business_menu_config bmc JOIN menu_items m ON m.id = bmc.menu_id WHERE bmc.business_id = ? ORDER BY m.id");
        $rows->execute([$bensId]);
        echo json_encode([
            'preview_only' => true,
            'eat_meet_business' => $eatMeet,
            'bens_cafe_menus' => $rows->fetchAll(PDO::FETCH_ASSOC)
        ], JSON_PRETTY_PRINT);
    }
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
