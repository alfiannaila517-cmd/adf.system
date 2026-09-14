<?php
/**
 * Shared helpers for platform subscription billing (Tripay).
 * Requires: config/database.php, includes/TripayClient.php already loaded, and
 * config/tripay.php (real credentials) present - falls back gracefully with an
 * error message if the gateway isn't configured yet.
 */

function subscriptionTripayConfigured()
{
    $tripayConfigFile = dirname(__DIR__) . '/config/tripay.php';
    if (!file_exists($tripayConfigFile)) return false;
    require_once $tripayConfigFile;
    return defined('TRIPAY_API_KEY') && TRIPAY_API_KEY !== 'YOUR_API_KEY';
}

/**
 * Create (or return existing unpaid) invoice for a business's current billing period,
 * calling Tripay to obtain a payment_url. Returns ['success'=>bool,'message'=>string,'invoice'=>array|null].
 */
function subscriptionCreateInvoice(PDO $masterDb, $businessId, $businessName)
{
    if (!subscriptionTripayConfigured()) {
        return ['success' => false, 'message' => 'Tripay belum dikonfigurasi. Salin config/tripay.example.php ke config/tripay.php dan isi API key.', 'invoice' => null];
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

    $invoiceNo = 'SUB-' . date('Ymd', strtotime($periodStart)) . '-' . $businessId . '-' . substr(uniqid(), -5);

    $tripay = new TripayClient();
    $result = $tripay->createTransaction([
        'method' => defined('TRIPAY_DEFAULT_CHANNEL') ? TRIPAY_DEFAULT_CHANNEL : 'QRIS',
        'merchant_ref' => $invoiceNo,
        'amount' => (int)round($amount),
        'customer_name' => $businessName,
        'customer_email' => 'billing@adfsystem.online',
        'order_items' => [[
            'sku' => 'SUBSCRIPTION',
            'name' => 'Langganan ' . ($sub['plan_name'] ?: 'ADF System') . ' - ' . $businessName,
            'price' => (int)round($amount),
            'quantity' => 1,
        ]],
        'expired_time' => strtotime('+3 days'),
    ]);

    if (empty($result['success'])) {
        return ['success' => false, 'message' => 'Gagal membuat transaksi Tripay: ' . ($result['message'] ?? 'unknown error'), 'invoice' => null];
    }

    $data = $result['data'];
    $insertStmt = $masterDb->prepare("INSERT INTO subscription_invoices
        (business_id, subscription_id, invoice_no, period_start, period_end, amount, status, gateway, gateway_reference, payment_method, payment_url, due_date, raw_response)
        VALUES (?, ?, ?, ?, ?, ?, 'unpaid', 'tripay', ?, ?, ?, ?, ?)");
    $insertStmt->execute([
        $businessId,
        $sub['id'],
        $invoiceNo,
        $periodStart,
        $periodEnd,
        $amount,
        $data['reference'] ?? null,
        $data['payment_method'] ?? null,
        $data['checkout_url'] ?? ($data['pay_url'] ?? null),
        date('Y-m-d', $data['expired_time'] ?? strtotime('+3 days')),
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
