<?php

/**
 * Diagnostic: shows which physical database Database::getInstance() connects to for the
 * currently active business, and dumps the raw email_imap_* rows from its `settings` table.
 * Login required. Delete after use.
 */

define('APP_ACCESS', true);
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

try {
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/business_helper.php';

    $auth = new Auth();
    $auth->requireLogin();

    echo "ACTIVE_BUSINESS_ID: " . (defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : '(tidak terdefinisi)') . "\n";
    $bizCfg = getActiveBusinessConfig();
    echo "getActiveBusinessConfig()['database']: " . ($bizCfg['database'] ?? '(kosong)') . "\n";
    echo "getActiveBusinessConfig()['name']: " . ($bizCfg['name'] ?? '(kosong)') . "\n\n";

    $db = Database::getInstance();
    echo "Database::getCurrentDatabase() (nama DB fisik yang benar2 dikoneksi): " . Database::getCurrentDatabase() . "\n\n";

    $rows = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'email_imap_%' ORDER BY setting_key");
    echo "=== ISI settings TABLE (email_imap_*) DI DATABASE INI ===\n";
    if (!$rows) {
        echo "(kosong / tidak ada baris email_imap_* di database ini)\n";
    } else {
        foreach ($rows as $r) {
            $val = $r['setting_value'];
            if ($r['setting_key'] === 'email_imap_pass') {
                $val = $val !== '' ? '(terisi, ' . strlen($val) . ' karakter, terenkripsi)' : '(kosong)';
            }
            echo "{$r['setting_key']} = {$val}\n";
        }
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}
