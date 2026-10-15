<?php
/**
 * Bahasa tampilan permanen per pengguna.
 * Disimpan di DATABASE UTAMA (bukan database bisnis), karena saat login bisnis belum dipilih — sebelumnya pilihan
 * bahasa hanya tersimpan di database bisnis sehingga hilang setelah logout/login. Dipakai set-language, login,
 * login Google, login "ingat saya", dan pemulihan sesi.
 */

/** Koneksi ke database utama (null bila gagal). */
function userLangPdo(): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) return $pdo;
    try {
        $name = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME;
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $name . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_language_pref (
            user_id INT NOT NULL PRIMARY KEY,
            language VARCHAR(10) NOT NULL DEFAULT 'id',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {
        $pdo = null;
    }
    return $pdo;
}

/** Simpan bahasa terakhir yang dipilih pengguna. */
function userLangSave(int $userId, string $code): void
{
    if ($userId <= 0 || !preg_match('/^[a-z]{2,10}$/', $code)) return;
    $pdo = userLangPdo();
    if (!$pdo) return;
    try {
        $pdo->prepare("INSERT INTO user_language_pref (user_id, language) VALUES (?, ?) ON DUPLICATE KEY UPDATE language = VALUES(language)")->execute([$userId, $code]);
    } catch (\Throwable $e) {
    }
}

/** Bahasa terakhir pengguna, atau null bila belum pernah memilih. */
function userLangLoad(int $userId): ?string
{
    if ($userId <= 0) return null;
    $pdo = userLangPdo();
    if (!$pdo) return null;
    try {
        $st = $pdo->prepare("SELECT language FROM user_language_pref WHERE user_id = ?");
        $st->execute([$userId]);
        $l = $st->fetchColumn();
        return $l ? (string)$l : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/** Terapkan bahasa tersimpan ke sesi (dipanggil sesudah login berhasil). */
function userLangRestore(int $userId): void
{
    $l = userLangLoad($userId);
    if ($l !== null) $_SESSION['user_language'] = $l;
}
