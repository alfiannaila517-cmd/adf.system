<?php

/**
 * tools/business-lock.php
 * ------------------------------------------------------------------
 * Run this ONCE on a NEW standalone server (after importing the master
 * + business SQL dumps from standalone-export.php) to hide every other
 * business, so only the target business shows up in this installation.
 *
 * This is a generalized version of the old one-off setup-lock-sunsea-only.php
 * (which was hardcoded to slug='sunsea' and never committed to git).
 * Kept in tools/ and committed to git since it's now generic/reusable for
 * every future standalone customer.
 *
 * Usage (must be logged in as owner/admin/developer):
 *   https://yourdomain.com/tools/business-lock.php?business=<slug>
 *     -> dry-run, shows what WOULD change
 *   https://yourdomain.com/tools/business-lock.php?business=<slug>&apply=1
 *     -> actually sets businesses.is_active=0 for every business except <slug>
 *
 * Delete this file from the server once you've confirmed the lock worked -
 * it can disable/enable ANY business on this install, so it must not be
 * left reachable long-term.
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$__role = $_SESSION['role'] ?? '';
if (!in_array($__role, ['owner', 'admin', 'developer'], true)) {
    http_response_code(403);
    exit("Forbidden - hanya owner/admin/developer yang boleh menjalankan tool ini.\n");
}

$businessSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($_GET['business'] ?? '')));
if ($businessSlug === '') {
    exit("Sertakan ?business=<slug> (nama file config/businesses/<slug>.php tanpa .php).\n");
}

$businessConfFile = __DIR__ . '/../config/businesses/' . $businessSlug . '.php';
if (!file_exists($businessConfFile)) {
    exit("config/businesses/{$businessSlug}.php tidak ditemukan.\n");
}

$pdo = Database::getInstance()->getConnection();

$rows = $pdo->query("SELECT id, slug, name, is_active FROM businesses ORDER BY slug")->fetchAll();
if (!$rows) {
    exit("Tabel businesses kosong atau tidak ditemukan.\n");
}

$apply = ($_GET['apply'] ?? '0') === '1';

echo "<pre>";
echo "Business target (tetap aktif): {$businessSlug}\n\n";
foreach ($rows as $row) {
    $willChange = ($row['slug'] !== $businessSlug) && (int)$row['is_active'] === 1;
    echo sprintf(
        "id=%d slug=%s is_active=%d %s\n",
        $row['id'],
        $row['slug'],
        (int)$row['is_active'],
        $willChange ? '-> akan di-nonaktifkan' . ($apply ? ' (APPLIED)' : ' (preview, tambahkan &apply=1)') : ''
    );
}

if ($apply) {
    $stmt = $pdo->prepare("UPDATE businesses SET is_active=0 WHERE slug != ?");
    $stmt->execute([$businessSlug]);
    echo "\nSelesai. " . $stmt->rowCount() . " bisnis lain dinonaktifkan. Hanya '{$businessSlug}' yang sekarang aktif.\n";
    echo "HAPUS file ini (tools/business-lock.php) dari server sekarang.\n";
} else {
    echo "\nIni baru PREVIEW, belum ada perubahan. Tambahkan &apply=1 di URL untuk eksekusi.\n";
}
echo "</pre>";
