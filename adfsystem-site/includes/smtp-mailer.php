<?php
/**
 * Minimal SMTP client (no external library) — used because PHP mail() is
 * disabled on this host. Requires includes/mail-config.php with SMTP_* constants.
 */

require_once __DIR__ . '/mail-config.php';

$GLOBALS['adf_mail_last_error'] = '';

function adf_mail_last_error(): string
{
    return $GLOBALS['adf_mail_last_error'];
}

function adf_smtp_send(string $to, string $subject, string $body): bool
{
    $GLOBALS['adf_mail_last_error'] = '';

    if (!defined('SMTP_HOST') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
        $GLOBALS['adf_mail_last_error'] = 'SMTP belum dikonfigurasi (includes/mail-config.php).';
        return false;
    }

    $host = SMTP_HOST;
    $port = defined('SMTP_PORT') ? (int) SMTP_PORT : 587;
    $encryption = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls'; // 'tls', 'ssl', or ''
    $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : SMTP_USER;
    $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'ADF System';

    $remote = ('ssl' === $encryption ? 'ssl://' : '') . $host;
    $errno = 0;
    $errstr = '';
    $conn = @stream_socket_client($remote . ':' . $port, $errno, $errstr, 15);
    if (!$conn) {
        $GLOBALS['adf_mail_last_error'] = "Gagal konek ke {$host}:{$port} — {$errstr}";
        return false;
    }
    stream_set_timeout($conn, 15);

    $read = static function ($conn): string {
        $data = '';
        while (($line = fgets($conn, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };

    $expect = static function ($conn, string $code) use ($read, &$success, &$lastReply): bool {
        $lastReply = $read($conn);
        return substr($lastReply, 0, 3) === $code;
    };

    $lastReply = '';
    $ok = true;

    $lastReply = $read($conn); // banner
    if (substr($lastReply, 0, 3) !== '220') {
        fclose($conn);
        $GLOBALS['adf_mail_last_error'] = 'Server tidak siap: ' . trim($lastReply);
        return false;
    }

    $hostname = $_SERVER['SERVER_NAME'] ?? 'adfsystem.store';

    fwrite($conn, "EHLO {$hostname}\r\n");
    $lastReply = $read($conn);
    if (substr($lastReply, 0, 3) !== '250') {
        fclose($conn);
        $GLOBALS['adf_mail_last_error'] = 'EHLO gagal: ' . trim($lastReply);
        return false;
    }

    if ('tls' === $encryption) {
        fwrite($conn, "STARTTLS\r\n");
        $lastReply = $read($conn);
        if (substr($lastReply, 0, 3) !== '220') {
            fclose($conn);
            $GLOBALS['adf_mail_last_error'] = 'STARTTLS gagal: ' . trim($lastReply);
            return false;
        }
        if (!stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($conn);
            $GLOBALS['adf_mail_last_error'] = 'Gagal mengaktifkan TLS.';
            return false;
        }
        fwrite($conn, "EHLO {$hostname}\r\n");
        $lastReply = $read($conn);
        if (substr($lastReply, 0, 3) !== '250') {
            fclose($conn);
            $GLOBALS['adf_mail_last_error'] = 'EHLO (setelah TLS) gagal: ' . trim($lastReply);
            return false;
        }
    }

    fwrite($conn, "AUTH LOGIN\r\n");
    $lastReply = $read($conn);
    if (substr($lastReply, 0, 3) !== '334') {
        fclose($conn);
        $GLOBALS['adf_mail_last_error'] = 'AUTH LOGIN gagal: ' . trim($lastReply);
        return false;
    }

    fwrite($conn, base64_encode(SMTP_USER) . "\r\n");
    $lastReply = $read($conn);
    if (substr($lastReply, 0, 3) !== '334') {
        fclose($conn);
        $GLOBALS['adf_mail_last_error'] = 'Username ditolak: ' . trim($lastReply);
        return false;
    }

    fwrite($conn, base64_encode(SMTP_PASS) . "\r\n");
    $lastReply = $read($conn);
    if (substr($lastReply, 0, 3) !== '235') {
        fclose($conn);
        $GLOBALS['adf_mail_last_error'] = 'Login SMTP gagal, cek username/password: ' . trim($lastReply);
        return false;
    }

    fwrite($conn, "MAIL FROM:<{$fromEmail}>\r\n");
    $lastReply = $read($conn);
    if (substr($lastReply, 0, 3) !== '250') {
        fclose($conn);
        $GLOBALS['adf_mail_last_error'] = 'MAIL FROM ditolak: ' . trim($lastReply);
        return false;
    }

    fwrite($conn, "RCPT TO:<{$to}>\r\n");
    $lastReply = $read($conn);
    if (!in_array(substr($lastReply, 0, 3), ['250', '251'], true)) {
        fclose($conn);
        $GLOBALS['adf_mail_last_error'] = 'RCPT TO ditolak: ' . trim($lastReply);
        return false;
    }

    fwrite($conn, "DATA\r\n");
    $lastReply = $read($conn);
    if (substr($lastReply, 0, 3) !== '354') {
        fclose($conn);
        $GLOBALS['adf_mail_last_error'] = 'DATA ditolak: ' . trim($lastReply);
        return false;
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = "From: {$fromName} <{$fromEmail}>\r\n"
        . "To: <{$to}>\r\n"
        . "Subject: {$encodedSubject}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Date: " . date('r') . "\r\n";

    $bodyEscaped = str_replace("\r\n.", "\r\n..", str_replace("\n", "\r\n", $body));
    fwrite($conn, $headers . "\r\n" . $bodyEscaped . "\r\n.\r\n");
    $lastReply = $read($conn);
    if (substr($lastReply, 0, 3) !== '250') {
        fclose($conn);
        $GLOBALS['adf_mail_last_error'] = 'Pengiriman ditolak server: ' . trim($lastReply);
        return false;
    }

    fwrite($conn, "QUIT\r\n");
    fclose($conn);

    return true;
}
