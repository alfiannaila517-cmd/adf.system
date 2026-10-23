<?php

declare(strict_types=1);

/**
 * Masuk Console dengan akun Google (OpenID Connect, authorization code + PKCE) — sama dengan Admin ADF Store.
 *   - Client ID & Secret: secrets/google.env → GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET (client "ADF Store Login").
 *   - Redirect URI yang didaftarkan di Google Cloud: https://console.adfsystem.store/google-callback.php
 *   - Hanya email Google terverifikasi yang SAMA dengan email user Console yang bisa masuk; tanpa kode email,
 *     karena keamanan (termasuk verifikasi 2 langkah) mengikuti akun Google itu.
 */
final class Google
{
    public static function config(): ?array
    {
        $id = (string)Secrets::get('GOOGLE_CLIENT_ID', '');
        $secret = (string)Secrets::get('GOOGLE_CLIENT_SECRET', '');
        return $id !== '' && $secret !== '' ? ['client_id' => $id, 'client_secret' => $secret] : null;
    }

    public static function redirectUri(): string
    {
        return (Auth::https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'console.adfsystem.store') . '/google-callback.php';
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** URL login Google + simpan state/nonce/PKCE di sesi. */
    public static function authUrl(): ?string
    {
        $cfg = self::config();
        if (!$cfg) {
            return null;
        }
        $verifier = self::b64url(random_bytes(48));
        $_SESSION['console_google'] = ['state' => bin2hex(random_bytes(16)), 'nonce' => bin2hex(random_bytes(16)), 'verifier' => $verifier, 'started' => time()];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $cfg['client_id'],
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $_SESSION['console_google']['state'],
            'nonce' => $_SESSION['console_google']['nonce'],
            'code_challenge' => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);
    }

    /** @return array{0: ?string, 1: string} [email terverifikasi, ''] atau [null, alasan] */
    public static function callback(array $query): array
    {
        $flow = $_SESSION['console_google'] ?? null;
        unset($_SESSION['console_google']); // state hanya bisa dipakai sekali
        $cfg = self::config();
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
        // Tukar kode dengan token langsung ke server Google (HTTPS, sertifikat diverifikasi)
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => (string)$query['code'], 'client_id' => $cfg['client_id'], 'client_secret' => $cfg['client_secret'],
                'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code', 'code_verifier' => $flow['verifier'],
            ]),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $tok = json_decode((string)$resp, true);
        if ($http !== 200 || empty($tok['id_token'])) {
            error_log('Console google token exchange failed: http=' . $http . ' ' . substr((string)$resp, 0, 200));
            return [null, 'Gagal memverifikasi ke Google. Cek Client ID/Secret & redirect URI.'];
        }
        // id_token diterima langsung dari Google lewat TLS → cukup cek klaimnya (OIDC Core 3.1.3.7)
        $parts = explode('.', (string)$tok['id_token']);
        $claims = count($parts) === 3 ? json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
        if (!is_array($claims)) {
            return [null, 'Token Google tidak valid.'];
        }
        $ok = in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)
            && ($claims['aud'] ?? '') === $cfg['client_id']
            && ($claims['exp'] ?? 0) > time() && ($claims['iat'] ?? 0) <= time() + 300
            && hash_equals($flow['nonce'], (string)($claims['nonce'] ?? ''));
        if (!$ok) {
            return [null, 'Token Google tidak lolos pemeriksaan.'];
        }
        if (empty($claims['email']) || ($claims['email_verified'] ?? false) !== true) {
            return [null, 'Email akun Google belum terverifikasi.'];
        }
        return [strtolower((string)$claims['email']), ''];
    }
}
