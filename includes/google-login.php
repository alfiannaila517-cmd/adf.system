<?php

/**
 * Login dengan akun Google untuk ADF System (OpenID Connect, authorization code + PKCE).
 *
 * - Memakai berkas kredensial yang SAMA dengan Admin ADF Store: <home hosting>/adf-google-oauth.php
 *   (di luar public_html, tidak ikut git). Diisi lewat adfsystem.store → Pengaturan → Login Google.
 *   Tambahkan juga "Authorized redirect URI" baru di Google Cloud: https://adfsystem.online/google-callback.php
 * - Hanya email Google yang SAMA dengan email user ADF System (users.email, aktif) dan sudah
 *   terverifikasi oleh Google yang bisa masuk. Keamanan (termasuk verifikasi 2 langkah) mengikuti akun Google.
 */

function adfGoogleConfigPath(): string
{
    // includes -> public_html -> home
    return dirname(__DIR__, 2) . '/adf-google-oauth.php';
}

/** @return array{client_id: string, client_secret: string}|null */
function adfGoogleConfig(): ?array
{
    $path = adfGoogleConfigPath();
    $cfg = is_file($path) ? include $path : null;
    if (!is_array($cfg) || empty($cfg['client_id']) || empty($cfg['client_secret'])) {
        return null;
    }
    return ['client_id' => (string)$cfg['client_id'], 'client_secret' => (string)$cfg['client_secret']];
}

function adfGoogleB64Url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** URL callback yang harus didaftarkan di Google Cloud (Authorized redirect URI). Di produksi selalu https + domain utama (tidak bergantung header proxy/www). */
function adfGoogleRedirectUri(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/google-login.php')), '/');
        return 'http://' . ($host ?: 'localhost') . $dir . '/google-callback.php';
    }
    $domain = defined('MASTER_DOMAIN') ? MASTER_DOMAIN : 'adfsystem.online';
    return 'https://' . $domain . '/google-callback.php';
}

/** Buat URL login Google + simpan state/nonce/PKCE di sesi. */
function adfGoogleAuthUrl(): ?string
{
    $cfg = adfGoogleConfig();
    if (!$cfg) {
        return null;
    }
    $verifier = adfGoogleB64Url(random_bytes(48));
    $_SESSION['adf_google'] = [
        'state' => bin2hex(random_bytes(16)),
        'nonce' => bin2hex(random_bytes(16)),
        'verifier' => $verifier,
        'started' => time(),
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $cfg['client_id'],
        'redirect_uri' => adfGoogleRedirectUri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $_SESSION['adf_google']['state'],
        'nonce' => $_SESSION['adf_google']['nonce'],
        'code_challenge' => adfGoogleB64Url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ]);
}

/** Proses callback Google. Return [email, ''] bila sah, atau [null, alasan]. */
function adfGoogleHandleCallback(array $query): array
{
    $flow = $_SESSION['adf_google'] ?? null;
    unset($_SESSION['adf_google']); // state hanya bisa dipakai sekali
    $cfg = adfGoogleConfig();
    if (!$cfg) {
        return [null, 'Login Google belum diatur.'];
    }
    if (!empty($query['error'])) {
        return [null, 'Login Google dibatalkan.'];
    }
    if (!$flow || empty($query['state']) || !hash_equals($flow['state'], (string)$query['state']) || time() - $flow['started'] > 600) {
        return [null, 'Sesi login Google tidak valid / kedaluwarsa. Coba lagi.'];
    }
    if (empty($query['code'])) {
        return [null, 'Kode dari Google tidak ada.'];
    }

    // Tukar kode dengan token langsung ke server Google (HTTPS, sertifikat diverifikasi).
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => (string)$query['code'],
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri' => adfGoogleRedirectUri(),
            'grant_type' => 'authorization_code',
            'code_verifier' => $flow['verifier'],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $tok = json_decode((string)$resp, true);
    if ($http !== 200 || empty($tok['id_token'])) {
        error_log('google token exchange failed: http=' . $http . ' ' . substr((string)$resp, 0, 200));
        return [null, 'Gagal memverifikasi ke Google. Pastikan redirect URI ' . adfGoogleRedirectUri() . ' sudah didaftarkan di Google Cloud.'];
    }

    // id_token diterima langsung dari Google lewat TLS -> cukup cek klaimnya (OIDC Core 3.1.3.7).
    $parts = explode('.', (string)$tok['id_token']);
    $claims = count($parts) === 3 ? json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
    if (!is_array($claims)) {
        return [null, 'Token Google tidak valid.'];
    }
    $issOk = in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true);
    $audOk = ($claims['aud'] ?? '') === $cfg['client_id'];
    $timeOk = ($claims['exp'] ?? 0) > time() && ($claims['iat'] ?? 0) <= time() + 300;
    $nonceOk = hash_equals($flow['nonce'], (string)($claims['nonce'] ?? ''));
    if (!$issOk || !$audOk || !$timeOk || !$nonceOk) {
        return [null, 'Token Google tidak lolos pemeriksaan.'];
    }
    if (empty($claims['email']) || ($claims['email_verified'] ?? false) !== true) {
        return [null, 'Email akun Google belum terverifikasi.'];
    }
    return [strtolower((string)$claims['email']), ''];
}

/** User ADF System aktif dengan email tersebut, atau null. */
function adfGoogleFindUser(string $email): ?array
{
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $st = $pdo->prepare('SELECT u.*, r.role_code FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE LOWER(u.email) = ? AND u.is_active = 1 LIMIT 1');
        $st->execute([strtolower($email)]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        return $u ?: null;
    } catch (Throwable $e) {
        error_log('adfGoogleFindUser: ' . $e->getMessage());
        return null;
    }
}

/**
 * Buat sesi login (sama seperti Auth::login) lalu arahkan ke halaman awal sesuai role.
 * Tidak mengembalikan nilai: selalu redirect + exit.
 */
function adfGoogleCompleteLogin(array $user): void
{
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    session_regenerate_id(true);
    $roleCode = $user['role_code'] ?? 'staff';
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $roleCode;
    $_SESSION['business_access'] = $user['business_access'] ?? 'all';
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
    $_SESSION['user_theme'] = 'dark';
    $_SESSION['user_language'] = 'id';
    try {
        $st = $pdo->prepare('SELECT theme, language FROM user_preferences WHERE user_id = ?');
        $st->execute([$user['id']]);
        if ($pref = $st->fetch(PDO::FETCH_ASSOC)) {
            $_SESSION['user_theme'] = $pref['theme'];
            $_SESSION['user_language'] = $pref['language'];
        }
    } catch (Throwable $e) {
    }
    try {
        $pdo->prepare("INSERT INTO audit_logs (user_id, action_type, table_name, record_id, ip_address, created_at) VALUES (?, 'login', 'users', ?, ?, NOW())")
            ->execute([$user['id'], $user['id'], $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
    } catch (Throwable $e) {
    }
    try {
        $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
    } catch (Throwable $e) {
    }

    require_once __DIR__ . '/business_helper.php';
    require_once __DIR__ . '/business_access.php';

    if (in_array($roleCode, ['owner', 'admin', 'developer'], true)) {
        $list = getUserAvailableBusinesses();
        if (!empty($list)) {
            setActiveBusinessId(getPreferredDefaultBusiness($list));
        }
        header('Location: ' . BASE_URL . '/modules/owner/dashboard-2028.php');
        exit;
    }

    // User biasa: bisnis pertama yang boleh diakses (penugasan user, atau semua bisnis aktif bila belum ada penugasan)
    try {
        $st = $pdo->prepare('SELECT DISTINCT b.id, b.business_code FROM businesses b LEFT JOIN user_business_assignment uba ON b.id = uba.business_id AND uba.user_id = ? WHERE b.is_active = 1 ORDER BY uba.user_id DESC, b.business_name LIMIT 1');
        $st->execute([$user['id']]);
        if ($biz = $st->fetch(PDO::FETCH_ASSOC)) {
            $_SESSION['business_id'] = (int)$biz['id'];
            setActiveBusinessId(strtolower(str_replace('_', '-', $biz['business_code'])));
        }
    } catch (Throwable $e) {
    }
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
