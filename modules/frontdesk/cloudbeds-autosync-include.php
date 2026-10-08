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

    /* Indikator kecil: status sinkron terakhir */
    .cbs-pill { display: inline-flex; align-items: center; gap: 8px; padding: 7px 13px 7px 10px; border-radius: 999px; background: #ffffff; color: #334155; border: 1px solid #e2e8f0; box-shadow: 0 6px 18px -8px rgba(15, 23, 42, .35); font-size: 12px; line-height: 1; font-weight: 600; text-decoration: none; cursor: pointer; transition: box-shadow .2s, transform .2s; white-space: nowrap; }
    .cbs-pill:hover { box-shadow: 0 10px 24px -10px rgba(15, 23, 42, .45); transform: translateY(-1px); color: #0f172a; text-decoration: none; }
    .cbs-pill .cbs-brand { color: #0f172a; font-weight: 700; }
    .cbs-pill .cbs-sep { color: #cbd5e1; font-weight: 400; }
    .cbs-pill .cbs-time { color: #64748b; font-weight: 600; }
    .cbs-dot { width: 8px; height: 8px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 3px rgba(34, 197, 94, .18); flex-shrink: 0; }
    .cbs-pill.is-busy .cbs-dot { background: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, .2); animation: cbsPulse 1s ease-in-out infinite; }
    .cbs-pill.is-err .cbs-dot { background: #ef4444; box-shadow: 0 0 0 3px rgba(239, 68, 68, .2); }
    .cbs-pill.is-err .cbs-time { color: #dc2626; }
    @keyframes cbsPulse { 50% { opacity: .35; } }

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
    <a class="cbs-pill" id="cbsPill" href="<?php echo htmlspecialchars(BASE_URL . '/modules/frontdesk/cloudbeds.php'); ?>" title="">
        <span class="cbs-dot"></span>
        <span class="cbs-brand">Cloudbeds</span>
        <span class="cbs-sep">·</span>
        <span class="cbs-time" id="cbsPillTime">menunggu sinkron…</span>
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

        var lastAt = '', lastSummary = '';
        function setPill(state, at, summary) {
            if (state !== 'busy') { if (at) lastAt = at; else at = lastAt; if (summary) lastSummary = summary; else summary = lastSummary; }
            pill.classList.toggle('is-busy', state === 'busy');
            pill.classList.toggle('is-err', state === 'err');
            if (state === 'busy') pillTime.textContent = 'menyinkron…';
            else if (state === 'err') pillTime.textContent = 'sinkron gagal' + (at ? ' · ' + hhmm(at) : '');
            else pillTime.textContent = at ? 'tersinkron ' + hhmm(at) : 'menunggu sinkron…';
            pill.title = (summary ? 'Sinkron terakhir: ' + summary + '\n' : '') + 'Klik untuk membuka pengaturan Cloudbeds';
        }
        setPill(<?php echo json_encode(empty($cbLast['at']) ? 'ok' : (!empty($cbLast['ok']) ? 'ok' : 'err')); ?>, <?php echo json_encode($cbLast['at'] ?? ''); ?>, <?php echo json_encode($cbLast['summary'] ?? ''); ?>);

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
                    setPill(d.last_ok === false ? 'err' : 'ok', d.last || '', d.summary || '');
                    if (d.skipped) return;
                    var items = [];
                    if (d.created) items.push(['g', d.created, 'booking baru masuk']);
                    if (d.cancelled) items.push(['r', d.cancelled, 'booking dibatalkan']);
                    if (d.blocked) items.push(['a', d.blocked, 'kamar diblok']);
                    if (d.unblocked) items.push(['b', d.unblocked, 'blok kamar dicabut']);
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
                    document.getElementById('cbAutoToastTime').textContent = 'Disinkron pukul ' + hhmm(d.last) + ' · muat ulang untuk melihatnya';
                    document.getElementById('cbAutoToast').classList.add('show');
                })
                .catch(function() { running = false; setPill('err'); });
        }
        if (document.readyState === 'complete') setTimeout(run, 300);
        else window.addEventListener('load', function() { setTimeout(run, 300); });
        // Selama halaman terbuka: cek Cloudbeds tiap menit (server tetap membatasi); berhenti saat tab disembunyikan
        setInterval(function() { if (!document.hidden) run(); }, 60000);
        document.addEventListener('visibilitychange', function() { if (!document.hidden) run(); });
    })();
</script>
<?php endif; ?>
