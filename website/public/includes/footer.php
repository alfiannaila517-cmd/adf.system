<!-- Footer -->
<footer class="footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-about">
                <h5>Narayana Karimunjawa</h5>
                <p>A tranquil island retreat in the heart of Karimunjawa, close to town, sunset viewpoints and the marine park. Natural beauty meets warm Indonesian hospitality.</p>
                <div class="footer-social">
                    <a href="https://www.instagram.com/<?= BUSINESS_INSTAGRAM ?>" target="_blank" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>" target="_blank" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                    <a href="mailto:<?= BUSINESS_EMAIL ?>" aria-label="Email"><i class="fas fa-envelope"></i></a>
                </div>
            </div>
            <div>
                <h5>Explore</h5>
                <ul class="footer-links">
                    <li><a href="<?= BASE_URL ?>/rooms.php">Rooms</a></li>
                    <li><a href="<?= BASE_URL ?>/activities.php">Activities</a></li>
                    <li><a href="<?= BASE_URL ?>/destinations.php">Destinations</a></li>
                    <li><a href="<?= htmlspecialchars(bookingUrl()) ?>">Reservations</a></li>
                    <li><a href="<?= BASE_URL ?>/contact.php">Contact</a></li>
                </ul>
            </div>
            <div>
                <h5>Information</h5>
                <ul class="footer-links">
                    <li><a href="<?= htmlspecialchars(bookingUrl()) ?>">Check Availability</a></li>
                    <li><a href="<?= BASE_URL ?>/contact.php">Cancellation Policy</a></li>
                    <li><a href="<?= BASE_URL ?>/#location">Getting Here</a></li>
                    <li><a href="<?= BASE_URL ?>/#gallery">Photo Gallery</a></li>
                </ul>
            </div>
            <div>
                <h5>Contact</h5>
                <ul class="footer-contact">
                    <li>
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?= BUSINESS_ADDRESS ?></span>
                    </li>
                    <li>
                        <i class="fas fa-phone"></i>
                        <a href="tel:<?= BUSINESS_PHONE ?>"><?= BUSINESS_PHONE ?></a>
                    </li>
                    <li>
                        <i class="fas fa-envelope"></i>
                        <a href="mailto:<?= BUSINESS_EMAIL ?>"><?= BUSINESS_EMAIL ?></a>
                    </li>
                    <li>
                        <i class="fas fa-clock"></i>
                        <span>Check-in <?= BUSINESS_CHECKIN_TIME ?> · Check-out <?= BUSINESS_CHECKOUT_TIME ?></span>
                    </li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <span>&copy; <?= date('Y') ?> Narayana Karimunjawa. All rights reserved.</span>
            <span>Karimunjawa Island, Central Java, Indonesia</span>
        </div>
    </div>
</footer>

<!-- Tombol WhatsApp melayang (semua halaman) -->
<a class="wa-float" href="https://wa.me/<?= BUSINESS_WHATSAPP ?>?text=<?= rawurlencode('Hi Narayana, I would like to ask about a reservation.') ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp">
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.26-.46-2.39-1.48-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.07c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.7.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35zM12.05 21.79a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.83 9.83 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.89 9.88m8.41-18.3A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.89 0-3.18-1.24-6.17-3.48-8.42z"/></svg>
    <span>Chat with us</span>
</a>

<!-- Lightbox bersama: elemen [data-lightbox="grup"] dengan href/data-src gambar & data-caption -->
<div class="nr-lightbox" id="nrLightbox" role="dialog" aria-modal="true" aria-label="Photo viewer">
    <button type="button" class="lb-close" aria-label="Close">&times;</button>
    <button type="button" class="lb-prev" aria-label="Previous">&#8249;</button>
    <img alt="">
    <button type="button" class="lb-next" aria-label="Next">&#8250;</button>
    <div class="cap"></div>
</div>

<script>
    // Navbar scroll
    const nav = document.getElementById('mainNav');
    window.addEventListener('scroll', () => nav.classList.toggle('scrolled', window.scrollY > 50));
    if (window.scrollY > 50) nav.classList.add('scrolled');

    // Mobile nav with overlay
    const navToggle = document.getElementById('navToggle');
    const navLinks = document.getElementById('navLinks');
    // Create overlay element
    const navOverlay = document.createElement('div');
    navOverlay.className = 'nav-overlay';
    document.body.appendChild(navOverlay);

    function toggleMobileNav() {
        const isOpen = navLinks.classList.toggle('open');
        navOverlay.classList.toggle('active', isOpen);
        document.body.style.overflow = isOpen ? 'hidden' : '';
    }

    function closeMobileNav() {
        navLinks.classList.remove('open');
        navOverlay.classList.remove('active');
        document.body.style.overflow = '';
    }
    navToggle?.addEventListener('click', toggleMobileNav);
    navOverlay.addEventListener('click', closeMobileNav);
    document.querySelectorAll('#navLinks a').forEach(link => {
        link.addEventListener('click', closeMobileNav);
    });

    // Tombol tema terang / gelap
    document.getElementById('themeToggle')?.addEventListener('click', () => {
        const root = document.documentElement;
        const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('nr-theme', next); } catch (e) {}
    });

    // Lightbox foto (galeri Beranda & foto kamar)
    (function() {
        const lb = document.getElementById('nrLightbox');
        if (!lb) return;
        const img = lb.querySelector('img'), cap = lb.querySelector('.cap');
        let items = [], idx = 0;
        const show = (i) => {
            idx = (i + items.length) % items.length;
            const el = items[idx];
            img.src = el.getAttribute('data-src') || el.getAttribute('href');
            cap.textContent = el.getAttribute('data-caption') || '';
        };
        document.addEventListener('click', (e) => {
            const el = e.target.closest('[data-lightbox]');
            if (!el) return;
            e.preventDefault();
            items = [...document.querySelectorAll('[data-lightbox="' + el.getAttribute('data-lightbox') + '"]')];
            show(items.indexOf(el));
            lb.classList.add('open');
            document.body.style.overflow = 'hidden';
        });
        const close = () => { lb.classList.remove('open'); document.body.style.overflow = ''; };
        lb.querySelector('.lb-close').addEventListener('click', close);
        lb.querySelector('.lb-prev').addEventListener('click', () => show(idx - 1));
        lb.querySelector('.lb-next').addEventListener('click', () => show(idx + 1));
        lb.addEventListener('click', (e) => { if (e.target === lb) close(); });
        document.addEventListener('keydown', (e) => {
            if (!lb.classList.contains('open')) return;
            if (e.key === 'Escape') close();
            if (e.key === 'ArrowLeft') show(idx - 1);
            if (e.key === 'ArrowRight') show(idx + 1);
        });
    })();

    // Fade-in observer
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.1
    });
    document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));

    // Utils
    function formatCurrency(amount) {
        return 'Rp ' + new Intl.NumberFormat('id-ID').format(amount);
    }

    function showToast(message, type = 'info') {
        const existing = document.querySelector('.toast');
        if (existing) existing.remove();
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 400);
        }, 4000);
    }

    async function apiCall(url, method = 'GET', data = null) {
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json'
            }
        };
        if (data && method !== 'GET') options.body = JSON.stringify(data);
        const response = await fetch(url, options);
        return response.json();
    }
</script>
</body>

</html>