<?php

/**
 * Diagnostic: shows exactly what business the current session resolves to, and whether
 * the incoming domain's addon_domain lookup in the `businesses` table succeeds. Login
 * required. Delete after use.
 */

define('APP_ACCESS', true);

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

try {
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/auth.php';

    $auth = new Auth();
    $auth->requireLogin();

    $incomingHost = strtolower(preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? ''));

    $masterPdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $stmt = $masterPdo->prepare(
        "SELECT slug, business_type, addon_domain, is_active FROM businesses WHERE LOWER(REPLACE(addon_domain,'www.','')) = ? "
    );
    $stmt->execute([$incomingHost]);
    $matches = $stmt->fetchAll();

    $allBiz = $masterPdo->query("SELECT slug, name, addon_domain, is_active FROM businesses ORDER BY slug")->fetchAll();

    echo "HTTP_HOST (raw): " . ($_SERVER['HTTP_HOST'] ?? '(kosong)') . "\n";
    echo "Incoming host (normalized, no www): {$incomingHost}\n";
    echo "MASTER_DOMAIN constant: " . (defined('MASTER_DOMAIN') ? MASTER_DOMAIN : '(tidak terdefinisi)') . "\n\n";

    echo "=== SESSION & ACTIVE BUSINESS ===\n";
    echo "\$_SESSION['active_business_id']: " . ($_SESSION['active_business_id'] ?? '(kosong)') . "\n";
    echo "\$_SESSION['business_id']: " . ($_SESSION['business_id'] ?? '(kosong)') . "\n";
    echo "ACTIVE_BUSINESS_ID constant: " . (defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : '(tidak terdefinisi)') . "\n";
    echo "BUSINESS_NAME constant: " . (defined('BUSINESS_NAME') ? BUSINESS_NAME : '(tidak terdefinisi)') . "\n\n";

    echo "=== ADDON_DOMAIN LOOKUP UNTUK HOST INI ({$incomingHost}) ===\n";
    if (count($matches) === 0) {
        echo "TIDAK DITEMUKAN business dengan addon_domain = '{$incomingHost}' di tabel businesses!\n";
        echo "Ini kemungkinan penyebab bug: karena tidak match, session TIDAK di-override, jadi\n";
        echo "active_business_id lama (dari sesi login sebelumnya) tetap dipakai.\n";
    } else {
        foreach ($matches as $m) {
            echo "slug={$m['slug']} | business_type={$m['business_type']} | addon_domain={$m['addon_domain']} | is_active={$m['is_active']}\n";
        }
    }

    echo "\n=== SEMUA BUSINESS + ADDON_DOMAIN (untuk cross-check manual) ===\n";
    foreach ($allBiz as $b) {
        $flag = (strtolower(preg_replace('/^www\./', '', (string)$b['addon_domain'])) === $incomingHost) ? '  <== MATCH' : '';
        echo "slug={$b['slug']} | name={$b['name']} | addon_domain=" . ($b['addon_domain'] ?: '(kosong)') . " | is_active={$b['is_active']}{$flag}\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}
