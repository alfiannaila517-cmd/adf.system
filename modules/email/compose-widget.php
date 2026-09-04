<?php
/**
 * Gmail-style floating "compose" button + slide-up panel.
 * Include this from modules/email/*.php pages (after $emailConfig / BASE_URL are available).
 */
?>
<button id="emFabCompose" title="Tulis Email"
        style="position:fixed;right:24px;bottom:24px;width:56px;height:56px;border-radius:50%;
               background:#1e3a8a;color:#fff;border:none;box-shadow:0 4px 14px rgba(30,58,138,0.4);
               font-size:22px;cursor:pointer;z-index:9995;">&#9998;</button>

<div id="emFabPanel" style="display:none;position:fixed;right:24px;bottom:92px;width:340px;max-width:90vw;
     background:#fff;border:1px solid #dbe4ee;border-radius:10px 10px 0 0;box-shadow:0 8px 30px rgba(0,0,0,0.2);z-index:9995;overflow:hidden;">
    <div style="background:#1e3a8a;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;">
        <span style="color:#fff !important;font-size:0.9rem;font-weight:600;">Pesan Baru</span>
        <span id="emFabClose" style="color:#fff !important;cursor:pointer;font-size:1.1rem;line-height:1;">&times;</span>
    </div>
    <div style="padding:12px;">
        <div id="emFabMsg" style="display:none;padding:8px 10px;border-radius:6px;margin-bottom:10px;font-size:0.82rem;"></div>
        <input id="emFabTo" type="email" placeholder="Kepada" style="width:100%;padding:8px;margin-bottom:8px;border:1px solid #dbe4ee;border-radius:6px;font-size:0.85rem;">
        <input id="emFabSubject" type="text" placeholder="Subjek" style="width:100%;padding:8px;margin-bottom:8px;border:1px solid #dbe4ee;border-radius:6px;font-size:0.85rem;">
        <textarea id="emFabBody" placeholder="Tulis pesan..." style="width:100%;min-height:140px;padding:8px;border:1px solid #dbe4ee;border-radius:6px;font-size:0.85rem;resize:vertical;"></textarea>
        <button id="emFabSend" style="margin-top:10px;width:100%;padding:9px;background:#1e3a8a;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;font-size:0.85rem;">Kirim</button>
    </div>
</div>

<script>
(function () {
    const fab = document.getElementById('emFabCompose');
    const panel = document.getElementById('emFabPanel');
    const closeBtn = document.getElementById('emFabClose');
    const sendBtn = document.getElementById('emFabSend');
    const msgBox = document.getElementById('emFabMsg');

    fab.addEventListener('click', () => {
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    });
    closeBtn.addEventListener('click', () => { panel.style.display = 'none'; });

    sendBtn.addEventListener('click', async () => {
        const to = document.getElementById('emFabTo').value.trim();
        const subject = document.getElementById('emFabSubject').value.trim();
        const body = document.getElementById('emFabBody').value;

        msgBox.style.display = 'none';
        sendBtn.disabled = true;
        sendBtn.textContent = 'Mengirim...';

        try {
            const res = await fetch('<?php echo BASE_URL; ?>/modules/email/send-ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'to=' + encodeURIComponent(to) + '&subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body)
            });
            const data = await res.json();
            msgBox.style.display = 'block';
            msgBox.textContent = data.message;
            msgBox.style.background = data.success ? '#f0fdf4' : '#fef2f2';
            msgBox.style.color = data.success ? '#15803d' : '#b91c1c';
            msgBox.style.border = '1px solid ' + (data.success ? '#bbf7d0' : '#fecaca');

            if (data.success) {
                document.getElementById('emFabTo').value = '';
                document.getElementById('emFabSubject').value = '';
                document.getElementById('emFabBody').value = '';
                setTimeout(() => { panel.style.display = 'none'; msgBox.style.display = 'none'; }, 1800);
            }
        } catch (e) {
            msgBox.style.display = 'block';
            msgBox.textContent = 'Gagal mengirim: ' + e.message;
            msgBox.style.background = '#fef2f2';
            msgBox.style.color = '#b91c1c';
        } finally {
            sendBtn.disabled = false;
            sendBtn.textContent = 'Kirim';
        }
    });
})();
</script>
