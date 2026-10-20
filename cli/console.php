<?php

/**
 * Perawatan ADF Console dari terminal (jalankan sebagai user adf):
 *   php cli/console.php migrate                       buat/ubah tabel (aman diulang; jalankan setiap selesai git pull)
 *   php cli/console.php import-users <users.json>     impor akun dari data/users.json adfsystem.store lama (password tetap sama)
 *   php cli/console.php create-admin                  buat akun admin baru (interaktif)
 *   php cli/console.php set-password <username>       ganti password akun (darurat: lupa password & email tidak bisa dipakai)
 *   php cli/console.php users                         daftar akun
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/apps/console/bootstrap.php';

$cmd = $argv[1] ?? '';
try {
    match ($cmd) {
        'migrate' => migrate(),
        'import-users' => importUsers($argv[2] ?? ''),
        'create-admin' => createAdmin(),
        'users' => listUsers(),
        'set-password' => setPassword($argv[2] ?? ''),
        default => print("Perintah: migrate | import-users <users.json> | create-admin | set-password <username> | users\n"),
    };
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function migrate(): void
{
    $done = Migrator::run(CONSOLE_DB, dirname(__DIR__) . '/apps/console/migrations');
    echo $done ? 'Dijalankan: ' . implode(', ', $done) . PHP_EOL : "Tabel sudah terbaru.\n";
}

/** Akun dari store lama: id, username, email, password_hash (bcrypt), role admin|staff. Akun yang sudah ada dilewati. */
function importUsers(string $path): void
{
    if (!is_readable($path)) {
        throw new RuntimeException("File tidak terbaca: {$path}");
    }
    $list = json_decode((string)file_get_contents($path), true);
    if (!is_array($list)) {
        throw new RuntimeException('Isi file bukan JSON daftar akun');
    }
    $db = Auth::db();
    $ins = $db->prepare('INSERT IGNORE INTO console_users (username, email, password_hash, role, legacy_id, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $added = 0;
    foreach ($list as $u) {
        if (empty($u['username']) || empty($u['email']) || empty($u['password_hash'])) {
            continue;
        }
        $created = !empty($u['created_at']) && strtotime((string)$u['created_at']) ? date('Y-m-d H:i:s', strtotime((string)$u['created_at'])) : date('Y-m-d H:i:s');
        $ins->execute([$u['username'], strtolower((string)$u['email']), $u['password_hash'], ($u['role'] ?? '') === 'admin' ? 'admin' : 'staff', (string)($u['id'] ?? ''), $created]);
        $added += $ins->rowCount();
        echo ($ins->rowCount() ? '  + ' : '  = (sudah ada) ') . $u['username'] . ' <' . $u['email'] . '> ' . ($u['role'] ?? 'staff') . PHP_EOL;
    }
    echo "{$added} akun diimpor.\n";
}

function createAdmin(): void
{
    $ask = function (string $label, bool $hidden = false): string {
        echo $label;
        if ($hidden) @shell_exec('stty -echo');
        $v = trim((string)fgets(STDIN));
        if ($hidden) {
            @shell_exec('stty echo');
            echo PHP_EOL;
        }
        return $v;
    };
    $username = $ask('Username: ');
    $email = $ask('Email: ');
    $pass = $ask('Password (min. 10, tidak tampil): ', true);
    if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 10) {
        throw new RuntimeException('Username/email tidak valid atau password kurang dari 10 karakter');
    }
    if ($ask('Ulangi password: ', true) !== $pass) {
        throw new RuntimeException('Password tidak sama');
    }
    Auth::db()->prepare('INSERT INTO console_users (username, email, password_hash, role) VALUES (?, ?, ?, ?)')->execute([$username, strtolower($email), password_hash($pass, PASSWORD_DEFAULT), 'admin']);
    echo "Admin {$username} dibuat.\n";
}

function listUsers(): void
{
    foreach (Auth::db()->query('SELECT id, username, email, role FROM console_users ORDER BY id') as $u) {
        printf("  #%-3d %-20s %-32s %s\n", $u['id'], $u['username'], $u['email'], $u['role']);
    }
}

/** Darurat: ganti password dari terminal server (hanya pemegang akses SSH yang bisa). Sesi lain tidak terpengaruh, link reset lama dibatalkan. */
function setPassword(string $username): void
{
    $user = Auth::findUser($username);
    if (!$user) {
        throw new RuntimeException("Akun tidak ditemukan: {$username} (lihat: php cli/console.php users)");
    }
    $read = function (string $label): string {
        echo $label;
        @shell_exec("stty -echo");
        $v = trim((string)fgets(STDIN));
        @shell_exec("stty echo");
        echo PHP_EOL;
        return $v;
    };
    $pass = $read("Password baru untuk {$user['username']} (min. 10, tidak tampil): ");
    if (strlen($pass) < 10) {
        throw new RuntimeException("Password minimal 10 karakter");
    }
    if ($read("Ulangi password: ") !== $pass) {
        throw new RuntimeException("Password tidak sama");
    }
    Auth::setPassword((int)$user["id"], $pass);
    Auth::clearFails((string)$user["username"]);
    Telegram::security("🔑", "Password ADF Console diganti dari terminal server", ["Akun" => (string)$user["username"]]);
    echo "Password {$user['username']} diganti. Kunci percobaan login juga dibuka.\n";
}