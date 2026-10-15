<?php
/**
 * Popup konfirmasi sebelum check-in (dipakai kalender & reservasi).
 * Pemakaian di JS:  const g = await adfCheckinGate(bookingId, 'catatan opsional');  → null bila dibatalkan,
 * atau { name, phone } (nama & nomor yang sudah dipastikan / dikoreksi petugas). Kirim ke api/checkin-guest.php
 * (guest_name, guest_phone): perubahan diterapkan ke semua data booking itu (GuestBookingEdit).
 * Teks mengikuti bahasa akun (ID/EN) lewat peta di bawah — tidak memakai kamus global agar kata umum di skrip halaman tidak ikut berubah.
 */
if (!defined('APP_ACCESS')) exit;

$ciEn = function_exists('getCurrentLanguage') && getCurrentLanguage() === 'en';
$ciText = [
    'Konfirmasi Check-in' => 'Check-in Confirmation', 'Memuat data tamu…' => 'Loading guest data…', 'Gagal memuat data tamu: ' => 'Failed to load guest data: ', 'Tutup' => 'Close',
    'Kamar' => 'Room', 'Check-in' => 'Check-in', 'Check-out' => 'Check-out', ' malam' => ' nights', ' kamar' => ' rooms',
    'Booking grup — tamu menerima 1 pesan welcome' => 'Group booking — the guest receives 1 welcome message',
    'Total' => 'Total', 'Dibayar' => 'Paid', 'Sisa' => 'Balance', 'Lunas' => 'Paid in full',
    'Nama tamu' => 'Guest name', 'Nomor WhatsApp' => 'WhatsApp number',
    'Perubahan nama / nomor diterapkan ke semua data booking ini (tamu, invoice, sarapan, buku kas).' => 'Name / number changes apply to all data of this booking (guest, invoices, breakfast, cashbook).',
    'Nama tamu tidak boleh kosong.' => 'Guest name cannot be empty.',
    'Nomor belum diisi' => 'Number not filled in', 'Nomor tidak valid' => 'Invalid number', 'pesan welcome tidak akan terkirim.' => 'the welcome message will not be sent.',
    'Pesan welcome otomatis sedang dimatikan di Pengaturan WhatsApp.' => 'The automatic welcome message is turned off in WhatsApp Settings.',
    'Token WhatsApp belum diisi, pesan welcome tidak akan terkirim.' => 'The WhatsApp token is not set, so the welcome message will not be sent.',
    'Pesan welcome sudah dikirim ke nomor ini (tidak dikirim ulang).' => 'The welcome message was already sent to this number (it will not be sent again).',
    'Pesan welcome akan dikirim dalam bahasa ' => 'The welcome message will be sent in ', 'Indonesia' => 'Indonesian',
    'Batal' => 'Cancel', 'Check-in sekarang' => 'Check in now',
    'Nomor telepon tampaknya tidak valid. Tetap lanjut check-in tanpa mengirim pesan welcome?' => 'The phone number looks invalid. Continue check-in without sending the welcome message?',
    'Nomor telepon kosong, pesan welcome tidak akan terkirim. Tetap lanjut check-in?' => 'The phone number is empty, so the welcome message will not be sent. Continue check-in?',
    '💳 Tagihan LUNAS' => '💳 Bill paid in full', '✅ Pembayaran sudah tercatat.' => '✅ Payment has been recorded.',
];
?>
<style>
    .ciw-ov { position: fixed; inset: 0; z-index: 100000; display: none; align-items: center; justify-content: center; padding: 14px; background: rgba(2, 6, 23, .55); backdrop-filter: blur(3px); }
    .ciw-ov.on { display: flex; }
    .ciw { width: 100%; max-width: 400px; max-height: 92vh; overflow: auto; border-radius: 14px; background: #fff; color: #0f172a; box-shadow: 0 22px 54px rgba(0, 0, 0, .32); font-family: system-ui, 'Segoe UI', sans-serif; font-size: 12.5px; }
    .ciw * { box-sizing: border-box; }
    .ciw-h { display: flex; align-items: center; gap: 9px; padding: 11px 14px; border-bottom: 1px solid #eef2f7; }
    .ciw-ico { flex: none; width: 26px; height: 26px; border-radius: 8px; display: grid; place-items: center; background: linear-gradient(135deg, #6366f1, #4338ca); }
    .ciw-ico svg { width: 14px; height: 14px; fill: none; stroke: #fff; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    .ciw-h b { flex: 1; font-size: 13.5px; font-weight: 700; }
    .ciw-code { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #f1f5f9; color: #475569; letter-spacing: .02em; }
    .ciw-b { padding: 12px 14px 4px; }
    .ciw-sum { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 3px; }
    .ciw-sum strong { font-size: 13px; font-weight: 700; }
    .ciw-src { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #eef2ff; color: #4338ca; white-space: nowrap; }
    .ciw-dt { color: #64748b; font-size: 11.5px; margin-bottom: 10px; }
    .ciw-grp { margin: -4px 0 10px; font-size: 11px; color: #92400e; background: #fffbeb; border-radius: 8px; padding: 5px 8px; }
    .ciw-money { display: flex; gap: 6px; margin-bottom: 11px; }
    .ciw-money div { flex: 1; padding: 6px 8px; border-radius: 9px; background: #f8fafc; border: 1px solid #eef2f7; text-align: center; }
    .ciw-money small { display: block; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; }
    .ciw-money b { font-size: 12px; font-weight: 700; }
    .ciw-money .due { background: #fef2f2; border-color: #fee2e2; } .ciw-money .due b { color: #b91c1c; }
    .ciw-money .ok { background: #f0fdf4; border-color: #dcfce7; } .ciw-money .ok b { color: #15803d; }
    .ciw-fld { margin-bottom: 9px; }
    .ciw-fld label { display: block; margin-bottom: 3px; font-size: 9.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #94a3b8; }
    .ciw-fld input { width: 100%; height: 32px; padding: 0 10px; border: 1px solid #dbe3ee; border-radius: 8px; font-size: 13px; font-weight: 600; color: #0f172a; background: #fff; transition: border-color .15s, box-shadow .15s; }
    .ciw-fld input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, .14); }
    .ciw-fld input.chg { border-color: #f59e0b; background: #fffdf5; }
    .ciw-st { margin-top: 4px; font-size: 11px; line-height: 1.4; }
    .ciw-st.ok { color: #15803d; } .ciw-st.warn { color: #b45309; } .ciw-st.bad { color: #b91c1c; }
    .ciw-hint { display: none; margin-bottom: 9px; font-size: 10.5px; color: #92400e; background: #fffbeb; border-radius: 8px; padding: 5px 8px; line-height: 1.4; }
    .ciw-hint.on { display: block; }
    .ciw-note { margin-bottom: 9px; padding: 7px 9px; border-radius: 8px; background: #f8fafc; border: 1px solid #eef2f7; color: #475569; font-size: 11px; white-space: pre-line; line-height: 1.5; }
    .ciw-f { display: flex; gap: 7px; justify-content: flex-end; padding: 10px 14px 12px; }
    .ciw-f button { height: 32px; padding: 0 14px; border-radius: 9px; border: 1px solid #dbe3ee; background: #fff; color: #334155; font-size: 12px; font-weight: 700; cursor: pointer; }
    .ciw-f button:hover { background: #f8fafc; }
    .ciw-f button.go { border-color: transparent; background: linear-gradient(180deg, #6366f1, #4f46e5); color: #fff; box-shadow: 0 4px 12px rgba(79, 70, 229, .3); }
    .ciw-f button.go:hover { filter: brightness(1.06); }
    .ciw-load { padding: 34px 14px; text-align: center; color: #64748b; font-size: 12px; }
</style>
<div class="ciw-ov" id="ciwOv" role="dialog" aria-modal="true"><div class="ciw" id="ciwBox"></div></div>
<script>
    (function() {
        const API = <?php echo json_encode(rtrim(BASE_URL, '/') . '/api/checkin-preview.php'); ?>;
        const EN = <?php echo $ciEn ? 'true' : 'false'; ?> || (document.documentElement.lang || '').toLowerCase() === 'en';
        const TX = <?php echo json_encode($ciText, JSON_UNESCAPED_UNICODE); ?>;
        const t = s => (EN && TX[s]) ? TX[s] : s;
        const ov = document.getElementById('ciwOv'), box = document.getElementById('ciwBox');
        const rp = n => 'Rp ' + (Number(n) || 0).toLocaleString('id-ID');
        const loc = EN ? 'en-GB' : 'id-ID';
        const fmtD = d => { if (!d) return '-'; const x = new Date(d + 'T00:00:00'); return isNaN(x) ? d : x.toLocaleDateString(loc, { day: '2-digit', month: 'short' }); };
        const fmtDY = d => { if (!d) return '-'; const x = new Date(d + 'T00:00:00'); return isNaN(x) ? d : x.toLocaleDateString(loc, { day: '2-digit', month: 'short', year: 'numeric' }); };
        // Sama dengan WhatsAppHelper::normalizeTarget
        function norm(v) {
            let d = String(v || '').replace(/\D+/g, '');
            if (!d) return '';
            if (d.startsWith('0')) d = '62' + d.slice(1); else if (d.startsWith('8')) d = '62' + d;
            return d.length >= 9 ? d : '';
        }
        function el(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
        function field(label, id, value, ph, type) {
            const w = el('div', 'ciw-fld'), l = el('label', '', label), i = el('input');
            l.htmlFor = id; i.id = id; i.type = type || 'text'; i.value = value || ''; i.placeholder = ph || ''; i.autocomplete = 'off';
            w.appendChild(l); w.appendChild(i);
            return { wrap: w, input: i };
        }

        window.adfCheckinGate = function(bookingId, note) {
            return new Promise(resolve => {
                box.innerHTML = '';
                box.appendChild(el('div', 'ciw-load', t('Memuat data tamu…')));
                ov.classList.add('on');
                let done = false;
                const finish = v => { if (done) return; done = true; ov.classList.remove('on'); document.removeEventListener('keydown', onKey); resolve(v); };
                const onKey = e => { if (e.key === 'Escape') finish(null); };
                document.addEventListener('keydown', onKey);
                ov.onclick = e => { if (e.target === ov) finish(null); };

                fetch(API + '?booking_id=' + encodeURIComponent(bookingId), { credentials: 'include' })
                    .then(r => r.json())
                    .then(r => {
                        if (!r.success) throw new Error(r.message || '-');
                        render(r.booking, r.welcome);
                    })
                    .catch(err => {
                        box.innerHTML = '';
                        box.appendChild(el('div', 'ciw-load', t('Gagal memuat data tamu: ') + err.message));
                        const f = el('div', 'ciw-f'), c = el('button', '', t('Tutup')); c.type = 'button'; c.onclick = () => finish(null);
                        f.appendChild(c); box.appendChild(f);
                    });

                function render(b, w) {
                    box.innerHTML = '';
                    const h = el('div', 'ciw-h');
                    const ico = el('span', 'ciw-ico'); ico.innerHTML = '<svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>';
                    h.appendChild(ico); h.appendChild(el('b', '', t('Konfirmasi Check-in'))); h.appendChild(el('span', 'ciw-code', b.code || ('#' + b.id)));
                    box.appendChild(h);

                    const body = el('div', 'ciw-b');
                    const sum = el('div', 'ciw-sum');
                    sum.appendChild(el('strong', '', t('Kamar') + ' ' + (b.room || '-') + (b.room_type ? ' · ' + b.room_type : '')));
                    if (b.source) sum.appendChild(el('span', 'ciw-src', b.source));
                    body.appendChild(sum);
                    body.appendChild(el('div', 'ciw-dt', fmtD(b.check_in) + ' → ' + fmtDY(b.check_out) + (b.nights ? ' · ' + b.nights + (EN && b.nights === 1 ? ' night' : t(' malam')) : '')));
                    if (b.group_count > 1) body.appendChild(el('div', 'ciw-grp', t('Booking grup — tamu menerima 1 pesan welcome') + ' (' + b.group_count + t(' kamar') + ')'));

                    const m = el('div', 'ciw-money');
                    [[t('Total'), rp(b.total), ''], [t('Dibayar'), rp(b.paid), ''], [b.remaining > 0 ? t('Sisa') : t('Lunas'), b.remaining > 0 ? rp(b.remaining) : '✓', b.remaining > 0 ? 'due' : 'ok']].forEach(([l, v, c]) => {
                        const d = el('div', c); d.appendChild(el('small', '', l)); d.appendChild(el('b', '', v)); m.appendChild(d);
                    });
                    body.appendChild(m);

                    // Nama & nomor WhatsApp tamu (bisa dikoreksi)
                    const fn = field(t('Nama tamu'), 'ciwName', b.guest_name, '', 'text');
                    const fp = field(t('Nomor WhatsApp'), 'ciwPhone', b.phone, '0812…', 'tel');
                    const st = el('div', 'ciw-st'); fp.wrap.appendChild(st);
                    const hint = el('div', 'ciw-hint', t('Perubahan nama / nomor diterapkan ke semua data booking ini (tamu, invoice, sarapan, buku kas).'));
                    body.appendChild(fn.wrap); body.appendChild(fp.wrap); body.appendChild(hint);

                    const origName = (b.guest_name || '').trim(), origPhone = (b.phone || '').trim();
                    function status() {
                        const inp = fp.input, n = norm(inp.value);
                        const lang = n && !n.startsWith('62') ? 'English' : t('Indonesia');
                        const pre = n ? '+' + n + ' · ' : '';
                        let cls = 'ok', msg;
                        if (!n) { cls = 'bad'; msg = (inp.value.trim() === '' ? t('Nomor belum diisi') : t('Nomor tidak valid')) + ' — ' + t('pesan welcome tidak akan terkirim.'); }
                        else if (w.state === 'disabled') { cls = 'warn'; msg = pre + t('Pesan welcome otomatis sedang dimatikan di Pengaturan WhatsApp.'); }
                        else if (w.state === 'no_token') { cls = 'warn'; msg = pre + t('Token WhatsApp belum diisi, pesan welcome tidak akan terkirim.'); }
                        else if (w.state === 'already_sent' && n === norm(b.phone)) { cls = 'warn'; msg = pre + t('Pesan welcome sudah dikirim ke nomor ini (tidak dikirim ulang).'); }
                        else msg = '✓ ' + pre + t('Pesan welcome akan dikirim dalam bahasa ') + lang + '.';
                        st.className = 'ciw-st ' + cls; st.textContent = msg;
                    }
                    function changed() {
                        const cn = fn.input.value.trim() !== origName, cp = fp.input.value.trim() !== origPhone;
                        fn.input.classList.toggle('chg', cn); fp.input.classList.toggle('chg', cp);
                        hint.classList.toggle('on', cn || cp);
                    }
                    fp.input.addEventListener('input', () => { status(); changed(); });
                    fn.input.addEventListener('input', changed);
                    status();

                    if (note) body.appendChild(el('div', 'ciw-note', t(String(note).trim())));
                    box.appendChild(body);

                    const f = el('div', 'ciw-f');
                    const c = el('button', '', t('Batal')); c.type = 'button'; c.onclick = () => finish(null);
                    const go = el('button', 'go', t('Check-in sekarang')); go.type = 'button';
                    go.onclick = () => {
                        const name = fn.input.value.trim(), phone = fp.input.value.trim();
                        if (!name) { fn.input.focus(); fn.input.classList.add('chg'); alert(t('Nama tamu tidak boleh kosong.')); return; }
                        if (phone !== '' && !norm(phone) && !window.confirm(t('Nomor telepon tampaknya tidak valid. Tetap lanjut check-in tanpa mengirim pesan welcome?'))) return;
                        if (phone === '' && !window.confirm(t('Nomor telepon kosong, pesan welcome tidak akan terkirim. Tetap lanjut check-in?'))) return;
                        finish({ name: name, phone: phone });
                    };
                    f.appendChild(c); f.appendChild(go); box.appendChild(f);
                    setTimeout(() => go.focus(), 30);
                }
            });
        };
    })();
</script>
