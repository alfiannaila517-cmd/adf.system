<?php
/**
 * Pintu masuk Developer Panel dari ADF Store (tiket sekali pakai, lihat includes/sso_lib.php).
 * Akun developer dicocokkan lewat EMAIL: email admin ADF Store harus sama dengan email
 * akun developer (role developer, aktif) di User & Akses Bisnis.
 */
define('APP_ACCESS', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';
require_once __DIR__ . '/includes/sso_lib.php';

$auth = new DevAuth();
$pdo = $auth->getConnection();

$error = '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['ticket'])) {
    $error = 'Buka Developer Panel lewat menu Developer di ADF Store.';
} else {
    [$payload, $reason] = dev_sec_verify_ticket((string) $_POST['ticket']);
    if (!$payload) {
        $error = $reason;
        // Tiket kedaluwarsa biasa tidak perlu alarm; tiket palsu / dipakai ulang = mencurigakan.
        if (stripos($reason, 'kedaluwarsa') === false) {
            dev_sec_tg('🚫', 'Tiket masuk Developer Panel ditolak', ['Alasan' => $reason]);
        }
    } else {
        $stmt = $pdo->prepare("
            SELECT u.*, r.role_code FROM users u JOIN roles r ON u.role_id = r.id
            WHERE LOWER(u.email) = ? AND u.is_active = 1 AND r.role_code = 'developer'
            LIMIT 1
        ");
        $stmt->execute([strtolower((string) $payload['email'])]);
        $devUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$devUser) {
            $error = 'Tidak ada akun developer aktif dengan email ' . $payload['email']
                . '. Samakan email akun developer (User & Akses Bisnis) dengan email admin ADF Store.';
            error_log('SSO rejected: no developer for ' . $payload['email'] . ' from ' . dev_sec_ip());
            dev_sec_tg('🚫', 'Masuk Developer Panel ditolak', ['Email' => (string) $payload['email'], 'Alasan' => 'tidak ada akun developer dengan email ini']);
        } else {
            $wasLoggedIn = $auth->isLoggedIn() && (int) ($_SESSION['dev_user_id'] ?? 0) === (int) $devUser['id'];
            $auth->completeLogin($devUser, 'sso');
            if (!$wasLoggedIn) dev_sec_tg('🛠️', 'Masuk Developer Panel', ['Akun' => (string) $devUser['username'], 'Lewat' => 'ADF Store (' . ($payload['user'] ?? '-') . ')']);
            $next = (string) ($payload['next'] ?? 'index.php');
            if (!preg_match('/^[a-z0-9_-]+\.php(\?[A-Za-z0-9=&_-]*)?$/', $next)) {
                $next = 'index.php';
            }
            header('Location: ' . $next);
            exit;
        }
    }
}
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Akses Ditolak · Developer Panel</title>
    <style>
        body { font-family: Inter, Arial, sans-serif; background: #161925; color: #e5e7eb; display: grid; place-items: center; min-height: 100vh; margin: 0; }
        .box { background: #1f2333; border: 1px solid #2c3145; border-radius: 12px; padding: 24px; max-width: 420px; text-align: center; }
        h1 { font-size: 17px; margin: 0 0 8px; } p { font-size: 13px; color: #a3a9bb; line-height: 1.5; }
        a { color: #a78bfa; font-size: 13px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Akses ditolak</h1>
        <p><?php echo htmlspecialchars($error); ?></p>
        <a href="https://adfsystem.store/admin/">Masuk lewat ADF Store</a>
    </div>
</body>
</html>
