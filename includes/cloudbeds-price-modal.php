<?php
/**
 * Popup "Cek harga Cloudbeds" per tamu (daftar reservasi). Pemakaian di JS: adfCbPrice(bookingId)
 * Tanpa salin ID: bandingkan harga ADF vs Cloudbeds, tunjukkan patokan harga (OTA → Cloudbeds, langsung → ADF),
 * dan samakan satu klik ke arah yang benar. Teks ID/EN mengikuti bahasa akun; tema gelap mengikuti body[data-theme].
 */
if (!defined('APP_ACCESS')) exit;

$cbpEn = function_exists('getCurrentLanguage') && getCurrentLanguage() === 'en';
$cbpText = [
    'Harga Cloudbeds' => 'Cloudbeds price', 'Memuat…' => 'Loading…', 'Tamu' => 'Guest', 'kamar' => 'rooms', 'Total ADF' => 'ADF total', 'Total Cloudbeds' => 'Cloudbeds total',
    'Selisih' => 'Difference', 'Sama' => 'Same', 'Dibayar' => 'Paid',
    'Patokan harga: Cloudbeds (booking OTA) — ADF mengikuti Cloudbeds.' => 'Price master: Cloudbeds (OTA booking) — ADF follows Cloudbeds.',
    'Patokan harga: ADF (booking langsung) — bisa diubah di ADF, Cloudbeds menyesuaikan.' => 'Price master: ADF (direct booking) — edit it in ADF, Cloudbeds follows.',
    'ADF ikut harga Cloudbeds' => 'ADF follows Cloudbeds price', 'Cloudbeds ikut harga ADF' => 'Cloudbeds follows ADF price', 'Tutup' => 'Close',
    'Samakan harga ADF dengan total Cloudbeds? Cloudbeds tidak diubah.' => 'Set the ADF price to the Cloudbeds total? Cloudbeds is not changed.',
    'Kirim kenaikan harga ke Cloudbeds agar sama dengan ADF?' => 'Send the price increase to Cloudbeds so it matches ADF?',
    'Memproses…' => 'Processing…', 'Harga sudah sama.' => 'Prices already match.',
    'Cloudbeds lebih tinggi dari ADF: penurunan tidak bisa dikirim otomatis (batasan API). Kurangi manual di folio Cloudbeds, atau pilih "ADF ikut harga Cloudbeds".' => 'Cloudbeds is higher than ADF: a decrease cannot be sent automatically (API limit). Reduce it manually in the Cloudbeds folio, or choose "ADF follows Cloudbeds price".',
];
?>
<style>
    .cbp-ov { position: fixed; inset: 0; z-index: 100000; display: none; align-items: center; justify-content: center; padding: 14px; background: rgba(2, 6, 23, .55); backdrop-filter: blur(3px); }
    .cbp-ov.on { display: flex; }
    .cbp { --bg: #fff; --bd: #eef2f7; --tx: #0f172a; --mu: #64748b; --lb: #94a3b8; --tile: #f8fafc; --inb: #dbe3ee; width: 100%; max-width: 420px; max-height: 92vh; overflow: auto; border-radius: 14px; background: var(--bg) !important; color: var(--tx) !important; box-shadow: 0 22px 54px rgba(0, 0, 0, .32); font-family: system-ui, 'Segoe UI', sans-serif; font-size: 12.5px; }
    body[data-theme="dark"] .cbp { --bg: #111a2e; --bd: #22304a; --tx: #e6edf7; --mu: #9fb0c8; --lb: #7f92ad; --tile: #16213a; --inb: #2f4062; box-shadow: 0 22px 54px rgba(0, 0, 0, .6); }
    .cbp * { box-sizing: border-box; }
    .cbp-h { display: flex; align-items: center; gap: 9px; padding: 11px 14px; border-bottom: 1px solid var(--bd); }
    .cbp-ico { flex: none; width: 26px; height: 26px; border-radius: 8px; display: grid; place-items: center; background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
    .cbp-ico svg { width: 14px; height: 14px; fill: none; stroke: #fff; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    .cbp .cbp-h b { flex: 1; font-size: 13.5px; font-weight: 700; color: var(--tx) !important; }
    .cbp .cbp-code { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: var(--tile) !important; color: var(--mu) !important; }
    .cbp-b { padding: 12px 14px 4px; }
    .cbp .cbp-who { font-weight: 700; font-size: 13px; color: var(--tx) !important; margin-bottom: 8px; }
    .cbp .cbp-who small { font-weight: 500; color: var(--mu) !important; }
    .cbp-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 9px; }
    .cbp-grid div { padding: 7px 9px; border-radius: 9px; background: var(--tile) !important; border: 1px solid var(--bd); }
    .cbp .cbp-grid small { display: block; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--lb) !important; }
    .cbp .cbp-grid b { font-size: 13px; font-weight: 700; color: var(--tx) !important; }
    .cbp .cbp-grid .diff.ok b { color: #22c55e !important; } .cbp .cbp-grid .diff.up b { color: #f59e0b !important; } .cbp .cbp-grid .diff.down b { color: #ef4444 !important; }
    .cbp .cbp-master { margin-bottom: 9px; padding: 6px 9px; border-radius: 8px; font-size: 11px; line-height: 1.45; background: rgba(99, 102, 241, .1) !important; color: #6366f1 !important; }
    body[data-theme="dark"] .cbp .cbp-master { color: #a5b4fc !important; }
    .cbp .cbp-msg { margin-bottom: 9px; padding: 7px 9px; border-radius: 8px; font-size: 11.5px; line-height: 1.5; background: var(--tile) !important; color: var(--mu) !important; border: 1px solid var(--bd); }
    .cbp .cbp-msg.ok { color: #16a34a !important; border-color: rgba(34, 197, 94, .4); } .cbp .cbp-msg.bad { color: #ef4444 !important; border-color: rgba(239, 68, 68, .4); } .cbp .cbp-msg.warn { color: #d97706 !important; border-color: rgba(245, 158, 11, .4); }
    .cbp-f { display: flex; flex-wrap: wrap; gap: 7px; justify-content: flex-end; padding: 8px 14px 12px; }
    .cbp .cbp-f button { height: 32px; padding: 0 12px; border-radius: 9px; border: 1px solid var(--inb) !important; background: var(--bg) !important; color: var(--tx) !important; font-size: 12px; font-weight: 700; cursor: pointer; }
    .cbp .cbp-f button.pri { background: linear-gradient(180deg, #8b5cf6, #6d28d9) !important; color: #fff !important; border-color: transparent !important; }
    .cbp .cbp-f button:disabled { opacity: .55; cursor: wait; }
    .cbp .cbp-load { padding: 34px 14px; text-align: center; color: var(--mu) !important; }
</style>
<div class="cbp-ov" id="cbpOv" role="dialog" aria-modal="true"><div class="cbp" id="cbpBox"></div></div>
<script>
    (function() {
        const API = <?php echo json_encode(rtrim(BASE_URL, '/') . '/api/cloudbeds-price.php'); ?>;
        const EN = <?php echo $cbpEn ? 'true' : 'false'; ?> || (document.documentElement.lang || '').toLowerCase() === 'en';
        const TX = <?php echo json_encode($cbpText, JSON_UNESCAPED_UNICODE); ?>;
        const t = s => (EN && TX[s]) ? TX[s] : s;
        // Pesan dari server berbahasa Indonesia: di mode English diterjemahkan per pola
        const MSG_EN = [
            [/^Booking ini belum tertaut ke reservasi Cloudbeds\.?$/, 'This booking is not linked to a Cloudbeds reservation yet.'],
            [/^Total Cloudbeds tidak terbaca(.*)$/, 'Could not read the Cloudbeds total$1'],
            [/^Harga sudah sama \(Rp ([\d.]+)\)\.?$/, 'Prices are already equal (Rp $1).'],
            [/^Total sudah sama dengan Cloudbeds \(Rp ([\d.]+)\)$/, 'The total already equals Cloudbeds (Rp $1)'],
            [/^Cloudbeds disamakan dengan ADF: (.+)\.$/, 'Cloudbeds now matches ADF: $1.'],
            [/^Total (\S+) disamakan dengan Cloudbeds: (.+?)( \(dibagi ke (\d+) kamar\))?\.$/, 'Total $1 now matches Cloudbeds: $2 (split across rooms where applicable).'],
            [/^Booking OTA: harga mengikuti Cloudbeds.*$/, 'OTA booking: the price follows Cloudbeds and is not sent from ADF. Use "ADF follows Cloudbeds price".'],
            [/^Cloudbeds lebih tinggi Rp ([\d.]+) dari ADF\..*$/, 'Cloudbeds is Rp $1 higher than ADF. A decrease cannot be sent automatically (Cloudbeds API limit): reduce it manually in the Cloudbeds folio, or choose "ADF follows Cloudbeds price".'],
            [/^Pembayaran tercatat Rp ([\d.]+) lebih besar dari total Cloudbeds Rp ([\d.]+).*$/, 'Recorded payments (Rp $1) exceed the Cloudbeds total (Rp $2) — adjust the payments first.'],
            [/^Cloudbeds menolak: (.*)$/, 'Cloudbeds refused: $1'],
            [/^Cloudbeds belum terhubung$/, 'Cloudbeds is not connected'],
            [/^Booking tidak ditemukan$/, 'Booking not found'],
            [/^Booking ini belum tertaut ke reservasi Cloudbeds$/, 'This booking is not linked to a Cloudbeds reservation yet.'],
        ];
        const tm = s => { s = String(s || ''); if (!EN) return s; for (const [re, to] of MSG_EN) if (re.test(s)) return s.replace(re, to); return s; };
        const ov = document.getElementById('cbpOv'), box = document.getElementById('cbpBox');
        const rp = n => 'Rp ' + (Number(n) || 0).toLocaleString('id-ID');
        function el(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
        function post(data) {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v));
            return fetch(API, { method: 'POST', body: fd, credentials: 'include' }).then(r => r.json());
        }

        window.adfCbPrice = function(bookingId) {
            let changed = false;
            const close = () => { ov.classList.remove('on'); document.removeEventListener('keydown', onKey); if (changed) location.reload(); };
            const onKey = e => { if (e.key === 'Escape') close(); };
            document.addEventListener('keydown', onKey);
            ov.onclick = e => { if (e.target === ov) close(); };
            ov.classList.add('on');

            function loading() { box.innerHTML = ''; box.appendChild(el('div', 'cbp-load', t('Memuat…'))); }
            function head(code) {
                const h = el('div', 'cbp-h'), ico = el('span', 'cbp-ico');
                ico.innerHTML = '<svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
                h.appendChild(ico); h.appendChild(el('b', '', t('Harga Cloudbeds'))); if (code) h.appendChild(el('span', 'cbp-code', code));
                return h;
            }
            function closeBtn(f) { const c = el('button', '', t('Tutup')); c.type = 'button'; c.onclick = close; f.appendChild(c); }

            function render(st, note, noteCls) {
                box.innerHTML = '';
                box.appendChild(head(st.code));
                const body = el('div', 'cbp-b');
                if (!st.ok || !st.linked || st.cb_total === undefined) {
                    body.appendChild(el('div', 'cbp-msg ' + (st.ok ? 'warn' : 'bad'), tm(st.msg || '-')));
                    box.appendChild(body);
                    const f = el('div', 'cbp-f'); closeBtn(f); box.appendChild(f);
                    return;
                }
                const who = el('div', 'cbp-who', st.guest || '-');
                who.appendChild(el('small', '', ' · ' + st.rooms + ' ' + (st.rooms > 1 ? t('kamar') : (EN ? 'room' : 'kamar')) + ' · Cloudbeds #' + st.cb_id));
                body.appendChild(who);
                const g = el('div', 'cbp-grid');
                const cell = (label, val, cls) => { const d = el('div', cls || ''); d.appendChild(el('small', '', label)); d.appendChild(el('b', '', val)); g.appendChild(d); };
                cell(t('Total ADF'), rp(st.adf_total));
                cell(t('Total Cloudbeds'), rp(st.cb_total));
                const diff = st.diff;
                cell(t('Selisih'), Math.abs(diff) < 1 ? t('Sama') + ' ✓' : (diff > 0 ? '+' : '−') + rp(Math.abs(diff)), 'diff ' + (Math.abs(diff) < 1 ? 'ok' : (diff > 0 ? 'up' : 'down')));
                cell(t('Dibayar'), rp(st.adf_paid));
                body.appendChild(g);
                body.appendChild(el('div', 'cbp-master', st.master === 'cloudbeds'
                    ? t('Patokan harga: Cloudbeds (booking OTA) — ADF mengikuti Cloudbeds.')
                    : t('Patokan harga: ADF (booking langsung) — bisa diubah di ADF, Cloudbeds menyesuaikan.')));
                if (note) body.appendChild(el('div', 'cbp-msg ' + (noteCls || ''), tm(note)));
                else if (diff < -0.99 && st.master === 'adf') body.appendChild(el('div', 'cbp-msg warn', t('Cloudbeds lebih tinggi dari ADF: penurunan tidak bisa dikirim otomatis (batasan API). Kurangi manual di folio Cloudbeds, atau pilih "ADF ikut harga Cloudbeds".')));
                else if (Math.abs(diff) < 1) body.appendChild(el('div', 'cbp-msg ok', t('Harga sudah sama.')));
                box.appendChild(body);

                const f = el('div', 'cbp-f');
                if (Math.abs(diff) >= 1) {
                    const a = el('button', st.master === 'cloudbeds' ? 'pri' : '', t('ADF ikut harga Cloudbeds')); a.type = 'button';
                    a.onclick = () => act('adopt', 'Samakan harga ADF dengan total Cloudbeds? Cloudbeds tidak diubah.', a);
                    f.appendChild(a);
                    if (st.master === 'adf' && diff > 0) {
                        const p = el('button', 'pri', t('Cloudbeds ikut harga ADF')); p.type = 'button';
                        p.onclick = () => act('push', 'Kirim kenaikan harga ke Cloudbeds agar sama dengan ADF?', p);
                        f.appendChild(p);
                    }
                }
                closeBtn(f);
                box.appendChild(f);
            }

            function act(action, confirmText, btn) {
                if (!window.confirm(t(confirmText))) return;
                btn.disabled = true; btn.textContent = t('Memproses…');
                post({ action: action, booking_id: bookingId }).then(r => {
                    if (r.ok) changed = true;
                    render(r.status || { ok: false, msg: r.msg }, r.msg, r.ok ? 'ok' : (r.manual ? 'warn' : 'bad'));
                }).catch(() => render({ ok: false, msg: 'Server error' }));
            }

            loading();
            post({ action: 'status', booking_id: bookingId }).then(r => render(r)).catch(() => render({ ok: false, msg: 'Server error' }));
        };
    })();
</script>
