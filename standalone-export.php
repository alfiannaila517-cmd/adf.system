<?php

/**
 * standalone-export.php
 * ------------------------------------------------------------------
 * Generic "one-click" export tool to move ANY business from this
 * multi-tenant adf.system install to its own standalone hosting account,
 * while still running the same adf.system codebase (git-synced).
 *
 * This is a generalized version of the old sunsea-standalone-export.php
 * (which only worked for the Sunsea business) - pass any business slug
 * via ?business=<slug> instead.
 *
 * What it does:
 *   1. Dumps the MASTER database in full (businesses, users, roles,
 *      menu config, cash_accounts, etc. - needed because those tables
 *      have foreign keys between each other, so a partial/filtered dump
 *      risks import errors on the new server).
 *   2. Dumps the target business's own database in full (all real data).
 *   3. Zips both .sql files together with a README and streams the zip
 *      straight to your browser as a download - nothing is uploaded
 *      anywhere, nothing is left behind on this server.
 *
 * Usage (must be logged in as owner/admin/developer):
 *   https://adfsystem.online/standalone-export.php?business=<slug>
 *   (slug = the filename without .php under config/businesses/, e.g. "sunsea")
 *
 * After downloading, see the README.txt inside the zip for import steps
 * on the new hosting, and use tools/business-lock.php on the NEW server
 * to hide every other business.
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$__role = $_SESSION['role'] ?? '';
if (!in_array($__role, ['owner', 'admin', 'developer'], true)) {
    http_response_code(403);
    exit("Forbidden - hanya owner/admin/developer yang boleh export database.\n");
}

$businessSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($_GET['business'] ?? '')));
if ($businessSlug === '') {
    http_response_code(400);
    exit("Sertakan ?business=<slug> (nama file config/businesses/<slug>.php tanpa .php).\n");
}

$businessConfFile = __DIR__ . '/config/businesses/' . $businessSlug . '.php';
if (!file_exists($businessConfFile)) {
    http_response_code(404);
    exit("config/businesses/{$businessSlug}.php tidak ditemukan.\n");
}
$businessConf = require $businessConfFile;

set_time_limit(0);
ignore_user_abort(true);

// ------------------------------------------------------------------
// Resolve database names (same mapping logic as config/database.php
// and cron-daily-backup.php, kept in sync intentionally)
// ------------------------------------------------------------------
function resolveHostingDbName(string $localName): string
{
    if (strpos($localName, 'adf_') !== 0) {
        return $localName;
    }
    $prefix = 'adfb2574_';
    if (defined('DB_USER')) {
        $parts = explode('_', DB_USER);
        if (count($parts) >= 2) {
            $prefix = $parts[0] . '_';
        }
    }
    return $prefix . substr($localName, 4);
}

$isLocalDev = (defined('DB_USER') && DB_USER === 'root');

$masterDbName   = DB_NAME; // already correct for whichever host is running this
$businessDbName = $isLocalDev ? $businessConf['database'] : resolveHostingDbName($businessConf['database']);

// ------------------------------------------------------------------
// Pure-PHP full SQL dump (no mysqldump binary needed - same approach
// already proven working in cron-daily-backup.php on this hosting)
// ------------------------------------------------------------------
function dumpDatabaseToFile(PDO $pdo, string $dbName, string $filePath): void
{
    $handle = fopen($filePath, 'w');
    fwrite($handle, "-- Standalone Export\n-- Database: {$dbName}\n-- Date: " . date('Y-m-d H:i:s') . "\n\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = [];
    $result = $pdo->query('SHOW TABLES');
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    foreach ($tables as $table) {
        $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        fwrite($handle, "-- --------------------------------------------------------\n");
        fwrite($handle, "-- Table structure for `{$table}`\n");
        fwrite($handle, "-- --------------------------------------------------------\n\n");
        fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($handle, $createRow[1] . ";\n\n");

        $result = $pdo->query("SELECT * FROM `{$table}`");
        $hasRows = false;
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            if (!$hasRows) {
                fwrite($handle, "-- Dumping data for table `{$table}`\n\n");
                $hasRows = true;
            }
            $columns = array_keys($row);
            $escapedValues = array_map(function ($value) use ($pdo) {
                return $value === null ? 'NULL' : $pdo->quote($value);
            }, array_values($row));
            fwrite($handle, "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $escapedValues) . ");\n");
        }
        if ($hasRows) {
            fwrite($handle, "\n");
        }
    }

    fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);
}

// ------------------------------------------------------------------
// Do the export
// ------------------------------------------------------------------
$stamp  = date('Ymd-His');
$tmpDir = sys_get_temp_dir() . '/standalone-export-' . $businessSlug . '-' . $stamp;
@mkdir($tmpDir, 0755, true);

$masterSqlPath   = $tmpDir . "/master-{$masterDbName}.sql";
$businessSqlPath = $tmpDir . "/business-{$businessDbName}.sql";

try {
    $masterPdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    dumpDatabaseToFile($masterPdo, $masterDbName, $masterSqlPath);

    $businessPdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . $businessDbName . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    dumpDatabaseToFile($businessPdo, $businessDbName, $businessSqlPath);
} catch (Exception $e) {
    http_response_code(500);
    exit('Export gagal: ' . $e->getMessage() . "\n");
}

$businessName = $businessConf['name'] ?? $businessSlug;
$readme = <<<TXT
STANDALONE HOSTING - IMPORT INSTRUCTIONS ({$businessName})
================================================

Isi zip ini:
  - master-{$masterDbName}.sql     -> import ke database MASTER baru
  - business-{$businessDbName}.sql -> import ke database BISNIS ({$businessSlug}) baru

Langkah di hosting BARU:
  1. cPanel -> MySQL Databases: buat 2 database (misal yourprefix_adf dan
     yourprefix_{$businessSlug}) + 1 user MySQL dengan ALL PRIVILEGES ke keduanya.
  2. cPanel -> phpMyAdmin: pilih database master baru -> tab Import ->
     upload master-{$masterDbName}.sql
  3. phpMyAdmin: pilih database bisnis baru -> tab Import -> upload
     business-{$businessDbName}.sql
  4. Buat repo GitHub baru utk customer ini (boleh clone/fork dari repo
     adf.system yang sama), lalu deploy ke hosting baru (cPanel Git Version
     Control, atau upload manual via File Manager).
  5. Di server BARU, copy config/local-db-config.example.php menjadi
     config/local-db-config.php (lewat File Manager, JANGAN lewat git),
     lalu isi DB_HOST/DB_NAME/DB_USER/DB_PASS sesuai database MASTER yang
     baru dibuat di langkah 1.
  6. Edit config/businesses/{$businessSlug}.php: ubah 'database' key ke nama
     database bisnis yang baru dibuat di langkah 1 (yourprefix_{$businessSlug}).
  7. Jalankan tools/business-lock.php?business={$businessSlug} di server BARU
     (login owner/admin/developer dulu) utk preview, lalu tambahkan &apply=1
     utk benar-benar menyembunyikan semua bisnis lain (supaya cuma
     "{$businessName}" yang muncul di server baru ini). HAPUS file
     tools/business-lock.php dari server setelah selesai dipakai.
  8. Arahkan DNS domain customer (A record) ke IP server hosting baru, lalu
     aktifkan SSL (AutoSSL/Let's Encrypt) di cPanel.

Catatan: dump master ini berisi SEMUA baris dari tabel master (businesses,
users, dll), bukan cuma bisnis "{$businessName}", karena tabel-tabel itu
saling berelasi (foreign key). Ini aman - database bisnis LAIN tidak ikut
dipindahkan (hanya row-row referensinya di master), jadi bisnis lain tidak
akan bisa dipakai dari server baru ini setelah langkah 7 dijalankan.
TXT;

$zipPath = $tmpDir . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit("Gagal membuat file zip.\n");
}
$zip->addFile($masterSqlPath, basename($masterSqlPath));
$zip->addFile($businessSqlPath, basename($businessSqlPath));
$zip->addFromString('README.txt', $readme);
$zip->close();

// Stream to browser
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="standalone-export-' . $businessSlug . '-' . $stamp . '.zip"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-store');
readfile($zipPath);

// Cleanup temp files
@unlink($masterSqlPath);
@unlink($businessSqlPath);
@rmdir($tmpDir);
@unlink($zipPath);
exit;
