<?php

/**
 * PLATFORM SUBSCRIPTION BILLING PANEL
 * Developer-only: manage subscription plans, assign a plan to each business,
 * and generate/track Mayar invoices for the monthly SaaS licensing fee.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/MayarClient.php';
require_once __DIR__ . '/../../includes/subscription_billing.php';

$auth = new Auth();
$auth->requireLogin();

if (($_SESSION['role'] ?? '') !== 'developer') {
    http_response_code(403);
    exit('Forbidden - halaman ini khusus Developer/pemilik platform.');
}

$masterDb = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_plan') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $name = trim($_POST['plan_name'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $cycle = max(1, (int)($_POST['billing_cycle_months'] ?? 1));
        $desc = trim($_POST['description'] ?? '');

        if ($name === '') {
            $flash = ['type' => 'error', 'text' => 'Nama plan wajib diisi.'];
        } elseif ($planId > 0) {
            $masterDb->prepare("UPDATE subscription_plans SET plan_name=?, price=?, billing_cycle_months=?, description=? WHERE id=?")
                ->execute([$name, $price, $cycle, $desc, $planId]);
            $flash = ['type' => 'ok', 'text' => 'Plan berhasil diupdate.'];
        } else {
            $masterDb->prepare("INSERT INTO subscription_plans (plan_name, price, billing_cycle_months, description) VALUES (?,?,?,?)")
                ->execute([$name, $price, $cycle, $desc]);
            $flash = ['type' => 'ok', 'text' => 'Plan baru berhasil dibuat.'];
        }
    } elseif ($action === 'assign_subscription') {
        $businessId = (int)($_POST['business_id'] ?? 0);
        $planId = (int)($_POST['plan_id'] ?? 0) ?: null;
        $status = $_POST['status'] ?? 'trial';
        $isFree = !empty($_POST['is_free']);
        // NULL next_billing_date means the cron job skips this business entirely -
        // no invoice ever generated, never auto-suspended. This is what "kasih free" means.
        $nextBillingDate = $isFree ? null : ($_POST['next_billing_date'] ?? date('Y-m-d'));
        $graceDays = (int)($_POST['grace_days'] ?? 3);

        $masterDb->prepare("INSERT INTO business_subscriptions (business_id, plan_id, status, started_at, next_billing_date, grace_days)
            VALUES (?,?,?,CURDATE(),?,?)
            ON DUPLICATE KEY UPDATE plan_id=VALUES(plan_id), status=VALUES(status), next_billing_date=VALUES(next_billing_date), grace_days=VALUES(grace_days)")
            ->execute([$businessId, $planId, $status, $nextBillingDate, $graceDays]);
        $masterDb->prepare("UPDATE businesses SET is_active = 1 WHERE id = ?")->execute([$businessId]);
        $flash = ['type' => 'ok', 'text' => $isFree ? 'Business ini digratiskan (tidak akan pernah ditagih/disuspend otomatis).' : 'Subscription business berhasil disimpan.'];
    } elseif ($action === 'generate_invoice') {
        $businessId = (int)($_POST['business_id'] ?? 0);
        $bizStmt = $masterDb->prepare("SELECT business_name FROM businesses WHERE id=?");
        $bizStmt->execute([$businessId]);
        $bizName = $bizStmt->fetchColumn() ?: ('Business #' . $businessId);
        $result = subscriptionCreateInvoice($masterDb, $businessId, $bizName);
        $flash = ['type' => $result['success'] ? 'ok' : 'error', 'text' => $result['message']];
    } elseif ($action === 'mark_paid_manual') {
        // Fallback for out-of-band payments (transfer, cash) not through Mayar.
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        subscriptionMarkInvoicePaid($masterDb, $invoiceId);
        $flash = ['type' => 'ok', 'text' => 'Invoice ditandai lunas manual.'];
    }
}

$businesses = $masterDb->query("SELECT b.*, bs.id AS sub_id, bs.plan_id, bs.status AS sub_status,
        bs.next_billing_date, bs.grace_days, sp.plan_name, sp.price
    FROM businesses b
    LEFT JOIN business_subscriptions bs ON bs.business_id = b.id
    LEFT JOIN subscription_plans sp ON sp.id = bs.plan_id
    ORDER BY b.business_name")->fetchAll();

$plans = $masterDb->query("SELECT * FROM subscription_plans ORDER BY price ASC")->fetchAll();

$invoices = $masterDb->query("SELECT si.*, b.business_name
    FROM subscription_invoices si
    JOIN businesses b ON b.id = si.business_id
    ORDER BY si.created_at DESC LIMIT 100")->fetchAll();

function subFmtRp($v)
{
    return 'Rp ' . number_format((float)$v, 0, ',', '.');
}

$statusBadge = [
    'trial' => '#0891b2',
    'active' => '#16a34a',
    'past_due' => '#d97706',
    'suspended' => '#dc2626',
    'cancelled' => '#64748b',
    'unpaid' => '#d97706',
    'paid' => '#16a34a',
    'expired' => '#64748b',
    'failed' => '#dc2626',
];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Platform Billing - ADF System</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f1f5f9;
            margin: 0;
            padding: 24px;
            color: #1e293b;
        }

        h1 {
            font-size: 1.4rem;
            margin-bottom: 4px;
        }

        h2 {
            font-size: 1.1rem;
            margin: 28px 0 10px;
        }

        .card {
            background: #fff;
            border-radius: 10px;
            padding: 18px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .88rem;
        }

        th,
        td {
            text-align: left;
            padding: 8px 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: #f8fafc;
        }

        .badge {
            display: inline-block;
            padding: 2px 9px;
            border-radius: 999px;
            color: #fff;
            font-size: .75rem;
            font-weight: 600;
        }

        form.inline {
            display: inline;
        }

        select,
        input[type=text],
        input[type=number],
        input[type=date] {
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: .85rem;
        }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: .82rem;
            font-weight: 600;
        }

        .btn-primary {
            background: #2563eb;
            color: #fff;
        }

        .btn-outline {
            background: #fff;
            border: 1px solid #cbd5e1;
        }

        .flash {
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: .88rem;
        }

        .flash-ok {
            background: #dcfce7;
            color: #166534;
        }

        .flash-error {
            background: #fee2e2;
            color: #991b1b;
        }

        a.pay-link {
            color: #2563eb;
        }

        .grid-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px;
            align-items: end;
        }

        .grid-form label {
            display: block;
            font-size: .75rem;
            color: #64748b;
            margin-bottom: 3px;
        }
    </style>
</head>

<body>
    <h1>💳 Platform Subscription Billing</h1>
    <p style="color:#64748b;">Kelola langganan bulanan tiap business ke platform ADF System (via Mayar).</p>

    <?php if ($flash): ?>
        <div class="flash flash-<?= $flash['type'] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash['text']) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2 style="margin-top:0;">➕ Tambah / Edit Plan</h2>
        <form method="POST" class="grid-form">
            <input type="hidden" name="action" value="save_plan">
            <input type="hidden" name="plan_id" value="0">
            <div><label>Nama Plan</label><input type="text" name="plan_name" required placeholder="Basic / Pro"></div>
            <div><label>Harga (Rp)</label><input type="number" name="price" min="0" step="1000" required></div>
            <div><label>Siklus (bulan)</label><input type="number" name="billing_cycle_months" min="1" value="1" required></div>
            <div><label>Deskripsi</label><input type="text" name="description" placeholder="opsional"></div>
            <div><button class="btn btn-primary" type="submit">Simpan Plan</button></div>
        </form>
    </div>

    <div class="card">
        <h2 style="margin-top:0;">📋 Daftar Plan</h2>
        <table>
            <tr>
                <th>Nama</th>
                <th>Harga</th>
                <th>Siklus</th>
                <th>Deskripsi</th>
            </tr>
            <?php foreach ($plans as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['plan_name']) ?></td>
                    <td><?= subFmtRp($p['price']) ?></td>
                    <td><?= (int)$p['billing_cycle_months'] ?> bulan</td>
                    <td><?= htmlspecialchars($p['description']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($plans)): ?>
                <tr>
                    <td colspan="4" style="color:#94a3b8;">Belum ada plan. Buat dulu di atas.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="card">
        <h2 style="margin-top:0;">🏢 Business & Subscription</h2>
        <table>
            <tr>
                <th>Business</th>
                <th>Plan</th>
                <th>Status</th>
                <th>Jatuh Tempo Berikut</th>
                <th>Aktif?</th>
                <th>Aksi</th>
            </tr>
            <?php foreach ($businesses as $b): ?>
                <tr>
                    <td><?= htmlspecialchars($b['business_name']) ?></td>
                    <td><?= $b['plan_name'] ? htmlspecialchars($b['plan_name']) . ' (' . subFmtRp($b['price']) . ')' : '-' ?></td>
                    <td>
                        <?php if ($b['sub_status']): ?>
                            <span class="badge" style="background:<?= $statusBadge[$b['sub_status']] ?? '#64748b' ?>"><?= htmlspecialchars($b['sub_status']) ?></span>
                        <?php else: ?>
                            <span style="color:#94a3b8;">belum diatur</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $b['next_billing_date'] ? date('d M Y', strtotime($b['next_billing_date'])) : ($b['sub_id'] ? '🎁 Gratis Selamanya' : '-') ?></td>
                    <td><?= $b['is_active'] ? '✅' : '⛔' ?></td>
                    <td>
                        <details>
                            <summary style="cursor:pointer;color:#2563eb;">Atur</summary>
                            <form method="POST" style="margin-top:8px;" class="grid-form">
                                <input type="hidden" name="action" value="assign_subscription">
                                <input type="hidden" name="business_id" value="<?= (int)$b['id'] ?>">
                                <div>
                                    <label>Plan</label>
                                    <select name="plan_id">
                                        <option value="">- pilih -</option>
                                        <?php foreach ($plans as $p): ?>
                                            <option value="<?= (int)$p['id'] ?>" <?= $b['plan_id'] == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['plan_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label>Status</label>
                                    <select name="status">
                                        <?php foreach (['trial', 'active', 'past_due', 'suspended', 'cancelled'] as $st): ?>
                                            <option value="<?= $st ?>" <?= $b['sub_status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label>Jatuh Tempo Berikut</label>
                                    <input type="date" name="next_billing_date" value="<?= htmlspecialchars($b['next_billing_date'] ?: date('Y-m-d')) ?>">
                                </div>
                                <div>
                                    <label>Grace (hari)</label>
                                    <input type="number" name="grace_days" value="<?= (int)($b['grace_days'] ?: 3) ?>" min="0">
                                </div>
                                <div>
                                    <label>&nbsp;</label>
                                    <label style="font-weight:400;"><input type="checkbox" name="is_free" value="1" <?= !$b['next_billing_date'] ? 'checked' : '' ?>> 🎁 Gratis Selamanya (tidak pernah ditagih/disuspend)</label>
                                </div>
                                <div><button class="btn btn-primary" type="submit">Simpan</button></div>
                            </form>
                        </details>
                        <form method="POST" class="inline" style="margin-top:6px;">
                            <input type="hidden" name="action" value="generate_invoice">
                            <input type="hidden" name="business_id" value="<?= (int)$b['id'] ?>">
                            <button class="btn btn-outline" type="submit">🧾 Generate Invoice Sekarang</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <h2 style="margin-top:0;">🧾 Riwayat Invoice</h2>
        <table>
            <tr>
                <th>No Invoice</th>
                <th>Business</th>
                <th>Periode</th>
                <th>Jumlah</th>
                <th>Status</th>
                <th>Link Bayar</th>
                <th>Aksi</th>
            </tr>
            <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td><?= htmlspecialchars($inv['invoice_no']) ?></td>
                    <td><?= htmlspecialchars($inv['business_name']) ?></td>
                    <td><?= date('d/m/Y', strtotime($inv['period_start'])) ?> - <?= date('d/m/Y', strtotime($inv['period_end'])) ?></td>
                    <td><?= subFmtRp($inv['amount']) ?></td>
                    <td><span class="badge" style="background:<?= $statusBadge[$inv['status']] ?? '#64748b' ?>"><?= htmlspecialchars($inv['status']) ?></span></td>
                    <td><?php if ($inv['payment_url']): ?><a class="pay-link" href="<?= htmlspecialchars($inv['payment_url']) ?>" target="_blank">buka</a><?php else: ?>-<?php endif; ?></td>
                    <td>
                        <?php if ($inv['status'] === 'unpaid'): ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Tandai invoice ini lunas secara manual?');">
                                <input type="hidden" name="action" value="mark_paid_manual">
                                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                                <button class="btn btn-outline" type="submit">Tandai Lunas Manual</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($invoices)): ?>
                <tr>
                    <td colspan="7" style="color:#94a3b8;">Belum ada invoice.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>
</body>

</html>