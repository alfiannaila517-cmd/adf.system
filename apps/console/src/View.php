<?php

declare(strict_types=1);

/**
 * Tampilan ADF Console: halaman tamu (login) dan kerangka utama (sidebar menu + isi). Gelap–emas, ramah HP.
 */
final class View
{
    /** Menu utama: [kunci, label, ikon, url, khusus admin?, sudah tersedia?] */
    private const MENU = [
        ['dashboard', 'Dashboard', '◧', '/', false, true],
        ['clients', 'Client & Bisnis', '▦', '/clients.php', false, true],
        ['billing', 'Langganan & Tagihan', '◈', '/billing.php', false, false],
        ['integrations', 'Integrasi', '⇄', '/integrations.php', false, false],
        ['monitor', 'Monitor Server', '◉', '/monitor.php', false, false],
        ['users', 'Tim ADF', '◍', '/users.php', true, true],
        ['audit', 'Audit Log', '☰', '/audit.php', true, false],
    ];

    public static function head(string $title): void
    {
        ?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#14110d">
    <title><?= e($title) ?> · ADF Console</title>
    <style>
        :root { --bg: #12100c; --side: #17140f; --card: #1d1914; --line: #312a21; --ink: #f1ece4; --mu: #a39888; --gold: #d6b16a; --gold2: #b8924a; --ok: #5fc983; --warn: #f0b85a; --bad: #ef7b6e; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 13.5px/1.5 system-ui, -apple-system, 'Segoe UI', sans-serif; }
        a { color: var(--gold); text-decoration: none; }
        h1 { font-size: 18px; font-weight: 600; margin: 0 0 2px; }
        .muted { color: var(--mu); }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; }
        .card h2 { font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--mu); margin: 0 0 10px; font-weight: 600; }
        label { display: block; font-size: 12px; color: var(--mu); margin: 0 0 4px; }
        input, select, textarea { width: 100%; padding: 8px 11px; border-radius: 8px; border: 1px solid var(--line); background: #15120e; color: var(--ink); font: inherit; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--gold2); }
        .btn { display: inline-block; padding: 8px 14px; border: 0; border-radius: 8px; background: var(--gold); color: #1a150e; font: inherit; font-weight: 700; cursor: pointer; text-align: center; }
        .btn.ghost { background: transparent; color: var(--ink); border: 1px solid var(--line); }
        .btn.danger { background: transparent; color: var(--bad); border: 1px solid rgba(239, 123, 110, .45); }
        .btn.sm { padding: 5px 10px; font-size: 12px; }
        .field { margin-bottom: 10px; }
        .alert { padding: 11px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 14px; }
        .alert.ok { background: rgba(95, 201, 131, .12); color: var(--ok); }
        .alert.err { background: rgba(239, 123, 110, .12); color: var(--bad); }
        .alert.info { background: rgba(214, 177, 106, .1); color: var(--gold); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--mu); font-weight: 600; padding: 0 10px 10px; }
        td { padding: 8px 10px; border-top: 1px solid var(--line); vertical-align: middle; }
        .pill { display: inline-block; padding: 1px 7px; border-radius: 99px; font-size: 11px; background: #2a241c; color: var(--mu); }
        .pill.gold { background: rgba(214, 177, 106, .15); color: var(--gold); }
        /* Tab & kartu */
        .crumb { font-size: 13px; color: var(--mu); margin-bottom: 6px; }
        .crumb a { color: var(--mu); } .crumb a:hover { color: var(--gold); }
        .tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--line); margin: 12px 0 14px; overflow-x: auto; }
        .tabs a { padding: 7px 11px; color: var(--mu); font-size: 13px; border-bottom: 2px solid transparent; white-space: nowrap; }
        .tabs a.on { color: var(--gold); border-bottom-color: var(--gold); }
        .tabs a.soon { opacity: .5; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 10px; }
        .tile { display: block; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; color: var(--ink); transition: border-color .2s ease, transform .2s ease; }
        a.tile:hover { border-color: var(--gold2); transform: translateY(-2px); }
        .tile .ic { font-size: 17px; } .tile b { display: block; font-size: 14px; margin: 4px 0 1px; } .tile small { color: var(--mu); font-size: 12px; }
        .tile .meta { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 6px; }
        .pill.ok { background: rgba(95, 201, 131, .14); color: var(--ok); } .pill.bad { background: rgba(239, 123, 110, .14); color: var(--bad); } .pill.warn { background: rgba(240, 184, 90, .14); color: var(--warn); }
        .row2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
        .kv { display: grid; grid-template-columns: 150px 1fr; gap: 6px 12px; font-size: 13px; } .kv dt { color: var(--mu); } .kv dd { margin: 0; }
        .soonbox { border: 1px dashed var(--line); border-radius: 12px; padding: 14px 16px; color: var(--mu); }
        .soonbox b { color: var(--ink); }
        code { background: #241f18; padding: 1px 6px; border-radius: 6px; color: var(--gold); font-size: 13px; }
        @media (max-width: 860px) { .kv { grid-template-columns: 1fr; } .kv dt { margin-top: 6px; } }
        /* Halaman tamu */
        .guest { min-height: 100vh; display: grid; place-items: center; padding: 20px; }
        .guest .box { width: 100%; max-width: 400px; }
        .brand { text-align: center; margin-bottom: 18px; }
        .brand b { display: block; font-size: 20px; font-weight: 300; letter-spacing: .22em; }
        .brand span { font-size: 12px; color: var(--mu); letter-spacing: .08em; }
        /* Kerangka utama */
        .shell { display: grid; grid-template-columns: 210px 1fr; min-height: 100vh; }
        .side { background: var(--side); border-right: 1px solid var(--line); padding: 20px 14px; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
        .side .brand { text-align: left; padding: 0 8px 18px; border-bottom: 1px solid var(--line); margin-bottom: 12px; }
        .side .brand b { font-size: 16px; }
        .nav a { display: flex; gap: 9px; align-items: center; padding: 7px 9px; border-radius: 8px; color: var(--ink); font-size: 13px; }
        .nav a i { font-style: normal; width: 18px; text-align: center; color: var(--gold2); }
        .nav a.on { background: rgba(214, 177, 106, .12); color: var(--gold); }
        .nav a.soon { color: var(--mu); }
        .nav a.soon::after { content: 'segera'; margin-left: auto; font-size: 10px; letter-spacing: .08em; color: var(--mu); border: 1px solid var(--line); border-radius: 99px; padding: 0 6px; }
        .side .me { margin-top: 18px; padding: 12px 10px; border-top: 1px solid var(--line); font-size: 13px; }
        .main { padding: 20px 24px 32px; max-width: 1100px; width: 100%; }
        .top { display: none; }
        @media (max-width: 860px) {
            .shell { grid-template-columns: 1fr; }
            .side { position: fixed; inset: 0 30% 0 0; z-index: 20; transform: translateX(-100%); transition: transform .25s ease; height: 100%; }
            .shell.open .side { transform: none; box-shadow: 0 0 0 100vmax rgba(0, 0, 0, .5); }
            .top { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--line); position: sticky; top: 0; background: var(--bg); z-index: 10; }
            .top button { background: none; border: 1px solid var(--line); color: var(--ink); border-radius: 8px; padding: 6px 10px; font-size: 16px; }
            .main { padding: 18px 16px 32px; }
            table.stack thead { display: none; }
            table.stack td { display: block; border: 0; padding: 4px 0; }
            table.stack tr { display: block; border-top: 1px solid var(--line); padding: 10px 0; }
        }
    </style>
</head>
<?php
    }

    public static function guestStart(string $title): void
    {
        self::head($title);
        echo '<body><div class="guest"><div class="box"><div class="brand"><b>ADF CONSOLE</b><span>Pusat kendali ADF Platform</span></div>';
    }

    public static function guestEnd(): void
    {
        echo '</div></div></body></html>';
    }

    public static function start(string $title, string $active, array $user): void
    {
        self::head($title);
        echo '<body><div class="shell" id="shell">';
        echo '<aside class="side"><div class="brand"><b>ADF CONSOLE</b><span>' . e(Secrets::get('SERVER_NAME', 'ADF Platform')) . '</span></div><nav class="nav">';
        foreach (self::MENU as [$key, $label, $icon, $url, $adminOnly, $ready]) {
            if ($adminOnly && $user['role'] !== 'admin') {
                continue;
            }
            $cls = ($key === $active ? 'on' : '') . ($ready ? '' : ' soon');
            echo '<a class="' . trim($cls) . '" href="' . ($ready ? e($url) : '#') . '"><i>' . $icon . '</i>' . e($label) . '</a>';
        }
        echo '</nav><div class="me"><b>' . e($user['username']) . '</b> <span class="pill">' . e($user['role']) . '</span><br>'
            . '<a href="/account.php">Akun saya</a> · <a href="/logout.php">Keluar</a></div></aside>';
        echo '<div><div class="top"><button type="button" onclick="document.getElementById(\'shell\').classList.toggle(\'open\')" aria-label="Menu">☰</button><b>' . e($title) . '</b></div><main class="main">';
        if ($f = flash()) {
            echo '<div class="alert ' . ($f['type'] === 'ok' ? 'ok' : 'err') . '">' . e($f['msg']) . '</div>';
        }
    }

    public static function end(): void
    {
        echo '</main></div></div><script>document.querySelectorAll(".nav a").forEach(function(a){a.addEventListener("click",function(){document.getElementById("shell").classList.remove("open")})});</script></body></html>';
    }
}
