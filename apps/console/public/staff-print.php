<?php

/**
 * Daftar akun Staff Portal satu bisnis, siap dicetak / disimpan sebagai PDF (khusus admin).
 * Password hanya tampil bila tersimpan dari panel lama (plain_password); password yang dibuat dari Console
 * tidak disimpan teks aslinya → ditulis "buat baru di Console". Setiap ekspor dicatat di audit log.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireAdmin();
$id = (int)($_GET['id'] ?? 0);
$biz = Biz::business($id);
if (!$biz) {
    http_response_code(404);
    exit('Bisnis tidak ditemukan.');
}
try {
    $sdb = StaffPortal::db($biz);
} catch (Throwable $e) {
    exit(e($e->getMessage()));
}
if (!$sdb) {
    exit('Bisnis ini belum terhubung ke database sistem.');
}
header('Cache-Control: no-store');
$emps = StaffPortal::employees($sdb);
$url = StaffPortal::url($biz);
Auth::audit('staff_portal.export', "{$biz['name']}: " . count($emps) . ' karyawan');
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Akun Staff Portal · <?= e($biz['name']) ?> · <?= date('d M Y') ?></title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font: 12px/1.45 'Segoe UI', system-ui, -apple-system, Roboto, sans-serif; color: #0f172a; background: #fff; }
        .bar { display: flex; gap: 8px; justify-content: flex-end; margin-bottom: 16px; }
        .bar button { padding: 8px 16px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        .bar button.main { background: #ff6a1a; border-color: #ff6a1a; color: #fff; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .sub { color: #64748b; margin: 0 0 4px; }
        .link { margin: 0 0 14px; font-size: 11.5px; }
        .link b { color: #1d4ed8; word-break: break-all; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #64748b; border-bottom: 2px solid #0f172a; padding: 6px 8px; }
        td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        tr:nth-child(even) td { background: #f8fafc; }
        .mono { font-family: ui-monospace, Consolas, monospace; font-size: 12.5px; }
        .muted { color: #94a3b8; font-size: 11px; }
        .note { margin-top: 16px; padding: 10px 12px; border: 1px solid #fecaca; background: #fef2f2; border-radius: 8px; color: #991b1b; font-size: 11px; }
        @media print { body { padding: 0; } .bar { display: none; } tr { break-inside: avoid; } }
    </style>
</head>

<body>
    <div class="bar">
        <button type="button" onclick="history.length > 1 ? history.back() : window.close()">Kembali</button>
        <button type="button" class="main" onclick="window.print()">Simpan PDF / Cetak</button>
    </div>
    <h1>Akun Staff Portal · <?= e($biz['name']) ?></h1>
    <p class="sub">Dicetak <?= date('d M Y H:i') ?> WIB oleh <?= e($me['username']) ?> · <?= count($emps) ?> karyawan aktif</p>
    <p class="link">Link login: <b><?= e($url) ?></b></p>
    <table>
        <thead><tr><th>#</th><th>Nama</th><th>Jabatan</th><th>Username</th><th>Password</th></tr></thead>
        <tbody>
            <?php $n = 0; foreach ($emps as $em): $n++; ?>
                <tr>
                    <td class="muted"><?= $n ?></td>
                    <td><b><?= e($em['full_name']) ?></b><br><span class="muted"><?= e($em['employee_code']) ?></span></td>
                    <td><?= e($em['position'] ?: '-') ?></td>
                    <td class="mono"><?= $em['account_id'] ? e($em['username']) : '<span class="muted">belum punya akun</span>' ?></td>
                    <td class="mono"><?= !$em['account_id'] ? '' : (!empty($em['old_password']) ? e($em['old_password']) : '<span class="muted">tidak tersimpan — buat baru di Console</span>') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$emps): ?><tr><td colspan="5" class="muted">Belum ada karyawan aktif.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <div class="note">RAHASIA — berisi password login karyawan. Jangan dibagikan ke grup / orang lain; simpan di tempat aman dan hapus bila tidak diperlukan lagi.</div>
    <script>
        // Langsung buka dialog cetak (pilih "Simpan sebagai PDF")
        window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
    </script>
</body>

</html>
