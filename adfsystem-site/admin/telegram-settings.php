<?php
/**
 * Pengaturan Notifikasi Telegram (khusus admin).
 * Token bot disimpan di folder home hosting dan tidak pernah ditampilkan ulang.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/telegram.php';
adf_admin_require_role('admin');

$msg = '';
$err = '';
$cfg = adf_tg_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adf_admin_csrf_check($_POST['csrf'] ?? null)) {
        $err = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        $action = $_POST['action'] ?? 'save';
        if ($action === 'save') {
            $token = trim((string) ($_POST['tg_bot_key'] ?? '')) ?: ($cfg['bot_token'] ?? '');
            $chatId = preg_replace('/\s+/', '', (string) ($_POST['tg_chat'] ?? ''));
            $me = adf_tg_api($token, 'getMe');
            if (empty($me['ok'])) {
                $err = 'Bot token tidak valid. Salin ulang dari @BotFather.';
            } elseif ($chatId !== '' && !preg_match('/^-?\d{5,20}$/', $chatId)) {
                $err = 'Chat ID harus berupa ANGKA (mis. 123456789), bukan email. Kosongkan saja lalu pakai tombol Deteksi Chat ID.';
            } elseif (!adf_tg_save_config($token, $chatId)) {
                $err = 'Gagal menyimpan. Folder home hosting tidak bisa ditulis.';
            } else {
                $msg = 'Tersimpan. Bot: @' . ($me['result']['username'] ?? '?') . ($chatId === '' ? ' — sekarang kirim /start ke bot lalu klik "Deteksi Chat ID".' : '');
            }
        } elseif ($action === 'detect' && $cfg) {
            $botNameNow = adf_tg_api($cfg['bot_token'], 'getMe', [], 4)['result']['username'] ?? 'bot';
            // Ambil pesan terakhir yang dikirim ke bot (setelah Anda mengirim /start).
            $upd = adf_tg_api($cfg['bot_token'], 'getUpdates', ['limit' => 20, 'timeout' => 0]);
            if (!empty($upd['description']) && stripos($upd['description'], 'webhook') !== false) {
                // Bot masih punya webhook lama → getUpdates ditolak. Hapus webhook lalu coba lagi.
                adf_tg_api($cfg['bot_token'], 'deleteWebhook');
                $upd = adf_tg_api($cfg['bot_token'], 'getUpdates', ['limit' => 20, 'timeout' => 0]);
            }
            $found = null;
            foreach (array_reverse($upd['result'] ?? []) as $u) {
                $chat = $u['message']['chat'] ?? $u['my_chat_member']['chat'] ?? null;
                if ($chat && isset($chat['id'])) {
                    $found = $chat;
                    break;
                }
            }
            if ($upd === null) {
                $err = 'Server tidak bisa menghubungi Telegram (koneksi diblokir / timeout). Isi Chat ID manual lalu Simpan.';
            } elseif (empty($upd['ok'])) {
                $err = 'Telegram menolak: ' . ($upd['description'] ?? 'tidak diketahui') . '. Isi Chat ID manual lalu Simpan.';
            } elseif (!$found) {
                $err = 'Belum ada pesan untuk bot ini. Buka @' . ($botNameNow ?? 'bot') . ' → kirim /start (atau ketik pesan apa saja) → klik Deteksi lagi. Atau isi Chat ID manual.';
            } else {
                adf_tg_save_config($cfg['bot_token'], (string) $found['id']);
                $who = trim(($found['first_name'] ?? '') . ' ' . ($found['last_name'] ?? '')) ?: ($found['title'] ?? $found['username'] ?? '');
                $msg = 'Chat ID ditemukan: ' . $found['id'] . ($who !== '' ? ' (' . $who . ')' : '') . '. Klik "Kirim Tes" untuk mencoba.';
            }
        } elseif ($action === 'test' && $cfg) {
            $ok = adf_tg_send("✅ <b>Notifikasi ADF Store aktif</b>\nAnda akan menerima pesan di sini setiap ada transaksi masuk.\n🕒 " . date('d M Y H:i'));
            $ok ? $msg = 'Pesan tes terkirim. Cek Telegram Anda.' : $err = 'Gagal mengirim. Pastikan Chat ID benar dan Anda sudah menekan /start di bot.';
        } elseif ($action === 'disable') {
            @unlink(adf_tg_config_path());
            $msg = 'Notifikasi Telegram dimatikan.';
        }
        $cfg = adf_tg_config();
    }
}

$botName = '';
if ($cfg) {
    $me = adf_tg_api($cfg['bot_token'], 'getMe', [], 4);
    $botName = $me['result']['username'] ?? '';
}
$csrf = adf_admin_csrf_token();
$adminPageTitle = 'Notifikasi Telegram';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="container admin-container">
    <h1>Notifikasi Telegram</h1>
    <p class="admin-lead">Terima pesan di HP setiap ada <strong>transaksi masuk (lunas)</strong>: tagihan bulanan, tagihan manual, dan checkout website.</p>

    <?php if ($err): ?><div class="admin-alert admin-alert-error"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="admin-alert admin-alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

    <p class="admin-lead">
        Status:
        <?php if ($cfg && $cfg['chat_id'] !== ''): ?>
            <strong style="color:#4ade80;">Aktif</strong> · bot <?php echo $botName ? '<a href="https://t.me/' . htmlspecialchars($botName) . '" target="_blank" rel="noopener">@' . htmlspecialchars($botName) . '</a>' : '-'; ?> · chat <?php echo htmlspecialchars($cfg['chat_id']); ?>
        <?php elseif ($cfg): ?>
            <strong style="color:#fbbf24;">Token tersimpan, Chat ID belum ada</strong>
            <?php if ($botName): ?> — buka <a href="https://t.me/<?php echo htmlspecialchars($botName); ?>" target="_blank" rel="noopener">@<?php echo htmlspecialchars($botName); ?></a>, kirim <code>/start</code>, lalu klik Deteksi Chat ID.<?php endif; ?>
        <?php else: ?>
            <strong>Belum aktif</strong>
        <?php endif; ?>
    </p>

    <form method="post" class="admin-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <label>Bot Token (dari @BotFather) <?php echo $cfg ? '<small>(kosongkan untuk tetap memakai yang lama)</small>' : ''; ?>
            <input type="password" name="tg_bot_key" autocomplete="new-password" data-lpignore="true" <?php echo $cfg ? '' : 'required'; ?> placeholder="<?php echo $cfg ? '•••••••• tersimpan' : '1234567890:AA…'; ?>">
        </label>
        <label>Chat ID <small>(boleh dikosongkan, pakai tombol Deteksi)</small>
            <input type="text" name="tg_chat" inputmode="numeric" autocomplete="off" data-lpignore="true" value="<?php echo htmlspecialchars($cfg['chat_id'] ?? ''); ?>" placeholder="angka, mis. 123456789">
        </label>
        <div class="admin-form-actions">
            <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
    </form>

    <?php if ($cfg): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
            <form method="post"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="detect"><button type="submit" class="btn btn-outline btn-sm">Deteksi Chat ID</button></form>
            <form method="post"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="test"><button type="submit" class="btn btn-outline btn-sm">Kirim Tes</button></form>
            <form method="post" onsubmit="return confirm('Matikan notifikasi Telegram?');"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="action" value="disable"><button type="submit" class="btn btn-outline btn-sm payment-btn-danger">Matikan</button></form>
        </div>
    <?php endif; ?>

    <h2 class="admin-subheading">Cara membuat bot (±2 menit)</h2>
    <ol class="admin-lead" style="line-height:1.8;">
        <li>Di Telegram, buka <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> → kirim <code>/newbot</code>.</li>
        <li>Isi nama (mis. <em>ADF Notif</em>) dan username yang berakhiran <code>bot</code> (mis. <em>adfnotif_arif_bot</em>).</li>
        <li>Salin <strong>token</strong> yang diberikan BotFather → tempel di atas → Simpan.</li>
        <li>Buka bot Anda, tekan <strong>Start</strong> (<code>/start</code>) → kembali ke sini → <strong>Deteksi Chat ID</strong> → <strong>Kirim Tes</strong>.</li>
    </ol>
</div>
</div>
<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
