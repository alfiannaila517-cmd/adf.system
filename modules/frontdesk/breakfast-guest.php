<?php
define('APP_ACCESS', true);
require_once '../../config/config.php';

$token = trim((string)($_GET['t'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Breakfast Menu Selection</title>
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
            background:
                radial-gradient(circle at top right, rgba(255, 255, 255, 0.35), transparent 42%),
                linear-gradient(140deg, rgba(30, 64, 175, 0.96), rgba(37, 99, 235, 0.9) 55%, rgba(14, 165, 233, 0.86));
            color: white;
            border: none;
            border-radius: 16px;
            padding: 14px 16px 12px;
        }
        .header-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .header-brand { min-width: 0; }
        .header-logo {
            height: 46px;
            max-width: 170px;
            object-fit: contain;
            display: none;
            background: rgba(255,255,255,0.94);
            border-radius: 10px;
            padding: 5px 8px;
            border: 1px solid rgba(255,255,255,0.45);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.16);
        }
        .header-logo.show { display: block; }
        .header-card .muted-white { color: #eff6ff; }
        h1 { margin: 0; font-size: 1.06rem; font-weight: 700; letter-spacing: .45px; text-transform: uppercase; }
        .header-subtitle { font-size: 0.78rem; color: #f8fbff; margin-top: 3px; letter-spacing: .25px; }
        .header-divider { margin-top: 8px; height: 1px; background: linear-gradient(90deg, rgba(219, 234, 254, 0.15), rgba(219, 234, 254, 0.75), rgba(219, 234, 254, 0.15)); }
        .muted { color: #475569; font-size: 0.9rem; }
        .muted-white { color: #ffffff; font-size: 0.92rem; font-weight: 500; text-shadow: 0 1px 2px rgba(15, 23, 42, 0.22); }
        .meta { display: flex; flex-direction: column; gap: 10px; margin-top: 12px; }
        .meta-item { background: rgba(255,255,255,0.15); border-radius: 10px; padding: 10px; }
        .meta-item-light { background: rgba(255,255,255,0.88); border: 1px solid rgba(96, 165, 250, 0.22); border-radius: 12px; padding: 10px 12px; }
        .meta-lbl { font-size: 0.65rem; color: rgba(255,255,255,0.8); text-transform: uppercase; font-weight: 700; letter-spacing: .5px; }
        .meta-lbl-dark { font-size: 0.65rem; color: #1d4ed8; text-transform: uppercase; font-weight: 700; letter-spacing: .5px; }
        .meta-val { margin-top: 4px; font-weight: 700; font-size: 0.98rem; color: #0f172a; }
        .section-title { font-size: 1.1rem; font-weight: 800; margin: 0 0 12px; color: #1f2937; display: flex; align-items: center; gap: 8px; }
        .section-icon { font-size: 1.3rem; }
        
        /* Moka-style menu cards */
        .menu-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
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
            height: 72px;
            background: linear-gradient(135deg, rgba(191, 219, 254, 0.28), rgba(147, 197, 253, 0.2));
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 4px;
        }
        .menu-img { width: 100%; height: 100%; object-fit: contain; border-radius: 8px; }
        .menu-img-placeholder { font-size: 2.2rem; }
        
        .menu-content { padding: 10px 11px 11px; }
        .menu-name { font-weight: 700; font-size: 0.96rem; color: #1f2937; margin-bottom: 4px; }
        .menu-desc { font-size: 0.76rem; color: #64748b; line-height: 1.35; margin-bottom: 8px; min-height: 30px; }
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
        .menu-note-wrap { display: none; margin-top: 8px; }
        .menu-item.selected .menu-note-wrap { display: block; }
        .menu-check:checked ~ .menu-content .menu-note-wrap { display: block; }
        .menu-note-input {
            width: 100%;
            border: 1px solid rgba(148, 163, 184, 0.32);
            border-radius: 8px;
            background: rgba(255,255,255,0.92);
            color: #0f172a;
            font-size: 0.72rem;
            padding: 7px 8px;
            line-height: 1.35;
        }
        .menu-note-input:focus { outline: none; border-color: #38bdf8; box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.12); }
        .menu-note-readonly { margin-top: 8px; font-size: 0.72rem; color: #475569; background: rgba(241, 245, 249, 0.8); border-radius: 8px; padding: 6px 8px; }
        
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

        .meta-stack { 
            grid-template-columns: 1fr; 
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
            font-size: 0.95rem;
        }
        .meta-item-light { 
            background: transparent; 
            border: none; 
            padding: 0 !important; 
            display: flex; 
            flex-direction: column; 
            gap: 2px;
        }
        .meta-lbl-dark { 
            font-size: 0.7rem; 
            text-transform: capitalize !important; 
            letter-spacing: 0;
            font-weight: 600;
        }
        .meta-val { 
            margin-top: 0 !important; 
            font-weight: 700 !important; 
            font-size: 1rem !important; 
        }
        .header-card .meta-lbl-dark { color: rgba(219, 234, 254, 0.92); }
        .header-card .meta-val { color: #ffffff; text-shadow: 0 1px 2px rgba(15, 23, 42, 0.25); }
        .drink-section { 
            margin-top: 24px; 
            padding-top: 20px; 
            border-top: 2px solid rgba(59, 130, 246, 0.25);
        }
        
        @media (max-width: 600px) {
            .menu-grid { grid-template-columns: repeat(2, 1fr); }
            .quota-box { flex-direction: column; text-align: center; }
            .header-logo { height: 40px; max-width: 130px; }
            .header-top { align-items: flex-start; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card" id="headerCard">
        <div class="header-card">
            <div class="header-top">
                <div class="header-brand">
                    <h1>Breakfast Selection</h1>
                    <div class="header-subtitle">Curated Morning Dining Experience</div>
                </div>
                <img id="portalLogo" class="header-logo" alt="Narayana Logo">
            </div>
            <div class="header-divider"></div>
            <div class="muted-white" id="stateText">Loading details...</div>
            <div class="meta hidden" id="metaBox"></div>
        </div>
    </div>

    <div class="card hidden" id="infoCard">
        <div class="section-title"><span class="section-icon">ℹ️</span> Information</div>
        <div class="info-box" id="infoText"></div>
        <a class="media-link hidden" id="infoMedia" target="_blank">Open attachment</a>
    </div>

    <div class="card hidden" id="mainCard">
        <div class="section-title"><span class="section-icon">🍽️</span> Main Course</div>
        <div class="quota-box">
            <div>
                <div class="quota-label">Allowed Main Course</div>
                <div class="quota-count"><span id="mainQuotaText">0</span> items</div>
            </div>
            <div>
                <div class="quota-label">Selected</div>
                <div class="quota-count" id="mainSelected">0</div>
            </div>
            <div id="mainExtraInfo" class="quota-extra"></div>
        </div>
        <div class="menu-grid" id="mainGrid"></div>
    </div>

    <div class="card hidden drink-section" id="drinkCard">
        <div class="section-title"><span class="section-icon">🥤</span> Beverages</div>
        <div class="quota-box">
            <div>
                <div class="quota-label">Allowed Beverages</div>
                <div class="quota-count"><span id="drinkQuotaText">0</span> items</div>
            </div>
            <div>
                <div class="quota-label">Selected</div>
                <div class="quota-count" id="drinkSelected">0</div>
            </div>
            <div id="drinkExtraInfo" class="quota-extra"></div>
        </div>
        <div class="menu-grid" id="drinkGrid"></div>
    </div>

    <div class="card hidden" id="childCard">
        <div class="section-title"><span class="section-icon">👶</span> Kids / Fruit</div>
        <div class="quota-box">
            <div>
                <div class="quota-label">Allowed Kids / Fruit</div>
                <div class="quota-count"><span id="childQuotaText">0</span> items</div>
            </div>
            <div>
                <div class="quota-label">Selected</div>
                <div class="quota-count" id="childSelected">0</div>
            </div>
            <div id="childExtraInfo" class="quota-extra"></div>
        </div>
        <div class="menu-grid" id="childGrid"></div>
    </div>

    <div class="card hidden" id="submitCard">
        <div class="section-title"><span class="section-icon">📝</span> Additional Notes</div>
        <textarea id="notes" placeholder="Example: no spicy food / egg allergy / others"></textarea>
        <div class="actions">
            <button class="btn btn-primary" id="btnSubmit">Submit Breakfast Selection</button>
        </div>
        <div class="msg" id="submitMsg"></div>
    </div>
</div>

<script>
(function () {
    var token = <?php echo json_encode($token); ?>;
    var API = <?php echo json_encode(rtrim(BASE_URL, '/') . '/api/breakfast-guest-portal.php'); ?>;
    var BASE_URL = <?php echo json_encode(rtrim(BASE_URL, '/')); ?>;
    var payload = null;
    var portalLogoEl = document.getElementById('portalLogo');

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

            var submitBtn = document.getElementById('btnSubmit');
            if (submitBtn) submitBtn.style.display = 'none';
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
        meta.className = 'meta meta-stack';
        meta.innerHTML = [
            ['Guest', payload.guest_name || '-'],
            ['Room', Array.isArray(payload.room_number) ? payload.room_number.join(', ') : '-'],
            ['Date', formatDate(payload.breakfast_date)],
            ['Expires', payload.expires_at || '-']
        ].map(function (it) {
            return '<div class="meta-item-light"><div class="meta-lbl-dark">' + esc(it[0]) + '</div><div class="meta-val">' + esc(it[1]) + '</div></div>';
        }).join('');

        document.getElementById('mainQuotaText').textContent = String(payload.max_main || 0);
        document.getElementById('drinkQuotaText').textContent = String(payload.max_drink || 0);
        document.getElementById('childQuotaText').textContent = String(payload.max_child || 0);

        var infoText = (payload.wa_info_text || '').trim();
        var infoMedia = (payload.wa_media_url || '').trim();
        document.getElementById('infoText').textContent = infoText || 'No additional information.';
        if (infoMedia) {
            var mediaEl = document.getElementById('infoMedia');
            mediaEl.href = infoMedia;
            mediaEl.classList.remove('hidden');
        }

        if (portalLogoEl) {
            var logoUrl = (payload.portal_logo_url || '').trim();
            if (logoUrl) {
                portalLogoEl.src = logoUrl;
                portalLogoEl.classList.add('show');
            } else {
                portalLogoEl.classList.remove('show');
                portalLogoEl.removeAttribute('src');
            }
        }
    }

    function menuCard(item, group) {
        var locked = !!payload.is_locked;
        var price = parseFloat(item.price || 0);
        var free = String(item.is_free) === '1' || item.is_free === 1 || item.is_free === true;
        var noteMap = group === 'main'
            ? (payload.selected_main_notes || {})
            : (group === 'drink' ? (payload.selected_drink_notes || {}) : (payload.selected_child_notes || {}));
        var noteVal = String(noteMap[String(item.id)] || noteMap[item.id] || '');
        var imgUrl = item.image_url || '';
        var desc = item.description || '';
        var hasImg = imgUrl && imgUrl.trim() !== '';
        var checked = item.pre_selected ? 'checked' : '';
        var resolvedImg = imgUrl ? (/^https?:\/\//i.test(imgUrl) ? imgUrl : BASE_URL + '/' + imgUrl.replace(/^\/+/, '')) : '';
        
        var imgHtml = '';
        if (hasImg) {
            imgHtml = '<div class="menu-img-wrap"><img class="menu-img" src="' + esc(resolvedImg) + '" alt="' + esc(item.menu_name) + '" onerror="this.parentElement.innerHTML=\'<span class=\'menu-img-placeholder\'>🍽️</span>\'"></div>';
        } else {
            imgHtml = '<div class="menu-img-wrap"><span class="menu-img-placeholder">🍽️</span></div>';
        }

        if (locked) {
            var noteHtmlLocked = noteVal
                ? '<div class="menu-note-readonly">Request: ' + esc(noteVal) + '</div>'
                : '';
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
                noteHtmlLocked +
                '</div>' +
                '</div>';
        }

        var noteHtmlEdit = '<div class="menu-note-wrap">' +
            '<input class="menu-note-input" type="text" data-group="' + group + '" data-menu-id="' + item.id + '" value="' + esc(noteVal) + '"' +
            ' placeholder="Special request (e.g. spicy / medium / no onion)" onclick="event.stopPropagation()" onfocus="event.stopPropagation()">' +
            '</div>';
        
        return '<label class="menu-item' + (item.pre_selected ? ' selected' : '') + '">' +
            '<input type="checkbox" class="menu-check" data-group="' + group + '" value="' + item.id + '" ' + checked + '>' +
            imgHtml +
            '<div class="menu-content">' +
            '<div class="menu-name">' + esc(item.menu_name) + '</div>' +
            '<div class="menu-desc">' + esc(desc) + '</div>' +
            '<div class="menu-footer">' +
            '<span class="menu-cat">' + esc(item.category || '-') + '</span>' +
            '<span class="menu-price ' + (free ? 'free' : '') + '">' + (free ? 'FREE' : 'Rp ' + Math.round(price).toLocaleString('id-ID')) + '</span>' +
            '</div>' +
            noteHtmlEdit +
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
        var estText = est > 0 ? (' (est. +' + est.toLocaleString('id-ID') + ')') : '';
        infoEl.textContent = 'Extra ' + extraCount + ' item(s)' + estText + ' will be charged at Front Desk.';
        if (max <= 0) {
            infoEl.textContent = '';
        }
    }

    function attachQuotaHandlers() {
        document.addEventListener('change', function (ev) {
            var el = ev.target;
            if (!el.classList.contains('menu-check')) return;

            var menuItem = el.closest('.menu-item');
            if (menuItem) {
                menuItem.classList.toggle('selected', !!el.checked);
            }

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

    function selectedWithNotes(group) {
        var ids = [];
        var notes = {};
        Array.from(document.querySelectorAll('.menu-check[data-group="' + group + '"]:checked')).forEach(function (c) {
            var id = parseInt(c.value, 10);
            if (!Number.isFinite(id) || id <= 0) return;
            ids.push(id);
            var noteInput = document.querySelector('.menu-note-input[data-group="' + group + '"][data-menu-id="' + id + '"]');
            var note = noteInput ? String(noteInput.value || '').trim() : '';
            if (note) {
                notes[String(id)] = note;
            }
        });
        return { ids: ids, notes: notes };
    }

    async function loadLink() {
        if (!token) {
            setState('Token is missing.', true);
            return;
        }
        try {
            var res = await fetch(API + '?action=get_link&token=' + encodeURIComponent(token));
            var json = await res.json();
            if (!json.success) {
                setState(json.message || 'Invalid link.', true);
                return;
            }

            payload = json.data || {};
            setState(payload.is_locked ? 'Selection already submitted. This link is read-only.' : 'Please choose items based on your allowance.', false);
            renderMeta();
            openCards();

            mainGrid.innerHTML = (payload.main_menus || []).map(function (m) { return menuCard(m, 'main'); }).join('');
            drinkGrid.innerHTML = (payload.drink_menus || []).map(function (m) { return menuCard(m, 'drink'); }).join('');
            childGrid.innerHTML = (payload.child_menus || []).map(function (m) { return menuCard(m, 'child'); }).join('');
            
            refreshQuotaInfo('main', parseInt(payload.max_main || 0, 10), document.getElementById('mainSelected'), 'mainExtraInfo', parseFloat(payload.extra_main_price || 0));
            refreshQuotaInfo('drink', parseInt(payload.max_drink || 0, 10), document.getElementById('drinkSelected'), 'drinkExtraInfo', parseFloat(payload.extra_drink_price || 0));
            refreshQuotaInfo('child', parseInt(payload.max_child || 0, 10), document.getElementById('childSelected'), 'childExtraInfo', parseFloat(payload.extra_child_price || 0));
        } catch (err) {
            setState('Failed to load link: ' + err.message, true);
        }
    }

    async function submitChoice() {
        var msgEl = document.getElementById('submitMsg');
        msgEl.textContent = '';
        msgEl.className = 'msg';

        var mainPicked = selectedWithNotes('main');
        var drinkPicked = selectedWithNotes('drink');
        var childPicked = selectedWithNotes('child');
        var selectedMain = mainPicked.ids;
        var selectedDrink = drinkPicked.ids;
        var selectedChild = childPicked.ids;
        if (selectedMain.length + selectedDrink.length + selectedChild.length === 0) {
            msgEl.textContent = 'Please select at least 1 item.';
            msgEl.classList.add('err');
            return;
        }

        var body = {
            action: 'submit_link',
            token: token,
            selected_main: selectedMain,
            selected_main_notes: mainPicked.notes,
            selected_drink: selectedDrink,
            selected_drink_notes: drinkPicked.notes,
            selected_child: selectedChild,
            selected_child_notes: childPicked.notes,
            special_requests: (document.getElementById('notes').value || '').trim(),
            location: 'restaurant'
        };

        var btn = document.getElementById('btnSubmit');
        btn.disabled = true;
        btn.textContent = 'Submitting...';

        try {
            var res = await fetch(API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(body)
            });
            var json = await res.json();
            if (!json.success) {
                msgEl.textContent = json.message || 'Failed to submit selection.';
                msgEl.classList.add('err');
                btn.disabled = false;
                btn.textContent = 'Submit Breakfast Selection';
                return;
            }

            msgEl.textContent = 'Thank you. Your breakfast selection has been submitted.';
            if (json.data && json.data.extra_total_price && json.data.extra_total_price > 0) {
                msgEl.textContent += ' Extra charge: Rp ' + Math.round(json.data.extra_total_price).toLocaleString('id-ID') + ' (pay at Front Desk).';
            }
            msgEl.classList.add('ok');
            btn.textContent = 'Submitted';
        } catch (err) {
            msgEl.textContent = 'Connection error: ' + err.message;
            msgEl.classList.add('err');
            btn.disabled = false;
            btn.textContent = 'Submit Breakfast Selection';
        }
    }

    attachQuotaHandlers();
    loadLink();
    document.getElementById('btnSubmit').addEventListener('click', submitChoice);
})();
</script>
</body>
</html>
