<?php
/**
 * Popup konfirmasi sebelum check-in (dipakai kalender & reservasi).
 * Pemakaian di JS:  const g = await adfCheckinGate(bookingId, 'catatan opsional');  → null bila dibatalkan,
 * atau { phone } (nomor yang sudah dipastikan / dikoreksi petugas). Kirim phone ke api/checkin-guest.php (guest_phone).
 * Teks mengikuti bahasa akun (ID/EN) lewat peta di bawah — tidak memakai kamus global agar kata umum di skrip halaman tidak ikut berubah.
 */
if (!defined('APP_ACCESS')) exit;

$ciEn = function_exists('getCurrentLanguage') && getCurrentLanguage() === 'en';
$ciText = [
    'Konfirmasi Check-in' => 'Check-in Confirmation', 'Memuat data tamu…' => 'Loading guest data…', 'Gagal memuat data tamu: ' => 'Failed to load guest data: ', 'Tutup' => 'Close',
    'Nama tamu' => 'Guest name', 'Kamar' => 'Room', 'Sumber' => 'Source', 'Check-in' => 'Check-in', 'Check-out' => 'Check-out', ' malam' => ' nights',
    'Booking grup' => 'Group booking', ' kamar — tamu menerima 1 pesan welcome' => ' rooms — the guest receives 1 welcome message',
    'Total' => 'Total', 'Dibayar' => 'Paid', 'Sisa' => 'Balance', 'Nomor WhatsApp tamu' => 'Guest WhatsApp number',
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
    .ciw-ov { position: fixed; inset: 0; z-index: 100000; display: none; align-items: center; justify-content: center; padding: 14px; background: rgba(2, 6, 23, .6); backdrop-filter: blur(3px); }
    .ciw-ov.on { display: flex; }
    .ciw { width: 100%; max-width: 460px; max-height: 92vh; overflow: auto; border-radius: 16px; background: #fff; color: #0f172a; box-shadow: 0 24px 60px rgba(0, 0, 0, .35); font-family: system-ui, 'Segoe UI', sans-serif; }
    .ciw-h { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 14px 16px; border-bottom: 1px solid #e2e8f0; }
    .ciw-h b { font-size: 15px; }
    .ciw-code { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 999px; background: #eef2ff; color: #4338ca; }
    .ciw-b { padding: 14px 16px; }
    .ciw-g { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 14px; margin-bottom: 12px; }
    .ciw-g div small { display: block; font-size: 10px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #64748b; }
    .ciw-g div span { display: block; font-size: 13.5px; font-weight: 600; word-break: break-word; }
    .ciw-g .full { grid-column: 1 / -1; }
    .ciw-money { display: flex; gap: 8px; margin-bottom: 12px; }
    .ciw-money div { flex: 1; padding: 8px 10px; border-radius: 10px; background: #f1f5f9; text-align: center; }
    .ciw-money small { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
    .ciw-money b { font-size: 13px; }
    .ciw-money .due { background: #fef2f2; } .ciw-money .due b { color: #b91c1c; }
    .ciw-money .ok { background: #ecfdf5; } .ciw-money .ok b { color: #047857; }
    .ciw-ph { padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 12px; }
    .ciw-ph label { display: block; font-size: 10px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #64748b; margin-bottom: 5px; }
    .ciw-ph input { box-sizing: border-box; width: 100%; height: 38px; padding: 0 11px; border: 1.5px solid #cbd5e1; border-radius: 9px; font-size: 15px; font-weight: 600; color: #0f172a; background: #fff; }
    .ciw-ph input:focus { outline: none; border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79, 70, 229, .15); }
    .ciw-st { margin-top: 7px; font-size: 12px; line-height: 1.45; }
    .ciw-st.ok { color: #047857; } .ciw-st.warn { color: #b45309; } .ciw-st.bad { color: #b91c1c; }
    .ciw-note { margin-bottom: 12px; padding: 9px 11px; border-radius: 10px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; font-size: 12px; white-space: pre-line; line-height: 1.5; }
    .ciw-f { display: flex; gap: 8px; justify-content: flex-end; padding: 12px 16px; border-top: 1px solid #e2e8f0; }
    .ciw-f button { height: 38px; padding: 0 16px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; color: #0f172a; font-size: 13px; font-weight: 700; cursor: pointer; }
    .ciw-f button.go { border-color: transparent; background: linear-gradient(180deg, #4f46e5, #4338ca); color: #fff; }
    .ciw-load { padding: 40px 16px; text-align: center; color: #64748b; font-size: 13px; }
    @media (max-width: 480px) { .ciw-g { grid-template-columns: 1fr; } }
</style>
<div class="ciw-ov" id="ciwOv" role="dialog" aria-modal="true"><div class="ciw" id="ciwBox"></div></div>
<script>
    (function() {
        const API = <?php echo json_encode(rtrim(BASE_URL, '/') . '/api/checkin-preview.php'); ?>;
        const EN = <?php echo $ciEn ? 'true' : 'false'; ?>;
        const TX = <?php echo json_encode($ciEn ? $ciText : new stdClass(), JSON_UNESCAPED_UNICODE); ?>;
        const t = s => (EN && TX[s]) ? TX[s] : s;
        const ov = document.getElementById('ciwOv'), box = document.getElementById('ciwBox');
        const rp = n => 'Rp ' + (Number(n) || 0).toLocaleString('id-ID');
        const loc = EN ? 'en-GB' : 'id-ID';
        const fmtD = d => { if (!d) return '-'; const x = new Date(d + 'T00:00:00'); return isNaN(x) ? d : x.toLocaleDateString(loc, { day: '2-digit', month: 'short', year: 'numeric' }); };
        // Sama dengan WhatsAppHelper::normalizeTarget
        function norm(v) {
            let d = String(v || '').replace(/\D+/g, '');
            if (!d) return '';
            if (d.startsWith('0')) d = '62' + d.slice(1); else if (d.startsWith('8')) d = '62' + d;
            return d.length >= 9 ? d : '';
        }
        function el(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
        function cell(label, value, full) { const d = el('div', full ? 'full' : ''); d.appendChild(el('small', '', label)); d.appendChild(el('span', '', value)); return d; }

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
                    const h = el('div', 'ciw-h'); h.appendChild(el('b', '', t('Konfirmasi Check-in'))); h.appendChild(el('span', 'ciw-code', b.code || ('#' + b.id)));
                    box.appendChild(h);
                    const body = el('div', 'ciw-b');
                    const g = el('div', 'ciw-g');
                    g.appendChild(cell(t('Nama tamu'), b.guest_name || '-', true));
                    g.appendChild(cell(t('Kamar'), (b.room || '-') + (b.room_type ? ' · ' + b.room_type : '')));
                    g.appendChild(cell(t('Sumber'), b.source || '-'));
                    g.appendChild(cell(t('Check-in'), fmtD(b.check_in)));
                    g.appendChild(cell(t('Check-out'), fmtD(b.check_out) + (b.nights ? ' · ' + b.nights + t(' malam') : '')));
                    if (b.group_count > 1) g.appendChild(cell(t('Booking grup'), b.group_count + t(' kamar — tamu menerima 1 pesan welcome'), true));
                    body.appendChild(g);

                    const m = el('div', 'ciw-money');
                    [[t('Total'), rp(b.total), ''], [t('Dibayar'), rp(b.paid), ''], [t('Sisa'), rp(b.remaining), b.remaining > 0 ? 'due' : 'ok']].forEach(([l, v, c]) => {
                        const d = el('div', c); d.appendChild(el('small', '', l)); d.appendChild(el('b', '', v)); m.appendChild(d);
                    });
                    body.appendChild(m);

                    // Nomor WhatsApp tamu (bisa dikoreksi)
                    const ph = el('div', 'ciw-ph');
                    const lb = el('label', '', t('Nomor WhatsApp tamu')); lb.htmlFor = 'ciwPhone';
                    const inp = el('input'); inp.id = 'ciwPhone'; inp.type = 'tel'; inp.value = b.phone || ''; inp.placeholder = '0812…'; inp.autocomplete = 'off';
                    const st = el('div', 'ciw-st');
                    ph.appendChild(lb); ph.appendChild(inp); ph.appendChild(st);
                    body.appendChild(ph);
                    function status() {
                        const n = norm(inp.value);
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
                    inp.addEventListener('input', status); status();

                    if (note) body.appendChild(el('div', 'ciw-note', t(String(note).trim())));
                    box.appendChild(body);

                    const f = el('div', 'ciw-f');
                    const c = el('button', '', t('Batal')); c.type = 'button'; c.onclick = () => finish(null);
                    const go = el('button', 'go', t('Check-in sekarang')); go.type = 'button';
                    go.onclick = () => {
                        if (!norm(inp.value) && inp.value.trim() !== '' && !window.confirm(t('Nomor telepon tampaknya tidak valid. Tetap lanjut check-in tanpa mengirim pesan welcome?'))) return;
                        if (inp.value.trim() === '' && !window.confirm(t('Nomor telepon kosong, pesan welcome tidak akan terkirim. Tetap lanjut check-in?'))) return;
                        finish({ phone: inp.value.trim() });
                    };
                    f.appendChild(c); f.appendChild(go); box.appendChild(f);
                    setTimeout(() => go.focus(), 30);
                }
            });
        };
    })();
</script>
