<?php

/**
 * Mailbox credentials - TEMPLATE
 * ------------------------------------------------------------------
 * Powers the "Email Kantor" menu (modules/email/) that reads the
 * office@narayanakarimunjawa.com inbox via IMAP.
 *
 * SETUP:
 *   1. Copy this file to config/email-narayana.php (same folder).
 *      This copy is gitignored - it will NOT be committed to git.
 *   2. Fill in EMAIL_IMAP_PASS below with the real mailbox password
 *      (the same password used to log into cPanel webmail).
 *   3. On the live server (cPanel), create config/email-narayana.php
 *      directly via File Manager with the real password - do not
 *      push it through git.
 */

define('EMAIL_IMAP_HOST', 'mail.narayanakarimunjawa.com');
define('EMAIL_IMAP_PORT', 993);
define('EMAIL_IMAP_ENCRYPTION', 'ssl'); // 'ssl' (port 993) or 'tls' (port 143)
define('EMAIL_IMAP_USER', 'office@narayanakarimunjawa.com');
define('EMAIL_IMAP_PASS', 'GANTI_password_email_disini');
