<?php
/**
 * Popup "Reservasi baru dari website" untuk staf Front Desk di bisnis hotel.
 * Memeriksa api/quotation.php?action=web_pending tiap 25 detik; Follow-up membuka detail penawaran di kalender.
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
    ? ['title' => 'New website reservation', 'follow' => 'Follow up', 'later' => 'Later', 'nights' => 'night(s)', 'adults' => 'adult(s)']
    : ['title' => 'Reservasi baru dari website', 'follow' => 'Follow-up', 'later' => 'Nanti', 'nights' => 'malam', 'adults' => 'dewasa'];
?>
<script>
    (function() {
        const API = <?php echo json_encode(rtrim(BASE_URL, '/') . '/api/quotation.php'); ?>;
        const CAL = <?php echo json_encode(rtrim(BASE_URL, '/') . '/modules/frontdesk/calendar.php'); ?>;
        const TX = <?php echo json_encode($wrpTx); ?>;
        const shown = new Set();
        let box = null;

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

        function check() {
            if (document.hidden) return;
            post({ action: 'web_pending' }).then(res => {
                if (!res || !res.ok || !res.rows || !res.rows.length) return;
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
</style>
