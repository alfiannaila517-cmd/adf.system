<?php
/**
 * Popup untuk staf Front Desk di bisnis hotel (memeriksa api/quotation.php?action=web_pending tiap 25 detik):
 *  - "Reservasi baru dari website" → Follow-up membuka detail penawaran di kalender,
 *  - "Blok kamar segera dibuka" (hold penawaran tinggal ≤ 60 menit) → Perpanjang 24 jam / Biarkan (lepas otomatis).
 * Dipanggil dari includes/footer.php.
 */
if (!defined('APP_ACCESS')) exit;

$wrpOk = isset($auth) && is_object($auth) && method_exists($auth, 'hasPermission') && $auth->hasPermission('frontdesk');
if ($wrpOk) {
    try {
        $wrpCfg = function_exists('getActiveBusinessConfig') ? getActiveBusinessConfig() : [];
        $wrpOk = ($wrpCfg['business_type'] ?? '') === 'hotel';
    } catch (\Throwable $e) {
        $wrpOk = false;
    }
}
if (!$wrpOk) return;

$wrpEn = function_exists('getCurrentLanguage') && getCurrentLanguage() === 'en';
$wrpTx = $wrpEn
    ? ['title' => 'New website reservation', 'follow' => 'Follow up', 'later' => 'Later', 'nights' => 'night(s)', 'adults' => 'adult(s)',
        'hTitle' => 'Room block ending soon', 'hText' => 'Rooms open for booking again (incl. OTAs) in', 'min' => 'min', 'room' => 'Room',
        'extend' => 'Extend 24 hours', 'leave' => 'Let it expire', 'view' => 'View', 'extended' => 'Hold extended until', 'failed' => 'Failed']
    : ['title' => 'Reservasi baru dari website', 'follow' => 'Follow-up', 'later' => 'Nanti', 'nights' => 'malam', 'adults' => 'dewasa',
        'hTitle' => 'Blok kamar segera dibuka', 'hText' => 'Kamar bisa dibooking lagi (termasuk OTA) dalam', 'min' => 'menit', 'room' => 'Kamar',
        'extend' => 'Perpanjang 24 jam', 'leave' => 'Biarkan', 'view' => 'Lihat', 'extended' => 'Hold diperpanjang sampai', 'failed' => 'Gagal'];
?>
<script>
    (function() {
        const API = <?php echo json_encode(rtrim(BASE_URL, '/') . '/api/quotation.php'); ?>;
        const CAL = <?php echo json_encode(rtrim(BASE_URL, '/') . '/modules/frontdesk/calendar.php'); ?>;
        const TX = <?php echo json_encode($wrpTx); ?>;
        const shown = new Set();
        const shownHold = new Set();
        let box = null, hbox = null;

        const post = d => { const f = new FormData(); Object.entries(d).forEach(([k, v]) => f.append(k, v)); return fetch(API, { method: 'POST', body: f, credentials: 'include' }).then(r => r.json()); };
        const rp = n => 'Rp ' + Math.round(Number(n) || 0).toLocaleString('id-ID');
        const fd = d => { const x = new Date(d + 'T00:00:00'); return isNaN(x) ? d : x.toLocaleDateString(<?php echo json_encode($wrpEn ? 'en-GB' : 'id-ID'); ?>, { day: '2-digit', month: 'short' }); };
        const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };

        // Bunyi pendek tanpa file audio
        function chime() {
            try {
                const ac = new(window.AudioContext || window.webkitAudioContext)();
                [880, 1175].forEach((hz, i) => {
                    const o = ac.createOscillator(), g = ac.createGain();
                    o.frequency.value = hz; o.connect(g); g.connect(ac.destination);
                    g.gain.setValueAtTime(.0001, ac.currentTime + i * .18);
                    g.gain.exponentialRampToValueAtTime(.25, ac.currentTime + i * .18 + .02);
                    g.gain.exponentialRampToValueAtTime(.0001, ac.currentTime + i * .18 + .3);
                    o.start(ac.currentTime + i * .18); o.stop(ac.currentTime + i * .18 + .32);
                });
            } catch (e) {}
        }

        function seen(ids) { if (ids.length) post({ action: 'web_seen', ids: ids.join(',') }).catch(() => {}); }

        function render(rows) {
            if (!box) {
                box = el('div', 'qt-wpop'); box.setAttribute('role', 'alert');
                document.body.appendChild(box);
            }
            box.innerHTML = '';
            const h = el('div', 'qt-wpop-h'); h.appendChild(el('span', '', '🌐 ' + TX.title + (rows.length > 1 ? ' (' + rows.length + ')' : '')));
            const x = el('button', 'qt-wpop-x', '×'); x.type = 'button'; x.title = TX.later;
            x.onclick = () => { seen(rows.map(r => r.id)); box.remove(); box = null; };
            h.appendChild(x); box.appendChild(h);
            rows.slice(0, 3).forEach(r => {
                const i = el('div', 'qt-wpop-i');
                i.appendChild(el('b', '', r.guest_name + ' · ' + r.quote_code));
                i.appendChild(el('small', '', fd(r.check_in) + ' → ' + fd(r.check_out) + ' · ' + r.nights + ' ' + TX.nights + ' · ' + (parseInt(r.adults, 10) || 0) + ' ' + TX.adults + ' · ' + rp(r.total)));
                const a = el('div', 'qt-wpop-a');
                const later = el('button', '', TX.later); later.type = 'button';
                later.onclick = () => { seen([r.id]); i.remove(); if (!box.querySelector('.qt-wpop-i')) { box.remove(); box = null; } };
                const go = el('button', 'pri', TX.follow); go.type = 'button';
                go.onclick = () => {
                    seen([r.id]);
                    if (typeof window.adfQuoteView === 'function') { window.adfQuoteView(parseInt(r.id, 10)); i.remove(); if (!box.querySelector('.qt-wpop-i')) { box.remove(); box = null; } }
                    else location.href = CAL + '?quote_view=' + r.id;
                };
                a.appendChild(later); a.appendChild(go); i.appendChild(a); box.appendChild(i);
            });
        }

        // ---- Hold penawaran hampir habis: Perpanjang 24 jam / Biarkan ----
        function renderHold(rows) {
            if (!rows.length) { if (hbox) { hbox.remove(); hbox = null; } return; }
            if (!hbox) {
                hbox = el('div', 'qt-wpop qt-wpop-hold'); hbox.setAttribute('role', 'alert');
                document.body.appendChild(hbox);
            }
            hbox.innerHTML = '';
            const h = el('div', 'qt-wpop-h'); h.appendChild(el('span', '', '⏳ ' + TX.hTitle + (rows.length > 1 ? ' (' + rows.length + ')' : '')));
            hbox.appendChild(h);
            const drop = i => { i.remove(); if (!hbox.querySelector('.qt-wpop-i')) { hbox.remove(); hbox = null; } };
            rows.slice(0, 3).forEach(r => {
                const until = new Date(String(r.hold_until).replace(' ', 'T'));
                const mins = Math.max(0, Math.ceil((until - Date.now()) / 60000));
                const i = el('div', 'qt-wpop-i');
                i.appendChild(el('b', '', (r.source === 'website' ? '🌐 ' : '') + r.guest_name + ' · ' + r.quote_code));
                i.appendChild(el('small', '', TX.room + ' ' + (r.rooms || '-') + ' · ' + fd(r.check_in) + ' → ' + fd(r.check_out)));
                i.appendChild(el('div', 'qt-wpop-t', TX.hText + ' ' + mins + ' ' + TX.min + ' (' + until.toLocaleTimeString(<?php echo json_encode($wrpEn ? 'en-GB' : 'id-ID'); ?>, { hour: '2-digit', minute: '2-digit' }) + ')'));
                const a = el('div', 'qt-wpop-a');
                const leave = el('button', '', TX.leave); leave.type = 'button';
                leave.onclick = () => { post({ action: 'hold_dismiss', id: r.id }).catch(() => {}); drop(i); };
                const view = el('button', '', TX.view); view.type = 'button';
                view.onclick = () => { if (typeof window.adfQuoteView === 'function') window.adfQuoteView(parseInt(r.id, 10)); else location.href = CAL + '?quote_view=' + r.id; };
                const ext = el('button', 'pri', TX.extend); ext.type = 'button';
                ext.onclick = () => {
                    ext.disabled = true;
                    post({ action: 'extend_hold', id: r.id }).then(x => {
                        if (!x.ok) throw new Error(x.msg || '');
                        drop(i);
                        window.alert(TX.extended + ' ' + new Date(String(x.hold_until).replace(' ', 'T')).toLocaleString(<?php echo json_encode($wrpEn ? 'en-GB' : 'id-ID'); ?>, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }));
                    }).catch(e => { ext.disabled = false; window.alert(TX.failed + (e.message ? ': ' + e.message : '')); });
                };
                a.appendChild(leave); a.appendChild(view); a.appendChild(ext); i.appendChild(a); hbox.appendChild(i);
            });
        }

        function check() {
            if (document.hidden) return;
            post({ action: 'web_pending' }).then(res => {
                if (!res || !res.ok) return;
                const exp = res.expiring || [];
                const freshHold = exp.some(r => !shownHold.has(r.id + '|' + r.hold_until));
                exp.forEach(r => shownHold.add(r.id + '|' + r.hold_until));
                renderHold(exp);
                if (freshHold) chime();
                if (!res.rows || !res.rows.length) return;
                const fresh = res.rows.some(r => !shown.has(r.id));
                res.rows.forEach(r => shown.add(r.id));
                render(res.rows);
                if (fresh) chime();
            }).catch(() => {});
        }

        setTimeout(check, 2500);
        setInterval(check, 25000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
    })();
</script>
<style>
    .qt-wpop { position: fixed; right: 16px; bottom: 16px; z-index: 10050; width: 320px; max-width: calc(100vw - 32px); border-radius: 14px; background: #fff; color: #0f172a; box-shadow: 0 18px 50px rgba(0, 0, 0, .3); border-top: 4px solid #2563eb; font-family: system-ui, 'Segoe UI', sans-serif; font-size: 12.5px; overflow: hidden; animation: qtwp .35s ease; }
    body[data-theme="dark"] .qt-wpop { background: #111a2e; color: #e6edf7; }
    @keyframes qtwp { from { transform: translateY(20px); opacity: 0; } }
    .qt-wpop-h { padding: 10px 12px; font-weight: 800; display: flex; justify-content: space-between; align-items: center; }
    .qt-wpop-x { border: 0; background: none; color: inherit; font-size: 18px; cursor: pointer; line-height: 1; }
    .qt-wpop-i { padding: 8px 12px; border-top: 1px solid rgba(148, 163, 184, .25); line-height: 1.45; }
    .qt-wpop-i b { display: block; }
    .qt-wpop-i small { opacity: .75; }
    .qt-wpop-a { display: flex; gap: 6px; margin-top: 6px; }
    .qt-wpop-a button { flex: 1; padding: 6px; border-radius: 8px; border: 1px solid rgba(148, 163, 184, .4); background: transparent; color: inherit; font: inherit; font-weight: 700; cursor: pointer; }
    .qt-wpop-a button.pri { background: #2563eb; border-color: #2563eb; color: #fff; }
    .qt-wpop-hold { bottom: auto; top: 76px; border-top-color: #f59e0b; }
    .qt-wpop-hold .qt-wpop-a button.pri { background: #d97706; border-color: #d97706; }
    .qt-wpop-t { margin-top: 4px; font-weight: 700; color: #b45309; }
    body[data-theme="dark"] .qt-wpop-t { color: #fbbf24; }
</style>
