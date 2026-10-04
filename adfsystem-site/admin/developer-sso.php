<?php
/**
 * Masuk ke Developer Panel (adfsystem.online/developer) dari ADF Store tanpa login ulang.
 *
 * Syarat: login admin ADF Store (sudah lolos verifikasi email), role admin, request POST + CSRF.
 * Store membuat tiket bertanda tangan (berlaku 60 detik, sekali pakai) lalu mengirimnya lewat
 * form POST otomatis — tiket tidak pernah muncul di URL/riwayat browser.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/security.php';

adf_admin_require_role('admin');

// Tiket hanya boleh diterbitkan dari adfsystem.store (atau localhost saat development).
$reqHost = strtolower(preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
$isLocal = strpos($reqHost, 'localhost') !== false || strpos($reqHost, '127.0.0.1') !== false;
if (!$isLocal && $reqHost !== 'adfsystem.store') {
    http_response_code(403);
    exit('Developer Panel hanya bisa dibuka dari https://adfsystem.store/admin/.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !adf_admin_csrf_check($_POST['csrf'] ?? null)) {
    header('Location: index.php');
    exit;
}

// Halaman tujuan di Developer Panel: hanya nama file .php di folder developer (tanpa domain/../).
$next = (string) ($_POST['next'] ?? 'index.php');
if (!preg_match('/^[a-z0-9_-]+\.php(\?[A-Za-z0-9=&_-]*)?$/', $next)) {
    $next = 'index.php';
}

$admin = adf_admin_current_user();
$ticket = adf_sec_sso_ticket($admin, $next);

$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$target = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false)
    ? 'http://' . $host . '/adf_system/developer/sso.php'
    : 'https://adfsystem.online/developer/sso.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Membuka Developer Panel…</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body class="admin-body">
    <div class="admin-login-wrap">
        <div class="admin-login-card" style="text-align:center;">
            <?php if (!$ticket): ?>
                <h1>Gagal</h1>
                <p class="admin-login-sub">Kunci rahasia SSO tidak bisa dibuat di folder home hosting. Hubungi developer / cek izin folder.</p>
                <a href="index.php" class="admin-back-link">&larr; Kembali</a>
            <?php else: ?>
                <h1>Membuka Developer Panel…</h1>
                <p class="admin-login-sub">Mohon tunggu sebentar.</p>
                <form id="ssoForm" method="post" action="<?php echo htmlspecialchars($target); ?>">
                    <input type="hidden" name="ticket" value="<?php echo htmlspecialchars($ticket); ?>">
                    <noscript><button type="submit" class="btn btn-primary">Lanjutkan</button></noscript>
                </form>
                <script>document.getElementById('ssoForm').submit();</script>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
