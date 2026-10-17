<?php

/**
 * Kirim invoice ke email pelanggan (HTML rapi + tautan publik). Memakai SMTP situs (includes/smtp-mailer.php).
 * @return array{0:bool,1:string} [berhasil, pesan galat]
 */
require_once __DIR__ . '/invoices-store.php';

function adf_invoice_send_email(array $inv, array $settings): array
{
    // SMTP dimuat saat dibutuhkan (mail-config.php berisi rahasia & hanya ada di server)
    if (!is_file(__DIR__ . '/mail-config.php')) {
        return [false, 'Konfigurasi email (includes/mail-config.php) belum dibuat di server ini.'];
    }
    require_once __DIR__ . '/smtp-mailer.php';
    $to = trim((string) ($inv['customer']['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Email pelanggan kosong atau tidak valid.'];
    }
    if (($inv['status'] ?? '') === 'draft') {
        return [false, 'Invoice masih berstatus Draft. Ubah ke "Belum dibayar" dulu.'];
    }
    $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $t = adf_invoice_totals($inv);
    $url = adf_invoice_public_url($inv);
    $name = (string) ($inv['customer']['name'] ?? '');
    $company = (string) ($settings['company_name'] ?? 'ADF System');
    $subject = 'Invoice ' . $inv['number'] . ' — ' . $company;

    $banks = '';
    foreach ((array) ($settings['banks'] ?? []) as $b) {
        if (trim((string) ($b['number'] ?? '')) !== '') {
            $banks .= '<tr><td style="padding:3px 0;color:#64748b;">' . $e($b['bank'] ?? '') . '</td><td style="padding:3px 0;text-align:right;font-weight:700;">' . $e($b['number']) . ' <span style="font-weight:400;color:#64748b;">a.n. ' . $e($b['holder'] ?? '') . '</span></td></tr>';
        }
    }

    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto;color:#0b0e14;">'
        . '<div style="background:#0b0e14;padding:18px 24px;border-radius:10px 10px 0 0;border-bottom:4px solid #ff6a1a;">'
        . '<span style="color:#fff;font-size:20px;font-weight:800;">ADF</span><span style="color:#ff6a1a;font-size:20px;font-weight:800;">system</span>'
        . '<span style="float:right;color:#cbd5e1;font-size:12px;letter-spacing:.18em;margin-top:6px;">INVOICE</span></div>'
        . '<div style="border:1px solid #e6e9ef;border-top:none;padding:24px;border-radius:0 0 10px 10px;">'
        . '<p style="margin:0 0 12px;">Halo <b>' . $e($name) . '</b>,</p>'
        . '<p style="margin:0 0 16px;color:#334155;">Berikut invoice dari ' . $e($company) . '. Rincian lengkap dapat dilihat, dicetak, atau disimpan sebagai PDF melalui tombol di bawah.</p>'
        . '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;font-size:14px;">'
        . '<tr><td style="padding:5px 0;color:#64748b;">No. Invoice</td><td style="padding:5px 0;text-align:right;font-weight:700;">' . $e($inv['number']) . '</td></tr>'
        . '<tr><td style="padding:5px 0;color:#64748b;">Tanggal terbit</td><td style="padding:5px 0;text-align:right;">' . $e(adf_invoice_date($inv['issue_date'] ?? '')) . '</td></tr>'
        . '<tr><td style="padding:5px 0;color:#64748b;">Jatuh tempo</td><td style="padding:5px 0;text-align:right;">' . $e(adf_invoice_date($inv['due_date'] ?? '')) . '</td></tr>'
        . '<tr><td style="padding:10px 0 5px;color:#64748b;border-top:1px solid #e6e9ef;">Total tagihan</td><td style="padding:10px 0 5px;text-align:right;font-size:18px;font-weight:800;border-top:1px solid #e6e9ef;">' . $e(adf_invoice_rp($t['total'])) . '</td></tr>'
        . '</table>'
        . '<p style="text-align:center;margin:0 0 18px;"><a href="' . $e($url) . '" style="display:inline-block;background:#ff6a1a;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:700;">Lihat Invoice</a></p>'
        . ($banks !== '' ? '<p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#ff6a1a;">Cara pembayaran</p><table style="width:100%;border-collapse:collapse;font-size:13px;">' . $banks . '</table>' : '')
        . '<p style="margin:20px 0 0;color:#64748b;font-size:12px;">Terima kasih atas kepercayaan Anda.<br>' . $e($company) . '</p>'
        . '</div></div>';
    $text = "Halo {$name},\n\nInvoice {$inv['number']} dari {$company}\nTotal: " . adf_invoice_rp($t['total']) . "\nJatuh tempo: " . adf_invoice_date($inv['due_date'] ?? '') . "\n\nLihat invoice: {$url}\n\nTerima kasih.";

    $ok = adf_smtp_send_html($to, $subject, $html, $text);
    return $ok ? [true, ''] : [false, adf_mail_last_error() ?: 'SMTP error'];
}
