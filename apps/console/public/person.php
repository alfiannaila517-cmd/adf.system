<?php

/** Satu orang: data akun, password login, status, dan semua bisnis yang bisa dibuka. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();
$db = Biz::db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isAdmin = $me['role'] === 'admin';
$load = function () use ($db, $id): ?array {
    $st = $db->prepare('SELECT * FROM people WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
};
$person = $load();
if (!$person) {
    flash('Orang tidak ditemukan.', 'err');
    redirect('/clients.php');
}
$canLogin = $person['legacy_user_id'] && Db::legacyMaster() !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!csrf_ok() || !$isAdmin) {
            throw new RuntimeException(!$isAdmin ? 'Hanya admin yang bisa mengubah akun.' : 'Sesi form kedaluwarsa, silakan coba lagi.');
        }
        if ($action === 'account') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $username = strtolower(trim((string)($_POST['username'] ?? '')));
            $phone = trim((string)($_POST['phone'] ?? ''));
            if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Nama dan email wajib diisi dengan benar.');
            }
            if ($username !== '' && !preg_match('/^[a-z0-9_.]{3,50}$/', $username)) {
                throw new RuntimeException('Username: 3–50 huruf kecil, angka, titik, atau garis bawah.');
            }
            $dup = $db->prepare('SELECT name FROM people WHERE (email = ? OR (username = ? AND ? <> \'\')) AND id <> ?');
            $dup->execute([$email, $username, $username, $id]);
            if ($other = $dup->fetchColumn()) {
                throw new RuntimeException("Email atau username sudah dipakai {$other}.");
            }
            $db->prepare('UPDATE people SET name = ?, email = ?, username = ?, phone = ? WHERE id = ?')
                ->execute([mb_substr($name, 0, 120), $email, $username ?: null, $phone ?: null, $id]);
            $msg = Legacy::syncAccount($id);
            Auth::audit('person.update', "#{$id} {$name}");
            flash('Data akun disimpan.' . ($msg ? " {$msg}" : ''));
        } elseif ($action === 'password') {
            $new = ($_POST['mode'] ?? '') === 'auto' ? Legacy::generatePassword() : (string)($_POST['password'] ?? '');
            if (mb_strlen($new) < 8) {
                throw new RuntimeException('Password minimal 8 karakter.');
            }
            Legacy::setPassword($id, $new);
            Auth::audit('person.password', "#{$id} {$person['name']}"); // password tidak pernah dicatat
            $_SESSION['shown_password'] = ['id' => $id, 'pw' => $new];
            flash('Password baru aktif. Berikan ke orangnya sekarang — setelah halaman ini ditutup, password tidak bisa dilihat lagi.');
        } elseif ($action === 'status') {
            $status = $person['status'] === 'disabled' ? 'active' : 'disabled';
            $db->prepare('UPDATE people SET status = ? WHERE id = ?')->execute([$status, $id]);
            Legacy::syncAccount($id);
            Auth::audit('person.status', "#{$id} {$person['name']} → {$status}");
            flash($status === 'disabled' ? 'Akun dinonaktifkan — orang ini tidak bisa login lagi.' : 'Akun diaktifkan kembali.');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect("/person.php?id={$id}");
}

$person = $load();
// Password baru hanya tampil sekali
$shown = ($_SESSION['shown_password']['id'] ?? 0) === $id ? $_SESSION['shown_password']['pw'] : null;
unset($_SESSION['shown_password']);

$st = $db->prepare('SELECT m.*, b.name AS business_name, b.slug, b.client_id, b.legacy_business_id, c.name AS client_name, r.name AS role_name, r.is_owner
    FROM memberships m JOIN businesses b ON b.id = m.business_id JOIN clients c ON c.id = b.client_id JOIN roles r ON r.id = m.role_id
    WHERE m.person_id = ? ORDER BY c.name, b.name');
$st->execute([$id]);
$access = $st->fetchAll();
$st = $db->prepare('SELECT c.id, c.name FROM client_owners co JOIN clients c ON c.id = co.client_id WHERE co.person_id = ?');
$st->execute([$id]);
$ownerOf = $st->fetchAll();
$dis = $isAdmin ? '' : 'disabled';

View::start($person['name'], 'clients', $me);
?>
<div class="crumb"><a href="/clients.php">Client & Bisnis</a> ›<?php foreach ($ownerOf ?: array_slice($access, 0, 1) as $c): ?> <a href="/client.php?id=<?= (int)($c['client_id'] ?? $c['id']) ?>&tab=orang"><?= e($c['client_name'] ?? $c['name']) ?></a> ›<?php endforeach; ?></div>
<h1>👤 <?= e($person['name']) ?>
    <?= $person['status'] === 'disabled' ? '<span class="pill">nonaktif</span>' : ($person['status'] === 'invited' && !$person['legacy_user_id'] ? '<span class="pill warn">belum punya login</span>' : '<span class="pill ok">aktif</span>') ?></h1>
<p class="muted" style="margin:0 0 14px"><?= $person['username'] ? 'username <code>' . e($person['username']) . '</code> · ' : '' ?><?= e($person['email']) ?></p>

<?php if ($shown): ?>
    <div class="card" style="border-color:var(--gold2);max-width:760px">
        <h2>Password baru untuk <?= e($person['name']) ?></h2>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <code id="pw" style="font-size:22px;letter-spacing:1px;padding:8px 14px"><?= e($shown) ?></code>
            <button class="btn sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('pw').textContent);this.textContent='Tersalin ✓'">Salin</button>
            <?php if ($person['phone']): $wa = preg_replace('/\D/', '', (string)$person['phone']); $wa = str_starts_with($wa, '0') ? '62' . substr($wa, 1) : $wa; ?>
                <a class="btn ghost sm" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode("Login sistem: username {$person['username']}, password {$shown} — mohon ganti setelah masuk.") ?>">Kirim lewat WhatsApp</a>
            <?php endif; ?>
        </div>
        <p class="muted" style="margin:10px 0 0;font-size:12px">Hanya tampil sekali. Sistem menyimpan password dalam bentuk acak satu arah, jadi tidak bisa dilihat lagi — bila lupa, buat yang baru.</p>
    </div>
<?php endif; ?>

<div class="row2" style="align-items:start">
    <div class="card" style="margin:0">
        <h2>Data akun</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="account"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="field"><label>Nama lengkap</label><input name="name" value="<?= e($person['name']) ?>" required <?= $dis ?>></div>
            <div class="field"><label>Email</label><input name="email" type="email" value="<?= e($person['email']) ?>" required <?= $dis ?>></div>
            <div class="field"><label>Username login</label><input name="username" value="<?= e($person['username']) ?>" pattern="[a-z0-9_.]{3,50}" <?= $dis ?>></div>
            <div class="field"><label>WhatsApp / telepon</label><input name="phone" value="<?= e($person['phone']) ?>" placeholder="0812…" <?= $dis ?>></div>
            <?php if ($isAdmin): ?><button class="btn">Simpan data akun</button><?php endif; ?>
        </form>
    </div>

    <div>
        <div class="card" style="margin:0 0 14px">
            <h2>Password login</h2>
            <?php if (!$canLogin): ?>
                <p class="muted" style="margin:0;font-size:13px">Orang ini belum punya akun login di sistem. Akun dibuat otomatis saat ditambahkan ke bisnis yang terhubung (isi username & password awal).</p>
            <?php elseif ($isAdmin): ?>
                <form method="post" style="margin-bottom:10px" onsubmit="return confirm('Buat password baru untuk <?= e($person['name']) ?>? Password lama langsung tidak berlaku.')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="mode" value="auto">
                    <button class="btn">🔑 Buat password baru otomatis</button>
                </form>
                <details>
                    <summary style="cursor:pointer;font-size:13px" class="muted">atau ketik password sendiri</summary>
                    <form method="post" style="margin-top:10px;display:flex;gap:8px" onsubmit="return confirm('Ganti password <?= e($person['name']) ?>?')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= $id ?>">
                        <input name="password" type="text" minlength="8" placeholder="min. 8 karakter" autocomplete="off" required>
                        <button class="btn ghost">Simpan</button>
                    </form>
                </details>
                <p class="muted" style="margin:10px 0 0;font-size:12px">Password lama tidak bisa dilihat (disimpan acak satu arah demi keamanan). Buat yang baru bila orangnya lupa.</p>
            <?php endif; ?>
        </div>

        <div class="card" style="margin:0">
            <h2>Status akun</h2>
            <p class="muted" style="margin:0 0 10px;font-size:13px"><?= $person['status'] === 'disabled' ? 'Nonaktif — tidak bisa login ke bisnis mana pun.' : 'Aktif — bisa login ke bisnis yang diizinkan di bawah.' ?></p>
            <?php if ($isAdmin): ?>
                <form method="post" onsubmit="return confirm('<?= $person['status'] === 'disabled' ? 'Aktifkan kembali akun ini?' : 'Nonaktifkan akun ini? Orangnya langsung tidak bisa login.' ?>')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $id ?>">
                    <button class="btn <?= $person['status'] === 'disabled' ? '' : 'danger' ?> sm"><?= $person['status'] === 'disabled' ? 'Aktifkan akun' : 'Nonaktifkan akun' ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top:14px">
    <h2>Bisnis yang bisa dibuka</h2>
    <table class="stack">
        <thead><tr><th>Bisnis</th><th>Peran</th><th>Bisa masuk lewat</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($ownerOf as $c): ?>
                <tr><td><b><?= e($c['name']) ?></b> <span class="muted" style="font-size:12px">— semua bisnis grup</span></td><td><span class="pill gold">👑 Owner grup</span></td><td><span class="pill">Sistem</span> <span class="pill ok">Owner Login</span></td>
                    <td style="text-align:right"><a class="btn ghost sm" href="/client.php?id=<?= (int)$c['id'] ?>&tab=orang">Atur</a></td></tr>
            <?php endforeach; ?>
            <?php foreach ($access as $a): ?>
                <tr>
                    <td><b><?= e($a['business_name']) ?></b> <span class="muted" style="font-size:12px">· <?= e($a['client_name']) ?></span><?= $a['legacy_business_id'] ? ' <span class="pill" title="terhubung ke sistem yang berjalan">🔗</span>' : '' ?></td>
                    <td><span class="pill <?= $a['is_owner'] ? 'gold' : '' ?>"><?= e($a['role_name']) ?></span></td>
                    <td><span class="pill">Sistem</span><?= $a['portal_owner'] ? ' <span class="pill ok">Owner Login</span>' : '' ?></td>
                    <td style="text-align:right;white-space:nowrap">
                        <?php if ($isAdmin): ?><a class="btn ghost sm" href="/business.php?id=<?= (int)$a['business_id'] ?>&tab=orang&edit=<?= $id ?>">Ubah peran</a><?php endif; ?>
                        <a class="btn ghost sm" href="/business.php?id=<?= (int)$a['business_id'] ?>&tab=orang&person=<?= $id ?>#izin">Izin</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$access && !$ownerOf): ?><tr><td colspan="4" class="muted">Belum punya akses ke bisnis mana pun.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <p class="muted" style="margin:10px 0 0;font-size:12px">Untuk menambah orang ini ke bisnis lain: buka bisnisnya → Orang & Akses → + Tambah orang, isi email yang sama.</p>
</div>
<?php View::end();
