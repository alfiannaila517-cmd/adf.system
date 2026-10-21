<?php

/**
 * Perawatan ADF Console dari terminal (jalankan sebagai user adf):
 *   php cli/console.php migrate                       buat/ubah tabel (aman diulang; jalankan setiap selesai git pull)
 *   php cli/console.php import-users <users.json>     impor akun dari data/users.json adfsystem.store lama (password tetap sama)
 *   php cli/console.php create-admin                  buat akun admin baru (interaktif)
 *   php cli/console.php set-password <username>       ganti password akun (darurat: lupa password & email tidak bisa dipakai)
 *   php cli/console.php users                         daftar akun
 *   php cli/console.php demo seed|clear               data contoh Grup Narayana (DEMO)
 *   php cli/console.php vault-key                     buat kunci brankas (enkripsi kunci API integrasi) — sekali saja
 *   php cli/console.php legacy-preview <kode-bisnis>  pratinjau baris tabel sistem lama (izin + integrasi)
 *   php cli/console.php legacy-import [--dry-run] [--from-hosting] [--client="Grup Narayana"] [slug …]
 *                                                     --from-hosting = data baru dari hosting (hari pindahan)
 *                                                     impor bisnis + orang + izin dari database master sistem lama
 *   php cli/console.php legacy-diff <kode-bisnis>     beda izin Console vs tabel sistem lama (kosong = identik)
 *   php cli/console.php mail-test <email>             kirim email uji lewat pengaturan secrets/smtp.env
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
        'legacy-preview' => legacyPreview($argv[2] ?? ''),
        'vault-key' => vaultKey(),
        'legacy-import' => legacyImport(array_slice($argv, 2)),
        'legacy-diff' => legacyDiff($argv[2] ?? ''),
        'mail-test' => mailTest($argv[2] ?? ''),
        default => print("Perintah: migrate | import-users <users.json> | create-admin | set-password <username> | users | demo seed|clear | vault-key | legacy-preview <kode> | legacy-import [--dry-run] | legacy-diff <kode> | mail-test <email>\n"),
    };
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/** Uji pengiriman email (mis. setelah mengganti SMTP ke Brevo) tanpa harus logout dari Console. */
function mailTest(string $to): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('pakai: mail-test alamat@email.com');
    }
    $host = (string)Secrets::get('SMTP_HOST', '(belum diatur)');
    $from = (string)Secrets::get('SMTP_FROM', (string)Secrets::get('SMTP_USER', ''));
    echo "SMTP {$host} · pengirim {$from} → {$to}\n";
    $ok = Mailer::send($to, 'Tes email ADF Console', '<p>Halo, ini email uji dari <b>ADF Console</b> (' . date('d M Y H:i') . ' WIB).</p><p>Kalau email ini masuk, pengiriman OTP, reset password, dan notifikasi sudah berjalan.</p>', 'ADF Console');
    echo $ok ? "OK: email terkirim. Cek kotak masuk (dan folder Spam).\n" : 'GAGAL: ' . Mailer::lastError() . "\n";
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
            $db->exec("DELETE FROM memberships WHERE business_id IN ({$in})");
            $db->exec("DELETE FROM roles WHERE business_id IN ({$in})");
            $db->exec("DELETE FROM businesses WHERE id IN ({$in})");
        }
        $n = $db->exec('DELETE FROM clients WHERE is_demo = 1');
        $db->exec("DELETE FROM people WHERE email LIKE '%@demo.adf'");
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
    $bizBySlug = [];
    foreach ($list as [$name, $type, $slug, $dbName, $domain, $base, $perGuest, $gSep, $gOct, $octPaid]) {
        $insB->execute([$clientId, $slug, $name, $type, $dbName, $domain]);
        $bid = (int)$db->lastInsertId();
        $insS->execute([$bid, $slug, bin2hex(random_bytes(24)), $name, $base, $perGuest, '2026-05-20']);
        $insC->execute([$bid, '2026-09', '2026-09', $base, $gSep, 0, $perGuest, 'paid', '2026-09-20', '2026-09-18 11:00:00']);
        $insC->execute([$bid, '2026-10', '2026-10', $base, $gOct, $gOct ? 5 : 0, $perGuest, $octPaid ? 'paid' : 'unpaid', '2026-10-20', $octPaid ? '2026-10-19 10:00:00' : null]);
        Access::ensureRoles(Biz::business($bid));
        $bizBySlug[$slug] = $bid;
    }

    // Orang contoh (email @demo.adf, dihapus oleh "demo clear")
    $roleId = function (string $slug, string $code) use ($db, &$bizBySlug): int {
        $st = $db->prepare('SELECT id FROM roles WHERE business_id = ? AND code = ?');
        $st->execute([$bizBySlug[$slug], $code]);
        return (int)$st->fetchColumn();
    };
    Access::setGroupOwner($clientId, Access::person('Arif', 'arif@demo.adf'), true);
    $lucca = Access::person('Lucca', 'lucca@demo.adf');
    Access::setMembership($bizBySlug['demo-narayana-hotel'], $lucca, $roleId('demo-narayana-hotel', 'owner'), true, false);
    Access::setMembership($bizBySlug['demo-bens-cafe'], $lucca, $roleId('demo-bens-cafe', 'owner'), true, false);
    $sandra = Access::person('Sandra', 'sandra@demo.adf');
    Access::setMembership($bizBySlug['demo-narayana-hotel'], $sandra, $roleId('demo-narayana-hotel', 'front_desk'), false, false);
    Access::setMembership($bizBySlug['demo-bens-cafe'], $sandra, $roleId('demo-bens-cafe', 'cashier'), false, false);
    $putri = Access::person('Putri', 'putri@demo.adf');
    Access::setMembership($bizBySlug['demo-narayana-hotel'], $putri, $roleId('demo-narayana-hotel', 'staff'), false, true);

    $db->commit();
    echo "Data DEMO Grup Narayana dibuat: 5 bisnis + langganan + tagihan Sep (lunas) & Okt + orang (Arif owner grup, Lucca, Sandra, Putri).\n";
}

/**
 * Pratinjau terjemahan akses Console → tabel sistem lama (tanpa menulis apa pun):
 *   php cli/console.php legacy-preview <kode-bisnis>
 * Menampilkan baris user_menu_permissions yang akan ditulis saat sistem bisnis dipindah: SEMUA modul katalog per orang
 * (yang tidak diizinkan ditulis 0000, supaya aturan lama "belum diatur = boleh semua" tidak pernah berlaku),
 * serta role global lama (owner / manager / staff).
 */
function legacyPreview(string $slug): void
{
    $db = Auth::db();
    $st = $db->prepare('SELECT id FROM businesses WHERE slug = ?');
    $st->execute([$slug]);
    $biz = Biz::business((int)$st->fetchColumn());
    if (!$biz) {
        throw new RuntimeException("Bisnis tidak ditemukan: {$slug}");
    }
    $people = Legacy::desired($biz);
    echo "Bisnis {$biz['name']} — " . count($people) . " orang, " . count(Catalog::MODULES) . " modul per orang\n";
    echo "flag = can_view can_create can_edit can_delete\n";
    foreach ($people as $d) {
        $granted = array_filter($d['levels']);
        echo "\n{$d['name']}" . ($d['username'] ? " ({$d['username']})" : '') . "  → users.role = {$d['role']}  · user_business_assignment: ya\n";
        foreach ($d['levels'] as $code => $lv) {
            if ($lv > 0) {
                printf("   %-20s %s  (%s)\n", $code, implode('', Catalog::legacyFlags($lv)), Catalog::LEVELS[$lv]);
            }
        }
        echo '   + ' . (count($d['levels']) - count($granted)) . " modul lain ditulis 0000\n";
    }

    // Integrasi → tabel lama di database bisnis (nilai rahasia disamarkan)
    echo "\nIntegrasi → database bisnis lama\n";
    foreach (Integration::all($biz) as $code => $it) {
        $def = Integrations::DEFS[$code];
        if (!$it['row']) {
            echo "   {$def['label']}: belum diisi (tidak ditulis)\n";
            continue;
        }
        echo "   {$def['label']}" . ($it['enabled'] ? '' : ' (mati)') . "\n";
        foreach ($def['fields'] as $field => [, $kind, $target]) {
            $val = $kind === 'secret' ? (Integration::secretHint($biz, $code, $field, $it['secrets']) ?? '(kosong)') : (string)($it['config'][$field] ?? '');
            printf("      %-48s %s\n", $target, $val === '' ? '(kosong)' : str_replace("\n", ' / ', $val));
        }
        if ($def['enabled_target']) {
            printf("      %-48s %d\n", $def['enabled_target'], $it['enabled'] ? 1 : 0);
        }
    }
}

/** Buat VAULT_KEY di secrets/vault.env (sekali). Kunci lama tidak pernah ditimpa: kunci API yang sudah tersimpan tidak bisa dibuka lagi kalau kuncinya diganti. */
function vaultKey(): void
{
    if (Vault::ready()) {
        echo "VAULT_KEY sudah ada — tidak diubah. (Backup file " . ADF_SECRETS . "/vault.env bersama backup database.)\n";
        return;
    }
    $path = ADF_SECRETS . '/vault.env';
    if (is_file($path) && filesize($path) > 0) {
        throw new RuntimeException("{$path} sudah ada tapi isinya tidak valid — periksa manual, jangan ditimpa");
    }
    if (file_put_contents($path, "# Kunci brankas ADF Console — JANGAN dihapus/diganti; backup terpisah\nVAULT_KEY=" . Vault::generateKey() . "\n", LOCK_EX) === false) {
        throw new RuntimeException("Tidak bisa menulis {$path} (jalankan sebagai user adf)");
    }
    @chmod($path, 0640);
    echo "VAULT_KEY dibuat di {$path}. Simpan salinannya di tempat aman (mis. password manager).\n";
}

/**
 * Impor dari database master sistem lama (LEGACY_MASTER_DB). Bawaan: 4 bisnis Grup Narayana.
 *   php cli/console.php legacy-import --dry-run
 *   php cli/console.php legacy-import --client="Grup Narayana" narayana-hotel bens-cafe
 */
function legacyImport(array $args): void
{
    $dry = in_array('--dry-run', $args, true);
    // Hari pindahan: data baru dari hosting → aturan mati PO/Stok hosting ikut dihitung sebagai akses Lihat
    Legacy::$fromHosting = in_array('--from-hosting', $args, true);
    $client = 'Grup Narayana';
    $slugs = [];
    foreach ($args as $a) {
        if (str_starts_with($a, '--client=')) {
            $client = trim(substr($a, 9), "\"' ");
        } elseif (!str_starts_with($a, '--')) {
            $slugs[] = $a;
        }
    }
    $slugs = $slugs ?: ['narayana-hotel', 'bens-cafe', 'eaat-meet', 'gudang-nasita'];
    foreach (Legacy::import($slugs, $client, $dry) as $line) {
        echo $line, PHP_EOL;
    }
    // Bukti: hitung balik dari Console harus sama persis dengan tabel lama
    echo "\nCek beda Console vs sistem lama:\n";
    foreach ($slugs as $slug) {
        $st = Auth::db()->prepare('SELECT id FROM businesses WHERE slug = ?');
        $st->execute([$slug]);
        if (!$dry && ($biz = Biz::business((int)$st->fetchColumn()))) {
            $d = Legacy::diff($biz);
            echo "   {$slug}: " . ($d ? count($d) . " beda\n      " . implode("\n      ", $d) : 'IDENTIK') . "\n";
        }
    }
    if ($dry) {
        echo "   (uji coba — cek beda dijalankan setelah impor sungguhan)\n";
    }
}

function legacyDiff(string $slug): void
{
    $st = Auth::db()->prepare('SELECT id FROM businesses WHERE slug = ?');
    $st->execute([$slug]);
    $biz = Biz::business((int)$st->fetchColumn()) ?: throw new RuntimeException("Bisnis tidak ditemukan: {$slug}");
    $d = Legacy::diff($biz);
    echo $d ? implode(PHP_EOL, $d) . PHP_EOL : "IDENTIK — izin di Console sama persis dengan tabel sistem lama.\n";
}
