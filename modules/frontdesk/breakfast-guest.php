<?php
define('APP_ACCESS', true);
require_once '../../config/config.php';

$token = trim((string)($_GET['t'] ?? ''));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilih Menu Sarapan</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: linear-gradient(160deg, #f8fafc, #eef2ff);
            color: #0f172a;
            min-height: 100vh;
            padding: 18px;
        }
        .wrap { max-width: 860px; margin: 0 auto; }
        .card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.07);
            padding: 18px;
            margin-bottom: 14px;
        }
        h1 { margin: 0 0 6px; font-size: 1.35rem; }
        .muted { color: #64748b; font-size: 0.9rem; }
        .meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin-top: 12px; }
        .meta-item { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px; }
        .meta-lbl { font-size: 0.72rem; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: .3px; }
        .meta-val { margin-top: 4px; font-weight: 700; }
        .section-title { font-size: 1rem; font-weight: 800; margin: 2px 0 10px; }
        .menu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
        .menu-item {
            border: 1px solid #dbeafe;
            border-radius: 10px;
            background: #f8fbff;
            padding: 10px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        .menu-item input { margin-top: 3px; }
        .menu-name { font-weight: 700; font-size: 0.92rem; }
        .menu-cat { font-size: 0.7rem; color: #475569; margin-top: 3px; text-transform: uppercase; }
        .menu-price { margin-top: 4px; font-size: 0.76rem; color: #059669; font-weight: 700; }
        .quota-line { margin-bottom: 10px; color: #334155; font-size: 0.86rem; }
        .counter { font-weight: 800; color: #0ea5e9; }
        .warn { color: #b45309; font-weight: 700; }
        textarea {
            width: 100%;
            min-height: 80px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px;
            font-family: inherit;
            font-size: 0.92rem;
        }
        .actions { display: flex; gap: 10px; margin-top: 12px; flex-wrap: wrap; }
        .btn {
            border: none;
            border-radius: 10px;
            padding: 11px 15px;
            font-size: 0.92rem;
            font-weight: 800;
            cursor: pointer;
        }
        .btn-primary { background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; }
        .msg { margin-top: 10px; font-size: 0.88rem; font-weight: 700; }
        .ok { color: #059669; }
        .err { color: #dc2626; }
        .hidden { display: none; }
        .info-box {
            border: 1px dashed #93c5fd;
            border-radius: 10px;
            background: #eff6ff;
            padding: 10px;
            font-size: 0.85rem;
            color: #1e3a8a;
            white-space: pre-wrap;
        }
        .media-link {
            display: inline-block;
            margin-top: 8px;
            color: #0369a1;
            font-weight: 700;
            text-decoration: none;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card" id="headerCard">
        <h1>Pilih Menu Sarapan Anda</h1>
        <div class="muted" id="stateText">Memuat data...</div>
        <div class="meta hidden" id="metaBox"></div>
    </div>

    <div class="card hidden" id="infoCard">
        <div class="section-title">Informasi</div>
        <div class="info-box" id="infoText"></div>
        <a class="media-link hidden" id="infoMedia" target="_blank">Lihat lampiran info</a>
    </div>

    <div class="card hidden" id="mainCard">
        <div class="section-title">Menu Main Course</div>
        <div class="quota-line">Jatah: <span class="counter" id="mainQuotaText">0</span> menu, terpilih <span class="counter" id="mainSelected">0</span>. <span class="warn" id="mainExtraInfo"></span></div>
        <div class="menu-grid" id="mainGrid"></div>
    </div>

    <div class="card hidden" id="childCard">
        <div class="section-title">Menu Anak</div>
        <div class="quota-line">Jatah: <span class="counter" id="childQuotaText">0</span> menu, terpilih <span class="counter" id="childSelected">0</span>. <span class="warn" id="childExtraInfo"></span></div>
        <div class="menu-grid" id="childGrid"></div>
    </div>

    <div class="card hidden" id="submitCard">
        <div class="section-title">Catatan Tambahan</div>
        <textarea id="notes" placeholder="Contoh: mohon tanpa pedas / alergi telur / dll"></textarea>
        <div class="actions">
            <button class="btn btn-primary" id="btnSubmit">Kirim Pilihan Sarapan</button>
        </div>
        <div class="msg" id="submitMsg"></div>
    </div>
</div>

<script>
(function () {
    var token = <?php echo json_encode($token); ?>;
    var API = <?php echo json_encode(rtrim(BASE_URL, '/') . '/api/breakfast-guest-portal.php'); ?>;
    var payload = null;

    var mainGrid = document.getElementById('mainGrid');
    var childGrid = document.getElementById('childGrid');

    function setState(text, isErr) {
        var el = document.getElementById('stateText');
        el.textContent = text;
        el.className = isErr ? 'muted err' : 'muted';
    }

    function openCards() {
        document.getElementById('metaBox').classList.remove('hidden');
        document.getElementById('mainCard').classList.remove('hidden');
        document.getElementById('submitCard').classList.remove('hidden');
        if ((payload.child_menus || []).length > 0 && (payload.max_child || 0) > 0) {
            document.getElementById('childCard').classList.remove('hidden');
        }
        if ((payload.wa_info_text || '').trim() || (payload.wa_media_url || '').trim()) {
            document.getElementById('infoCard').classList.remove('hidden');
        }
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function formatDate(v) {
        if (!v) return '-';
        var p = String(v).split('-');
        if (p.length !== 3) return v;
        return p[2] + '/' + p[1] + '/' + p[0];
    }

    function renderMeta() {
        var meta = document.getElementById('metaBox');
        meta.innerHTML = [
            ['Tamu', payload.guest_name || '-'],
            ['Kamar', Array.isArray(payload.room_number) ? payload.room_number.join(', ') : '-'],
            ['Tanggal', formatDate(payload.breakfast_date)],
            ['Batas Link', payload.expires_at || '-']
        ].map(function (it) {
            return '<div class="meta-item"><div class="meta-lbl">' + esc(it[0]) + '</div><div class="meta-val">' + esc(it[1]) + '</div></div>';
        }).join('');

        document.getElementById('mainQuotaText').textContent = String(payload.max_main || 0);
        document.getElementById('childQuotaText').textContent = String(payload.max_child || 0);

        var infoText = (payload.wa_info_text || '').trim();
        var infoMedia = (payload.wa_media_url || '').trim();
        document.getElementById('infoText').textContent = infoText || 'Tidak ada info tambahan.';
        if (infoMedia) {
            var mediaEl = document.getElementById('infoMedia');
            mediaEl.href = infoMedia;
            mediaEl.classList.remove('hidden');
        }
    }

    function menuCard(item, group) {
        var price = parseFloat(item.price || 0);
        var free = String(item.is_free) === '1' || item.is_free === 1 || item.is_free === true;
        return '<label class="menu-item">' +
            '<input type="checkbox" class="menu-check" data-group="' + group + '" value="' + item.id + '">' +
            '<div>' +
            '<div class="menu-name">' + esc(item.menu_name) + '</div>' +
            '<div class="menu-cat">' + esc(item.category || '-') + '</div>' +
            '<div class="menu-price">' + (free ? 'Free' : 'Rp ' + Math.round(price).toLocaleString('id-ID')) + '</div>' +
            '</div>' +
            '</label>';
    }

    function refreshQuotaInfo(group, max, target, infoTargetId, extraUnitPrice) {
        var checks = Array.from(document.querySelectorAll('.menu-check[data-group="' + group + '"]'));
        var selected = checks.filter(function (c) { return c.checked; });
        target.textContent = String(selected.length);
        var extraCount = Math.max(0, selected.length - max);
        var infoEl = document.getElementById(infoTargetId);
        if (!infoEl) return;
        if (extraCount <= 0) {
            infoEl.textContent = '';
            return;
        }
        var est = Math.max(0, extraUnitPrice || 0) * extraCount;
        var estText = est > 0 ? (' (estimasi +' + est.toLocaleString('id-ID') + ')') : '';
        infoEl.textContent = 'Extra ' + extraCount + ' menu' + estText + ' dibayar di Front Desk.';
    }

    function attachQuotaHandlers() {
        document.addEventListener('change', function (ev) {
            var el = ev.target;
            if (!el.classList.contains('menu-check')) return;
            var group = el.dataset.group;
            if (group === 'main') {
                refreshQuotaInfo('main', parseInt(payload.max_main || 0, 10), document.getElementById('mainSelected'), 'mainExtraInfo', parseFloat(payload.extra_main_price || 0));
            } else {
                refreshQuotaInfo('child', parseInt(payload.max_child || 0, 10), document.getElementById('childSelected'), 'childExtraInfo', parseFloat(payload.extra_child_price || 0));
            }
        });
    }

    function selectedIds(group) {
        return Array.from(document.querySelectorAll('.menu-check[data-group="' + group + '"]:checked')).map(function (c) {
            return parseInt(c.value, 10);
        }).filter(function (v) { return Number.isFinite(v) && v > 0; });
    }

    async function loadLink() {
        if (!token) {
            setState('Token tidak tersedia.', true);
            return;
        }
        try {
            var res = await fetch(API + '?action=get_link&token=' + encodeURIComponent(token));
            var json = await res.json();
            if (!json.success) {
                setState(json.message || 'Link tidak valid.', true);
                return;
            }

            payload = json.data || {};
            setState('Silakan pilih menu sesuai jatah.', false);
            renderMeta();
            openCards();

            mainGrid.innerHTML = (payload.main_menus || []).map(function (m) { return menuCard(m, 'main'); }).join('');
            childGrid.innerHTML = (payload.child_menus || []).map(function (m) { return menuCard(m, 'child'); }).join('');
            refreshQuotaInfo('main', parseInt(payload.max_main || 0, 10), document.getElementById('mainSelected'), 'mainExtraInfo', parseFloat(payload.extra_main_price || 0));
            refreshQuotaInfo('child', parseInt(payload.max_child || 0, 10), document.getElementById('childSelected'), 'childExtraInfo', parseFloat(payload.extra_child_price || 0));
        } catch (err) {
            setState('Gagal memuat link: ' + err.message, true);
        }
    }

    async function submitChoice() {
        var msgEl = document.getElementById('submitMsg');
        msgEl.textContent = '';
        msgEl.className = 'msg';

        var selectedMain = selectedIds('main');
        var selectedChild = selectedIds('child');
        if (selectedMain.length + selectedChild.length === 0) {
            msgEl.textContent = 'Pilih minimal 1 menu.';
            msgEl.classList.add('err');
            return;
        }

        var body = {
            action: 'submit_link',
            token: token,
            selected_main: selectedMain,
            selected_child: selectedChild,
            special_requests: (document.getElementById('notes').value || '').trim(),
            location: 'restaurant'
        };

        var btn = document.getElementById('btnSubmit');
        btn.disabled = true;
        btn.textContent = 'Mengirim...';

        try {
            var res = await fetch(API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(body)
            });
            var json = await res.json();
            if (!json.success) {
                msgEl.textContent = json.message || 'Gagal mengirim pilihan.';
                msgEl.classList.add('err');
                btn.disabled = false;
                btn.textContent = 'Kirim Pilihan Sarapan';
                return;
            }

            msgEl.textContent = 'Terima kasih, pilihan menu Anda berhasil dikirim.';
            if (json.data && json.data.extra_total_price && json.data.extra_total_price > 0) {
                msgEl.textContent += ' Extra charge: Rp ' + Math.round(json.data.extra_total_price).toLocaleString('id-ID') + ' (dibayar di Front Desk).';
            }
            msgEl.classList.add('ok');
            btn.textContent = 'Berhasil Dikirim';
        } catch (err) {
            msgEl.textContent = 'Error koneksi: ' + err.message;
            msgEl.classList.add('err');
            btn.disabled = false;
            btn.textContent = 'Kirim Pilihan Sarapan';
        }
    }

    attachQuotaHandlers();
    loadLink();
    document.getElementById('btnSubmit').addEventListener('click', submitChoice);
})();
</script>
</body>
</html>
