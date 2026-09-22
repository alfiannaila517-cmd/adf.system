<?php

/**
 * Travel Bureau Business - Admin Modules Diagnostic & Fix
 * ------------------------------------------------------------------
 * Generic tool for ANY travel_bureau addon-domain business (not just
 * Sunsea/karimunjawaexplore). Use this whenever a new travel business
 * is cloned/created and its "Login Admin" dashboard shows the wrong
 * (or empty) menu instead of Booking Calendar / Customers / Partners
 * (hotel db) / Invoices / Settings.
 *
 * Root cause this fixes: config/businesses/{slug}.php only gets the
 * 'sunsea' module (which powers calendar, customers, partners, invoices,
 * settings, etc.) when businesses.business_type = 'travel_bureau' AT
 * THE TIME the config file was first auto-generated. If the row was
 * created with a different/empty type first, the config file is stuck
 * without that module forever (auto-sync skips existing files).
 *
 * Usage: log in as admin/developer, then open:
 *   https://linewisatakarimunjawa.com/travel-bureau-fix.php?domain=linewisatakarimunjawa.com
 * (or just /travel-bureau-fix.php - domain defaults to the current Host)
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/business_helper.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$DOMAIN = $_GET['domain'] ?? strtolower(preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? ''));
$actionMessage = '';

function checkRow(string $label, bool $ok, string $detail = ''): string
{
    $icon = $ok ? "<span class='ok'>✓</span>" : "<span class='error'>✗</span>";
    $detailHtml = $detail !== '' ? " - " . htmlspecialchars($detail) : '';
    return "<p>{$icon} <strong>" . htmlspecialchars($label) . "</strong>{$detailHtml}</p>";
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    die('DB connect failed: ' . htmlspecialchars($e->getMessage()));
}

$stmt = $pdo->prepare("SELECT * FROM businesses WHERE LOWER(REPLACE(addon_domain,'www.','')) = ? LIMIT 1");
$stmt->execute([$DOMAIN]);
$biz = $stmt->fetch();

if (($_GET['action'] ?? '') === 'fix' && $_SERVER['REQUEST_METHOD'] === 'POST' && $biz) {
    try {
        $pdo->prepare("UPDATE businesses SET business_type = 'travel_bureau' WHERE id = ?")->execute([$biz['id']]);

        $slug = $biz['slug'];
        $configFile = __DIR__ . '/config/businesses/' . $slug . '.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            $modules = $config['enabled_modules'] ?? [];
            if (!in_array('sunsea', $modules, true)) {
                $modules[] = 'sunsea';
            }
            $config['business_type'] = 'travel_bureau';
            $config['enabled_modules'] = $modules;

            $exported = var_export($config, true);
            @file_put_contents($configFile, "<?php\nreturn {$exported};\n");
            $actionMessage = "<p class='ok'>✓ business_type di-set ke 'travel_bureau' dan module 'sunsea' ditambahkan ke {$slug}.php</p>";
        } else {
            $actionMessage = "<p class='ok'>✓ business_type di-set ke 'travel_bureau'. Config file belum ada, akan dibuat otomatis (dengan module sunsea) saat halaman apapun di sistem diakses.</p>";
        }

        // Re-fetch for display below
        $stmt->execute([$DOMAIN]);
        $biz = $stmt->fetch();
    } catch (Exception $e) {
        $actionMessage = "<p class='error'>✗ Gagal: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Travel Bureau - Modules Diagnostic</title>";
echo "<style>body{font-family:monospace;margin:20px;background:#0f172a;color:#e2e8f0;} .ok{color:#4ade80;} .error{color:#f87171;} .info{color:#60a5fa;} h1,h2{color:#fff;} .box{background:#1e293b;padding:16px 20px;border-radius:10px;margin-bottom:16px;} pre{background:#0f172a;padding:10px;border-radius:6px;overflow:auto;white-space:pre-wrap;} button{background:#f97316;color:#fff;border:none;padding:10px 18px;border-radius:6px;cursor:pointer;font-family:monospace;font-size:14px;} a{color:#60a5fa;}</style>";
echo "</head><body>";
echo "<h1>🌊 Travel Bureau - Admin Modules Diagnostic</h1>";
echo "<p>Domain: <strong>" . htmlspecialchars($DOMAIN) . "</strong></p>";

if ($actionMessage) {
    echo "<div class='box'>{$actionMessage}</div>";
}

echo "<div class='box'><h2>1. Business row (by addon_domain)</h2>";
if (!$biz) {
    echo checkRow('addon_domain match', false, 'Tidak ada business dengan addon_domain = ' . $DOMAIN);
} else {
    echo "<p><strong>Slug:</strong> " . htmlspecialchars($biz['slug']) . " | <strong>Nama:</strong> " . htmlspecialchars($biz['business_name']) . "</p>";
    echo checkRow('business_type', $biz['business_type'] === 'travel_bureau', $biz['business_type'] ?: '(kosong)');

    $configFile = __DIR__ . '/config/businesses/' . $biz['slug'] . '.php';
    echo "<h2>2. Config file: config/businesses/" . htmlspecialchars($biz['slug']) . ".php</h2>";
    if (!file_exists($configFile)) {
        echo checkRow('File ada', false, 'Belum di-generate');
    } else {
        $config = require $configFile;
        $modules = $config['enabled_modules'] ?? [];
        echo checkRow('File ada', true);
        echo checkRow("Module 'sunsea' (booking calendar, customers, partners/hotel db, invoices, settings)", in_array('sunsea', $modules, true));
        echo "<pre>enabled_modules = " . htmlspecialchars(json_encode($modules)) . "</pre>";
    }

    echo "<form method='post' action='?action=fix&domain=" . urlencode($DOMAIN) . "'><button type='submit'>🔧 Set business_type = travel_bureau + tambah module 'sunsea'</button></form>";
}
echo "</div>";

echo "<p><a href='" . BASE_URL . "/index.php'>&larr; Kembali ke dashboard</a></p>";
echo "</body></html>";
