<?php

/**
 * Keamanan login admin ADF Store (pintu utama ke Developer Panel):
 *  - batas percobaan login (5x salah / 15 menit → dikunci 15 menit)
 *  - kode verifikasi email (OTP 6 digit, 10 menit, maks. 5 percobaan)
 *  - "ingat perangkat ini" 30 hari (token acak, yang disimpan hanya hash-nya)
 *  - email pemberitahuan setiap login berhasil
 *  - kunci rahasia & tiket sekali pakai untuk masuk Developer Panel (SSO)
 *
 * File rahasia disimpan di folder home hosting (DI LUAR public_html, tidak bisa dibuka dari web,
 * tidak ikut git): adf-sso-secret.php dan adf-otp-disabled.txt (darurat).
 */

require_once __DIR__ . '/smtp-mailer.php';
require_once __DIR__ . '/telegram.php';

const ADF_SEC_MAX_FAILS = 5;
const ADF_SEC_FAIL_WINDOW = 900;      // 15 menit
const ADF_SEC_OTP_TTL = 600;          // 10 menit
const ADF_SEC_OTP_MAX_TRIES = 5;
const ADF_SEC_OTP_RESEND_GAP = 60;    // detik
const ADF_SEC_DEVICE_DAYS = 30;
const ADF_SEC_DEVICE_COOKIE = 'adf_td';
const ADF_SEC_SSO_TTL = 60;           // tiket Developer Panel berlaku 60 detik

/** Folder home hosting (induk public_html). Lokal: c:/xampp/htdocs. */
function adf_sec_home(): string
{
    // includes → adfsystem.store → public_html → home
    return dirname(__DIR__, 3);
}

function adf_sec_data_path(string $file): string
{
    return __DIR__ . '/../data/' . $file;
}

/** Baca/ubah file JSON dengan kunci (aman dari request bersamaan). */
function adf_sec_json_update(string $path, callable $fn)
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $fh = @fopen($path, 'c+');
    if (!$fh) {
        return $fn([])[1] ?? null;
    }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $data = json_decode((string) $raw, true);
    [$newData, $result] = $fn(is_array($data) ? $data : []);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($newData, JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

function adf_sec_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/* ── Batas percobaan login ───────────────────────────────────────── */

/** Kunci yang dipakai: per IP dan per username, supaya tidak bisa ditebak dari banyak IP / banyak akun. */
function adf_sec_fail_keys(string $username): array
{
    return ['ip:' . adf_sec_client_ip(), 'user:' . strtolower(trim($username))];
}

function adf_sec_is_locked(string $username): bool
{
    $path = adf_sec_data_path('login-attempts.json');
    if (!is_file($path)) {
        return false;
    }
    $data = json_decode((string) file_get_contents($path), true) ?: [];
    $since = time() - ADF_SEC_FAIL_WINDOW;
    foreach (adf_sec_fail_keys($username) as $key) {
        $recent = array_filter($data[$key] ?? [], static fn($t) => $t >= $since);
        if (count($recent) >= ADF_SEC_MAX_FAILS) {
            return true;
        }
    }
    return false;
}

function adf_sec_record_fail(string $username): void
{
    $keys = adf_sec_fail_keys($username);
    $justLocked = adf_sec_json_update(adf_sec_data_path('login-attempts.json'), static function (array $data) use ($keys) {
        $since = time() - ADF_SEC_FAIL_WINDOW;
        foreach ($data as $k => $times) {
            $data[$k] = array_values(array_filter((array) $times, static fn($t) => $t >= $since));
            if (!$data[$k]) {
                unset($data[$k]);
            }
        }
        $locked = false;
        foreach ($keys as $key) {
            $data[$key][] = time();
            if (count($data[$key]) === ADF_SEC_MAX_FAILS) {
                $locked = true; // baru saja mencapai batas → kirim peringatan sekali
            }
        }
        return [$data, $locked];
    });
    if ($justLocked) {
        adf_tg_security('🚨', 'Login ADF Store dikunci 15 menit', [
            'Akun dicoba' => $username !== '' ? $username : '-',
            'Sebab' => ADF_SEC_MAX_FAILS . 'x password / kode salah',
        ]);
    }
}

function adf_sec_clear_fails(string $username): void
{
    $keys = adf_sec_fail_keys($username);
    adf_sec_json_update(adf_sec_data_path('login-attempts.json'), static function (array $data) use ($keys) {
        foreach ($keys as $key) {
            unset($data[$key]);
        }
        return [$data, null];
    });
}

/* ── Darurat: OTP dimatikan sementara ───────────────────────────────
 * Hanya bisa diaktifkan dengan membuat file adf-otp-disabled.txt di folder home lewat cPanel,
 * jadi hanya pemilik akses cPanel yang bisa. Hapus lagi setelah email kembali normal. */
function adf_sec_otp_bypassed(): bool
{
    return is_file(adf_sec_home() . '/adf-otp-disabled.txt');
}

/* ── Kode verifikasi email (OTP) ─────────────────────────────────── */

function adf_sec_mask_email(string $email): string
{
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return substr($name, 0, 2) . str_repeat('•', max(1, strlen($name) - 2)) . '@' . $domain;
}

function adf_sec_otp_send(array $user): bool
{
    $code = (string) random_int(100000, 999999);
    $_SESSION['adf_pending_login'] = [
        'user_id' => $user['id'],
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires' => time() + ADF_SEC_OTP_TTL,
        'tries' => 0,
        'sent_at' => time(),
        'resends' => (int) ($_SESSION['adf_pending_login']['resends'] ?? -1) + 1,
    ];
    $html = '<div style="font-family:Arial,sans-serif;max-width:420px;margin:auto;padding:20px;border:1px solid #e5e7eb;border-radius:12px;">'
        . '<h2 style="margin:0 0 6px;font-size:18px;color:#111827;">Kode Verifikasi Login</h2>'
        . '<p style="margin:0 0 14px;color:#4b5563;font-size:14px;">Gunakan kode ini untuk masuk ke Admin ADF Store:</p>'
        . '<div style="font-size:32px;letter-spacing:8px;font-weight:700;color:#111827;text-align:center;padding:12px;background:#f3f4f6;border-radius:10px;">' . $code . '</div>'
        . '<p style="margin:14px 0 0;color:#6b7280;font-size:12px;">Berlaku 10 menit. Jangan berikan kode ini kepada siapa pun.<br>'
        . 'Permintaan dari IP ' . htmlspecialchars(adf_sec_client_ip()) . ' · ' . date('d M Y H:i') . '.<br>'
        . 'Jika ini bukan Anda, segera ganti password.</p></div>';
    $text = "Kode verifikasi login Admin ADF Store: {$code}\nBerlaku 10 menit. Jangan berikan kepada siapa pun.\n"
        . 'IP: ' . adf_sec_client_ip() . ' · ' . date('d M Y H:i');
    $ok = adf_smtp_send_html((string) $user['email'], 'Kode verifikasi login: ' . $code, $html, $text);
    if (!$ok) {
        error_log('OTP mail failed: ' . adf_mail_last_error());
    }
    return $ok;
}

/** @return array{0: bool, 1: string} [berhasil, pesan error] */
function adf_sec_otp_verify(string $code): array
{
    $p = $_SESSION['adf_pending_login'] ?? null;
    if (!$p) {
        return [false, 'Sesi verifikasi tidak ditemukan. Silakan login ulang.'];
    }
    if (time() > $p['expires']) {
        unset($_SESSION['adf_pending_login']);
        return [false, 'Kode sudah kedaluwarsa. Silakan login ulang.'];
    }
    if ($p['tries'] >= ADF_SEC_OTP_MAX_TRIES) {
        unset($_SESSION['adf_pending_login']);
        return [false, 'Terlalu banyak kode salah. Silakan login ulang.'];
    }
    $_SESSION['adf_pending_login']['tries']++;
    if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $p['code_hash'])) {
        $left = ADF_SEC_OTP_MAX_TRIES - $_SESSION['adf_pending_login']['tries'];
        return [false, 'Kode salah.' . ($left > 0 ? " Sisa {$left} percobaan." : '')];
    }
    return [true, ''];
}

/* ── Ingat perangkat (30 hari) ───────────────────────────────────── */

function adf_sec_device_is_trusted(string $userId): bool
{
    $token = (string) ($_COOKIE[ADF_SEC_DEVICE_COOKIE] ?? '');
    if (strlen($token) !== 64) {
        return false;
    }
    $path = adf_sec_data_path('trusted-devices.json');
    if (!is_file($path)) {
        return false;
    }
    $hash = hash('sha256', $token);
    foreach (json_decode((string) file_get_contents($path), true) ?: [] as $d) {
        if (($d['user_id'] ?? '') === $userId && hash_equals((string) ($d['hash'] ?? ''), $hash) && ($d['expires'] ?? 0) > time()) {
            return true;
        }
    }
    return false;
}

function adf_sec_trust_device(string $userId): void
{
    $token = bin2hex(random_bytes(32));
    adf_sec_json_update(adf_sec_data_path('trusted-devices.json'), static function (array $list) use ($userId, $token) {
        $list = array_values(array_filter($list, static fn($d) => ($d['expires'] ?? 0) > time()));
        $list[] = [
            'user_id' => $userId,
            'hash' => hash('sha256', $token),
            'expires' => time() + ADF_SEC_DEVICE_DAYS * 86400,
            'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
            'created' => date('c'),
        ];
        return [$list, null];
    });
    setcookie(ADF_SEC_DEVICE_COOKIE, $token, [
        'expires' => time() + ADF_SEC_DEVICE_DAYS * 86400,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/** Dipanggil saat password diganti/di-reset: semua perangkat harus verifikasi email lagi. */
function adf_sec_revoke_devices(string $userId): void
{
    adf_sec_json_update(adf_sec_data_path('trusted-devices.json'), static function (array $list) use ($userId) {
        return [array_values(array_filter($list, static fn($d) => ($d['user_id'] ?? '') !== $userId && ($d['expires'] ?? 0) > time())), null];
    });
}

/* ── Email pemberitahuan login ───────────────────────────────────── */

function adf_sec_login_alert(array $user, string $how): void
{
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#111827;">'
        . '<p><b>Login berhasil ke Admin ADF Store</b> (' . htmlspecialchars($how) . ')</p>'
        . '<p>Akun: ' . htmlspecialchars((string) $user['username']) . '<br>Waktu: ' . date('d M Y H:i:s')
        . '<br>IP: ' . htmlspecialchars(adf_sec_client_ip())
        . '<br>Perangkat: ' . htmlspecialchars(substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 160)) . '</p>'
        . '<p style="color:#b91c1c;">Jika ini bukan Anda, segera reset password lewat "Lupa password".</p></div>';
    if (!adf_smtp_send_html((string) $user['email'], 'Login baru ke Admin ADF Store', $html)) {
        error_log('login alert mail failed: ' . adf_mail_last_error());
    }
    adf_tg_security('🔐', 'Login ADF Store', ['Akun' => (string) $user['username'], 'Lewat' => $how]);
}

/* ── Tiket sekali pakai ke Developer Panel (SSO) ─────────────────── */

/** Kunci rahasia bersama dengan adfsystem.online/developer. Dibuat otomatis sekali, di luar public_html. */
function adf_sec_sso_secret(): ?string
{
    $path = adf_sec_home() . '/adf-sso-secret.php';
    if (!is_file($path)) {
        $secret = bin2hex(random_bytes(32));
        $fh = @fopen($path, 'x'); // gagal kalau sudah ada (dibuat request lain) → tidak menimpa
        if ($fh) {
            fwrite($fh, "<?php\n// Kunci rahasia SSO ADF Store → Developer Panel. JANGAN dibagikan / di-commit.\nreturn '" . $secret . "';\n");
            fclose($fh);
            @chmod($path, 0600);
        }
    }
    $secret = is_file($path) ? include $path : null;
    return (is_string($secret) && strlen($secret) >= 64) ? $secret : null;
}

function adf_sec_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** Tiket bertanda tangan HMAC-SHA256: berisi email admin, berlaku 60 detik, nonce untuk sekali pakai. */
function adf_sec_sso_ticket(array $admin, string $next): ?string
{
    $secret = adf_sec_sso_secret();
    if (!$secret) {
        return null;
    }
    $payload = adf_sec_b64url(json_encode([
        'aud' => 'adf-developer',
        'email' => strtolower((string) $admin['email']),
        'user' => (string) $admin['username'],
        'iat' => time(),
        'exp' => time() + ADF_SEC_SSO_TTL,
        'nonce' => bin2hex(random_bytes(16)),
        'next' => $next,
    ]));
    return $payload . '.' . adf_sec_b64url(hash_hmac('sha256', $payload, $secret, true));
}
