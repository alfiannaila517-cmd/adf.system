<?php

/**
 * Builds a simple PDF receipt for a subscription payment, attached to the
 * "Pembayaran Diterima" email sent by api/subscription-payment-notify.php.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Spipu\Html2Pdf\Html2Pdf;

function adf_subscription_invoice_pdf(
    string $clientName,
    string $period,
    float $totalAmount,
    string $paidAtDisplay,
    string $invoiceNumber
): string {
    $formattedAmount = 'Rp ' . number_format($totalAmount, 0, ',', '.');

    $html = '<html><head><style>
        body { font-family: sans-serif; font-size: 13px; color: #1e293b; }
        .header { text-align: center; margin-bottom: 18px; }
        .header h1 { font-size: 18px; margin: 0; color: #0f172a; }
        .header p { margin: 2px 0; color: #64748b; font-size: 11px; }
        .badge { display: inline-block; background: #dcfce7; color: #166534; padding: 4px 12px;
                 border-radius: 4px; font-size: 12px; font-weight: bold; margin-bottom: 14px; }
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.info td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
        table.info td:first-child { color: #64748b; width: 40%; }
        table.total { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.total td { padding: 10px 8px; font-size: 15px; font-weight: bold; background: #f1f5f9; }
        .footer { margin-top: 24px; font-size: 10px; color: #94a3b8; text-align: center; }
        </style></head><body>
        <div class="header">
            <h1>' . htmlspecialchars(SITE_NAME) . '</h1>
            <p>' . htmlspecialchars(SITE_TAGLINE) . '</p>
        </div>
        <div class="badge">&#10003; PEMBAYARAN DITERIMA</div>
        <table class="info">
            <tr><td>No. Invoice</td><td>' . htmlspecialchars($invoiceNumber) . '</td></tr>
            <tr><td>Pelanggan</td><td>' . htmlspecialchars($clientName) . '</td></tr>
            <tr><td>Periode Langganan</td><td>' . htmlspecialchars($period) . '</td></tr>
            <tr><td>Waktu Pembayaran</td><td>' . htmlspecialchars($paidAtDisplay) . '</td></tr>
            <tr><td>Metode Pembayaran</td><td>Pakasir Payment Gateway</td></tr>
        </table>
        <table class="total">
            <tr><td>Total Dibayar</td><td style="text-align:right;">' . htmlspecialchars($formattedAmount) . '</td></tr>
        </table>
        <div class="footer">
            Invoice ini dibuat otomatis oleh sistem dan sah tanpa tanda tangan basah.<br>
            ' . htmlspecialchars(COMPANY_LEGAL_NAME) . ' &mdash; ' . htmlspecialchars(CONTACT_EMAIL) . '
        </div>
        </body></html>';

    $html2pdf = new Html2Pdf('P', 'A5', 'en', true, 'UTF-8');
    $html2pdf->writeHTML($html);

    return $html2pdf->output('invoice.pdf', 'S');
}
