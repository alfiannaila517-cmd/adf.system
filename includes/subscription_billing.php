<?php

/**
 * Shared helpers for platform subscription billing (Mayar.id).
 * Requires: config/database.php, includes/MayarClient.php already loaded, and
 * config/mayar.php (real credentials) present - falls back gracefully with an
 * error message if the gateway isn't configured yet.
 */

function subscriptionGatewayConfigured()
{
    $mayarConfigFile = dirname(__DIR__) . '/config/mayar.php';
    if (!file_exists($mayarConfigFile)) return false;
    require_once $mayarConfigFile;
    return defined('MAYAR_API_KEY') && MAYAR_API_KEY !== 'YOUR_API_KEY';
}

/**
 * Create (or return existing unpaid) invoice for a business's current billing period,
 * calling Mayar to obtain a payment link. Returns ['success'=>bool,'message'=>string,'invoice'=>array|null].
 */
function subscriptionCreateInvoice(PDO $masterDb, $businessId, $businessName)
{
    if (!subscriptionGatewayConfigured()) {
        return ['success' => false, 'message' => 'Mayar belum dikonfigurasi. Salin config/mayar.example.php ke config/mayar.php dan isi API key.', 'invoice' => null];
    }

    $subStmt = $masterDb->prepare("SELECT bs.*, sp.plan_name, sp.price, sp.billing_cycle_months
        FROM business_subscriptions bs LEFT JOIN subscription_plans sp ON sp.id = bs.plan_id
        WHERE bs.business_id = ? LIMIT 1");
    $subStmt->execute([$businessId]);
    $sub = $subStmt->fetch(PDO::FETCH_ASSOC);
    if (!$sub) {
        return ['success' => false, 'message' => 'Business ini belum punya subscription/plan. Atur dulu di panel Platform Billing.', 'invoice' => null];
    }

    // Reuse an existing unpaid invoice for the same period instead of double-charging.
    $periodStart = $sub['next_billing_date'] ?: date('Y-m-d');
    $cycleMonths = max(1, (int)($sub['billing_cycle_months'] ?: 1));
    $periodEnd = date('Y-m-d', strtotime("+{$cycleMonths} months -1 day", strtotime($periodStart)));

    $existingStmt = $masterDb->prepare("SELECT * FROM subscription_invoices
        WHERE business_id = ? AND period_start = ? AND status = 'unpaid' LIMIT 1");
    $existingStmt->execute([$businessId, $periodStart]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        return ['success' => true, 'message' => 'Invoice periode ini sudah ada.', 'invoice' => $existing];
    }

    $amount = (float)($sub['price'] ?: 0);
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Plan untuk business ini belum punya harga.', 'invoice' => null];
    }

    // Contact info comes from the business owner's user account (businesses has no email/phone of its own).
    $contactStmt = $masterDb->prepare("SELECT u.email, u.phone FROM businesses b JOIN users u ON u.id = b.owner_id WHERE b.id = ?");
    $contactStmt->execute([$businessId]);
    $contact = $contactStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $email = $contact['email'] ?: 'billing+business' . $businessId . '@adfsystem.online';
    $mobile = $contact['phone'] ?: '081234567890';

    $invoiceNo = 'SUB-' . date('Ymd', strtotime($periodStart)) . '-' . $businessId . '-' . substr(uniqid(), -5);

    $mayar = new MayarClient();
    $result = $mayar->createInvoice([
        'name' => $businessName,
        'email' => $email,
        'mobile' => $mobile,
        'description' => 'Langganan ' . ($sub['plan_name'] ?: 'ADF System') . ' - ' . $businessName,
        'expiredAt' => gmdate('Y-m-d\TH:i:s.000\Z', strtotime('+3 days')),
        'items' => [[
            'quantity' => 1,
            'rate' => (int)round($amount),
            'description' => 'Langganan ' . ($sub['plan_name'] ?: 'ADF System') . ' - ' . $businessName,
        ]],
        'extraData' => ['invoiceNo' => $invoiceNo, 'businessId' => $businessId],
    ]);

    if ((int)($result['statusCode'] ?? 0) !== 200 || empty($result['data']['id'])) {
        return ['success' => false, 'message' => 'Gagal membuat invoice Mayar: ' . ($result['messages'] ?? 'unknown error'), 'invoice' => null];
    }

    $data = $result['data'];
    $insertStmt = $masterDb->prepare("INSERT INTO subscription_invoices
        (business_id, subscription_id, invoice_no, period_start, period_end, amount, status, gateway, gateway_reference, payment_url, due_date, raw_response)
        VALUES (?, ?, ?, ?, ?, ?, 'unpaid', 'mayar', ?, ?, ?, ?)");
    $insertStmt->execute([
        $businessId,
        $sub['id'],
        $invoiceNo,
        $periodStart,
        $periodEnd,
        $amount,
        $data['id'],
        $data['link'] ?? null,
        date('Y-m-d', (int)round((float)($data['expiredAt'] ?? (strtotime('+3 days') * 1000)) / 1000)),
        json_encode($result),
    ]);

    $newId = $masterDb->lastInsertId();
    $fetchStmt = $masterDb->prepare("SELECT * FROM subscription_invoices WHERE id = ?");
    $fetchStmt->execute([$newId]);

    return ['success' => true, 'message' => 'Invoice berhasil dibuat.', 'invoice' => $fetchStmt->fetch(PDO::FETCH_ASSOC)];
}

/**
 * Mark an invoice paid, extend the subscription's next_billing_date, and reactivate the business.
 * Idempotent: safe to call multiple times for the same invoice.
 */
function subscriptionMarkInvoicePaid(PDO $masterDb, $invoiceId)
{
    $invStmt = $masterDb->prepare("SELECT * FROM subscription_invoices WHERE id = ? LIMIT 1");
    $invStmt->execute([$invoiceId]);
    $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice || $invoice['status'] === 'paid') return;

    $masterDb->prepare("UPDATE subscription_invoices SET status = 'paid', paid_at = NOW() WHERE id = ?")
        ->execute([$invoiceId]);

    $masterDb->prepare("UPDATE business_subscriptions
        SET status = 'active', next_billing_date = ?
        WHERE business_id = ?")
        ->execute([$invoice['period_end'] ? date('Y-m-d', strtotime($invoice['period_end'] . ' +1 day')) : null, $invoice['business_id']]);

    $masterDb->prepare("UPDATE businesses SET is_active = 1 WHERE id = ?")->execute([$invoice['business_id']]);
}

/**
 * Re-verify an invoice's real status directly against Mayar's API (authenticated GET),
 * instead of trusting the unauthenticated/unsigned webhook payload. Used by the callback.
 */
function subscriptionVerifyAndSyncInvoice(PDO $masterDb, $gatewayReference)
{
    if (!subscriptionGatewayConfigured()) return false;

    $stmt = $masterDb->prepare("SELECT * FROM subscription_invoices WHERE gateway_reference = ? LIMIT 1");
    $stmt->execute([$gatewayReference]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice || $invoice['status'] === 'paid') return false;

    $mayar = new MayarClient();
    $result = $mayar->getInvoiceDetail($gatewayReference);
    $status = strtolower($result['data']['status'] ?? '');

    if ($status === 'paid') {
        subscriptionMarkInvoicePaid($masterDb, $invoice['id']);
        return true;
    }
    return false;
}
