<?php

/**
 * Perawatan ADF Console dari terminal (jalankan sebagai user adf):
 *   php cli/console.php migrate                       buat/ubah tabel (aman diulang; jalankan setiap selesai git pull)
 *   php cli/console.php import-users <users.json>     impor akun dari data/users.json adfsystem.store lama (password tetap sama)
 *   php cli/console.php create-admin                  buat akun admin baru (interaktif)
 *   php cli/console.php set-password <username>       ganti password akun (darurat: lupa password & email tidak bisa dipakai)
 *   php cli/console.php users                         daftar akun
 *   php cli/console.php demo seed|clear               data contoh Grup Narayana (DEMO)
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
        'demo' => demo($argv[2] ?? ''),
        default => print("Perintah: migrate | import-users <users.json> | create-admin | set-password <username> | users | demo seed|clear\n"),
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
/**
 * Data contoh Grup Narayana untuk mencoba menu Client & Bisnis sebelum data asli diimpor.
 *   php cli/console.php demo seed    isi (ditandai DEMO, kode bisnis berawalan demo-)
 *   php cli/console.php demo clear   hapus semua data DEMO
 */
function demo(string $what): void
{
    $db = Auth::db();
    if ($what === 'clear') {
        $ids = $db->query('SELECT b.id FROM businesses b JOIN clients c ON c.id = b.client_id WHERE c.is_demo = 1')->fetchAll(PDO::FETCH_COLUMN);
        $db->beginTransaction();
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $db->exec("DELETE FROM invoices WHERE business_id IN ({$in})");
            $db->exec("DELETE FROM subscription_charges WHERE business_id IN ({$in})");
            $db->exec("DELETE FROM subscriptions WHERE business_id IN ({$in})");
            $db->exec("DELETE FROM businesses WHERE id IN ({$in})");
        }
        $n = $db->exec('DELETE FROM clients WHERE is_demo = 1');
        $db->commit();
        echo "Data DEMO dihapus ({$n} client, " . count($ids) . " bisnis).\n";
        return;
    }
    if ($what !== 'seed') {
        throw new RuntimeException('Pakai: demo seed | demo clear');
    }
    if ($db->query('SELECT 1 FROM clients WHERE is_demo = 1')->fetchColumn()) {
        throw new RuntimeException('Data DEMO sudah ada. Hapus dulu dengan: demo clear');
    }
    $db->beginTransaction();
    $db->prepare('INSERT INTO clients (name, contact_name, email, phone, address, is_demo) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute(['Grup Narayana', 'Arif', 'owner@narayana.test', '0812-0000-0000', 'Karimunjawa, Jepara']);
    $clientId = (int)$db->lastInsertId();
    // [nama, jenis, kode, database, domain, biaya dasar, tarif per tamu, tamu Sep, tamu Okt, Okt lunas?]
    $list = [
        ['Narayana Hotel', 'hotel', 'demo-narayana-hotel', 'adf_narayana_hotel', null, 250000, 2500, 120, 85, false],
        ['Bens Cafe', 'restaurant', 'demo-bens-cafe', 'adf_benscafe', null, 150000, 0, 0, 0, true],
        ['Eatmeet', 'restaurant', 'demo-eatmeet', 'adf_eatmeet', null, 150000, 0, 0, 0, false],
        ['Gudang Nasita', 'warehouse', 'demo-gudang-nasita', 'adf_gudang_nasita', null, 100000, 0, 0, 0, true],
        ['Narayana Website', 'website', 'demo-narayana-website', null, 'narayanakarimunjawa.com', 100000, 0, 0, 0, false],
    ];
    $insB = $db->prepare('INSERT INTO businesses (client_id, slug, name, type, db_name, domain) VALUES (?, ?, ?, ?, ?, ?)');
    $insS = $db->prepare('INSERT INTO subscriptions (business_id, client_key, client_token, display_name, base_fee, per_guest_fee, start_date) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insC = $db->prepare('INSERT INTO subscription_charges (business_id, kind, ref, period, base_fee, guest_count, carry_guests, per_guest_fee, status, due_date, paid_at) VALUES (?, \'recurring\', ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($list as [$name, $type, $slug, $dbName, $domain, $base, $perGuest, $gSep, $gOct, $octPaid]) {
        $insB->execute([$clientId, $slug, $name, $type, $dbName, $domain]);
        $bid = (int)$db->lastInsertId();
        $insS->execute([$bid, $slug, bin2hex(random_bytes(24)), $name, $base, $perGuest, '2026-05-20']);
        $insC->execute([$bid, '2026-09', '2026-09', $base, $gSep, 0, $perGuest, 'paid', '2026-09-20', '2026-09-18 11:00:00']);
        $insC->execute([$bid, '2026-10', '2026-10', $base, $gOct, $gOct ? 5 : 0, $perGuest, $octPaid ? 'paid' : 'unpaid', '2026-10-20', $octPaid ? '2026-10-19 10:00:00' : null]);
    }
    $db->commit();
    echo "Data DEMO Grup Narayana dibuat: 5 bisnis + langganan + tagihan Sep (lunas) & Okt.\n";
}
