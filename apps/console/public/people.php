<?php

/** User: daftar semua orang yang punya akses ke bisnis client + tambah user baru (sekaligus akses bisnis & peran). */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();
$db = Biz::db();
$isAdmin = $me['role'] === 'admin';
$view = (string)($_GET['view'] ?? '');

// Semua bisnis (dikelompokkan per client) + peran masing-masing, untuk form tambah
$bizList = $db->query('SELECT b.*, c.name AS client_name FROM businesses b JOIN clients c ON c.id = b.client_id WHERE b.type <> "website" ORDER BY c.name, b.name')->fetchAll();
$rolesOf = [];
foreach ($bizList as $b) {
    Access::ensureRoles($b);
    $rolesOf[(int)$b['id']] = Access::roles((int)$b['id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = [
        'name' => trim((string)($_POST['name'] ?? '')), 'email' => strtolower(trim((string)($_POST['email'] ?? ''))),
        'phone' => trim((string)($_POST['phone'] ?? '')), 'username' => strtolower(trim((string)($_POST['username'] ?? ''))),
        'password' => (string)($_POST['password'] ?? ''), 'biz' => array_map('intval', (array)($_POST['biz'] ?? [])),
        'role' => array_map('intval', (array)($_POST['role'] ?? [])), 'owner_login' => array_map('intval', (array)($_POST['owner_login'] ?? [])),
    ];
    try {
        if (!csrf_ok() || !$isAdmin) {
            throw new RuntimeException(!$isAdmin ? 'Hanya admin yang bisa menambah user.' : 'Sesi form kedaluwarsa, silakan coba lagi.');
        }
        if (mb_strlen($in['name']) < 2 || !filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Nama dan email wajib diisi dengan benar.');
        }
        if (!preg_match('/^[a-z0-9_.]{3,50}$/', $in['username'])) {
            throw new RuntimeException('Username: 3–50 huruf kecil, angka, titik, atau garis bawah (tanpa spasi).');
        }
        if (mb_strlen($in['password']) < 8) {
            throw new RuntimeException('Password minimal 8 karakter (atau klik "Buat acak").');
        }
        if (!$in['biz']) {
            throw new RuntimeException('Pilih minimal satu bisnis yang boleh dibuka.');
        }
        $dup = $db->prepare('SELECT name FROM people WHERE email = ? OR username = ?');
        $dup->execute([$in['email'], $in['username']]);
        if ($other = $dup->fetchColumn()) {
            throw new RuntimeException("Email atau username sudah dipakai {$other} — buka user itu untuk menambah aksesnya.");
        }
        if (Db::legacyMaster() !== null) {
            $dup = Legacy::db()->prepare('SELECT username FROM users WHERE username = ? OR email = ?');
            $dup->execute([$in['username'], $in['email']]);
            if ($other = $dup->fetchColumn()) {
                throw new RuntimeException("Username atau email sudah dipakai di sistem ({$other}).");
            }
        }
        $pid = Access::person($in['name'], $in['email']);
        $db->prepare('UPDATE people SET username = ?, phone = ? WHERE id = ?')->execute([$in['username'], $in['phone'] ?: null, $pid]);
        $log = [];
        $password = $in['password'];
        foreach ($bizList as $b) {
            $bid = (int)$b['id'];
            if (!in_array($bid, $in['biz'], true)) {
                continue;
            }
            $rid = $in['role'][$bid] ?? 0;
            if (!isset($rolesOf[$bid][$rid])) {
                $rid = (int)(array_search('staff', array_column(array_map(fn ($r) => ['id' => (int)$r['id'], 'code' => $r['code']], $rolesOf[$bid]), 'code', 'id'), true) ?: array_key_last($rolesOf[$bid]));
            }
            Access::setMembership($bid, $pid, $rid, in_array($bid, $in['owner_login'], true), false);
            if (Legacy::linked($b)) {
                // Akun login dibuat di bisnis terhubung pertama; berikutnya tinggal ditambah aksesnya
                $log = array_merge($log, Legacy::export($b, [$pid => $password]));
            }
        }
        Auth::audit('person.add', "{$in['name']} ({$in['username']})");
        $_SESSION['shown_password'] = ['id' => $pid, 'pw' => $password];
        flash("User {$in['name']} ditambahkan." . ($log ? ' ' . Legacy::label() . ': ' . implode(' · ', array_unique($log)) : ''));
        redirect("/person.php?id={$pid}");
    } catch (Throwable $e) {
        unset($in['password']);
        $_SESSION['old_person'] = $in;
        flash($e->getMessage(), 'err');
        redirect('/people.php?view=tambah');
    }
}

View::start('User', 'people', $me);

if ($view === 'tambah' && $isAdmin):
    $old = $_SESSION['old_person'] ?? [];
    unset($_SESSION['old_person']);
    $preBiz = (int)($_GET['biz'] ?? 0); ?>
    <div class="crumb"><a href="/people.php">User</a> ›</div>
    <h1>Tambah user</h1>
    <p class="muted" style="margin:0 0 12px;font-size:12px">Satu user = satu akun login untuk semua bisnis yang dicentang.</p>
    <form method="post" autocomplete="off" novalidate class="card" style="max-width:820px">
        <?= csrf_field() ?>
        <h2>1 · Data user</h2>
        <div class="row2">
            <div class="field"><label>Nama lengkap</label><input name="name" value="<?= e($old['name'] ?? '') ?>"></div>
            <div class="field"><label>Email</label><input name="email" type="email" value="<?= e($old['email'] ?? '') ?>"></div>
            <div class="field"><label>Username login</label><input name="username" value="<?= e($old['username'] ?? '') ?>" placeholder="mis. rina.fo" autocapitalize="none"></div>
            <div class="field"><label>Password awal</label>
                <div style="display:flex;gap:6px"><input name="password" id="pw" placeholder="min. 8 karakter"><button type="button" class="btn ghost sm" onclick="var c='abcdefghjkmnpqrstuvwxyz23456789',p='';for(var i=0;i<10;i++){p+=c[Math.floor(Math.random()*c.length)];if(i===4)p+='-';}document.getElementById('pw').value=p">Buat acak</button></div></div>
            <div class="field"><label>WhatsApp (opsional)</label><input name="phone" value="<?= e($old['phone'] ?? '') ?>" placeholder="0812…"></div>
        </div>
        <h2 style="margin-top:6px">2 · Bisnis yang boleh dibuka & perannya</h2>
        <table class="sm">
            <thead><tr><th style="width:30px"></th><th>Bisnis</th><th>Peran</th><th>Owner Login</th></tr></thead>
            <tbody>
                <?php foreach ($bizList as $b): $bid = (int)$b['id']; $checked = in_array($bid, $old['biz'] ?? [], true) || $bid === $preBiz;
                    $roleSel = (int)(($old['role'][$bid] ?? 0) ?: (array_search('staff', array_column(array_map(fn ($r) => ['id' => (int)$r['id'], 'code' => $r['code']], $rolesOf[$bid]), 'code', 'id'), true) ?: 0)); ?>
                    <tr>
                        <td><input type="checkbox" name="biz[]" value="<?= $bid ?>" style="width:auto" <?= $checked ? 'checked' : '' ?>></td>
                        <td><b><?= e($b['name']) ?></b> <span class="muted" style="font-size:11px">· <?= e($b['client_name']) ?></span><?= $b['legacy_business_id'] ? ' <span class="pill" title="terhubung ke ' . e(Legacy::label()) . '">🔗</span>' : '' ?></td>
                        <td><select name="role[<?= $bid ?>]" class="sm"><?php foreach ($rolesOf[$bid] as $rid => $r): ?><option value="<?= $rid ?>" <?= $rid === $roleSel ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></td>
                        <td><input type="checkbox" name="owner_login[]" value="<?= $bid ?>" style="width:auto" <?= in_array($bid, $old['owner_login'] ?? [], true) ? 'checked' : '' ?>></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted" style="margin:8px 0 12px;font-size:12px">Menu yang tampil mengikuti peran. Setelah disimpan, menu per user bisa diatur lagi di tab <b>Menu Sidebar</b>.</p>
        <button class="btn">Simpan user</button> <a class="btn ghost" href="/people.php">Batal</a>
    </form>

<?php else:
    $q = trim((string)($_GET['q'] ?? ''));
    $people = $db->query('SELECT * FROM people ORDER BY name')->fetchAll();
    $acc = [];
    foreach ($db->query('SELECT m.person_id, b.id, b.name, r.name AS role_name, r.is_owner, b.legacy_business_id FROM memberships m JOIN businesses b ON b.id = m.business_id JOIN roles r ON r.id = m.role_id ORDER BY b.name')->fetchAll() as $r) {
        $acc[(int)$r['person_id']][] = $r;
    }
    $owners = [];
    foreach ($db->query('SELECT co.person_id, c.name FROM client_owners co JOIN clients c ON c.id = co.client_id')->fetchAll() as $r) {
        $owners[(int)$r['person_id']][] = $r['name'];
    }
    if ($q !== '') {
        $people = array_filter($people, fn ($p) => stripos($p['name'] . ' ' . $p['username'] . ' ' . $p['email'], $q) !== false);
    } ?>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
        <div><h1>User</h1><p class="muted" style="margin:0;font-size:12px">Semua orang yang bisa login ke sistem bisnis. Klik <b>Edit</b> untuk password, akses bisnis, dan menu sidebar.</p></div>
        <?php if ($isAdmin): ?><a class="btn sm" href="/people.php?view=tambah">+ Tambah user</a><?php endif; ?>
    </div>
    <form class="card" style="display:flex;gap:8px;padding:10px 12px" method="get"><input name="q" value="<?= e($q) ?>" placeholder="Cari nama, username, atau email…"><button class="btn ghost sm">Cari</button></form>
    <div class="card">
        <table class="sm stack">
            <thead><tr><th>User</th><th>Bisnis & peran</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($people as $p): $pid = (int)$p['id']; ?>
                    <tr>
                        <td><b><?= e($p['name']) ?></b><br><span class="muted" style="font-size:11px"><?= $p['username'] ? e($p['username']) . ' · ' : '' ?><?= e($p['email']) ?></span></td>
                        <td>
                            <?php foreach ($owners[$pid] ?? [] as $c): ?><span class="pill gold">Pemilik grup <?= e($c) ?></span> <?php endforeach; ?>
                            <?php foreach ($acc[$pid] ?? [] as $a): ?><span class="pill <?= $a['is_owner'] ? 'gold' : '' ?>"><?= e($a['name']) ?> · <?= e($a['role_name']) ?></span> <?php endforeach; ?>
                            <?= empty($acc[$pid]) && empty($owners[$pid]) ? '<span class="muted" style="font-size:11px">belum ada akses</span>' : '' ?>
                        </td>
                        <td><?= $p['status'] === 'disabled' ? '<span class="pill">nonaktif</span>' : ($p['legacy_user_id'] ? '<span class="pill ok">aktif</span>' : '<span class="pill warn">belum punya login</span>') ?></td>
                        <td style="text-align:right"><a class="btn ghost sm" href="/person.php?id=<?= $pid ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$people): ?><tr><td colspan="4" class="muted">Tidak ada user<?= $q !== '' ? ' yang cocok' : '' ?>.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif;
View::end();
