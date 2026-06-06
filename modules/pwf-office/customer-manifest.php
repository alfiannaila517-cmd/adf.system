<?php
/**
 * Customer Portal PWA manifest
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/db-helper.php';

header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=300');

$pdo = getPwfOfficePdo();
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$customerCode = strtoupper(trim((string)($_GET['c'] ?? '')));
$customerCode = preg_replace('/[^A-Z0-9\-]/', '', $customerCode);

$companyName = 'PWF Customer Portal';
$iconUrl = $baseUrl . '/favicon.ico';
$iconType = 'image/png';

try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('pwf_company_name','pwf_favicon','pwf_login_logo')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($rows['pwf_company_name'])) {
        $companyName = $rows['pwf_company_name'] . ' Customer Portal';
    }

    $iconCandidate = '';
    if (!empty($rows['pwf_favicon'])) {
        $iconCandidate = $rows['pwf_favicon'];
    } elseif (!empty($rows['pwf_login_logo'])) {
        $iconCandidate = $rows['pwf_login_logo'];
    }

    if ($iconCandidate !== '') {
        $iconUrl = (strpos($iconCandidate, 'http') === 0)
            ? $iconCandidate
            : $baseUrl . '/' . ltrim($iconCandidate, '/');
        $ext = strtolower(pathinfo(parse_url($iconUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            $iconType = 'image/jpeg';
        } elseif ($ext === 'svg') {
            $iconType = 'image/svg+xml';
        } else {
            $iconType = 'image/png';
        }
    }
} catch (Exception $e) {
}

$startUrl = $baseUrl . '/modules/pwf-office/customer-portal.php';
if ($customerCode !== '') {
    $startUrl .= '?c=' . urlencode($customerCode);
}

while (ob_get_level()) {
    ob_end_clean();
}

echo json_encode([
    'id' => '/modules/pwf-office/customer-portal' . ($customerCode !== '' ? '?c=' . $customerCode : ''),
    'name' => $companyName,
    'short_name' => 'Customer Portal',
    'description' => 'Monitoring order customer: progress jadi, qty kontainer, dan rekap bulanan',
    'start_url' => $startUrl,
    'scope' => $baseUrl . '/modules/pwf-office/',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'theme_color' => '#0E223D',
    'background_color' => '#0E223D',
    'lang' => 'id',
    'prefer_related_applications' => false,
    'icons' => [
        [
            'src' => $iconUrl,
            'sizes' => '192x192',
            'type' => $iconType,
            'purpose' => 'any maskable',
        ],
        [
            'src' => $iconUrl,
            'sizes' => '512x512',
            'type' => $iconType,
            'purpose' => 'any maskable',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
