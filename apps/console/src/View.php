<?php

declare(strict_types=1);

/**
 * Tampilan ADF Console: halaman tamu (login) dan kerangka utama (sidebar menu + isi). Terang, bersih, ramah HP.
 */
final class View
{
    /**
     * Menu samping — susunan sama dengan admin adfsystem.store lama; "Developer" = pengelolaan sistem bisnis
     * (pengganti Developer Panel lama). String = judul kelompok; array = [kunci, label, ikon, url, khusus admin].
     */
    private const NAV = [
        ['dashboard', 'Dashboard', 'home', '/', false],
        'Bisnis',
        ['orders', 'Transaksi', 'receipt', '/site.php?tab=pesanan', true],
        ['customers', 'Pelanggan', 'team', '/site.php?tab=pelanggan', true],
        ['invoices', 'Invoice', 'file', '/invoices.php', true],
        ['billing', 'Klien Langganan', 'link', '/billing.php', false],
        ['finance', 'Keuangan', 'wallet', '/finance.php', true],
        'Website',
        ['site', 'Konten Website', 'globe', '/site.php?tab=beranda', true],
        'Pengaturan',
        ['payment', 'Payment Gateway', 'card', '/site.php?tab=bayar', true],
        ['users', 'Pengguna', 'user', '/users.php', true],
        ['google', 'Login Google', 'key', '/google-settings.php', true],
        ['telegram', 'Notifikasi Telegram', 'send', '/telegram-settings.php', true],
        'Developer',
        ['clients', 'Bisnis & Sistem', 'grid', '/clients.php', false],
        ['people', 'User & Akses', 'team', '/people.php', false],
        ['server', 'Server', 'server', '/server.php', false],
        ['audit', 'Audit log', 'list', '/audit.php', true],
    ];

    /** Menu bawah di HP: [kunci, label, ikon, url] + "Lainnya" (semua menu) */
    private const BNAV = [
        ['dashboard', 'Dashboard', 'home', '/'],
        ['orders', 'Transaksi', 'receipt', '/site.php?tab=pesanan'],
        ['invoices', 'Invoice', 'file', '/invoices.php'],
        ['billing', 'Langganan', 'link', '/billing.php'],
    ];

    /** Menu aktif halaman ini (dipakai juga oleh menu bawah di HP) */
    private static string $active = '';

    /** Menu untuk daftar "Lainnya" (HP) — kelompok + item yang boleh dilihat peran ini */
    public static function navFor(array $user): array
    {
        $isAdmin = ($user['role'] ?? '') === 'admin';
        return array_values(array_filter(self::NAV, fn ($n) => is_string($n) || $isAdmin || !$n[4]));
    }

    /** Ikon garis sederhana (inline SVG, ikut warna teks) */
    public static function icon(string $name): string
    {
        $p = [
            'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
            'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
            'card' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M6.5 15h4"/>',
            'gear' => '<circle cx="12" cy="12" r="3.2"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
            'out' => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4"/><path d="M10 17l5-5-5-5M15 12H3"/>',
            'server' => '<rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 7.5h.01M7 16.5h.01"/>',
            'team' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.2c2.1.7 3.5 2.6 3.5 5.8"/>',
            'list' => '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
            'key' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M15 8l2 2"/>',
            'store' => '<path d="M4 9.5 5.5 4h13L20 9.5"/><path d="M4 9.5h16v1a3 3 0 0 1-5.3 1.9A3 3 0 0 1 12 13.5a3 3 0 0 1-2.7-1.1A3 3 0 0 1 4 10.5z"/><path d="M5.5 13v7h13v-7M10 20v-4h4v4"/>',
            'sliders' => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
            'phone' => '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M10.5 18.5h3"/>',
            'plug' => '<path d="M9 7V3M15 7V3"/><path d="M6.5 7h11v4a5.5 5.5 0 0 1-11 0z"/><path d="M12 16.5V21"/>',
            'db' => '<ellipse cx="12" cy="5.5" rx="7.5" ry="2.8"/><path d="M4.5 5.5v13c0 1.5 3.4 2.8 7.5 2.8s7.5-1.3 7.5-2.8v-13M4.5 12c0 1.5 3.4 2.8 7.5 2.8s7.5-1.3 7.5-2.8"/>',
            'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"/>',
            'book' => '<path d="M5 4.5A1.5 1.5 0 0 1 6.5 3H19v16H6.5A1.5 1.5 0 0 0 5 20.5z"/><path d="M5 20.5A1.5 1.5 0 0 0 6.5 22H19v-3"/><circle cx="12" cy="9" r="2.2"/><path d="M8.5 15c.4-1.8 1.8-2.8 3.5-2.8s3.1 1 3.5 2.8"/>',
            'wallet' => '<path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H18v4"/><rect x="3" y="7" width="18" height="13" rx="2.5"/><path d="M16 13.5h2"/>',
            'receipt' => '<path d="M5 3v18l2.5-1.5L10 21l2-1.5 2 1.5 2.5-1.5L19 21V3l-2.5 1.5L14 3l-2 1.5L10 3 7.5 4.5z"/><path d="M8.5 9h7M8.5 13h7"/>',
            'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
            'link' => '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>',
            'send' => '<path d="M21 3 10 14"/><path d="M21 3l-7 18-4-7-7-4z"/>',
            'chev' => '<path d="m9 6 6 6-6 6"/>',
        ][$name] ?? '';
        return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
    }

    /** Avatar grup: logo/favicon login perusahaan bila ada, selain itu inisial nama. */
    public static function groupAvatar(string $name, ?string $logoUrl, string $cls = ''): string
    {
        if ($logoUrl) {
            return '<span class="gav ' . $cls . '"><img src="' . e($logoUrl) . '" alt="" loading="lazy"></span>';
        }
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $ini = mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1) . (isset($words[1]) ? mb_substr($words[1], 0, 1) : ''));
        return '<span class="gav ' . $cls . '">' . e($ini) . '</span>';
    }

    /** Satu baris bisnis (ikon jenis, nama, jenis · domain, status, ›) — dipakai daftar bisnis grup & hasil cari. */
    public static function bizRow(array $b, string $extraSub = ''): string
    {
        [$tLabel, $tIcon] = Biz::type((string)$b['type']);
        $pills = '';
        if ((int)($b['unpaid_count'] ?? 0) > 0) {
            $pills .= '<span class="pill warn">' . (int)$b['unpaid_count'] . ' tagihan belum lunas</span>';
        }
        if (!empty($b['hosted_elsewhere'])) {
            $pills .= '<span class="pill">server lain</span>';
        } elseif (empty($b['legacy_business_id']) && $b['type'] !== 'website') {
            $pills .= '<span class="pill">belum dibuat di sistem</span>';
        }
        $pills .= $b['status'] !== 'active' ? '<span class="pill">' . e(Biz::STATUS[$b['status']] ?? $b['status']) . '</span>'
            : (!empty($b['locked']) ? '<span class="pill bad">terkunci</span>' : '<span class="pill ok">aktif</span>');
        $sub = $tLabel . ($b['domain'] ? ' · ' . $b['domain'] : '') . ($extraSub !== '' ? ' · ' . $extraSub : '');
        return '<a class="biz" href="/business.php?id=' . (int)$b['id'] . '"><span class="ic">' . $tIcon . '</span>'
            . '<span style="min-width:0"><div class="nm">' . e($b['name']) . '</div><div class="sub">' . e($sub) . '</div></span>'
            . '<span class="st">' . $pills . '</span><span class="go">' . self::icon('chev') . '</span></a>';
    }

    public static function head(string $title): void
    {
        ?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0b0e14">
    <title><?= e($title) ?> · ADF Console</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/png" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ADF Console">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b0e14; --side: #0e121a; --card: #121620; --card2: #171c27; --line: #222836; --line2: #1b2029; --line3: #2d3546;
            --ink: #e6e9ef; --ink2: #a3abba; --mu: #7d8696; --brand: #ff6a1a; --brand2: #ea580c;
            --gold: #ff8a4c; --gold2: #ff6a1a; --goldbg: rgba(255, 106, 26, .12);
            --ok: #34d399; --okbg: rgba(52, 211, 153, .12); --warn: #fbbf24; --warnbg: rgba(251, 191, 36, .12); --bad: #f87171; --badbg: rgba(248, 113, 113, .12);
            --shadow: 0 1px 2px rgba(0, 0, 0, .25);
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 12.5px/1.5 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; -webkit-font-smoothing: antialiased; }
        a { color: var(--gold); text-decoration: none; }
        a:hover { color: var(--brand2); }
        h1 { font-size: 17px; font-weight: 650; letter-spacing: -.01em; margin: 0 0 2px; }
        .muted { color: var(--mu); }
        .ico { width: 16px; height: 16px; flex-shrink: 0; }

        /* Kartu & judul bagian */
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 13px 15px; margin-bottom: 12px; box-shadow: var(--shadow); }
        .card h2 { font-size: 10px; letter-spacing: .09em; text-transform: uppercase; color: var(--mu); margin: 0 0 9px; font-weight: 600; }
        .pagehead { display: flex; justify-content: space-between; align-items: flex-end; gap: 10px; flex-wrap: wrap; margin: 0 0 14px; }
        .pagehead p { margin: 1px 0 0; color: var(--mu); font-size: 12px; }
        .stat { font-size: 18px; font-weight: 650; letter-spacing: -.01em; line-height: 1.2; }
        .stat small { font-size: 12px; font-weight: 400; color: var(--mu); }

        /* Form */
        label { display: block; font-size: 11.5px; color: var(--mu); margin: 0 0 3px; font-weight: 500; }
        input, select, textarea { width: 100%; padding: 6px 10px; border-radius: 7px; font-size: 12.5px; border: 1px solid var(--line3); background: var(--card2); color: var(--ink); font: inherit; transition: border-color .15s, box-shadow .15s; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--gold2); box-shadow: 0 0 0 3px rgba(255, 106, 26, .18); }
        input:disabled, select:disabled, textarea:disabled { background: #0f131b; color: var(--mu); }
        input[type=checkbox], input[type=radio] { accent-color: var(--brand); }
        .field { margin-bottom: 9px; } textarea { resize: vertical; min-height: 54px; }
        .row2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 9px 10px; }
        .row2 > .field { margin: 0; } .row2 + .field, .row2 + .row2, .field + .row2 { margin-top: 9px; } /* jarak di dalam grid cukup dari gap */

        /* Tombol */
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 6px 13px; font-size: 12.5px; border: 1px solid var(--brand); border-radius: 7px; background: var(--brand); color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-align: center; transition: background .15s, border-color .15s; }
        .btn:hover { background: var(--brand2); color: #fff; }
        .btn.ghost { background: transparent; color: var(--ink); border-color: var(--line3); }
        .btn.ghost:hover { background: rgba(255, 255, 255, .04); color: #fff; }
        .btn.gold { background: var(--goldbg); border-color: var(--goldbg); color: var(--brand); }
        .btn.danger { background: transparent; color: var(--bad); border-color: rgba(248, 113, 113, .35); }
        .btn.danger:hover { background: var(--badbg); }
        .btn.sm { padding: 3px 9px; font-size: 11.5px; border-radius: 6px; }
        .btn { white-space: nowrap; } .card h2:not(:first-child) { margin-top: 14px; }

        /* Pesan */
        .alert { padding: 8px 12px; border-radius: 9px; margin-bottom: 12px; font-size: 12.5px; border: 1px solid transparent; }
        .alert.ok { background: var(--okbg); color: var(--ok); border-color: rgba(52, 211, 153, .2); }
        .alert.err { background: var(--badbg); color: var(--bad); border-color: rgba(248, 113, 113, .2); }
        .alert.info { background: var(--goldbg); color: var(--gold); border-color: rgba(255, 106, 26, .2); }

        /* Tabel */
        table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        th { text-align: left; font-size: 10px; letter-spacing: .08em; text-transform: uppercase; color: var(--mu); font-weight: 600; padding: 0 12px 10px; }
        td { padding: 7px 10px; border-top: 1px solid var(--line2); vertical-align: middle; }
        tbody tr:hover td { background: rgba(255, 255, 255, .02); }
        table.sm { font-size: 12.5px; }
        table.sm td { padding: 5px 8px; }
        table.sm th { padding: 0 10px 8px; }
        select.sm, input.sm { padding: 4px 8px; font-size: 12px; width: auto; }

        /* Label kecil */
        .pill { display: inline-block; padding: 1px 7px; border-radius: 99px; font-size: 10.5px; font-weight: 500; background: #1d2330; color: var(--ink2); }
        .pill.gold { background: var(--goldbg); color: var(--gold); }
        .pill.ok { background: var(--okbg); color: var(--ok); }
        .pill.bad { background: var(--badbg); color: var(--bad); }
        .pill.warn { background: var(--warnbg); color: var(--warn); }

        /* Navigasi halaman */
        .crumb { font-size: 12px; color: var(--mu); margin-bottom: 8px; }
        .crumb a { color: var(--mu); } .crumb a:hover { color: var(--gold); }
        .tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--line); margin: 12px 0 14px; overflow-x: auto; }
        .tabs a { padding: 6px 11px; color: var(--mu); font-size: 12.5px; font-weight: 500; border-bottom: 2px solid transparent; white-space: nowrap; margin-bottom: -1px; }
        .tabs a:hover { color: var(--ink); }
        .tabs a.on { color: var(--brand); border-bottom-color: var(--brand); font-weight: 600; }
        .subtabs { display: flex; gap: 6px; flex-wrap: wrap; margin: 0 0 14px; }
        .subtabs a { padding: 3px 10px; border-radius: 99px; font-size: 11.5px; color: var(--mu); border: 1px solid var(--line); background: transparent; }
        .subtabs a.on { background: var(--brand); color: #fff; border-color: var(--brand); font-weight: 600; }

        /* Kotak / ubin */
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 10px; }
        .tile { display: block; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; color: var(--ink); box-shadow: var(--shadow); transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease; }
        a.tile:hover { border-color: var(--gold2); color: var(--ink); box-shadow: 0 6px 18px rgba(0, 0, 0, .35); transform: translateY(-1px); }
        .tile .ic { font-size: 16px; } .tile b { display: block; font-size: 13.5px; font-weight: 600; margin: 5px 0 1px; } .tile small { color: var(--mu); font-size: 12px; }
        .tile .meta { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 6px; }
        .kv { display: grid; grid-template-columns: 150px 1fr; gap: 7px 14px; font-size: 13px; } .kv dt { color: var(--mu); } .kv dd { margin: 0; }
        .soonbox { border: 1px dashed var(--line3); background: #0f131b; border-radius: 12px; padding: 12px 14px; color: var(--mu); }
        .soonbox b { color: var(--ink); }

        /* Grup bisnis: ubin berikon (halaman Bisnis) */
        .gtiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 10px; }
        .gtile { position: relative; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 6px; padding: 16px 12px 14px; background: var(--card); border: 1px solid var(--line); border-radius: 14px; color: var(--ink); transition: border-color .2s, transform .2s, box-shadow .2s; }
        a.gtile:hover { border-color: var(--gold2); color: var(--ink); transform: translateY(-1px); box-shadow: 0 6px 18px rgba(0, 0, 0, .35); }
        .gav { width: 54px; height: 54px; border-radius: 16px; display: grid; place-items: center; font-weight: 800; font-size: 18px; color: #fff; background: linear-gradient(135deg, #ff6a1a, #b8410a); overflow: hidden; flex-shrink: 0; }
        .gav img { width: 100%; height: 100%; object-fit: cover; background: #fff; }
        .gav.sm { width: 34px; height: 34px; border-radius: 10px; font-size: 13px; }
        .gtile b { font-size: 13.5px; font-weight: 650; line-height: 1.3; }
        .gtile .sub { color: var(--mu); font-size: 11.5px; }
        .gtile .types { font-size: 14px; letter-spacing: 2px; min-height: 18px; }
        .gtile .flag { position: absolute; top: 9px; right: 9px; }

        /* Daftar bisnis: satu baris tipis per bisnis (halaman grup & hasil cari) */
        .blist { background: var(--card); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
        .biz { display: grid; grid-template-columns: 36px minmax(0, 1fr) auto 14px; align-items: center; gap: 11px; padding: 10px 14px; color: var(--ink); border-top: 1px solid var(--line2); }
        .biz:first-child { border-top: 0; }
        .biz:hover { background: rgba(255, 255, 255, .025); color: var(--ink); }
        .biz .ic { width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center; background: #1a1f2b; font-size: 17px; }
        .biz .nm { font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .biz .sub { color: var(--mu); font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .biz .st { display: flex; gap: 5px; flex-wrap: wrap; justify-content: flex-end; }
        .biz .go { color: var(--mu); } .biz:hover .go { color: var(--brand); }

        /* Menu pengaturan bisnis (halaman depan detail bisnis) */
        .hub { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; }
        .hub a { display: grid; grid-template-columns: 40px minmax(0, 1fr) 14px; align-items: center; gap: 12px; padding: 13px 14px; background: var(--card); border: 1px solid var(--line); border-radius: 12px; color: var(--ink); transition: border-color .2s; }
        .hub a:hover { border-color: var(--gold2); color: var(--ink); }
        .hub .hi { width: 40px; height: 40px; border-radius: 11px; display: grid; place-items: center; background: var(--goldbg); color: var(--brand); }
        .hub .hi .ico { width: 19px; height: 19px; }
        .hub b { display: block; font-size: 13px; font-weight: 600; }
        .hub small { display: block; color: var(--mu); font-size: 11.5px; line-height: 1.4; margin-top: 1px; }
        .hub .go { color: var(--mu); } .hub a:hover .go { color: var(--brand); }
        .bizhead { display: flex; align-items: center; gap: 12px; margin: 0 0 14px; }
        .bizhead .ic { width: 46px; height: 46px; border-radius: 13px; display: grid; place-items: center; background: #1a1f2b; font-size: 22px; flex-shrink: 0; }
        @media (max-width: 860px) {
            .gtiles { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .hub { grid-template-columns: 1fr; }
            .biz { grid-template-columns: 36px minmax(0, 1fr) 14px; }
            .biz .st { grid-column: 2; justify-content: flex-start; }
            .biz .go { grid-row: 1; grid-column: 3; }
        }
        code { background: #1a1f2b; padding: 1px 6px; border-radius: 6px; color: var(--gold); font-size: 12px; }
        details > summary { list-style: none; } details > summary::-webkit-details-marker { display: none; }
        details > summary::before { content: '›'; display: inline-block; margin-right: 8px; transition: transform .15s; color: var(--mu); }
        details[open] > summary::before { transform: rotate(90deg); }
        @media (max-width: 860px) { .kv { grid-template-columns: 1fr; } .kv dt { margin-top: 6px; } }

        /* Halaman tamu (login) */
        .guest { min-height: 100vh; display: grid; place-items: center; padding: 20px; background: radial-gradient(ellipse 70% 50% at 50% 0%, rgba(255, 106, 26, .10) 0%, var(--bg) 70%); }
        .guest .box { width: 100%; max-width: 380px; }
        /* iPhone memperbesar layar bila teks input < 16px → di halaman masuk semua input 16px */
        .guest input, .guest select, .guest textarea { font-size: 16px; padding: 10px 12px; border-radius: 9px; }
        .guest .card { padding: 20px 18px; border-radius: 14px; }
        .guest .btn { padding: 10px 14px; font-size: 14px; border-radius: 9px; }
        .gbtn { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; padding: 10px 14px; border-radius: 9px; background: #fff; color: #1f2937; font-weight: 600; font-size: 14px; border: 1px solid #e5e7eb; }
        .gbtn:hover { background: #f3f4f6; color: #111827; }
        .or { display: flex; align-items: center; gap: 10px; color: var(--mu); font-size: 11.5px; margin: 16px 0; }
        .or::before, .or::after { content: ''; flex: 1; height: 1px; background: var(--line); }
        .pw { position: relative; } .pw input { padding-right: 64px; }
        .pw button { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); background: none; border: 0; color: var(--mu); font: inherit; font-size: 12px; cursor: pointer; padding: 6px 8px; }
        .brand { text-align: center; margin-bottom: 20px; }
        .brand .mark { display: block; width: 44px; height: 44px; object-fit: contain; margin: 0 auto 10px; }
        .brand b { display: block; font-size: 17px; font-weight: 800; letter-spacing: -.01em; color: #fff; } .brand b em { font-style: normal; color: var(--brand); font-weight: 700; }
        .brand span { font-size: 12.5px; color: var(--mu); }

        /* Kerangka utama */
        .shell { display: grid; grid-template-columns: 214px 1fr; min-height: 100vh; }
        .shell > div { min-width: 0; } /* isi tidak boleh melebar melewati layar (tabel / form panjang) */
        .side { background: var(--side); border-right: 1px solid var(--line); padding: 16px 12px; position: sticky; top: 0; height: 100vh; overflow-y: auto; display: flex; flex-direction: column; }
        .side .brand { display: flex; align-items: center; gap: 10px; text-align: left; padding: 0 6px 14px; margin-bottom: 10px; border-bottom: 1px solid var(--line2); }
        .side .brand .mark { width: 32px; height: 32px; margin: 0; }
        .side .brand b { font-size: 14.5px; }
        .side .brand span { font-size: 11.5px; display: block; }
        .nav { display: flex; flex-direction: column; gap: 2px; }
        .nav a { display: flex; gap: 9px; align-items: center; padding: 6px 9px; border-radius: 7px; color: var(--ink2); font-size: 12.5px; font-weight: 500; }
        .nav a:hover { background: rgba(255, 255, 255, .04); color: #fff; }
        .nav a.on { background: var(--goldbg); color: #fff; font-weight: 600; box-shadow: inset 2px 0 0 var(--brand); }
        .nav a.on .ico { color: var(--brand); }
        .nav-label { font-size: 11px; letter-spacing: .09em; text-transform: uppercase; color: var(--mu); padding: 18px 11px 6px; font-weight: 600; }
        .side-foot { margin-top: auto; padding-top: 14px; border-top: 1px solid var(--line2); }
        .me { display: flex; align-items: center; gap: 10px; padding: 10px 8px 0; font-size: 13px; }
        .me .av { width: 28px; height: 28px; border-radius: 50%; background: var(--goldbg); color: var(--gold); display: grid; place-items: center; font-weight: 700; flex-shrink: 0; }
        .me .who { flex: 1; min-width: 0; line-height: 1.3; } .me .who small { color: var(--mu); font-size: 11.5px; }
        .me a { color: var(--mu); display: grid; place-items: center; padding: 6px; border-radius: 8px; } .me a:hover { background: rgba(255, 255, 255, .04); color: var(--bad); }
        .main { padding: 20px 26px 34px; max-width: 1140px; width: 100%; }
        .top, .bnav, .install { display: none; }
        /* HP: seperti aplikasi — bilah atas ringkas + menu bawah, sidebar disembunyikan */
        @media (max-width: 860px) {
            .shell { grid-template-columns: 1fr; }
            .side { display: none; }
            .top { display: flex; align-items: center; gap: 10px; padding: calc(10px + env(safe-area-inset-top)) 16px 10px; border-bottom: 1px solid var(--line); position: sticky; top: 0; background: rgba(11, 14, 20, .92); backdrop-filter: blur(10px); z-index: 10; }
            .top img { width: 24px; height: 24px; }
            .top b { flex: 1; font-size: 14px; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .top a.out { color: var(--mu); display: grid; place-items: center; padding: 4px; }
            .install.show { display: inline-flex; }
            .main { padding: 16px 14px calc(80px + env(safe-area-inset-bottom)); }
            .bnav { display: grid; grid-template-columns: repeat(6, 1fr); position: fixed; left: 0; right: 0; bottom: 0; z-index: 15; background: rgba(14, 18, 26, .96); backdrop-filter: blur(10px); border-top: 1px solid var(--line); padding: 6px 4px calc(6px + env(safe-area-inset-bottom)); }
            .bnav a { display: flex; flex-direction: column; align-items: center; gap: 3px; color: var(--mu); font-size: 10.5px; font-weight: 500; padding: 4px 0; border-radius: 8px; }
            .bnav a .ico { width: 20px; height: 20px; }
            .bnav a.on { color: var(--brand); }
            .bnav a.on .ico { filter: drop-shadow(0 0 6px rgba(255, 106, 26, .45)); }
            table.stack thead { display: none; }
            table.stack tr[hidden] { display: none; }
            table.stack td { display: block; border: 0; padding: 4px 0; }
            table.stack tr { display: block; border-top: 1px solid var(--line2); padding: 10px 0; }
            tbody tr:hover td { background: none; }
        }
    </style>
</head>
<?php
    }

    public static function guestStart(string $title): void
    {
        self::head($title);
        echo '<body><div class="guest"><div class="box"><div class="brand"><img class="mark" src="/logo.png" alt=""><b>ADF<em>system</em></b><span>Console · pusat kendali</span></div>';
    }

    public static function guestEnd(): void
    {
        echo '</div></div><script>if ("serviceWorker" in navigator) { navigator.serviceWorker.register("/sw.js").catch(function () {}); }</script></body></html>';
    }

    public static function start(string $title, string $active, array $user): void
    {
        self::head($title);
        echo '<body><div class="shell" id="shell">';
        echo '<aside class="side"><a class="brand" href="/"><img class="mark" src="/logo.png" alt=""><div><b>ADF<em>system</em></b><span>Console · ' . e(Secrets::get('SERVER_NAME', 'ADF Platform')) . '</span></div></a><nav class="nav">';
        foreach (self::navFor($user) as $n) {
            if (is_string($n)) {
                echo '<div class="nav-label">' . e($n) . '</div>';
                continue;
            }
            [$key, $label, $icon, $url] = $n;
            echo '<a class="' . ($key === $active ? 'on' : '') . '" href="' . e($url) . '">' . self::icon($icon) . e($label) . '</a>';
        }
        echo '</nav><div class="side-foot"><nav class="nav">'
            . '<a class="' . ($active === 'account' ? 'on' : '') . '" href="/account.php">' . self::icon('key') . 'Ubah password</a></nav>'
            . '<div class="me"><span class="av">' . e(mb_strtoupper(mb_substr((string)$user['username'], 0, 1))) . '</span>'
            . '<span class="who"><b>' . e($user['username']) . '</b><br><small>' . e($user['role'] === 'admin' ? 'Admin' : 'Staf ADF') . '</small></span>'
            . '<a href="/logout.php" title="Keluar" aria-label="Keluar">' . self::icon('out') . '</a></div></div></aside>';
        self::$active = $active;
        echo '<div><div class="top"><a href="/"><img src="/logo.png" alt=""></a><b>' . e($title) . '</b>'
            . '<button type="button" class="btn sm install" id="installBtn">Pasang aplikasi</button>'
            . '<a class="out" href="/logout.php" title="Keluar" aria-label="Keluar">' . self::icon('out') . '</a></div><main class="main">';
        if ($f = flash()) {
            echo '<div class="alert ' . ($f['type'] === 'ok' ? 'ok' : 'err') . '">' . e($f['msg']) . '</div>';
        }
    }

    public static function end(): void
    {
        // Menu bawah (HP): 4 menu yang paling sering + Lainnya (semua menu di settings.php)
        echo '</main></div></div><nav class="bnav" style="grid-template-columns:repeat(' . (count(self::BNAV) + 1) . ',1fr)">';
        $inBnav = array_column(self::BNAV, 0);
        foreach (self::BNAV as [$key, $label, $icon, $url]) {
            echo '<a class="' . ($key === self::$active ? 'on' : '') . '" href="' . e($url) . '">' . self::icon($icon) . e($label) . '</a>';
        }
        echo '<a class="' . (!in_array(self::$active, $inBnav, true) ? 'on' : '') . '" href="/settings.php">' . self::icon('gear') . 'Lainnya</a></nav>';
        echo <<<'JS'
<script>
// Aplikasi (PWA): daftarkan service worker + tombol "Pasang aplikasi"
if ('serviceWorker' in navigator) { navigator.serviceWorker.register('/sw.js').catch(function () {}); }
(function () {
    var btn = document.getElementById('installBtn'), prompt = null;
    var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
    var ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
    if (!btn || standalone) return;
    window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); prompt = e; btn.classList.add('show'); });
    if (ios) btn.classList.add('show'); // Safari tidak punya tombol otomatis → tampilkan petunjuk
    btn.addEventListener('click', function () {
        if (prompt) { prompt.prompt(); prompt.userChoice.finally(function () { prompt = null; btn.classList.remove('show'); }); return; }
        alert('Di iPhone: ketuk tombol Bagikan (kotak dengan panah ke atas) di Safari, lalu pilih "Tambah ke Layar Utama".');
    });
})();
</script></body></html>
JS;
    }
}
