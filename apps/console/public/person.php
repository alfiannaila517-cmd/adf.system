<?php

/**
 * Edit user — tiga tab:
 *   akun   : nama, email, username, WA, password baru, aktif/nonaktif
 *   akses  : bisnis yang boleh dibuka + peran + Owner Login
 *   menu   : menu sidebar yang tampil untuk user ini, per bisnis (level per menu)
 * Semua perubahan pada bisnis yang terhubung langsung ditulis ke sistem lama (Legacy).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();
$db = Biz::db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? 'akun');
$isAdmin = $me['role'] === 'admin';
$load = function () use ($db, $id): ?array {
    $st = $db->prepare('SELECT * FROM people WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
};
$person = $load();
if (!$person) {
    flash('User tidak ditemukan.', 'err');
    redirect('/people.php');
}

/** Tulis satu bisnis ke sistem lama bila terhubung; kembalikan ringkasan untuk pesan. */
$sync = function (int $bizId, array $passwords = [], bool $modules = false): string {
    $b = Biz::business($bizId);
    if (!$b || !Legacy::linked($b)) {
        return '';
    }
    $log = array_merge($modules ? Legacy::exportModules($b) : [], Legacy::export($b, $passwords));
    return ' ' . Legacy::label() . ' diperbarui' . ($log ? ': ' . implode(' · ', $log) : '.');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $bizId = (int)($_POST['business_id'] ?? 0);
    try {
        if (!csrf_ok() || !$isAdmin) {
            throw new RuntimeException(!$isAdmin ? 'Hanya admin yang bisa mengubah user.' : 'Sesi form kedaluwarsa, silakan coba lagi.');
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
            flash($status === 'disabled' ? 'Akun dinonaktifkan — tidak bisa login lagi.' : 'Akun diaktifkan kembali.');
        } elseif ($action === 'access_save' || $action === 'access_add') {
            if ($action === 'access_add') {
                [$bizId, $roleId] = array_map('intval', explode(':', (string)($_POST['pick'] ?? '0:0')) + [0, 0]);
            } else {
                $roleId = (int)($_POST['role_id'] ?? 0);
            }
            $biz = Biz::business($bizId) ?: throw new RuntimeException('Pilih bisnis.');
            Access::ensureRoles($biz);
            $roles = Access::roles($bizId);
            if (!isset($roles[$roleId])) {
                throw new RuntimeException('Pilih peran yang valid.');
            }
            $passwords = [];
            if (Legacy::linked($biz) && !$person['legacy_user_id']) {
                // User belum punya akun login → wajib username + password sekali
                if (!$person['username']) {
                    throw new RuntimeException('Isi username dulu di tab Akun & Password.');
                }
                $pw = (string)($_POST['password'] ?? '');
                if (mb_strlen($pw) < 8) {
                    throw new RuntimeException('User ini belum punya login: isi password awal (min. 8 karakter).');
                }
                $passwords[$id] = $pw;
                $_SESSION['shown_password'] = ['id' => $id, 'pw' => $pw];
            }
            Access::setMembership($bizId, $id, $roleId, !empty($_POST['owner_login']), false);
            Auth::audit($action === 'access_add' ? 'access.add' : 'access.update', "{$biz['name']}: {$person['name']} → {$roles[$roleId]['name']}");
            flash(($action === 'access_add' ? "Akses ke {$biz['name']} ditambahkan." : "Akses {$biz['name']} disimpan.") . $sync($bizId, $passwords));
        } elseif ($action === 'access_remove') {
            $biz = Biz::business($bizId) ?: throw new RuntimeException('Bisnis tidak ditemukan.');
            Access::removeMembership($bizId, $id);
            Auth::audit('access.remove', "{$biz['name']}: {$person['name']}");
            flash("Akses ke {$biz['name']} dicabut." . $sync($bizId));
        } elseif ($action === 'menu_save') {
            Access::setPersonLevels($bizId, $id, array_map('intval', (array)($_POST['lv'] ?? [])));
            Auth::audit('access.menu', "#{$bizId}: {$person['name']}");
            flash('Menu sidebar disimpan.' . $sync($bizId));
        } elseif ($action === 'menu_reset') {
            Access::clearOverrides($bizId, $id);
            Auth::audit('access.clear_overrides', "#{$bizId}: {$person['name']}");
            flash('Menu dikembalikan sesuai peran.' . $sync($bizId));
        } elseif ($action === 'module_on') {
            $biz = Biz::business($bizId) ?: throw new RuntimeException('Bisnis tidak ditemukan.');
            $code = (string)($_POST['code'] ?? '');
            $cur = Biz::modules($biz);
            if (!array_key_exists($code, $cur['module'])) {
                throw new RuntimeException('Menu tidak dikenal untuk bisnis ini.');
            }
            Biz::saveModules($biz, array_merge(array_keys(array_filter($cur['module'])), [$code]), array_keys(array_filter($cur['feature'])));
            Auth::audit('business.module_on', "{$biz['name']}: {$code}");
            flash('Menu ' . Catalog::MODULES[$code][0] . " dinyalakan untuk {$biz['name']}." . $sync($bizId, [], true));
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect("/person.php?id={$id}&tab={$tab}" . ($bizId ? "&biz={$bizId}" : ''));
}

$person = $load();
$canLogin = $person['legacy_user_id'] && Db::legacyMaster() !== null;
$shown = ($_SESSION['shown_password']['id'] ?? 0) === $id ? $_SESSION['shown_password']['pw'] : null;
unset($_SESSION['shown_password']);

$st = $db->prepare('SELECT m.*, b.name AS business_name, b.client_id, b.legacy_business_id, c.name AS client_name, r.name AS role_name, r.is_owner
    FROM memberships m JOIN businesses b ON b.id = m.business_id JOIN clients c ON c.id = b.client_id JOIN roles r ON r.id = m.role_id
    WHERE m.person_id = ? ORDER BY c.name, b.name');
$st->execute([$id]);
$access = $st->fetchAll();
$st = $db->prepare('SELECT c.id, c.name FROM client_owners co JOIN clients c ON c.id = co.client_id WHERE co.person_id = ?');
$st->execute([$id]);
$ownerOf = $st->fetchAll();
$dis = $isAdmin ? '' : 'disabled';
$base = "/person.php?id={$id}";

View::start($person['name'], 'people', $me);
?>
<div class="crumb"><a href="/people.php">User</a> ›</div>
<h1><?= e($person['name']) ?>
    <?= $person['status'] === 'disabled' ? '<span class="pill">nonaktif</span>' : ($person['legacy_user_id'] ? '<span class="pill ok">aktif</span>' : '<span class="pill warn">belum punya login</span>') ?></h1>
<p class="muted" style="margin:0 0 10px;font-size:12px"><?= $person['username'] ? 'username <code>' . e($person['username']) . '</code> · ' : '' ?><?= e($person['email']) ?></p>

<?php if ($shown): ?>
    <div class="card" style="border-color:var(--gold2);max-width:760px">
        <h2>Password untuk <?= e($person['name']) ?></h2>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <code id="pw" style="font-size:20px;letter-spacing:1px;padding:6px 12px"><?= e($shown) ?></code>
            <button class="btn sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('pw').textContent);this.textContent='Tersalin ✓'">Salin</button>
            <?php if ($person['phone']): $wa = preg_replace('/\D/', '', (string)$person['phone']); $wa = str_starts_with($wa, '0') ? '62' . substr($wa, 1) : $wa; ?>
                <a class="btn ghost sm" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode("Login sistem: username {$person['username']}, password {$shown}") ?>">Kirim WhatsApp</a>
            <?php endif; ?>
        </div>
        <p class="muted" style="margin:8px 0 0;font-size:11px">Hanya tampil sekali. Bila lupa, buat password baru.</p>
    </div>
<?php endif; ?>

<nav class="subtabs">
    <a class="<?= $tab === 'akun' ? 'on' : '' ?>" href="<?= $base ?>&tab=akun">Akun & Password</a>
    <a class="<?= $tab === 'akses' ? 'on' : '' ?>" href="<?= $base ?>&tab=akses">Akses Bisnis (<?= count($access) + count($ownerOf) ?>)</a>
    <a class="<?= $tab === 'menu' ? 'on' : '' ?>" href="<?= $base ?>&tab=menu">Menu Sidebar</a>
</nav>

<?php if ($tab === 'akses'): ?>
    <div class="card" style="max-width:900px">
        <h2>Bisnis yang boleh dibuka</h2>
        <table class="sm">
            <thead><tr><th>Bisnis</th><th>Peran</th><th>Owner Login</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($ownerOf as $c): ?>
                    <tr><td><b>Semua bisnis <?= e($c['name']) ?></b></td><td><span class="pill gold">Pemilik grup</span></td><td>✓</td>
                        <td style="text-align:right"><a class="btn ghost sm" href="/client.php?id=<?= (int)$c['id'] ?>&tab=orang">Atur di grup</a></td></tr>
                <?php endforeach; ?>
                <?php foreach ($access as $a): $bid = (int)$a['business_id']; Access::ensureRoles(Biz::business($bid)); $roles = Access::roles($bid); ?>
                    <tr>
                        <td><b><?= e($a['business_name']) ?></b> <span class="muted" style="font-size:11px">· <?= e($a['client_name']) ?></span></td>
                        <td colspan="2">
                            <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                                <?= csrf_field() ?><input type="hidden" name="action" value="access_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="akses"><input type="hidden" name="business_id" value="<?= $bid ?>">
                                <select name="role_id" class="sm" <?= $dis ?>><?php foreach ($roles as $rid => $r): ?><option value="<?= $rid ?>" <?= $rid === (int)$a['role_id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
                                <label style="display:flex;gap:4px;align-items:center;margin:0;color:var(--ink)"><input type="checkbox" name="owner_login" value="1" style="width:auto" <?= $a['portal_owner'] ? 'checked' : '' ?> <?= $dis ?>> boleh</label>
                                <?php if ($isAdmin): ?><button class="btn sm">Simpan</button><?php endif; ?>
                            </form>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <a class="btn ghost sm" href="<?= $base ?>&tab=menu&biz=<?= $bid ?>">Menu</a>
                            <?php if ($isAdmin): ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('Cabut akses <?= e($person['name']) ?> dari <?= e($a['business_name']) ?>?')"><?= csrf_field() ?><input type="hidden" name="action" value="access_remove"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="akses"><input type="hidden" name="business_id" value="<?= $bid ?>"><button class="btn danger sm">Cabut</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$access && !$ownerOf): ?><tr><td colspan="4" class="muted">Belum punya akses ke bisnis mana pun.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($isAdmin):
        $have = array_map(fn ($a) => (int)$a['business_id'], $access);
        $others = array_filter($db->query('SELECT b.*, c.name AS client_name FROM businesses b JOIN clients c ON c.id = b.client_id WHERE b.type <> "website" ORDER BY c.name, b.name')->fetchAll(), fn ($b) => !in_array((int)$b['id'], $have, true)); ?>
        <?php if ($others): ?>
            <form method="post" class="card" style="max-width:900px" novalidate>
                <h2>Tambah akses ke bisnis lain</h2>
                <?= csrf_field() ?><input type="hidden" name="action" value="access_add"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="akses">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <select name="pick" class="sm">
                        <?php foreach ($others as $b): Access::ensureRoles($b); ?>
                            <optgroup label="<?= e($b['name']) ?> · <?= e($b['client_name']) ?>">
                                <?php foreach (Access::roles((int)$b['id']) as $rid => $r): ?><option value="<?= (int)$b['id'] ?>:<?= $rid ?>" <?= $r['code'] === 'staff' ? 'selected' : '' ?>><?= e($b['name']) ?> — <?= e($r['name']) ?></option><?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <label style="display:flex;gap:4px;align-items:center;margin:0;color:var(--ink)"><input type="checkbox" name="owner_login" value="1" style="width:auto"> Owner Login</label>
                    <?php if (!$person['legacy_user_id']): ?><input name="password" class="sm" placeholder="password awal (min. 8)" style="width:180px"><?php endif; ?>
                    <button class="btn sm">Tambah akses</button>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

<?php elseif ($tab === 'menu'):
    $bizId = (int)($_GET['biz'] ?? ($access[0]['business_id'] ?? 0));
    $a = array_values(array_filter($access, fn ($x) => (int)$x['business_id'] === $bizId))[0] ?? null; ?>
    <?php if (!$access): ?>
        <div class="card muted" style="max-width:760px"><?= $ownerOf ? 'Pemilik grup otomatis melihat semua menu di semua bisnis grupnya.' : 'Tambahkan akses ke bisnis dulu di tab Akses Bisnis.' ?></div>
    <?php else: ?>
        <?php if (count($access) > 1): ?>
            <nav class="subtabs"><?php foreach ($access as $x): ?><a class="<?= (int)$x['business_id'] === $bizId ? 'on' : '' ?>" href="<?= $base ?>&tab=menu&biz=<?= (int)$x['business_id'] ?>"><?= e($x['business_name']) ?></a><?php endforeach; ?></nav>
        <?php endif; ?>
        <?php if ($a):
            $biz = Biz::business($bizId);
            $mods = Biz::modules($biz)['module'];
            $eff = Access::effective($biz, $id);
            $over = json_decode((string)($a['overrides'] ?? ''), true) ?: []; ?>
            <div class="card" style="max-width:760px">
                <h2>Menu sidebar · <?= e($a['business_name']) ?></h2>
                <p class="muted" style="margin:0 0 10px;font-size:12px">Peran: <b><?= e($a['role_name']) ?></b>. Ubah level per menu untuk user ini saja (✱ = berbeda dari perannya). <b>Tidak</b> = menu tidak tampil.</p>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="menu_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="menu"><input type="hidden" name="business_id" value="<?= $bizId ?>">
                    <table class="sm">
                        <thead><tr><th>Menu</th><th>Akses</th></tr></thead>
                        <tbody>
                            <?php foreach ($mods as $code => $on): if (!$on) continue; $lv = $eff[$code] ?? 0; ?>
                                <tr>
                                    <td><?= e(Catalog::MODULES[$code][0]) ?><?= isset($over[$code]) ? ' <span style="color:var(--gold)" title="berbeda dari peran">✱</span>' : '' ?></td>
                                    <td><select name="lv[<?= e($code) ?>]" class="sm" <?= $dis ?>><?php foreach (Catalog::LEVELS as $v => $l): ?><option value="<?= $v ?>" <?= $v === $lv ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($isAdmin): ?><div style="margin-top:10px"><button class="btn sm">Simpan menu</button></div><?php endif; ?>
                </form>
                <?php if ($over && $isAdmin): ?>
                    <form method="post" style="margin-top:8px" onsubmit="return confirm('Kembalikan semua menu sesuai peran <?= e($a['role_name']) ?>?')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="menu_reset"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="menu"><input type="hidden" name="business_id" value="<?= $bizId ?>"><button class="btn ghost sm">Kembalikan sesuai peran</button>
                    </form>
                <?php endif; ?>
                <p class="muted" style="margin:10px 0 0;font-size:11px">Lihat = hanya melihat · Input = + menambah · Ubah = + mengubah · Kelola = + menghapus.</p>
            </div>
            <?php $offList = array_keys(array_filter($mods, fn ($on) => !$on)); if ($offList): ?>
                <div class="card" style="max-width:760px">
                    <h2>Menu yang dimatikan untuk <?= e($a['business_name']) ?></h2>
                    <p class="muted" style="margin:0 0 8px;font-size:12px">Tidak tampil untuk siapa pun di bisnis ini. Nyalakan bila mau dipakai:</p>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <?php foreach ($offList as $code): ?>
                            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="module_on"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="menu"><input type="hidden" name="business_id" value="<?= $bizId ?>"><input type="hidden" name="code" value="<?= e($code) ?>">
                                <button class="btn ghost sm" <?= $dis ?>>+ <?= e(Catalog::MODULES[$code][0]) ?></button></form>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

<?php else: ?>
    <div class="row2" style="align-items:start;max-width:960px">
        <form method="post" class="card" style="margin:0">
            <h2>Data akun</h2>
            <?= csrf_field() ?><input type="hidden" name="action" value="account"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="akun">
            <div class="field"><label>Nama lengkap</label><input name="name" value="<?= e($person['name']) ?>" <?= $dis ?>></div>
            <div class="field"><label>Email</label><input name="email" type="email" value="<?= e($person['email']) ?>" <?= $dis ?>></div>
            <div class="field"><label>Username login</label><input name="username" value="<?= e($person['username']) ?>" autocapitalize="none" <?= $dis ?>></div>
            <div class="field"><label>WhatsApp</label><input name="phone" value="<?= e($person['phone']) ?>" placeholder="0812…" <?= $dis ?>></div>
            <?php if ($isAdmin): ?><button class="btn sm">Simpan data</button><?php endif; ?>
        </form>
        <div>
            <div class="card" style="margin:0 0 12px">
                <h2>Password</h2>
                <?php if (!$canLogin): ?>
                    <p class="muted" style="margin:0;font-size:12px">Belum punya akun login. Akun dibuat saat diberi akses ke bisnis (tab Akses Bisnis).</p>
                <?php elseif ($isAdmin): ?>
                    <form method="post" style="margin-bottom:8px" onsubmit="return confirm('Buat password baru untuk <?= e($person['name']) ?>? Password lama langsung tidak berlaku.')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="akun"><input type="hidden" name="mode" value="auto">
                        <button class="btn sm">🔑 Buat password baru</button>
                    </form>
                    <form method="post" style="display:flex;gap:6px" onsubmit="return confirm('Ganti password <?= e($person['name']) ?>?')" novalidate>
                        <?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="akun">
                        <input name="password" class="sm" placeholder="atau ketik sendiri (min. 8)" autocomplete="off" style="flex:1"><button class="btn ghost sm">Simpan</button>
                    </form>
                    <p class="muted" style="margin:8px 0 0;font-size:11px">Password lama tidak bisa dilihat (disimpan acak satu arah). Bila user lupa, buat yang baru.</p>
                <?php endif; ?>
            </div>
            <div class="card" style="margin:0">
                <h2>Status</h2>
                <p class="muted" style="margin:0 0 8px;font-size:12px"><?= $person['status'] === 'disabled' ? 'Nonaktif — tidak bisa login.' : 'Aktif — bisa login ke bisnis yang diizinkan.' ?></p>
                <?php if ($isAdmin): ?>
                    <form method="post" onsubmit="return confirm('<?= $person['status'] === 'disabled' ? 'Aktifkan kembali akun ini?' : 'Nonaktifkan akun ini? Orangnya langsung tidak bisa login.' ?>')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="akun">
                        <button class="btn <?= $person['status'] === 'disabled' ? '' : 'danger' ?> sm"><?= $person['status'] === 'disabled' ? 'Aktifkan' : 'Nonaktifkan' ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php View::end();
