<?php

/**
 * Thin wrapper around PHP's ext-imap for reading the office@narayanakarimunjawa.com
 * mailbox (Menu "Email Kantor" in the Narayana section).
 */
class EmailHelper
{
    /** @var resource|\IMAP\Connection|null */
    private $conn = null;
    private string $mailbox;

    public function __construct()
    {
        if (!extension_loaded('imap')) {
            throw new RuntimeException('Ekstensi PHP IMAP belum aktif di server ini. Hubungi developer untuk mengaktifkan ext-imap.');
        }
        if (!defined('EMAIL_IMAP_HOST') || !defined('EMAIL_IMAP_USER') || !defined('EMAIL_IMAP_PASS')) {
            throw new RuntimeException('Konfigurasi email (config/email-narayana.php) belum dibuat.');
        }
        if (EMAIL_IMAP_PASS === 'GANTI_password_email_disini' || EMAIL_IMAP_PASS === '') {
            throw new RuntimeException('Password email belum diisi di config/email-narayana.php.');
        }

        $encryption = defined('EMAIL_IMAP_ENCRYPTION') ? EMAIL_IMAP_ENCRYPTION : 'ssl';
        $port = defined('EMAIL_IMAP_PORT') ? EMAIL_IMAP_PORT : 993;
        $flag = $encryption === 'tls' ? '/imap/tls' : '/imap/ssl';

        $this->mailbox = '{' . EMAIL_IMAP_HOST . ':' . $port . $flag . '/novalidate-cert}INBOX';

        $conn = @imap_open($this->mailbox, EMAIL_IMAP_USER, EMAIL_IMAP_PASS);
        if ($conn === false) {
            throw new RuntimeException('Gagal konek ke email: ' . imap_last_error());
        }
        $this->conn = $conn;
    }

    public function __destruct()
    {
        if ($this->conn) {
            @imap_close($this->conn);
        }
    }

    /**
     * Return the newest messages first as an array of overview objects
     * (subject, from, date, seen, uid, msgno, size).
     */
    public function listMessages(int $limit = 30, int $offset = 0): array
    {
        $total = imap_num_msg($this->conn);
        if ($total <= 0) {
            return ['total' => 0, 'messages' => []];
        }

        $start = max(1, $total - $offset - $limit + 1);
        $end = $total - $offset;
        if ($end < 1) {
            return ['total' => $total, 'messages' => []];
        }

        $overview = imap_fetch_overview($this->conn, $start . ':' . $end, 0);
        $messages = [];
        foreach ($overview as $item) {
            $messages[] = [
                'uid' => (int)($item->uid ?? 0),
                'msgno' => (int)($item->msgno ?? 0),
                'subject' => isset($item->subject) ? $this->decodeMimeStr($item->subject) : '(Tanpa Subjek)',
                'from' => isset($item->from) ? $this->decodeMimeStr($item->from) : '',
                'date' => isset($item->date) ? $item->date : '',
                'seen' => !empty($item->seen),
                'size' => (int)($item->size ?? 0),
            ];
        }
        // Newest first
        usort($messages, fn($a, $b) => $b['msgno'] <=> $a['msgno']);

        return ['total' => $total, 'messages' => $messages];
    }

    public function getMessageByUid(int $uid): array
    {
        $msgno = imap_msgno($this->conn, $uid);
        if (!$msgno) {
            throw new RuntimeException('Email tidak ditemukan.');
        }

        $overview = imap_fetch_overview($this->conn, (string)$msgno, 0);
        $header = $overview[0] ?? null;

        $structure = imap_fetchstructure($this->conn, $msgno);
        $body = $this->getBody($msgno, $structure);

        // Mark as seen
        @imap_setflag_full($this->conn, (string)$msgno, '\\Seen');

        return [
            'uid' => $uid,
            'subject' => $header && isset($header->subject) ? $this->decodeMimeStr($header->subject) : '(Tanpa Subjek)',
            'from' => $header && isset($header->from) ? $this->decodeMimeStr($header->from) : '',
            'to' => $header && isset($header->to) ? $this->decodeMimeStr($header->to) : '',
            'date' => $header && isset($header->date) ? $header->date : '',
            'body_html' => $body['html'],
            'body_plain' => $body['plain'],
        ];
    }

    private function getBody($msgno, $structure): array
    {
        $html = '';
        $plain = '';

        if (!isset($structure->parts) || !is_array($structure->parts)) {
            // Single-part message
            $data = imap_body($this->conn, $msgno);
            if ((int)$structure->encoding === 3) {
                $data = base64_decode($data);
            } elseif ((int)$structure->encoding === 4) {
                $data = quoted_printable_decode($data);
            }
            if (strtoupper($structure->subtype ?? '') === 'HTML') {
                $html = $data;
            } else {
                $plain = $data;
            }
            return ['html' => $html, 'plain' => $plain];
        }

        $this->walkParts($msgno, $structure->parts, '', $html, $plain);
        return ['html' => $html, 'plain' => $plain];
    }

    private function walkParts($msgno, array $parts, string $prefix, string &$html, string &$plain): void
    {
        foreach ($parts as $index => $part) {
            $partNum = $prefix . ($index + 1);
            if (isset($part->parts) && is_array($part->parts) && (int)($part->ifsubtype ?? 0) && strtoupper($part->subtype) !== 'ALTERNATIVE') {
                $this->walkParts($msgno, $part->parts, $partNum . '.', $html, $plain);
                continue;
            }
            if (isset($part->parts) && is_array($part->parts)) {
                $this->walkParts($msgno, $part->parts, $partNum . '.', $html, $plain);
                continue;
            }

            $subtype = strtoupper($part->subtype ?? '');
            if ($subtype !== 'HTML' && $subtype !== 'PLAIN') {
                continue;
            }

            $data = imap_fetchbody($this->conn, $msgno, $partNum);
            if ((int)$part->encoding === 3) {
                $data = base64_decode($data);
            } elseif ((int)$part->encoding === 4) {
                $data = quoted_printable_decode($data);
            }

            if ($subtype === 'HTML' && $html === '') {
                $html = $data;
            } elseif ($subtype === 'PLAIN' && $plain === '') {
                $plain = $data;
            }
        }
    }

    private function decodeMimeStr(string $str): string
    {
        $decoded = @imap_mime_header_decode($str);
        if (!$decoded) {
            return $str;
        }
        $out = '';
        foreach ($decoded as $part) {
            $charset = $part->charset ?? 'default';
            $text = $part->text ?? '';
            if (strtolower($charset) !== 'default' && strtolower($charset) !== 'utf-8') {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
                $text = $converted !== false ? $converted : $text;
            }
            $out .= $text;
        }
        return $out;
    }
}
