<?php

/**
 * NARAYANA KARIMUNJAWA — Reservations
 * Tamu memilih tanggal → kamar tersedia (langsung dari ADF) → isi data → permintaan reservasi terkirim.
 * Pembayaran: DP 50% via transfer setelah dihubungi admin lewat WhatsApp, sisanya di hotel.
 */
$_cfg = __DIR__ . '/config/config.php';
if (!file_exists($_cfg)) $_cfg = dirname(__DIR__) . '/config/config.php';
require_once $_cfg;

$currentPage = 'booking';
$pageTitle = 'Reservations';

$valid = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$ci = $_GET['checkin'] ?? $_GET['check_in'] ?? '';
$co = $_GET['checkout'] ?? $_GET['check_out'] ?? '';
if (!$valid($ci) || $ci < date('Y-m-d')) $ci = date('Y-m-d', strtotime('+1 day'));
if (!$valid($co) || $co <= $ci) $co = date('Y-m-d', strtotime($ci . ' +1 day'));
$adults = max(1, min(20, (int)($_GET['adults'] ?? $_GET['guests'] ?? 2)));
$autoSearch = isset($_GET['checkin']) || isset($_GET['check_in']);

include __DIR__ . '/includes/header.php';
?>

<section class="page-hero nrb-hero">
    <div class="container">
        <div class="section-eyebrow" style="color:var(--gold-light);">Reservations</div>
        <h1>Book Your Stay</h1>
        <p>Choose your dates, pick a room, and our team will confirm your reservation on WhatsApp.</p>
    </div>
</section>

<section class="section nrb">
    <div class="container">
        <ol class="nrb-steps" id="nrbSteps">
            <li class="is-active" data-step="1"><span>1</span>Dates</li>
            <li data-step="2"><span>2</span>Room</li>
            <li data-step="3"><span>3</span>Your Details</li>
        </ol>

        <form class="nrb-search" id="nrbSearch" autocomplete="off">
            <label>Check-in<input type="date" id="nrbCi" value="<?= htmlspecialchars($ci) ?>" min="<?= date('Y-m-d') ?>" required></label>
            <label>Check-out<input type="date" id="nrbCo" value="<?= htmlspecialchars($co) ?>" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required></label>
            <label>Adults<input type="number" id="nrbAdults" value="<?= $adults ?>" min="1" max="20" required></label>
            <label>Children<input type="number" id="nrbKids" value="0" min="0" max="10"></label>
            <button type="submit" class="btn btn-primary">Check Availability</button>
        </form>

        <div class="nrb-msg" id="nrbMsg" hidden></div>
        <div class="nrb-rooms" id="nrbRooms"></div>

        <div class="nrb-details" id="nrbDetails" hidden>
            <form class="nrb-form" id="nrbForm" autocomplete="on" novalidate>
                <h2>Your Details</h2>
                <div class="nrb-grid">
                    <label>Full name<input type="text" name="name" maxlength="80" required autocomplete="name"></label>
                    <label>WhatsApp number<input type="tel" name="phone" maxlength="20" placeholder="+62 812 ..." required autocomplete="tel"></label>
                    <label class="nrb-span">Email<input type="email" name="email" maxlength="120" required autocomplete="email"></label>
                    <label>Adults<input type="number" name="adults" id="nrbFAdults" min="1" max="40" required></label>
                    <label>Children<input type="number" name="children" id="nrbFKids" min="0" max="20"></label>
                    <label class="nrb-span">Special request <small>(optional)</small><textarea name="notes" rows="3" maxlength="500" placeholder="Arrival time, boat schedule, dietary needs..."></textarea></label>
                    <label class="nrb-hp" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>
                <div class="nrb-err" id="nrbErr" hidden></div>
                <div class="nrb-actions">
                    <button type="button" class="btn btn-outline" id="nrbBack">Change Room</button>
                    <button type="submit" class="btn btn-primary" id="nrbSubmit">Send Reservation Request</button>
                </div>
            </form>
            <aside class="nrb-summary" id="nrbSummary"></aside>
        </div>

        <div class="nrb-policy">
            <div><i class="fas fa-shield-halved"></i><strong>No online payment</strong><span>Nothing is charged on this website.</span></div>
            <div><i class="fab fa-whatsapp"></i><strong>Confirmed on WhatsApp</strong><span>Our team contacts you to confirm availability.</span></div>
            <div><i class="fas fa-building-columns"></i><strong>50% deposit</strong><span>Bank transfer to secure the room, balance paid at the hotel.</span></div>
        </div>
    </div>
</section>

<div class="nrb-modal" id="nrbModal" hidden role="dialog" aria-modal="true" aria-labelledby="nrbModalTitle">
    <div class="nrb-modal-box">
        <div class="nrb-modal-icon"><i class="fas fa-check"></i></div>
        <h2 id="nrbModalTitle">Reservation Request Received</h2>
        <p class="nrb-code" id="nrbModalCode"></p>
        <div class="nrb-modal-body" id="nrbModalBody"></div>
        <p class="nrb-modal-note">Please wait for our WhatsApp message to confirm your reservation and deposit details. Your room is secured once the deposit is received.</p>
        <div class="nrb-modal-actions">
            <a class="btn btn-primary" id="nrbModalWa" href="#" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Chat with Admin</a>
            <a class="btn btn-outline" href="<?= BASE_URL ?>/">Back to Home</a>
        </div>
    </div>
</div>

<script>
    (function() {
        var API = <?= json_encode(BASE_URL . '/api') ?>;
        var $ = function(id) { return document.getElementById(id); };
        var state = { data: null, room: null, qty: 1, opened: Date.now() };
        var idr = function(n) { return 'IDR ' + Math.round(n).toLocaleString('id-ID'); };
        var esc = function(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
        var fmtDate = function(d) { return new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }); };

        function step(n) {
            document.querySelectorAll('#nrbSteps li').forEach(function(li) {
                var s = +li.dataset.step;
                li.classList.toggle('is-active', s === n);
                li.classList.toggle('is-done', s < n);
            });
        }

        function msg(text, kind) {
            var m = $('nrbMsg');
            m.hidden = !text;
            m.className = 'nrb-msg' + (kind ? ' is-' + kind : '');
            m.innerHTML = text || '';
        }

        // Check-out minimal sehari setelah check-in
        $('nrbCi').addEventListener('change', function() {
            var d = new Date(this.value + 'T00:00:00');
            d.setDate(d.getDate() + 1);
            var min = d.toISOString().slice(0, 10);
            $('nrbCo').min = min;
            if ($('nrbCo').value <= this.value) $('nrbCo').value = min;
        });

        $('nrbSearch').addEventListener('submit', function(e) {
            e.preventDefault();
            search();
        });

        function search() {
            $('nrbDetails').hidden = true;
            $('nrbRooms').innerHTML = '<div class="nrb-loading"><span></span>Checking availability...</div>';
            msg('');
            step(1);
            var q = 'check_in=' + encodeURIComponent($('nrbCi').value) + '&check_out=' + encodeURIComponent($('nrbCo').value);
            fetch(API + '/availability.php?' + q).then(function(r) { return r.json(); }).then(function(res) {
                if (!res.success) throw new Error(res.error || 'Unable to check availability.');
                state.data = res;
                renderRooms();
            }).catch(function(err) {
                $('nrbRooms').innerHTML = '';
                msg(esc(err.message), 'error');
            });
        }

        function renderRooms() {
            var d = state.data;
            var adults = +$('nrbAdults').value || 1;
            var any = d.rooms.some(function(r) { return r.available > 0; });
            step(2);
            msg(any ? '<strong>' + d.nights + ' night' + (d.nights > 1 ? 's' : '') + '</strong> &middot; ' + fmtDate(d.check_in) + ' &rarr; ' + fmtDate(d.check_out) : 'Sorry, we are fully booked for these dates. Please try other dates or <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>" target="_blank" rel="noopener">chat with us</a>.', any ? '' : 'error');
            $('nrbRooms').innerHTML = d.rooms.map(function(r, i) {
                var need = Math.max(1, Math.ceil(adults / r.max));
                var qty = Math.min(need, Math.max(1, r.available));
                var opts = '';
                for (var k = 1; k <= Math.min(r.available, 10); k++) opts += '<option value="' + k + '"' + (k === qty ? ' selected' : '') + '>' + k + ' room' + (k > 1 ? 's' : '') + '</option>';
                var photo = r.photos[0] ? '<img src="' + esc(r.photos[0]) + '" alt="' + esc(r.name) + '" loading="lazy">' : '<div class="nrb-nophoto"><i class="fas fa-bed"></i></div>';
                var am = r.amenities.slice(0, 5).map(function(a) { return '<li>' + esc(a) + '</li>'; }).join('');
                var left = r.available === 0 ? '<span class="nrb-badge is-out">Fully booked</span>' : (r.available <= 2 ? '<span class="nrb-badge">Only ' + r.available + ' left</span>' : '<span class="nrb-badge is-ok">Available</span>');
                return '<article class="nrb-room' + (r.available ? '' : ' is-out') + '">' +
                    '<div class="nrb-room-photo">' + photo + left + '</div>' +
                    '<div class="nrb-room-body"><h3>' + esc(r.name) + '</h3>' +
                    '<p class="nrb-room-meta"><i class="fas fa-user-group"></i> Up to ' + r.max + ' guests per room</p>' +
                    (am ? '<ul class="nrb-am">' + am + '</ul>' : '') + '</div>' +
                    '<div class="nrb-room-buy"><div class="nrb-price"><small>per night</small>' + idr(r.price) + '</div>' +
                    '<div class="nrb-price-total">' + idr(r.total) + ' for ' + d.nights + ' night' + (d.nights > 1 ? 's' : '') + '</div>' +
                    (r.available ? '<select class="nrb-qty" data-i="' + i + '">' + opts + '</select><button type="button" class="btn btn-primary nrb-pick" data-i="' + i + '">Select</button>' : '') +
                    '</div></article>';
            }).join('');
        }

        $('nrbRooms').addEventListener('click', function(e) {
            var b = e.target.closest('.nrb-pick');
            if (!b) return;
            var i = +b.dataset.i;
            state.room = state.data.rooms[i];
            state.qty = +document.querySelector('.nrb-qty[data-i="' + i + '"]').value || 1;
            $('nrbFAdults').value = $('nrbAdults').value;
            $('nrbFKids').value = $('nrbKids').value;
            $('nrbRooms').innerHTML = '';
            msg('');
            $('nrbDetails').hidden = false;
            $('nrbErr').hidden = true;
            step(3);
            renderSummary();
            $('nrbDetails').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        $('nrbBack').addEventListener('click', function() {
            $('nrbDetails').hidden = true;
            renderRooms();
        });
        ['nrbFAdults', 'nrbFKids'].forEach(function(id) { $(id).addEventListener('input', renderSummary); });

        function renderSummary() {
            var d = state.data, r = state.room;
            var total = r.total * state.qty;
            var dp = Math.round(total * 0.5 / 1000) * 1000;
            var guests = (+$('nrbFAdults').value || 0) + ' adult' + ((+$('nrbFAdults').value || 0) > 1 ? 's' : '') + ((+$('nrbFKids').value || 0) ? ', ' + $('nrbFKids').value + ' child' + (+$('nrbFKids').value > 1 ? 'ren' : '') : '');
            $('nrbSummary').innerHTML = (r.photos[0] ? '<img src="' + esc(r.photos[0]) + '" alt="">' : '') +
                '<div class="nrb-sum-body"><div class="section-eyebrow">Your Stay</div><h3>' + esc(r.name) + '</h3>' +
                '<dl><dt>Check-in</dt><dd>' + fmtDate(d.check_in) + '</dd><dt>Check-out</dt><dd>' + fmtDate(d.check_out) + '</dd>' +
                '<dt>Rooms</dt><dd>' + state.qty + ' &times; ' + d.nights + ' night' + (d.nights > 1 ? 's' : '') + '</dd><dt>Guests</dt><dd>' + guests + '</dd>' +
                '<dt>Rate</dt><dd>' + idr(r.price) + ' / night</dd></dl>' +
                '<div class="nrb-sum-total"><span>Estimated total</span><strong>' + idr(total) + '</strong></div>' +
                '<div class="nrb-sum-dp"><span>Deposit 50% (bank transfer)</span><strong>' + idr(dp) + '</strong></div>' +
                '<p class="nrb-sum-note">Final price is confirmed by our team. Balance is paid at the hotel.</p></div>';
        }

        $('nrbForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var f = this, err = $('nrbErr'), btn = $('nrbSubmit');
            if (!f.checkValidity()) {
                f.reportValidity();
                return;
            }
            var body = {
                check_in: state.data.check_in, check_out: state.data.check_out, room_type_id: state.room.id, rooms: state.qty,
                adults: +f.adults.value, children: +f.children.value || 0, name: f.name.value, phone: f.phone.value,
                email: f.email.value, notes: f.notes.value, website: f.website.value, t: state.opened
            };
            btn.disabled = true;
            btn.textContent = 'Sending...';
            err.hidden = true;
            fetch(API + '/reserve.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) throw new Error(res.error || 'Unable to send your request.');
                    $('nrbModalCode').textContent = 'Reference ' + res.code;
                    $('nrbModalBody').innerHTML = '<dl><dt>Room</dt><dd>' + res.rooms + ' &times; ' + esc(res.room) + '</dd><dt>Stay</dt><dd>' + fmtDate(state.data.check_in) + ' &rarr; ' + fmtDate(state.data.check_out) + '</dd>' +
                        '<dt>Estimated total</dt><dd>' + idr(res.total) + '</dd><dt>Deposit 50%</dt><dd><strong>' + idr(res.dp) + '</strong></dd></dl>';
                    $('nrbModalWa').href = res.whatsapp;
                    $('nrbModal').hidden = false;
                    document.body.style.overflow = 'hidden';
                    f.reset();
                })
                .catch(function(e2) {
                    err.textContent = e2.message;
                    err.hidden = false;
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.textContent = 'Send Reservation Request';
                });
        });

        <?php if ($autoSearch): ?>search();<?php endif; ?>
    })();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
