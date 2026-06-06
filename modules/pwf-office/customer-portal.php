<?php
/**
 * Customer Portal - PWF Office
 * Public monitoring page for customer order progress, container shipped qty, and monthly recap.
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/db-helper.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Direct PWF DB connection — bypasses session-based config that defaults to wrong database
$_isProduction = (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') === false);
$_pwfDb = $_isProduction ? 'adfb2574_pwf' : 'adf_pwf';
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $_pwfDb . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    if (function_exists('ensurePwfOfficeTables')) ensurePwfOfficeTables($pdo);
} catch (PDOException $e) {
    // If primary DB name fails, attempt alternate naming convention
    $_altDb = $_isProduction ? 'adfb2574_pwf' : 'adf_system_pwf';
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $_altDb . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';

$rawQuery = trim((string)($_GET['c'] ?? $_GET['q'] ?? ''));
$searchText = strtoupper($rawQuery);
$searchCode = preg_replace('/[^A-Z0-9\-]/', '', $searchText);
$searchNormalized = preg_replace('/[^A-Z0-9]/', '', $searchText);

$companyName = 'Prapen Wood Furniture';
$logoUrl = '';
$faviconUrl = '';
$faviconType = 'image/x-icon';

try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('pwf_company_name','pwf_login_logo','pwf_favicon')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($rows['pwf_company_name'])) {
        $companyName = $rows['pwf_company_name'];
    }
    if (!empty($rows['pwf_login_logo'])) {
        $logoUrl = (strpos($rows['pwf_login_logo'], 'http') === 0)
            ? $rows['pwf_login_logo']
            : $baseUrl . '/' . ltrim($rows['pwf_login_logo'], '/');
    }
    if (!empty($rows['pwf_favicon'])) {
        $faviconUrl = (strpos($rows['pwf_favicon'], 'http') === 0)
            ? $rows['pwf_favicon']
            : $baseUrl . '/' . ltrim($rows['pwf_favicon'], '/');
        $ext = strtolower(pathinfo(parse_url($faviconUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $mimeMap = [
            'ico' => 'image/x-icon',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
        ];
        $faviconType = $mimeMap[$ext] ?? 'image/x-icon';
    }
} catch (Exception $e) {
}

$customer = null;
$summary = [
    'total_orders' => 0,
    'qty_ordered' => 0,
    'qty_done' => 0,
    'qty_shipped' => 0,
    'container_count' => 0,
];
$monthlyRows = [];
$recentOrders = [];
$errorMessage = '';
$resolvedSearchLabel = '';

if ($rawQuery !== '') {
    // Flexible search by customer code OR customer name
    $stmtCustomer = $pdo->prepare("SELECT id, customer_code, customer_name, phone, address
        FROM pwf_customers
        WHERE UPPER(customer_code) = ?
           OR UPPER(REPLACE(REPLACE(REPLACE(customer_code, '-', ''), ' ', ''), '.', '')) = ?
           OR UPPER(customer_name) = ?
           OR UPPER(customer_name) LIKE ?
        LIMIT 1");
    $stmtCustomer->execute([
        $searchCode,
        $searchNormalized,
        $searchText,
        '%' . $searchText . '%'
    ]);
    $customer = $stmtCustomer->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        // Fallback scan to tolerate unusual separators in code entries
        $allCustomers = $pdo->query("SELECT id, customer_code, customer_name, phone, address FROM pwf_customers ORDER BY id DESC LIMIT 3000")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allCustomers as $row) {
            $rowCodeNorm = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($row['customer_code'] ?? '')));
            $rowNameNorm = preg_replace('/\s+/', ' ', strtoupper(trim((string)($row['customer_name'] ?? ''))));
            if ($rowCodeNorm === $searchNormalized || strpos($rowNameNorm, $searchText) !== false) {
                $customer = $row;
                break;
            }
        }
    }

    if (!$customer) {
        $errorMessage = 'Customer not found. Please check your customer code or customer name.';
    } else {
        $customerId = (int)$customer['id'];
        $resolvedSearchLabel = $customer['customer_code'] . ' / ' . $customer['customer_name'];

        $stmtSummary = $pdo->prepare("
            SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(o.quantity), 0) AS qty_ordered,
                COALESCE(SUM(o.qty_done), 0) AS qty_done,
                COALESCE(SUM(s.qty_shipped), 0) AS qty_shipped,
                COUNT(DISTINCT ci.container_id) AS container_count
            FROM pwf_orders o
            LEFT JOIN (
                SELECT order_id, SUM(qty_shipped) AS qty_shipped
                FROM pwf_container_items
                GROUP BY order_id
            ) s ON s.order_id = o.id
            LEFT JOIN pwf_container_items ci ON ci.order_id = o.id
            WHERE o.customer_id = ?
              AND o.status <> 'cancelled'
        ");
        $stmtSummary->execute([$customerId]);
        $sum = $stmtSummary->fetch(PDO::FETCH_ASSOC) ?: [];

        $summary['total_orders'] = (int)($sum['total_orders'] ?? 0);
        $summary['qty_ordered'] = (float)($sum['qty_ordered'] ?? 0);
        $summary['qty_done'] = (float)($sum['qty_done'] ?? 0);
        $summary['qty_shipped'] = (float)($sum['qty_shipped'] ?? 0);
        $summary['container_count'] = (int)($sum['container_count'] ?? 0);

        $stmtMonthly = $pdo->prepare("
            SELECT
                DATE_FORMAT(o.order_date, '%Y-%m') AS period_key,
                DATE_FORMAT(o.order_date, '%b %Y') AS period_label,
                COUNT(*) AS total_orders,
                COALESCE(SUM(o.quantity), 0) AS qty_ordered,
                COALESCE(SUM(o.qty_done), 0) AS qty_done,
                COALESCE(SUM(s.qty_shipped), 0) AS qty_shipped
            FROM pwf_orders o
            LEFT JOIN (
                SELECT order_id, SUM(qty_shipped) AS qty_shipped
                FROM pwf_container_items
                GROUP BY order_id
            ) s ON s.order_id = o.id
            WHERE o.customer_id = ?
              AND o.status <> 'cancelled'
            GROUP BY period_key, period_label
            ORDER BY period_key DESC
            LIMIT 12
        ");
        $stmtMonthly->execute([$customerId]);
        $monthlyRows = $stmtMonthly->fetchAll(PDO::FETCH_ASSOC);

        $stmtRecent = $pdo->prepare("
            SELECT
                o.order_code,
                o.order_date,
                o.product_name,
                o.quantity,
                o.qty_done,
                o.status,
                COALESCE(s.qty_shipped, 0) AS qty_shipped
            FROM pwf_orders o
            LEFT JOIN (
                SELECT order_id, SUM(qty_shipped) AS qty_shipped
                FROM pwf_container_items
                GROUP BY order_id
            ) s ON s.order_id = o.id
            WHERE o.customer_id = ?
              AND o.status <> 'cancelled'
            ORDER BY o.order_date DESC, o.id DESC
            LIMIT 8
        ");
        $stmtRecent->execute([$customerId]);
        $recentOrders = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
    }
}

function fmtQty(float $value): string
{
    return rtrim(rtrim(number_format($value, 2), '0'), '.');
}

$completionPct = 0;
if ($summary['qty_ordered'] > 0) {
    $completionPct = min(100, max(0, (int)round(($summary['qty_done'] / $summary['qty_ordered']) * 100)));
}

$shippingPct = 0;
if ($summary['qty_ordered'] > 0) {
    $shippingPct = min(100, max(0, (int)round(($summary['qty_shipped'] / $summary['qty_ordered']) * 100)));
}

$manifestHref = 'customer-manifest.php';
if ($rawQuery !== '') {
    $manifestHref .= '?q=' . urlencode($rawQuery);
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0E223D">
    <title>Customer Portal - <?= htmlspecialchars($companyName) ?></title>
    <link rel="manifest" href="<?= htmlspecialchars($manifestHref) ?>">
    <?php if ($faviconUrl !== ''): ?>
        <link rel="icon" type="<?= htmlspecialchars($faviconType) ?>" href="<?= htmlspecialchars($faviconUrl) ?>">
        <link rel="apple-touch-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
    <?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #0E223D;
            --ink-soft: #1E3A5F;
            --mist: #EFF4FA;
            --card: #FFFFFF;
            --line: #D8E2EF;
            --ok: #0F9D74;
            --warn: #F59E0B;
            --accent: #F97316;
            --text: #0F172A;
            --muted: #64748B;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text);
            background:
                radial-gradient(1200px 500px at 80% -10%, #173B64 0%, transparent 60%),
                radial-gradient(900px 400px at -20% 10%, #1D4B7A 0%, transparent 55%),
                linear-gradient(180deg, #0C1E37 0%, #0E223D 260px, #F3F7FB 260px, #F3F7FB 100%);
            min-height: 100vh;
        }

        .wrap {
            width: min(1080px, 94vw);
            margin: 0 auto;
            padding: 24px 0 40px;
        }

        .hero {
            color: #fff;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 16px;
            align-items: center;
            margin-bottom: 18px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo {
            width: 72px;
            height: 72px;
            border-radius: 18px;
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.25);
            display: grid;
            place-items: center;
            overflow: hidden;
        }

        .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 8px;
            background: #fff;
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(20px, 3.2vw, 30px);
            font-weight: 800;
            letter-spacing: .2px;
        }

        .hero p {
            margin: 4px 0 0;
            font-size: 13px;
            color: rgba(255,255,255,.78);
        }

        .install-btn {
            border: 1px solid rgba(255,255,255,.28);
            background: rgba(255,255,255,.14);
            color: #fff;
            border-radius: 12px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
            display: none;
        }

        .install-btn::before {
            content: '\2B07\FE0F';
            margin-right: 6px;
        }

        .panel {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 16px;
            box-shadow: 0 12px 40px rgba(8, 22, 43, .08);
        }

        .search-grid {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            align-items: end;
        }

        .label {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--muted);
            font-weight: 700;
            margin-bottom: 6px;
        }

        .input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 11px 12px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            outline: none;
        }

        .input:focus {
            border-color: #5C88C2;
            box-shadow: 0 0 0 3px rgba(92,136,194,.16);
        }

        .btn {
            border: 0;
            border-radius: 12px;
            background: linear-gradient(135deg, #1F4B7A, #2A6DA8);
            color: #fff;
            padding: 11px 16px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .error {
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            background: #FFF1F2;
            border: 1px solid #FFD5DD;
            color: #BE123C;
            font-size: 13px;
            font-weight: 600;
        }

        .dashboard {
            margin-top: 14px;
            display: grid;
            gap: 14px;
            overflow-x: auto;
        }

        .desktop-fixed {
            min-width: 980px;
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 6px;
        }

        .chip {
            font-size: 11px;
            background: #EEF4FF;
            color: #1E40AF;
            border: 1px solid #C7D7FE;
            border-radius: 99px;
            padding: 6px 10px;
            font-weight: 700;
        }

        .kpi {
            display: grid;
            grid-template-columns: repeat(4, minmax(0,1fr));
            gap: 10px;
        }

        .kpi-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 12px;
        }

        .kpi-title {
            font-size: 11px;
            color: var(--muted);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 6px;
        }

        .kpi-value {
            font-size: 26px;
            font-weight: 800;
            line-height: 1;
            color: var(--ink);
        }

        .kpi-unit {
            font-size: 12px;
            color: var(--muted);
            margin-left: 4px;
            font-weight: 700;
        }

        .two-col {
            display: grid;
            grid-template-columns: 1.2fr .8fr;
            gap: 12px;
        }

        .progress-row { margin-bottom: 12px; }
        .progress-head {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            font-weight: 700;
            color: var(--ink-soft);
            margin-bottom: 6px;
        }
        .bar {
            width: 100%;
            height: 12px;
            background: #E8EFF8;
            border-radius: 999px;
            overflow: hidden;
        }
        .bar-fill {
            height: 100%;
            border-radius: 999px;
            transition: width .5s ease;
        }
        .bar-done { background: linear-gradient(90deg, #0F9D74, #34D399); }
        .bar-ship { background: linear-gradient(90deg, #F97316, #FDBA74); }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        th {
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .45px;
            color: var(--muted);
            padding: 10px 8px;
            border-bottom: 1px solid var(--line);
        }

        td {
            padding: 11px 8px;
            border-bottom: 1px solid #EEF3F9;
            vertical-align: middle;
        }

        .status {
            display: inline-flex;
            align-items: center;
            border-radius: 99px;
            padding: 4px 8px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            background: #F1F5F9;
            color: #334155;
        }

        .status.ready_ship, .status.completed { background: #ECFDF5; color: #047857; }
        .status.on_progress, .status.partial_ship, .status.qc { background: #FFF7ED; color: #C2410C; }
        .status.draft { background: #EFF6FF; color: #1D4ED8; }
        .status.shipped { background: #EEF2FF; color: #4338CA; }

        .footer-note {
            margin-top: 14px;
            font-size: 11px;
            color: #64748B;
            text-align: center;
        }

        .install-guide {
            margin-top: 14px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 14px;
        }

        .install-guide h3 {
            margin: 0 0 10px;
            font-size: 14px;
            color: #0F2948;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .install-cols {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .install-card {
            border: 1px solid #E6EDF6;
            background: #FAFCFF;
            border-radius: 12px;
            padding: 12px;
        }

        .install-title {
            margin: 0 0 8px;
            font-size: 13px;
            font-weight: 800;
            color: #1E3A5F;
        }

        .install-list {
            margin: 0;
            padding-left: 18px;
            color: #334155;
            font-size: 12px;
            line-height: 1.55;
        }

        .android-install-btn {
            display: none;
            width: 100%;
            margin-top: 10px;
            padding: 12px;
            background: linear-gradient(135deg, #1a7cf9, #1558c0);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        @media (max-width: 560px) {
            .wrap { width: min(1080px, 96vw); padding-top: 16px; }
            .panel { border-radius: 14px; padding: 12px; }
            .search-grid { grid-template-columns: 1fr auto; }
            .btn { width: auto; }
            .logo { width: 62px; height: 62px; border-radius: 16px; }
            .kpi-card { padding: 10px; }
            .kpi-value { font-size: 22px; }
            .install-cols { grid-template-columns: 1fr; }
            table { font-size: 11px; }
            th, td { padding: 8px 6px; }
        }

        @media (max-width: 900px) {
            .hero { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="hero">
            <div class="brand">
                <div class="logo">
                    <?php if ($logoUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo">
                    <?php else: ?>
                        <span style="font-size:30px;color:#fff;">P</span>
                    <?php endif; ?>
                </div>
                <div>
                    <h1>Customer Monitoring Portal</h1>
                    <p><?= htmlspecialchars($companyName) ?> - Production progress, container shipment, and monthly recap</p>
                </div>
            </div>
            <button id="installBtn" class="install-btn" type="button" aria-label="Install app">Install</button>
        </div>

        <div class="panel">
            <form method="get" class="search-grid">
                <div>
                    <label class="label">Customer Code or Customer Name</label>
                    <input class="input" type="text" name="q" value="<?= htmlspecialchars($rawQuery) ?>" placeholder="Example: CUS-202606-001 or Hunger Resto" required>
                </div>
                <button class="btn" type="submit">View Monitoring</button>
            </form>

            <?php if ($errorMessage !== ''): ?>
                <div class="error"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <?php if ($customer): ?>
                <div class="dashboard">
                    <div class="desktop-fixed">
                        <h2 style="margin:0;font-size:20px;color:#102A4A;"><?= htmlspecialchars($customer['customer_name']) ?></h2>
                        <div class="meta">
                            <span class="chip">Code: <?= htmlspecialchars($customer['customer_code']) ?></span>
                            <?php if ($resolvedSearchLabel !== ''): ?>
                                <span class="chip">Matched: <?= htmlspecialchars($resolvedSearchLabel) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($customer['phone'])): ?>
                                <span class="chip">Phone: <?= htmlspecialchars($customer['phone']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="kpi desktop-fixed">
                        <div class="kpi-card">
                            <div class="kpi-title">Total Orders</div>
                            <div class="kpi-value"><?= (int)$summary['total_orders'] ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Ordered Qty</div>
                            <div class="kpi-value"><?= htmlspecialchars(fmtQty((float)$summary['qty_ordered'])) ?><span class="kpi-unit">pcs</span></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Completed Qty</div>
                            <div class="kpi-value" style="color:#0F766E;"><?= htmlspecialchars(fmtQty((float)$summary['qty_done'])) ?><span class="kpi-unit">pcs</span></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">In Container</div>
                            <div class="kpi-value" style="color:#C2410C;"><?= htmlspecialchars(fmtQty((float)$summary['qty_shipped'])) ?><span class="kpi-unit">pcs</span></div>
                        </div>
                    </div>

                    <div class="two-col desktop-fixed">
                        <div class="panel" style="padding:12px;">
                            <h3 style="margin:0 0 10px;font-size:14px;color:#0F2948;">Progress Summary</h3>
                            <div class="progress-row">
                                <div class="progress-head">
                                    <span>Production completed</span>
                                    <span><?= (int)$completionPct ?>%</span>
                                </div>
                                <div class="bar"><div class="bar-fill bar-done" style="width: <?= (int)$completionPct ?>%;"></div></div>
                            </div>
                            <div class="progress-row" style="margin-bottom:0;">
                                <div class="progress-head">
                                    <span>Already in container</span>
                                    <span><?= (int)$shippingPct ?>%</span>
                                </div>
                                <div class="bar"><div class="bar-fill bar-ship" style="width: <?= (int)$shippingPct ?>%;"></div></div>
                            </div>
                            <div style="margin-top:10px;font-size:11px;color:#64748B;">Total related containers: <strong><?= (int)$summary['container_count'] ?></strong></div>
                        </div>

                        <div class="panel" style="padding:12px;">
                            <h3 style="margin:0 0 10px;font-size:14px;color:#0F2948;">Current Status</h3>
                            <div style="display:grid;gap:8px;font-size:12px;">
                                <div style="display:flex;justify-content:space-between;"><span style="color:#64748B;">Active orders</span><strong><?= (int)$summary['total_orders'] ?></strong></div>
                                <div style="display:flex;justify-content:space-between;"><span style="color:#64748B;">Qty not completed</span><strong><?= htmlspecialchars(fmtQty(max(0, (float)$summary['qty_ordered'] - (float)$summary['qty_done']))) ?> pcs</strong></div>
                                <div style="display:flex;justify-content:space-between;"><span style="color:#64748B;">Qty ready to ship</span><strong><?= htmlspecialchars(fmtQty(max(0, (float)$summary['qty_done'] - (float)$summary['qty_shipped']))) ?> pcs</strong></div>
                            </div>
                        </div>
                    </div>

                    <div class="panel desktop-fixed" style="padding:12px;overflow:auto;">
                        <h3 style="margin:0 0 8px;font-size:14px;color:#0F2948;">Monthly Order Recap (Last 12 Months)</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Orders</th>
                                    <th>Ordered Qty</th>
                                    <th>Completed Qty</th>
                                    <th>In Container</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($monthlyRows)): ?>
                                    <tr><td colspan="5" style="text-align:center;color:#94A3B8;">No monthly data yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($monthlyRows as $row): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($row['period_label']) ?></strong></td>
                                            <td><?= (int)$row['total_orders'] ?></td>
                                            <td><?= htmlspecialchars(fmtQty((float)$row['qty_ordered'])) ?> pcs</td>
                                            <td><?= htmlspecialchars(fmtQty((float)$row['qty_done'])) ?> pcs</td>
                                            <td><?= htmlspecialchars(fmtQty((float)$row['qty_shipped'])) ?> pcs</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="panel desktop-fixed" style="padding:12px;overflow:auto;">
                        <h3 style="margin:0 0 8px;font-size:14px;color:#0F2948;">Recent Orders</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Order Code</th>
                                    <th>Date</th>
                                    <th>Product</th>
                                    <th>Qty</th>
                                    <th>Done</th>
                                    <th>Container</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentOrders)): ?>
                                    <tr><td colspan="7" style="text-align:center;color:#94A3B8;">No orders yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recentOrders as $ord): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($ord['order_code']) ?></strong></td>
                                            <td><?= htmlspecialchars((string)$ord['order_date']) ?></td>
                                            <td><?= htmlspecialchars((string)$ord['product_name']) ?></td>
                                            <td><?= htmlspecialchars(fmtQty((float)$ord['quantity'])) ?></td>
                                            <td><?= htmlspecialchars(fmtQty((float)$ord['qty_done'])) ?></td>
                                            <td><?= htmlspecialchars(fmtQty((float)$ord['qty_shipped'])) ?></td>
                                            <td>
                                                <span class="status <?= htmlspecialchars((string)$ord['status']) ?>">
                                                    <?= htmlspecialchars(str_replace('_', ' ', (string)$ord['status'])) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="install-guide">
            <h3>&#128241; Add to Home Screen</h3>
            <div class="install-cols">
                <div class="install-card" id="androidGuide">
                    <div class="install-title">&#9654; Android (Chrome)</div>
                    <button id="androidInstallBtn" class="android-install-btn" type="button">
                        &#8659; Install Customer Portal
                    </button>
                    <ol class="install-list" style="margin-top:8px">
                        <li>Tap <strong>Install</strong> button above if visible.</li>
                        <li>Or open Chrome menu &rarr; <strong>Install app</strong>.</li>
                        <li>The icon will appear on your Home Screen.</li>
                    </ol>
                </div>
                <div class="install-card">
                    <div class="install-title">&#63743; iPhone / iPad (Safari)</div>
                    <ol class="install-list">
                        <li>Open this portal in <strong>Safari</strong>.</li>
                        <li>Tap the <strong>Share</strong> button (&#11014; box icon).</li>
                        <li>Select <strong>Add to Home Screen</strong>.</li>
                        <li>Tap <strong>Add</strong> — icon appears on Home Screen.</li>
                    </ol>
                </div>
            </div>
        </div>

        <div class="footer-note">This portal displays real-time monitoring data from production and shipping.</div>
    </div>

    <script>
        (function () {
            // Register service worker
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('customer-sw.js').catch(function (err) {
                    console.warn('SW register failed:', err);
                });
            }

            var ua = navigator.userAgent || '';
            var isAndroid = /Android/i.test(ua);
            var isIOS = /iPhone|iPad|iPod/i.test(ua);

            // Header install button (hero area)
            var headerInstallBtn = document.getElementById('installBtn');
            // Guide install button (in the install card)
            var guideInstallBtn = document.getElementById('androidInstallBtn');

            var deferredPrompt = null;

            function showAndroidInstallUI() {
                if (guideInstallBtn) guideInstallBtn.style.display = 'inline-flex';
                if (headerInstallBtn && isAndroid) headerInstallBtn.style.display = 'inline-flex';
            }

            function doInstall() {
                if (deferredPrompt) {
                    deferredPrompt.prompt();
                    deferredPrompt.userChoice.then(function () {
                        deferredPrompt = null;
                        if (guideInstallBtn) guideInstallBtn.style.display = 'none';
                        if (headerInstallBtn) headerInstallBtn.style.display = 'none';
                    }).catch(function () {});
                } else {
                    // Fallback: Chrome didn't fire event yet, guide user to menu
                    alert('To install: open Chrome menu (⋮) → Install app');
                }
            }

            window.addEventListener('beforeinstallprompt', function (e) {
                e.preventDefault();
                deferredPrompt = e;
                if (isAndroid) showAndroidInstallUI();
            });

            window.addEventListener('appinstalled', function () {
                deferredPrompt = null;
                if (guideInstallBtn) guideInstallBtn.style.display = 'none';
                if (headerInstallBtn) headerInstallBtn.style.display = 'none';
            });

            if (isAndroid) {
                // On Android, always show install guide section even before event fires
                showAndroidInstallUI();
                if (guideInstallBtn) guideInstallBtn.addEventListener('click', doInstall);
                if (headerInstallBtn) headerInstallBtn.addEventListener('click', doInstall);
            }

            // Hide header install btn on non-Android
            if (!isAndroid && headerInstallBtn) headerInstallBtn.style.display = 'none';

            // Scroll to error on mobile
            var errorBox = document.querySelector('.error');
            if (errorBox && window.innerWidth <= 900) {
                setTimeout(function () {
                    errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 200);
            }
        })();
    </script>
</body>
</html>
