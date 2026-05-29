<?php
/**
 * One-time setup: register or update addon_domain for a business slug.
 *
 * Usage (browser, logged in as admin OR run once then DELETE this file):
 *   /setup-addon-domain.php?slug=pwf-furniture&domain=pwfoffice.com
 *
 * Or edit the defaults below and just hit /setup-addon-domain.php
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$slug   = trim($_GET['slug']   ?? 'pwf-furniture');
$domain = strtolower(trim($_GET['domain'] ?? 'pwfoffice.com'));
$domain = preg_replace('/^https?:\/\//', '', $domain);
$domain = preg_replace('/^www\./', '', $domain);

if ($slug === '' || $domain === '') {
    exit("Provide ?slug=...&domain=...\n");
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Ensure column exists
    try {
        $pdo->query("SELECT addon_domain FROM businesses LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN addon_domain VARCHAR(190) NULL UNIQUE AFTER slug");
        echo "✓ Added column businesses.addon_domain\n";
    }

    $stmt = $pdo->prepare("SELECT * FROM businesses WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        exit("✗ Business slug '$slug' not found in businesses table.\n");
    }
    $bizLabel = $row['business_name'] ?? $row['name'] ?? $row['display_name'] ?? $slug;

    $upd = $pdo->prepare("UPDATE businesses SET addon_domain = ?, is_active = 1 WHERE id = ?");
    $upd->execute([$domain, $row['id']]);

    echo "✓ Business: {$bizLabel} (slug=$slug)\n";
    echo "✓ addon_domain set to: $domain\n";
    echo "✓ is_active = 1\n\n";

    $check = $pdo->prepare("SELECT slug, addon_domain, is_active FROM businesses WHERE slug=?");
    $check->execute([$slug]);
    print_r($check->fetch(PDO::FETCH_ASSOC));

    echo "\nNext steps:\n";
    echo "1. Pastikan addon domain 'www.$domain' di cPanel sudah point ke folder project ini.\n";
    echo "2. Aktifkan SSL (Let's Encrypt) untuk $domain & www.$domain.\n";
    echo "3. Buka https://www.$domain — akan auto-redirect ke /pwf-login.php\n";
    echo "4. HAPUS file ini (setup-addon-domain.php) setelah selesai.\n";
} catch (Exception $e) {
    exit("Error: " . $e->getMessage() . "\n");
}
