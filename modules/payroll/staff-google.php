<?php

/**
 * Staff Portal - Masuk dengan Google & Hubungkan Gmail.
 *   ?b=<slug>&do=link   staf yang SUDAH login (username + password) menautkan Gmail-nya sendiri — admin tidak perlu input apa pun
 *   ?b=<slug>&do=login  masuk dengan Gmail yang sudah ditautkan
 *   (tanpa do)          kembali dari Google (code + state)
 * Kredensial Google sama dengan login sistem (includes/google-login.php, adf-google-oauth.php di folder home).
 * Redirect URI yang didaftarkan di Google Cloud: https://<domain>/modules/payroll/staff-google.php
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/google-login.php';

/** Kembali ke Staff Portal bisnis ini dengan pesan sekali tampil. */
function staffGoogleBack(string $slug, string $msg = '', string $type = 'error'): void
{
    if ($msg !== '') {
        $_SESSION['staff_google_msg'] = ['text' => $msg, 'type' => $type];
    }
    header('Location: staff-portal.php?b=' . urlencode($slug));
    exit;
}

// Alamat kembalian yang dikirim ke Google harus SAMA PERSIS dengan yang didaftarkan di Google Cloud:
// di server selalu https + domain tanpa "www" (mis. https://nasita.biz.id/modules/payroll/staff-google.php).
$cbHost = strtolower((string)preg_replace(['/^www\./', '/:\d+$/'], '', (string)($_SERVER['HTTP_HOST'] ?? '')));
$isLocal = $cbHost === '' || str_contains($cbHost, 'localhost') || str_contains($cbHost, '127.0.0.1');
$redirectUri = $isLocal
    ? rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/') . '/modules/payroll/staff-google.php'
    : 'https://' . $cbHost . '/modules/payroll/staff-google.php';

// ── Mulai: simpan konteks (bisnis, tujuan, akun yang sedang login) lalu ke Google ──
if (isset($_GET['do'])) {
    $slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim((string)($_GET['b'] ?? ''))));
    if ($slug === '' || !is_file(__DIR__ . '/../../config/businesses/' . $slug . '.php')) {
        http_response_code(400);
        exit('Link tidak valid.');
    }
    $do = $_GET['do'] === 'link' ? 'link' : 'login';
    if ($do === 'link' && empty($_SESSION['staff_logged_in'])) {
        staffGoogleBack($slug, 'Login dulu dengan username & password, lalu hubungkan Gmail.');
    }
    $_SESSION['staff_google_ctx'] = [
        'b' => $slug, 'do' => $do,
        'staff_id' => (int)($_SESSION['staff_id'] ?? 0), 'staff_email' => (string)($_SESSION['staff_email'] ?? ''),
    ];
    $url = adfGoogleAuthUrl($redirectUri, 'staff_google');
    if (!$url) {
        staffGoogleBack($slug, 'Login Google belum diatur di sistem.');
    }
    header('Location: ' . $url);
    exit;
}

// ── Kembali dari Google ──
$ctx = $_SESSION['staff_google_ctx'] ?? null;
unset($_SESSION['staff_google_ctx']);
if (!$ctx || empty($ctx['b']) || !is_file(__DIR__ . '/../../config/businesses/' . $ctx['b'] . '.php')) {
    http_response_code(400);
    exit('Sesi login Google kedaluwarsa. Buka Staff Portal lalu coba lagi.');
}
$slug = (string)$ctx['b'];
[$gmail, $err] = adfGoogleHandleCallback($_GET, 'staff_google');
if ($gmail === null) {
    staffGoogleBack($slug, $err);
}

$bizConfig = require __DIR__ . '/../../config/businesses/' . $slug . '.php';
$db = Database::switchDatabase($bizConfig['database']);
$pdo = $db->getConnection();
try {
    $pdo->query("SELECT google_email FROM staff_accounts LIMIT 0");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE staff_accounts ADD COLUMN google_email VARCHAR(255) DEFAULT NULL AFTER email, ADD INDEX idx_google (google_email)");
}

if ($ctx['do'] === 'link') {
    // Hanya akun yang tadi login (dicek id + username) — Gmail tidak boleh dipakai dua akun
    $me = $db->fetchOne("SELECT id FROM staff_accounts WHERE id = ? AND LOWER(email) = LOWER(?)", [(int)$ctx['staff_id'], (string)$ctx['staff_email']]);
    if (!$me || empty($_SESSION['staff_logged_in']) || (int)($_SESSION['staff_id'] ?? 0) !== (int)$me['id']) {
        staffGoogleBack($slug, 'Sesi login berakhir. Login lagi, lalu hubungkan Gmail.');
    }
    $other = $db->fetchOne("SELECT pe.full_name FROM staff_accounts sa JOIN payroll_employees pe ON pe.id = sa.employee_id WHERE LOWER(sa.google_email) = ? AND sa.id <> ?", [$gmail, (int)$me['id']]);
    if ($other) {
        staffGoogleBack($slug, "Gmail {$gmail} sudah dipakai akun lain ({$other['full_name']}).");
    }
    $pdo->prepare("UPDATE staff_accounts SET google_email = ? WHERE id = ?")->execute([$gmail, (int)$me['id']]);
    staffGoogleBack($slug, "Gmail {$gmail} tersambung. Berikutnya cukup tekan Masuk dengan Google.", 'success');
}

// Masuk: Gmail harus sudah ditautkan ke salah satu akun bisnis ini
$account = $db->fetchOne("SELECT sa.*, pe.full_name, pe.employee_code, pe.position
    FROM staff_accounts sa JOIN payroll_employees pe ON pe.id = sa.employee_id
    WHERE LOWER(sa.google_email) = ? AND pe.is_active = 1 LIMIT 1", [$gmail]);
if (!$account) {
    staffGoogleBack($slug, "Gmail {$gmail} belum tersambung ke akun mana pun. Login dengan username & password dulu, lalu tekan Hubungkan Gmail.");
}
session_regenerate_id(true);
$pdo->prepare("UPDATE staff_accounts SET last_login = NOW() WHERE id = ?")->execute([$account['id']]);
// Sama dengan login password (staff-api.php action=login)
$_SESSION['staff_id'] = $account['id'];
$_SESSION['employee_id'] = $account['employee_id'];
$_SESSION['staff_name'] = $account['full_name'];
$_SESSION['staff_code'] = $account['employee_code'];
$_SESSION['staff_position'] = $account['position'];
$_SESSION['staff_email'] = $account['email'];
$_SESSION['staff_logged_in'] = true;
staffGoogleBack($slug, '', 'success');
