<?php

/**
 * cron-generate-subscription-invoices.php
 * ------------------------------------------------------------------
 * Runs daily. For every active/trial/past_due business subscription whose
 * next_billing_date is within the next 7 days (or already overdue), creates
 * a Tripay invoice (if one doesn't already exist for that period) and, if
 * the previous invoice is overdue past its grace period, suspends the
 * business (businesses.is_active = 0) until payment comes in.
 *
 * SETUP:
 *   1. Copy config/tripay.example.php -> config/tripay.php and fill in your
 *      real Tripay merchant code / API key / private key + a random
 *      SUBSCRIPTION_CRON_TOKEN.
 *   2. Trigger this script once a day via a cPanel Cron Job:
 *      /usr/bin/curl -s "https://adfsystem.online/cron-generate-subscription-invoices.php?token=YOUR_TOKEN" > /home/adfb2574/subscription_cron_log.txt 2>&1
 *
 * You can also run it manually to test:
 *   https://adfsystem.online/cron-generate-subscription-invoices.php?token=YOUR_TOKEN
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';

$tripayConfigFile = __DIR__ . '/config/tripay.php';
if (!file_exists($tripayConfigFile)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    exit("Missing config/tripay.php - copy config/tripay.example.php and fill in your Tripay credentials first.\n");
}
require_once $tripayConfigFile;

$providedToken = $_GET['token'] ?? '';
if (!defined('SUBSCRIPTION_CRON_TOKEN') || !$providedToken || !hash_equals(SUBSCRIPTION_CRON_TOKEN, $providedToken)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit("Forbidden\n");
}

require_once __DIR__ . '/includes/TripayClient.php';
require_once __DIR__ . '/includes/subscription_billing.php';

header('Content-Type: text/plain');

$masterDb = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$dueSoon = $masterDb->prepare("SELECT bs.*, b.business_name
    FROM business_subscriptions bs
    JOIN businesses b ON b.id = bs.business_id
    WHERE bs.status IN ('trial','active','past_due')
      AND bs.next_billing_date IS NOT NULL
      AND bs.next_billing_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
$dueSoon->execute();
$rows = $dueSoon->fetchAll();

echo "Found " . count($rows) . " subscription(s) due within 7 days.\n";

foreach ($rows as $row) {
    echo "- {$row['business_name']} (business_id={$row['business_id']}): ";
    $result = subscriptionCreateInvoice($masterDb, $row['business_id'], $row['business_name']);
    echo ($result['success'] ? 'OK' : 'FAILED') . ' - ' . $result['message'] . "\n";

    // Suspend if overdue past grace period and still unpaid.
    $graceDays = (int)($row['grace_days'] ?: 3);
    $overdueDate = date('Y-m-d', strtotime($row['next_billing_date'] . " +{$graceDays} days"));
    if (date('Y-m-d') > $overdueDate) {
        $masterDb->prepare("UPDATE business_subscriptions SET status = 'suspended' WHERE id = ?")->execute([$row['id']]);
        $masterDb->prepare("UPDATE businesses SET is_active = 0 WHERE id = ?")->execute([$row['business_id']]);
        echo "  -> OVERDUE past grace period, business suspended.\n";
    }
}

echo "Done.\n";
