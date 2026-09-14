<?php
/**
 * Tripay payment gateway credentials.
 * Copy this file to config/tripay.php on each server and fill in real values.
 * config/tripay.php is gitignored - never commit real API keys.
 *
 * Get these from https://tripay.co.id/member/merchant (sandbox: https://tripay.co.id/simulator/merchant)
 */

if (!defined('TRIPAY_MODE')) define('TRIPAY_MODE', 'sandbox'); // 'sandbox' or 'production'
if (!defined('TRIPAY_MERCHANT_CODE')) define('TRIPAY_MERCHANT_CODE', 'T0000');
if (!defined('TRIPAY_API_KEY')) define('TRIPAY_API_KEY', 'YOUR_API_KEY');
if (!defined('TRIPAY_PRIVATE_KEY')) define('TRIPAY_PRIVATE_KEY', 'YOUR_PRIVATE_KEY');

// Default payment channel used when generating subscription invoices (e.g. QRIS, BRIVA, MYBVA).
// Full channel list: GET /merchant/payment-channel on Tripay API.
if (!defined('TRIPAY_DEFAULT_CHANNEL')) define('TRIPAY_DEFAULT_CHANNEL', 'QRIS');

// Public callback URL Tripay will POST payment status updates to.
if (!defined('TRIPAY_CALLBACK_URL')) define('TRIPAY_CALLBACK_URL', 'https://adfsystem.online/api/tripay-callback.php');

// Secret token to protect cron-generate-subscription-invoices.php (HTTP-triggered by cPanel cron).
if (!defined('SUBSCRIPTION_CRON_TOKEN')) define('SUBSCRIPTION_CRON_TOKEN', 'CHANGE_ME_TO_A_RANDOM_SECRET');
