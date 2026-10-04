<?php

/**
 * Login dengan akun Google (OpenID Connect, authorization code + PKCE) untuk Admin ADF Store.
 *
 * - Client ID & Secret disimpan di folder home hosting (di luar public_html, tidak ikut git):
 *   adf-google-oauth.php — diisi lewat menu Pengaturan → Login Google.
 * - Hanya email Google yang SAMA dengan email user admin ADF Store yang bisa masuk,
 *   dan email harus terverifikasi oleh Google.
 * - Keamanan login Google (termasuk verifikasi 2 langkah) mengikuti akun Google itu sendiri,
 *   jadi tidak perlu kode email lagi.
 */

require_once __DIR__ . '/security.php';

function adf_google_config_path(): string
{
    return adf_sec_home() . '/adf-google-oauth.php';
}

/** @return array{client_id: string, client_secret: string}|null */
function adf_google_config(): ?array
{
    $path = adf_google_config_path();
    $cfg = is_file($path) ? include $path : null;
    if (!is_array($cfg) || empty($cfg['client_id']) || empty($cfg['client_secret'])) {
        return null;
    }
    return ['client_id' => (string) $cfg['client_id'], 'client_secret' => (string) $cfg['client_secret']];
}

function adf_google_save_config(string $clientId, string $clientSecret): bool
{
    $content = "<?php\n// Login Google ADF Store. JANGAN dibagikan / di-commit.\nreturn " . var_export([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'updated_at' => date('c'),
    ], true) . ";\n";
    $ok = file_put_contents(adf_google_config_path(), $content, LOCK_EX) !== false;
    if ($ok) {
        @chmod(adf_google_config_path(), 0600);
    }
    return $ok;
}

/** URL callback yang harus didaftarkan di Google Cloud (Authorized redirect URI). */
function adf_google_redirect_uri(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php')), '/');
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'adfsystem.store') . $dir . '/google-callback.php';
}

/** Buat URL login Google + simpan state/nonce/PKCE di sesi. */
function adf_google_auth_url(): ?string
{
    $cfg = adf_google_config();
    if (!$cfg) {
        return null;
    }
    $verifier = adf_sec_b64url(random_bytes(48));
    $_SESSION['adf_google'] = [
        'state' => bin2hex(random_bytes(16)),
        'nonce' => bin2hex(random_bytes(16)),
        'verifier' => $verifier,
        'started' => time(),
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $cfg['client_id'],
        'redirect_uri' => adf_google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $_SESSION['adf_google']['state'],
        'nonce' => $_SESSION['adf_google']['nonce'],
        'code_challenge' => adf_sec_b64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ]);
}

/**
 * Proses callback Google. Return [email, ''] bila sah, atau [null, alasan].
 */
function adf_google_handle_callback(array $query): array
{
    $flow = $_SESSION['adf_google'] ?? null;
    unset($_SESSION['adf_google']); // state hanya bisa dipakai sekali
    $cfg = adf_google_config();
    if (!$cfg) {
        return [null, 'Login Google belum diatur.'];
    }
    if (!empty($query['error'])) {
        return [null, 'Login Google dibatalkan.'];
    }
    if (!$flow || empty($query['state']) || !hash_equals($flow['state'], (string) $query['state']) || time() - $flow['started'] > 600) {
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
            'code' => (string) $query['code'],
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri' => adf_google_redirect_uri(),
            'grant_type' => 'authorization_code',
            'code_verifier' => $flow['verifier'],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $tok = json_decode((string) $resp, true);
    if ($http !== 200 || empty($tok['id_token'])) {
        error_log('google token exchange failed: http=' . $http . ' ' . substr((string) $resp, 0, 200));
        return [null, 'Gagal memverifikasi ke Google. Cek Client ID/Secret & redirect URI.'];
    }

    // id_token diterima langsung dari Google lewat TLS → cukup cek klaimnya (OIDC Core 3.1.3.7).
    $parts = explode('.', (string) $tok['id_token']);
    $claims = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
    if (!is_array($claims)) {
        return [null, 'Token Google tidak valid.'];
    }
    $issOk = in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true);
    $audOk = ($claims['aud'] ?? '') === $cfg['client_id'];
    $timeOk = ($claims['exp'] ?? 0) > time() && ($claims['iat'] ?? 0) <= time() + 300;
    $nonceOk = hash_equals($flow['nonce'], (string) ($claims['nonce'] ?? ''));
    if (!$issOk || !$audOk || !$timeOk || !$nonceOk) {
        return [null, 'Token Google tidak lolos pemeriksaan.'];
    }
    if (empty($claims['email']) || ($claims['email_verified'] ?? false) !== true) {
        return [null, 'Email akun Google belum terverifikasi.'];
    }
    return [strtolower((string) $claims['email']), ''];
}
