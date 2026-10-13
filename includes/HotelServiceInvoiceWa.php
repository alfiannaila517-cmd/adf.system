<?php

/**
 * Kirim invoice Hotel Service + pengingat tagihan ke tamu lewat WhatsApp (sekali klik dari menu Aksi).
 * Tautan invoice publik memakai token HMAC (includes/InvoiceShare.php: hsInvoiceShareUrl), tanpa login.
 */
if (!defined('APP_ACCESS')) { http_response_code(403); exit; }

/** Bahasa pesan: nomor luar negeri → en; nomor Indonesia → id (kecuali kebangsaan jelas asing). */
function hsInvWaLang(string $nationality, string $phone): string
{
    // Nomor luar negeri → selalu Inggris (kebangsaan di data tamu sering masih bawaan 'Indonesia').
    // Nomor Indonesia → Indonesia, kecuali kebangsaan jelas asing. Tanpa nomor → ikut kebangsaan.
    $t = WhatsAppHelper::normalizeTarget($phone);
    $n = strtolower(trim($nationality));
    $indoNat = $n === '' || strpos($n, 'indonesia') !== false || $n === 'id' || $n === 'wni';
    if ($t !== '') {
        if (strpos($t, '62') !== 0) return 'en';
        return $indoNat ? 'id' : 'en';
    }
    return ($n !== '' && !$indoNat) ? 'en' : 'id';
}

/** Isi pesan: invoice terbaru + pengingat sopan bila masih ada sisa tagihan. */
function hsInvWaMessage(string $guestName, string $invoiceNo, float $total, float $balance, string $link, string $lang, string $hotel, int $hour): string
{
    $rp = fn(float $v) => 'Rp ' . number_format($v, 0, ',', '.');
    $name = trim($guestName);
    if ($lang === 'id') {
        $greet = $hour < 11 ? 'Selamat pagi' : ($hour < 15 ? 'Selamat siang' : ($hour < 18 ? 'Selamat sore' : 'Selamat malam'));
        $lines = [$greet . ($name !== '' ? ', ' . $name : '') . '.', ''];
        if ($balance > 0) {
            $lines[] = 'Berikut invoice terbaru layanan hotel Anda (' . $invoiceNo . '). Total ' . $rp($total) . ', sisa tagihan ' . $rp($balance) . ':';
            $lines[] = $link;
            $lines[] = '';
            $lines[] = 'Pembayaran dapat dilakukan langsung di Front Office kapan saja, atau sebelum check-out. Terima kasih.';
        } else {
            $lines[] = 'Berikut invoice layanan hotel Anda (' . $invoiceNo . '), seluruhnya telah lunas:';
            $lines[] = $link;
            $lines[] = '';
            $lines[] = 'Terima kasih atas kepercayaan Anda.';
        }
        $lines[] = $hotel;
        return implode("\n", $lines);
    }
    $greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $lines = [$greet . ($name !== '' ? ', ' . $name : '') . '.', ''];
    if ($balance > 0) {
        $lines[] = 'Here is the latest invoice for your hotel services (' . $invoiceNo . '). Total ' . $rp($total) . ', outstanding balance ' . $rp($balance) . ':';
        $lines[] = $link;
        $lines[] = '';
        $lines[] = 'You are welcome to settle it at the Front Office at any time, or before check-out. Thank you.';
    } else {
        $lines[] = 'Here is the invoice for your hotel services (' . $invoiceNo . '), fully paid:';
        $lines[] = $link;
        $lines[] = '';
        $lines[] = 'Thank you for choosing us.';
    }
    $lines[] = $hotel;
    return implode("\n", $lines);
}
