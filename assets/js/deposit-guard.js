/**
 * Pengingat deposit saat check-out + dialog konfirmasi kecil.
 *
 *   depositGuard(apiBase, bookingId, guestName) → Promise<{ ok: boolean, shown: boolean }>
 *     - tanpa deposit: { ok: true, shown: false } (langsung lanjut)
 *     - ada deposit: popup daftar deposit (KTP / uang) yang wajib dicentang "sudah dikembalikan"
 *       sebelum tombol "Lanjut Check-out" aktif.
 *   dgConfirm(message, okText) → Promise<boolean>
 */
(function () {
    const CSS = `
    .dg-ov{position:fixed;inset:0;z-index:100002;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.55);backdrop-filter:blur(3px)}
    .dg-box{width:min(420px,100%);border-radius:18px;overflow:hidden;background:#fff;box-shadow:0 30px 70px rgba(0,0,0,.35);animation:dgIn .18s ease-out;font-family:inherit}
    @keyframes dgIn{from{transform:translateY(8px) scale(.98);opacity:0}}
    .dg-head{display:flex;align-items:center;gap:12px;padding:14px 16px;background:linear-gradient(135deg,#92400e,#d97706)}
    .dg-ic{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.35);flex-shrink:0}
    .dg-ic svg{width:21px;height:21px;color:#fff;stroke:#fff}
    body .dg-t{font-size:1rem!important;font-weight:800!important;color:#fff!important;-webkit-text-fill-color:#fff!important}
    body .dg-s{font-size:.78rem!important;font-weight:600!important;color:rgba(255,255,255,.92)!important;-webkit-text-fill-color:rgba(255,255,255,.92)!important;margin-top:2px}
    .dg-body{padding:14px 16px;display:flex;flex-direction:column;gap:10px}
    body .dg-msg{font-size:.86rem!important;font-weight:600!important;color:#334155!important;-webkit-text-fill-color:#334155!important;line-height:1.5}
    .dg-list{display:flex;flex-direction:column;gap:6px}
    .dg-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:12px;background:#fffbeb;border:1px solid #fde68a}
    .dg-item svg{width:20px;height:20px;color:#b45309;flex-shrink:0}
    body .dg-item b{font-size:.92rem!important;font-weight:800!important;color:#0f172a!important;-webkit-text-fill-color:#0f172a!important}
    body .dg-item small{display:block;font-size:.72rem!important;color:#64748b!important;-webkit-text-fill-color:#64748b!important}
    .dg-check{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;cursor:pointer}
    .dg-check input{width:18px;height:18px;margin-top:1px;accent-color:#059669;flex-shrink:0}
    body .dg-check span{font-size:.84rem!important;font-weight:700!important;color:#0f172a!important;-webkit-text-fill-color:#0f172a!important;line-height:1.4}
    .dg-foot{display:grid;grid-template-columns:1fr 1.4fr;gap:10px;padding:12px 16px;border-top:1px solid #e2e8f0}
    body .dg-btn{height:42px;border-radius:11px;font-size:.88rem!important;font-weight:800!important;cursor:pointer;font-family:inherit}
    body .dg-cancel{border:1px solid #cbd5e1;background:#fff;color:#334155!important;-webkit-text-fill-color:#334155!important}
    body .dg-ok{border:0;background:linear-gradient(135deg,#065f46,#059669);color:#fff!important;-webkit-text-fill-color:#fff!important}
    body .dg-ok.danger{background:linear-gradient(135deg,#b91c1c,#ef4444)}
    body .dg-ok:disabled{background:#94a3b8;cursor:not-allowed}
    .dg-plain .dg-head{background:linear-gradient(135deg,#1e3a8a,#2563eb)}
    [data-theme="dark"] .dg-box{background:#111a2e}
    [data-theme="dark"] .dg-foot{border-color:rgba(255,255,255,.1)}
    body[data-theme="dark"] .dg-msg{color:#cbd5e1!important;-webkit-text-fill-color:#cbd5e1!important}
    [data-theme="dark"] .dg-item{background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.35)}
    [data-theme="dark"] .dg-check{background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.12)}
    body[data-theme="dark"] .dg-item b,body[data-theme="dark"] .dg-check span{color:#f1f5f9!important;-webkit-text-fill-color:#f1f5f9!important}
    body[data-theme="dark"] .dg-cancel{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.16);color:#e2e8f0!important;-webkit-text-fill-color:#e2e8f0!important}`;

    const ICON = {
        warn: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/><path d="M12 8v4M12 16h.01"/></svg>',
        card: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M15 8h2M15 12h2M7 16h10"/></svg>',
        cash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01M18 12h.01"/></svg>',
        ask: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/></svg>'
    };
    const ID_LABEL = { KTP: 'KTP', Passport: 'Passport / Paspor', SIM: 'SIM', Lainnya: 'Kartu identitas' };
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const rp = n => 'Rp ' + Math.round(parseFloat(n) || 0).toLocaleString('id-ID');

    function injectCss() {
        if (document.getElementById('dg-css')) return;
        const st = document.createElement('style');
        st.id = 'dg-css';
        st.textContent = CSS;
        document.head.appendChild(st);
    }

    function open(html, onReady) {
        injectCss();
        const ov = document.createElement('div');
        ov.className = 'dg-ov';
        ov.innerHTML = html;
        document.body.appendChild(ov);
        return new Promise(resolve => {
            const done = v => { ov.remove(); resolve(v); };
            ov.addEventListener('click', e => { if (e.target === ov) done(false); });
            ov.querySelector('.dg-cancel').onclick = () => done(false);
            ov.querySelector('.dg-ok').onclick = () => done(true);
            if (onReady) onReady(ov);
        });
    }

    window.dgConfirm = function (message, okText, danger) {
        return open('<div class="dg-box dg-plain"><div class="dg-head"><div class="dg-ic">' + ICON.ask + '</div><div><div class="dg-t">Konfirmasi</div></div></div>' +
            '<div class="dg-body"><div class="dg-msg">' + esc(message) + '</div></div>' +
            '<div class="dg-foot"><button type="button" class="dg-btn dg-cancel">Batal</button><button type="button" class="dg-btn dg-ok' + (danger ? ' danger' : '') + '">' + esc(okText || 'Ya, lanjutkan') + '</button></div></div>');
    };

    window.depositGuard = function (apiBase, bookingId, guestName) {
        return fetch(apiBase + '/api/booking-deposit.php?action=list&booking_id=' + encodeURIComponent(bookingId), { credentials: 'include' })
            .then(r => r.json())
            .catch(() => ({ data: [] }))
            .then(res => {
                const rows = (res && res.data) || [];
                if (!rows.length) return { ok: true, shown: false };
                const items = rows.map(d => d.deposit_type === 'cash'
                    ? '<div class="dg-item">' + ICON.cash + '<div><b>Uang deposit ' + rp(d.amount) + '</b><small>Kembalikan uang deposit ke tamu</small></div></div>'
                    : '<div class="dg-item">' + ICON.card + '<div><b>' + esc(ID_LABEL[d.id_type] || d.id_type || 'Kartu identitas') + (d.id_number ? ' · ' + esc(d.id_number) : '') + '</b><small>Kembalikan kartu identitas asli ke tamu</small></div></div>').join('');
                return open('<div class="dg-box"><div class="dg-head"><div class="dg-ic">' + ICON.warn + '</div><div><div class="dg-t">Ada deposit yang belum dikembalikan</div><div class="dg-s">' + esc(guestName || '') + '</div></div></div>' +
                    '<div class="dg-body"><div class="dg-msg">Sebelum check-out, pastikan deposit berikut sudah dikembalikan ke tamu:</div><div class="dg-list">' + items + '</div>' +
                    '<label class="dg-check"><input type="checkbox" class="dg-cb"><span>Deposit sudah saya kembalikan ke tamu</span></label></div>' +
                    '<div class="dg-foot"><button type="button" class="dg-btn dg-cancel">Batal</button><button type="button" class="dg-btn dg-ok" disabled>Lanjut Check-out</button></div></div>',
                    ov => {
                        const cb = ov.querySelector('.dg-cb');
                        const ok = ov.querySelector('.dg-ok');
                        cb.addEventListener('change', () => { ok.disabled = !cb.checked; });
                    }).then(v => ({ ok: v, shown: true }));
            });
    };
})();
