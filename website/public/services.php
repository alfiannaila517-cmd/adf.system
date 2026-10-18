<?php

/**
 * NARAYANA KARIMUNJAWA — Hotel Services
 * Trip, sewa motor, transfer, laundry, dll. Data dari ADF (Layanan Tamu + katalog Hotel Service), dipesan via WhatsApp Front Office.
 */
$_cfg = __DIR__ . '/config/config.php';
if (!file_exists($_cfg)) $_cfg = dirname(__DIR__) . '/config/config.php';
require_once $_cfg;
require_once __DIR__ . '/includes/services-data.php';

$currentPage = 'services';
$pageTitle = 'Hotel Services';

$_heroRows = dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_hero_services_%' OR setting_key = 'web_hero_background'");
$_hero = [];
foreach ($_heroRows as $_h) $_hero[$_h['setting_key']] = $_h['setting_value'];
$heroBg = $_hero['web_hero_services_background'] ?? ($_hero['web_hero_background'] ?? '');

$services = nrServices();
$wa = nrsWhatsApp();
$hours = nrsHours();
$waLink = fn(string $text) => 'https://wa.me/' . $wa . '?text=' . rawurlencode($text);

include __DIR__ . '/includes/header.php';
?>

<section class="page-hero" <?php if (!empty($heroBg)): ?> style="background: linear-gradient(180deg, rgba(0,0,0,0.45), rgba(0,0,0,0.65)), url('<?= (strpos($heroBg, 'http') === 0) ? htmlspecialchars($heroBg) : BASE_URL . '/' . htmlspecialchars($heroBg) ?>') center/cover;" <?php endif; ?>>
    <div class="container">
        <div class="section-eyebrow" style="color:var(--gold-light);">Hotel Services</div>
        <h1>Everything for Your Island Stay</h1>
        <p>Trips, transport and thoughtful extras — arranged personally by our front office.</p>
    </div>
</section>

<section class="section nrs-intro">
    <div class="container">
        <?php if ($services): ?>
            <nav class="nrs-jump" aria-label="Services">
                <?php foreach ($services as $sv): ?>
                    <a href="#<?= htmlspecialchars($sv['key']) ?>"><i class="fas <?= htmlspecialchars($sv['icon']) ?>"></i><?= htmlspecialchars($sv['name']) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <ol class="nrs-steps">
            <li><span>01</span><strong>Choose a service</strong><p>Browse what we offer below — before or during your stay.</p></li>
            <li><span>02</span><strong>Message our front office</strong><p>One tap opens WhatsApp. We reply daily, <?= htmlspecialchars($hours) ?>.</p></li>
            <li><span>03</span><strong>We arrange everything</strong><p>Confirmed by our team. Simply pay at the hotel.</p></li>
        </ol>
    </div>
</section>

<?php foreach ($services as $i => $sv): ?>
    <section class="nrs-row<?= $i % 2 ? ' is-flip' : '' ?>" id="<?= htmlspecialchars($sv['key']) ?>">
        <div class="container nrs-grid">
            <div class="nrs-media fade-in">
                <?php if ($sv['images']): ?>
                    <a class="nrs-main" style="--nrs-bg: url('<?= htmlspecialchars($sv['images'][0], ENT_QUOTES) ?>')" href="<?= htmlspecialchars($sv['images'][0]) ?>" data-lightbox="svc-<?= htmlspecialchars($sv['key']) ?>" data-caption="<?= htmlspecialchars($sv['name']) ?>">
                        <img src="<?= htmlspecialchars($sv['images'][0]) ?>" alt="<?= htmlspecialchars($sv['name']) ?>" loading="lazy">
                        <span class="nrs-zoom" aria-hidden="true"><i class="fas fa-expand"></i></span>
                    </a>
                    <?php if (count($sv['images']) > 1): ?>
                        <div class="nrs-thumbs">
                            <?php foreach (array_slice($sv['images'], 1, 5) as $k => $img): ?>
                                <a href="<?= htmlspecialchars($img) ?>" data-lightbox="svc-<?= htmlspecialchars($sv['key']) ?>" data-caption="<?= htmlspecialchars($sv['name']) ?>">
                                    <img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy">
                                    <?php if ($k === 4 && count($sv['images']) > 6): ?><span>+<?= count($sv['images']) - 6 ?></span><?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                            <?php foreach (array_slice($sv['images'], 6) as $img): ?>
                                <a href="<?= htmlspecialchars($img) ?>" data-lightbox="svc-<?= htmlspecialchars($sv['key']) ?>" data-caption="<?= htmlspecialchars($sv['name']) ?>" hidden></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <?php // Belum ada flyer: poster tipografi otomatis dari data layanan ?>
                    <div class="nrs-poster nrs-tone-<?= htmlspecialchars($sv['tone']) ?>">
                        <div class="nrs-poster-in">
                            <div class="nrs-poster-brand">Narayana <span>·</span> Karimunjawa</div>
                            <div class="nrs-poster-ic"><i class="fas <?= htmlspecialchars($sv['icon']) ?>"></i></div>
                            <div class="nrs-poster-eyebrow"><?= htmlspecialchars($sv['eyebrow']) ?></div>
                            <div class="nrs-poster-title"><?= htmlspecialchars($sv['name']) ?></div>
                            <div class="nrs-poster-rule"><span></span></div>
                            <?php if ($sv['points']): ?>
                                <ul class="nrs-poster-points">
                                    <?php foreach (array_slice($sv['points'], 0, 4) as $pt): ?><li><?= htmlspecialchars($pt) ?></li><?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if ($sv['price'] > 0): ?>
                                <div class="nrs-poster-price"><small>From</small><strong><?= nrsPrice($sv['price']) ?></strong><?php if ($sv['unit'] !== ''): ?><em>per <?= htmlspecialchars($sv['unit']) ?></em><?php endif; ?></div>
                            <?php endif; ?>
                            <div class="nrs-poster-foot"><i class="fab fa-whatsapp"></i> Arranged by our front office</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="nrs-body fade-in">
                <div class="section-eyebrow"><?= htmlspecialchars($sv['eyebrow']) ?></div>
                <h2><?= htmlspecialchars($sv['name']) ?></h2>
                <div class="divider"></div>
                <?php if ($sv['intro'] !== ''): ?><p class="nrs-text"><?= htmlspecialchars($sv['intro']) ?></p><?php endif; ?>
                <?php if ($sv['points']): ?>
                    <ul class="nrs-points">
                        <?php foreach ($sv['points'] as $pt): ?><li><i class="fas fa-check"></i><?= htmlspecialchars($pt) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($sv['price'] > 0): ?>
                    <div class="nrs-price"><small>From</small><strong><?= nrsPrice($sv['price']) ?></strong><?php if ($sv['unit'] !== ''): ?><span>/ <?= htmlspecialchars($sv['unit']) ?></span><?php endif; ?></div>
                <?php endif; ?>
                <?php if (count($sv['items']) > 1): ?>
                    <details class="nrs-list">
                        <summary>View price list <i class="fas fa-chevron-down"></i></summary>
                        <table>
                            <?php foreach ($sv['items'] as $it): ?>
                                <tr><td><?= htmlspecialchars($it['name']) ?></td><td><?= nrsPrice($it['price']) ?><?= $it['unit'] !== '' ? ' <small>/ ' . htmlspecialchars($it['unit']) . '</small>' : '' ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </details>
                <?php endif; ?>
                <div class="nrs-actions">
                    <a class="nrs-btn" href="<?= htmlspecialchars($waLink($sv['wa'])) ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Arrange via WhatsApp</a>
                    <a class="nrs-link" href="<?= htmlspecialchars(bookingUrl()) ?>">Book a room <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </section>
<?php endforeach; ?>

<section class="nrs-note">
    <div class="container">
        <p><i class="fas fa-circle-info"></i> Prices may change with season and availability; our front office confirms the final price before anything is arranged. Services are paid at the hotel.</p>
    </div>
</section>

<section class="cta-section" <?php if (!empty($heroBg)): ?> style="--cta-bg: url('<?= (strpos($heroBg, 'http') === 0) ? htmlspecialchars($heroBg) : BASE_URL . '/' . htmlspecialchars($heroBg) ?>');" <?php endif; ?>>
    <div class="container">
        <div class="section-eyebrow" style="color:var(--gold-light);">Plan Your Stay</div>
        <h2>Stay with Us, Explore with Us</h2>
        <p>Book your room directly and let our team arrange the rest of your island holiday.</p>
        <div class="cta-buttons">
            <a href="<?= htmlspecialchars(bookingUrl()) ?>" class="btn btn-gold btn-lg"><i class="fas fa-calendar-alt"></i> Book Your Stay</a>
            <a href="<?= htmlspecialchars($waLink('Hello Narayana, I have a question about your services.')) ?>" class="btn btn-outline-white btn-lg" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Chat with Us</a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
