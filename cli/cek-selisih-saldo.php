<?php

/**
 * Cek selisih saldo rekening (HANYA MEMBACA, tidak mengubah data). Jalankan dari terminal VPS:
 *   sudo -u www-data php /srv/adf/legacy/public_html/cli/cek-selisih-saldo.php [slug ...]
 * Bawaan: bens-cafe eaat-meet.
 *
 * Per rekening (cash_accounts, database master) dibandingkan:
 *   - Saldo tersimpan  = cash_accounts.current_balance (dipakai aplikasi Owner, halaman Rekening, pilihan rekening bayar)
 *   - Menurut Buku Kas = pemasukan − pengeluaran di cash_book bisnis dengan cash_account_id rekening itu
 *                        + Setor Tunai yang masuk ke rekening bank itu (sisi bank Setor Tunai tidak punya baris cash_book)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// config.php memilih kredensial server (local-db-config.php) bila host bukan localhost
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cli.internal';
$_SERVER['REQUEST_URI'] = '/cli';
define('APP_ACCESS', true);
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/business_helper.php';

$slugs = array_slice($argv, 1) ?: ['bens-cafe', 'eaat-meet'];
$rp = fn ($v) => ($v < 0 ? '-' : '') . 'Rp ' . number_format(abs((float)$v), 0, ',', '.');
$master = new PDO('mysql:host=' . DB_HOST . ';dbname=' . MASTER_DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

foreach ($slugs as $slug) {
    $bizId = (int)getNumericBusinessId($slug);
    $biz = $bizId ? $master->query('SELECT id, business_name, database_name FROM businesses WHERE id = ' . $bizId)->fetch(PDO::FETCH_ASSOC) : null;
    echo "\n==================== " . strtoupper($slug) . " ====================\n";
    if (!$biz) {
        echo "Bisnis tidak ditemukan di database master.\n";
        continue;
    }
    // Database yang BENAR-BENAR dipakai sistem = 'database' di config/businesses/<slug>.php (lewat getDbName);
    // businesses.database_name di master bisa beda (mis. Bens: master "adfb2574_benscafe", dipakai "adfb2574_Adf_Bens")
    $cfgFile = __DIR__ . '/../config/businesses/' . basename($slug) . '.php';
    $cfgDb = is_file($cfgFile) ? ((require $cfgFile)['database'] ?? '') : '';
    $dbName = $cfgDb !== '' ? getDbName($cfgDb) : $biz['database_name'];
    echo "{$biz['business_name']} · id master {$biz['id']} · database {$dbName}" . ($dbName !== $biz['database_name'] ? " (di master tercatat {$biz['database_name']})" : '') . "\n";
    try {
        $bdb = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) {
        echo "Tidak bisa membuka database {$dbName}: " . $e->getMessage() . "\n";
        continue;
    }

    // Buku Kas per rekening
    $book = [];
    foreach ($bdb->query("SELECT cash_account_id AS a, SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) AS inc,
            SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) AS exp, COUNT(*) AS n
            FROM cash_book GROUP BY cash_account_id") as $r) {
        $book[(string)$r['a']] = $r;
    }
    // Setor Tunai masuk ke rekening bank (master)
    $setor = [];
    try {
        $st = $master->prepare('SELECT bank_account_id AS a, SUM(amount) AS s FROM cash_transfers WHERE business_id = ? AND COALESCE(is_archived, 0) = 0 GROUP BY bank_account_id');
        $st->execute([$bizId]);
        foreach ($st as $r) {
            $setor[(string)$r['a']] = (float)$r['s'];
        }
    } catch (Throwable $e) { /* tabel cash_transfers belum ada */ }

    $acc = $master->prepare('SELECT id, account_name, account_type, current_balance, is_active FROM cash_accounts WHERE business_id = ? ORDER BY account_type, id');
    $acc->execute([$bizId]);
    $own = [];
    printf("\n%-5s %-26s %-13s %17s %17s %17s %6s\n", 'ID', 'Rekening', 'Jenis', 'Saldo tersimpan', 'Menurut Buku Kas', 'SELISIH', 'Trx');
    echo str_repeat('-', 106) . "\n";
    $totDiff = 0;
    foreach ($acc as $a) {
        $id = (string)$a['id'];
        $own[$id] = true;
        $b = $book[$id] ?? ['inc' => 0, 'exp' => 0, 'n' => 0];
        $calc = (float)$b['inc'] - (float)$b['exp'] + ($setor[$id] ?? 0);
        $diff = (float)$a['current_balance'] - $calc;
        $totDiff += abs($diff);
        printf("%-5s %-26s %-13s %17s %17s %17s %6d%s\n", $id, mb_strimwidth($a['account_name'], 0, 26, '…'),
            $a['account_type'] . ($a['is_active'] ? '' : ' (off)'), $rp($a['current_balance']), $rp($calc),
            abs($diff) < 1 ? 'cocok' : $rp($diff), $b['n'], isset($setor[$id]) ? '  (+setor ' . $rp($setor[$id]) . ')' : '');
    }

    // Transaksi Buku Kas tanpa rekening / dengan rekening milik bisnis lain
    $noAcc = $book[''] ?? null;
    $foreign = array_diff_key($book, $own, ['' => 1]);
    echo "\n";
    if ($noAcc) {
        echo "• {$noAcc['n']} transaksi TANPA rekening: masuk " . $rp($noAcc['inc']) . ", keluar " . $rp($noAcc['exp']) . " (tidak masuk saldo rekening mana pun)\n";
    }
    foreach ($foreign as $aid => $b) {
        echo "• {$b['n']} transaksi memakai rekening id {$aid} yang BUKAN milik bisnis ini: masuk " . $rp($b['inc']) . ", keluar " . $rp($b['exp']) . "\n";
    }
    echo $totDiff < 1 ? "✓ Semua rekening cocok.\n" : "Total selisih (nilai mutlak): " . $rp($totDiff) . "\n";
}
echo "\nCatatan: SELISIH positif = saldo tersimpan LEBIH BESAR dari Buku Kas. Skrip ini tidak mengubah data apa pun.\n";
