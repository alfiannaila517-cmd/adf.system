<?php

/** Satu grup (pemilik / perusahaan): tab Bisnis · Owner & orang · Login & Domain · Data grup. Tagihannya ada di menu Tagihan. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();
$db = Biz::db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$client = Biz::client($id);
if (!$client) {
    flash('Grup tidak ditemukan.', 'err');
    redirect('/clients.php');
}
$tab = (string)($_GET['tab'] ?? 'bisnis');
if ($tab === 'tagihan') {
    redirect('/billing.php'); // tagihan pindah ke menu Tagihan
}
$isAdmin = $me['role'] === 'admin';
// Bisnis grup ini yang terhubung ke sistem lama (perubahan owner grup dikirim ke sana)
$linkedIds = [];
if (Db::legacyMaster() !== null) {
    $st = $db->prepare('SELECT id FROM businesses WHERE client_id = ? AND legacy_business_id IS NOT NULL');
    $st->execute([$id]);
    $linkedIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $newPasswords = [];
    if (!csrf_ok() || !$isAdmin) {
        flash(!$isAdmin ? 'Hanya admin yang bisa mengubah data grup.' : 'Sesi form kedaluwarsa, silakan coba lagi.', 'err');
    } elseif ($action === 'update') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        if (mb_strlen($name) < 2 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            flash('Nama wajib diisi dan email harus valid.', 'err');
        } else {
            $db->prepare('UPDATE clients SET name = ?, contact_name = ?, email = ?, phone = ?, address = ?, notes = ? WHERE id = ?')->execute([
                mb_substr($name, 0, 150), trim((string)($_POST['contact_name'] ?? '')) ?: null, $email ?: null, trim((string)($_POST['phone'] ?? '')) ?: null,
                trim((string)($_POST['address'] ?? '')) ?: null, trim((string)($_POST['notes'] ?? '')) ?: null, $id,
            ]);
            Auth::audit('client.update', $name);
            flash('Data grup disimpan.');
        }
    } elseif ($action === 'owner_add') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $st = $db->prepare('SELECT id, legacy_user_id FROM people WHERE email = ?');
        $st->execute([$email]);
        $existing = $st->fetch() ?: null;
        $needLogin = $linkedIds && empty($existing['legacy_user_id']);
        $taken = false;
        if ($needLogin && $username !== '') {
            $st = $db->prepare('SELECT 1 FROM people WHERE username = ? AND id <> ?');
            $st->execute([$username, (int)($existing['id'] ?? 0)]);
            $taken = (bool)$st->fetchColumn();
            $st = Legacy::db()->prepare('SELECT 1 FROM users WHERE username = ? OR email = ?');
            $st->execute([$username, $email]);
            $taken = $taken || (bool)$st->fetchColumn();
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Email tidak valid.', 'err');
        } elseif ($needLogin && (!preg_match('/^[a-z0-9_.]{3,50}$/', $username) || mb_strlen($password) < 8)) {
            flash('Grup ini terhubung ke ' . Legacy::label() . ': isi username (3–50 huruf kecil/angka/titik) dan password awal (min. 8).', 'err');
        } elseif ($needLogin && $taken) {
            flash("Username {$username} atau email {$email} sudah dipakai di sistem.", 'err');
        } else {
            $pid = Access::person((string)($_POST['name'] ?? ''), $email);
            if ($needLogin) {
                $db->prepare('UPDATE people SET username = ? WHERE id = ?')->execute([$username, $pid]);
                $newPasswords[$pid] = $password;
            }
            Access::setGroupOwner($id, $pid, true);
            Auth::audit('access.group_owner_add', "{$client['name']}: {$email}");
            flash('Pemilik grup ditambahkan — otomatis punya akses penuh ke semua bisnis grup ini.');
        }
    } elseif ($action === 'owner_remove') {
        Access::setGroupOwner($id, (int)($_POST['person_id'] ?? 0), false);
        Auth::audit('access.group_owner_remove', "{$client['name']}: #" . (int)($_POST['person_id'] ?? 0));
        flash('Pemilik grup dicabut. Akses per bisnis (bila ada) tetap.');
    } elseif ($action === 'branding') {
        // Login & Domain: domain grup + background/logo login (tanpa mengunci satu bisnis)
        try {
            Branding::save($id, $_POST, $_FILES, array_map('strval', (array)($_POST['remove'] ?? [])));
            Auth::audit('client.branding', $client['name']);
            flash('Login & domain disimpan.');
        } catch (Throwable $e) {
            flash('Gagal: ' . $e->getMessage(), 'err');
        }
    } elseif ($action === 'add_business') {
        try {
            [$bid, $msg, $ok] = Biz::add($client, $_POST);
            flash($msg, $ok ? 'ok' : 'err');
            redirect("/business.php?id={$bid}" . ($ok ? '' : '&tab=database'));
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
    }

    // Owner grup berlaku di semua bisnis grup → kirim ke setiap bisnis yang terhubung ke sistem lama
    if (in_array($action, ['owner_add', 'owner_remove'], true) && ($_SESSION['flash']['type'] ?? '') === 'ok' && $linkedIds) {
        $sync = [];
        try {
            foreach ($linkedIds as $bid) {
                // Password awal hanya dipakai sekali (user dibuat di bisnis pertama)
                $sync = array_merge($sync, Legacy::export(Biz::business((int)$bid), $newPasswords));
                $newPasswords = [];
            }
            Auth::audit('legacy.export', "{$client['name']}: " . count($sync) . ' perubahan');
            $_SESSION['flash']['msg'] .= ' ' . Legacy::label() . ' diperbarui' . ($sync ? ': ' . implode(' · ', array_unique($sync)) : '.');
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['msg' => $_SESSION['flash']['msg'] . ' TAPI gagal menulis ke ' . Legacy::label() . ': ' . $e->getMessage(), 'type' => 'err'];
        }
    }
    redirect("/client.php?id={$id}&tab={$tab}");
}

$businesses = Biz::businessesOf($id);

View::start($client['name'], 'clients', $me);
?>
<?php
$gLogo = null;
try {
    if (Branding::available()) {
        $gbr = Branding::forClient($id);
        $gLogo = Branding::publicUrl($gbr, 'favicon') ?? Branding::publicUrl($gbr, 'logo');
    }
} catch (Throwable) {
    // tanpa logo: pakai inisial
}
?>
<div class="crumb"><a href="/clients.php">Bisnis</a> ›</div>
<div class="bizhead">
    <?= View::groupAvatar((string)$client['name'], $gLogo, 'sm') ?>
    <div style="min-width:0">
        <h1 style="margin:0"><?= e($client['name']) ?> <?php if ($client['is_demo']): ?><span class="pill gold">DEMO</span><?php endif; ?></h1>
        <p class="muted" style="margin:0;font-size:12px"><?= e(implode(' · ', array_filter([$client['contact_name'], $client['email'], $client['phone']])) ?: 'Kontak belum diisi') ?></p>
    </div>
</div>

<nav class="tabs">
    <?php foreach (['bisnis' => 'Bisnis (' . count($businesses) . ')', 'orang' => 'Akses', 'login' => 'Login & Domain', 'ringkasan' => 'Data grup'] as $k => $label): ?>
        <a class="<?= $tab === $k ? 'on' : '' ?>" href="/client.php?id=<?= $id ?>&tab=<?= $k ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'bisnis'): ?>
    <?php if ($businesses): ?>
        <div class="blist"><?php foreach ($businesses as $b) {
            echo View::bizRow($b, !$b['sub_id'] ? 'belum ada langganan' : ((int)$b['unpaid_count'] > 0 ? 'jatuh tempo ' . date('d M', strtotime((string)$b['next_due'])) : ''));
        } ?></div>
    <?php else: ?>
        <div class="soonbox">Belum ada bisnis di grup ini.</div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <details class="card" id="tambah" style="margin-top:12px" <?= isset($_GET['add']) || !$businesses ? 'open' : '' ?>>
            <summary style="cursor:pointer;font-weight:600">＋ Tambah bisnis ke <?= e($client['name']) ?></summary>
            <div style="height:10px"></div>
            <form method="post" class="row2" style="align-items:end">
                <?= csrf_field() ?><input type="hidden" name="action" value="add_business"><input type="hidden" name="id" value="<?= $id ?>">
                <div><label>Nama bisnis</label><input name="name" required placeholder="mis. Eatmeet"></div>
                <div><label>Jenis</label><select name="type"><?php foreach (Biz::TYPES as $code => [$l, $i]): ?><option value="<?= $code ?>"><?= $i ?> <?= e($l) ?></option><?php endforeach; ?></select></div>
                <div><label>Kode (opsional)</label><input name="slug" placeholder="otomatis dari nama"></div>
                <div><label>Domain (opsional)</label><input name="domain" placeholder="mis. narayanakarimunjawa.com"></div>
                <div><label style="display:flex;gap:6px;align-items:center;margin:0 0 8px;color:var(--ink)"><input type="checkbox" name="hosted_elsewhere" value="1" style="width:auto"> Sistemnya di server lain</label><span class="muted" style="font-size:11px">mis. hosting sendiri — tidak dibuat di VPS, tetap ditagih &amp; sinkron dari Console</span></div>
                <div><button class="btn" style="width:100%">Tambah bisnis</button></div>
            </form>
        </details>
    <?php endif; ?>

<?php elseif ($tab === 'orang'):
    $people = Access::clientPeople($id);
    $owners = array_filter($people, fn ($p) => $p['group_owner']);
    // Peran tiap orang per bisnis → tabel orang × bisnis
    $roleAt = [];
    foreach ($people as $p) {
        foreach ($p['access'] as [$bid, , $rName]) {
            $roleAt[(int)$p['id']][(int)$bid] = $rName;
        }
    } ?>
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px">
            <h2 style="margin:0">Siapa bisa membuka bisnis mana</h2>
            <?php if ($isAdmin): ?><a class="btn sm" href="/people.php?view=tambah">+ Tambah user</a><?php endif; ?>
        </div>
        <p class="muted" style="margin:0 0 10px;font-size:12px">Klik nama untuk ubah password, peran, atau menu. Tanda – = tidak punya akses ke bisnis itu.</p>
        <div style="overflow-x:auto">
            <table class="sm">
                <thead><tr><th>Nama</th><?php foreach ($businesses as $b): ?><th><?= e($b['name']) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <?php foreach ($people as $p): $pid = (int)$p['id']; ?>
                        <tr>
                            <td style="white-space:nowrap"><a href="/person.php?id=<?= $pid ?>" style="color:var(--ink)"><b><?= e($p['name']) ?></b></a><?= $p['status'] === 'invited' ? ' <span class="pill warn">diundang</span>' : '' ?><br><span class="muted" style="font-size:11px"><?= e($p['email']) ?></span></td>
                            <?php foreach ($businesses as $b): $r = $p['group_owner'] ? 'Pemilik' : ($roleAt[$pid][(int)$b['id']] ?? null); ?>
                                <td><?= $r === null ? '<span class="muted">–</span>' : '<span class="pill ' . ($p['group_owner'] ? 'gold' : '') . '">' . e($r) . '</span>' ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$people): ?><tr><td colspan="<?= count($businesses) + 1 ?>" class="muted">Belum ada orang di grup ini.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Pemilik grup</h2>
        <p class="muted" style="margin:0 0 10px;font-size:12px">Pemilik grup otomatis bisa membuka <b>semua</b> bisnis <?= e($client['name']) ?> dengan akses penuh + Owner Login, termasuk bisnis yang ditambahkan nanti.</p>
        <?php foreach ($owners as $o): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;border-top:1px solid var(--line2)">
                <span><b><?= e($o['name']) ?></b> <span class="muted" style="font-size:12px">· <?= e($o['email']) ?></span></span>
                <?php if ($isAdmin): ?>
                    <form method="post" onsubmit="return confirm('Cabut status pemilik grup <?= e($o['name']) ?>?')"><?= csrf_field() ?><input type="hidden" name="action" value="owner_remove"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="person_id" value="<?= (int)$o['id'] ?>"><button class="btn danger sm">Cabut</button></form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$owners): ?><p style="margin:0;font-size:12.5px" class="muted">Belum ada pemilik grup.</p><?php endif; ?>
        <?php if ($isAdmin): ?>
            <details style="margin-top:10px">
                <summary style="cursor:pointer;font-size:12.5px;font-weight:600">Tambah pemilik grup</summary>
                <form method="post" class="row2" style="align-items:end;margin-top:10px">
                    <?= csrf_field() ?><input type="hidden" name="action" value="owner_add"><input type="hidden" name="id" value="<?= $id ?>">
                    <div><label>Nama</label><input name="name" required></div>
                    <div><label>Email</label><input name="email" type="email" required></div>
                    <?php if ($linkedIds): ?>
                        <div><label>Username login</label><input name="username" pattern="[a-z0-9_.]{3,50}" placeholder="kosong bila sudah punya akun" autocomplete="off"></div>
                        <div><label>Password awal</label><input name="password" type="password" minlength="8" placeholder="min. 8 karakter" autocomplete="new-password"></div>
                    <?php endif; ?>
                    <div><button class="btn" style="width:100%">Jadikan pemilik grup</button></div>
                </form>
            </details>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'login'): ?>
    <?php if (!Branding::available()): ?>
        <div class="soonbox">Sistem lama belum terhubung di server ini.</div>
    <?php else: $br = Branding::forClient($id); ?>
        <p class="muted" style="margin:0 0 12px;font-size:12px">Domain & tampilan login khusus <?= e($client['name']) ?>. Staf membuka domain ini → halaman login memakai background, logo & nama perusahaan; setelah login tetap bisa pindah ke semua bisnis yang diizinkan.</p>
        <form method="post" enctype="multipart/form-data" class="card" style="max-width:760px">
            <?= csrf_field() ?><input type="hidden" name="action" value="branding"><input type="hidden" name="id" value="<?= $id ?>">
            <h2>Login perusahaan</h2>
            <div class="row2">
                <div class="field"><label>Domain login</label><input name="domain" value="<?= e($br['domain']) ?>" placeholder="mis. nasita.biz.id" <?= $isAdmin ? '' : 'disabled' ?>></div>
                <div class="field"><label>Nama yang tampil</label><input name="name" value="<?= e($br['name']) ?>" placeholder="mis. NASITA" maxlength="40" <?= $isAdmin ? '' : 'disabled' ?>></div>
            </div>
            <div class="field"><label>Keterangan kecil (opsional)</label><input name="subtitle" value="<?= e($br['subtitle']) ?>" placeholder="mis. Narayana Hotel · Bens Cafe · Eat Meet · Gudang Nasita" maxlength="80" <?= $isAdmin ? '' : 'disabled' ?>></div>
            <div class="row2">
                <?php foreach (['bg' => 'Background (foto horizontal, min. 1920×1080)', 'logo' => 'Logo (PNG transparan disarankan)', 'favicon' => 'Favicon (ikon di tab browser & HP)'] as $k => $label): $url = Branding::publicUrl($br, $k); ?>
                    <div class="field"><label><?= e($label) ?><?= $k === 'favicon' && !empty($br['favicon_auto']) ? ' <span class="pill">otomatis dari logo</span>' : '' ?></label>
                        <?php if ($url): ?><img src="<?= e($url) ?>" alt="" style="display:block;max-width:100%;margin:0 0 6px;<?= $k === 'favicon' ? 'width:64px;height:64px;border-radius:50%' : 'max-height:110px;border-radius:8px' ?><?= $k === 'logo' ? ';background:#0f172a;padding:6px' : '' ?>">
                        <?php elseif ($k === 'favicon'): ?><p class="muted" style="margin:0 0 6px;font-size:11.5px">Kosong = otomatis dibuat dari logo, dipotong bulat.</p>
                            <?php if ($isAdmin): ?><label style="display:flex;gap:6px;align-items:center;margin:0 0 6px;font-size:12px"><input type="checkbox" name="remove[]" value="<?= $k ?>" style="width:auto"> hapus</label><?php endif; ?>
                        <?php endif; ?>
                        <input type="file" name="<?= $k ?>" accept="image/jpeg,image/png,image/webp" <?= $isAdmin ? '' : 'disabled' ?>>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($isAdmin): ?><button class="btn">Simpan</button><?php endif; ?>
        </form>
        <?php if ($br['domain']):
            $dom = $br['domain'];
            $serverIp = (string)Secrets::get('SERVER_IP', $_SERVER['SERVER_ADDR'] ?? '');
            $resolved = gethostbyname($dom);
            $dnsOk = $resolved !== $dom && ($serverIp === '' || $resolved === $serverIp); ?>
            <div class="card" style="max-width:760px">
                <h2>Domain <?= e($dom) ?></h2>
                <table class="sm">
                    <tr><td style="width:28px"><?= $dnsOk ? '✅' : '⏳' ?></td><td><b>1. DNS</b> — record <b>A</b> <code><?= e($dom) ?></code> (dan <code>www</code>) → <code><?= e($serverIp ?: 'IP VPS') ?></code><br><span class="muted" style="font-size:11px">sekarang mengarah ke: <?= $resolved === $dom ? 'belum ada' : e($resolved) ?></span></td></tr>
                    <tr><td>🖥️</td><td><b>2. Pasang di server</b> (sekali, setelah DNS benar):<br><code>sudo bash /srv/adf/app/ops/add-domain.sh <?= e($dom) ?></code></td></tr>
                    <tr><td>🌐</td><td><b>3. Cek</b> — <a href="https://<?= e($dom) ?>/login.php" target="_blank" rel="noopener">https://<?= e($dom) ?>/login.php</a></td></tr>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

<?php else: ?>
    <div class="card" style="max-width:720px">
        <h2>Data grup</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="row2">
                <div class="field"><label>Nama grup</label><input name="name" value="<?= e($client['name']) ?>" required <?= $isAdmin ? '' : 'disabled' ?>></div>
                <div class="field"><label>Nama kontak</label><input name="contact_name" value="<?= e($client['contact_name']) ?>" <?= $isAdmin ? '' : 'disabled' ?>></div>
                <div class="field"><label>Email</label><input name="email" type="email" value="<?= e($client['email']) ?>" <?= $isAdmin ? '' : 'disabled' ?>></div>
                <div class="field"><label>WhatsApp / telepon</label><input name="phone" value="<?= e($client['phone']) ?>" <?= $isAdmin ? '' : 'disabled' ?>></div>
            </div>
            <div class="field"><label>Alamat</label><input name="address" value="<?= e($client['address']) ?>" <?= $isAdmin ? '' : 'disabled' ?>></div>
            <div class="field"><label>Catatan</label><input name="notes" value="<?= e($client['notes']) ?>" <?= $isAdmin ? '' : 'disabled' ?>></div>
            <?php if ($isAdmin): ?><button class="btn">Simpan</button><?php endif; ?>
        </form>
    </div>
<?php endif; ?>
<?php View::end();
