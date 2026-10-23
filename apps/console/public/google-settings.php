<?php

/**
 * Login Google (seperti admin adfsystem.store lama): Client ID & Secret OAuth disimpan terenkripsi di Console
 * (SecureSettings); menampilkan Redirect URI yang harus didaftarkan di Google Cloud.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        flash('Sesi form kedaluwarsa, silakan coba lagi.', 'err');
        redirect('/google-settings.php');
    }
    try {
        if (($_POST['action'] ?? '') === 'reset') {
            SecureSettings::save(['GOOGLE_CLIENT_ID' => '', 'GOOGLE_CLIENT_SECRET' => '']);
            flash('Pengaturan Console dihapus — kembali memakai file secrets di server (bila ada).');
        } else {
            $id = trim((string)($_POST['client_id'] ?? ''));
            if ($id !== '' && !str_ends_with($id, '.apps.googleusercontent.com')) {
                throw new RuntimeException('Client ID biasanya berakhiran .apps.googleusercontent.com — salin ulang dari Google Cloud.');
            }
            $secret = trim((string)($_POST['client_secret'] ?? '')) ?: (string)Secrets::get('GOOGLE_CLIENT_SECRET', '');
            SecureSettings::save(['GOOGLE_CLIENT_ID' => $id ?: (string)Secrets::get('GOOGLE_CLIENT_ID', ''), 'GOOGLE_CLIENT_SECRET' => $secret]);
            flash('Login Google disimpan.');
        }
        Auth::audit('google.settings', (string)($_POST['action'] ?? 'save'));
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('/google-settings.php');
}

$cfg = Google::config();
$id = (string)Secrets::get('GOOGLE_CLIENT_ID', '');
$secret = (string)Secrets::get('GOOGLE_CLIENT_SECRET', '');
$src = SecureSettings::source('GOOGLE_CLIENT_ID');

View::start('Login Google', 'google', $me);
?>
<div class="pagehead"><div><h1>Login Google</h1><p>Tombol "Masuk dengan Google" di halaman login Console. Hanya email Google yang sama dengan email user Console yang bisa masuk.</p></div></div>
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(320px,1fr));align-items:start">
    <form class="card" method="post" autocomplete="off" style="margin:0">
        <?= csrf_field() ?>
        <h2 style="display:flex;justify-content:space-between;gap:8px"><span>OAuth Client</span><?= $cfg ? '<span class="pill ok">aktif' . ($src === 'file' ? ' · file server' : '') . '</span>' : '<span class="pill warn">belum diatur</span>' ?></h2>
        <div class="field"><label>Client ID</label><input name="client_id" value="<?= e($id) ?>" placeholder="….apps.googleusercontent.com"></div>
        <div class="field"><label>Client Secret <?= $secret !== '' ? '<span class="pill ok">tersimpan ••••' . e(substr($secret, -4)) . '</span>' : '' ?></label>
            <input type="password" name="client_secret" placeholder="<?= $secret !== '' ? 'kosongkan = tetap' : 'GOCSPX-…' ?>" autocomplete="new-password"></div>
        <div style="display:flex;gap:6px">
            <button class="btn sm">Simpan</button>
            <?php if ($src === 'console'): ?><button class="btn ghost sm" name="action" value="reset" onclick="return confirm('Hapus pengaturan Google di Console?')">Hapus</button><?php endif; ?>
        </div>
    </form>
    <div class="card" style="margin:0">
        <h2>Redirect URI</h2>
        <p class="muted" style="margin:0 0 8px;font-size:12.5px">Daftarkan alamat ini di Google Cloud → APIs &amp; Services → Credentials → client OAuth → <b>Authorized redirect URIs</b>:</p>
        <code style="display:block;padding:8px 10px;border-radius:8px;background:var(--bg2,#0f131b);word-break:break-all"><?= e(Google::redirectUri()) ?></code>
        <p class="muted" style="margin:10px 0 0;font-size:11.5px">Client Secret disimpan terenkripsi dan tidak pernah ditampilkan ulang.</p>
    </div>
</div>
<?php View::end();
