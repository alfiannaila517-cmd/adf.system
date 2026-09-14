<?php

/**
 * Mayar.id payment gateway credentials (platform subscription billing).
 * Copy this file to config/mayar.php on each server and fill in real values.
 * config/mayar.php is gitignored - never commit real API keys.
 *
 * Get your API key at https://web.mayar.id/api-keys (production) or
 * https://web.mayar.io/api-keys (sandbox - register/login there first to test).
 * Mayar accepts individual (KTP only, no NPWP/PT required) merchants.
 */

if (!defined('MAYAR_MODE')) define('MAYAR_MODE', 'sandbox'); // 'sandbox' or 'production'
if (!defined('MAYAR_API_KEY')) define('MAYAR_API_KEY', 'YOUR_API_KEY');

// Secret token to protect cron-generate-subscription-invoices.php (HTTP-triggered by cPanel cron).
if (!defined('SUBSCRIPTION_CRON_TOKEN')) define('SUBSCRIPTION_CRON_TOKEN', 'CHANGE_ME_TO_A_RANDOM_SECRET');

// Set the webhook URL manually in Mayar dashboard (Integration -> Webhook) to:
//   https://adfsystem.online/api/mayar-callback.php
// Mayar's webhook payload isn't signed, so the callback re-verifies the real
// status by calling Mayar's own API before marking anything paid - no secret needed here.
