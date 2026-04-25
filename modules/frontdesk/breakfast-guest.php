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
            background:
                radial-gradient(circle at top left, rgba(125, 211, 252, 0.35), transparent 32%),
                radial-gradient(circle at top right, rgba(147, 197, 253, 0.28), transparent 28%),
                linear-gradient(160deg, #eff6ff, #dbeafe 55%, #e0f2fe);
            color: #0f172a;
            min-height: 100vh;
            padding: 16px;
        }
        .wrap { max-width: 900px; margin: 0 auto; }
        .card {
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(96, 165, 250, 0.22);
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(59, 130, 246, 0.12);
            backdrop-filter: blur(14px);
            padding: 16px;
            margin-bottom: 16px;
        }
        .header-card {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.96), rgba(96, 165, 250, 0.88));
            color: white;
            border: none;
        }
        h1 { margin: 0 0 4px; font-size: 1.5rem; font-weight: 800; }
        .muted { color: #9ca3af; font-size: 0.9rem; }
        .muted-white { color: rgba(255,255,255,0.9); font-size: 0.85rem; }
        .meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-top: 12px; }
        .meta-item { background: rgba(255,255,255,0.15); border-radius: 10px; padding: 10px; }
        .meta-item-light { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px; }
        .meta-lbl { font-size: 0.65rem; color: rgba(255,255,255,0.8); text-transform: uppercase; font-weight: 700; letter-spacing: .5px; }
        .meta-lbl-dark { font-size: 0.65rem; color: #6b7280; text-transform: uppercase; font-weight: 700; letter-spacing: .5px; }
        .meta-val { margin-top: 4px; font-weight: 700; font-size: 0.95rem; }
        .section-title { font-size: 1.1rem; font-weight: 800; margin: 0 0 12px; color: #1f2937; display: flex; align-items: center; gap: 8px; }
        .section-icon { font-size: 1.3rem; }
        
        /* Moka-style menu cards */
        .menu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
        .menu-item {
            border: 1px solid rgba(96, 165, 250, 0.18);
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.82);
            overflow: hidden;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .menu-item:hover { border-color: rgba(59, 130, 246, 0.55); transform: translateY(-2px); box-shadow: 0 10px 24px rgba(59, 130, 246, 0.12); }
        .menu-item.selected { border-color: #38bdf8; background: linear-gradient(135deg, rgba(224, 242, 254, 0.92), rgba(191, 219, 254, 0.88)); }
        .menu-item.locked { cursor: default; }
        .menu-item input { display: none; }
        
        .menu-img-wrap {
            width: 100%;
            height: 140px;
            background: linear-gradient(135deg, rgba(191, 219, 254, 0.45), rgba(147, 197, 253, 0.35));
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .menu-img { width: 100%; height: 100%; object-fit: cover; }
        .menu-img-placeholder { font-size: 3rem; }
        
        .menu-content { padding: 12px; }
        .menu-name { font-weight: 700; font-size: 1rem; color: #1f2937; margin-bottom: 4px; }
        .menu-desc { font-size: 0.8rem; color: #6b7280; line-height: 1.4; margin-bottom: 8px; min-height: 36px; }
        .menu-footer { display: flex; justify-content: space-between; align-items: center; }
        .menu-cat { 
            font-size: 0.65rem; 
            padding: 3px 8px; 
            background: #fef3c7; 
            color: #d97706; 
            border-radius: 20px; 
            font-weight: 600;
            text-transform: uppercase;
        }
        .menu-price { 
            font-weight: 800; 
            font-size: 1rem; 
            color: #059669; 
        }
        .menu-price.free { color: #10b981; }
        
        .quota-box {
            background: linear-gradient(135deg, rgba(224, 242, 254, 0.9), rgba(191, 219, 254, 0.82));
            border: 1px solid rgba(59, 130, 246, 0.22);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .quota-label { font-size: 0.85rem; font-weight: 600; color: #1d4ed8; }
        .quota-count { font-size: 1.2rem; font-weight: 800; color: #1d4ed8; }
        .quota-extra { font-size: 0.75rem; color: #0f766e; font-weight: 600; }
        
        textarea {
            width: 100%;
            min-height: 80px;
            border: 1px solid rgba(96, 165, 250, 0.25);
            border-radius: 12px;
            padding: 12px;
            font-family: inherit;
            font-size: 0.9rem;
            background: rgba(255,255,255,0.75);
            transition: border-color 0.2s;
        }
        textarea:focus { outline: none; border-color: #38bdf8; }
        
        .actions { display: flex; gap: 12px; margin-top: 16px; flex-wrap: wrap; }
        .btn {
            border: none;
            border-radius: 12px;
            padding: 14px 20px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            flex: 1;
            transition: all 0.2s;
        }
        .btn-primary { 
            background: linear-gradient(135deg, #38bdf8, #2563eb); 
            color: white;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.22);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(37, 99, 235, 0.28); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        
        .msg { margin-top: 12px; font-size: 0.9rem; font-weight: 600; padding: 10px; border-radius: 8px; }
        .ok { background: #d1fae5; color: #059669; }
        .err { background: #fee2e2; color: #dc2626; }
        .hidden { display: none; }
        
        .info-box {
            border: 1px dashed rgba(96, 165, 250, 0.5);
            border-radius: 12px;
            background: rgba(239, 246, 255, 0.8);
            padding: 14px;
            font-size: 0.9rem;
            color: #1e3a8a;
            white-space: pre-wrap;
        }
        .media-link {
            display: inline-block;
            margin-top: 8px;
            color: #2563eb;
            font-weight: 700;
            text-decoration: none;
        }
        
        .drink-section { margin-top: 20px; }
        
        @media (max-width: 600px) {
            .menu-grid { grid-template-columns: 1fr; }
            .quota-box { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card" id="headerCard">
        <div class="header-card">
            <h1>🍳 Pilih Menu Sarapan Anda</h1>
            <div class="muted-white" id="stateText">Memuat data...</div>
            <div class="meta hidden" id="metaBox"></div>
        </div>
    </div>

    <div class="card hidden" id="infoCard">
        <div class="section-title"><span class="section-icon">ℹ️</span> Informasi</div>
        <div class="info-box" id="infoText"></div>
        <a class="media-link hidden" id="infoMedia" target="_blank">Lihat lampiran info</a>
    </div>

    <div class="card hidden" id="mainCard">
        <div class="section-title"><span class="section-icon">🍽️</span> Menu Utama</div>
        <div class="quota-box">
            <div>
                <div class="quota-label">Jatah Main Course</div>
                <div class="quota-count"><span id="mainQuotaText">0</span> menu</div>
            </div>
            <div>
                <div class="quota-label">Terpilih</div>
                <div class="quota-count" id="mainSelected">0</div>
            </div>
            <div id="mainExtraInfo" class="quota-extra"></div>
        </div>
        <div class="menu-grid" id="mainGrid"></div>
    </div>

    <div class="card hidden" id="drinkCard">
        <div class="section-title"><span class="section-icon">🥤</span> Minuman</div>
        <div class="quota-box">
            <div>
                <div class="quota-label">Jatah Minuman</div>
                <div class="quota-count"><span id="drinkQuotaText">0</span> menu</div>
            </div>
            <div>
                <div class="quota-label">Terpilih</div>
                <div class="quota-count" id="drinkSelected">0</div>
            </div>
            <div id="drinkExtraInfo" class="quota-extra"></div>
        </div>
        <div class="menu-grid" id="drinkGrid"></div>
    </div>

    <div class="card hidden" id="childCard">
        <div class="section-title"><span class="section-icon">👶</span> Menu Anak</div>
        <div class="quota-box">
            <div>
                <div class="quota-label">Jatah Menu Anak</div>
                <div class="quota-count"><span id="childQuotaText">0</span> menu</div>
            </div>
            <div>
                <div class="quota-label">Terpilih</div>
                <div class="quota-count" id="childSelected">0</div>
            </div>
            <div id="childExtraInfo" class="quota-extra"></div>
        </div>
        <div class="menu-grid" id="childGrid"></div>
    </div>

    <div class="card hidden" id="submitCard">
        <div class="section-title"><span class="section-icon">📝</span> Catatan Tambahan</div>
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
    var drinkGrid = document.getElementById('drinkGrid');

    function setState(text, isErr) {
        var el = document.getElementById('stateText');
        el.textContent = text;
        el.className = isErr ? 'muted-white err' : 'muted-white';
    }

    function openCards() {
        document.getElementById('metaBox').classList.remove('hidden');
        document.getElementById('mainCard').classList.remove('hidden');
        document.getElementById('submitCard').classList.remove('hidden');
        
        // Show drink section if there are drink menus and quota > 0
        if ((payload.drink_menus || []).length > 0 && (payload.max_drink || 0) > 0) {
            document.getElementById('drinkCard').classList.remove('hidden');
        }
        
        if ((payload.child_menus || []).length > 0 && (payload.max_child || 0) > 0) {
            document.getElementById('childCard').classList.remove('hidden');
        }
        if ((payload.wa_info_text || '').trim() || (payload.wa_media_url || '').trim()) {
            document.getElementById('infoCard').classList.remove('hidden');
        }

        if (payload.is_locked) {
            var submitTitle = document.querySelector('#submitCard .section-title');
            if (submitTitle) submitTitle.innerHTML = '<span class="section-icon">🔒</span> Submitted Menu';
            var notes = document.getElementById('notes');
            notes.disabled = true;
            notes.value = 'This breakfast selection has already been submitted. Please contact Front Office for changes.';
            var btn = document.getElementById('btnSubmit');
            btn.disabled = true;
            btn.textContent = 'Submitted';
            var msg = document.getElementById('submitMsg');
            msg.textContent = 'This link is read-only. Menu changes can only be made by Front Office.';
            msg.className = 'msg ok';
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
            return '<div class="meta-item-light"><div class="meta-lbl-dark">' + esc(it[0]) + '</div><div class="meta-val">' + esc(it[1]) + '</div></div>';
        }).join('');

        document.getElementById('mainQuotaText').textContent = String(payload.max_main || 0);
        document.getElementById('drinkQuotaText').textContent = String(payload.max_drink || 0);
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
        var locked = !!payload.is_locked;
        var price = parseFloat(item.price || 0);
        var free = String(item.is_free) === '1' || item.is_free === 1 || item.is_free === true;
        var imgUrl = item.image_url || '';
        var desc = item.description || '';
        var hasImg = imgUrl && imgUrl.trim() !== '';
        var checked = item.pre_selected ? 'checked' : '';
        
        var imgHtml = '';
        if (hasImg) {
            imgHtml = '<div class="menu-img-wrap"><img class="menu-img" src="' + esc(imgUrl) + '" alt="' + esc(item.menu_name) + '" onerror="this.parentElement.innerHTML=\'<span class=\'menu-img-placeholder\'>🍽️</span>\'"></div>';
        } else {
            imgHtml = '<div class="menu-img-wrap"><span class="menu-img-placeholder">🍽️</span></div>';
        }

        if (locked) {
            return '<div class="menu-item locked' + (item.pre_selected ? ' selected' : '') + '">' +
                '<input type="checkbox" class="menu-check" data-group="' + group + '" value="' + item.id + '" ' + checked + ' disabled>' +
                imgHtml +
                '<div class="menu-content">' +
                '<div class="menu-name">' + esc(item.menu_name) + '</div>' +
                '<div class="menu-desc">' + esc(desc) + '</div>' +
                '<div class="menu-footer">' +
                '<span class="menu-cat">' + esc(item.category || '-') + '</span>' +
                '<span class="menu-price ' + (free ? 'free' : '') + '">' + (free ? 'FREE' : 'Rp ' + Math.round(price).toLocaleString('id-ID')) + '</span>' +
                '</div>' +
                '</div>' +
                '</div>';
        }
        
        return '<label class="menu-item' + (item.pre_selected ? ' selected' : '') + '" onclick="var cb=this.querySelector(\'input\'); cb.checked = !cb.checked; this.classList.toggle(\'selected\', cb.checked);">' +
            '<input type="checkbox" class="menu-check" data-group="' + group + '" value="' + item.id + '" ' + checked + '>' +
            imgHtml +
            '<div class="menu-content">' +
            '<div class="menu-name">' + esc(item.menu_name) + '</div>' +
            '<div class="menu-desc">' + esc(desc) + '</div>' +
            '<div class="menu-footer">' +
            '<span class="menu-cat">' + esc(item.category || '-') + '</span>' +
            '<span class="menu-price ' + (free ? 'free' : '') + '">' + (free ? 'FREE' : 'Rp ' + Math.round(price).toLocaleString('id-ID')) + '</span>' +
            '</div>' +
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
            } else if (group === 'drink') {
                refreshQuotaInfo('drink', parseInt(payload.max_drink || 0, 10), document.getElementById('drinkSelected'), 'drinkExtraInfo', parseFloat(payload.extra_drink_price || 0));
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
            setState(payload.is_locked ? 'Menu sudah dikirim. Link ini hanya untuk melihat pilihan yang sudah tersimpan.' : 'Silakan pilih menu sesuai jatah.', false);
            renderMeta();
            openCards();

            mainGrid.innerHTML = (payload.main_menus || []).map(function (m) { return menuCard(m, 'main'); }).join('');
            drinkGrid.innerHTML = (payload.drink_menus || []).map(function (m) { return menuCard(m, 'drink'); }).join('');
            childGrid.innerHTML = (payload.child_menus || []).map(function (m) { return menuCard(m, 'child'); }).join('');
            
            refreshQuotaInfo('main', parseInt(payload.max_main || 0, 10), document.getElementById('mainSelected'), 'mainExtraInfo', parseFloat(payload.extra_main_price || 0));
            refreshQuotaInfo('drink', parseInt(payload.max_drink || 0, 10), document.getElementById('drinkSelected'), 'drinkExtraInfo', parseFloat(payload.extra_drink_price || 0));
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
        var selectedDrink = selectedIds('drink');
        var selectedChild = selectedIds('child');
        if (selectedMain.length + selectedDrink.length + selectedChild.length === 0) {
            msgEl.textContent = 'Pilih minimal 1 menu.';
            msgEl.classList.add('err');
            return;
        }

        var body = {
            action: 'submit_link',
            token: token,
            selected_main: selectedMain,
            selected_drink: selectedDrink,
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
