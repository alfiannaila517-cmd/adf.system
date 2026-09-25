<?php
/**
 * Shared site config: brand info, nav links, contact details.
 * Edit here once — all pages pull from these constants.
 */

define('SITE_NAME', 'ADF System');
define('SITE_TAGLINE', 'Platform Manajemen Bisnis All-in-One');
define('COMPANY_LEGAL_NAME', 'ADF System Developer');
define('COMPANY_DESC_SHORT', 'Web & IT Consultant — Custom System Developer');
define('CONTACT_EMAIL', 'office@adfsystem.id');
define('CONTACT_WHATSAPP', ''); // TODO: isi nomor WhatsApp untuk ditampilkan di halaman Kontak
define('CONTACT_ADDRESS', 'Indonesia'); // TODO: lengkapi alamat kota/provinsi untuk kebutuhan verifikasi payment gateway
define('SITE_YEAR', date('Y'));

function nav_link(string $href, string $label, string $current): string
{
    $active = ($current === $href) ? ' active' : '';
    return '<a href="' . htmlspecialchars($href) . '" class="' . $active . '">' . htmlspecialchars($label) . '</a>';
}
