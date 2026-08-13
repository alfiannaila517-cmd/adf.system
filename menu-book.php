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

$bizNorm = strtolower((string)preg_replace('/[^a-z0-9]/', '', $biz));
$activeBiz = (string)($_SESSION['active_business_id'] ?? '');
$activeBizNorm = strtolower((string)preg_replace('/[^a-z0-9]/', '', $activeBiz));
$isDev = isset($_SESSION['role']) && $_SESSION['role'] === 'developer';
$isAllowedInternal = $isDev || $activeBizNorm === $bizNorm;
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
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($bizTitle); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Manrope:wght@400;500;700&display=swap');

        :root {
            --bg-1: #f4ecdf;
            --bg-2: #e7d7bf;
            --ink: #1f1a14;
            --gold: #a1732a;
            --card: #fdf8ef;
            --line: #d9c7aa;
            --shadow: rgba(65, 40, 12, 0.24);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Manrope', 'Segoe UI', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 640px at 8% 0%, rgba(255, 255, 255, 0.82), transparent 60%),
                radial-gradient(900px 500px at 94% 100%, rgba(162, 110, 44, 0.24), transparent 60%),
                linear-gradient(140deg, var(--bg-1), var(--bg-2));
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .book-shell {
            width: min(920px, 100%);
        }

        .book-head {
            text-align: center;
            margin-bottom: 8px;
        }

        .book-head h1 {
            margin: 0;
            font-family: 'Cormorant Garamond', Georgia, serif;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            font-size: clamp(1.2rem, 2.2vw, 2rem);
            font-weight: 700;
            color: #332514;
        }

        .viewer {
            position: relative;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.68);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.45), rgba(255, 255, 255, 0.14));
            box-shadow: 0 20px 46px var(--shadow);
            backdrop-filter: blur(4px);
            padding: 12px;
        }

        .viewer::after {
            content: '';
            position: absolute;
            inset: 6px;
            border: 1px solid rgba(161, 115, 42, 0.35);
            border-radius: 16px;
            pointer-events: none;
        }

        .book-stage {
            position: relative;
            height: clamp(590px, 78vh, 980px);
            aspect-ratio: 10 / 13;
            max-width: 100%;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid var(--line);
            background: linear-gradient(180deg, #fefcf8, #f6eddf);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.75);
            touch-action: pan-y;
        }

        .book-stage::before {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(110deg, rgba(255, 255, 255, 0.16) 0%, transparent 40%, transparent 60%, rgba(120, 85, 30, 0.08) 100%);
            z-index: 4;
        }

        .page-layer {
            position: absolute;
            inset: 0;
            overflow: hidden;
            border-radius: 14px;
            opacity: 0;
            pointer-events: none;
            display: none;
            will-change: transform, opacity;
            backface-visibility: hidden;
        }

        .page-layer.is-active {
            opacity: 1;
            pointer-events: auto;
            display: block;
        }

        .page-sheet {
            position: absolute;
            inset: 0;
            padding: 6px;
            background: linear-gradient(180deg, #fffefc, #f8efe2);
            border-radius: 14px;
        }

        .page-sheet img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #fff;
            display: block;
            border-radius: 10px;
            border: 1px solid #eadcc8;
            box-shadow: 0 8px 20px rgba(88, 58, 18, 0.11);
        }

        .sheet-meta {
            position: absolute;
            left: 12px;
            right: 12px;
            bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            min-height: 24px;
            background: linear-gradient(180deg, rgba(35, 22, 7, 0.03), rgba(35, 22, 7, 0.38));
            border-radius: 10px;
            padding: 6px 8px;
        }

        .sheet-title {
            font-size: 0.72rem;
            letter-spacing: 0.04em;
            color: #fff4e3;
            text-transform: uppercase;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sheet-page-number {
            font-size: 0.66rem;
            font-weight: 700;
            color: #ffefdb;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 231, 193, 0.44);
            padding: 3px 8px;
            border-radius: 999px;
        }

        .controls {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .btn {
            border: 1px solid #e3ccb0;
            background: #fffaf2;
            color: #3f2f1d;
            border-radius: 999px;
            padding: 8px 14px;
            font-weight: 700;
            font-size: 0.76rem;
            cursor: pointer;
            letter-spacing: 0.02em;
            box-shadow: 0 8px 16px rgba(61, 35, 6, 0.1);
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .btn:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 12px 22px rgba(61, 35, 6, 0.16);
        }

        .btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            transform: none;
        }

        .badge {
            align-self: center;
            font-size: 0.74rem;
            color: #4b3621;
            background: #f4e8d8;
            border: 1px solid #dcc4a4;
            padding: 6px 9px;
            border-radius: 999px;
            font-weight: 700;
            min-width: 112px;
            text-align: center;
        }

        .progress-track {
            margin: 8px auto 0;
            width: min(280px, 84%);
            height: 4px;
            background: rgba(161, 115, 42, 0.18);
            border-radius: 999px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            width: 0%;
            border-radius: 999px;
            background: linear-gradient(90deg, #8a5a22, var(--gold));
            transition: width .35s ease;
        }

        .empty {
            text-align: center;
            background: var(--card);
            border-radius: 12px;
            min-height: 50vh;
            display: grid;
            place-items: center;
            color: #64523f;
            padding: 20px;
            border: 1px solid #e5d4bc;
        }

        @media (max-width: 860px) {
            .viewer {
                padding: 9px;
            }

            .book-stage {
                height: clamp(520px, 74vh, 860px);
            }

            .page-sheet {
                padding: 5px;
            }
        }

        @media (max-width: 520px) {
            body {
                padding: 8px;
            }

            .book-head h1 {
                font-size: clamp(1rem, 6vw, 1.35rem);
            }

            .book-stage {
                height: clamp(560px, 78vh, 820px);
                aspect-ratio: 10 / 14;
            }

            .controls {
                gap: 8px;
            }

            .btn {
                padding: 7px 12px;
                font-size: 0.72rem;
            }

            .sheet-meta {
                left: 8px;
                right: 8px;
                bottom: 8px;
                padding: 5px 7px;
            }

            .sheet-title {
                font-size: 0.64rem;
            }
        }
    </style>
</head>

<body>
    <div class="book-shell">
        <div class="book-head">
            <h1><?php echo htmlspecialchars($bizTitle); ?></h1>
        </div>

        <?php if (empty($pages)): ?>
            <div class="empty">The menu book is not available yet.</div>
        <?php else: ?>
            <div class="viewer">
                <div class="book-stage" id="bookStage" aria-live="polite">
                    <div class="page-layer is-active" id="pageA"></div>
                    <div class="page-layer" id="pageB"></div>
                </div>
            </div>
            <div class="controls">
                <button class="btn" id="btnPrev">Previous</button>
                <div class="badge" id="pageBadge">Page 1 of <?php echo count($pages); ?></div>
                <button class="btn" id="btnNext">Next</button>
            </div>
            <div class="progress-track" aria-hidden="true">
                <div class="progress-fill" id="progressFill"></div>
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
            let isAnimating = false;
            let activeLayer = document.getElementById('pageA');
            let passiveLayer = document.getElementById('pageB');
            const stage = document.getElementById('bookStage');
            const prevBtn = document.getElementById('btnPrev');
            const nextBtn = document.getElementById('btnNext');
            const badge = document.getElementById('pageBadge');
            const progressFill = document.getElementById('progressFill');

            function pageHtml(idx) {
                if (idx < 0 || idx >= PAGES.length) {
                    return `
                        <div class="page-sheet" style="display:grid;place-items:center;color:#9a9387;">
                            <div>No page available</div>
                        </div>
                    `;
                }
                const p = PAGES[idx];
                const n = idx + 1;
                const title = (p.title || '').trim() || 'Signature Menu';
                return `
                    <article class="page-sheet">
                        <img src="${p.image}" alt="Menu page ${n}">
                        <div class="sheet-meta">
                            <div class="sheet-title">${title}</div>
                            <div class="sheet-page-number">Page ${n}</div>
                        </div>
                    </article>
                `;
            }

            function updateControls() {
                const pageNumber = current + 1;
                badge.textContent = `Page ${pageNumber} of ${PAGES.length}`;
                const progressPercent = (pageNumber / PAGES.length) * 100;
                progressFill.style.width = `${progressPercent}%`;
                prevBtn.disabled = current <= 0 || isAnimating;
                nextBtn.disabled = current >= PAGES.length - 1 || isAnimating;
            }

            function renderInitial() {
                activeLayer.innerHTML = pageHtml(current);
                activeLayer.classList.add('is-active');
                passiveLayer.classList.remove('is-active');
                updateControls();
            }

            function animateTo(targetIndex) {
                if (isAnimating || targetIndex < 0 || targetIndex >= PAGES.length || targetIndex === current) {
                    return;
                }

                isAnimating = true;
                const direction = targetIndex > current ? 1 : -1;

                passiveLayer.innerHTML = pageHtml(targetIndex);
                passiveLayer.classList.add('is-active');
                passiveLayer.style.transform = `translateX(${direction * 108}%) scale(0.98)`;
                passiveLayer.style.opacity = '0.4';
                activeLayer.style.transform = 'translateX(0) scale(1)';
                activeLayer.style.opacity = '1';

                requestAnimationFrame(() => {
                    const duration = 560;
                    const easing = 'cubic-bezier(0.22, 1, 0.36, 1)';

                    passiveLayer.style.transition = `transform ${duration}ms ${easing}, opacity ${duration}ms ${easing}`;
                    activeLayer.style.transition = `transform ${duration}ms ${easing}, opacity ${duration}ms ${easing}`;

                    passiveLayer.style.transform = 'translateX(0) scale(1)';
                    passiveLayer.style.opacity = '1';

                    activeLayer.style.transform = `translateX(${direction * -20}%) scale(0.985)`;
                    activeLayer.style.opacity = '0.2';

                    window.setTimeout(() => {
                        const oldActive = activeLayer;
                        activeLayer = passiveLayer;
                        passiveLayer = oldActive;

                        passiveLayer.classList.remove('is-active');
                        passiveLayer.style.transition = '';
                        passiveLayer.style.transform = 'translateX(0) scale(1)';
                        passiveLayer.style.opacity = '1';

                        activeLayer.style.transition = '';
                        activeLayer.style.transform = 'translateX(0) scale(1)';
                        activeLayer.style.opacity = '1';

                        current = targetIndex;
                        isAnimating = false;
                        updateControls();
                    }, duration + 24);
                });
            }

            prevBtn.addEventListener('click', () => animateTo(current - 1));
            nextBtn.addEventListener('click', () => animateTo(current + 1));

            document.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowLeft') {
                    animateTo(current - 1);
                }
                if (e.key === 'ArrowRight') {
                    animateTo(current + 1);
                }
            });

            function resetLayerStyles(layer) {
                layer.style.transition = '';
                layer.style.transform = 'translateX(0) scale(1)';
                layer.style.opacity = '1';
            }

            let dragStartX = 0;
            let dragDx = 0;
            let isDragging = false;
            let dragPreviewDirection = 0;

            function dragThresholdPx() {
                return Math.max(48, Math.min(130, stage.clientWidth * 0.18));
            }

            function updatePreviewForDirection(direction) {
                if (direction === 0 || direction === dragPreviewDirection) {
                    return;
                }
                const previewIndex = current + direction;
                if (previewIndex < 0 || previewIndex >= PAGES.length) {
                    dragPreviewDirection = 0;
                    passiveLayer.classList.remove('is-active');
                    return;
                }
                dragPreviewDirection = direction;
                passiveLayer.innerHTML = pageHtml(previewIndex);
                passiveLayer.classList.add('is-active');
            }

            function onDragMove(clientX) {
                if (!isDragging || isAnimating) {
                    return;
                }

                dragDx = clientX - dragStartX;
                const direction = dragDx < 0 ? 1 : (dragDx > 0 ? -1 : 0);
                const previewIndex = current + direction;
                if (direction === 0 || previewIndex < 0 || previewIndex >= PAGES.length) {
                    dragPreviewDirection = 0;
                    passiveLayer.classList.remove('is-active');
                    activeLayer.style.transform = `translateX(${dragDx * 0.18}px) scale(0.995)`;
                    return;
                }

                updatePreviewForDirection(direction);

                const stageWidth = Math.max(stage.clientWidth, 1);
                const progress = Math.min(Math.abs(dragDx) / stageWidth, 1);
                const offsetPercent = (dragDx / stageWidth) * 100;

                activeLayer.style.transition = 'none';
                passiveLayer.style.transition = 'none';

                activeLayer.style.transform = `translateX(${offsetPercent}%) rotateY(${offsetPercent * 0.08}deg) scale(${1 - progress * 0.03})`;
                activeLayer.style.opacity = String(1 - progress * 0.28);

                const enterStart = direction > 0 ? 26 : -26;
                passiveLayer.style.transform = `translateX(${enterStart + offsetPercent}%) scale(${0.985 + progress * 0.015})`;
                passiveLayer.style.opacity = String(0.35 + progress * 0.65);
            }

            function endDrag() {
                if (!isDragging || isAnimating) {
                    return;
                }

                isDragging = false;
                const direction = dragDx < 0 ? 1 : (dragDx > 0 ? -1 : 0);
                const canNavigate = direction !== 0 && (current + direction) >= 0 && (current + direction) < PAGES.length;
                const passThreshold = Math.abs(dragDx) > dragThresholdPx();

                if (canNavigate && passThreshold) {
                    const target = current + direction;
                    resetLayerStyles(activeLayer);
                    resetLayerStyles(passiveLayer);
                    passiveLayer.classList.remove('is-active');
                    dragDx = 0;
                    dragPreviewDirection = 0;
                    animateTo(target);
                    return;
                }

                const duration = 360;
                const easing = 'cubic-bezier(0.2, 0.9, 0.2, 1)';
                activeLayer.style.transition = `transform ${duration}ms ${easing}, opacity ${duration}ms ${easing}`;
                activeLayer.style.transform = 'translateX(0) scale(1)';
                activeLayer.style.opacity = '1';

                passiveLayer.style.transition = `transform ${duration}ms ${easing}, opacity ${duration}ms ${easing}`;
                passiveLayer.style.transform = 'translateX(0) scale(1)';
                passiveLayer.style.opacity = '0';

                window.setTimeout(() => {
                    passiveLayer.classList.remove('is-active');
                    resetLayerStyles(activeLayer);
                    resetLayerStyles(passiveLayer);
                }, duration + 16);

                dragDx = 0;
                dragPreviewDirection = 0;
            }

            stage.addEventListener('pointerdown', (e) => {
                if (isAnimating || e.pointerType === 'mouse' && e.button !== 0) {
                    return;
                }
                isDragging = true;
                dragStartX = e.clientX;
                dragDx = 0;
                dragPreviewDirection = 0;
                stage.setPointerCapture(e.pointerId);
            });

            stage.addEventListener('pointermove', (e) => {
                onDragMove(e.clientX);
            });

            stage.addEventListener('pointerup', () => {
                endDrag();
            });

            stage.addEventListener('pointercancel', () => {
                endDrag();
            });

            renderInitial();
        </script>
    <?php endif; ?>
</body>

</html>