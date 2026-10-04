<?php

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$biz = trim((string)($_GET['biz'] ?? ''));
$allowedBiz = ['narayana-hotel', 'bens-cafe', 'eaat-meet', 'eat-meet'];
if (!in_array($biz, $allowedBiz, true)) {
    http_response_code(404);
    echo 'Menu tidak ditemukan.';
    exit;
}

// Halaman PUBLIK untuk tamu yang scan QR (tanpa login). Hanya membaca halaman menu yang aktif.
// Database & nama selalu diambil dari bisnis di URL (?biz=), bukan dari sesi login yang sedang aktif,
// supaya QR Bens Cafe selalu menampilkan menu Bens Cafe.
$bizCfgPath = __DIR__ . '/config/businesses/' . $biz . '.php';
$bizCfg = file_exists($bizCfgPath) ? (require $bizCfgPath) : [];
if (empty($bizCfg['database'])) {
    http_response_code(404);
    echo 'Menu tidak ditemukan.';
    exit;
}

$pages = [];
try {
    // Koneksi langsung (tanpa Database::switchDatabase) — tamu tidak punya sesi, jadi switchDatabase
    // akan menjalankan sinkronisasi skema penuh di setiap scan QR (beberapa detik).
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . getDbName((string)$bizCfg['database']) . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $stmt = $pdo->query('SELECT title, image_path FROM menu_book_pages WHERE is_active = 1 ORDER BY page_order ASC, id ASC');
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // Tabel belum dibuat (belum ada halaman yang diunggah) → tampil "menu belum tersedia".
    error_log('menu-book public [' . $biz . ']: ' . $e->getMessage());
}

$bizTitle = (string)($bizCfg['name'] ?? strtoupper(str_replace('-', ' ', $biz)));
// Logo bisnis untuk sampul buku (fallback: inisial nama). Urutan sama dengan logo di sidebar:
// setting company_logo_<id> → logo di config → file <id>_logo.* → setting hotel_logo → company_logo.
$bizLogo = null;
$logoUrl = function ($val) {
    $val = preg_replace('#^/?uploads/logos/#', '', (string)$val);
    if ($val === '') {
        return null;
    }
    if (strpos($val, 'http') === 0) {
        return $val;
    }
    $file = __DIR__ . '/uploads/logos/' . $val;
    return is_file($file) ? BASE_URL . '/uploads/logos/' . $val . '?v=' . filemtime($file) : null;
};
$bizId = (string)($bizCfg['business_id'] ?? $biz);
$settings = [];
try {
    if (isset($pdo)) {
        $st = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN (?, 'hotel_logo', 'company_logo')");
        $st->execute(['company_logo_' . $bizId]);
        $settings = array_column($st->fetchAll(), 'setting_value', 'setting_key');
    }
} catch (Throwable $e) {
}
$logoCandidates = [$settings['company_logo_' . $bizId] ?? '', $bizCfg['logo'] ?? ''];
foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
    $logoCandidates[] = $bizId . '_logo.' . $ext;
}
$logoCandidates[] = $settings['hotel_logo'] ?? '';
$logoCandidates[] = $settings['company_logo'] ?? '';
foreach ($logoCandidates as $candidate) {
    if ($bizLogo = $logoUrl($candidate)) {
        break;
    }
}
$bizInitial = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $bizTitle), 0, 1) ?: 'M');
$menuPages = [];
foreach ($pages as $r) {
    $menuPages[] = [
        'title' => (string)($r['title'] ?? ''),
        'image' => BASE_URL . '/' . ltrim((string)$r['image_path'], '/'),
    ];
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#16110d">
    <title>Menu · <?php echo htmlspecialchars($bizTitle); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <!-- Font dimuat tanpa memblokir tampilan (sinyal lemah tetap langsung tampil dengan font cadangan). -->
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,500&family=Inter:wght@400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <style>
        :root {
            --bg: #16110d;
            --bg2: #2a1f17;
            --gold: #d4a957;
            --gold-soft: rgba(212, 169, 87, .35);
            --paper: #fbf7f0;
            --text: #f5ede0;
            --muted: #b7a58c;
            --ratio: 0.7071;
            /* lebar/tinggi halaman, disesuaikan dengan gambar pertama */
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html, body { margin: 0; height: 100%; }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            color: var(--text);
            background:
                radial-gradient(900px 600px at 50% -10%, rgba(212, 169, 87, .16), transparent 70%),
                radial-gradient(700px 500px at 100% 110%, rgba(120, 72, 30, .35), transparent 70%),
                linear-gradient(180deg, var(--bg2), var(--bg));
            overflow: hidden;
            overscroll-behavior: none;
        }

        /* ── Sampul ── */
        .cover-screen {
            position: fixed; inset: 0; z-index: 30;
            display: grid; place-items: center;
            perspective: 1800px;
            transition: opacity .6s ease .5s, visibility 0s linear 1.1s;
        }
        .cover-screen.opened { opacity: 0; visibility: hidden; pointer-events: none; }
        .cover {
            position: relative;
            width: min(78vw, 340px);
            aspect-ratio: 0.7;
            border-radius: 6px 16px 16px 6px;
            background:
                linear-gradient(90deg, rgba(0, 0, 0, .35) 0, rgba(0, 0, 0, .05) 4%, transparent 9%),
                radial-gradient(circle at 30% 20%, rgba(255, 255, 255, .08), transparent 55%),
                linear-gradient(160deg, #3b2a1d, #1d140e 70%);
            box-shadow: 0 30px 60px rgba(0, 0, 0, .55), inset 0 0 0 1px rgba(212, 169, 87, .25);
            transform-origin: left center;
            transition: transform 1.1s cubic-bezier(.6, .05, .25, 1);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-align: center; padding: 28px 22px; cursor: pointer;
        }
        .cover::before {
            content: ''; position: absolute; inset: 12px 12px 12px 18px;
            border: 1px solid var(--gold-soft); border-radius: 4px 10px 10px 4px; pointer-events: none;
        }
        .cover-screen.opened .cover { transform: rotateY(-115deg); }
        .cover-logo {
            width: 92px; height: 92px; border-radius: 50%; object-fit: cover;
            border: 2px solid var(--gold); box-shadow: 0 6px 20px rgba(0, 0, 0, .4); background: #fff;
        }
        .cover-mono {
            width: 92px; height: 92px; border-radius: 50%; display: grid; place-items: center;
            border: 2px solid var(--gold); font-family: 'Playfair Display', serif; font-size: 2.6rem; color: var(--gold);
        }
        .cover small { margin-top: 22px; letter-spacing: .32em; text-transform: uppercase; font-size: .68rem; color: var(--gold); }
        .cover h1 { font-family: 'Playfair Display', serif; font-weight: 700; font-size: clamp(1.6rem, 6.5vw, 2.2rem); margin: 8px 0 4px; line-height: 1.15; color: var(--text); }
        .cover .rule { width: 60px; height: 1px; background: var(--gold); margin: 14px auto; opacity: .7; }
        .cover em { font-family: 'Playfair Display', serif; font-style: italic; color: var(--muted); font-size: .95rem; }
        .open-btn {
            margin-top: 26px; border: 1px solid var(--gold); background: transparent; color: var(--gold);
            padding: 11px 26px; border-radius: 999px; font: 600 .82rem 'Inter', sans-serif; letter-spacing: .12em; text-transform: uppercase;
            cursor: pointer; animation: pulse 2.4s ease-in-out infinite;
        }
        @keyframes pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(212, 169, 87, .35); } 50% { box-shadow: 0 0 0 10px rgba(212, 169, 87, 0); } }

        /* ── Pembaca ── */
        .reader { position: fixed; inset: 0; display: flex; flex-direction: column; opacity: 0; transition: opacity .6s ease .45s; }
        .reader.show { opacity: 1; }
        .topbar {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            padding: calc(env(safe-area-inset-top, 0px) + 12px) 16px 8px;
        }
        .brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .brand img, .brand .mini-mono { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 1px solid var(--gold-soft); flex-shrink: 0; }
        .brand .mini-mono { display: grid; place-items: center; color: var(--gold); font-family: 'Playfair Display', serif; }
        .brand b { font-family: 'Playfair Display', serif; font-size: 1.02rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .icon-btn {
            width: 40px; height: 40px; border-radius: 50%; border: 1px solid rgba(255, 255, 255, .14);
            background: rgba(255, 255, 255, .06); color: var(--text); display: grid; place-items: center; cursor: pointer; flex-shrink: 0;
        }
        .icon-btn svg { width: 19px; height: 19px; }
        .icon-btn:disabled { opacity: .3; cursor: default; }

        .stage { flex: 1; display: grid; place-items: center; min-height: 0; padding: 6px 12px; perspective: 2200px; }
        .book { position: relative; touch-action: pan-y; user-select: none; }
        .leaf, .face {
            position: absolute; top: 0; bottom: 0; background: var(--paper); overflow: hidden;
        }
        .leaf img, .face img { width: 100%; height: 100%; object-fit: contain; display: block; pointer-events: none; background: var(--paper); }
        /* Satu halaman (HP) */
        .book.single .leaf.right { left: 0; right: 0; border-radius: 4px 10px 10px 4px; box-shadow: 0 18px 50px rgba(0, 0, 0, .5); }
        .book.single .leaf.left { display: none; }
        /* Dua halaman (layar lebar) */
        .book.spread .leaf.left { left: 0; width: 50%; border-radius: 10px 2px 2px 10px; box-shadow: -10px 18px 50px rgba(0, 0, 0, .45); }
        .book.spread .leaf.right { left: 50%; width: 50%; border-radius: 2px 10px 10px 2px; box-shadow: 10px 18px 50px rgba(0, 0, 0, .45); }
        /* Bayangan lipatan tengah buku */
        .book.spread::after {
            content: ''; position: absolute; top: 0; bottom: 0; left: 50%; width: 60px; transform: translateX(-50%); z-index: 6; pointer-events: none;
            background: linear-gradient(90deg, transparent, rgba(0, 0, 0, .18) 45%, rgba(0, 0, 0, .28) 50%, rgba(0, 0, 0, .18) 55%, transparent);
        }
        .leaf.blank { display: grid; place-items: center; background: linear-gradient(160deg, #3b2a1d, #1d140e); color: var(--gold); text-align: center; }
        .leaf.blank .inner b { font-family: 'Playfair Display', serif; font-size: 1.5rem; display: block; margin-top: 10px; }
        .leaf.blank .inner small { letter-spacing: .3em; text-transform: uppercase; font-size: .62rem; }

        /* Lembar yang sedang dibalik */
        .flipper { position: absolute; top: 0; bottom: 0; z-index: 8; transform-style: preserve-3d; transform-origin: left center; display: none; }
        .flipper.active { display: block; }
        .book.single .flipper { left: 0; right: 0; }
        .book.spread .flipper { left: 50%; width: 50%; }
        .face { inset: 0; backface-visibility: hidden; -webkit-backface-visibility: hidden; }
        .face.back { transform: rotateY(180deg); }
        .face::after { content: ''; position: absolute; inset: 0; pointer-events: none; opacity: 0; transition: opacity .35s ease; }
        .face.front::after { background: linear-gradient(90deg, rgba(0, 0, 0, .05), rgba(0, 0, 0, .35)); }
        .face.back::after { background: linear-gradient(270deg, rgba(0, 0, 0, .05), rgba(0, 0, 0, .3)); }
        .flipper.turning .face::after { opacity: 1; }
        .book.single .face.back { background: linear-gradient(90deg, #efe7da, #fbf7f0); }
        .book.single .face.back img { opacity: .08; transform: scaleX(-1); }

        .bottombar { display: flex; align-items: center; justify-content: center; gap: 14px; padding: 8px 16px calc(env(safe-area-inset-bottom, 0px) + 14px); }
        .counter { min-width: 110px; text-align: center; }
        .counter b { display: block; font-family: 'Playfair Display', serif; font-size: 1.02rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 46vw; }
        .counter span { font-size: .72rem; color: var(--muted); letter-spacing: .06em; }
        .hint { position: fixed; left: 50%; bottom: calc(env(safe-area-inset-bottom, 0px) + 74px); transform: translateX(-50%); font-size: .72rem; color: var(--muted); background: rgba(0, 0, 0, .35); padding: 6px 12px; border-radius: 999px; transition: opacity .6s; pointer-events: none; white-space: nowrap; }
        .hint.hide { opacity: 0; }

        /* Daftar halaman */
        .sheet { position: fixed; inset: 0; z-index: 40; background: rgba(0, 0, 0, .55); opacity: 0; visibility: hidden; transition: opacity .3s, visibility 0s linear .3s; }
        .sheet.open { opacity: 1; visibility: visible; transition: opacity .3s; }
        .sheet-panel {
            position: absolute; left: 0; right: 0; bottom: 0; max-height: 72vh; overflow-y: auto;
            background: #1f1711; border-radius: 22px 22px 0 0; padding: 14px 16px calc(env(safe-area-inset-bottom, 0px) + 18px);
            transform: translateY(100%); transition: transform .35s cubic-bezier(.2, .9, .3, 1); border-top: 1px solid var(--gold-soft);
        }
        .sheet.open .sheet-panel { transform: none; }
        .sheet-grip { width: 42px; height: 4px; border-radius: 4px; background: rgba(255, 255, 255, .25); margin: 0 auto 12px; }
        .sheet h2 { font-family: 'Playfair Display', serif; font-size: 1.15rem; margin: 0 0 12px; }
        .thumbs { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 12px; }
        .thumb { background: none; border: 0; padding: 0; color: var(--text); cursor: pointer; text-align: center; }
        .thumb img { width: 100%; aspect-ratio: var(--ratio); object-fit: cover; border-radius: 6px; border: 2px solid transparent; background: var(--paper); }
        .thumb.on img { border-color: var(--gold); }
        .thumb span { display: block; font-size: .7rem; color: var(--muted); margin-top: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Zoom */
        .zoom { position: fixed; inset: 0; z-index: 50; background: #0d0a08; display: none; flex-direction: column; }
        .zoom.open { display: flex; }
        .zoom-bar { display: flex; justify-content: space-between; align-items: center; padding: calc(env(safe-area-inset-top, 0px) + 10px) 14px 10px; gap: 8px; }
        .zoom-scroll { flex: 1; overflow: auto; -webkit-overflow-scrolling: touch; touch-action: pan-x pan-y pinch-zoom; }
        .zoom-scroll img { display: block; margin: 0 auto; width: 100%; max-width: none; transition: width .25s ease; background: var(--paper); }

        .empty { position: fixed; inset: 0; display: grid; place-items: center; text-align: center; padding: 24px; }
        .empty b { font-family: 'Playfair Display', serif; font-size: 1.4rem; display: block; margin-bottom: 6px; }

        @media (prefers-reduced-motion: reduce) {
            .cover, .flipper, .face::after { transition: none !important; }
            .open-btn { animation: none; }
        }
    </style>
</head>

<body>
    <?php if (empty($menuPages)): ?>
        <div class="empty">
            <div><b><?php echo htmlspecialchars($bizTitle); ?></b><span style="color:var(--muted);">Menu belum tersedia · The menu is not available yet.</span></div>
        </div>
    <?php else: ?>
        <!-- Sampul buku -->
        <div class="cover-screen" id="coverScreen">
            <div class="cover" id="cover" role="button" tabindex="0" aria-label="Buka menu">
                <?php if ($bizLogo): ?>
                    <img class="cover-logo" src="<?php echo htmlspecialchars($bizLogo); ?>" alt="">
                <?php else: ?>
                    <div class="cover-mono"><?php echo htmlspecialchars($bizInitial); ?></div>
                <?php endif; ?>
                <small>Menu</small>
                <h1><?php echo htmlspecialchars($bizTitle); ?></h1>
                <div class="rule"></div>
                <em><?php echo count($menuPages); ?> pages · Bon appétit</em>
                <button class="open-btn" type="button" id="openBtn">Buka Menu · Open</button>
            </div>
        </div>

        <!-- Pembaca -->
        <div class="reader" id="reader">
            <div class="topbar">
                <div class="brand">
                    <?php if ($bizLogo): ?><img src="<?php echo htmlspecialchars($bizLogo); ?>" alt=""><?php else: ?><span class="mini-mono"><?php echo htmlspecialchars($bizInitial); ?></span><?php endif; ?>
                    <b><?php echo htmlspecialchars($bizTitle); ?></b>
                </div>
                <div style="display:flex;gap:8px;">
                    <button class="icon-btn" id="zoomBtn" aria-label="Perbesar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4.3-4.3M11 8v6M8 11h6" /></svg></button>
                    <button class="icon-btn" id="listBtn" aria-label="Daftar halaman"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /></svg></button>
                </div>
            </div>

            <div class="stage" id="stage">
                <div class="book single" id="book">
                    <div class="leaf left" id="leafLeft"></div>
                    <div class="leaf right" id="leafRight"></div>
                    <div class="flipper" id="flipper">
                        <div class="face front" id="faceFront"></div>
                        <div class="face back" id="faceBack"></div>
                    </div>
                </div>
            </div>

            <div class="bottombar">
                <button class="icon-btn" id="prevBtn" aria-label="Sebelumnya"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M15 18l-6-6 6-6" /></svg></button>
                <div class="counter"><b id="pageTitle"></b><span id="pageNum"></span></div>
                <button class="icon-btn" id="nextBtn" aria-label="Berikutnya"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M9 18l6-6-6-6" /></svg></button>
            </div>
            <div class="hint" id="hint">Geser atau ketuk tepi halaman · Swipe to turn</div>
        </div>

        <!-- Daftar halaman -->
        <div class="sheet" id="sheet">
            <div class="sheet-panel">
                <div class="sheet-grip"></div>
                <h2>Daftar Halaman · Pages</h2>
                <div class="thumbs" id="thumbs"></div>
            </div>
        </div>

        <!-- Zoom -->
        <div class="zoom" id="zoom">
            <div class="zoom-bar">
                <div style="display:flex;gap:8px;">
                    <button class="icon-btn" id="zoomOut" aria-label="Perkecil"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14" /></svg></button>
                    <button class="icon-btn" id="zoomIn" aria-label="Perbesar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg></button>
                </div>
                <button class="icon-btn" id="zoomClose" aria-label="Tutup"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
            </div>
            <div class="zoom-scroll" id="zoomScroll"><img id="zoomImg" alt=""></div>
        </div>

        <script>
            const PAGES = <?php echo json_encode($menuPages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
            const $ = (id) => document.getElementById(id);
            const book = $('book'), stage = $('stage'), flipper = $('flipper');
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const FLIP_MS = reduceMotion ? 1 : 750;
            let spread = false;   // dua halaman berdampingan (layar lebar)
            let view = 0;         // posisi: HP = indeks halaman; lebar = indeks bentangan
            let busy = false;

            // Preload semua halaman.
            PAGES.forEach((p) => { const im = new Image(); im.src = p.image; });

            const pageImg = (i) => (i >= 0 && i < PAGES.length)
                ? `<img src="${PAGES[i].image}" alt="${(PAGES[i].title || 'Menu ' + (i + 1)).replace(/"/g, '&quot;')}">`
                : '';
            // Bentangan v: kiri = halaman 2v-1 (v=0: sampul dalam), kanan = halaman 2v.
            const viewCount = () => spread ? Math.floor(PAGES.length / 2) + 1 : PAGES.length;
            const leftOf = (v) => 2 * v - 1;
            const rightOf = (v) => 2 * v;

            function fillLeaf(el, i, isLeft) {
                if (i >= 0 && i < PAGES.length) {
                    el.className = 'leaf ' + (isLeft ? 'left' : 'right');
                    el.innerHTML = pageImg(i);
                } else {
                    el.className = 'leaf blank ' + (isLeft ? 'left' : 'right');
                    el.innerHTML = isLeft
                        ? `<div class="inner"><small>Menu</small><b><?php echo htmlspecialchars(addslashes($bizTitle), ENT_QUOTES); ?></b></div>`
                        : `<div class="inner"><small>Terima kasih · Thank you</small></div>`;
                }
            }

            function render() {
                if (spread) {
                    fillLeaf($('leafLeft'), leftOf(view), true);
                    fillLeaf($('leafRight'), rightOf(view), false);
                } else {
                    fillLeaf($('leafRight'), view, false);
                }
                updateUi();
            }

            function currentPages() {
                if (!spread) return [view];
                return [leftOf(view), rightOf(view)].filter((i) => i >= 0 && i < PAGES.length);
            }

            function updateUi() {
                const ps = currentPages();
                const titles = ps.map((i) => (PAGES[i].title || '').trim()).filter(Boolean);
                $('pageTitle').textContent = titles[0] || 'Menu';
                $('pageNum').textContent = ps.length ? `Hal. ${ps.map((i) => i + 1).join('–')} / ${PAGES.length}` : '';
                $('prevBtn').disabled = view <= 0;
                $('nextBtn').disabled = view >= viewCount() - 1;
                document.querySelectorAll('.thumb').forEach((t) => t.classList.toggle('on', ps.includes(+t.dataset.i)));
                try { history.replaceState(null, '', '#hal-' + ((ps[0] ?? 0) + 1)); } catch (e) {}
            }

            // Ukuran buku menyesuaikan layar (rasio dari gambar pertama).
            function layout() {
                const ratio = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--ratio')) || 0.7071;
                const W = stage.clientWidth - 24, H = stage.clientHeight - 12;
                const firstPage = spread ? (leftOf(view) >= 0 ? leftOf(view) : rightOf(view)) : view;
                const wantSpread = W > 820 && W / H > ratio * 1.35 && PAGES.length > 1;
                if (wantSpread !== spread) {
                    spread = wantSpread;
                    view = spread ? Math.ceil(firstPage / 2) : Math.max(0, firstPage);
                    book.className = 'book ' + (spread ? 'spread' : 'single');
                }
                const pageW = Math.min(spread ? W / 2 : W, H * ratio);
                book.style.width = (spread ? pageW * 2 : pageW) + 'px';
                book.style.height = (pageW / ratio) + 'px';
                render();
            }

            // Balik halaman dengan animasi 3D.
            function flip(dir) {
                if (busy) return;
                const target = view + dir;
                if (target < 0 || target >= viewCount()) return;
                busy = true;
                hideHint();
                const front = $('faceFront'), back = $('faceBack');
                if (spread) {
                    if (dir > 0) {
                        front.innerHTML = pageImg(rightOf(view));
                        back.innerHTML = pageImg(leftOf(target)) || '';
                        fillLeaf($('leafRight'), rightOf(target), false);
                    } else {
                        front.innerHTML = pageImg(rightOf(target));
                        back.innerHTML = pageImg(leftOf(view));
                        fillLeaf($('leafLeft'), leftOf(target), true);
                    }
                } else {
                    if (dir > 0) {
                        front.innerHTML = pageImg(view);
                        back.innerHTML = pageImg(view);
                        fillLeaf($('leafRight'), target, false);
                    } else {
                        front.innerHTML = pageImg(target);
                        back.innerHTML = pageImg(target);
                    }
                }
                flipper.style.transition = 'none';
                flipper.style.transform = dir > 0 ? 'rotateY(0deg)' : 'rotateY(-180deg)';
                flipper.classList.add('active');
                void flipper.offsetWidth;
                flipper.style.transition = `transform ${FLIP_MS}ms cubic-bezier(.45,.05,.25,1)`;
                flipper.classList.add('turning');
                flipper.style.transform = dir > 0 ? 'rotateY(-180deg)' : 'rotateY(0deg)';
                setTimeout(() => {
                    view = target;
                    render();
                    flipper.classList.remove('active', 'turning');
                    busy = false;
                }, FLIP_MS);
            }

            function goTo(pageIndex) {
                view = spread ? Math.ceil(pageIndex / 2) : pageIndex;
                render();
            }

            // Kontrol
            $('prevBtn').onclick = () => flip(-1);
            $('nextBtn').onclick = () => flip(1);
            document.addEventListener('keydown', (e) => {
                if ($('zoom').classList.contains('open')) { if (e.key === 'Escape') closeZoom(); return; }
                if (e.key === 'ArrowRight') flip(1);
                if (e.key === 'ArrowLeft') flip(-1);
            });
            // Geser (swipe) atau ketuk tepi kiri/kanan buku.
            let sx = null, sy = null, st = 0;
            book.addEventListener('pointerdown', (e) => { sx = e.clientX; sy = e.clientY; st = Date.now(); });
            book.addEventListener('pointerup', (e) => {
                if (sx === null) return;
                const dx = e.clientX - sx, dy = e.clientY - sy;
                sx = null;
                if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) { flip(dx < 0 ? 1 : -1); return; }
                if (Math.abs(dx) < 8 && Math.abs(dy) < 8 && Date.now() - st < 400) {
                    const r = book.getBoundingClientRect();
                    const x = (e.clientX - r.left) / r.width;
                    if (x < 0.3) flip(-1); else if (x > 0.7) flip(1); else openZoom();
                }
            });

            // Hint hilang setelah interaksi pertama.
            let hintTimer = null;
            function hideHint() { $('hint').classList.add('hide'); }

            // Daftar halaman
            const thumbs = $('thumbs');
            PAGES.forEach((p, i) => {
                const b = document.createElement('button');
                b.className = 'thumb';
                b.dataset.i = i;
                b.innerHTML = `<img loading="lazy" src="${p.image}" alt=""><span>${i + 1}. ${(p.title || 'Menu').replace(/</g, '&lt;')}</span>`;
                b.onclick = () => { goTo(i); $('sheet').classList.remove('open'); };
                thumbs.appendChild(b);
            });
            $('listBtn').onclick = () => $('sheet').classList.add('open');
            $('sheet').addEventListener('click', (e) => { if (e.target.id === 'sheet') $('sheet').classList.remove('open'); });

            // Zoom: halaman sekarang (kanan bila dua halaman), bisa dicubit/diperbesar.
            let zoomLevel = 1;
            function openZoom() {
                const ps = currentPages();
                if (!ps.length) return;
                $('zoomImg').src = PAGES[ps[ps.length - 1]].image;
                zoomLevel = 1;
                $('zoomImg').style.width = '100%';
                $('zoom').classList.add('open');
            }
            function closeZoom() { $('zoom').classList.remove('open'); }
            function setZoom(z) { zoomLevel = Math.min(3, Math.max(1, z)); $('zoomImg').style.width = (zoomLevel * 100) + '%'; }
            $('zoomBtn').onclick = openZoom;
            $('zoomClose').onclick = closeZoom;
            $('zoomIn').onclick = () => setZoom(zoomLevel + 0.5);
            $('zoomOut').onclick = () => setZoom(zoomLevel - 0.5);

            // Buka sampul
            function openBook() {
                $('coverScreen').classList.add('opened');
                $('reader').classList.add('show');
                hintTimer = setTimeout(hideHint, 4500);
            }
            $('cover').addEventListener('click', openBook);
            $('cover').addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') openBook(); });

            // Rasio halaman dari gambar pertama, lalu atur tata letak.
            const first = new Image();
            first.onload = () => {
                if (first.naturalWidth && first.naturalHeight) {
                    document.documentElement.style.setProperty('--ratio', (first.naturalWidth / first.naturalHeight).toFixed(4));
                }
                layout();
            };
            first.onerror = layout;
            first.src = PAGES[0].image;
            window.addEventListener('resize', () => { if (!busy) layout(); });

            // Link langsung ke halaman: menu-book.php?biz=...#hal-3 (langsung terbuka).
            const m = location.hash.match(/^#hal-(\d+)$/);
            if (m) {
                const idx = Math.min(PAGES.length, Math.max(1, +m[1])) - 1;
                view = idx;
                $('coverScreen').classList.add('opened');
                $('reader').classList.add('show');
                hideHint();
            }
            layout();
        </script>
    <?php endif; ?>
</body>

</html>
