<?php
require_once __DIR__ . '/content-store.php';
$footerContact = adf_load_content()['contact'];
$footerEmail = $footerContact['email'] ?: CONTACT_EMAIL;
$footerWhatsapp = $footerContact['whatsapp'] ?: CONTACT_WHATSAPP;
?>
<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <div>
                <h4>ADF<span style="color:#60a5fa">System</span></h4>
                <p style="max-width:320px;color:#94a3b8;font-size:0.9rem;">
                    <?php echo htmlspecialchars(COMPANY_LEGAL_NAME); ?> — <?php echo htmlspecialchars(COMPANY_DESC_SHORT); ?>.
                    Menyediakan sistem manajemen hotel, travel, dan multi-bisnis dalam satu platform.
                </p>
            </div>
            <div>
                <h4>Perusahaan</h4>
                <a href="tentang.php">Tentang Kami</a>
                <a href="layanan.php">Layanan</a>
                <a href="harga.php">Harga</a>
                <a href="kontak.php">Kontak</a>
            </div>
            <div>
                <h4>Legal</h4>
                <a href="syarat-ketentuan.php">Syarat &amp; Ketentuan</a>
                <a href="kebijakan-privasi.php">Kebijakan Privasi</a>
                <a href="kebijakan-refund.php">Kebijakan Refund</a>
            </div>
        </div>
        <div class="footer-bottom">
            &copy; <?php echo SITE_YEAR; ?> <?php echo htmlspecialchars(COMPANY_LEGAL_NAME); ?>. Seluruh hak cipta dilindungi.
            &nbsp;|&nbsp; <a href="mailto:<?php echo htmlspecialchars($footerEmail); ?>" style="color:#94a3b8;"><?php echo htmlspecialchars($footerEmail); ?></a>
        </div>
    </div>
</footer>

<?php if ($footerWhatsapp !== ''): $footerWaNumber = preg_replace('/[^0-9]/', '', $footerWhatsapp); ?>
<div class="adf-chat-widget" id="adfChatWidget">
    <div class="adf-chat-panel" id="adfChatPanel">
        <div class="adf-chat-header">
            <div class="adf-chat-header-info">
                <span class="adf-chat-avatar">⚙️</span>
                <div>
                    <div class="adf-chat-title"><?php echo htmlspecialchars(SITE_NAME); ?></div>
                    <div class="adf-chat-status">Admin siap membalas via WhatsApp</div>
                </div>
            </div>
            <button type="button" class="adf-chat-close" onclick="adfChatToggle(false)">&times;</button>
        </div>
        <div class="adf-chat-body" id="adfChatBody">
            <div class="adf-chat-bubble adf-chat-bubble-in">
                Halo! 👋 Ada yang bisa kami bantu seputar sistem manajemen bisnis Anda? Tulis pesan Anda di bawah ini.
            </div>
        </div>
        <div class="adf-chat-footer">
            <input type="text" id="adfChatInput" class="adf-chat-input" placeholder="Tulis pesan Anda..." maxlength="500">
            <button type="button" class="adf-chat-send" id="adfChatSendBtn" onclick="adfChatSend()" aria-label="Kirim">&#10148;</button>
        </div>
    </div>
    <button type="button" class="adf-chat-fab" id="adfChatFab" onclick="adfChatToggle()" aria-label="Chat WhatsApp">
        <svg class="adf-chat-fab-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path fill="#fff" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z" />
        </svg>
    </button>
</div>
<script>
    (function() {
        var waNumber = <?php echo json_encode($footerWaNumber); ?>;
        var panel = document.getElementById('adfChatPanel');
        var widget = document.getElementById('adfChatWidget');
        var body = document.getElementById('adfChatBody');
        var input = document.getElementById('adfChatInput');

        window.adfChatToggle = function(forceOpen) {
            var open = typeof forceOpen === 'boolean' ? forceOpen : !widget.classList.contains('adf-open');
            widget.classList.toggle('adf-open', open);
            if (open) input.focus();
        };

        window.adfChatSend = function() {
            var msg = input.value.trim();
            if (!msg) return;

            var bubble = document.createElement('div');
            bubble.className = 'adf-chat-bubble adf-chat-bubble-out';
            bubble.textContent = msg;
            body.appendChild(bubble);
            body.scrollTop = body.scrollHeight;
            input.value = '';

            window.open('https://wa.me/' + waNumber + '?text=' + encodeURIComponent(msg), '_blank');
        };

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                adfChatSend();
            }
        });
    })();
</script>
<?php endif; ?>
</body>
</html>

