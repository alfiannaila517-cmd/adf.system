<?php
/**
 * Tautan invoice yang bisa dibuka tamu tanpa login (dikirim lewat WhatsApp).
 * Token = HMAC dari ID booking dengan rahasia acak yang disimpan di settings → tidak bisa ditebak / diubah ke booking lain.
 */
if (!defined('APP_ACCESS')) { http_response_code(403); exit; }

function invoiceShareSecret($db): string
{
    $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'invoice_share_secret' LIMIT 1");
    $v = trim((string)($row['setting_value'] ?? ''));
    if ($v === '') {
        $v = bin2hex(random_bytes(16));
        $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('invoice_share_secret', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$v]);
    }
    return $v;
}

function invoiceShareToken($db, int $bookingId): string
{
    return substr(hash_hmac('sha256', 'invoice|' . $bookingId, invoiceShareSecret($db)), 0, 24);
}

function invoiceShareValid($db, int $bookingId, string $token): bool
{
    if ($bookingId <= 0 || strlen($token) < 20) return false;
    try {
        return hash_equals(invoiceShareToken($db, $bookingId), $token);
    } catch (\Throwable $e) {
        return false;
    }
}

function invoiceShareUrl($db, int $bookingId): string
{
    return rtrim(BASE_URL, '/') . '/modules/frontdesk/invoice.php?booking_id=' . $bookingId
        . '&b=' . rawurlencode((string)ACTIVE_BUSINESS_ID) . '&t=' . invoiceShareToken($db, $bookingId);
}
