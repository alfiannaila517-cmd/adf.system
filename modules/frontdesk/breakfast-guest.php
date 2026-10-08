<?php
/**
 * Guest breakfast portal (public, opened from the WhatsApp link).
 * Data: api/breakfast-guest-portal.php?action=get_link / submit_link.
 * Allowance: 1 pax = 1 main course + 1 juice + 1 coffee or tea. Only extra main courses are charged
 * (1 extra breakfast each), and every extra breakfast adds 1 juice + 1 coffee/tea. Drinks above that are blocked.
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';

$previewLogoUrl = '';
$hotelName = '';
$foPhone = '081222228590';
try {
    $db = Database::getInstance();
    $rows = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('breakfast_portal_logo_path', 'company_name', 'company_phone')") ?: [];
    $set = [];
    foreach ($rows as $r) $set[$r['setting_key']] = (string)$r['setting_value'];
    $logo = $set['breakfast_portal_logo_path'] ?? '';
    if ($logo !== '') {
        $previewLogoUrl = (strpos($logo, 'http') === 0) ? $logo : rtrim(BASE_URL, '/') . '/' . ltrim($logo, '/');
    }
    $hotelName = trim($set['company_name'] ?? '');
    if (trim($set['company_phone'] ?? '') !== '') $foPhone = trim($set['company_phone']);
} catch (Throwable $e) {
}
if ($hotelName === '') $hotelName = defined('BUSINESS_NAME') ? BUSINESS_NAME : 'Hotel';

$token = trim((string)($_GET['t'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Breakfast Selection · <?php echo htmlspecialchars($hotelName); ?></title>
    <meta name="theme-color" content="#0f2747">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Breakfast Selection · <?php echo htmlspecialchars($hotelName); ?>">
    <meta property="og:description" content="Choose your breakfast for tomorrow morning.">
    <?php if ($previewLogoUrl !== ''): ?>
        <meta property="og:image" content="<?php echo htmlspecialchars($previewLogoUrl); ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Cormorant+Garamond:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0f2747;
            --navy-2: #1d3f6e;
            --gold: #b08d57;
            --gold-soft: #f6efe3;
            --ink: #1f2937;
            --muted: #6b7280;
            --line: #e7e2d8;
            --bg: #faf7f2;
            --card: #ffffff;
            --green: #047857;
            --red: #b91c1c;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--ink);
            font-size: 14px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
            padding-bottom: 96px;
        }

        .wrap { max-width: 560px; margin: 0 auto; padding: 0 14px; }

        /* Header */
        .hero {
            background: linear-gradient(160deg, var(--navy) 0%, var(--navy-2) 100%);
            color: #fff;
            padding: 22px 0 54px;
            position: relative;
        }

        .hero::after {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
        }

        .hero-top { display: flex; align-items: center; gap: 12px; }

        .hero-logo {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            object-fit: cover;
            background: #fff;
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.25);
        }

        .hero-hotel { font-size: 11px; letter-spacing: 0.16em; text-transform: uppercase; color: #d9c39c; }
        .hero h1 { font-family: 'Cormorant Garamond', serif; font-weight: 700; font-size: 27px; line-height: 1.1; }

        /* Guest card */
        .guest {
            margin-top: -38px;
            position: relative;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px 16px;
            box-shadow: 0 14px 30px -18px rgba(15, 39, 71, 0.45);
        }

        .guest-name { font-weight: 700; font-size: 16px; color: var(--navy); }
        .guest-sub { font-size: 12px; color: var(--muted); margin-top: 2px; }

        .allow { display: flex; gap: 8px; margin-top: 12px; }

        .allow div {
            flex: 1;
            min-width: 0;
            padding: 8px 10px;
            border-radius: 10px;
            background: var(--gold-soft);
            text-align: center;
        }

        .allow b { display: block; font-size: 17px; color: var(--navy); }
        .allow span { font-size: 10.5px; letter-spacing: 0.06em; text-transform: uppercase; color: #8a6d3b; }

        .note-bar {
            margin-top: 10px;
            font-size: 12px;
            color: var(--muted);
        }

        /* Kartu tamu ringkas */
        .guest { padding: 12px 14px; }
        .g-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .g-who { min-width: 0; }
        .guest-name { font-size: 15px; }
        .guest-sub { font-size: 11.5px; }
        .g-hours {
            flex-shrink: 0;
            text-align: right;
            padding: 5px 9px;
            border-radius: 9px;
            background: #eef2f8;
            line-height: 1.15;
        }
        .g-hours b { display: block; font-size: 12.5px; color: var(--navy); }
        .g-hours span { font-size: 9.5px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); }
        .allow-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 10px; }
        .ac {
            padding: 3px 9px;
            border-radius: 999px;
            background: var(--gold-soft);
            font-size: 11.5px;
            color: #8a6d3b;
            white-space: nowrap;
        }
        .ac b { color: var(--navy); font-size: 12px; }
        .ac.kid { background: #ecfdf5; color: var(--green); }
        .ac.kid b { color: var(--green); }
        .g-rules { margin: 9px 0 0; padding-left: 16px; font-size: 11.5px; line-height: 1.5; color: var(--muted); }
        .g-spot {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            margin-top: 10px;
            padding: 8px 12px;
            border-radius: 11px;
            border: 1px dashed #cdbb98;
            background: #fffdf8;
            font-family: inherit;
            text-align: left;
            cursor: pointer;
        }
        .g-spot > span:first-child { font-size: 17px; }
        .g-spot span:last-child { font-size: 11.5px; color: var(--muted); line-height: 1.35; }
        .g-spot b { display: block; font-size: 12.5px; color: var(--navy); }
        /* Popup informasi (Extra Breakfast, konfirmasi) */
        .mdl {
            position: fixed;
            inset: 0;
            z-index: 60;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(15, 39, 71, 0.55);
            backdrop-filter: blur(3px);
            animation: mdlFade 0.18s ease;
        }
        .mdl.hidden { display: none; }
        .mdl-box {
            width: 100%;
            max-width: 380px;
            padding: 22px 20px 18px;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 24px 60px rgba(15, 39, 71, 0.35);
            text-align: center;
            animation: mdlUp 0.22s ease;
        }
        .mdl-ico {
            width: 52px;
            height: 52px;
            margin: 0 auto 10px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            background: var(--gold-soft);
            border: 1px solid #ead9b9;
        }
        .mdl-box h3 { font-family: 'Cormorant Garamond', Georgia, serif; font-size: 22px; color: var(--navy); margin-bottom: 6px; }
        .mdl-text { font-size: 13px; color: var(--muted); line-height: 1.5; }
        .mdl-text b { color: var(--ink); }
        .mdl-price {
            margin: 14px 0 4px;
            padding: 12px 14px;
            border-radius: 12px;
            background: var(--bg);
            border: 1px solid var(--line);
            text-align: left;
        }
        .mdl-price .row { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; }
        .mdl-price .row span { font-size: 12.5px; color: var(--muted); }
        .mdl-price .row b { font-size: 17px; color: var(--navy); white-space: nowrap; }
        .mdl-price ul { margin: 8px 0 0; padding-left: 16px; font-size: 12px; color: var(--muted); line-height: 1.55; }
        .mdl-tip {
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            font-size: 12.5px;
            color: #065f46;
            text-align: left;
            line-height: 1.45;
        }
        .mdl-acts { display: grid; gap: 8px; margin-top: 16px; }
        .mdl-acts button {
            padding: 12px 14px;
            border-radius: 12px;
            border: 0;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .mdl-acts .p { background: var(--navy); color: #fff; }
        .mdl-acts .k { background: var(--green); color: #fff; }
        .mdl-acts .g { background: transparent; color: var(--muted); font-weight: 600; padding: 8px; }
        .sec.flash { animation: secFlash 1.6s ease; border-radius: 14px; }
        @keyframes secFlash { 0%, 60% { box-shadow: 0 0 0 3px rgba(4, 120, 87, 0.35); } 100% { box-shadow: 0 0 0 0 rgba(4, 120, 87, 0); } }
        @keyframes mdlFade { from { opacity: 0; } }
        @keyframes mdlUp { from { opacity: 0; transform: translateY(14px) scale(0.98); } }
        /* Langkah: Food → Drinks → Details */
        .steps {
            position: sticky;
            top: 0;
            z-index: 15;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            margin: 14px -2px 4px;
            padding: 8px 2px;
            background: var(--bg);
        }
        .steps button {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 36px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--muted);
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
        }
        .steps button i {
            width: 19px;
            height: 19px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-style: normal;
            font-size: 11px;
            background: #eef0f3;
            color: var(--muted);
        }
        .steps button.on { background: var(--navy); border-color: var(--navy); color: #fff; }
        .steps button.on i { background: var(--gold); color: #fff; }
        .steps button.done i { background: var(--green); color: #fff; }
        .stp.hidden { display: none; }
        .stp-hint { margin: 2px 2px 0; font-size: 12px; color: var(--muted); }
        .seg.two { grid-template-columns: repeat(2, 1fr); }
        .input[readonly] { background: #f6f4ef; color: var(--navy); font-weight: 600; cursor: default; }
        /* Notices */
        .notice {
            margin: 14px 0 0;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 13px;
            line-height: 1.5;
            background: #eef2f8;
            color: var(--navy);
        }

        .notice.err { background: #fef2f2; color: var(--red); }
        .notice.ok { background: #ecfdf5; color: var(--green); }

        /* Sections */
        .sec { margin-top: 18px; }

        .sec-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            margin: 0 2px 8px;
        }

        .sec-head h2 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 21px;
            font-weight: 700;
            color: var(--navy);
        }

        .sec-count { font-size: 12px; color: var(--muted); }
        .sec-count b { color: var(--navy); }
        .sec-count.over b { color: var(--red); }

        .chips { display: flex; gap: 6px; overflow-x: auto; padding: 0 2px 8px; scrollbar-width: none; }
        .chips::-webkit-scrollbar { display: none; }

        .chip {
            flex-shrink: 0;
            padding: 5px 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: var(--card);
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
        }

        .chip.on { background: var(--navy); border-color: var(--navy); color: #fff; }

        .list {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 14px;
            overflow: hidden;
        }

        .item {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 10px 12px;
            border-top: 1px solid var(--line);
        }

        .item:first-child { border-top: 0; }
        .item.sel { background: #fbf8f2; }

        .thumb {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            object-fit: cover;
            flex-shrink: 0;
            background: var(--gold-soft);
            display: grid;
            place-items: center;
            font-size: 18px;
        }

        .info { flex: 1; min-width: 0; }

        .name {
            font-weight: 600;
            font-size: 13.5px;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .tag {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: 0.05em;
            padding: 1px 6px;
            border-radius: 999px;
            color: #fff;
        }

        .tag.hot { background: #dc2626; }
        .tag.ice { background: #0284c7; }
        .tag.paid { background: #fff7ed; color: #b45309; }

        .desc {
            font-size: 11.5px;
            color: var(--muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .add-note {
            margin-top: 2px;
            border: 0;
            background: none;
            padding: 0;
            font-family: inherit;
            font-size: 11px;
            font-weight: 600;
            color: var(--gold);
            cursor: pointer;
        }

        .note-input {
            width: 100%;
            margin-top: 5px;
            padding: 6px 9px;
            border-radius: 8px;
            border: 1px solid var(--line);
            font-family: inherit;
            font-size: 12px;
            background: #fff;
        }

        .qty { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }

        .qbtn {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--navy);
            font-size: 17px;
            line-height: 1;
            cursor: pointer;
        }

        .qbtn.plus { background: var(--navy); border-color: var(--navy); color: #fff; }
        .qbtn:disabled { opacity: 0.35; cursor: default; }
        .qnum { min-width: 18px; text-align: center; font-weight: 700; font-size: 14px; color: var(--navy); }

        /* Extra banner */
        .extra {
            display: none;
            margin-top: 14px;
            padding: 11px 14px;
            border-radius: 12px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            font-size: 12.5px;
            line-height: 1.5;
        }

        .extra.show { display: block; }
        .extra b { color: #7c2d12; }

        /* Details */
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 14px;
        }

        .label {
            display: block;
            margin: 12px 0 6px;
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .label:first-child { margin-top: 0; }

        .seg { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }

        .seg button {
            height: 38px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--ink);
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
        }

        .seg button.on { background: var(--navy); border-color: var(--navy); color: #fff; }

        .times { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
        .times button { height: 34px; font-size: 12.5px; }

        .input {
            width: 100%;
            height: 40px;
            padding: 0 12px;
            border-radius: 10px;
            border: 1px solid var(--line);
            font-family: inherit;
            font-size: 13.5px;
            background: #fff;
        }

        textarea.input { height: 64px; padding: 9px 12px; resize: vertical; }

        .onspot {
            display: block;
            width: 100%;
            margin-top: 14px;
            padding: 11px;
            border-radius: 12px;
            border: 1px dashed #cdbb98;
            background: transparent;
            color: #8a6d3b;
            font-family: inherit;
            font-size: 12.5px;
            line-height: 1.4;
            cursor: pointer;
        }

        .onspot b { display: block; color: var(--navy); font-size: 13px; }

        /* Sticky bar */
        .bar {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 20;
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(8px);
            border-top: 1px solid var(--line);
            padding: 10px 0 calc(10px + env(safe-area-inset-bottom));
        }

        .bar .wrap { display: flex; align-items: center; gap: 10px; }
        .bar-sum { flex: 1; min-width: 0; font-size: 12px; color: var(--muted); }
        .bar-sum b { display: block; font-size: 14px; color: var(--navy); }

        .btn {
            height: 44px;
            padding: 0 20px;
            border-radius: 12px;
            border: 0;
            background: var(--navy);
            color: #fff;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn:disabled { opacity: 0.5; cursor: wait; }

        .fo {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin: 18px auto 0;
            font-size: 12px;
            color: var(--muted);
            text-decoration: none;
        }

        .fo b { color: var(--green); }
        .center { text-align: center; }
        .hidden { display: none !important; }

        /* Summary (submitted) */
        .sum-row { display: flex; justify-content: space-between; gap: 10px; padding: 8px 0; border-top: 1px solid var(--line); font-size: 13px; }
        .sum-row:first-of-type { border-top: 0; }
        .sum-row span { color: var(--muted); }
        .sum-row b { text-align: right; }

        .loader { padding: 60px 0; text-align: center; color: var(--muted); }

        /* Sent confirmation */
        .sent-ov { position: fixed; inset: 0; z-index: 1000; display: none; align-items: center; justify-content: center; padding: 22px; background: rgba(15, 23, 42, .55); backdrop-filter: blur(4px); }
        .sent-ov.open { display: flex; }
        .sent-card { box-sizing: border-box; width: 100%; max-width: 380px; padding: 30px 26px 22px; text-align: center; border-radius: 22px; background: #fff; box-shadow: 0 30px 80px rgba(15, 23, 42, .35); animation: sentIn .35s cubic-bezier(.2, .9, .3, 1.2); }
        @keyframes sentIn { from { opacity: 0; transform: translateY(14px) scale(.96); } }
        .sent-mark { width: 66px; height: 66px; margin: 0 auto 16px; border-radius: 50%; display: grid; place-items: center; background: linear-gradient(135deg, #059669, #10b981); box-shadow: 0 12px 28px -10px rgba(5, 150, 105, .7); }
        .sent-mark svg { width: 32px; height: 32px; stroke: #fff; fill: none; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 30; stroke-dashoffset: 30; animation: sentDraw .5s .25s ease forwards; }
        @keyframes sentDraw { to { stroke-dashoffset: 0; } }
        .sent-eyebrow { font-size: 11px; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; color: #059669; }
        .sent-card h2 { margin: 6px 0 10px; font-family: Georgia, "Times New Roman", serif; font-size: 24px; font-weight: 600; color: #0f172a; }
        .sent-card p { margin: 0 0 8px; font-size: 14px; line-height: 1.6; color: #475569; }
        .sent-meta { margin: 14px 0 4px; padding: 10px 12px; border-radius: 12px; background: #f1f5f9; font-size: 12.5px; color: #334155; }
        .sent-meta b { color: #0f172a; }
        .sent-btn { width: 100%; margin-top: 16px; height: 46px; border: 0; border-radius: 13px; background: linear-gradient(135deg, #1e3a8a, #2563eb); color: #fff; font-size: 14px; font-weight: 700; letter-spacing: .02em; cursor: pointer; font-family: inherit; }
    </style>
</head>

<body>
    <header class="hero">
        <div class="wrap hero-top">
            <?php if ($previewLogoUrl !== ''): ?><img class="hero-logo" src="<?php echo htmlspecialchars($previewLogoUrl); ?>" alt=""><?php endif; ?>
            <div>
                <div class="hero-hotel"><?php echo htmlspecialchars($hotelName); ?></div>
                <h1>Breakfast Selection</h1>
            </div>
        </div>
    </header>

    <div class="sent-ov" id="sentOv" role="dialog" aria-modal="true" aria-labelledby="sentTitle">
        <div class="sent-card">
            <div class="sent-mark"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
            <div class="sent-eyebrow">Breakfast Selection</div>
            <h2 id="sentTitle">Selection Sent</h2>
            <p id="sentMsg"></p>
            <div class="sent-meta" id="sentMeta"></div>
            <p style="font-size:12.5px;color:#64748b;margin-top:10px">Should you wish to make any changes, our Front Office team will gladly assist you.</p>
            <button type="button" class="sent-btn" id="sentBtn">Done</button>
        </div>
    </div>

    <main class="wrap">
        <div class="guest" id="guestCard">
            <div class="loader" id="loader">Loading your breakfast menu…</div>
        </div>
        <div id="notice"></div>

        <div id="pickArea" class="hidden">
            <div class="steps" id="steps">
                <button type="button" data-s="0" class="on"><i>1</i>Food</button>
                <button type="button" data-s="1"><i>2</i>Drinks</button>
                <button type="button" data-s="2"><i>3</i>Details</button>
            </div>

            <div class="stp" data-stp="0">
            <section class="sec" id="mainSec">
                <div class="sec-head">
                    <h2>Main Course</h2>
                    <div class="sec-count" id="mainCount"></div>
                </div>
                <div class="chips" id="mainChips"></div>
                <div class="list" id="mainList"></div>
            </section>

            <section class="sec hidden" id="kidSec">
                <div class="sec-head">
                    <h2>For Kids <small style="font-family:Inter,sans-serif;font-size:11px;color:#047857;font-weight:600">under 7 · free</small></h2>
                    <div class="sec-count" id="kidCount"></div>
                </div>
                <div class="list" id="kidList"></div>
            </section>

            <div class="extra" id="extraBanner"></div>
            </div>

            <div class="stp hidden" data-stp="1">
            <p class="stp-hint" id="drinkHint"></p>
            <section class="sec" id="juiceSec">
                <div class="sec-head">
                    <h2>Fresh Juice</h2>
                    <div class="sec-count" id="juiceCount"></div>
                </div>
                <div class="list" id="juiceList"></div>
            </section>

            <section class="sec" id="coffeeSec">
                <div class="sec-head">
                    <h2>Coffee &amp; Tea</h2>
                    <div class="sec-count" id="coffeeCount"></div>
                </div>
                <div class="list" id="coffeeList"></div>
            </section>

            </div>

            <div class="stp hidden" data-stp="2">
            <section class="sec">
                <div class="sec-head">
                    <h2>Serving Details</h2>
                </div>
                <div class="card">
                    <span class="label">Service</span>
                    <div class="seg two" id="serviceSeg">
                        <button type="button" data-v="restaurant" class="on">Restaurant</button>
                        <button type="button" data-v="take_away">Take Away</button>
                    </div>
                    <span class="label">Time</span>
                    <div class="seg times" id="timeSeg"></div>
                    <div style="font-size:11px;color:var(--muted);margin-top:6px">Breakfast is served from 07:00 to 10:00.</div>
                    <span class="label" id="locLabel">Location</span>
                    <input class="input" id="location" maxlength="120" value="Main Restaurant" readonly>
                    <span class="label">Notes (optional)</span>
                    <textarea class="input" id="notes" maxlength="300" placeholder="Allergies, no spicy, egg well done…"></textarea>
                </div>
                <button type="button" class="onspot" id="btnOnSpot">
                    <b>Prefer to order at the restaurant?</b>
                    Choose “On the spot” and our team will take your order in the morning.
                </button>
            </section>
            </div>
        </div>

        <div class="center">
            <a class="fo" id="foLink" target="_blank" rel="noopener">Need help? <b>Chat with Front Office</b></a>
        </div>
    </main>

    <div class="mdl hidden" id="mdl" role="dialog" aria-modal="true" aria-labelledby="mdlTitle">
        <div class="mdl-box">
            <div class="mdl-ico" id="mdlIco"></div>
            <h3 id="mdlTitle"></h3>
            <div id="mdlBody"></div>
            <div class="mdl-acts" id="mdlActs"></div>
        </div>
    </div>
    <div class="bar hidden" id="bar">
        <div class="wrap">
            <div class="bar-sum" id="barSum"></div>
            <button type="button" class="btn" id="btnSubmit">Next: Drinks →</button>
        </div>
    </div>

    <script>
        (function() {
            var TOKEN = <?php echo json_encode($token); ?>;
            var API = <?php echo json_encode(rtrim(BASE_URL, '/') . '/api/breakfast-guest-portal.php'); ?>;
            var BASE = <?php echo json_encode(rtrim(BASE_URL, '/')); ?>;
            var FO_PHONE = <?php echo json_encode($foPhone); ?>;
            var data = null;
            var qty = { main: {}, drink: {}, child: {} };
            var notes = { main: {}, drink: {}, child: {} };
            var service = 'restaurant';
            var time = '07:00';
            var mainFilter = 'all';

            var $ = function(id) { return document.getElementById(id); };
            var esc = function(s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            };
            var rp = function(n) { return 'Rp ' + Math.round(n || 0).toLocaleString('id-ID'); };
            var cap = function(s) { s = String(s || ''); return s.charAt(0).toUpperCase() + s.slice(1); };

            function waNum(p) {
                p = String(p || '').replace(/\D+/g, '');
                if (p.indexOf('0') === 0) return '62' + p.slice(1);
                return p;
            }
            $('foLink').href = 'https://wa.me/' + waNum(FO_PHONE) + '?text=' + encodeURIComponent('Hello Front Office, I need help with my breakfast selection.');

            function notice(text, kind) {
                $('notice').innerHTML = text ? '<div class="notice ' + (kind || '') + '">' + text + '</div>' : '';
            }

            function fmtDate(v) {
                if (!v) return '';
                var d = new Date(v + 'T00:00:00');
                var next = new Date(d.getTime() + 86400000); // breakfast is served the morning after
                return next.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
            }

            function total(group) {
                return Object.keys(qty[group]).reduce(function(s, k) { return s + (qty[group][k] || 0); }, 0);
            }

            // Jumlah porsi yang memakai jatah (menu berbayar ditagih terpisah).
            function counted(group, list) {
                return list.reduce(function(s, m) {
                    var free = String(m.is_free) === '1' || m.is_free === true || m.is_free === 1 || !(parseFloat(m.price || 0) > 0);
                    return s + (free ? (qty[group][String(m.id)] || 0) : 0);
                }, 0);
            }

            // Popup: opts = { icon, title, body (html), actions: [{ label, cls, fn }] }
            function modal(opts) {
                $('mdlIco').textContent = opts.icon || 'ℹ️';
                $('mdlTitle').textContent = opts.title || '';
                $('mdlBody').innerHTML = opts.body || '';
                $('mdlActs').innerHTML = '';
                (opts.actions || []).forEach(function(a) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = a.cls || 'g';
                    b.textContent = a.label;
                    b.addEventListener('click', function() {
                        closeModal();
                        if (a.fn) a.fn();
                    });
                    $('mdlActs').appendChild(b);
                });
                $('mdl').classList.remove('hidden');
            }

            function closeModal() { $('mdl').classList.add('hidden'); }

            function isFree(m) {
                return String(m.is_free) === '1' || m.is_free === true || m.is_free === 1 || !(parseFloat(m.price || 0) > 0);
            }

            function goKids() {
                var sec = $('kidSec');
                if (!sec || sec.classList.contains('hidden')) return;
                sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
                sec.classList.remove('flash');
                void sec.offsetWidth;
                sec.classList.add('flash');
            }

            // Popup saat menambah makanan di luar jatah (Extra Breakfast berbayar).
            function askExtra(onAdd) {
                var pax = parseInt(data.max_main || 0, 10);
                var kids = parseInt(data.max_child || 0, 10);
                var price = parseFloat(data.extra_package_price || 82500);
                var kidsLeft = kids - total('child');
                var showKids = kidsLeft > 0 && !$('kidSec').classList.contains('hidden');
                var acts = [];
                if (showKids) acts.push({ label: 'Choose Kids Menu (free)', cls: 'k', fn: goKids });
                acts.push({ label: 'Add Extra Breakfast · ' + rp(price), cls: 'p', fn: onAdd });
                acts.push({ label: 'Cancel', cls: 'g' });
                modal({
                    icon: '🍳',
                    title: 'Extra Breakfast',
                    body: '<div class="mdl-text">Your stay includes <b>' + pax + ' main course' + (pax === 1 ? '' : 's') + '</b> (1 per guest), and all of them are already chosen.</div>' +
                        '<div class="mdl-price"><div class="row"><span>1 extra breakfast</span><b>' + rp(price) + '</b></div>' +
                        '<ul><li>1 main course of your choice</li><li>+1 fresh juice and +1 coffee or tea</li><li>Added to your bill, payable at Front Office</li></ul></div>' +
                        (showKids ? '<div class="mdl-tip">🧒 <b>Ordering for your child?</b> Kids under 7 eat free — ' + kidsLeft + ' kids portion' + (kidsLeft === 1 ? '' : 's') + ' (pancake / waffle + 1 drink) still available in the <b>For Kids</b> menu.</div>' : ''),
                    actions: acts
                });
            }

            function renderGuest() {
                var rooms = (data.room_number || []).join(', ');
                var pax = parseInt(data.max_main || 0, 10);
                var kids = parseInt(data.max_child || 0, 10);
                var chip = function(n, label) { return '<span class="ac"><b>' + n + '</b> ' + label + '</span>'; };
                $('guestCard').innerHTML =
                    '<div class="g-top">' +
                    '<div class="g-who"><div class="guest-name">' + esc(data.guest_name) + '</div>' +
                    '<div class="guest-sub">Room ' + esc(rooms || '-') + ' · ' + esc(fmtDate(data.breakfast_date)) + '</div></div>' +
                    '<div class="g-hours"><b>07:00–10:00</b><span>Breakfast</span></div>' +
                    '</div>' +
                    '<div class="allow-chips">' +
                    chip(pax, pax === 1 ? 'guest' : 'guests') + chip(pax, 'main') + chip(pax, 'juice') + chip(pax, 'coffee/tea') +
                    (kids ? '<span class="ac kid"><b>' + kids + '</b> kid' + (kids === 1 ? '' : 's') + ' · free</span>' : '') +
                    '</div>' +
                    '<ul class="g-rules">' +
                    '<li>Per guest: 1 main course, 1 fresh juice and 1 coffee or tea.</li>' +
                    (kids ? '<li>Kids under 7: 1 pancake or waffle + 1 drink, complimentary.</li>' : '') +
                    '<li>Extra main course ' + rp(data.extra_package_price || 82500) + ', includes 1 more juice and coffee/tea.</li>' +
                    '</ul>' +
                    ((data.is_locked || data.auto_on_the_spot_midnight) ? '' :
                        '<button type="button" class="g-spot" id="btnSpotTop"><span>🍽️</span><span><b>Order on the spot</b>Choose directly at the restaurant tomorrow</span></button>');
                var top = $('btnSpotTop');
                if (top) top.addEventListener('click', function() { $('btnOnSpot').click(); });
            }

            function itemRow(m, group) {
                var id = String(m.id);
                var q = qty[group][id] || 0;
                var img = (m.image_url || '').trim();
                var src = img ? (/^https?:\/\//i.test(img) ? img : BASE + '/' + img.replace(/^\/+/, '')) : '';
                var free = String(m.is_free) === '1' || m.is_free === true || m.is_free === 1;
                var price = parseFloat(m.price || 0);
                var tags = '';
                if (m.serve_temp === 'hot') tags += '<span class="tag hot">HOT</span>';
                if (m.serve_temp === 'ice') tags += '<span class="tag ice">ICE</span>';
                if (!free && price > 0) tags += '<span class="tag paid">' + rp(price) + '</span>';
                var note = notes[group][id] || '';
                return '<div class="item' + (q ? ' sel' : '') + '" data-g="' + group + '" data-id="' + id + '">' +
                    (src ? '<img class="thumb" src="' + esc(src) + '" alt="" loading="lazy" onerror="this.outerHTML=\'<div class=thumb>🍽️</div>\'">' : '<div class="thumb">' + (group === 'drink' ? '☕' : '🍳') + '</div>') +
                    '<div class="info"><div class="name">' + esc(m.menu_name) + tags + '</div>' +
                    (m.description ? '<div class="desc">' + esc(m.description) + '</div>' : '') +
                    (q ? (note || m._noteOpen ? '<input class="note-input" data-note placeholder="Note for the kitchen" maxlength="160" value="' + esc(note) + '">' : '<button type="button" class="add-note" data-addnote>+ Add note</button>') : '') +
                    '</div>' +
                    '<div class="qty">' +
                    (q ? '<button type="button" class="qbtn" data-step="-1" aria-label="Less">−</button><span class="qnum">' + q + '</span>' : '') +
                    '<button type="button" class="qbtn plus" data-step="1" aria-label="Add">+</button>' +
                    '</div></div>';
            }

            var menus = { main: [], drink: [], juice: [], coffee: [], child: [] };

            function renderLists() {
                var mains = menus.main.filter(function(m) { return mainFilter === 'all' || (m.category || '') === mainFilter; });
                $('mainList').innerHTML = mains.map(function(m) { return itemRow(m, 'main'); }).join('') || '<div class="item"><div class="info desc">No menu in this category</div></div>';
                $('juiceList').innerHTML = menus.juice.map(function(m) { return itemRow(m, 'drink'); }).join('');
                $('coffeeList').innerHTML = menus.coffee.map(function(m) { return itemRow(m, 'drink'); }).join('');
                $('kidList').innerHTML = menus.child.map(function(m) { return itemRow(m, 'child'); }).join('');
                $('juiceSec').classList.toggle('hidden', !menus.juice.length);
                $('coffeeSec').classList.toggle('hidden', !menus.coffee.length);
                $('kidSec').classList.toggle('hidden', !menus.child.length || !(parseInt(data.max_child || 0, 10) > 0));
                renderSummary();
            }

            function renderChips() {
                var cats = [];
                menus.main.forEach(function(m) { if (m.category && cats.indexOf(m.category) === -1) cats.push(m.category); });
                if (cats.length < 2) { $('mainChips').classList.add('hidden'); return; }
                $('mainChips').innerHTML = ['all'].concat(cats).map(function(c) {
                    return '<button type="button" class="chip' + (c === mainFilter ? ' on' : '') + '" data-cat="' + esc(c) + '">' + (c === 'all' ? 'All' : esc(cap(c))) + '</button>';
                }).join('');
            }

            function renderSummary() {
                var pax = parseInt(data.max_main || 0, 10);
                var tm = counted('main', menus.main), tj = counted('drink', menus.juice), tc = counted('drink', menus.coffee);
                var td = total('drink');
                var extraMeals = Math.max(0, tm - pax);
                var cap = pax + extraMeals; // jatah minuman bertambah per makanan extra
                var kids = parseInt(data.max_child || 0, 10);
                var dCap = cap + kids; // minuman anak boleh jus maupun kopi/teh
                var dFull = (tj + tc) >= (2 * cap + kids);
                $('mainCount').innerHTML = '<b>' + tm + '</b> / ' + pax + ' included';
                $('mainCount').classList.toggle('over', tm > pax);
                [['juiceCount', tj], ['coffeeCount', tc]].forEach(function(p) {
                    $(p[0]).innerHTML = '<b>' + p[1] + '</b> / ' + dCap + ' allowed';
                    $(p[0]).classList.toggle('over', p[1] > dCap);
                });
                // Tombol + minuman gratis terkunci bila jatah jenisnya sudah penuh.
                [['juice', tj], ['coffee', tc]].forEach(function(p) {
                    menus[p[0]].forEach(function(m) {
                        var free = String(m.is_free) === '1' || m.is_free === true || m.is_free === 1 || !(parseFloat(m.price || 0) > 0);
                        var btn = document.querySelector('.item[data-g="drink"][data-id="' + m.id + '"] [data-step="1"]');
                        if (btn && free) btn.disabled = p[1] >= dCap || dFull;
                    });
                });
                // Menu anak: 1 porsi per anak.
                var tk = total('child');
                menus.child.forEach(function(m) {
                    var btn = document.querySelector('.item[data-g="child"][data-id="' + m.id + '"] [data-step="1"]');
                    if (btn) btn.disabled = tk >= kids;
                });
                $('kidCount').innerHTML = '<b>' + total('child') + '</b> / ' + parseInt(data.max_child || 0, 10) + ' free';

                var packs = extraMeals;
                var price = parseFloat(data.extra_package_price || 82500);
                var banner = $('extraBanner');
                if (packs > 0) {
                    banner.innerHTML = '<b>' + packs + ' additional breakfast' + (packs > 1 ? 's' : '') + ' · ' + rp(packs * price) + '</b><br>' +
                        'You selected more main courses than your allowance. Each extra main course is charged ' + rp(price) + ' and adds 1 juice + 1 coffee/tea.';
                    banner.classList.add('show');
                } else {
                    banner.classList.remove('show');
                }
                $('barSum').innerHTML = '<b>' + tm + ' main · ' + td + ' drink' + (td === 1 ? '' : 's') + '</b>' +
                    (packs > 0 ? 'Extra ' + rp(packs * price) : 'Included in your stay');
            }

            function renderTimes() {
                var out = [];
                for (var mins = 7 * 60; mins <= 10 * 60; mins += 30) {
                    var v = String(Math.floor(mins / 60)).padStart(2, '0') + ':' + String(mins % 60).padStart(2, '0');
                    out.push('<button type="button" data-v="' + v + '" class="' + (v === time ? 'on' : '') + '">' + v + '</button>');
                }
                $('timeSeg').innerHTML = out.join('');
            }

            function setService(v) {
                service = v;
                document.querySelectorAll('#serviceSeg button').forEach(function(b) { b.classList.toggle('on', b.dataset.v === v); });
                // Lokasi selalu restoran (take away diambil di restoran).
                $('location').value = 'Main Restaurant';
                $('locLabel').textContent = v === 'take_away' ? 'Pick up at' : 'Location';
            }

            function renderSubmitted() {
                $('pickArea').classList.add('hidden');
                $('bar').classList.add('hidden');
                var spot = parseInt(data.on_the_spot || 0, 10) === 1;
                var rows = [];
                [['main', data.main_menus], ['drink', data.drink_menus], ['child', data.child_menus]].forEach(function(pair) {
                    var ids = (data['selected_' + pair[0] + '_ids'] || []).map(String);
                    var q = data['selected_' + pair[0] + '_qty'] || {};
                    (pair[1] || []).forEach(function(m) {
                        if (ids.indexOf(String(m.id)) > -1) rows.push((q[String(m.id)] || 1) + ' × ' + esc(m.menu_name));
                    });
                });
                var svc = { restaurant: 'Restaurant', room_service: 'Room Service', take_away: 'Take Away' }[data.breakfast_service] || '-';
                notice((spot ? 'You chose to order <b>on the spot</b>. Our restaurant team will take your order in the morning.' :
                    'Your breakfast selection has been sent. We look forward to serving you.') + ' To make changes, please contact Front Office.', 'ok');
                $('notice').innerHTML += '<div class="card" style="margin-top:12px">' +
                    (rows.length ? '<div class="sum-row"><span>Menu</span><b>' + rows.join('<br>') + '</b></div>' : '') +
                    '<div class="sum-row"><span>Time</span><b>' + esc((data.breakfast_time || '').slice(0, 5) || '-') + '</b></div>' +
                    '<div class="sum-row"><span>Service</span><b>' + esc(svc) + '</b></div>' +
                    '<div class="sum-row"><span>Location</span><b>' + esc(data.breakfast_location || '-') + '</b></div></div>';
            }

            async function load() {
                if (!TOKEN) {
                    $('guestCard').innerHTML = '<div class="loader">This link is not valid.</div>';
                    return;
                }
                try {
                    var res = await fetch(API + '?action=get_link&token=' + encodeURIComponent(TOKEN));
                    var json = await res.json();
                    if (!json.success) {
                        $('guestCard').innerHTML = '<div class="loader">' + esc(/kedaluwarsa|expired/i.test(json.message || '') ?
                            'This link has expired. Please contact Front Office for a new link.' : 'This link is not valid. Please contact Front Office.') + '</div>';
                        return;
                    }
                    data = json.data || {};
                    renderGuest();
                    if (data.auto_on_the_spot_midnight) {
                        notice(esc(data.auto_on_the_spot_message_en || 'The selection time has passed. You can order directly at the restaurant in the morning.'));
                        return;
                    }
                    if (data.is_locked) {
                        renderSubmitted();
                        return;
                    }
                    menus.main = data.main_menus || [];
                    menus.drink = data.drink_menus || [];
                    menus.juice = menus.drink.filter(function(m) { return m.drink_kind === 'juice'; });
                    menus.coffee = menus.drink.filter(function(m) { return m.drink_kind !== 'juice'; });
                    menus.child = data.child_menus || [];
                    $('pickArea').classList.remove('hidden');
                    $('bar').classList.remove('hidden');
                    renderChips();
                    renderTimes();
                    renderLists();
                } catch (e) {
                    $('guestCard').innerHTML = '<div class="loader">Could not load the menu. Please check your connection and try again.</div>';
                }
            }

            // Interaksi daftar menu
            $('pickArea').addEventListener('click', function(e) {
                var chip = e.target.closest('[data-cat]');
                if (chip) {
                    mainFilter = chip.dataset.cat;
                    renderChips();
                    renderLists();
                    return;
                }
                var row = e.target.closest('.item[data-id]');
                if (!row) return;
                var g = row.dataset.g, id = row.dataset.id;
                var step = e.target.closest('[data-step]');
                if (step) {
                    var delta = parseInt(step.dataset.step, 10);
                    var apply = function() {
                        var next = Math.max(0, Math.min(20, (qty[g][id] || 0) + delta));
                        if (next) qty[g][id] = next; else { delete qty[g][id]; delete notes[g][id]; }
                        renderLists();
                    };
                    var mm = g === 'main' && delta > 0 ? menus.main.find(function(x) { return String(x.id) === id; }) : null;
                    if (mm && isFree(mm) && counted('main', menus.main) >= parseInt(data.max_main || 0, 10)) {
                        askExtra(apply);
                        return;
                    }
                    apply();
                    return;
                }
                if (e.target.closest('[data-addnote]')) {
                    var m = (menus[g] || []).find(function(x) { return String(x.id) === id; });
                    if (m) m._noteOpen = true;
                    renderLists();
                    var inp = document.querySelector('.item[data-g="' + g + '"][data-id="' + id + '"] [data-note]');
                    if (inp) inp.focus();
                }
            });
            $('pickArea').addEventListener('input', function(e) {
                if (!e.target.matches('[data-note]')) return;
                var row = e.target.closest('.item');
                notes[row.dataset.g][row.dataset.id] = e.target.value;
            });
            $('serviceSeg').addEventListener('click', function(e) {
                var b = e.target.closest('button[data-v]');
                if (b) setService(b.dataset.v);
            });
            $('timeSeg').addEventListener('click', function(e) {
                var b = e.target.closest('button[data-v]');
                if (!b) return;
                time = b.dataset.v;
                renderTimes();
            });

            // Elegant confirmation shown once the selection has been saved
            function showSent(onSpot) {
                var first = String(data.guest_name || '').trim().split(/\s+/)[0] || 'Guest';
                var svcMap = { restaurant: 'Restaurant', room_service: 'Room Service', take_away: 'Take Away' };
                $('sentMsg').innerHTML = onSpot
                    ? 'Thank you, <b>' + esc(first) + '</b>. We have noted that you will order on the spot &mdash; our team will gladly take your order at the restaurant in the morning.'
                    : 'Thank you, <b>' + esc(first) + '</b>. Your breakfast selection has been sent to our kitchen team, and we look forward to welcoming you in the morning.';
                var t = (data.breakfast_time || '').slice(0, 5);
                $('sentMeta').innerHTML = onSpot ? 'Order on the spot &middot; <b>Main Restaurant</b>'
                    : (t ? '<b>' + esc(t) + '</b> &middot; ' : '') + esc(svcMap[data.breakfast_service] || 'Restaurant');
                $('sentOv').classList.add('open');
            }
            $('sentBtn').addEventListener('click', function() { $('sentOv').classList.remove('open'); });
            $('sentOv').addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });

            async function submit(onSpot) {
                var ids = function(g) { return Object.keys(qty[g]).map(Number); };
                if (!onSpot && !ids('main').length && !ids('drink').length && !ids('child').length) {
                    notice('Please choose at least one item, or select “order on the spot”.', 'err');
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                var loc = 'Main Restaurant';
                var body = {
                    action: 'submit_link',
                    token: TOKEN,
                    lang: 'en',
                    selected_main: onSpot ? [] : ids('main'),
                    selected_main_qty: onSpot ? {} : qty.main,
                    selected_main_notes: onSpot ? {} : notes.main,
                    selected_drink: onSpot ? [] : ids('drink'),
                    selected_drink_qty: onSpot ? {} : qty.drink,
                    selected_drink_notes: onSpot ? {} : notes.drink,
                    selected_child: onSpot ? [] : ids('child'),
                    selected_child_qty: onSpot ? {} : qty.child,
                    selected_child_notes: onSpot ? {} : notes.child,
                    breakfast_time: time,
                    service_type: onSpot ? 'restaurant' : service,
                    breakfast_location: onSpot ? 'Main Restaurant' : loc,
                    on_the_spot: onSpot ? 1 : 0,
                    special_requests: (($('notes').value || '').trim() + (onSpot ? ' [ON THE SPOT]' : '')).trim()
                };
                var btn = $('btnSubmit');
                btn.disabled = true;
                btn.textContent = 'Sending…';
                try {
                    var res = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
                    var json = await res.json();
                    if (!json.success) throw new Error(json.message || 'Could not submit your selection.');
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    if (json.demo) {
                        // Tautan contoh: tampilkan hasil seperti sungguhan tanpa menyimpan
                        data.is_locked = true;
                        data.on_the_spot = onSpot ? 1 : 0;
                        data.selected_main_ids = body.selected_main; data.selected_main_qty = body.selected_main_qty;
                        data.selected_drink_ids = body.selected_drink; data.selected_drink_qty = body.selected_drink_qty;
                        data.selected_child_ids = body.selected_child; data.selected_child_qty = body.selected_child_qty;
                        data.breakfast_time = time; data.breakfast_service = body.service_type; data.breakfast_location = body.breakfast_location;
                        renderSubmitted();
                    } else {
                        await load();
                    }
                    showSent(onSpot);
                    var extra = json.data && json.data.extra_total_price;
                    if (extra > 0) {
                        $('notice').insertAdjacentHTML('afterbegin', '<div class="notice">Additional breakfast ' + rp(extra) + ' has been added to your bill. Please settle it at Front Office.</div>');
                    }
                } catch (e) {
                    notice(esc(e.message), 'err');
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    btn.disabled = false;
                    btn.textContent = 'Confirm';
                }
            }

            var stepNow = 0;
            var STEP_BTN = ['Next: Drinks →', 'Next: Details →', 'Confirm'];
            function goStep(n) {
                stepNow = n;
                document.querySelectorAll('.stp').forEach(function(el) { el.classList.toggle('hidden', parseInt(el.dataset.stp, 10) !== n); });
                document.querySelectorAll('#steps button').forEach(function(b) {
                    var s = parseInt(b.dataset.s, 10);
                    b.classList.toggle('on', s === n);
                    b.classList.toggle('done', s < n);
                });
                $('btnSubmit').textContent = STEP_BTN[n];
                if (n === 1) {
                    var cap = parseInt(data.max_main || 0, 10) + Math.max(0, counted('main', menus.main) - parseInt(data.max_main || 0, 10));
                    var kids = parseInt(data.max_child || 0, 10);
                    $('drinkHint').textContent = 'Choose up to ' + (cap + kids) + ' fresh juice and ' + (cap + kids) + ' coffee or tea' +
                        (kids ? ' (including ' + kids + ' for kids)' : '') + ' — all complimentary.';
                }
                var top = $('steps').getBoundingClientRect().top + window.pageYOffset - 6;
                window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
            }
            $('steps').addEventListener('click', function(e) {
                var b = e.target.closest('button[data-s]');
                if (b) goStep(parseInt(b.dataset.s, 10));
            });

            $('btnSubmit').addEventListener('click', function() {
                if (stepNow < 2) { goStep(stepNow + 1); return; }
                var packs = Math.max(0, counted('main', menus.main) - parseInt(data.max_main || 0, 10));
                if (!packs) { submit(false); return; }
                var price = parseFloat(data.extra_package_price || 82500);
                modal({
                    icon: '🧾',
                    title: 'Confirm Extra Breakfast',
                    body: '<div class="mdl-text">Your order includes extra breakfast beyond your allowance.</div>' +
                        '<div class="mdl-price"><div class="row"><span>' + packs + ' × ' + rp(price) + '</span><b>' + rp(packs * price) + '</b></div>' +
                        '<ul><li>Added to your bill as Extra Breakfast</li><li>Please settle it at Front Office</li></ul></div>',
                    actions: [
                        { label: 'Confirm Order · ' + rp(packs * price), cls: 'p', fn: function() { submit(false); } },
                        { label: 'Review my order', cls: 'g' }
                    ]
                });
            });
            $('btnOnSpot').addEventListener('click', function() {
                modal({
                    icon: '🍽️',
                    title: 'Order on the Spot',
                    body: '<div class="mdl-text">No need to choose now — our restaurant team will take your order directly tomorrow morning, <b>07:00–10:00</b>.</div>',
                    actions: [
                        { label: 'Yes, order on the spot', cls: 'p', fn: function() { submit(true); } },
                        { label: 'Cancel', cls: 'g' }
                    ]
                });
            });
            $('mdl').addEventListener('click', function(e) { if (e.target === this) closeModal(); });

            load();
        })();
    </script>
</body>

</html>
