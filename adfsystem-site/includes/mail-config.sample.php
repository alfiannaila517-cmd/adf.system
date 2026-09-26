<?php
/**
 * Copy this file to mail-config.php (same folder) and fill in the real
 * mailbox credentials via cPanel File Manager. mail-config.php is gitignored
 * and must NEVER be committed.
 */

define('SMTP_HOST', 'mail.adfsystem.store'); // or 'localhost'
define('SMTP_PORT', 587); // 587 = TLS, 465 = SSL
define('SMTP_ENCRYPTION', 'tls'); // 'tls' or 'ssl'
define('SMTP_USER', 'admin@adfsystem.store');
define('SMTP_PASS', 'ISI_PASSWORD_MAILBOX_DI_SINI');
define('SMTP_FROM_EMAIL', 'admin@adfsystem.store');
define('SMTP_FROM_NAME', 'ADF System');
