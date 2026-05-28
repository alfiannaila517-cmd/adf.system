<?php
function pwfOfficeHeader(string $title, string $active = ''): void
{
    $menu = [
        'dashboard' => ['label' => 'Dashboard', 'url' => 'dashboard.php'],
        'customers' => ['label' => 'Customer', 'url' => 'customers.php'],
        'orders' => ['label' => 'Order Customer', 'url' => 'orders.php'],
        'craftsmen' => ['label' => 'Data Tukang', 'url' => 'craftsmen.php'],
        'progress' => ['label' => 'Pencapaian Tukang', 'url' => 'progress.php'],
        'shipping' => ['label' => 'Finish & Export', 'url' => 'shipping.php'],
        'settings' => ['label' => '⚙ Settings', 'url' => 'settings.php']
    ];
?>
    <!doctype html>
    <html lang="id">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($title) ?> - PWF Office</title>
        <style>
            :root {
                --primary: #0f766e;
                --accent: #f59e0b;
                --bg: #f3f4f6;
                --text: #111827;
                --muted: #6b7280;
                --card: #ffffff;
            }

            body {
                margin: 0;
                font-family: 'Segoe UI', Tahoma, sans-serif;
                background: var(--bg);
                color: var(--text);
            }

            .top {
                background: linear-gradient(135deg, #0f766e, #115e59);
                color: #fff;
                padding: 16px 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .brand h1 {
                margin: 0;
                font-size: 20px;
            }

            .brand p {
                margin: 4px 0 0;
                font-size: 12px;
                opacity: 0.9;
            }

            .wrap {
                display: grid;
                grid-template-columns: 250px 1fr;
                min-height: calc(100vh - 76px);
            }

            .side {
                background: #0b1320;
                padding: 14px;
            }

            .side a {
                display: block;
                color: #d1d5db;
                text-decoration: none;
                padding: 10px 12px;
                border-radius: 8px;
                margin-bottom: 6px;
                font-size: 14px;
            }

            .side a.active {
                background: #1f2937;
                color: #fff;
                border-left: 4px solid var(--accent);
            }

            .main {
                padding: 20px;
            }

            .card {
                background: var(--card);
                border-radius: 12px;
                padding: 16px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, .06);
            }

            .row {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 12px;
            }

            .stat h3 {
                margin: 0;
                font-size: 24px;
            }

            .stat p {
                margin: 4px 0 0;
                color: var(--muted);
            }

            .btn {
                background: var(--primary);
                color: #fff;
                border: 0;
                border-radius: 8px;
                padding: 8px 12px;
                cursor: pointer;
            }

            .btn.warn {
                background: #b45309;
            }

            .input,
            .select,
            textarea {
                width: 100%;
                padding: 9px;
                border: 1px solid #d1d5db;
                border-radius: 8px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
            }

            th,
            td {
                padding: 10px;
                border-bottom: 1px solid #e5e7eb;
                text-align: left;
                font-size: 14px;
            }

            .grid2 {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .mt {
                margin-top: 14px;
            }

            .small {
                font-size: 12px;
                color: var(--muted);
            }

            @media (max-width: 900px) {
                .wrap {
                    grid-template-columns: 1fr;
                }

                .row {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }

                .grid2 {
                    grid-template-columns: 1fr;
                }
            }
        </style>
    </head>

    <body>
        <div class="top">
            <div class="brand">
                <h1>Prapen Wood Furniture - Office System</h1>
                <p>Jl. Ngabul - Batealit No.KM. 5 Godang, Mindahan, Kec. Batealit, Kabupaten Jepara, Jawa Tengah 59400</p>
            </div>
            <div>
                <a href="<?= BASE_URL ?>/logout.php" style="color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.4);padding:8px 12px;border-radius:8px;">Logout</a>
            </div>
        </div>
        <div class="wrap">
            <aside class="side">
                <?php foreach ($menu as $key => $m): ?>
                    <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= BASE_URL ?>/modules/pwf-office/<?= $m['url'] ?>"><?= htmlspecialchars($m['label']) ?></a>
                <?php endforeach; ?>
            </aside>
            <main class="main">
                <div class="card">
                    <h2 style="margin:0 0 8px;"><?= htmlspecialchars($title) ?></h2>
                    <div class="small">Versi lokal pengembangan PWF Office</div>
                </div>
                <div class="mt"></div>
            <?php
        }

        function pwfOfficeFooter(): void
        {
            echo "        </main></div></body></html>";
        }
