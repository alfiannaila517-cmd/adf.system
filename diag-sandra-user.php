<?php
// TEMP diagnostic: locate every "sandra"-like account (master users, per-business
// local users tables, staff_accounts) to explain a login/password mismatch.
// Usage: diag-sandra-user.php?token=diag-sandra-2026-08-19&q=sandra
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain');

if (($_GET['token'] ?? '') !== 'diag-sandra-2026-08-19') {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

$q = trim((string)($_GET['q'] ?? 'sandra'));
$like = '%' . $q . '%';

function maskHash($hash) {
    if (!$hash) return '(empty)';
    $len = strlen($hash);
    $isBcrypt = (strpos($hash, '$2y$') === 0);
    return substr($hash, 0, 8) . '...(' . $len . ' chars, ' . ($isBcrypt ? 'bcrypt' : 'md5/plain?') . ')';
}

try {
    echo "=== MASTER DB (" . DB_NAME . ") users table ===\n";
    $masterDb = Database::getInstance();
    $masterRows = $masterDb->fetchAll("SELECT id, username, email, full_name, role_id, is_active, created_at, updated_at, password FROM users WHERE username LIKE ? OR email LIKE ? OR full_name LIKE ?", [$like, $like, $like]);
    if (!$masterRows) {
        echo "(no rows found)\n";
    }
    foreach ($masterRows as $r) {
        echo "id={$r['id']} username={$r['username']} email={$r['email']} full_name={$r['full_name']} role_id={$r['role_id']} is_active={$r['is_active']} created_at={$r['created_at']} updated_at={$r['updated_at']} password=" . maskHash($r['password']) . "\n";
    }

    echo "\n=== PER-BUSINESS local users tables ===\n";
    $configDir = __DIR__ . '/config/businesses/';
    $originDb = Database::getCurrentDatabase();
    foreach (glob($configDir . '*.php') as $cfgFile) {
        $cfg = require $cfgFile;
        $slug = basename($cfgFile, '.php');
        $dbName = (string)($cfg['database'] ?? '');
        if (!$dbName) continue;
        try {
            $db = Database::switchDatabase($dbName);
            $hasTable = $db->fetchOne("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
            if (!$hasTable) {
                echo "[{$slug} / {$dbName}] no local users table\n";
                continue;
            }
            $rows = $db->fetchAll("SELECT id, username, email, full_name, role, is_active, created_at, updated_at, password FROM users WHERE username LIKE ? OR email LIKE ? OR full_name LIKE ?", [$like, $like, $like]);
            if (!$rows) {
                echo "[{$slug} / {$dbName}] (no matching rows)\n";
            }
            foreach ($rows as $r) {
                echo "[{$slug} / {$dbName}] id={$r['id']} username={$r['username']} email=" . ($r['email'] ?? '') . " full_name={$r['full_name']} role=" . ($r['role'] ?? '') . " is_active={$r['is_active']} created_at={$r['created_at']} updated_at={$r['updated_at']} password=" . maskHash($r['password']) . "\n";
            }
        } catch (Throwable $e) {
            echo "[{$slug} / {$dbName}] ERROR: " . $e->getMessage() . "\n";
        }
    }

    echo "\n=== PER-BUSINESS staff_accounts tables ===\n";
    foreach (glob($configDir . '*.php') as $cfgFile) {
        $cfg = require $cfgFile;
        $slug = basename($cfgFile, '.php');
        $dbName = (string)($cfg['database'] ?? '');
        if (!$dbName) continue;
        try {
            $db = Database::switchDatabase($dbName);
            $hasTable = $db->fetchOne("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_accounts'");
            if (!$hasTable) {
                echo "[{$slug} / {$dbName}] no staff_accounts table\n";
                continue;
            }
            $rows = $db->fetchAll("SELECT sa.id, sa.email, sa.employee_id, sa.is_active, sa.created_at, sa.password_hash, pe.full_name
                FROM staff_accounts sa LEFT JOIN payroll_employees pe ON pe.id = sa.employee_id
                WHERE sa.email LIKE ? OR pe.full_name LIKE ?", [$like, $like]);
            if (!$rows) {
                echo "[{$slug} / {$dbName}] (no matching rows)\n";
            }
            foreach ($rows as $r) {
                echo "[{$slug} / {$dbName}] id={$r['id']} email={$r['email']} full_name=" . ($r['full_name'] ?? '') . " is_active={$r['is_active']} created_at={$r['created_at']} password=" . maskHash($r['password_hash']) . "\n";
            }
        } catch (Throwable $e) {
            echo "[{$slug} / {$dbName}] ERROR: " . $e->getMessage() . "\n";
        }
    }

    if ($originDb) {
        Database::switchDatabase($originDb);
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
