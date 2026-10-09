<?php
if (!defined('APP_ACCESS')) { http_response_code(403); exit; }
/**
 * Sisipkan di halaman Front Desk (Kalender, Reservasi, Dashboard) sebelum footer:
 * memicu sinkron Cloudbeds di latar belakang saat halaman dibuka, menampilkan indikator
 * "tersinkron HH:MM" di pojok, dan memberi tahu bila ada perubahan dari Cloudbeds.
 * Tidak menampilkan apa pun bila Cloudbeds belum diatur / sinkron otomatis mati.
 */
try {
    $cbAutoRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_auto_sync'");
    $cbKeyRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_api_key'");
    $cbLastRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_last_auto_sync'");
} catch (\Throwable $e) {
    $cbAutoRow = $cbKeyRow = $cbLastRow = null;
}
if (($cbAutoRow['setting_value'] ?? '0') === '1' && !empty($cbKeyRow['setting_value'])):
    $cbLast = json_decode((string)($cbLastRow['setting_value'] ?? ''), true) ?: [];
?>
<style>
    .cbs-wrap, .cbs-wrap * { box-sizing: border-box; font-family: inherit; letter-spacing: normal; text-transform: none; }
    .cbs-wrap { position: fixed; right: 18px; bottom: 18px; z-index: 10060; display: flex; flex-direction: column; align-items: flex-end; gap: 10px; pointer-events: none; }
    .cbs-wrap > * { pointer-events: auto; }

    /* Indikator sinkron: di samping judul halaman ([data-cbs-slot]); tanpa slot → pojok kanan bawah */
    #cbsPill { position: relative; display: inline-flex; align-items: center; gap: 7px; height: 28px; padding: 0 11px 0 9px; border-radius: 999px; background: #ffffff; border: 1px solid #e2e8f0; box-shadow: 0 4px 14px -8px rgba(15, 23, 42, .3); font-size: 0.7rem; line-height: 1; font-weight: 600; text-decoration: none !important; cursor: pointer; transition: border-color .2s, box-shadow .2s; white-space: nowrap; vertical-align: middle; }
    #cbsPill:hover, #cbsPill:focus-visible { border-color: #93c5fd; box-shadow: 0 6px 18px -8px rgba(37, 99, 235, .35); outline: none; }
    #cbsPill.inline { box-shadow: none; background: #f8fafc; }
    #cbsPill .cbs-brand { color: #0f172a !important; font-weight: 700; font-size: inherit; }
    #cbsPill .cbs-sep { color: #cbd5e1 !important; font-weight: 400; font-size: inherit; }
    #cbsPill .cbs-time { color: #64748b !important; font-weight: 600; font-size: inherit; }
    #cbsPill .cbs-dot { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 3px rgba(34, 197, 94, .18); flex-shrink: 0; }
    #cbsPill.is-busy .cbs-dot { background: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, .2); animation: cbsPulse 1s ease-in-out infinite; }
    #cbsPill.is-err .cbs-dot { background: #ef4444; box-shadow: 0 0 0 3px rgba(239, 68, 68, .2); }
    #cbsPill.is-err .cbs-time { color: #dc2626 !important; }
    @keyframes cbsPulse { 50% { opacity: .35; } }
    body[data-theme="dark"] #cbsPill { background: rgba(30, 41, 59, .85); border-color: rgba(148, 163, 184, .25); }
    body[data-theme="dark"] #cbsPill .cbs-brand { color: #f1f5f9 !important; }
    body[data-theme="dark"] #cbsPill .cbs-time { color: #94a3b8 !important; }
    body[data-theme="dark"] #cbsPill .cbs-sep { color: #475569 !important; }

    /* Kartu info saat kursor menyentuh indikator */
    #cbsPill .cbs-pop { position: absolute; z-index: 10070; width: 280px; padding: 0; border-radius: 14px; background: #ffffff; border: 1px solid #e2e8f0; box-shadow: 0 22px 44px -18px rgba(15, 23, 42, .35), 0 2px 6px rgba(15, 23, 42, .05); white-space: normal; cursor: default; opacity: 0; visibility: hidden; transform: translateY(-4px); transition: opacity .16s ease, transform .16s ease, visibility .16s; text-align: left; }
    #cbsPill.inline .cbs-pop { top: calc(100% + 10px); left: 0; }
    #cbsPill:not(.inline) .cbs-pop { bottom: calc(100% + 10px); right: 0; transform: translateY(4px); }
    #cbsPill:hover .cbs-pop, #cbsPill:focus-visible .cbs-pop { opacity: 1; visibility: visible; transform: none; }
    #cbsPill .cbs-pop::before { content: ''; position: absolute; left: 0; right: 0; height: 12px; }
    #cbsPill.inline .cbs-pop::before { top: -12px; }
    #cbsPill:not(.inline) .cbs-pop::before { bottom: -12px; }
    #cbsPill .cbs-pop-head { display: flex; align-items: center; gap: 10px; padding: 12px 14px 10px; border-bottom: 1px solid #f1f5f9; }
    #cbsPill .cbs-pop-ic { width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: #eff6ff; color: #2563eb !important; }
    #cbsPill .cbs-pop-ic svg { width: 16px; height: 16px; }
    #cbsPill .cbs-pop-ttl { flex: 1; min-width: 0; display: block; }
    #cbsPill .cbs-pop-ttl b { display: block; font-size: 0.78rem; font-weight: 800; color: #0f172a !important; }
    #cbsPill .cbs-pop-ttl small { display: block; margin-top: 2px; font-size: 0.66rem; font-weight: 500; color: #64748b !important; }
    #cbsPill .cbs-pop-st { flex-shrink: 0; padding: 3px 8px; border-radius: 999px; font-size: 0.6rem; font-weight: 800; background: #dcfce7; color: #15803d !important; }
    #cbsPill .cbs-pop-st.err { background: #fee2e2; color: #b91c1c !important; }
    #cbsPill .cbs-pop-st.busy { background: #dbeafe; color: #1d4ed8 !important; }
    #cbsPill .cbs-pop-body { display: block; padding: 10px 14px 4px; }
    #cbsPill .cbs-pop-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    #cbsPill .cbs-pop-item { display: flex; align-items: center; justify-content: space-between; gap: 6px; padding: 6px 8px; border-radius: 8px; background: #f8fafc; font-size: 0.66rem; font-weight: 600; color: #475569 !important; }
    #cbsPill .cbs-pop-item b { font-size: 0.72rem; font-weight: 800; color: #0f172a !important; }
    #cbsPill .cbs-pop-item.bad { background: #fef2f2; color: #b91c1c !important; }
    #cbsPill .cbs-pop-item.bad b { color: #b91c1c !important; }
    #cbsPill .cbs-pop-none { display: block; padding: 8px 10px; border-radius: 8px; background: #f8fafc; font-size: 0.68rem; color: #64748b !important; text-align: center; }
    #cbsPill .cbs-pop-item.warn { background: #fffbeb; color: #b45309 !important; }
    #cbsPill .cbs-pop-item.warn b { color: #b45309 !important; }
    #cbsPill .cbs-pop-sec { display: block; margin: 10px 0 6px; font-size: 0.6rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: #94a3b8 !important; }
    #cbsPill .cbs-pop-issue { display: block; padding: 7px 9px; margin-bottom: 5px; border-radius: 8px; background: #fffbeb; border-left: 3px solid #f59e0b; font-size: 0.64rem; line-height: 1.4; color: #78350f !important; }
    #cbsPill .cbs-pop-issue b { display: block; font-size: 0.64rem; font-weight: 700; color: #0f172a !important; margin-bottom: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    #cbsPill .cbs-pop-issue.err { background: #fef2f2; border-left-color: #ef4444; color: #991b1b !important; }
    #cbsPill .cbs-pop-more { display: block; font-size: 0.62rem; color: #64748b !important; margin: 2px 0 2px; }
    body[data-theme="dark"] #cbsPill .cbs-pop-issue { background: rgba(245, 158, 11, .1); color: #fcd34d !important; }
    body[data-theme="dark"] #cbsPill .cbs-pop-issue b { color: #f1f5f9 !important; }
    #cbsPill .cbs-pop-msg { display: block; padding: 8px 10px; border-radius: 8px; background: #fef2f2; font-size: 0.66rem; line-height: 1.45; color: #b91c1c !important; word-break: break-word; }
    #cbsPill .cbs-pop-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 9px 14px 11px; font-size: 0.62rem; color: #94a3b8 !important; }
    #cbsPill .cbs-pop-foot span { color: #94a3b8 !important; font-size: inherit; }
    #cbsPill .cbs-pop-foot .go { color: #2563eb !important; font-weight: 700; }
    body[data-theme="dark"] #cbsPill .cbs-pop { background: #1e293b; border-color: rgba(148, 163, 184, .2); box-shadow: 0 22px 44px -18px rgba(0, 0, 0, .7); }
    body[data-theme="dark"] #cbsPill .cbs-pop-head { border-color: rgba(148, 163, 184, .12); }
    body[data-theme="dark"] #cbsPill .cbs-pop-ttl b, body[data-theme="dark"] #cbsPill .cbs-pop-item b { color: #f1f5f9 !important; }
    body[data-theme="dark"] #cbsPill .cbs-pop-item, body[data-theme="dark"] #cbsPill .cbs-pop-none { background: rgba(255, 255, 255, .04); color: #94a3b8 !important; }
    body[data-theme="dark"] #cbsPill .cbs-pop-ic { background: rgba(59, 130, 246, .15); color: #93c5fd !important; }
    /* Kartu pemberitahuan perubahan */
    .cbs-card { width: 330px; max-width: calc(100vw - 36px); background: #ffffff; color: #0f172a; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 24px 48px -16px rgba(15, 23, 42, .4); overflow: hidden; display: none; animation: cbsIn .28s cubic-bezier(.2, .8, .2, 1); }
    .cbs-card.show { display: block; }
    @keyframes cbsIn { from { opacity: 0; transform: translateY(12px) scale(.98); } to { opacity: 1; transform: none; } }
    .cbs-card-head { display: flex; align-items: center; gap: 11px; padding: 14px 14px 10px 16px; }
    .cbs-ic { width: 36px; height: 36px; border-radius: 11px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #6366f1, #3b82f6); color: #fff; }
    .cbs-ic svg { width: 18px; height: 18px; }
    .cbs-title { flex: 1; min-width: 0; }
    .cbs-title b { display: block; font-size: 14px; font-weight: 700; color: #0f172a; line-height: 1.25; }
    .cbs-title small { display: block; font-size: 11.5px; color: #64748b; margin-top: 2px; line-height: 1.3; }
    .cbs-x { border: 0; background: transparent; color: #94a3b8; width: 28px; height: 28px; border-radius: 8px; cursor: pointer; font-size: 18px; line-height: 1; padding: 0; display: flex; align-items: center; justify-content: center; }
    .cbs-x:hover { background: #f1f5f9; color: #334155; }
    .cbs-list { list-style: none; margin: 0; padding: 2px 16px 4px; }
    .cbs-list li { display: flex; align-items: center; gap: 10px; padding: 7px 0; font-size: 13px; color: #334155; border-top: 1px dashed #e2e8f0; }
    .cbs-list li:first-child { border-top: 0; }
    .cbs-num { min-width: 26px; height: 22px; padding: 0 7px; border-radius: 7px; font-size: 12px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; }
    .cbs-num.g { background: #dcfce7; color: #15803d; }
    .cbs-num.r { background: #fee2e2; color: #b91c1c; }
    .cbs-num.a { background: #fef3c7; color: #b45309; }
    .cbs-num.b { background: #e0e7ff; color: #4338ca; }
    .cbs-actions { display: flex; gap: 8px; padding: 10px 16px 14px; }
    .cbs-btn { flex: 1; border-radius: 10px; padding: 9px 12px; font-size: 13px; font-weight: 700; cursor: pointer; line-height: 1; border: 1px solid transparent; }
    .cbs-btn.primary { background: #0f172a; color: #fff; }
    .cbs-btn.primary:hover { background: #1e293b; }
    .cbs-btn.ghost { background: #fff; color: #475569; border-color: #e2e8f0; flex: 0 0 auto; }
    .cbs-btn.ghost:hover { background: #f8fafc; }

    @media (max-width: 600px) {
        .cbs-wrap { right: 12px; bottom: 12px; }
        .cbs-pill .cbs-brand, .cbs-pill .cbs-sep { display: none; }
    }
</style>
<div class="cbs-wrap" id="cbsWrap">
    <div class="cbs-card" id="cbAutoToast" role="status" aria-live="polite">
        <div class="cbs-card-head">
            <span class="cbs-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/><path d="M3 21v-5h5"/><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5"/></svg></span>
            <div class="cbs-title">
                <b>Ada perubahan dari Cloudbeds</b>
                <small id="cbAutoToastTime"></small>
            </div>
            <button type="button" class="cbs-x" aria-label="Tutup" onclick="document.getElementById('cbAutoToast').classList.remove('show')">&times;</button>
        </div>
        <ul class="cbs-list" id="cbAutoToastList"></ul>
        <div class="cbs-actions">
            <button type="button" class="cbs-btn primary" onclick="location.reload()">Muat ulang halaman</button>
            <button type="button" class="cbs-btn ghost" onclick="document.getElementById('cbAutoToast').classList.remove('show')">Nanti</button>
        </div>
    </div>
    <a class="cbs-pill" id="cbsPill" href="<?php echo htmlspecialchars(BASE_URL . '/modules/frontdesk/cloudbeds.php'); ?>" aria-describedby="cbsPop">
        <span class="cbs-dot"></span>
        <span class="cbs-brand">Cloudbeds</span>
        <span class="cbs-sep">·</span>
        <span class="cbs-time" id="cbsPillTime">menunggu sinkron…</span>
        <span class="cbs-pop" id="cbsPop" role="tooltip">
            <span class="cbs-pop-head">
                <span class="cbs-pop-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.5 19a4.5 4.5 0 1 0-1.4-8.78A6 6 0 1 0 6 17.5"/><path d="M12 13v8M9 18l3 3 3-3"/></svg></span>
                <span class="cbs-pop-ttl"><b>Sinkron Cloudbeds</b><small id="cbsPopWhen">Belum ada sinkron</small></span>
                <span class="cbs-pop-st" id="cbsPopSt">OK</span>
            </span>
            <span class="cbs-pop-body" id="cbsPopBody"></span>
            <span class="cbs-pop-foot"><span>Otomatis tiap 5 menit &amp; saat halaman dibuka</span><span class="go">Pengaturan →</span></span>
        </span>
    </a>
</div>
<script>
    (function() {
        var pill = document.getElementById('cbsPill');
        var pillTime = document.getElementById('cbsPillTime');

        function hhmm(s) {
            var m = /(\d{2}):(\d{2})/.exec(String(s || ''));
            return m ? m[1] + ':' + m[2] : '';
        }

        var serverSkew = <?php echo time() * 1000; ?> - Date.now();
        var LABELS = { 'baru': 'Booking baru', 'harga': 'Harga disamakan', 'kamar': 'Kamar dipindah', 'taut': 'Ditautkan', 'batal': 'Dibatalkan', 'blok': 'Blok masuk', 'cabut': 'Blok dicabut',
            'kirim status': 'Status terkirim', 'kirim baru': 'Booking terkirim', 'kirim blok': 'Blok terkirim', 'kirim bayar': 'Bayar terkirim', 'dicek': 'Perlu dicek', 'gagal': 'Gagal' };
        function esc(t) { return String(t).replace(/[&<>"]/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
        function ago(at) {
            var t = Date.parse(String(at).replace(' ', 'T'));
            if (!t) return '';
            var m = Math.round((Date.now() + serverSkew - t) / 60000);
            return m <= 0 ? 'baru saja' : (m < 60 ? m + ' menit lalu' : Math.floor(m / 60) + ' jam lalu');
        }
        function fillPop(state, at, summary, issues) {
            var st = document.getElementById('cbsPopSt');
            st.className = 'cbs-pop-st' + (state === 'err' ? ' err' : (state === 'busy' ? ' busy' : ''));
            st.textContent = state === 'err' ? 'Gagal' : (state === 'busy' ? 'Berjalan' : 'Lancar');
            document.getElementById('cbsPopWhen').textContent = at ? 'Terakhir ' + hhmm(at) + ' · ' + ago(at) + (/halaman dibuka/.test(summary || '') ? ' · dari halaman' : '') : 'Belum ada sinkron';
            var body = document.getElementById('cbsPopBody');
            var s = String(summary || '');
            if (/^GAGAL:/i.test(s)) {
                body.innerHTML = '<span class="cbs-pop-msg">' + esc(s.replace(/^GAGAL:\s*/i, '')) + '</span>';
                return;
            }
            var items = [];
            s.replace(/\s*\(.*?\)\s*$/, '').split(',').forEach(function(p) {
                var m = /^\s*([a-z ]+?)\s+(\d+)\s*$/i.exec(p);
                if (m && +m[2] > 0) {
                    var k = m[1].toLowerCase();
                    items.push('<span class="cbs-pop-item' + (k === 'gagal' ? ' bad' : (k === 'dicek' ? ' warn' : '')) + '">' + esc(LABELS[k] || m[1]) + '<b>' + m[2] + '</b></span>');
                }
            });
            var html = items.length ? '<span class="cbs-pop-grid">' + items.join('') + '</span>' : '<span class="cbs-pop-none">' + (s ? 'Tidak ada perubahan — sistem &amp; Cloudbeds sudah sama' : 'Menunggu sinkron pertama') + '</span>';
            // Isi peringatan / error agar jelas apa yang perlu dicek
            var errs = (issues && issues.errors) || [], warns = (issues && issues.warns) || [];
            if (errs.length || warns.length) {
                html += '<span class="cbs-pop-sec">' + (errs.length ? 'Gagal &amp; perlu dicek' : 'Perlu dicek') + '</span>';
                errs.slice(0, 2).forEach(function(e) { html += '<span class="cbs-pop-issue err">' + esc(e) + '</span>'; });
                warns.slice(0, 3).forEach(function(w) { html += '<span class="cbs-pop-issue"><b>' + esc(w.label) + '</b>' + esc(w.msg) + '</span>'; });
                var more = Math.max(0, warns.length - 3);
                if (more) html += '<span class="cbs-pop-more">+' + more + ' lainnya — lihat di Pengaturan Cloudbeds</span>';
            }
            body.innerHTML = html;
        }

        var lastAt = '', lastSummary = '';
        var lastIssues = null;
        function setPill(state, at, summary, issues) {
            if (state !== 'busy') { if (at) lastAt = at; else at = lastAt; if (summary) lastSummary = summary; else summary = lastSummary; if (issues) lastIssues = issues; }
            pill.classList.toggle('is-busy', state === 'busy');
            pill.classList.toggle('is-err', state === 'err');
            if (state === 'busy') pillTime.textContent = 'menyinkron…';
            else if (state === 'err') pillTime.textContent = 'sinkron gagal' + (at ? ' · ' + hhmm(at) : '');
            else pillTime.textContent = at ? 'tersinkron ' + hhmm(at) : 'menunggu sinkron…';
            fillPop(state, state === 'busy' ? lastAt : at, state === 'busy' ? lastSummary : summary, lastIssues);
        }
        // Tempatkan di samping judul halaman bila halaman menyediakan slot
        var slot = document.querySelector('[data-cbs-slot]');
        if (slot) { slot.appendChild(pill); pill.classList.add('inline'); }
        setPill(<?php echo json_encode(empty($cbLast['at']) ? 'ok' : (!empty($cbLast['ok']) ? 'ok' : 'err')); ?>, <?php echo json_encode($cbLast['at'] ?? ''); ?>, <?php echo json_encode($cbLast['summary'] ?? ''); ?>, <?php echo json_encode(['warns' => $cbLast['warns'] ?? [], 'errors' => $cbLast['errors'] ?? []], JSON_UNESCAPED_UNICODE); ?>);

        // Sinkron Cloudbeds di latar belakang setelah halaman tampil (server membatasi maks. sekali per menit)
        var running = false;
        function run() {
            if (running) return;
            running = true;
            setPill('busy');
            fetch(<?php echo json_encode(BASE_URL . '/api/cloudbeds-sync-now.php'); ?>, { method: 'POST', credentials: 'include' })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    running = false;
                    if (!d) return;
                    if (!d.ok) { setPill('err', '', d.error || ''); return; }
                    setPill(d.last_ok === false ? 'err' : 'ok', d.last || '', d.summary || '', d.warns || d.errors ? { warns: d.warns || [], errors: d.errors || [] } : null);
                    if (d.skipped) return;
                    var items = [];
                    if (d.created) items.push(['g', d.created, 'booking baru masuk']);
                    if (d.cancelled) items.push(['r', d.cancelled, 'booking dibatalkan']);
                    if (d.blocked) items.push(['a', d.blocked, 'kamar diblok']);
                    if (d.unblocked) items.push(['b', d.unblocked, 'blok kamar dicabut']);
                    if (d.moved) items.push(['b', d.moved, 'kamar dipindah']);
                    if (d.repriced) items.push(['a', d.repriced, 'harga disamakan']);
                    if (!items.length) return;
                    var ul = document.getElementById('cbAutoToastList');
                    ul.innerHTML = '';
                    items.forEach(function(it) {
                        var li = document.createElement('li');
                        var n = document.createElement('span');
                        n.className = 'cbs-num ' + it[0];
                        n.textContent = it[1];
                        li.appendChild(n);
                        li.appendChild(document.createTextNode(it[2]));
                        ul.appendChild(li);
                    });
                    document.getElementById('cbAutoToast').classList.add('show');
                    // Ada perubahan dari Cloudbeds → halaman dimuat ulang otomatis (hitung mundur 4 dtk, bisa dibatalkan).
                    // Tidak otomatis bila ada popup / formulir yang sedang dibuka agar isian tidak hilang.
                    var busyUi = document.querySelector('.modal-overlay.active, .modal.show, .guest-side-panel-overlay.active, .mv-overlay.active, [role="dialog"].open');
                    var timeEl = document.getElementById('cbAutoToastTime');
                    // Pengaman: tidak memuat ulang otomatis lebih dari sekali per menit (mencegah muat-ulang berulang)
                    var lastAuto = 0;
                    try { lastAuto = parseInt(sessionStorage.getItem('cbsAutoReload') || '0', 10) || 0; } catch (e) {}
                    if (busyUi || Date.now() - lastAuto < 60000) {
                        timeEl.textContent = 'Disinkron pukul ' + hhmm(d.last) + ' · muat ulang untuk melihatnya';
                        return;
                    }
                    try { sessionStorage.setItem('cbsAutoReload', String(Date.now())); } catch (e) {}
                    var left = 4;
                    var tick = function() {
                        if (!document.getElementById('cbAutoToast').classList.contains('show')) return; // dibatalkan ("Nanti"/tutup)
                        timeEl.textContent = 'Memuat ulang dalam ' + left + ' dtk…';
                        if (left-- <= 0) { location.reload(); return; }
                        setTimeout(tick, 1000);
                    };
                    tick();
                })
                .catch(function() { running = false; setPill('err'); });
        }
        // 1) Pemeriksaan KAMAR kilat (1–3 dtk): kamar yang dipindah di Cloudbeds langsung ikut → halaman segera dimuat ulang
        // 2) Sinkron penuh di latar belakang (booking baru, harga, dll.)
        function quickRooms() {
            fetch(<?php echo json_encode(BASE_URL . '/api/cloudbeds-rooms-now.php'); ?>, { method: 'POST', credentials: 'include' })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d && d.moved) {
                        var lastAuto = 0;
                        try { lastAuto = parseInt(sessionStorage.getItem('cbsAutoReload') || '0', 10) || 0; } catch (e) {}
                        var ul = document.getElementById('cbAutoToastList');
                        ul.innerHTML = '<li><span class="cbs-num b">' + d.moved + '</span>kamar dipindah mengikuti Cloudbeds</li>';
                        document.getElementById('cbAutoToastTime').textContent = 'Memuat ulang…';
                        document.getElementById('cbAutoToast').classList.add('show');
                        var busyUi = document.querySelector('.modal-overlay.active, .modal.show, .guest-side-panel-overlay.active, .mv-overlay.active, [role="dialog"].open');
                        if (!busyUi && Date.now() - lastAuto > 20000) {
                            try { sessionStorage.setItem('cbsAutoReload', String(Date.now())); } catch (e) {}
                            setTimeout(function() { if (document.getElementById('cbAutoToast').classList.contains('show')) location.reload(); }, 900);
                            return;
                        }
                        document.getElementById('cbAutoToastTime').textContent = 'Muat ulang halaman untuk melihatnya';
                    }
                    setTimeout(run, 100);
                })
                .catch(function() { setTimeout(run, 100); });
        }
        if (document.readyState === 'complete') setTimeout(quickRooms, 150);
        else window.addEventListener('load', function() { setTimeout(quickRooms, 150); });
        // Selama halaman terbuka: cek Cloudbeds tiap menit (server tetap membatasi); berhenti saat tab disembunyikan
        setInterval(function() { if (!document.hidden) run(); }, 60000);
        document.addEventListener('visibilitychange', function() { if (!document.hidden) run(); });
    })();
</script>
<?php endif; ?>
