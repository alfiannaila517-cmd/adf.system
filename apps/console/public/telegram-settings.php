<?php

/**
 * Notifikasi Telegram (seperti admin adfsystem.store lama): token bot & chat ID disimpan terenkripsi di Console
 * (SecureSettings), deteksi chat ID dari pesan /start, kirim pesan tes. Dipakai untuk peringatan login, kunci sistem,
 * pembayaran masuk, dll. (core/Telegram).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireAdmin();

/** Panggil Bot API Telegram; null bila server tidak bisa terhubung */
$api = function (string $token, string $method, array $params = []): ?array {
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $res = curl_exec($ch);
    return is_string($res) ? json_decode($res, true) : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        flash('Sesi form kedaluwarsa, silakan coba lagi.', 'err');
        redirect('/telegram-settings.php');
    }
    $action = (string)($_POST['action'] ?? 'save');
    $token = trim((string)($_POST['bot_token'] ?? '')) ?: (string)Secrets::get('TELEGRAM_BOT_TOKEN', '');
    try {
        if ($action === 'save') {
            $chat = preg_replace('/\s+/', '', (string)($_POST['chat_id'] ?? ''));
            $bot = $token !== '' ? $api($token, 'getMe') : null;
            if (empty($bot['ok'])) {
                throw new RuntimeException('Bot token tidak valid. Salin ulang dari @BotFather.');
            }
            if ($chat !== '' && !preg_match('/^-?\d{5,20}$/', $chat)) {
                throw new RuntimeException('Chat ID harus berupa angka (mis. 123456789). Kosongkan lalu pakai tombol Deteksi Chat ID.');
            }
            SecureSettings::save(['TELEGRAM_BOT_TOKEN' => $token, 'TELEGRAM_CHAT_ID' => $chat]);
            Auth::audit('telegram.save', '@' . ($bot['result']['username'] ?? '?'));
            flash('Tersimpan. Bot: @' . ($bot['result']['username'] ?? '?') . ($chat === '' ? ' — kirim /start ke bot, lalu klik Deteksi Chat ID.' : ''));
        } elseif ($action === 'detect') {
            $upd = $api($token, 'getUpdates', ['limit' => 20, 'timeout' => 0]);
            if (!empty($upd['description']) && stripos((string)$upd['description'], 'webhook') !== false) {
                $api($token, 'deleteWebhook'); // webhook lama menghalangi getUpdates
                $upd = $api($token, 'getUpdates', ['limit' => 20, 'timeout' => 0]);
            }
            $found = null;
            foreach (array_reverse($upd['result'] ?? []) as $u) {
                $chat = $u['message']['chat'] ?? $u['my_chat_member']['chat'] ?? $u['channel_post']['chat'] ?? null;
                if (isset($chat['id'])) {
                    $found = $chat;
                    break;
                }
            }
            if (!$found) {
                throw new RuntimeException('Belum ada pesan ke bot. Buka bot di Telegram, kirim /start, lalu klik Deteksi lagi.');
            }
            SecureSettings::save(['TELEGRAM_BOT_TOKEN' => $token, 'TELEGRAM_CHAT_ID' => (string)$found['id']]);
            Auth::audit('telegram.detect', (string)$found['id']);
            flash('Chat ID terdeteksi: ' . $found['id'] . ' (' . ($found['title'] ?? trim(($found['first_name'] ?? '') . ' ' . ($found['last_name'] ?? ''))) . ').');
        } elseif ($action === 'test') {
            $ok = Telegram::send("✅ <b>Tes notifikasi ADF Console</b>\nDikirim oleh " . Telegram::esc((string)$me['username']) . ' · ' . date('d M Y H:i'));
            flash($ok ? 'Pesan tes terkirim — cek Telegram.' : 'Gagal mengirim. Periksa token & chat ID.', $ok ? 'ok' : 'err');
        } elseif ($action === 'reset') {
            SecureSettings::save(['TELEGRAM_BOT_TOKEN' => '', 'TELEGRAM_CHAT_ID' => '']);
            Auth::audit('telegram.reset', '');
            flash('Pengaturan Console dihapus — kembali memakai file secrets di server (bila ada).');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('/telegram-settings.php');
}

$token = (string)Secrets::get('TELEGRAM_BOT_TOKEN', '');
$chat = (string)Secrets::get('TELEGRAM_CHAT_ID', '');
$src = SecureSettings::source('TELEGRAM_BOT_TOKEN');
$bot = $token !== '' ? $api($token, 'getMe') : null;

View::start('Notifikasi Telegram', 'telegram', $me);
?>
<div class="pagehead"><div><h1>Notifikasi Telegram</h1><p>Pemberitahuan ke Telegram Anda: login Console, kunci / buka sistem klien, pembayaran masuk, peringatan server.</p></div></div>
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(320px,1fr));align-items:start">
    <form class="card" method="post" autocomplete="off" style="margin:0">
        <?= csrf_field() ?>
        <h2 style="display:flex;justify-content:space-between;gap:8px"><span>Bot</span>
            <?= Telegram::configured() && !empty($bot['ok']) ? '<span class="pill ok">aktif · @' . e($bot['result']['username'] ?? '') . '</span>' : '<span class="pill warn">belum aktif</span>' ?></h2>
        <div class="field"><label>Bot token <?= $token !== '' ? '<span class="pill ok">tersimpan ••••' . e(substr($token, -4)) . ($src === 'file' ? ' (file server)' : '') . '</span>' : '' ?></label>
            <input type="password" name="bot_token" placeholder="<?= $token !== '' ? 'kosongkan = tetap' : '123456:ABC-… dari @BotFather' ?>" autocomplete="new-password"></div>
        <div class="field"><label>Chat ID</label><input name="chat_id" value="<?= e($chat) ?>" placeholder="angka, mis. 123456789 — atau pakai Deteksi"></div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <button class="btn sm" name="action" value="save">Simpan</button>
            <?php if ($token !== ''): ?>
                <button class="btn ghost sm" name="action" value="detect">Deteksi Chat ID</button>
                <button class="btn ghost sm" name="action" value="test">Kirim tes</button>
            <?php endif; ?>
            <?php if ($src === 'console'): ?><button class="btn ghost sm" name="action" value="reset" onclick="return confirm('Hapus pengaturan Telegram di Console?')">Hapus</button><?php endif; ?>
        </div>
    </form>
    <div class="card" style="margin:0">
        <h2>Cara mengatur</h2>
        <ol class="muted" style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.7">
            <li>Di Telegram buka <b>@BotFather</b> → <code>/newbot</code> → salin <b>token</b>.</li>
            <li>Tempel token di kolom Bot token → <b>Simpan</b>.</li>
            <li>Buka bot Anda di Telegram → kirim <code>/start</code>.</li>
            <li>Kembali ke sini → <b>Deteksi Chat ID</b> → <b>Kirim tes</b>.</li>
        </ol>
        <p class="muted" style="margin:10px 0 0;font-size:11.5px">Token disimpan terenkripsi di Console dan tidak pernah ditampilkan ulang.</p>
    </div>
</div>
<?php View::end();
