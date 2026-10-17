<?php
/**
 * UI penawaran kamar untuk kalender: modal "Buat Penawaran", popup detail penawaran (Cetak / Konfirmasi / Batalkan),
 * dan alur "Konfirmasi" yang membuka form reservasi terisi. Teks ID/EN lewat peta di bawah (tidak memakai kamus global).
 * JS publik: adfQuoteCreate(state), adfQuoteEdit(id), adfQuoteView(id), adfQuoteConfirm(id). URL: calendar.php?quote=<id> (konfirmasi), ?quote_view=<id>, ?quote_edit=<id>.
 */
if (!defined('APP_ACCESS')) exit;

$qtEn = function_exists('getCurrentLanguage') && getCurrentLanguage() === 'en';
$qtText = [
    'Buat Penawaran' => 'Create Quotation', 'Nama tamu' => 'Guest name', 'WhatsApp / telepon' => 'WhatsApp / phone', 'Email' => 'Email', 'Berlaku' => 'Valid for', '1 hari' => '1 day', '3 hari' => '3 days', '7 hari' => '7 days',
    '14 hari' => '14 days', '30 hari' => '30 days', 'Catatan (opsional)' => 'Notes (optional)', 'Batal' => 'Cancel', 'Simpan & Cetak' => 'Save & Print', 'Menyimpan…' => 'Saving…',
    'Nama tamu wajib diisi.' => 'Guest name is required.', 'Penawaran tersimpan' => 'Quotation saved', 'Kamar ini belum diblok — masih bisa dibooking orang lain sampai Anda mengonfirmasi.' => 'These rooms are NOT blocked — others can still book them until you confirm.',
    'malam' => 'night(s)', 'kamar' => 'room(s)', 'Total' => 'Total', 'Penawaran' => 'Quotation', 'Berlaku sampai' => 'Valid until', 'Cetak' => 'Print', 'Konfirmasi (jadikan booking)' => 'Confirm (make a booking)',
    'Batalkan penawaran' => 'Cancel quotation', 'Tutup' => 'Close', 'Terbuka' => 'Open', 'Terkonfirmasi' => 'Confirmed', 'Dibatalkan' => 'Cancelled', 'Kedaluwarsa' => 'Expired',
    'Batalkan penawaran ini? Bar hijau di kalender akan hilang.' => 'Cancel this quotation? The green bar in the calendar will disappear.',
    'Kamar berikut sudah terisi / diblok pada tanggal ini, penawaran tidak bisa dikonfirmasi:' => 'These rooms are already booked / blocked on these dates, so the quotation cannot be confirmed:',
    'Penawaran ini sudah ' => 'This quotation is already ', 'Gagal memuat penawaran.' => 'Could not load the quotation.', 'Gagal' => 'Failed', 'Kamar' => 'Room',
    'Fasilitas tambahan (Hotel Service)' => 'Additional facilities (Hotel Service)', 'Memuat katalog…' => 'Loading catalog…', 'Katalog Hotel Service masih kosong.' => 'The Hotel Service catalog is empty.',
    '+ Item lain' => '+ Other item', 'Nama item' => 'Item name', 'Harga' => 'Price', 'Layanan tambahan' => 'Additional services', 'dipilih' => 'selected', 'Kamar' => 'Room',
    'Ubah Penawaran' => 'Edit Quotation', 'Ubah' => 'Edit', 'Simpan' => 'Save', 'Diskon' => 'Discount', '+ Kamar' => '+ Room', 'Tambah kamar…' => 'Add room…', '+ Tambah' => '+ Add', 'Pilih layanan…' => 'Select service…',
    'Pilih minimal satu kamar.' => 'Select at least one room.', 'Tanggal tidak valid.' => 'Invalid dates.',
    'Harga pas' => 'Net rate', '/malam' => '/night', 'Penawaran dibatalkan' => 'Quotation cancelled', 'Check-in' => 'Check-in', 'Check-out' => 'Check-out', 'Tamu' => 'Guest',
];
?>
<style>
    .qt-ov { position: fixed; inset: 0; z-index: 10060; display: none; align-items: center; justify-content: center; padding: 14px; background: rgba(2, 6, 23, .55); backdrop-filter: blur(3px); }
    .qt-ov.on { display: flex; }
    .qt { --bg: #fff; --bd: #e8edf4; --tx: #0f172a; --mu: #64748b; --tile: #f8fafc; --inb: #dbe3ee; width: 100%; max-width: 440px; max-height: 92vh; overflow: auto; border-radius: 16px; background: var(--bg) !important; color: var(--tx) !important; box-shadow: 0 24px 60px rgba(0, 0, 0, .35); font-family: system-ui, 'Segoe UI', sans-serif; font-size: 12.5px; }
    body[data-theme="dark"] .qt { --bg: #111a2e; --bd: #22304a; --tx: #e6edf7; --mu: #9fb0c8; --tile: #16213a; --inb: #2f4062; }
    .qt * { box-sizing: border-box; }
    .qt-h { display: flex; align-items: center; gap: 10px; padding: 13px 16px; border-bottom: 1px solid var(--bd); }
    .qt-ico { width: 28px; height: 28px; border-radius: 9px; display: grid; place-items: center; background: linear-gradient(135deg, #22c55e, #15803d); flex: none; }
    .qt-ico svg { width: 15px; height: 15px; fill: none; stroke: #fff; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    .qt .qt-h b { flex: 1; font-size: 14px; color: var(--tx) !important; }
    .qt .qt-code { font-size: 10px; font-weight: 700; padding: 2px 9px; border-radius: 999px; background: var(--tile) !important; color: var(--mu) !important; }
    .qt-b { padding: 12px 16px 6px; }
    .qt-sum { padding: 9px 11px; border-radius: 11px; background: var(--tile); border: 1px solid var(--bd); margin-bottom: 11px; line-height: 1.55; }
    .qt .qt-sum b { color: var(--tx) !important; } .qt .qt-sum span, .qt .qt-sum small { color: var(--mu) !important; }
    .qt-sum .tot { display: flex; justify-content: space-between; align-items: baseline; margin-top: 5px; padding-top: 5px; border-top: 1px dashed var(--bd); }
    .qt .qt-sum .tot b { font-size: 15px; }
    .qt-f { margin-bottom: 9px; }
    .qt .qt-f label { display: block; margin-bottom: 3px; font-size: 9.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--mu) !important; }
    .qt .qt-f input, .qt .qt-f select, .qt .qt-f textarea { width: 100%; padding: 0 10px; height: 34px; border: 1px solid var(--inb) !important; border-radius: 9px; background: var(--bg) !important; color: var(--tx) !important; -webkit-text-fill-color: var(--tx) !important; font: inherit; font-size: 13px; }
    .qt .qt-f textarea { height: 56px; padding: 7px 10px; resize: vertical; }
    .qt .qt-f input:focus, .qt .qt-f select:focus, .qt .qt-f textarea:focus { outline: none; border-color: #22c55e !important; box-shadow: 0 0 0 3px rgba(34, 197, 94, .15); }
    body[data-theme="dark"] .qt .qt-f select { color-scheme: dark; } body[data-theme="dark"] .qt .qt-f select option { background: #16213a; color: #e6edf7; }
    .qt-r2 { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; }
    .qt .qt-note { margin: 2px 0 10px; padding: 7px 10px; border-radius: 9px; background: rgba(34, 197, 94, .1); color: #15803d !important; font-size: 11px; line-height: 1.45; }
    body[data-theme="dark"] .qt .qt-note { color: #86efac !important; }
    .qt .qt-err { margin: 0 0 9px; padding: 7px 10px; border-radius: 9px; background: rgba(239, 68, 68, .12); color: #dc2626 !important; font-size: 11.5px; line-height: 1.5; }
    .qt-foot { display: flex; flex-wrap: wrap; gap: 7px; justify-content: flex-end; padding: 8px 16px 14px; }
    .qt .qt-foot button { height: 34px; padding: 0 14px; border-radius: 9px; border: 1px solid var(--inb) !important; background: var(--bg) !important; color: var(--tx) !important; font-size: 12px; font-weight: 700; cursor: pointer; }
    .qt .qt-foot button.pri { background: linear-gradient(180deg, #22c55e, #15803d) !important; color: #fff !important; border-color: transparent !important; }
    .qt .qt-foot button.bad { color: #dc2626 !important; }
    .qt .qt-foot button:disabled { opacity: .55; cursor: wait; }
    .qt-st { font-size: 10px; font-weight: 800; padding: 2px 9px; border-radius: 999px; text-transform: uppercase; letter-spacing: .04em; }
    .qt .qt-st.open { background: rgba(34, 197, 94, .16); color: #15803d !important; } .qt .qt-st.confirmed { background: rgba(59, 130, 246, .16); color: #2563eb !important; }
    .qt .qt-st.cancelled, .qt .qt-st.expired { background: rgba(148, 163, 184, .2); color: #64748b !important; }
    .qt-rooms { margin: 0 0 4px; padding: 0; list-style: none; }
    .qt-rooms li { display: flex; justify-content: space-between; gap: 8px; padding: 6px 0; border-bottom: 1px solid var(--bd); }
    .qt-rooms li:last-child { border-bottom: 0; }
    .qt .qt-rooms span { color: var(--mu) !important; } .qt .qt-rooms b { color: var(--tx) !important; }

    .qt-ex { margin: 0 0 10px; border: 1px solid var(--bd); border-radius: 11px; background: var(--tile); }
    .qt-ex > summary { display: flex; align-items: center; gap: 8px; padding: 8px 11px; cursor: pointer; list-style: none; font-weight: 700; }
    .qt-ex > summary::-webkit-details-marker { display: none; }
    .qt-ex > summary::before { content: '▸'; font-size: .7rem; color: var(--mu); transition: transform .15s; }
    .qt-ex[open] > summary::before { transform: rotate(90deg); }
    .qt .qt-ex > summary { color: var(--tx) !important; }
    .qt .qt-ex-n { margin-left: auto; padding: 0 8px; border-radius: 999px; background: #16a34a; color: #fff !important; font-size: 10px; font-weight: 800; line-height: 18px; }
    .qt-ex-list { max-height: 210px; overflow-y: auto; padding: 0 9px 9px; }
    .qt .qt-ex-g { margin: 7px 2px 3px; font-size: 9.5px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--mu) !important; }
    .qt-ex-r { display: grid; grid-template-columns: 18px minmax(0, 1fr) auto 58px; gap: 8px; align-items: center; padding: 5px 4px; border-radius: 8px; }
    .qt-ex-r:hover { background: rgba(34, 197, 94, .08); }
    .qt .qt-ex-r span { color: var(--tx) !important; font-size: 12.5px; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .qt .qt-ex-r small { color: var(--mu) !important; font-size: 11px; white-space: nowrap; }
    .qt .qt-ex-r input[type="number"], .qt .qt-ex-c input { height: 28px; padding: 0 6px; text-align: right; }
    .qt .qt-ex-r input[type="checkbox"] { width: 16px; height: 16px; accent-color: #16a34a; }
    .qt-ex-r input[type="number"]:disabled { opacity: .4; }
    .qt-ex-c { display: grid; grid-template-columns: minmax(0, 1fr) 96px 58px; gap: 6px; padding: 4px 4px 0; }
    .qt .qt-ex-add { margin: 4px 4px 0; height: 28px; padding: 0 10px; border: 1px dashed var(--inb) !important; border-radius: 8px; background: transparent !important; color: #16a34a !important; font-size: 11.5px; font-weight: 700; cursor: pointer; }
    .qt .qt-ex-empty { padding: 8px 4px; color: var(--mu) !important; font-size: 11.5px; }

    .qt.wide { max-width: 580px; }
    .qt-r3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 9px; }
    .qt .qt-sec { margin: 4px 0 6px; font-size: 9.5px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; color: var(--mu) !important; }
    .qt-ed { display: flex; flex-direction: column; gap: 5px; margin-bottom: 6px; }
    .qt-er { display: grid; gap: 6px; align-items: center; padding: 5px 7px; border: 1px solid var(--bd); border-radius: 10px; background: var(--tile); }
    .qt-er-room { grid-template-columns: minmax(0, 1fr) 62px 92px 28px; }
    .qt-er-ex { grid-template-columns: minmax(0, 1fr) 100px 54px 28px; }
    .qt-er-l { min-width: 0; line-height: 1.3; }
    .qt .qt-er-l b { display: block; color: var(--tx) !important; }
    .qt .qt-er-l small { color: var(--mu) !important; font-size: 10.5px; }
    .qt .qt-er input, .qt .qt-er select, .qt .qt-add select { width: 100%; height: 30px; padding: 0 8px; border: 1px solid var(--inb) !important; border-radius: 8px; background: var(--bg) !important; color: var(--tx) !important; -webkit-text-fill-color: var(--tx) !important; font: inherit; font-size: 12px; }
    .qt .qt-er input[type="number"] { text-align: right; }
    .qt .qt-x { width: 28px; height: 28px; padding: 0; border: 0; border-radius: 8px; background: rgba(239, 68, 68, .12); color: #dc2626 !important; font-weight: 800; cursor: pointer; }
    .qt-add { display: flex; gap: 6px; margin-bottom: 10px; }
    .qt .qt-add select { flex: 1; min-width: 0; }
    .qt .qt-mini { height: 30px; padding: 0 11px; border-radius: 8px; border: 0; background: #16a34a; color: #fff !important; font-size: 11.5px; font-weight: 700; cursor: pointer; white-space: nowrap; }
    .qt .qt-mini.ghost { background: transparent; border: 1px dashed var(--inb); color: #16a34a !important; }
    body[data-theme="dark"] .qt .qt-er select, body[data-theme="dark"] .qt .qt-add select { color-scheme: dark; }
    body[data-theme="dark"] .qt select option, body[data-theme="dark"] .qt select optgroup { background: #16213a; color: #e6edf7; }
    @media (max-width: 520px) { .qt-r3 { grid-template-columns: 1fr; } .qt-er-room { grid-template-columns: minmax(0, 1fr) 56px 80px 28px; } }

    /* Bar penawaran di kalender: hijau transparan, tidak memblok (klik sel tetap membuat booking) */
    .booking-bar-container.qt-ghost { pointer-events: none; z-index: 3; }
    .booking-bar.booking-quote { background: rgba(34, 197, 94, .2) !important; border: 1.5px dashed #22c55e; box-shadow: none; transform: none; border-radius: 6px; pointer-events: none; justify-content: flex-start; }
    .booking-bar.booking-quote::before, .booking-bar.booking-quote::after { display: none; }
    .qt-chip { pointer-events: auto; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; border: 0; background: transparent; padding: 0 .4rem; font-size: .62rem; font-weight: 700; color: #15803d !important; -webkit-text-fill-color: #15803d !important; cursor: pointer; text-align: left; }
    .qt-chip:hover { text-decoration: underline; }
    body[data-theme="dark"] .qt-chip { color: #86efac !important; -webkit-text-fill-color: #86efac !important; }
</style>

<div class="qt-ov" id="qtOv" role="dialog" aria-modal="true"><div class="qt" id="qtBox"></div></div>
<script>
    (function() {
        const API = <?php echo json_encode(rtrim(BASE_URL, '/') . '/api/quotation.php'); ?>;
        const PRINT = <?php echo json_encode(rtrim(BASE_URL, '/') . '/modules/frontdesk/quotation-print.php'); ?>;
        const EN = <?php echo $qtEn ? 'true' : 'false'; ?> || (document.documentElement.lang || '').toLowerCase() === 'en';
        const TX = <?php echo json_encode($qtText, JSON_UNESCAPED_UNICODE); ?>;
        const t = s => (EN && TX[s]) ? TX[s] : s;
        const ov = document.getElementById('qtOv'), box = document.getElementById('qtBox');
        const rp = n => 'Rp ' + Math.round(Number(n) || 0).toLocaleString('id-ID');
        const loc = EN ? 'en-GB' : 'id-ID';
        const fd = d => { const x = new Date(d + 'T00:00:00'); return isNaN(x) ? d : x.toLocaleDateString(loc, { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' }); };
        function el(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
        function field(label, node) { const w = el('div', 'qt-f'), l = el('label', '', label); w.appendChild(l); w.appendChild(node); return w; }
        function post(data) { const f = new FormData(); Object.entries(data).forEach(([k, v]) => f.append(k, v)); return fetch(API, { method: 'POST', body: f, credentials: 'include' }).then(r => r.json()); }
        function header(title, code, stateCls, stateTxt) {
            const h = el('div', 'qt-h'), i = el('span', 'qt-ico');
            i.innerHTML = '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>';
            h.appendChild(i); h.appendChild(el('b', '', title));
            if (stateTxt) h.appendChild(el('span', 'qt-st ' + stateCls, stateTxt));
            if (code) h.appendChild(el('span', 'qt-code', code));
            return h;
        }
        const close = () => { ov.classList.remove('on'); document.removeEventListener('keydown', onKey); };
        const onKey = e => { if (e.key === 'Escape') close(); };
        function open() { ov.classList.add('on'); document.addEventListener('keydown', onKey); ov.onclick = e => { if (e.target === ov) close(); }; }
        const reload = () => { if (typeof window.saveScrollAndReload === 'function') window.saveScrollAndReload(); else location.reload(); };

        // ---- Buat penawaran (dari Cek Ketersediaan) ----
        window.adfQuoteCreate = function(st) {
            box.innerHTML = '';
            box.appendChild(header(t('Buat Penawaran')));
            const body = el('div', 'qt-b');
            const total = st.rooms.reduce((s, r) => {
                const net = r.disc_value > 0 ? (r.disc_type === 'percent' ? r.base * (1 - Math.min(100, r.disc_value) / 100) : Math.max(0, r.base - r.disc_value)) : r.base;
                return s + Math.round(net) * st.nights;
            }, 0);
            const sum = el('div', 'qt-sum');
            sum.appendChild(el('div')).innerHTML = '<b></b>';
            sum.firstChild.firstChild.textContent = fd(st.ci) + ' → ' + fd(st.co);
            const l2 = el('div'); l2.appendChild(el('span', '', st.nights + ' ' + t('malam') + ' · ' + st.rooms.length + ' ' + t('kamar') + ': ' + st.rooms.map(r => r.number).join(', '))); sum.appendChild(l2);
            const tt = el('div', 'tot'); tt.appendChild(el('small', '', t('Total'))); const totB = el('b', '', rp(total)); tt.appendChild(totB); sum.appendChild(tt);
            body.appendChild(sum);

            const name = el('input'); name.type = 'text'; name.maxLength = 150; name.autocomplete = 'off';
            const phone = el('input'); phone.type = 'tel'; phone.autocomplete = 'off'; phone.placeholder = '0812…';
            const mail = el('input'); mail.type = 'email'; mail.autocomplete = 'off';
            const valid = el('select');
            [[1, '1 hari'], [3, '3 hari'], [7, '7 hari'], [14, '14 hari'], [30, '30 hari']].forEach(([d, l]) => { const o = el('option', '', t(l)); o.value = d; if (d === 7) o.selected = true; valid.appendChild(o); });
            const notes = el('textarea'); notes.maxLength = 600;
            body.appendChild(field(t('Nama tamu') + ' *', name));
            const r2 = el('div', 'qt-r2'); r2.appendChild(field(t('WhatsApp / telepon'), phone)); r2.appendChild(field(t('Email'), mail)); body.appendChild(r2);
            body.appendChild(field(t('Berlaku'), valid));

            // ---- Fasilitas tambahan dari katalog Hotel Service (+ baris manual) ----
            const ex = el('details', 'qt-ex'), exS = el('summary'), exN = el('span', 'qt-ex-n', '0 ' + t('dipilih'));
            exS.appendChild(el('span', '', t('Fasilitas tambahan (Hotel Service)'))); exS.appendChild(exN); ex.appendChild(exS);
            const exList = el('div', 'qt-ex-list'); exList.appendChild(el('div', 'qt-ex-empty', t('Memuat katalog…'))); ex.appendChild(exList);
            body.appendChild(ex);
            const extraRows = []; // { get: () => {catalog_id|name, price, qty, unit, type, total} | null }
            const recalc = () => {
                let ex$ = 0, n = 0;
                extraRows.forEach(r => { const v = r.get(); if (v) { ex$ += v.total; n++; } });
                exN.textContent = n + ' ' + t('dipilih');
                totB.textContent = rp(total + ex$);
            };
            const numIn = (val) => { const i = el('input'); i.type = 'number'; i.min = '1'; i.step = '1'; i.value = val; i.addEventListener('input', recalc); return i; };
            function addCatalogRow(c) {
                const r = el('div', 'qt-ex-r'), cb = el('input'); cb.type = 'checkbox';
                const q = numIn('1'); q.disabled = true;
                cb.onchange = () => { q.disabled = !cb.checked; recalc(); };
                r.appendChild(cb); r.appendChild(el('span', '', c.name)); r.appendChild(el('small', '', rp(c.price) + (c.unit && c.unit !== 'unit' ? ' / ' + c.unit : ''))); r.appendChild(q);
                exList.appendChild(r);
                extraRows.push({ get: () => cb.checked ? { catalog_id: c.id, name: c.name, price: c.price, unit: c.unit, type: c.type, qty: parseFloat(q.value) || 1, total: c.price * (parseFloat(q.value) || 1) } : null });
            }
            function addCustomRow() {
                const w = el('div', 'qt-ex-c'), nm = el('input'), pr = el('input'), q = numIn('1');
                nm.type = 'text'; nm.placeholder = t('Nama item'); nm.maxLength = 120; pr.type = 'number'; pr.min = '0'; pr.step = '1000'; pr.placeholder = t('Harga'); pr.style.textAlign = 'right';
                nm.addEventListener('input', recalc); pr.addEventListener('input', recalc);
                w.appendChild(nm); w.appendChild(pr); w.appendChild(q);
                exList.insertBefore(w, addBtn);
                extraRows.push({ get: () => { const p = parseFloat(pr.value) || 0, k = parseFloat(q.value) || 1; return nm.value.trim() && p > 0 ? { name: nm.value.trim(), price: p, unit: '', type: '', qty: k, total: p * k } : null; } });
            }
            const addBtn = el('button', 'qt-ex-add', t('+ Item lain')); addBtn.type = 'button'; addBtn.onclick = addCustomRow;
            post({ action: 'catalog' }).then(r => {
                exList.innerHTML = '';
                const rows = (r && r.rows) || [];
                if (!rows.length) exList.appendChild(el('div', 'qt-ex-empty', t('Katalog Hotel Service masih kosong.')));
                let last = null;
                rows.forEach(c => { if (c.type !== last) { exList.appendChild(el('div', 'qt-ex-g', (c.icon ? c.icon + ' ' : '') + c.type)); last = c.type; } addCatalogRow(c); });
                exList.appendChild(addBtn);
            }).catch(() => { exList.innerHTML = ''; exList.appendChild(el('div', 'qt-ex-empty', t('Katalog Hotel Service masih kosong.'))); exList.appendChild(addBtn); });
            body.appendChild(field(t('Catatan (opsional)'), notes));
            body.appendChild(el('div', 'qt-note', t('Kamar ini belum diblok — masih bisa dibooking orang lain sampai Anda mengonfirmasi.')));
            const err = el('div', 'qt-err'); err.style.display = 'none'; body.appendChild(err);
            box.appendChild(body);

            const f = el('div', 'qt-foot');
            const c = el('button', '', t('Batal')); c.type = 'button'; c.onclick = close;
            const s = el('button', 'pri', t('Simpan & Cetak')); s.type = 'button';
            s.onclick = () => {
                if (!name.value.trim()) { err.textContent = t('Nama tamu wajib diisi.'); err.style.display = 'block'; name.focus(); return; }
                err.style.display = 'none'; s.disabled = true; s.textContent = t('Menyimpan…');
                const until = new Date(); until.setDate(until.getDate() + parseInt(valid.value, 10));
                const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                post({
                    action: 'create', check_in: st.ci, check_out: st.co, guest_name: name.value.trim(), guest_phone: phone.value.trim(), guest_email: mail.value.trim(),
                    notes: notes.value.trim(), valid_until: ymd(until), lang: EN ? 'en' : 'id',
                    items: JSON.stringify(st.rooms.map(r => ({ room_id: r.id, disc_type: r.disc_type, disc_value: r.disc_value }))),
                    extras: JSON.stringify(extraRows.map(r => r.get()).filter(Boolean).map(v => ({ catalog_id: v.catalog_id || 0, name: v.name, price: v.price, unit: v.unit, type: v.type, qty: v.qty })))
                }).then(r => {
                    if (!r.ok) throw new Error(r.msg || '');
                    window.open(PRINT + '?id=' + r.id, '_blank', 'noopener');
                    close();
                    reload();
                }).catch(e => { s.disabled = false; s.textContent = t('Simpan & Cetak'); err.textContent = t('Gagal') + ': ' + e.message; err.style.display = 'block'; });
            };
            f.appendChild(c); f.appendChild(s); box.appendChild(f);
            open();
            setTimeout(() => name.focus(), 40);
        };

        // ---- Ubah penawaran (hanya yang masih terbuka) ----
        window.adfQuoteEdit = function(id) {
            box.innerHTML = ''; box.className = 'qt'; box.appendChild(el('div', 'qt-b', '…')); open();
            Promise.all([post({ action: 'get', id: id }), post({ action: 'rooms' }), post({ action: 'catalog' })]).then(([g, rm, cat]) => {
                if (!g.ok) throw new Error(g.msg || t('Gagal memuat penawaran.'));
                const q = g.quote;
                if (q.status !== 'open') { window.adfQuoteView(id); return; }
                const allRooms = (rm && rm.rows) || [], catalog = (cat && cat.rows) || [];
                const rooms = q.items.map(i => ({ id: +i.room_id, number: i.room_number, type: i.room_type || '', base: +i.base_price, dtype: i.disc_type || '', dval: +i.disc_value || 0 }));
                const extras = q.extras.map(x => ({ name: x.item_name, price: +x.unit_price, qty: +x.qty, unit: x.unit || '', type: x.type_label || '' }));

                box.innerHTML = ''; box.className = 'qt wide';
                box.appendChild(header(t('Ubah Penawaran'), q.quote_code));
                const body = el('div', 'qt-b');
                const inp = (type, val, extra) => { const i = el('input'); i.type = type; i.value = val == null ? '' : val; if (extra) Object.assign(i, extra); return i; };
                const name = inp('text', q.guest_name, { maxLength: 150, autocomplete: 'off' });
                const phone = inp('tel', q.guest_phone, { autocomplete: 'off' });
                const mail = inp('email', q.guest_email, { autocomplete: 'off' });
                const ci = inp('date', q.check_in), co = inp('date', q.check_out), valid = inp('date', q.valid_until || '');
                const notes = el('textarea'); notes.maxLength = 600; notes.value = q.notes || '';
                body.appendChild(field(t('Nama tamu') + ' *', name));
                const r1 = el('div', 'qt-r2'); r1.appendChild(field(t('WhatsApp / telepon'), phone)); r1.appendChild(field(t('Email'), mail)); body.appendChild(r1);
                const r2 = el('div', 'qt-r3'); r2.appendChild(field(t('Check-in'), ci)); r2.appendChild(field(t('Check-out'), co)); r2.appendChild(field(t('Berlaku sampai'), valid)); body.appendChild(r2);

                // Kamar + diskon
                body.appendChild(el('div', 'qt-sec', t('Kamar')));
                const roomBox = el('div', 'qt-ed');
                const addSel = el('select'); const addBtn = el('button', 'qt-mini', t('+ Kamar')); addBtn.type = 'button';
                const addRow = el('div', 'qt-add'); addRow.appendChild(addSel); addRow.appendChild(addBtn);
                body.appendChild(roomBox); body.appendChild(addRow);
                // Layanan tambahan
                body.appendChild(el('div', 'qt-sec', t('Layanan tambahan')));
                const exBox = el('div', 'qt-ed');
                const exSel = el('select'); const exBtn = el('button', 'qt-mini', t('+ Tambah')); exBtn.type = 'button';
                const exCustom = el('button', 'qt-mini ghost', t('+ Item lain')); exCustom.type = 'button';
                const exAdd = el('div', 'qt-add'); exAdd.appendChild(exSel); exAdd.appendChild(exBtn); exAdd.appendChild(exCustom);
                body.appendChild(exBox); body.appendChild(exAdd);
                body.appendChild(field(t('Catatan (opsional)'), notes));

                const sum = el('div', 'qt-sum'); const tt = el('div', 'tot'); tt.style.marginTop = '0'; tt.style.borderTop = '0'; tt.style.paddingTop = '0';
                const totInfo = el('small', ''), totB = el('b', ''); tt.appendChild(totInfo); tt.appendChild(totB); sum.appendChild(tt); body.appendChild(sum);
                const err = el('div', 'qt-err'); err.style.display = 'none'; body.appendChild(err);
                box.appendChild(body);

                const nightsNow = () => { const a = new Date(ci.value + 'T00:00:00'), b = new Date(co.value + 'T00:00:00'); const n = Math.round((b - a) / 86400000); return n > 0 ? n : 0; };
                const net = r => { const v = +r.dval || 0; return v > 0 ? (r.dtype === 'percent' ? Math.round(r.base * (1 - Math.min(100, v) / 100)) : (r.dtype === 'rp' ? Math.max(0, r.base - v) : r.base)) : r.base; };
                const recalc = () => {
                    const n = nightsNow();
                    const roomsT = rooms.reduce((s, r) => s + net(r) * n, 0), exT = extras.reduce((s, x) => s + (+x.price || 0) * (+x.qty || 0), 0);
                    totInfo.textContent = n + ' ' + t('malam') + ' · ' + rooms.length + ' ' + t('kamar') + (extras.length ? ' + ' + extras.length + ' ' + t('Layanan tambahan').toLowerCase() : '');
                    totB.textContent = rp(roomsT + exT);
                };
                const delBtn = fn => { const b = el('button', 'qt-x', '✕'); b.type = 'button'; b.onclick = fn; return b; };
                function drawRooms() {
                    roomBox.innerHTML = '';
                    rooms.forEach((r, idx) => {
                        const row = el('div', 'qt-er qt-er-room');
                        const lab = el('div', 'qt-er-l'); lab.appendChild(el('b', '', t('Kamar') + ' ' + r.number)); lab.appendChild(el('small', '', (r.type ? r.type + ' · ' : '') + rp(r.base) + t('/malam')));
                        const sel = el('select'); [['', '—'], ['percent', '%'], ['rp', 'Rp']].forEach(([v, l]) => { const o = el('option', '', l); o.value = v; if (v === r.dtype) o.selected = true; sel.appendChild(o); });
                        const val = inp('number', r.dval || '', { min: '0', step: 'any', placeholder: t('Diskon') });
                        sel.onchange = () => { r.dtype = sel.value; if (!sel.value) { r.dval = 0; val.value = ''; } recalc(); };
                        val.oninput = () => { r.dval = parseFloat(val.value) || 0; if (r.dval > 0 && !r.dtype) { r.dtype = 'percent'; sel.value = 'percent'; } recalc(); };
                        row.appendChild(lab); row.appendChild(sel); row.appendChild(val);
                        row.appendChild(delBtn(() => { rooms.splice(idx, 1); drawRooms(); fillAddSel(); recalc(); }));
                        roomBox.appendChild(row);
                    });
                }
                function fillAddSel() {
                    addSel.innerHTML = ''; const o0 = el('option', '', t('Tambah kamar…')); o0.value = ''; addSel.appendChild(o0);
                    allRooms.filter(r => !rooms.some(x => x.id === +r.id)).forEach(r => { const o = el('option', '', r.room_number + ' · ' + (r.type_name || '') + ' · ' + rp(r.base_price)); o.value = r.id; addSel.appendChild(o); });
                }
                addBtn.onclick = () => {
                    const r = allRooms.find(x => String(x.id) === addSel.value); if (!r) return;
                    rooms.push({ id: +r.id, number: r.room_number, type: r.type_name || '', base: +r.base_price || 0, dtype: '', dval: 0 });
                    rooms.sort((a, b) => String(a.number).localeCompare(String(b.number), undefined, { numeric: true }));
                    drawRooms(); fillAddSel(); recalc();
                };
                function drawExtras() {
                    exBox.innerHTML = '';
                    extras.forEach((x, idx) => {
                        const row = el('div', 'qt-er qt-er-ex');
                        const nm = inp('text', x.name, { maxLength: 120, placeholder: t('Nama item') });
                        const pr = inp('number', x.price, { min: '0', step: '1000', placeholder: t('Harga') });
                        const qy = inp('number', x.qty, { min: '1', step: '1' });
                        nm.oninput = () => { x.name = nm.value; recalc(); }; pr.oninput = () => { x.price = parseFloat(pr.value) || 0; recalc(); }; qy.oninput = () => { x.qty = parseFloat(qy.value) || 1; recalc(); };
                        row.appendChild(nm); row.appendChild(pr); row.appendChild(qy);
                        row.appendChild(delBtn(() => { extras.splice(idx, 1); drawExtras(); recalc(); }));
                        exBox.appendChild(row);
                    });
                }
                (function fillCatalog() {
                    const o0 = el('option', '', t('Pilih layanan…')); o0.value = ''; exSel.appendChild(o0);
                    let grp = null, last = null;
                    catalog.forEach(c => { if (c.type !== last) { grp = el('optgroup'); grp.label = (c.icon ? c.icon + ' ' : '') + c.type; exSel.appendChild(grp); last = c.type; } const o = el('option', '', c.name + ' · ' + rp(c.price) + (c.unit && c.unit !== 'unit' ? ' / ' + c.unit : '')); o.value = c.id; grp.appendChild(o); });
                })();
                exBtn.onclick = () => {
                    const c = catalog.find(x => String(x.id) === exSel.value); if (!c) return;
                    extras.push({ name: c.name, price: c.price, qty: 1, unit: c.unit || '', type: c.type || '' }); exSel.value = ''; drawExtras(); recalc();
                };
                exCustom.onclick = () => { extras.push({ name: '', price: 0, qty: 1, unit: '', type: '' }); drawExtras(); recalc(); };
                [ci, co].forEach(i => i.addEventListener('change', recalc));
                drawRooms(); fillAddSel(); drawExtras(); recalc();

                const f = el('div', 'qt-foot');
                const c = el('button', '', t('Batal')); c.type = 'button'; c.onclick = close;
                const sv = el('button', '', t('Simpan')); sv.type = 'button';
                const sp = el('button', 'pri', t('Simpan & Cetak')); sp.type = 'button';
                const save = (print) => {
                    const show = m => { err.textContent = m; err.style.display = 'block'; };
                    if (!name.value.trim()) { show(t('Nama tamu wajib diisi.')); name.focus(); return; }
                    if (!nightsNow()) { show(t('Tanggal tidak valid.')); return; }
                    if (!rooms.length) { show(t('Pilih minimal satu kamar.')); return; }
                    err.style.display = 'none'; sv.disabled = sp.disabled = true; (print ? sp : sv).textContent = t('Menyimpan…');
                    post({
                        action: 'update', id: q.id, check_in: ci.value, check_out: co.value, valid_until: valid.value, guest_name: name.value.trim(), guest_phone: phone.value.trim(), guest_email: mail.value.trim(), notes: notes.value.trim(),
                        items: JSON.stringify(rooms.map(r => ({ room_id: r.id, disc_type: r.dtype, disc_value: r.dval }))),
                        extras: JSON.stringify(extras.filter(x => x.name.trim() && +x.price >= 0).map(x => ({ name: x.name.trim(), price: +x.price || 0, qty: +x.qty || 1, unit: x.unit, type: x.type })))
                    }).then(r => {
                        if (!r.ok) throw new Error(r.msg || '');
                        if (print) window.open(PRINT + '?id=' + q.id, '_blank', 'noopener');
                        close(); reload();
                    }).catch(e => { sv.disabled = sp.disabled = false; sv.textContent = t('Simpan'); sp.textContent = t('Simpan & Cetak'); show(t('Gagal') + ': ' + e.message); });
                };
                sv.onclick = () => save(false); sp.onclick = () => save(true);
                f.appendChild(c); f.appendChild(sv); f.appendChild(sp); box.appendChild(f);
            }).catch(e => { box.innerHTML = ''; box.appendChild(header(t('Penawaran'))); box.appendChild(el('div', 'qt-b', t('Gagal') + ': ' + e.message)); const f = el('div', 'qt-foot'), c = el('button', '', t('Tutup')); c.onclick = close; f.appendChild(c); box.appendChild(f); });
        };

        // ---- Detail penawaran ----
        window.adfQuoteView = function(id, afterConflicts) {
            box.innerHTML = ''; box.appendChild(el('div', 'qt-b', '…')); open();
            post({ action: 'get', id: id }).then(r => {
                if (!r.ok) throw new Error(r.msg || t('Gagal memuat penawaran.'));
                const q = r.quote;
                const stTxt = q.status === 'confirmed' ? t('Terkonfirmasi') : q.status === 'cancelled' ? t('Dibatalkan') : q.expired ? t('Kedaluwarsa') : t('Terbuka');
                const stCls = q.status === 'open' && !q.expired ? 'open' : (q.status === 'confirmed' ? 'confirmed' : (q.status === 'cancelled' ? 'cancelled' : 'expired'));
                box.innerHTML = '';
                box.appendChild(header(t('Penawaran'), q.quote_code, stCls, stTxt));
                const body = el('div', 'qt-b');
                const sum = el('div', 'qt-sum');
                const a = el('div'); a.appendChild(el('b', '', q.guest_name)); sum.appendChild(a);
                if (q.guest_phone || q.guest_email) sum.appendChild(el('div')).appendChild(el('span', '', [q.guest_phone, q.guest_email].filter(Boolean).join(' · ')));
                const d = el('div'); d.appendChild(el('span', '', fd(q.check_in) + ' → ' + fd(q.check_out) + ' · ' + q.nights + ' ' + t('malam'))); sum.appendChild(d);
                if (q.valid_until) { const v = el('div'); v.appendChild(el('span', '', t('Berlaku sampai') + ': ' + fd(q.valid_until))); sum.appendChild(v); }
                body.appendChild(sum);
                const ul = el('ul', 'qt-rooms');
                q.items.forEach(it => {
                    const li = el('li'); li.appendChild(el('span', '', t('Kamar') + ' ' + it.room_number + ' · ' + (it.room_type || '')));
                    li.appendChild(el('b', '', rp(it.net_price) + t('/malam'))); ul.appendChild(li);
                });
                body.appendChild(ul);
                if (q.extras && q.extras.length) {
                    const eh = el('div', 'qt-ex-g', t('Layanan tambahan')); eh.style.margin = '8px 0 2px'; body.appendChild(eh);
                    const eu = el('ul', 'qt-rooms');
                    q.extras.forEach(x => {
                        const li = el('li'); li.appendChild(el('span', '', x.item_name + ' × ' + parseFloat(x.qty)));
                        li.appendChild(el('b', '', rp(x.line_total))); eu.appendChild(li);
                    });
                    body.appendChild(eu);
                }                const tt = el('div', 'qt-sum'); const t2 = el('div', 'tot'); t2.style.marginTop = '0'; t2.style.borderTop = '0'; t2.style.paddingTop = '0';
                t2.appendChild(el('small', '', t('Total'))); t2.appendChild(el('b', '', rp(q.total))); tt.appendChild(t2); body.appendChild(tt);
                if (r.conflicts && r.conflicts.length) {
                    body.appendChild(el('div', 'qt-err', t('Kamar berikut sudah terisi / diblok pada tanggal ini, penawaran tidak bisa dikonfirmasi:') + ' ' + r.conflicts.join(', ')));
                }
                if (q.status !== 'open') body.appendChild(el('div', 'qt-note', t('Penawaran ini sudah ') + stTxt.toLowerCase() + '.'));
                box.appendChild(body);

                const f = el('div', 'qt-foot');
                const cl = el('button', '', t('Tutup')); cl.type = 'button'; cl.onclick = close;
                const pr = el('button', '', t('Cetak')); pr.type = 'button'; pr.onclick = () => window.open(PRINT + '?id=' + q.id, '_blank', 'noopener');
                f.appendChild(cl); f.appendChild(pr);
                if (q.status === 'open') {
                    const cx = el('button', 'bad', t('Batalkan penawaran')); cx.type = 'button';
                    cx.onclick = () => { if (!window.confirm(t('Batalkan penawaran ini? Bar hijau di kalender akan hilang.'))) return; post({ action: 'cancel', id: q.id }).then(() => { close(); reload(); }); };
                    const ed = el('button', '', t('Ubah')); ed.type = 'button'; ed.onclick = () => { close(); window.adfQuoteEdit(q.id); };
                    f.appendChild(ed); f.appendChild(cx);
                    if (!q.expired && !(r.conflicts && r.conflicts.length)) {
                        const ok = el('button', 'pri', t('Konfirmasi (jadikan booking)')); ok.type = 'button'; ok.onclick = () => { close(); window.adfQuoteConfirm(q.id); };
                        f.appendChild(ok);
                    }
                }
                box.appendChild(f);
            }).catch(e => { box.innerHTML = ''; box.appendChild(header(t('Penawaran'))); box.appendChild(el('div', 'qt-b', t('Gagal') + ': ' + e.message)); const f = el('div', 'qt-foot'), c = el('button', '', t('Tutup')); c.onclick = close; f.appendChild(c); box.appendChild(f); });
        };

        // ---- Konfirmasi: buka form reservasi terisi dari penawaran (booking baru dicek ketersediaannya di form itu) ----
        window.adfQuoteConfirm = function(id) {
            post({ action: 'get', id: id }).then(r => {
                if (!r.ok) throw new Error(r.msg || '');
                const q = r.quote;
                if (q.status !== 'open' || (r.conflicts && r.conflicts.length)) { window.adfQuoteView(id); return; }
                // Diskon: seragam → pakai nilai aslinya; beda-beda → total diskon dalam Rupiah (total tetap sama dengan penawaran)
                const first = q.items[0] || {};
                const uniform = q.items.length && q.items.every(i => i.disc_type === first.disc_type && parseFloat(i.disc_value) === parseFloat(first.disc_value) && parseFloat(i.base_price) === parseFloat(first.base_price));
                let discount = null;
                if (parseFloat(q.discount_total) > 0) {
                    discount = (uniform && first.disc_type === 'percent') ? { type: 'percent', value: parseFloat(first.disc_value) } : { type: 'rp', value: parseFloat(q.discount_total) };
                }
                window.openNewReservationForm({ checkIn: q.check_in, checkOut: q.check_out, roomIds: q.items.map(i => i.room_id), discount: discount, quote: { id: q.id, code: q.quote_code } });
                const set = (id2, v) => { const e = document.getElementById(id2); if (e && v) e.value = v; };
                set('guestName', q.guest_name); set('guestPhone', q.guest_phone); set('guestEmail', q.guest_email);
            }).catch(e => window.alert(t('Gagal') + ': ' + e.message));
        };

        // Tautan dari daftar Reservasi: calendar.php?quote=<id> (konfirmasi) / ?quote_view=<id> (detail)
        document.addEventListener('DOMContentLoaded', function() {
            const p = new URLSearchParams(location.search);
            if (p.get('quote')) setTimeout(() => window.adfQuoteConfirm(parseInt(p.get('quote'), 10)), 300);
            else if (p.get('quote_edit')) setTimeout(() => window.adfQuoteEdit(parseInt(p.get('quote_edit'), 10)), 300);
            else if (p.get('quote_view')) setTimeout(() => window.adfQuoteView(parseInt(p.get('quote_view'), 10)), 300);
        });
    })();
</script>
