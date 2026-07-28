<?php
/**
 * Fix: Migrate [Kas Besar] → [Rekening Operasional] for operational bank account entries
 *
 * Transaksi pengeluaran yang dibayar dari rekening BRI Operasional (tujuan Setor Tunai)
 * sebelumnya di-tag [Kas Besar]. Script ini mendeteksi akun-akun operasional tersebut
 * dari tabel cash_transfers, lalu memperbarui deskripsi cash_book yang bersangkutan.
 *
 * Jalankan SEKALI via browser: /fix-kas-besar-to-rekening-ops.php
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';

// Require login
session_start();
if (empty($_SESSION['user_id'])) {
    die('Login required');
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    die('Admin access required');
}

$dryRun = !isset($_GET['execute']);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Fix Rekening Operasional Tags</title>
<style>
body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
h2 { color: #4fc1ff; }
.ok { color: #4ec9b0; }
.warn { color: #ce9178; }
.err { color: #f48771; }
.info { color: #9cdcfe; }
pre { background: #252526; padding: 12px; border-radius: 4px; }
.btn { display: inline-block; padding: 10px 20px; background: #0e639c; color: white;
       text-decoration: none; border-radius: 4px; margin-top: 16px; font-size: 14px; }
.btn.danger { background: #c9291e; }
</style>
</head>
<body>
<h2>Fix: [Kas Besar] → [Rekening Operasional]</h2>
<?php if ($dryRun): ?>
<p class="warn">⚠️ Mode DRY RUN — tidak ada perubahan nyata. <a class="btn danger" href="?execute=1" onclick="return confirm('Yakin jalankan update sungguhan?')">Jalankan SUNGGUHAN</a></p>
<?php else: ?>
<p class="ok">✅ Mode EXECUTE — perubahan disimpan ke database.</p>
<?php endif; ?>
<pre>
<?php

try {
    // Master DB
    $masterDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Check if cash_transfers exists in master DB
    $tbCheck = $masterDb->query("SHOW TABLES LIKE 'cash_transfers'");
    if (!$tbCheck->fetch()) {
        echo "<span class='err'>ERROR: Tabel cash_transfers tidak ditemukan di master DB (" . DB_NAME . ").</span>\n";
        echo "<span class='info'>Pastikan script dijalankan di environment yang memiliki tabel cash_transfers (production).</span>\n";
        exit;
    }

    // Load businesses
    $businesses = $masterDb->query(
        "SELECT b.id, b.business_name, b.database_name FROM businesses b WHERE b.is_active = 1 ORDER BY b.id"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo "Ditemukan " . count($businesses) . " bisnis aktif.\n\n";

    $totalUpdated = 0;

    foreach ($businesses as $biz) {
        $bizId   = $biz['id'];
        $bizName = $biz['business_name'];
        $bizDb   = $biz['database_name'];

        echo "─── Bisnis: <span class='info'>{$bizName}</span> (id={$bizId}, db={$bizDb})\n";

        // Find operational account IDs for this business (setor tunai destinations)
        $opStmt = $masterDb->prepare(
            "SELECT DISTINCT ct.bank_account_id, ca.account_name
             FROM cash_transfers ct
             JOIN cash_accounts ca ON ca.id = ct.bank_account_id
             WHERE ct.business_id = ?"
        );
        $opStmt->execute([$bizId]);
        $opAccounts = $opStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($opAccounts)) {
            echo "    <span class='warn'>Tidak ada akun operasional (belum ada setor tunai). Skip.</span>\n";
            continue;
        }

        $opIds = array_column($opAccounts, 'bank_account_id');
        $opNames = implode(', ', array_map(fn($a) => $a['account_name'] . " (id={$a['bank_account_id']})", $opAccounts));
        echo "    Akun operasional: {$opNames}\n";

        // Connect to business DB
        // Production: remap adf_* → adfb2574_*
        $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false &&
                         strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
        $dbMapping = [
            'adf_system'          => 'adfb2574_adf',
            'adf_narayana_hotel'  => 'adfb2574_narayana_hotel',
            'adf_benscafe'        => 'adfb2574_Adf_Bens',
            'adf_demo'            => 'adfb2574_demo',
            'adf_cqc'             => 'adfb2574_cqc',
        ];
        $actualDb = $isProduction
            ? ($dbMapping[$bizDb] ?? $bizDb)
            : $bizDb;

        try {
            $bizPdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname={$actualDb};charset=utf8mb4",
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            echo "    <span class='err'>Gagal konek ke {$actualDb}: " . $e->getMessage() . "</span>\n";
            continue;
        }

        // Find affected entries
        $placeholders = implode(',', array_fill(0, count($opIds), '?'));
        $findSql = "SELECT id, cash_account_id, description
                    FROM cash_book
                    WHERE cash_account_id IN ({$placeholders})
                    AND description LIKE '%[Kas Besar]%'";
        $findStmt = $bizPdo->prepare($findSql);
        $findStmt->execute($opIds);
        $rows = $findStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            echo "    <span class='ok'>Tidak ada entry [Kas Besar] yang perlu diperbaiki.</span>\n";
            continue;
        }

        echo "    Ditemukan " . count($rows) . " entry yang akan diupdate:\n";
        foreach ($rows as $row) {
            echo "      id={$row['id']} acct={$row['cash_account_id']} desc=" . htmlspecialchars(substr($row['description'], 0, 80)) . "\n";
        }

        if (!$dryRun) {
            $updateSql = "UPDATE cash_book
                          SET description = REPLACE(description, '[Kas Besar]', '[Rekening Operasional]'),
                              updated_at = NOW()
                          WHERE cash_account_id IN ({$placeholders})
                          AND description LIKE '%[Kas Besar]%'";
            $updStmt = $bizPdo->prepare($updateSql);
            $updStmt->execute($opIds);
            $affected = $updStmt->rowCount();
            echo "    <span class='ok'>✅ Updated {$affected} baris.</span>\n";
            $totalUpdated += $affected;
        } else {
            echo "    <span class='warn'>[DRY RUN] Akan update " . count($rows) . " baris.</span>\n";
        }
        echo "\n";
    }

    if (!$dryRun) {
        echo "\n<span class='ok'>══ SELESAI: Total {$totalUpdated} baris diupdate. ══</span>\n";
    } else {
        echo "\n<span class='warn'>══ DRY RUN selesai. Klik tombol di atas untuk eksekusi sungguhan. ══</span>\n";
    }

} catch (Throwable $e) {
    echo "<span class='err'>FATAL ERROR: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

?>
</pre>
</body>
</html>
