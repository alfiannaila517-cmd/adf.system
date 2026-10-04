<?php

/**
 * Keamanan Developer Panel:
 *  - verifikasi tiket SSO dari ADF Store (HMAC-SHA256, berlaku 60 detik, sekali pakai)
 *  - login darurat langsung: batas percobaan + kode verifikasi email
 *
 * File rahasia ada di folder home hosting (di luar public_html, tidak ikut git):
 *   adf-sso-secret.php      kunci bersama dengan ADF Store (dibuat otomatis oleh ADF Store)
 *   adf-sso-used.json       nonce tiket yang sudah dipakai
 *   adf-dev-login.json      catatan percobaan login gagal
 *   adf-otp-disabled.txt    (darurat) buat lewat cPanel untuk mematikan OTP sementara
 */

require_once __DIR__ . '/adfstore_bridge.php';

const DEV_SEC_MAX_FAILS = 5;
const DEV_SEC_FAIL_WINDOW = 900;
const DEV_SEC_OTP_TTL = 600;
const DEV_SEC_OTP_MAX_TRIES = 5;

function dev_sec_home(): string
{
    return dirname(BASE_PATH); // public_html → home
}

function dev_sec_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function dev_sec_json_update(string $path, callable $fn)
{
    $fh = @fopen($path, 'c+');
    if (!$fh) {
        return null;
    }
    flock($fh, LOCK_EX);
    $data = json_decode((string) stream_get_contents($fh), true);
    [$newData, $result] = $fn(is_array($data) ? $data : []);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($newData));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod($path, 0600);
    return $result;
}

function dev_sec_otp_bypassed(): bool
{
    return is_file(dev_sec_home() . '/adf-otp-disabled.txt');
}

/* ── Tiket SSO dari ADF Store ───────────────────────────────────── */

function dev_sec_b64url_decode(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/**
 * Validasi tiket. Return [payload, ''] bila sah, atau [null, alasan].
 * Tiket hanya sah sekali: nonce dicatat & ditolak bila dipakai lagi.
 */
function dev_sec_verify_ticket(string $ticket): array
{
    $secretFile = dev_sec_home() . '/adf-sso-secret.php';
    $secret = is_file($secretFile) ? include $secretFile : null;
    if (!is_string($secret) || strlen($secret) < 64) {
        return [null, 'Kunci SSO belum ada di server.'];
    }
    $parts = explode('.', $ticket);
    if (count($parts) !== 2) {
        return [null, 'Format tiket tidak valid.'];
    }
    [$payloadB64, $sigB64] = $parts;
    $expected = hash_hmac('sha256', $payloadB64, $secret, true);
    if (!hash_equals($expected, dev_sec_b64url_decode($sigB64))) {
        return [null, 'Tanda tangan tiket tidak valid.'];
    }
    $p = json_decode(dev_sec_b64url_decode($payloadB64), true);
    if (!is_array($p) || ($p['aud'] ?? '') !== 'adf-developer' || empty($p['email']) || empty($p['nonce'])) {
        return [null, 'Isi tiket tidak valid.'];
    }
    $now = time();
    if (($p['exp'] ?? 0) < $now || ($p['iat'] ?? 0) > $now + 30) {
        return [null, 'Tiket sudah kedaluwarsa. Klik lagi dari ADF Store.'];
    }
    $nonce = (string) $p['nonce'];
    $fresh = dev_sec_json_update(dev_sec_home() . '/adf-sso-used.json', static function (array $used) use ($nonce, $now) {
        $used = array_filter($used, static fn($exp) => $exp > $now);
        if (isset($used[$nonce])) {
            return [$used, false];
        }
        $used[$nonce] = $now + 300;
        return [$used, true];
    });
    if ($fresh !== true) {
        return [null, 'Tiket sudah pernah dipakai.'];
    }
    return [$p, ''];
}

/* ── Batas percobaan login darurat ───────────────────────────────── */

function dev_sec_fail_keys(string $username): array
{
    return ['ip:' . dev_sec_ip(), 'user:' . strtolower(trim($username))];
}

function dev_sec_is_locked(string $username): bool
{
    $path = dev_sec_home() . '/adf-dev-login.json';
    $data = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    $since = time() - DEV_SEC_FAIL_WINDOW;
    foreach (dev_sec_fail_keys($username) as $k) {
        if (count(array_filter($data[$k] ?? [], static fn($t) => $t >= $since)) >= DEV_SEC_MAX_FAILS) {
            return true;
        }
    }
    return false;
}

function dev_sec_record_fail(string $username): void
{
    $keys = dev_sec_fail_keys($username);
    dev_sec_json_update(dev_sec_home() . '/adf-dev-login.json', static function (array $data) use ($keys) {
        $since = time() - DEV_SEC_FAIL_WINDOW;
        foreach ($data as $k => $times) {
            $data[$k] = array_values(array_filter((array) $times, static fn($t) => $t >= $since));
            if (!$data[$k]) {
                unset($data[$k]);
            }
        }
        foreach ($keys as $k) {
            $data[$k][] = time();
        }
        return [$data, null];
    });
}

function dev_sec_clear_fails(string $username): void
{
    $keys = dev_sec_fail_keys($username);
    dev_sec_json_update(dev_sec_home() . '/adf-dev-login.json', static function (array $data) use ($keys) {
        foreach ($keys as $k) {
            unset($data[$k]);
        }
        return [$data, null];
    });
}

/* ── Kode verifikasi email untuk login darurat ───────────────────── */

/** Kirim email lewat SMTP ADF Store (satu konfigurasi email untuk semua). */
function dev_sec_mail(string $to, string $subject, string $html, string $text): bool
{
    $root = adfstore_root();
    if (!$root || !is_file($root . '/includes/smtp-mailer.php') || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    require_once $root . '/includes/smtp-mailer.php';
    $ok = adf_smtp_send_html($to, $subject, $html, $text);
    if (!$ok) {
        error_log('dev OTP mail failed: ' . adf_mail_last_error());
    }
    return $ok;
}

function dev_sec_otp_send(array $user): bool
{
    $code = (string) random_int(100000, 999999);
    $_SESSION['dev_pending'] = [
        'user_id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires' => time() + DEV_SEC_OTP_TTL,
        'tries' => 0,
    ];
    $html = '<div style="font-family:Arial,sans-serif;max-width:420px;margin:auto;padding:20px;border:1px solid #e5e7eb;border-radius:12px;">'
        . '<h2 style="margin:0 0 6px;font-size:18px;">Kode Login Developer Panel</h2>'
        . '<div style="font-size:32px;letter-spacing:8px;font-weight:700;text-align:center;padding:12px;background:#f3f4f6;border-radius:10px;">' . $code . '</div>'
        . '<p style="margin:14px 0 0;color:#6b7280;font-size:12px;">Berlaku 10 menit. IP ' . htmlspecialchars(dev_sec_ip()) . ' · ' . date('d M Y H:i')
        . '.<br>Jika ini bukan Anda, segera ganti password developer.</p></div>';
    return dev_sec_mail((string) $user['email'], 'Kode login Developer Panel: ' . $code, $html, "Kode login Developer Panel: {$code} (berlaku 10 menit)");
}

/** @return array{0: bool, 1: string} */
function dev_sec_otp_verify(string $code): array
{
    $p = $_SESSION['dev_pending'] ?? null;
    if (!$p || time() > $p['expires']) {
        unset($_SESSION['dev_pending']);
        return [false, 'Kode kedaluwarsa. Silakan login ulang.'];
    }
    if ($p['tries'] >= DEV_SEC_OTP_MAX_TRIES) {
        unset($_SESSION['dev_pending']);
        return [false, 'Terlalu banyak kode salah. Silakan login ulang.'];
    }
    $_SESSION['dev_pending']['tries']++;
    if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $p['code_hash'])) {
        return [false, 'Kode salah.'];
    }
    return [true, ''];
}
