<?php

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$biz = trim((string)($_GET['biz'] ?? ''));
$allowedBiz = ['narayana-hotel', 'bens-cafe', 'eaat-meet'];
if (!in_array($biz, $allowedBiz, true)) {
    http_response_code(404);
    echo 'Menu tidak ditemukan.';
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$activeBiz = (string)($_SESSION['active_business_id'] ?? '');
$isDev = isset($_SESSION['role']) && $_SESSION['role'] === 'developer';
$isAllowedInternal = $isDev || $activeBiz === $biz;
if (!$isAllowedInternal) {
    http_response_code(403);
    echo 'Akses halaman menu ini dibatasi.';
    exit;
}

require_once __DIR__ . '/config/businesses/' . $biz . '.php';

$db = Database::getInstance();
$pdo = $db->getConnection();
$pdo->exec("CREATE TABLE IF NOT EXISTS menu_book_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) DEFAULT NULL,
    image_path VARCHAR(255) NOT NULL,
    page_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_order (page_order),
    KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stmt = $pdo->query('SELECT title, image_path FROM menu_book_pages WHERE is_active = 1 ORDER BY page_order ASC, id ASC');
$pages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$bizTitle = defined('BUSINESS_NAME') ? BUSINESS_NAME : strtoupper(str_replace('-', ' ', $biz));
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($bizTitle); ?> - Buku Menu</title>
    <style>
        :root {
            --paper: #fbf7ef;
            --ink: #222;
            --cover: #0f3f33;
            --accent: #c48c2a;
            --shadow: rgba(0, 0, 0, 0.28);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Georgia, 'Times New Roman', serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 15% 15%, #f8edd8 0%, transparent 45%),
                radial-gradient(circle at 90% 80%, #eadfcb 0%, transparent 40%),
                linear-gradient(130deg, #f7f2e8, #efe5d2);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .book-shell {
            width: min(1100px, 100%);
        }
        .book-head {
            text-align: center;
            margin-bottom: 12px;
        }
        .book-head h1 {
            margin: 0;
            letter-spacing: 0.04em;
            font-size: clamp(1.3rem, 2.4vw, 2.2rem);
        }
        .book-head p {
            margin: 5px 0 0;
            color: #5a5346;
            font-size: 0.92rem;
        }
        .book {
            position: relative;
            border-radius: 18px;
            background: var(--cover);
            box-shadow: 0 24px 48px var(--shadow);
            padding: 16px;
        }
        .book::before {
            content: '';
            position: absolute;
            left: 50%; top: 15px; bottom: 15px;
            width: 6px;
            transform: translateX(-50%);
            background: linear-gradient(180deg, #7a5d2f, #4f3918);
            border-radius: 4px;
            opacity: 0.8;
        }
        .spread {
            position: relative;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            min-height: min(76vh, 820px);
        }
        .page {
            background: var(--paper);
            border-radius: 12px;
            box-shadow: inset 0 0 0 1px rgba(0,0,0,0.07);
            overflow: hidden;
            position: relative;
            transform-origin: center;
            transition: transform .45s ease, filter .45s ease;
        }
        .page img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #fff;
            display: block;
        }
        .page-number {
            position: absolute;
            bottom: 8px;
            font-size: 0.75rem;
            color: #6b6251;
            background: rgba(255,255,255,0.7);
            padding: 2px 6px;
            border-radius: 999px;
        }
        .left .page-number { left: 8px; }
        .right .page-number { right: 8px; }
        .controls {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        .btn {
            border: 0;
            background: #fff;
            color: #1f2937;
            border-radius: 999px;
            padding: 9px 15px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(0,0,0,0.15);
        }
        .btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }
        .badge {
            align-self: center;
            font-size: 0.86rem;
            color: #f6e7c5;
            background: rgba(0,0,0,0.22);
            padding: 6px 10px;
            border-radius: 999px;
        }
        .page.flip {
            transform: perspective(1200px) rotateY(-15deg);
            filter: brightness(0.92);
        }
        .empty {
            text-align: center;
            background: var(--paper);
            border-radius: 12px;
            min-height: 50vh;
            display: grid;
            place-items: center;
            color: #645b4f;
            padding: 20px;
        }
        @media (max-width: 860px) {
            .spread {
                grid-template-columns: 1fr;
                min-height: auto;
            }
            .book::before { display: none; }
            .page { min-height: 64vh; }
        }
    </style>
</head>
<body>
<div class="book-shell">
    <div class="book-head">
        <h1><?php echo htmlspecialchars($bizTitle); ?> Menu Book</h1>
        <p>Geser halaman seperti membuka buku.</p>
    </div>

    <?php if (empty($pages)): ?>
        <div class="empty">Buku menu belum diisi.</div>
    <?php else: ?>
        <div class="book">
            <div class="spread">
                <div class="page left" id="leftPage"></div>
                <div class="page right" id="rightPage"></div>
            </div>
        </div>
        <div class="controls">
            <button class="btn" id="btnPrev">Sebelumnya</button>
            <div class="badge" id="pageBadge">1 / <?php echo count($pages); ?></div>
            <button class="btn" id="btnNext">Berikutnya</button>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($pages)): ?>
<script>
const PAGES = <?php
    $safePages = [];
    foreach ($pages as $r) {
        $safePages[] = [
            'title' => (string)($r['title'] ?? ''),
            'image' => BASE_URL . '/' . ltrim((string)$r['image_path'], '/'),
        ];
    }
    echo json_encode($safePages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>;

let current = 0;
const leftEl = document.getElementById('leftPage');
const rightEl = document.getElementById('rightPage');
const prevBtn = document.getElementById('btnPrev');
const nextBtn = document.getElementById('btnNext');
const badge = document.getElementById('pageBadge');

function pageHtml(idx, side) {
    if (idx < 0 || idx >= PAGES.length) {
        return '<div style="height:100%;display:grid;place-items:center;color:#9a9387;">-</div>';
    }
    const p = PAGES[idx];
    const n = idx + 1;
    return `
        <img src="${p.image}" alt="Page ${n}">
        <div class="page-number">${n}</div>
    `;
}

function render(withFlip = false) {
    const leftIndex = current;
    const rightIndex = current + 1;

    if (withFlip) {
        rightEl.classList.add('flip');
        setTimeout(() => rightEl.classList.remove('flip'), 360);
    }

    leftEl.innerHTML = pageHtml(leftIndex, 'left');
    rightEl.innerHTML = pageHtml(rightIndex, 'right');

    const display = Math.min(current + 1, PAGES.length);
    badge.textContent = `${display} / ${PAGES.length}`;

    prevBtn.disabled = current <= 0;
    nextBtn.disabled = current >= PAGES.length - 1;
}

prevBtn.addEventListener('click', () => {
    if (current <= 0) return;
    current = Math.max(0, current - 2);
    render(true);
});

nextBtn.addEventListener('click', () => {
    if (current >= PAGES.length - 1) return;
    current = Math.min(PAGES.length - 1, current + 2);
    render(true);
});

let touchStartX = 0;
document.addEventListener('touchstart', (e) => {
    touchStartX = e.changedTouches[0].clientX;
}, { passive: true });
document.addEventListener('touchend', (e) => {
    const dx = e.changedTouches[0].clientX - touchStartX;
    if (Math.abs(dx) < 40) return;
    if (dx < 0) {
        nextBtn.click();
    } else {
        prevBtn.click();
    }
}, { passive: true });

render(false);
</script>
<?php endif; ?>
</body>
</html>
