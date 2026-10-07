<?php
if (!defined('APP_ACCESS')) { http_response_code(403); exit; }
/**
 * Sisipkan di halaman Front Desk (Kalender, Reservasi, Dashboard) sebelum footer:
 * memicu sinkron Cloudbeds di latar belakang saat halaman dibuka, lalu memberi tahu bila ada perubahan.
 * Tidak menampilkan apa pun bila Cloudbeds belum diatur / sinkron otomatis mati.
 */
try {
    $cbAutoRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_auto_sync'");
    $cbKeyRow = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbeds_api_key'");
} catch (\Throwable $e) {
    $cbAutoRow = $cbKeyRow = null;
}
if (($cbAutoRow['setting_value'] ?? '0') === '1' && !empty($cbKeyRow['setting_value'])):
?>
<div id="cbAutoToast" role="status" aria-live="polite" style="display:none;position:fixed;right:18px;bottom:18px;z-index:10060;max-width:340px;padding:12px 14px;border-radius:14px;background:#0f172a;color:#fff;box-shadow:0 18px 40px -14px rgba(15,23,42,.55);font-size:.78rem;line-height:1.45">
    <div style="display:flex;align-items:flex-start;gap:10px">
        <span style="flex-shrink:0;width:26px;height:26px;border-radius:8px;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center">🔗</span>
        <div style="flex:1;min-width:0">
            <b style="display:block;font-size:.8rem">Update dari Cloudbeds</b>
            <span id="cbAutoToastText"></span>
            <div style="margin-top:8px;display:flex;gap:6px">
                <button type="button" onclick="location.reload()" style="border:0;border-radius:8px;padding:5px 12px;background:#22c55e;color:#fff;font-weight:700;font-size:.72rem;cursor:pointer">Muat ulang</button>
                <button type="button" onclick="document.getElementById('cbAutoToast').style.display='none'" style="border:1px solid rgba(255,255,255,.25);border-radius:8px;padding:5px 10px;background:transparent;color:#fff;font-size:.72rem;cursor:pointer">Nanti</button>
            </div>
        </div>
    </div>
</div>
<script>
    (function() {
        // Sinkron Cloudbeds di latar belakang setelah halaman tampil (server membatasi maks. sekali per 2 menit)
        function run() {
            fetch(<?php echo json_encode(BASE_URL . '/api/cloudbeds-sync-now.php'); ?>, { method: 'POST', credentials: 'include' })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (!d || !d.ok || d.skipped) return;
                    var parts = [];
                    if (d.created) parts.push(d.created + ' booking baru');
                    if (d.cancelled) parts.push(d.cancelled + ' dibatalkan');
                    if (d.blocked) parts.push(d.blocked + ' kamar diblok');
                    if (d.unblocked) parts.push(d.unblocked + ' blok dicabut');
                    if (!parts.length) return;
                    document.getElementById('cbAutoToastText').textContent = parts.join(', ') + '. Muat ulang untuk melihatnya.';
                    document.getElementById('cbAutoToast').style.display = 'block';
                })
                .catch(function() {});
        }
        if (document.readyState === 'complete') setTimeout(run, 300);
        else window.addEventListener('load', function() { setTimeout(run, 300); });
    })();
</script>
<?php endif; ?>
