<?php

/**
 * NARAYANA KARIMUNJAWA — Homepage
 * Marriott-Inspired Clean Luxury Design
 */
// Flexible path: works on hosting (config inside webroot) and local dev (config outside public/)
$_cfg = __DIR__ . '/config/config.php';
if (!file_exists($_cfg)) $_cfg = dirname(__DIR__) . '/config/config.php';
require_once $_cfg;

$currentPage = 'home';

function normalizeRoomTypeKey(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
    $normalized = trim((string)$normalized, '_');

    // Match settings keys like deluxe_king even if DB type_name is "Deluxe King Room".
    if (str_ends_with($normalized, '_room')) {
        $normalized = substr($normalized, 0, -5);
    }

    return $normalized;
}

// Load hero settings from database
$heroSettings = dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_hero_%'");
$hero = [];
foreach ($heroSettings as $setting) {
    $hero[$setting['setting_key']] = $setting['setting_value'];
}

// Extract hero values with defaults
$heroBg = $hero['web_hero_background'] ?? '';
$heroAccent = $hero['web_hero_accent'] ?? 'Karimunjawa Islands · Indonesia';
$heroTitle = $hero['web_hero_title'] ?? 'Experience Karimunjawa Like Never Before';
// Force 2-line layout: "Experience Karimunjawa" + "Like Never Before"
$heroTitle = strip_tags($heroTitle, '<em><br><strong>');
if (stripos($heroTitle, 'Experience') !== false && stripos($heroTitle, 'Like Never') !== false) {
    // Remove any existing <br> first, then re-insert cleanly
    $heroTitle = str_replace(['<br>', '<br/>', '<br />'], ' ', $heroTitle);
    $heroTitle = preg_replace('/\s+/', ' ', trim($heroTitle));
    $heroTitle = preg_replace('/(Experience Karimunjawa)\s*(Like Never Before)/i', '$1<br><em>$2</em>', $heroTitle);
}
$heroSubtitle = $hero['web_hero_subtitle'] ?? 'An exclusive island retreat where tropical luxury meets the pristine beauty of the Java Sea';
$heroCards = json_decode($hero['web_hero_cards'] ?? '[]', true) ?: [];

// Room types with availability
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$roomTypes = dbFetchAll("
    SELECT rt.*, 
           COUNT(r.id) as total_rooms,
           SUM(CASE WHEN r.id NOT IN (
               SELECT b.room_id FROM bookings b 
               WHERE b.status IN ('pending','confirmed','checked_in')
               AND b.check_in_date <= ? AND b.check_out_date > ?
           ) THEN 1 ELSE 0 END) as available_rooms
    FROM room_types rt
    LEFT JOIN rooms r ON rt.id = r.room_type_id
    GROUP BY rt.id
    ORDER BY rt.base_price DESC
", [$today, $today]);

// Today's stats
$totalRooms = dbFetch("SELECT COUNT(*) as c FROM rooms")['c'] ?? 0;
$availableNow = dbFetch("
    SELECT COUNT(*) as c FROM rooms 
    WHERE id NOT IN (
        SELECT room_id FROM bookings 
        WHERE status IN ('pending','confirmed','checked_in')
        AND check_in_date <= ? AND check_out_date > ?
    )
", [$today, $today])['c'] ?? 0;

$roomIcons = ['King' => '👑', 'Queen' => '🌙', 'Twin' => '🛏️'];

// Load activities for homepage slideshow
$homeActivities = [];
try {
    $_actRow = dbFetch("SELECT setting_value FROM settings WHERE setting_key = 'web_activities'");
    $_actList = $_actRow ? json_decode($_actRow['setting_value'], true) : [];
    if (!empty($_actList)) {
        $_actList = array_filter($_actList, function ($a) {
            return ($a['active'] ?? true) && !empty($a['image']);
        });
        usort($_actList, function ($a, $b) {
            return ($a['order'] ?? 0) - ($b['order'] ?? 0);
        });
        $homeActivities = array_values($_actList);
    }
} catch (Exception $e) {
    // Activities query failed — don't break the page
}
// Fallback defaults if DB empty
if (empty($homeActivities)) {
    $homeActivities = [
        ['id' => 'snorkeling', 'eyebrow' => 'Underwater World', 'title' => 'Snorkelling in Crystal-Clear Waters', 'image' => 'https://images.unsplash.com/photo-1534258936925-c58bed479fcb?w=900&q=80', 'body' => 'Karimunjawa\'s waters are among the clearest in the Java Sea, with visibility reaching 15–20 metres. Float above vibrant coral gardens teeming with clownfish, parrotfish, giant clams, and green sea turtles.', 'details' => ['Best time: 07:00–11:00', 'Top spots: Menjangan Kecil, Gosong Cemara', 'Suitable for all ages & skill levels'], 'order' => 1, 'active' => true],
        ['id' => 'island-hopping', 'eyebrow' => 'Island Exploration', 'title' => 'Hopping Between 27 Islands', 'image' => 'https://images.unsplash.com/photo-1559128010-7c1ad6e1b6a5?w=900&q=80', 'body' => 'The Karimunjawa archipelago comprises 27 islands — only five are inhabited. Board a traditional wooden boat and sail between pristine beaches, hidden lagoons, and deserted shores.', 'details' => ['Full-day excursion (08:00–16:00)', 'Visit 3–5 islands per trip', 'Grilled seafood lunch included'], 'order' => 2, 'active' => true],
        ['id' => 'diving', 'eyebrow' => 'Deep Exploration', 'title' => 'Scuba Diving the Marine National Park', 'image' => 'https://images.unsplash.com/photo-1682687220742-aba13b6e50ba?w=900&q=80', 'body' => 'Karimunjawa National Marine Park protects some of Indonesia\'s healthiest reefs — towering sea fans, barrel sponges, reef sharks, Napoleon wrasse, and on lucky days, passing whale sharks.', 'details' => ['Over 30 mapped dive sites', 'Marine life: reef sharks, turtles, whale sharks', 'PADI courses available'], 'order' => 3, 'active' => true],
        ['id' => 'sunset', 'eyebrow' => 'Golden Hour', 'title' => 'Sunset from Bukit Love', 'image' => 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=900&q=80', 'body' => 'Bukit Love is Karimunjawa\'s most iconic sunset viewpoint — a hilltop overlooking the archipelago where the sky erupts in amber and coral as the sun dips below the horizon.', 'details' => ['Elevation: ~120 metres', '10-minute walk from hotel', 'Best from April to October'], 'order' => 4, 'active' => true],
        ['id' => 'mangrove', 'eyebrow' => 'Nature Trail', 'title' => 'Mangrove Forest Trail', 'image' => 'https://images.unsplash.com/photo-1569974498991-d3c12a56ab4c?w=900&q=80', 'body' => 'Walk elevated boardwalks through 27 identified mangrove species in one of Java\'s most pristine mangrove ecosystems — a sanctuary for monitor lizards, mudskippers, and migratory birds.', 'details' => ['1.5 km boardwalk loop', '27 mangrove species identified', 'Great for photography'], 'order' => 5, 'active' => true],
        ['id' => 'motorbike', 'eyebrow' => 'Free Roaming', 'title' => 'Motorbike Island Exploration', 'image' => 'https://images.unsplash.com/photo-1558981806-ec527fa84c39?w=900&q=80', 'body' => 'Rent a motorbike directly from Narayana and discover Karimunjawa at your own rhythm — wind through fishing villages, empty coastal roads, and jungle-fringed hills without a schedule.', 'details' => ['Available directly from hotel', 'Full island circuit: ~3 hours', 'No special licence needed'], 'order' => 6, 'active' => true],
        ['id' => 'fishing', 'eyebrow' => 'Ocean Heritage', 'title' => 'Traditional & Sport Fishing', 'image' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=900&q=80', 'body' => 'Join local fishermen at dawn for a traditional handline session, or charter a sport-fishing boat to target giant trevally, barracuda, and red snapper in the deeper channels.', 'details' => ['Depart 05:30 or 15:00', 'Traditional & sport options', 'Cook your catch at the hotel'], 'order' => 7, 'active' => true],
        ['id' => 'kayaking', 'eyebrow' => 'Coastal Adventure', 'title' => 'Kayaking & Stand-Up Paddleboarding', 'image' => 'https://images.unsplash.com/photo-1472745942893-4b9f730c7668?w=900&q=80', 'body' => 'Glide across glass-calm lagoons and mangrove channels by kayak or SUP — an intimate way to explore the coastline and spot marine life from above in perfectly clear water.', 'details' => ['Best conditions: early morning', 'Single & tandem kayaks available', 'SUP boards with anchor leash'], 'order' => 8, 'active' => true],
    ];
}

// Load room gallery images from settings
$gallerySettings = dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_room_gallery_%' OR setting_key LIKE 'web_room_primary_%'");
$roomGalleries = [];
$roomPrimary = [];
foreach ($gallerySettings as $gs) {
    if (strpos($gs['setting_key'], 'web_room_gallery_') === 0) {
        $type = str_replace('web_room_gallery_', '', $gs['setting_key']);
        $roomGalleries[normalizeRoomTypeKey($type)] = json_decode($gs['setting_value'], true) ?: [];
    }
    if (strpos($gs['setting_key'], 'web_room_primary_') === 0) {
        $type = str_replace('web_room_primary_', '', $gs['setting_key']);
        $roomPrimary[normalizeRoomTypeKey($type)] = $gs['setting_value'];
    }
}

// Galeri Beranda: foto kamar dari pengaturan + foto aktivitas (tanpa duplikat), maksimal 8
$homeGallery = [];
$__seen = [];
$__addImg = function ($src, $caption) use (&$homeGallery, &$__seen) {
    $src = trim((string)$src);
    if ($src === '' || isset($__seen[$src]) || count($homeGallery) >= 8) return;
    $__seen[$src] = true;
    $homeGallery[] = ['src' => preg_match('#^https?://#i', $src) ? $src : BASE_URL . '/' . ltrim($src, '/'), 'caption' => $caption];
};
foreach ($roomGalleries as $__k => $__imgs) {
    foreach (array_slice((array)$__imgs, 0, 2) as $__img) $__addImg($__img, ucwords(str_replace('_', ' ', $__k)) . ' Room');
}
foreach ($homeActivities as $__a) $__addImg($__a['image'] ?? '', $__a['title'] ?? '');
unset($__seen, $__addImg, $__k, $__imgs, $__img, $__a);

// Lokasi untuk peta: koordinat dari pengaturan SEO, bila kosong cari berdasarkan nama hotel
$__geo = [];
foreach (dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('web_schema_latitude','web_schema_longitude')") as $__r) $__geo[$__r['setting_key']] = trim((string)$__r['setting_value']);
$mapQuery = (!empty($__geo['web_schema_latitude']) && !empty($__geo['web_schema_longitude'])) ? $__geo['web_schema_latitude'] . ',' . $__geo['web_schema_longitude'] : 'Narayana Hotel Karimunjawa';
unset($__geo, $__r);
$mapEmbed = 'https://maps.google.com/maps?q=' . rawurlencode($mapQuery) . '&z=15&output=embed';
$mapDirections = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($mapQuery);

require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero -->
<section class="luxury-hero" <?php if (!empty($heroBg)): ?>style="background-image: url('<?= (strpos($heroBg, 'http') === 0) ? htmlspecialchars($heroBg) : BASE_URL . '/' . htmlspecialchars($heroBg) ?>');" <?php endif; ?>>
    <div class="hero-content-luxury">
        <div class="hero-left">
            <p class="hero-eyebrow"><?= htmlspecialchars($heroAccent) ?></p>
            <h1 class="hero-title"><?= $heroTitle ?></h1>
            <div class="hero-line" aria-hidden="true"></div>
            <p class="hero-subtitle"><?= htmlspecialchars($heroSubtitle) ?></p>
            <div class="hero-ctas">
                <a href="<?= BASE_URL ?>/rooms.php" class="btn btn-gold btn-lg">View Rooms</a>
                <a href="<?= htmlspecialchars(bookingUrl()) ?>" class="btn btn-outline-white btn-lg">Book Your Stay</a>
            </div>
        </div>
        <?php if (!empty($heroCards)): ?>
            <div class="hero-right">
                <div class="hero-cards-row">
                    <?php foreach ($heroCards as $ci => $card): ?>
                        <?php if (empty($card['title'])) continue; ?>
                        <div class="hero-room-card <?= $ci === 0 ? 'active' : '' ?>">
                            <?php if (!empty($card['image'])): ?>
                                <img src="<?= (strpos($card['image'], 'http') === 0) ? htmlspecialchars($card['image']) : BASE_URL . '/' . htmlspecialchars($card['image']) ?>" alt="<?= htmlspecialchars($card['title']) ?>" class="hero-card-img">
                            <?php else: ?>
                                <div class="hero-card-img hero-card-placeholder"></div>
                            <?php endif; ?>
                            <div class="hero-card-overlay">
                                <span class="hero-card-sub"><?= htmlspecialchars($card['subtitle'] ?? '') ?></span>
                                <span class="hero-card-title"><?= htmlspecialchars($card['title']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="hero-cards-nav">
                    <button class="hero-nav-btn" aria-label="Previous"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="15 18 9 12 15 6" />
                        </svg></button>
                    <button class="hero-nav-btn" aria-label="Next"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="9 18 15 12 9 6" />
                        </svg></button>
                    <div class="hero-progress">
                        <div class="hero-progress-bar"></div>
                    </div>
                    <span class="hero-counter">0<?= min(count(array_filter($heroCards, function ($c) {
                                                    return !empty($c['title']);
                                                })), 1) ?></span>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <a href="#discover" class="hero-scroll" aria-label="Scroll down"><span></span></a>
    <div class="hero-place"><i class="fas fa-location-dot"></i> Narayana Karimunjawa, Central Java</div>
</section>

<!-- Booking Bar -->
<section class="nrbar" aria-label="Check availability">
    <div class="container">
        <form class="nrbar-form" action="<?= BASE_URL ?>/booking.php" method="GET">
            <label class="nrbar-field">
                <i class="far fa-calendar"></i>
                <span class="nrbar-label">Check-in</span>
                <input type="date" id="bb_checkin" name="checkin" min="<?= $today ?>" value="<?= $today ?>" required>
            </label>
            <label class="nrbar-field">
                <i class="far fa-calendar-check"></i>
                <span class="nrbar-label">Check-out</span>
                <input type="date" id="bb_checkout" name="checkout" min="<?= $tomorrow ?>" value="<?= $tomorrow ?>" required>
            </label>
            <label class="nrbar-field nrbar-field-sm">
                <i class="far fa-user"></i>
                <span class="nrbar-label">Guests</span>
                <select id="bb_guests" name="adults">
                    <?php for ($g = 1; $g <= 8; $g++): ?>
                        <option value="<?= $g ?>" <?= $g === 2 ? 'selected' : '' ?>><?= $g ?> Adult<?= $g > 1 ? 's' : '' ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <div class="nrbar-nights"><strong id="bb_nights">1</strong><span id="bb_nights_l">Night</span></div>
            <button type="submit" class="nrbar-btn">Check Availability <i class="fas fa-arrow-right"></i></button>
        </form>
        <ul class="nrbar-perks">
            <li><i class="fas fa-tag"></i>Best rate booking direct</li>
            <li><i class="fas fa-shield-halved"></i>No online payment</li>
            <li><i class="fab fa-whatsapp"></i>Confirmed by our team on WhatsApp</li>
        </ul>
    </div>
</section>
<script>
    // Check-out mengikuti check-in + jumlah malam
    (function() {
        var ci = document.getElementById('bb_checkin'), co = document.getElementById('bb_checkout');
        function upd() {
            var d1 = new Date(ci.value + 'T00:00:00'), next = new Date(d1); next.setDate(next.getDate() + 1);
            var min = next.toISOString().slice(0, 10);
            co.min = min;
            if (!co.value || co.value <= ci.value) co.value = min;
            var n = Math.max(1, Math.round((new Date(co.value + 'T00:00:00') - d1) / 86400000));
            document.getElementById('bb_nights').textContent = n;
            document.getElementById('bb_nights_l').textContent = n > 1 ? 'Nights' : 'Night';
        }
        ci.addEventListener('change', upd); co.addEventListener('change', upd); upd();
    })();
</script>
<!-- About Narayana -->
<section class="section about-section" id="discover">
    <div class="container">
        <div class="about-layout fade-in">
            <div class="about-content">
                <div class="section-eyebrow">About Us</div>
                <h2 class="section-title">Discover Narayana</h2>
                <div class="divider"></div>
                <p class="about-lead">A newly established retreat in the heart of Karimunjawa, <strong>perfectly positioned</strong> near town with effortless access to the island's finest sunset viewpoint and mountain panoramas.</p>
                <p class="about-text">Narayana is designed around a distinctive architectural concept that harmonises modern comfort with the serenity of nature. Wake to breathtaking mountain views, unwind in tranquil surroundings far from the ordinary, yet remain moments away from everything the island has to offer.</p>
                <div class="about-highlight">
                    <div class="about-highlight-icon"><i class="fas fa-gem"></i></div>
                    <div>
                        <strong>Your Complete Island Experience — All in One Place</strong>
                        <p>Everything you need for the perfect Karimunjawa holiday, under one roof. From a hotel with spectacular views and a restaurant serving the freshest seafood, to motorbike rentals for island exploration and curated ocean trips to hidden snorkelling spots — we bring it all together, effortlessly.</p>
                    </div>
                </div>
            </div>

            <?php if (!empty($homeActivities)): ?>
                <!-- Activities Slideshow (right side) -->
                <div class="act-slideshow">
                    <div class="act-slideshow-viewport">
                        <?php foreach ($homeActivities as $ai => $act): ?>
                            <div class="act-ss-slide <?= $ai === 0 ? 'active' : '' ?>" data-index="<?= $ai ?>">
                                <div class="act-ss-img">
                                    <img src="<?= htmlspecialchars($act['image']) ?>" alt="<?= htmlspecialchars($act['title'] ?? '') ?>" loading="<?= $ai < 2 ? 'eager' : 'lazy' ?>">
                                    <div class="act-ss-gradient"></div>
                                </div>
                                <div class="act-ss-caption">
                                    <span class="act-ss-eyebrow"><?= htmlspecialchars($act['eyebrow'] ?? '') ?></span>
                                    <h4 class="act-ss-title"><?= htmlspecialchars($act['title'] ?? '') ?></h4>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="act-ss-controls">
                        <div class="act-ss-dots">
                            <?php foreach (array_slice($homeActivities, 0, 8) as $ai => $a): ?>
                                <span class="act-ss-dot <?= $ai === 0 ? 'active' : '' ?>" data-index="<?= $ai ?>"></span>
                            <?php endforeach; ?>
                        </div>
                        <a href="<?= BASE_URL ?>/activities.php" class="act-ss-link">All Activities <i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>
</section>

<!-- Rooms -->
<section class="section">
    <div class="container">
        <div class="section-header text-center fade-in">
            <div class="section-eyebrow">Accommodations</div>
            <h2 class="section-title">Our Rooms</h2>
            <div class="divider center"></div>
            <p class="section-desc center">Every room is designed for comfort and relaxation with modern amenities and island charm.</p>
        </div>

        <div class="rooms-grid">
            <?php foreach ($roomTypes as $room):
                $amenities = $room['amenities'] ? explode(',', $room['amenities']) : [];
                $icon = $roomIcons[trim($room['type_name'])] ?? '🏨';
                $avail = (int)$room['available_rooms'];
                $total = (int)$room['total_rooms'];

                if ($avail >= 3) {
                    $ac = 'available';
                    $at = $avail . ' Available';
                } elseif ($avail > 0) {
                    $ac = 'limited';
                    $at = 'Only ' . $avail . ' Left';
                } else {
                    $ac = 'full';
                    $at = 'Fully Booked';
                }
            ?>
                <?php
                $typeName = trim($room['type_name']);
                $typeKey = normalizeRoomTypeKey($typeName);
                $gallery = $roomGalleries[$typeKey] ?? [];
                $primary = $roomPrimary[$typeKey] ?? '';
                // If primary is set, put it first
                if ($primary && in_array($primary, $gallery)) {
                    $gallery = array_values(array_diff($gallery, [$primary]));
                    array_unshift($gallery, $primary);
                }
                ?>
                <div class="room-card fade-in">
                    <div class="room-card-image <?= count($gallery) > 1 ? 'has-gallery' : '' ?>" data-total="<?= count($gallery) ?>">
                        <span class="room-type-badge"><?= htmlspecialchars($typeName) ?></span>
                        <?php if (!empty($gallery)): ?>
                            <?php foreach ($gallery as $gi => $gImg): ?>
                                <div class="room-slide <?= $gi === 0 ? 'active' : '' ?>" style="background-image: url('<?= (strpos($gImg, 'http') === 0) ? htmlspecialchars($gImg) : BASE_URL . '/' . htmlspecialchars($gImg) ?>');"></div>
                            <?php endforeach; ?>
                            <?php if (count($gallery) > 1): ?>
                                <button class="room-nav room-nav-prev" onclick="slideRoom(this,-1)"><i class="fas fa-chevron-left"></i></button>
                                <button class="room-nav room-nav-next" onclick="slideRoom(this,1)"><i class="fas fa-chevron-right"></i></button>
                                <div class="room-dots">
                                    <?php for ($di = 0; $di < count($gallery); $di++): ?>
                                        <span class="room-dot <?= $di === 0 ? 'active' : '' ?>" onclick="goSlide(this,<?= $di ?>)"></span>
                                    <?php endfor; ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="room-photo-empty"><svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 46V26a4 4 0 0 1 4-4h40a4 4 0 0 1 4 4v20"/><path d="M4 46h56v6H4z"/><path d="M14 22v-6a3 3 0 0 1 3-3h30a3 3 0 0 1 3 3v6"/><rect x="14" y="30" width="14" height="8" rx="2"/><rect x="36" y="30" width="14" height="8" rx="2"/></svg><span><?= htmlspecialchars($typeName) ?></span><small>Photos coming soon</small></div>
                        <?php endif; ?>
                    </div>
                    <div class="room-card-body">
                        <h3><?= htmlspecialchars($room['type_name']) ?> Room</h3>
                        <div class="room-meta">
                            <span class="room-meta-item"><i class="fas fa-user"></i> Up to <?= $room['max_occupancy'] ?> guests</span>
                            <span class="room-meta-item"><i class="fas fa-door-open"></i> <?= $total ?> rooms</span>
                        </div>
                        <div class="room-amenities">
                            <?php foreach (array_slice($amenities, 0, 4) as $a): ?>
                                <span><?= htmlspecialchars(nrAmenityLabel($a)) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="room-card-footer">
                            <div class="room-price"><?= formatCurrency($room['base_price']) ?><small>/night</small></div>
                            <span class="avail-badge <?= $ac ?>"><span class="avail-dot"></span><?= $at ?></span>
                        </div>
                        <div class="room-book-btn">
                            <a href="<?= htmlspecialchars(bookingUrl()) ?>" class="btn btn-primary btn-block">Reserve This Room</a>
                            <a href="<?= BASE_URL ?>/rooms.php" class="room-more">View details <i class="fas fa-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Features -->
<section class="section section-alt">
    <div class="container">
        <div class="section-header text-center fade-in">
            <div class="section-eyebrow">Experience</div>
            <h2 class="section-title">Why Narayana</h2>
            <div class="divider center"></div>
        </div>

        <div class="nr-services">
            <div class="nr-service fade-in"><i class="fas fa-map-marker-alt"></i><h4>Prime Location</h4><p>Close to the town centre, harbour and local market, with easy access to every major island destination.</p></div>
            <div class="nr-service fade-in"><i class="fas fa-mountain-sun"></i><h4>Mountain &amp; Sunset Views</h4><p>Sweeping mountain panoramas and moments from Karimunjawa's most beautiful sunset viewpoint.</p></div>

            <div class="nr-service fade-in"><i class="fas fa-utensils"></i><h4>Restaurant</h4><p>Freshly caught seafood and authentic local dishes prepared daily by our kitchen.</p></div>

        </div>
    </div>
</section>

<?php
require_once __DIR__ . '/includes/services-data.php';
$homeServices = array_slice(nrServices(), 0, 4);
?>
<?php if ($homeServices): ?>
<!-- Hotel Services (data dari ADF: Layanan Tamu + katalog Hotel Service) -->
<section class="section" id="services">
    <div class="container">
        <div class="section-header text-center fade-in">
            <div class="section-eyebrow">Hotel Services</div>
            <h2 class="section-title">More Than a Place to Stay</h2>
            <div class="divider center"></div>
            <p class="section-desc">Island trips, motorbikes, transfers and thoughtful extras — arranged personally by our front office.</p>
        </div>
        <div class="nrs-tiles">
            <?php foreach ($homeServices as $sv): ?>
                <a class="nrs-tile fade-in" href="<?= BASE_URL ?>/services.php#<?= htmlspecialchars($sv['key']) ?>">
                    <?php if ($sv['images']): ?><img src="<?= htmlspecialchars($sv['images'][0]) ?>" alt="<?= htmlspecialchars($sv['name']) ?>" loading="lazy"><?php endif; ?>
                    <i class="fas <?= htmlspecialchars($sv['icon']) ?> nrs-tile-ic"></i>
                    <div class="nrs-tile-body">
                        <small><?= htmlspecialchars($sv['eyebrow']) ?></small>
                        <strong><?= htmlspecialchars($sv['name']) ?></strong>
                        <?php if ($sv['price'] > 0): ?><span>From <?= nrsPrice($sv['price']) ?><?= $sv['unit'] !== '' ? ' / ' . htmlspecialchars($sv['unit']) : '' ?></span><?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="nrs-more"><a class="nrs-link" href="<?= BASE_URL ?>/services.php">Explore All Services <i class="fas fa-arrow-right"></i></a></div>
    </div>
</section>
<?php endif; ?>

<!-- Stats -->
<section class="section-dark">
    <div class="container">
        <div class="stats-bar">
            <div class="stat-item fade-in">
                <div class="stat-value"><?= $totalRooms ?></div>
                <div class="stat-label">Total Rooms</div>
            </div>
            <div class="stat-item fade-in">
                <div class="stat-value">27</div>
                <div class="stat-label">Islands to Explore</div>
            </div>
            <div class="stat-item fade-in">
                <div class="stat-value"><?= count($roomTypes) ?></div>
                <div class="stat-label">Room Categories</div>
            </div>
            <div class="stat-item fade-in">
                <div class="stat-value">4.8</div>
                <div class="stat-label">Guest Rating</div>
            </div>
        </div>
    </div>
</section>

<!-- Testimonials -->
<section class="section">
    <div class="container">
        <div class="section-header text-center fade-in">
            <div class="section-eyebrow">Reviews</div>
            <h2 class="section-title">What Our Guests Say</h2>
            <div class="divider center"></div>
        </div>

        <div class="testimonials-grid">
            <div class="testimonial-card fade-in">
                <div class="testimonial-stars">★★★★★</div>
                <blockquote>"The most beautiful place I've ever stayed. Waking up to the sound of waves and stepping onto that white sand — simply magical."</blockquote>
                <div class="testimonial-author">
                    <div class="testimonial-avatar">SC</div>
                    <div>
                        <div class="testimonial-name">Sarah Chen</div>
                        <div class="testimonial-origin">Singapore</div>
                    </div>
                </div>
            </div>
            <div class="testimonial-card fade-in">
                <div class="testimonial-stars">★★★★★</div>
                <blockquote>"Incredible service and attention to detail. The staff arranged a private island tour that was the highlight of our trip."</blockquote>
                <div class="testimonial-author">
                    <div class="testimonial-avatar">MR</div>
                    <div>
                        <div class="testimonial-name">Marco Rossi</div>
                        <div class="testimonial-origin">Italy</div>
                    </div>
                </div>
            </div>
            <div class="testimonial-card fade-in">
                <div class="testimonial-stars">★★★★★</div>
                <blockquote>"A hidden paradise. Karimunjawa is still so unspoiled and Narayana made everything easy. Will definitely return."</blockquote>
                <div class="testimonial-author">
                    <div class="testimonial-avatar">AW</div>
                    <div>
                        <div class="testimonial-name">Andi Wijaya</div>
                        <div class="testimonial-origin">Jakarta, Indonesia</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($homeGallery)): ?>
<!-- Gallery -->
<section class="section section-alt" id="gallery">
    <div class="container">
        <div class="section-header text-center fade-in">
            <div class="section-eyebrow">Gallery</div>
            <h2 class="section-title">Moments at Narayana</h2>
            <div class="divider center"></div>
        </div>
        <div class="nr-gallery fade-in">
            <?php foreach ($homeGallery as $g): ?>
                <a href="<?= htmlspecialchars($g['src']) ?>" data-lightbox="home" data-caption="<?= htmlspecialchars($g['caption']) ?>">
                    <img src="<?= htmlspecialchars($g['src']) ?>" alt="<?= htmlspecialchars($g['caption']) ?>" loading="lazy">
                    <span class="cap"><?= htmlspecialchars($g['caption']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Location -->
<section class="section" id="location">
    <div class="container">
        <div class="section-header text-center fade-in">
            <div class="section-eyebrow">Location</div>
            <h2 class="section-title">Getting Here</h2>
            <div class="divider center"></div>
        </div>
        <div class="nr-location fade-in">
            <div class="nr-loc-card">
                <h3>Narayana Karimunjawa</h3>
                <p>Reach Karimunjawa by fast boat or ferry from Jepara or Semarang. Once you arrive at the harbour, we are only minutes away — just let us know your arrival time.</p>
                <ul class="nr-loc-list">
                    <li><i class="fas fa-location-dot"></i><span><b>Address</b><?= htmlspecialchars(BUSINESS_ADDRESS) ?></span></li>
                    <li><i class="fas fa-ship"></i><span><b>From the harbour</b>Free assistance on request — contact us before you sail.</span></li>
                    <li><i class="fas fa-clock"></i><span><b>Check-in / Check-out</b><?= BUSINESS_CHECKIN_TIME ?> · <?= BUSINESS_CHECKOUT_TIME ?></span></li>
                    <li><i class="fab fa-whatsapp"></i><span><b>WhatsApp</b><a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>" target="_blank" rel="noopener"><?= htmlspecialchars(BUSINESS_PHONE) ?></a></span></li>
                </ul>
                <a href="<?= htmlspecialchars($mapDirections) ?>" target="_blank" rel="noopener" class="btn btn-primary"><i class="fas fa-diamond-turn-right"></i> Get Directions</a>
            </div>
            <div class="nr-map">
                <iframe src="<?= htmlspecialchars($mapEmbed) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Narayana Karimunjawa location map" allowfullscreen></iframe>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="cta-section"<?php if (!empty($heroBg)): ?> style="--cta-bg: url('<?= (strpos($heroBg, 'http') === 0) ? htmlspecialchars($heroBg) : BASE_URL . '/' . htmlspecialchars($heroBg) ?>');"<?php endif; ?>>
    <div class="container">
        <div class="section-eyebrow" style="color:var(--gold-light);">Ready to escape?</div>
        <h2>Begin Your Island Journey</h2>
        <p>Book your stay and experience the magic of Karimunjawa.</p>
        <div class="btn-group" style="justify-content:center;">
            <a href="<?= htmlspecialchars(bookingUrl()) ?>" class="btn btn-gold btn-lg">Reserve Your Room</a>
            <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>?text=Hi%20Narayana%2C%20I%27d%20like%20to%20inquire%20about%20a%20reservation" target="_blank" class="btn btn-outline-white btn-lg">
                <i class="fab fa-whatsapp"></i> WhatsApp
            </a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
    // Room gallery slider
    function slideRoom(btn, dir) {
        const container = btn.closest('.room-card-image');
        const slides = container.querySelectorAll('.room-slide');
        const dots = container.querySelectorAll('.room-dot');
        let current = [...slides].findIndex(s => s.classList.contains('active'));
        slides[current].classList.remove('active');
        if (dots[current]) dots[current].classList.remove('active');
        current = (current + dir + slides.length) % slides.length;
        slides[current].classList.add('active');
        if (dots[current]) dots[current].classList.add('active');
    }

    function goSlide(dot, idx) {
        const container = dot.closest('.room-card-image');
        const slides = container.querySelectorAll('.room-slide');
        const dots = container.querySelectorAll('.room-dot');
        slides.forEach(s => s.classList.remove('active'));
        dots.forEach(d => d.classList.remove('active'));
        slides[idx].classList.add('active');
        dots[idx].classList.add('active');
    }

    // Touch swipe support for room gallery
    document.querySelectorAll('.room-card-image.has-gallery').forEach(card => {
        let startX = 0,
            startY = 0,
            distX = 0;
        card.addEventListener('touchstart', e => {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        }, {
            passive: true
        });
        card.addEventListener('touchmove', e => {
            distX = e.touches[0].clientX - startX;
        }, {
            passive: true
        });
        card.addEventListener('touchend', () => {
            if (Math.abs(distX) > 40) {
                const slides = card.querySelectorAll('.room-slide');
                const dots = card.querySelectorAll('.room-dot');
                let current = [...slides].findIndex(s => s.classList.contains('active'));
                slides[current].classList.remove('active');
                if (dots[current]) dots[current].classList.remove('active');
                current = (current + (distX < 0 ? 1 : -1) + slides.length) % slides.length;
                slides[current].classList.add('active');
                if (dots[current]) dots[current].classList.add('active');
            }
            distX = 0;
        }, {
            passive: true
        });
    });

    // ── Activities Slideshow: wipe + zoom pelan, ±3,2 dtk per slide, panah, swipe, jeda saat hover ──
    (function() {
        const ss = document.querySelector('.act-slideshow');
        if (!ss) return;
        const slides = ss.querySelectorAll('.act-ss-slide');
        const dots = ss.querySelectorAll('.act-ss-dot');
        const total = slides.length;
        if (total === 0) return;
        const DELAY = 3200;
        let current = 0, timer = null, busy = false;

        function goTo(idx) {
            idx = ((idx % total) + total) % total;
            if (idx === current || busy) return;
            busy = true;
            const prev = slides[current];
            prev.classList.add('leaving');
            prev.classList.remove('active');
            if (dots[current]) dots[current].classList.remove('active');
            current = idx;
            slides[current].classList.add('active');
            if (dots[current]) dots[current].classList.add('active');
            setTimeout(() => { prev.classList.remove('leaving'); busy = false; }, 950);
        }
        const start = () => { stop(); if (total > 1) timer = setInterval(() => goTo(current + 1), DELAY); };
        const stop = () => { if (timer) clearInterval(timer); timer = null; };

        dots.forEach(d => d.addEventListener('click', () => { goTo(+d.dataset.index); start(); }));
        if (total > 1) {
            ['prev', 'next'].forEach(dir => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'act-ss-nav act-ss-' + dir;
                b.setAttribute('aria-label', dir === 'prev' ? 'Previous' : 'Next');
                b.innerHTML = '<i class="fas fa-chevron-' + (dir === 'prev' ? 'left' : 'right') + '"></i>';
                b.addEventListener('click', () => { goTo(current + (dir === 'prev' ? -1 : 1)); start(); });
                ss.appendChild(b);
            });
            let x0 = null;
            ss.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; }, { passive: true });
            ss.addEventListener('touchend', e => {
                if (x0 === null) return;
                const dx = e.changedTouches[0].clientX - x0;
                if (Math.abs(dx) > 40) { goTo(current + (dx < 0 ? 1 : -1)); start(); }
                x0 = null;
            });
        }
        ss.addEventListener('mouseenter', () => { stop(); ss.classList.add('is-paused'); });
        ss.addEventListener('mouseleave', () => { ss.classList.remove('is-paused'); start(); });
        document.addEventListener('visibilitychange', () => document.hidden ? stop() : start());
        start();
    })();</script>