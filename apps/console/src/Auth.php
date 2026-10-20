<?php

declare(strict_types=1);

/**
 * Login ADF Console — perilaku sama dengan admin adfsystem.store lama:
 *  1. username/email + password (5x salah per IP / per akun dalam 15 menit → dikunci 15 menit)
 *  2. kode OTP 6 digit ke email (10 menit, maks. 5 percobaan, kirim ulang tiap 60 detik)
 *     — selalu diminta (sama dengan store lama), kecuali mode darurat aktif (file secrets/console-otp-disabled)
 *  3. sesi aktif; keluar otomatis setelah 2 jam tidak aktif
 * Setiap login berhasil → email pemberitahuan + Telegram; akun dikunci → Telegram.
 */
final class Auth
{
    public const MAX_FAILS = 5;
    public const FAIL_WINDOW = 900;
    public const OTP_TTL = 600;
    public const OTP_MAX_TRIES = 5;
    public const OTP_RESEND_GAP = 60;
    public const IDLE_TIMEOUT = 7200;

    public static function db(): PDO
    {
        return Db::connect(CONSOLE_DB);
    }

    public static function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /* ── Sesi ─────────────────────────────────────────────── */

    public static function user(): ?array
    {
        $u = $_SESSION['console_user'] ?? null;
        if (!$u || empty($u['mfa'])) {
            return null;
        }
        if (time() - (int)$u['last_seen'] > self::IDLE_TIMEOUT) {
            self::logout();
            return null;
        }
        $_SESSION['console_user']['last_seen'] = time();
        return $_SESSION['console_user'];
    }

    public static function requireLogin(): array
    {
        $u = self::user();
        if (!$u) {
            header('Location: /login.php');
            exit;
        }
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::requireLogin();
        if ($u['role'] !== 'admin') {
            header('Location: /?denied=1');
            exit;
        }
        return $u;
    }

    public static function complete(array $user, string $how): void
    {
        session_regenerate_id(true);
        unset($_SESSION['console_pending']);
        $_SESSION['console_user'] = [
            'id' => (int)$user['id'], 'username' => $user['username'], 'email' => $user['email'], 'role' => $user['role'],
            'mfa' => true, 'login_at' => time(), 'last_seen' => time(),
        ];
        self::clearFails($user['username']);
        self::audit('login', $how);
        self::loginAlert($user, $how);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /* ── Password ─────────────────────────────────────────── */

    public static function findUser(string $usernameOrEmail): ?array
    {
        $st = self::db()->prepare('SELECT * FROM console_users WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$usernameOrEmail, $usernameOrEmail]);
        return $st->fetch() ?: null;
    }

    public static function findUserById(int $id): ?array
    {
        $st = self::db()->prepare('SELECT * FROM console_users WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function checkPassword(string $usernameOrEmail, string $password): ?array
    {
        $user = self::findUser(trim($usernameOrEmail));
        if ($user && password_verify($password, $user['password_hash'])) {
            return $user;
        }
        // Tetap hitung hash walau akun tidak ada, supaya waktu respons tidak membocorkan akun yang terdaftar
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        return null;
    }

    /* ── Batas percobaan ─────────────────────────────────── */

    private static function lockKeys(string $username): array
    {
        return ['ip:' . self::ip(), 'user:' . strtolower(trim($username))];
    }

    public static function isLocked(string $username): bool
    {
        $st = self::db()->prepare('SELECT COUNT(*) FROM console_login_attempts WHERE lock_key = ? AND attempted_at >= ?');
        foreach (self::lockKeys($username) as $k) {
            $st->execute([$k, time() - self::FAIL_WINDOW]);
            if ((int)$st->fetchColumn() >= self::MAX_FAILS) {
                return true;
            }
        }
        return false;
    }

    public static function recordFail(string $username): void
    {
        $db = self::db();
        $db->prepare('DELETE FROM console_login_attempts WHERE attempted_at < ?')->execute([time() - self::FAIL_WINDOW]);
        $ins = $db->prepare('INSERT INTO console_login_attempts (lock_key, attempted_at) VALUES (?, ?)');
        $cnt = $db->prepare('SELECT COUNT(*) FROM console_login_attempts WHERE lock_key = ?');
        $justLocked = false;
        foreach (self::lockKeys($username) as $k) {
            $ins->execute([$k, time()]);
            $cnt->execute([$k]);
            if ((int)$cnt->fetchColumn() === self::MAX_FAILS) {
                $justLocked = true;
            }
        }
        if ($justLocked) {
            Telegram::security('🚨', 'Login ADF Console dikunci 15 menit', ['Akun dicoba' => $username !== '' ? $username : '-', 'Sebab' => self::MAX_FAILS . 'x password / kode salah']);
        }
    }

    public static function clearFails(string $username): void
    {
        $st = self::db()->prepare('DELETE FROM console_login_attempts WHERE lock_key = ?');
        foreach (self::lockKeys($username) as $k) {
            $st->execute([$k]);
        }
    }

    /* ── OTP email ───────────────────────────────────────── */

    public static function otpBypassed(): bool
    {
        return is_file(ADF_SECRETS . '/console-otp-disabled');
    }

    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return substr($name, 0, 2) . str_repeat('•', max(1, strlen($name) - 2)) . '@' . $domain;
    }

    public static function otpSend(array $user): bool
    {
        $code = (string)random_int(100000, 999999);
        $_SESSION['console_pending'] = [
            'user_id' => (int)$user['id'], 'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires' => time() + self::OTP_TTL, 'tries' => 0, 'sent_at' => time(),
        ];
        $html = '<div style="font-family:Arial,sans-serif;max-width:420px;margin:auto;padding:20px;border:1px solid #e5e7eb;border-radius:12px">'
            . '<h2 style="margin:0 0 6px;font-size:18px;color:#111827">Kode Verifikasi Login</h2>'
            . '<p style="margin:0 0 14px;color:#4b5563;font-size:14px">Gunakan kode ini untuk masuk ke ADF Console:</p>'
            . '<div style="font-size:32px;letter-spacing:8px;font-weight:700;color:#111827;text-align:center;padding:12px;background:#f3f4f6;border-radius:10px">' . $code . '</div>'
            . '<p style="margin:14px 0 0;color:#6b7280;font-size:12px">Berlaku 10 menit. Jangan berikan kode ini kepada siapa pun.<br>'
            . 'Permintaan dari IP ' . htmlspecialchars(self::ip()) . ' · ' . date('d M Y H:i') . ' WIB.<br>Jika ini bukan Anda, segera ganti password.</p></div>';
        return Mailer::send((string)$user['email'], 'Kode verifikasi login: ' . $code, $html, 'ADF Console');
    }

    /** @return array{0: bool, 1: string} */
    public static function otpVerify(string $code): array
    {
        $p = $_SESSION['console_pending'] ?? null;
        if (!$p) {
            return [false, 'Sesi verifikasi tidak ditemukan. Silakan login ulang.'];
        }
        if (time() > $p['expires']) {
            unset($_SESSION['console_pending']);
            return [false, 'Kode sudah kedaluwarsa. Silakan login ulang.'];
        }
        if ($p['tries'] >= self::OTP_MAX_TRIES) {
            unset($_SESSION['console_pending']);
            return [false, 'Terlalu banyak kode salah. Silakan login ulang.'];
        }
        $_SESSION['console_pending']['tries']++;
        if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $p['code_hash'])) {
            $left = self::OTP_MAX_TRIES - $_SESSION['console_pending']['tries'];
            return [false, 'Kode salah.' . ($left > 0 ? " Sisa {$left} percobaan." : '')];
        }
        return [true, ''];
    }

    /* ── Reset password ──────────────────────────────────── */

    public static function createResetToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $db = self::db();
        $db->prepare('DELETE FROM console_password_resets WHERE user_id = ? OR expires_at <= NOW()')->execute([$userId]);
        $db->prepare('INSERT INTO console_password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))')
            ->execute([$userId, hash('sha256', $token)]);
        return $token;
    }

    public static function resetTokenUser(string $token): ?int
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $st = self::db()->prepare('SELECT user_id FROM console_password_resets WHERE token_hash = ? AND expires_at > NOW()');
        $st->execute([hash('sha256', $token)]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public static function setPassword(int $userId, string $password): void
    {
        $db = self::db();
        $db->prepare('UPDATE console_users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        $db->prepare('DELETE FROM console_password_resets WHERE user_id = ?')->execute([$userId]);
    }

    /* ── Pemberitahuan & jejak ───────────────────────────── */

    private static function loginAlert(array $user, string $how): void
    {
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#111827">'
            . '<p><b>Login berhasil ke ADF Console</b> (' . htmlspecialchars($how) . ')</p>'
            . '<p>Akun: ' . htmlspecialchars((string)$user['username']) . '<br>Waktu: ' . date('d M Y H:i:s') . ' WIB<br>IP: ' . htmlspecialchars(self::ip())
            . '<br>Perangkat: ' . htmlspecialchars(substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 160)) . '</p>'
            . '<p style="color:#b91c1c">Jika ini bukan Anda, segera reset password lewat "Lupa password".</p></div>';
        if (!Mailer::send((string)$user['email'], 'Login baru ke ADF Console', $html, 'ADF Console')) {
            error_log('Console login alert mail: ' . Mailer::lastError());
        }
        Telegram::security('🔐', 'Login ADF Console', ['Akun' => (string)$user['username'], 'Lewat' => $how]);
    }

    public static function audit(string $action, string $detail = ''): void
    {
        $u = $_SESSION['console_user'] ?? null;
        try {
            self::db()->prepare('INSERT INTO console_audit_log (user_id, username, action, detail, ip) VALUES (?, ?, ?, ?, ?)')
                ->execute([$u['id'] ?? null, $u['username'] ?? null, $action, mb_substr($detail, 0, 500), self::ip()]);
        } catch (Throwable $e) {
            error_log('Console audit: ' . $e->getMessage());
        }
    }

    public static function https(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }
}
