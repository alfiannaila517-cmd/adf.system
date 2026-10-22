<?php

/** Satu bisnis: tab Ringkasan · Orang & Akses · Menu & Fitur · Staff Portal · Integrasi · Langganan · Data & Backup. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();
$db = Biz::db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$biz = Biz::business($id);
if (!$biz) {
    flash('Bisnis tidak ditemukan.', 'err');
    redirect('/clients.php');
}
$tab = (string)($_GET['tab'] ?? 'ringkasan');
$isAdmin = $me['role'] === 'admin';
$money = fn ($v) => (float)str_replace(['.', ','], ['', '.'], preg_replace('/[^\d,.\-]/', '', (string)$v));
$dateOrNull = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $newPasswords = [];
    if (!csrf_ok() || !$isAdmin) {
        flash(!$isAdmin ? 'Hanya admin yang bisa mengubah bisnis.' : 'Sesi form kedaluwarsa, silakan coba lagi.', 'err');
    } elseif ($action === 'update') {
        $name = trim((string)($_POST['name'] ?? ''));
        $type = array_key_exists($_POST['type'] ?? '', Biz::TYPES) ? $_POST['type'] : 'other';
        $status = array_key_exists($_POST['status'] ?? '', Biz::STATUS) ? $_POST['status'] : 'active';
        $clientId = (int)($_POST['client_id'] ?? $biz['client_id']);
        if (mb_strlen($name) < 2 || !Biz::client($clientId)) {
            flash('Nama bisnis wajib diisi dan grup harus valid.', 'err');
        } else {
            $db->prepare('UPDATE businesses SET name = ?, type = ?, status = ?, client_id = ?, domain = ?, notes = ? WHERE id = ?')->execute([
                mb_substr($name, 0, 150), $type, $status, $clientId, Legacy::normalizeDomain((string)($_POST['domain'] ?? '')) ?: null, trim((string)($_POST['notes'] ?? '')) ?: null, $id,
            ]);
            Auth::audit('business.update', $name . ($clientId !== (int)$biz['client_id'] ? ' (pindah client)' : ''));
            try {
                // Nama, domain sendiri & status ikut ke sistem (domain → website + /admin bisnis ini)
                Legacy::syncBusiness(Biz::business($id));
                flash('Bisnis disimpan.' . (Legacy::linked($biz) ? ' ' . Legacy::label() . ' diperbarui.' : ''));
            } catch (Throwable $e) {
                flash('Bisnis disimpan di Console, TAPI: ' . $e->getMessage(), 'err');
            }
        }
    } elseif ($action === 'provision') {
        try {
            $steps = Provision::run($biz);
            Auth::audit('business.provision', $biz['name']);
            flash("{$biz['name']} dibuat di sistem: " . implode(' · ', $steps) . '.');
        } catch (Throwable $e) {
            flash('Gagal membuat di sistem: ' . $e->getMessage(), 'err');
        }
    } elseif ($action === 'database') {
        $dbName = trim((string)($_POST['db_name'] ?? ''));
        if ($dbName !== '' && !preg_match('/^[A-Za-z0-9_]{3,64}$/', $dbName)) {
            flash('Nama database hanya huruf, angka, garis bawah.', 'err');
        } else {
            $db->prepare('UPDATE businesses SET db_name = ?, db_host = ? WHERE id = ?')->execute([$dbName ?: null, trim((string)($_POST['db_host'] ?? '')) ?: null, $id]);
            Auth::audit('business.database', "{$biz['name']}: {$dbName}");
            flash('Pengaturan database disimpan.');
        }
    } elseif ($action === 'create_subscription') {
        if (!Biz::subscription($id)) {
            $key = $biz['slug'];
            for ($i = 2; $db->query('SELECT 1 FROM subscriptions WHERE client_key = ' . $db->quote($key))->fetchColumn(); $i++) {
                $key = $biz['slug'] . '-' . $i;
            }
            $db->prepare('INSERT INTO subscriptions (business_id, client_key, client_token, display_name, start_date) VALUES (?, ?, ?, ?, CURDATE())')
                ->execute([$id, $key, bin2hex(random_bytes(24)), $biz['name']]);
            Auth::audit('subscription.create', $biz['name']);
            flash('Langganan dibuat. Atur biayanya di bawah.');
        }
    } elseif ($action === 'subscription') {
        $email = trim((string)($_POST['notify_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Email notifikasi tidak valid.', 'err');
        } else {
            $db->prepare('UPDATE subscriptions SET display_name = ?, base_fee = ?, per_guest_fee = ?, start_date = ?, due_date_override = ?, notify_email = ?,
                    pakasir_slug = ?, pakasir_api_key = COALESCE(NULLIF(?, \'\'), pakasir_api_key), pakasir_webhook_secret = COALESCE(NULLIF(?, \'\'), pakasir_webhook_secret) WHERE business_id = ?')
                ->execute([
                    trim((string)($_POST['display_name'] ?? '')) ?: $biz['name'], $money($_POST['base_fee'] ?? 0), $money($_POST['per_guest_fee'] ?? 0),
                    $dateOrNull($_POST['start_date'] ?? ''), $dateOrNull($_POST['due_date_override'] ?? ''), $email ?: null,
                    trim((string)($_POST['pakasir_slug'] ?? '')) ?: null, trim((string)($_POST['pakasir_api_key'] ?? '')), trim((string)($_POST['pakasir_webhook_secret'] ?? '')), $id,
                ]);
            Auth::audit('subscription.update', $biz['name']);
            flash('Langganan disimpan.');
        }
    } elseif ($action === 'lock' || $action === 'unlock') {
        $lock = $action === 'lock';
        $db->prepare('UPDATE subscriptions SET locked = ?, locked_at = ' . ($lock ? 'NOW()' : 'NULL') . ' WHERE business_id = ?')->execute([$lock ? 1 : 0, $id]);
        Auth::audit($lock ? 'subscription.lock' : 'subscription.unlock', $biz['name']);
        Telegram::security($lock ? '🔒' : '🔓', ($lock ? 'Sistem bisnis DIKUNCI' : 'Sistem bisnis DIBUKA') . ' (Console)', ['Bisnis' => $biz['name'], 'Oleh' => $me['username']]);
        flash($lock ? 'Sistem bisnis dikunci.' : 'Kunci sistem bisnis dibuka.');
    } elseif ($action === 'modules') {
        Biz::saveModules($biz, array_map('strval', (array)($_POST['m'] ?? [])), array_map('strval', (array)($_POST['f'] ?? [])));
        Auth::audit('business.modules', $biz['name']);
        flash('Modul & fitur disimpan.');
    } elseif ($action === 'modules_reset') {
        Biz::resetModules($id);
        Auth::audit('business.modules_reset', $biz['name']);
        flash('Modul & fitur dikembalikan ke bawaan jenis bisnis.');
    } elseif ($action === 'member_add' || $action === 'member_update') {
        Access::ensureRoles($biz);
        $roles = Access::roles($id);
        $roleId = (int)($_POST['role_id'] ?? 0);
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $personId = (int)($_POST['person_id'] ?? 0);
        // Bisnis yang terhubung ke sistem lama: orang baru butuh username + password awal untuk login di sana
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $existing = null;
        if ($action === 'member_add' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $st = $db->prepare('SELECT id, username, legacy_user_id FROM people WHERE email = ?');
            $st->execute([$email]);
            $existing = $st->fetch() ?: null;
        }
        $needLogin = $action === 'member_add' && Legacy::linked($biz) && empty($existing['legacy_user_id']);
        $nameTaken = false;
        if ($needLogin && $username !== '') {
            $st = $db->prepare('SELECT 1 FROM people WHERE username = ? AND id <> ?');
            $st->execute([$username, (int)($existing['id'] ?? 0)]);
            $nameTaken = (bool)$st->fetchColumn();
            $st = Legacy::db()->prepare('SELECT 1 FROM users WHERE username = ? OR email = ?');
            $st->execute([$username, $email]);
            $nameTaken = $nameTaken || (bool)$st->fetchColumn();
        }
        if (!isset($roles[$roleId])) {
            flash('Pilih peran yang valid.', 'err');
        } elseif ($action === 'member_add' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Email tidak valid.', 'err');
        } elseif ($needLogin && !preg_match('/^[a-z0-9_.]{3,50}$/', $username)) {
            flash('Username wajib: 3–50 huruf kecil/angka/titik/garis bawah (dipakai untuk login di sistem).', 'err');
        } elseif ($needLogin && $nameTaken) {
            flash("Username {$username} atau email {$email} sudah dipakai di sistem.", 'err');
        } elseif ($needLogin && mb_strlen($password) < 8) {
            flash('Password awal minimal 8 karakter.', 'err');
        } else {
            if ($action === 'member_add') {
                $personId = Access::person((string)($_POST['name'] ?? ''), $email);
                if ($needLogin) {
                    $db->prepare('UPDATE people SET username = ? WHERE id = ?')->execute([$username, $personId]);
                    $newPasswords[$personId] = $password;
                }
            }
            Access::setMembership($id, $personId, $roleId, !empty($_POST['portal_owner']), !empty($_POST['portal_staff']));
            Auth::audit($action === 'member_add' ? 'access.add' : 'access.update', "{$biz['name']}: #{$personId} → {$roles[$roleId]['name']}");
            flash($action === 'member_add' ? 'Akses ditambahkan.' : 'Akses diperbarui.');
        }
    } elseif ($action === 'module_on') {
        // Nyalakan satu menu (dari peringatan "izin belum berlaku") tanpa mengubah pilihan lain
        $code = (string)($_POST['code'] ?? '');
        $cur = Biz::modules($biz);
        if (!array_key_exists($code, $cur['module'])) {
            flash('Menu tidak dikenal untuk jenis bisnis ini.', 'err');
        } else {
            $on = array_keys(array_filter($cur['module']));
            $on[] = $code;
            Biz::saveModules($biz, $on, array_keys(array_filter($cur['feature'])));
            Auth::audit('business.module_on', "{$biz['name']}: {$code}");
            flash('Menu ' . Catalog::MODULES[$code][0] . ' dinyalakan.');
        }
    } elseif (in_array($action, ['staff_create', 'staff_reset', 'staff_delete'], true)) {
        // Akun Staff Portal di database bisnis sistem lama
        try {
            $sdb = StaffPortal::db($biz) ?? throw new RuntimeException('Bisnis ini belum terhubung ke database sistem.');
            $emp = (int)($_POST['employee_id'] ?? 0);
            if ($action === 'staff_delete') {
                StaffPortal::delete($sdb, $emp);
                Auth::audit('staff_portal.delete', "{$biz['name']}: karyawan #{$emp}");
                flash('Akun portal dihapus — karyawan ini tidak bisa login portal lagi.');
            } else {
                $pw = ($_POST['mode'] ?? '') === 'auto' || (string)($_POST['password'] ?? '') === '' ? Legacy::generatePassword() : (string)$_POST['password'];
                $action === 'staff_create'
                    ? StaffPortal::create($sdb, $emp, (string)($_POST['username'] ?? ''), $pw)
                    : StaffPortal::resetPassword($sdb, $emp, $pw);
                Auth::audit('staff_portal.' . ($action === 'staff_create' ? 'create' : 'password'), "{$biz['name']}: karyawan #{$emp}");
                $_SESSION['shown_staff_pw'] = ['biz' => $id, 'emp' => $emp, 'pw' => $pw];
                flash($action === 'staff_create' ? 'Akun portal dibuat.' : 'Password portal diganti.');
            }
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif ($action === 'member_clear_overrides') {
        Access::clearOverrides($id, (int)($_POST['person_id'] ?? 0));
        Auth::audit('access.clear_overrides', "{$biz['name']}: #" . (int)($_POST['person_id'] ?? 0));
        flash('Pengecualian dihapus — izin mengikuti peran.');
    } elseif ($action === 'member_remove') {
        Access::removeMembership($id, (int)($_POST['person_id'] ?? 0));
        Auth::audit('access.remove', "{$biz['name']}: #" . (int)($_POST['person_id'] ?? 0));
        flash('Akses dicabut.');
    } elseif ($action === 'roles_save') {
        Access::ensureRoles($biz);
        Access::saveRolePermissions($id, (array)($_POST['lv'] ?? []));
        Auth::audit('roles.update', $biz['name']);
        flash('Izin peran disimpan.');
    } elseif ($action === 'integ') {
        $code = (string)($_POST['provider'] ?? '');
        try {
            Integration::save($biz, $code, (array)($_POST['v'] ?? []), !empty($_POST['enabled']), array_map('strval', (array)($_POST['clear'] ?? [])));
            Auth::audit('integration.save', "{$biz['name']}: {$code}");
            if (($_POST['do'] ?? '') === 'test') {
                $res = Integrations::test($code, Integration::values($biz, $code));
                Integration::recordTest($biz, $code, $res);
                Auth::audit('integration.test', "{$biz['name']}: {$code} " . ($res['ok'] ? 'OK' : 'GAGAL'));
                flash(Integrations::DEFS[$code]['label'] . ': ' . $res['msg'], $res['ok'] ? 'ok' : 'err');
            } else {
                flash(Integrations::DEFS[$code]['label'] . ' disimpan.');
            }
        } catch (Throwable $e) {
            flash('Gagal: ' . $e->getMessage(), 'err');
        }
        redirect("/business.php?id={$id}&tab=integrasi#i-{$code}");
    } elseif ($action === 'website_source') {
        // Bisnis Website: data website diambil dari database bisnis hotel yang dipilih
        $src = (int)($_POST['source_id'] ?? 0);
        $row = array_values(array_filter(Website::sources((int)$biz['client_id']), fn ($s) => (int)$s['id'] === $src))[0] ?? null;
        if ($biz['type'] !== 'website' || !$row) {
            flash('Pilih bisnis hotel sumber data website.', 'err');
        } else {
            $db->prepare('UPDATE businesses SET db_name = ? WHERE id = ?')->execute([$row['db_name'], $id]);
            Auth::audit('website.source', "{$biz['name']} ← {$row['name']}");
            flash("Website ini sekarang memakai data {$row['name']}. Buka tab Pengaturan website.");
        }
    } elseif ($action === 'web_save' || $action === 'web_room') {
        // Website publik hotel: kunci web_* di database bisnis (dibaca langsung oleh website)
        try {
            if (!Website::available($biz)) {
                throw new RuntimeException('Bisnis ini tidak punya pengaturan website.');
            }
            $wdb = Website::db($biz);
            if ($action === 'web_room') {
                $room = (string)($_POST['room'] ?? '');
                Website::saveRoomPhotos($wdb, $room, $_POST, $_FILES);
                Auth::audit('website.room_photos', "{$biz['name']}: {$room}");
                flash('Foto kamar ' . (Website::ROOMS[$room] ?? $room) . ' disimpan — langsung tampil di website.');
            } else {
                $sec = (string)($_POST['sec'] ?? '');
                $n = Website::save($wdb, $sec, $_POST, $_FILES);
                Auth::audit('website.save', "{$biz['name']}: {$sec} ({$n} perubahan)");
                flash($n ? "{$n} pengaturan website disimpan — langsung tampil di website." : 'Tidak ada yang berubah.');
            }
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif ($action === 'regenerate_token') {
        $db->prepare('UPDATE subscriptions SET client_token = ? WHERE business_id = ?')->execute([bin2hex(random_bytes(24)), $id]);
        Auth::audit('subscription.token', $biz['name']);
        flash('Token baru dibuat. Ganti juga token di pengaturan langganan sistem bisnis ini, kalau tidak sinkron akan gagal.', 'err');
    }

    // Perubahan akses langsung dikirim ke tabel sistem lama (bila bisnis ini terhubung) supaya sistem lama tetap berjalan sama
    $accessActions = ['modules', 'modules_reset', 'module_on', 'member_add', 'member_update', 'member_clear_overrides', 'member_remove', 'roles_save'];
    if (in_array($action, $accessActions, true) && ($_SESSION['flash']['type'] ?? '') === 'ok' && Legacy::linked($biz)) {
        try {
            $fresh = Biz::business($id);
            // Modul menyala/mati → menu sidebar sistem lama ikut berubah; lalu izin semua orang ditulis ulang
            $sync = in_array($action, ['modules', 'modules_reset', 'module_on'], true) ? Legacy::exportModules($fresh) : [];
            $sync = array_merge($sync, Legacy::export($fresh, $newPasswords));
            Auth::audit('legacy.export', "{$biz['name']}: " . count($sync) . ' perubahan');
            $_SESSION['flash']['msg'] .= ' ' . Legacy::label() . ' diperbarui' . ($sync ? ': ' . implode(' · ', $sync) : ' (tidak ada yang berubah).');
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['msg' => $_SESSION['flash']['msg'] . ' TAPI gagal menulis ke ' . Legacy::label() . ': ' . $e->getMessage(), 'type' => 'err'];
        }
    }

    // Kembali ke halaman yang tepat; form tambah yang gagal diisi ulang (kecuali password)
    $failed = ($_SESSION['flash']['type'] ?? '') === 'err';
    $suffix = '';
    if ($action === 'member_add' && $failed) {
        $_SESSION['old_member'] = array_intersect_key($_POST, array_flip(['name', 'email', 'username', 'role_id', 'portal_owner']));
        $suffix = '&view=tambah';
    } elseif ($action === 'member_update' && $failed) {
        $suffix = '&edit=' . (int)($_POST['person_id'] ?? 0);
    } elseif ($action === 'roles_save') {
        $suffix = '&view=peran';
    } elseif ($action === 'web_save') {
        $suffix = '&sec=' . rawurlencode((string)($_POST['sec'] ?? 'umum'));
    } elseif ($action === 'web_room') {
        $suffix = '&sec=kamar#room-' . rawurlencode((string)($_POST['room'] ?? ''));
    }
    redirect("/business.php?id={$id}&tab={$tab}{$suffix}");
}

$biz = Biz::business($id);
$sub = Biz::subscription($id);
$charges = $sub ? Biz::charges($id) : [];
$clients = $db->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();
[$typeLabel, $typeIcon] = Biz::type((string)$biz['type']);
$dis = $isAdmin ? '' : 'disabled';

View::start($biz['name'], 'clients', $me);
?>
<div class="crumb"><a href="/clients.php">Bisnis</a> › <a href="/client.php?id=<?= (int)$biz['client_id'] ?>"><?= e($biz['client_name']) ?></a> ›</div>
<h1><?= $typeIcon ?> <?= e($biz['name']) ?></h1>
<p class="muted" style="margin:0"><?= e($typeLabel) ?> · kode <code><?= e($biz['slug']) ?></code><?= $biz['domain'] ? ' · ' . e($biz['domain']) : '' ?></p>

<nav class="tabs">
    <?php // Bisnis jenis Website tidak punya login staf → hanya tab yang relevan
    $tabs = $biz['type'] === 'website'
        ? ['ringkasan' => 'Ringkasan', 'website' => 'Pengaturan website', 'langganan' => 'Langganan', 'database' => 'Data & Backup']
        : ['ringkasan' => 'Ringkasan', 'orang' => 'Orang & Akses', 'modul' => 'Menu & Fitur', 'portal' => 'Staff Portal', 'integrasi' => 'Integrasi', 'langganan' => 'Langganan', 'database' => 'Data & Backup'];
    foreach ($tabs as $k => $label): ?>
        <a class="<?= $tab === $k ? 'on' : '' ?>" href="/business.php?id=<?= $id ?>&tab=<?= $k ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'ringkasan'): ?>
    <div class="card" style="max-width:760px">
        <h2>Data bisnis</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="row2">
                <div class="field"><label>Nama bisnis</label><input name="name" value="<?= e($biz['name']) ?>" required <?= $dis ?>></div>
                <div class="field"><label>Jenis</label><select name="type" <?= $dis ?>><?php foreach (Biz::TYPES as $code => [$l, $i]): ?><option value="<?= $code ?>" <?= $biz['type'] === $code ? 'selected' : '' ?>><?= $i ?> <?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Status</label><select name="status" <?= $dis ?>><?php foreach (Biz::STATUS as $code => $l): ?><option value="<?= $code ?>" <?= $biz['status'] === $code ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Milik grup</label><select name="client_id" <?= $dis ?>><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === (int)$biz['client_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="field"><label>Domain sendiri (opsional)</label><input name="domain" value="<?= e($biz['domain']) ?>" placeholder="mis. sunseakarimunjawa.com" <?= $dis ?>></div>
            <div class="field"><label>Catatan</label><input name="notes" value="<?= e($biz['notes']) ?>" <?= $dis ?>></div>
            <?php if ($isAdmin): ?><button class="btn">Simpan</button><?php endif; ?>
        </form>
    </div>
    <?php if ($biz['type'] === 'website'): $sources = Website::sources((int)$biz['client_id']); ?>
        <div class="card" style="max-width:760px">
            <h2>Sumber data website</h2>
            <p class="muted" style="margin:0 0 8px;font-size:12px">Website hotel membaca kamar, harga, ketersediaan, dan pengaturannya dari database sistem hotel. Pilih hotelnya:</p>
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <?= csrf_field() ?><input type="hidden" name="action" value="website_source"><input type="hidden" name="id" value="<?= $id ?>">
                <select name="source_id" style="flex:1;min-width:220px" <?= $dis ?>>
                    <option value="">— pilih bisnis hotel —</option>
                    <?php foreach ($sources as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $s['db_name'] === $biz['db_name'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
                </select>
                <?php if ($isAdmin): ?><button class="btn sm">Simpan</button><?php endif; ?>
                <?php if (Website::available($biz)): ?><a class="btn ghost sm" href="/business.php?id=<?= $id ?>&tab=website">Buka pengaturan website →</a><?php endif; ?>
            </form>
            <?php if (!$sources): ?><p class="muted" style="margin:8px 0 0;font-size:12px">Belum ada bisnis hotel yang terhubung ke sistem.</p><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($biz['domain'] && $biz['legacy_business_id']):
        $dom = (string)$biz['domain'];
        $serverIp = (string)Secrets::get('SERVER_IP', $_SERVER['SERVER_ADDR'] ?? '');
        $resolved = gethostbyname($dom);
        $dnsOk = $resolved !== $dom && ($serverIp === '' || $resolved === $serverIp); ?>
        <div class="card" style="max-width:760px">
            <h2>Domain <?= e($dom) ?></h2>
            <p class="muted" style="margin:0 0 10px;font-size:12px">
                <b><?= e($dom) ?></b> → <?= $biz['type'] === 'travel' ? 'website publik bisnis ini' : 'halaman login bisnis ini' ?> · <b><?= e($dom) ?>/admin</b> → login sistem <?= e($biz['name']) ?> (bisnisnya otomatis terpilih).</p>
            <table class="sm">
                <tr><td style="width:28px"><?= $dnsOk ? '✅' : '⏳' ?></td>
                    <td><b>1. DNS</b> — di tempat domain dibeli, buat record <b>A</b> untuk <code><?= e($dom) ?></code> dan <code>www</code> → <code><?= e($serverIp ?: 'IP VPS') ?></code>
                        <br><span class="muted" style="font-size:11px">sekarang mengarah ke: <?= $resolved === $dom ? 'belum ada' : e($resolved) ?><?= $dnsOk ? ' — sudah benar' : '' ?></span></td></tr>
                <tr><td>🖥️</td><td><b>2. Pasang di server</b> (sekali, setelah DNS benar) — jalankan di VPS:<br><code>sudo bash /srv/adf/app/ops/add-domain.sh <?= e($dom) ?></code>
                    <br><span class="muted" style="font-size:11px">Membuat pintu Nginx untuk domain ini + sertifikat SSL (https) otomatis.</span></td></tr>
                <tr><td>🌐</td><td><b>3. Cek</b> — buka <a href="https://<?= e($dom) ?>" target="_blank" rel="noopener">https://<?= e($dom) ?></a> dan <a href="https://<?= e($dom) ?>/admin" target="_blank" rel="noopener">https://<?= e($dom) ?>/admin</a></td></tr>
            </table>
        </div>
    <?php endif; ?>

<?php elseif ($tab === 'website' && !Website::available($biz)): ?>
    <div class="soonbox"><?= $biz['type'] === 'website' ? 'Pilih dulu <b>sumber data website</b> di tab <a href="/business.php?id=' . $id . '">Ringkasan</a>.' : 'Pengaturan website ada di bisnis jenis <b>Website</b> (mis. "Website ' . e($biz['name']) . '") di grup yang sama.' ?></div>

<?php elseif ($tab === 'website'):
    $sec =isset(Website::SECTIONS[$_GET['sec'] ?? '']) || ($_GET['sec'] ?? '') === 'kamar' ? (string)$_GET['sec'] : 'umum';
    $wErr = null;
    try {
        $w = Website::values(Website::db($biz));
    } catch (Throwable $e) {
        $wErr = $e->getMessage();
    }
    $cloud = Website::cloudinaryReady();
    // Satu kolom isian sesuai jenisnya (gambar: pratinjau + unggah / tempel URL + hapus)
    $field = function (string $key, array $f) use (&$w, $dis, $cloud): void {
        [$label, $kind, , $hint] = $f;
        $val = (string)($w[$key] ?? '');
        $wide = in_array($kind, ['textarea', 'image'], true);
        echo '<div class="field" style="' . ($wide ? 'grid-column:1/-1' : '') . '"><label>' . e($label) . '</label>';
        if ($kind === 'textarea') {
            echo '<textarea name="' . e($key) . '" rows="2" ' . $dis . '>' . e($val) . '</textarea>';
        } elseif ($kind === 'toggle') {
            echo '<label style="display:flex;gap:6px;align-items:center;margin:0;color:var(--ink)"><input type="checkbox" name="' . e($key) . '" value="1" style="width:auto" ' . ($val === '1' ? 'checked' : '') . ' ' . $dis . '> aktif</label>';
        } elseif ($kind === 'image') {
            $url = Website::imageUrl($val);
            echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
            echo $url ? '<img src="' . e($url) . '" alt="" style="height:54px;max-width:120px;object-fit:cover;border-radius:7px;border:1px solid var(--line)">' : '<span class="pill">belum ada</span>';
            if ($cloud) {
                echo '<input type="file" name="' . e($key) . '" accept="image/*" style="width:auto;flex:1;min-width:180px" ' . $dis . '>';
            }
            echo '<input name="' . e($key) . '" placeholder="atau tempel URL gambar https://…" style="flex:2;min-width:200px" ' . $dis . '>';
            if ($url) {
                echo '<label style="display:flex;gap:4px;align-items:center;margin:0;font-size:11.5px"><input type="checkbox" name="remove[' . e($key) . ']" value="1" style="width:auto" ' . $dis . '> hapus</label>';
            }
            echo '</div>';
        } else {
            $type = ['time' => 'time', 'number' => 'number', 'color' => 'color'][$kind] ?? 'text';
            echo '<input type="' . $type . '" name="' . e($key) . '" value="' . e($val) . '" ' . ($kind === 'color' ? 'style="height:32px;padding:2px"' : '') . ' ' . $dis . '>';
        }
        echo $hint !== '' ? '<div class="muted" style="font-size:11px;margin-top:2px">' . e($hint) . '</div>' : '';
        echo '</div>';
    }; ?>
    <?php if ($wErr): ?>
        <div class="soonbox"><?= e($wErr) ?></div>
    <?php else: ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
            <div class="subtabs" style="margin:0">
                <?php foreach (array_map(fn ($s) => $s[0], Website::SECTIONS) as $k => $l): ?>
                    <a class="<?= $sec === $k ? 'on' : '' ?>" href="/business.php?id=<?= $id ?>&tab=website&sec=<?= $k ?>"><?= e($l) ?></a>
                <?php endforeach; ?>
            </div>
            <span class="pill <?= $w['web_enabled'] === '1' ? 'ok' : 'warn' ?>"><?= $w['web_enabled'] === '1' ? 'website aktif' : 'website dimatikan' ?></span>
        </div>
        <?php if (!$cloud): ?>
            <p class="muted" style="margin:0 0 10px;font-size:11.5px">Unggah foto langsung belum diatur di server — sementara tempel alamat (URL) gambar. Foto lama tetap tampil.</p>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="card">
            <?= csrf_field() ?><input type="hidden" name="action" value="web_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="sec" value="<?= e($sec) ?>">
            <div class="row2" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">
                <?php foreach (Website::SECTIONS[$sec][1] as $key => $f) { $field($key, $f); } ?>
            </div>
            <?php if ($isAdmin): ?><button class="btn" style="margin-top:10px">Simpan <?= e(mb_strtolower(Website::SECTIONS[$sec][0])) ?></button><?php endif; ?>
            <?php if ($sec === 'kamar'): ?><p class="muted" style="margin:8px 0 0;font-size:11.5px">Nama, harga, dan jumlah kamar diatur dari <b>Front Desk → Pengaturan</b> di sistem; di sini hanya deskripsi & foto untuk website.</p><?php endif; ?>
        </form>

        <?php if ($sec === 'kamar'): ?>
            <?php foreach (Website::ROOMS as $code => $rLabel):
                $gal = json_decode($w["web_room_gallery_{$code}"] ?? '[]', true) ?: [];
                $primary = (string)($w["web_room_primary_{$code}"] ?? ''); ?>
                <form method="post" enctype="multipart/form-data" class="card" id="room-<?= e($code) ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="web_room"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="room" value="<?= e($code) ?>">
                    <h2>Foto kamar <?= e($rLabel) ?> <span class="muted" style="text-transform:none;letter-spacing:0">· <?= count($gal) ?> foto</span></h2>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
                        <?php foreach ($gal as $u): $src = Website::imageUrl((string)$u); ?>
                            <div style="width:116px;font-size:11px">
                                <img src="<?= e((string)$src) ?>" alt="" style="width:116px;height:78px;object-fit:cover;border-radius:7px;border:2px solid <?= $u === $primary ? 'var(--brand)' : 'var(--line)' ?>">
                                <label style="display:flex;gap:4px;align-items:center;margin:2px 0 0"><input type="radio" name="primary" value="<?= e((string)$u) ?>" style="width:auto" <?= $u === $primary ? 'checked' : '' ?> <?= $dis ?>> utama</label>
                                <label style="display:flex;gap:4px;align-items:center;margin:0"><input type="checkbox" name="remove_photo[]" value="<?= e((string)$u) ?>" style="width:auto" <?= $dis ?>> hapus</label>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$gal): ?><span class="muted" style="font-size:12px">Belum ada foto.</span><?php endif; ?>
                    </div>
                    <?php if ($isAdmin): ?>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                            <?php if ($cloud): ?><input type="file" name="photos[]" accept="image/*" multiple style="width:auto;flex:1;min-width:200px"><?php endif; ?>
                            <input name="photo_url" placeholder="atau tempel URL foto https://…" style="flex:2;min-width:200px">
                            <button class="btn sm">Simpan foto <?= e($rLabel) ?></button>
                        </div>
                    <?php endif; ?>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

<?php elseif ($tab === 'langganan'): ?>
    <?php if (!$sub): ?>
        <div class="soonbox">Bisnis ini belum punya langganan.
            <?php if ($isAdmin): ?><form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="create_subscription"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn">Buat langganan</button></form><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="row2" style="margin-bottom:14px">
            <div class="card"><h2>Status sistem</h2>
                <div style="font-size:15px;font-weight:600;color:<?= $sub['locked'] ? 'var(--bad)' : 'var(--ok)' ?>"><?= $sub['locked'] ? '🔒 Terkunci sejak ' . e(date('d M Y H:i', strtotime((string)$sub['locked_at']))) : '● Aktif' ?></div>
                <?php if ($isAdmin): ?>
                    <form method="post" style="margin-top:10px" onsubmit="return confirm('<?= $sub['locked'] ? 'Buka kunci sistem bisnis ini?' : 'Kunci sistem bisnis ini? Staf tidak bisa memakai sistem sampai dibuka.' ?>')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="<?= $sub['locked'] ? 'unlock' : 'lock' ?>"><input type="hidden" name="id" value="<?= $id ?>">
                        <button class="btn <?= $sub['locked'] ? '' : 'danger' ?> sm"><?= $sub['locked'] ? '🔓 Buka kunci' : '🔒 Kunci sistem' ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card"><h2>Tarif</h2>
                <div style="font-size:15px;font-weight:600"><?= Biz::rp($sub['base_fee']) ?> <span class="muted" style="font-size:13px;font-weight:400">/ bulan</span></div>
                <p class="muted" style="margin:4px 0 0;font-size:13px"><?= (float)$sub['per_guest_fee'] > 0 ? '+ ' . Biz::rp($sub['per_guest_fee']) . ' per tamu' : 'tanpa tarif per tamu' ?></p>
            </div>
        </div>

        <div class="card">
            <h2>Riwayat tagihan</h2>
            <?php if (!$charges): ?>
                <p class="muted" style="margin:0">Belum ada tagihan.</p>
            <?php else: ?>
                <table class="stack">
                    <thead><tr><th>Periode</th><th>Rincian</th><th>Jatuh tempo</th><th>Status</th><th style="text-align:right">Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($charges as $ch): $guests = (int)$ch['guest_count'] + (int)$ch['carry_guests']; ?>
                            <tr>
                                <td><?= e($ch['period']) ?><?= $ch['kind'] === 'topup' ? ' <span class="pill">susulan</span>' : '' ?></td>
                                <td class="muted" style="font-size:13px"><?= Biz::rp($ch['base_fee']) ?><?= $guests ? " + {$guests} tamu × " . Biz::rp($ch['per_guest_fee']) : '' ?><?= (float)$ch['adjustment'] != 0 ? ' ' . ((float)$ch['adjustment'] > 0 ? '+' : '−') . ' ' . Biz::rp(abs((float)$ch['adjustment'])) : '' ?><?= $ch['note'] ? ' · ' . e($ch['note']) : '' ?></td>
                                <td><?= $ch['due_date'] ? e(date('d M Y', strtotime($ch['due_date']))) : '-' ?></td>
                                <td><span class="pill <?= $ch['status'] === 'paid' ? 'ok' : ($ch['status'] === 'unpaid' ? 'warn' : '') ?>"><?= ['paid' => 'Lunas', 'unpaid' => 'Belum lunas', 'cancelled' => 'Batal'][$ch['status']] ?? e($ch['status']) ?></span></td>
                                <td style="text-align:right;font-weight:600"><?= Biz::rp(Biz::chargeTotal($ch)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <details class="card">
            <summary style="cursor:pointer;font-weight:600">Pengaturan langganan</summary>
            <form method="post" style="margin-top:14px">
                <?= csrf_field() ?><input type="hidden" name="action" value="subscription"><input type="hidden" name="id" value="<?= $id ?>">
                <div class="row2">
                    <div class="field"><label>Nama di sistem bisnis</label><input name="display_name" value="<?= e($sub['display_name']) ?>" <?= $dis ?>></div>
                    <div class="field"><label>Biaya dasar / bulan (Rp)</label><input name="base_fee" inputmode="numeric" value="<?= e(number_format((float)$sub['base_fee'], 0, ',', '.')) ?>" <?= $dis ?>></div>
                    <div class="field"><label>Tarif per tamu (Rp)</label><input name="per_guest_fee" inputmode="numeric" value="<?= e(number_format((float)$sub['per_guest_fee'], 0, ',', '.')) ?>" <?= $dis ?>></div>
                    <div class="field"><label>Mulai langganan</label><input name="start_date" type="date" value="<?= e($sub['start_date']) ?>" <?= $dis ?>></div>
                    <div class="field"><label>Ubah jatuh tempo bulan ini</label><input name="due_date_override" type="date" value="<?= e($sub['due_date_override']) ?>" <?= $dis ?>></div>
                    <div class="field"><label>Email notifikasi</label><input name="notify_email" type="email" value="<?= e($sub['notify_email']) ?>" <?= $dis ?>></div>
                </div>
                <h2 style="margin-top:8px">Pakasir (pembayaran online bisnis ini)</h2>
                <div class="row2">
                    <div class="field"><label>Slug proyek</label><input name="pakasir_slug" value="<?= e($sub['pakasir_slug']) ?>" <?= $dis ?>></div>
                    <div class="field"><label>API key <?= $sub['pakasir_api_key'] ? '<span class="pill ok">tersimpan</span>' : '' ?></label><input name="pakasir_api_key" type="password" placeholder="<?= $sub['pakasir_api_key'] ? 'kosongkan = tetap' : '' ?>" autocomplete="off" <?= $dis ?>></div>
                    <div class="field"><label>Webhook secret <?= $sub['pakasir_webhook_secret'] ? '<span class="pill ok">tersimpan</span>' : '' ?></label><input name="pakasir_webhook_secret" type="password" placeholder="<?= $sub['pakasir_webhook_secret'] ? 'kosongkan = tetap' : '' ?>" autocomplete="off" <?= $dis ?>></div>
                </div>
                <?php if ($isAdmin): ?><button class="btn">Simpan pengaturan</button><?php endif; ?>
            </form>
        </details>

        <details class="card">
            <summary style="cursor:pointer;font-weight:600">Kunci sinkron sistem bisnis (client key & token)</summary>
            <p class="muted" style="font-size:13px">Dua nilai ini diisi di pengaturan langganan sistem bisnis supaya bisa mengecek status ke Console.</p>
            <dl class="kv"><dt>Client key</dt><dd><code><?= e($sub['client_key']) ?></code></dd><dt>Client token</dt><dd><code><?= $isAdmin ? e($sub['client_token']) : '••••••' ?></code></dd></dl>
            <?php if ($isAdmin): ?>
                <form method="post" style="margin-top:12px" onsubmit="return confirm('Buat token baru? Sistem bisnis ini tidak bisa sinkron sampai token barunya dipasang di sana.')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="regenerate_token"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn ghost sm">Buat token baru</button>
                </form>
            <?php endif; ?>
        </details>
    <?php endif; ?>

<?php elseif ($tab === 'database'): ?>
    <?php if (Provision::can($biz)): ?>
        <div class="card" style="max-width:760px;border-color:var(--gold2)">
            <h2>Belum ada di sistem</h2>
            <p class="muted" style="margin:0 0 10px;font-size:12px">Satu klik: daftarkan bisnis di sistem, buat database + tabel + data awal, file config, lalu aktifkan dan hubungkan ke Console (menu & akses user ikut terkirim). Berlaku di <?= e(Legacy::label()) ?>.</p>
            <?php if ($isAdmin): ?>
                <form method="post" onsubmit="return confirm('Buat <?= e($biz['name']) ?> di sistem sekarang?')"><?= csrf_field() ?><input type="hidden" name="action" value="provision"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn">⚡ Buat di sistem sekarang</button></form>
            <?php endif; ?>
        </div>
    <?php elseif ($biz['legacy_business_id']): ?>
        <div class="card" style="max-width:760px"><h2>Status</h2><span class="pill ok">terhubung ke <?= e(Legacy::label()) ?></span> <span class="muted" style="font-size:12px">· database <code><?= e($biz['db_name']) ?></code></span></div>
    <?php endif; ?>
    <div class="card" style="max-width:760px">
        <h2>Database</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="database"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="row2">
                <div class="field"><label>Nama database</label><input name="db_name" value="<?= e($biz['db_name']) ?>" placeholder="<?= $biz['type'] === 'website' ? 'website tidak wajib punya database' : 'mis. adf_narayana_hotel' ?>" <?= $dis ?>></div>
                <div class="field"><label>Server database</label><input name="db_host" value="<?= e($biz['db_host']) ?>" placeholder="kosong = server utama" <?= $dis ?>></div>
            </div>
            <?php if ($isAdmin): ?><button class="btn">Simpan</button><?php endif; ?>
        </form>
    </div>
    <?php $bk = ServerHealth::backupStatus(); ?>
    <div class="card" style="max-width:760px">
        <h2>Backup</h2>
        <?php if ($bk === null): ?>
            <p class="muted" style="margin:0;font-size:13px">Status backup belum terbaca dari server.</p>
        <?php else: ?>
            <p style="margin:0 0 8px;font-weight:600;color:<?= !empty($bk['ok']) ? 'var(--ok)' : 'var(--bad)' ?>"><?= !empty($bk['ok']) ? 'Backup harian berjalan' : 'Backup terakhir gagal: ' . e((string)($bk['message'] ?? '')) ?></p>
            <dl class="kv">
                <dt>Terakhir</dt><dd><?= e(date('d M Y H:i', strtotime((string)($bk['finished'] ?? 'now')))) ?> <span class="muted">(<?= (int)$bk['age_h'] ?> jam lalu)</span></dd>
                <dt>Isi</dt><dd>semua database (termasuk <?= $biz['db_name'] ? '<code>' . e($biz['db_name']) . '</code>' : 'bisnis ini' ?>) setiap malam · foto & upload tiap Minggu</dd>
                <dt>Disimpan</dt><dd>7 harian · 4 mingguan · 3 bulanan di VPS + salinan terenkripsi di luar VPS</dd>
            </dl>
        <?php endif; ?>
        <p class="muted" style="margin:10px 0 0;font-size:12px">Memulihkan data dilakukan lewat server (SSH) dengan <code>ops/legacy-db.sh import … --live</code>, supaya tidak bisa dipicu dari browser.</p>
    </div>

<?php elseif ($tab === 'modul'): $mods = Biz::modules($biz); ?>
    <p class="muted" style="margin:0 0 12px">Menu yang tampil di sistem <?= e($biz['name']) ?> dan fitur tambahannya. Yang dimatikan di sini tidak bisa dibuka siapa pun di bisnis ini, apa pun perannya.
        <?= $mods['custom'] ? '<span class="pill gold">diatur khusus</span>' : '<span class="pill">bawaan jenis ' . e($typeLabel) . '</span>' ?></p>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="modules"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr))">
            <?php foreach (Catalog::GROUPS as $g => $gLabel):
                $inGroup = array_filter($mods['module'], fn ($on, $code) => Catalog::MODULES[$code][1] === $g, ARRAY_FILTER_USE_BOTH);
                if (!$inGroup) continue; ?>
                <div class="card" style="margin:0">
                    <h2><?= e($gLabel) ?></h2>
                    <?php foreach ($inGroup as $code => $on): $required = Catalog::MODULES[$code][4]; ?>
                        <label style="display:flex;gap:8px;align-items:center;color:var(--ink);font-size:13px;margin:0 0 7px">
                            <input type="checkbox" name="m[]" value="<?= e($code) ?>" style="width:auto" <?= $on ? 'checked' : '' ?> <?= $required || !$isAdmin ? 'disabled' : '' ?>>
                            <?= e(Catalog::MODULES[$code][0]) ?><?= $required ? ' <span class="pill">wajib</span>' : '' ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($mods['feature']): ?>
            <div class="card" style="margin-top:12px">
                <h2>Fitur</h2>
                <?php foreach ($mods['feature'] as $code => $on): [$fLabel, $fDesc] = Catalog::FEATURES[$code]; ?>
                    <label style="display:flex;gap:10px;align-items:flex-start;color:var(--ink);font-size:13px;margin:0 0 9px">
                        <input type="checkbox" name="f[]" value="<?= e($code) ?>" style="width:auto;margin-top:3px" <?= $on ? 'checked' : '' ?> <?= $isAdmin ? '' : 'disabled' ?>>
                        <span><b style="font-weight:600"><?= e($fLabel) ?></b><br><span class="muted" style="font-size:12px"><?= e($fDesc) ?></span></span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($isAdmin): ?><button class="btn" style="margin-top:4px">Simpan modul & fitur</button><?php endif; ?>
    </form>
    <?php if ($isAdmin && $mods['custom']): ?>
        <form method="post" style="margin-top:10px" onsubmit="return confirm('Kembalikan semua modul & fitur ke bawaan jenis bisnis?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="modules_reset"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn ghost sm">Kembalikan ke bawaan</button>
        </form>
    <?php endif; ?>
<?php elseif ($tab === 'orang'):
    Access::ensureRoles($biz);
    $roles = Access::roles($id);
    $acc = Access::members($biz);
    $enabledMods = array_keys(array_filter(Biz::modules($biz)['module']));
    $linked = Legacy::linked($biz);
    $view = (string)($_GET['view'] ?? '');
    $editId = (int)($_GET['edit'] ?? 0);
    $show = (int)($_GET['person'] ?? 0);
    $old = $_SESSION['old_member'] ?? [];
    unset($_SESSION['old_member']);
    $base = "/business.php?id={$id}&tab=orang";
    // Menu yang tersedia untuk jenis bisnis ini tapi sedang MATI di Modul & Fitur → izin ke menu itu tidak berlaku
    $offMods = array_keys(array_filter(Biz::modules($biz)['module'], fn ($on) => !$on));
    $offFor = function (array $perms, bool $isOwner, array $over = []) use ($offMods): array {
        if ($isOwner) {
            return [];
        }
        return array_values(array_filter($offMods, fn ($code) => (isset($over[$code]) ? (int)$over[$code] : Catalog::levelOf($perms, $code)) > 0));
    };
    $modName = fn (string $code): string => Catalog::MODULES[$code][0] ?? $code;
    // Tombol satu klik: nyalakan menu yang dimatikan
    $moduleOnButton = function (string $code) use ($id, $modName, $isAdmin): void {
        if (!$isAdmin) {
            return;
        } ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="module_on"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="code" value="<?= e($code) ?>"><button class="btn sm">Nyalakan <?= e($modName($code)) ?></button></form>
    <?php };
    // Ringkasan izin peran: "8 menu · Kelola 5 · Input 3"
    $roleSummary = function (array $r) use ($enabledMods, $offFor, $modName): string {
        $count = array_fill_keys(array_keys(Catalog::LEVELS), 0);
        foreach ($enabledMods as $code) {
            $count[$r['is_owner'] ? Catalog::MAX_LEVEL : Catalog::levelOf($r['perms'], $code)]++;
        }
        $open = array_sum($count) - $count[0];
        $parts = [];
        foreach (array_reverse(Catalog::LEVELS, true) as $lv => $label) {
            if ($lv > 0 && $count[$lv]) {
                $parts[] = "{$label} {$count[$lv]}";
            }
        }
        $off = $offFor($r['perms'], (bool)$r['is_owner']);
        return ($open ? "{$open} menu · " . implode(' · ', $parts) : 'tidak ada menu')
            . ($off ? ' · ⚠ tidak berlaku karena menunya mati: ' . implode(', ', array_map($modName, $off)) : '');
    };
    // Pilihan peran berupa kartu (lebih jelas daripada dropdown)
    $rolePicker = function (int $selected) use ($roles, $roleSummary): void {
        foreach ($roles as $rid => $r): ?>
            <label style="display:flex;gap:10px;align-items:flex-start;border:1px solid var(--line);border-radius:10px;padding:9px 12px;margin:0 0 6px;cursor:pointer;color:var(--ink)">
                <input type="radio" name="role_id" value="<?= $rid ?>" data-owner="<?= $r['is_owner'] ? 1 : 0 ?>" style="width:auto;margin-top:3px" <?= $rid === $selected ? 'checked' : '' ?> required>
                <span><b style="font-weight:600"><?= e($r['name']) ?></b><?= $r['is_owner'] ? ' <span class="pill gold">akses penuh</span>' : '' ?>
                    <br><span class="muted" style="font-size:12px"><?= e((string)$r['description']) ?> — <?= e($roleSummary($r)) ?></span></span>
            </label>
        <?php endforeach;
    };
    $ownerConfirm = "onsubmit=\"var r=this.querySelector('input[name=role_id]:checked');return !r||r.dataset.owner!=='1'||confirm('Peran Owner = akses PENUH ke semua menu bisnis ini. Lanjutkan?')\""; ?>

    <nav style="display:flex;gap:6px;margin:0 0 12px">
        <a class="btn <?= $view === 'peran' ? 'ghost' : '' ?> sm" href="<?= $base ?>">Orang</a>
        <a class="btn <?= $view === 'peran' ? '' : 'ghost' ?> sm" href="<?= $base ?>&view=peran">Peran & izin</a>
    </nav>
    <?php if ($linked): ?>
        <div class="soonbox" style="margin-bottom:12px;padding:10px 14px;font-size:13px">🔗 Terhubung ke <b><?= e(Legacy::label()) ?></b> — perubahan di sini langsung berlaku di sana. Sistem asli di hosting <b>tidak tersentuh</b>.</div>
    <?php endif; ?>

    <?php if ($view === 'peran'): ?>
        <div class="card">
            <h2>Izin per peran</h2>
            <p class="muted" style="margin:0 0 10px;font-size:12px">Hanya menu yang menyala di <?= e($biz['name']) ?> yang tampil. Mengubah izin peran berlaku untuk semua orang berperan itu di bisnis ini saja.</p>
            <form method="post" style="overflow-x:auto" onsubmit="return confirm('Simpan izin peran? Berlaku untuk semua orang dengan peran tersebut.')">
                <?= csrf_field() ?><input type="hidden" name="action" value="roles_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="peran">
                <table>
                    <thead><tr><th>Menu</th><?php foreach ($roles as $r): ?><th><?= e($r['name']) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                        <?php foreach ($enabledMods as $code): ?>
                            <tr>
                                <td><?= e(Catalog::MODULES[$code][0]) ?></td>
                                <?php foreach ($roles as $rid => $r): $lv = Catalog::levelOf($r['perms'], $code); ?>
                                    <td>
                                        <?php if ($r['is_owner']): ?>
                                            <span class="pill ok">Kelola</span>
                                        <?php else: ?>
                                            <select name="lv[<?= $rid ?>][<?= e($code) ?>]" style="width:auto;padding:4px 6px" <?= $dis ?>><?php foreach (Catalog::LEVELS as $v => $l): ?><option value="<?= $v ?>" <?= $v === $lv ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($isAdmin): ?><button class="btn" style="margin-top:10px">Simpan izin peran</button><?php endif; ?>
            </form>
            <p class="muted" style="margin:10px 0 0;font-size:12px">Tidak = menu tidak tampil · Lihat = hanya melihat · Input = melihat & menambah · Ubah = melihat, menambah, mengubah (tanpa hapus) · Kelola = semuanya termasuk menghapus.</p>
        </div>

    <?php elseif ($view === 'tambah' && $isAdmin): ?>
        <div class="card" style="max-width:760px">
            <h2>Tambah orang ke <?= e($biz['name']) ?></h2>
            <?php if (!empty($old)): ?><div style="border:1px solid var(--bad);border-radius:8px;padding:8px 12px;margin:0 0 12px;color:var(--bad);font-size:13px">Belum tersimpan — periksa pesan merah di atas, lalu perbaiki isiannya.</div><?php endif; ?>
            <form method="post" autocomplete="off" novalidate <?= $ownerConfirm ?>>
                <?= csrf_field() ?><input type="hidden" name="action" value="member_add"><input type="hidden" name="id" value="<?= $id ?>">
                <p style="margin:4px 0 8px;font-weight:600">1. Data orang</p>
                <div class="row2">
                    <div class="field"><label>Nama lengkap</label><input name="name" value="<?= e($old['name'] ?? '') ?>" required></div>
                    <div class="field"><label>Email</label><input name="email" type="email" value="<?= e($old['email'] ?? '') ?>" required></div>
                </div>
                <?php if ($linked): ?>
                    <p style="margin:4px 0 8px;font-weight:600">2. Akun login sistem</p>
                    <div class="row2">
                        <div class="field"><label>Username *</label><input name="username" value="<?= e($old['username'] ?? '') ?>" placeholder="mis. rina.fo (huruf kecil, tanpa spasi)" autocapitalize="none"></div>
                        <div class="field"><label>Password awal *</label><input name="password" type="text" placeholder="min. 8 karakter" autocomplete="off"></div>
                    </div>
                    <p class="muted" style="margin:-4px 0 10px;font-size:12px"><b>Wajib untuk orang baru</b> — dipakai untuk login di sistem. Boleh kosong hanya bila orang ini sudah punya akun di bisnis lain grup ini (email sama).</p>
                <?php endif; ?>
                <p style="margin:4px 0 8px;font-weight:600"><?= $linked ? 3 : 2 ?>. Peran di <?= e($biz['name']) ?></p>
                <?php $defaultRole = (int)($old['role_id'] ?? 0) ?: (int)(array_search('staff', array_column(array_map(fn ($r) => ['id' => (int)$r['id'], 'code' => $r['code']], $roles), 'code', 'id'), true) ?: array_key_last($roles)); $rolePicker($defaultRole); ?>
                <label style="display:flex;gap:8px;align-items:center;margin:10px 0 14px;color:var(--ink)"><input type="checkbox" name="portal_owner" value="1" style="width:auto" <?= !empty($old['portal_owner']) ? 'checked' : '' ?>> Boleh masuk lewat <b>Owner Login</b> <span class="muted" style="font-size:12px">(pintu khusus pemilik / admin)</span></label>
                <button class="btn">Tambah orang</button> <a class="btn ghost" href="<?= $base ?>">Batal</a>
            </form>
        </div>

    <?php elseif ($editId && $isAdmin && ($m = array_values(array_filter($acc['members'], fn ($m) => (int)$m['person_id'] === $editId))[0] ?? null)): ?>
        <div class="card" style="max-width:760px">
            <h2>Ubah akses · <?= e($m['name']) ?></h2>
            <p class="muted" style="margin:-4px 0 12px;font-size:13px"><?= e($m['username'] ?: '') ?><?= $m['username'] ? ' · ' : '' ?><?= e($m['email']) ?></p>
            <form method="post" <?= $ownerConfirm ?>>
                <?= csrf_field() ?><input type="hidden" name="action" value="member_update"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="person_id" value="<?= (int)$m['person_id'] ?>">
                <p style="margin:0 0 8px;font-weight:600">Peran di <?= e($biz['name']) ?></p>
                <?php $rolePicker((int)$m['role_id']); ?>
                <label style="display:flex;gap:8px;align-items:center;margin:10px 0 14px;color:var(--ink)"><input type="checkbox" name="portal_owner" value="1" style="width:auto" <?= $m['portal_owner'] ? 'checked' : '' ?>> Boleh masuk lewat <b>Owner Login</b></label>
                <?php if (count(json_decode((string)($m['overrides'] ?? ''), true) ?: [])): ?>
                    <p class="muted" style="margin:0 0 12px;font-size:12px">Orang ini punya pengecualian izin dari sistem lama; pengecualian tetap berlaku setelah ganti peran (hapus lewat tombol Izin).</p>
                <?php endif; ?>
                <button class="btn">Simpan perubahan</button> <a class="btn ghost" href="<?= $base ?>">Batal</a>
            </form>
        </div>

    <?php else:
        // Siapa yang punya izin ke menu yang sedang mati (izin itu tidak berlaku sampai menunya dinyalakan)
        $blocked = [];
        foreach ($acc['members'] as $m) {
            $r = $roles[(int)$m['role_id']] ?? null;
            foreach ($r ? $offFor($r['perms'], (bool)$r['is_owner'], json_decode((string)($m['overrides'] ?? ''), true) ?: []) : [] as $code) {
                $blocked[$code][] = $m['name'];
            }
        } ?>
        <?php if ($blocked): ?>
            <div class="card" style="border-color:var(--warn)">
                <h2 style="color:var(--warn)">⚠ Ada izin yang belum berlaku</h2>
                <p class="muted" style="margin:0 0 10px;font-size:13px">Menu di bawah ini <b>dimatikan</b> di <a href="/business.php?id=<?= $id ?>&tab=modul">Modul & Fitur</a>, jadi tidak tampil untuk siapa pun walaupun perannya mengizinkan. Nyalakan bila memang dipakai:</p>
                <?php foreach ($blocked as $code => $names): ?>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 8px">
                        <?php $moduleOnButton($code); ?>
                        <span class="muted" style="font-size:12px">diizinkan untuk: <?= e(implode(', ', array_unique($names))) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:6px">
                <h2 style="margin:0">Orang yang punya akses (<?= count($acc['owners']) + count($acc['members']) ?>)</h2>
                <?php if ($isAdmin): ?><a class="btn sm" href="/people.php?view=tambah&biz=<?= $id ?>">+ Tambah user</a><?php endif; ?>
            </div>
            <table class="stack">
                <thead><tr><th>Orang</th><th>Peran</th><th>Bisa masuk lewat</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($acc['owners'] as $o): ?>
                        <tr>
                            <td><a href="/person.php?id=<?= (int)$o['person_id'] ?>" style="color:var(--ink)"><b><?= e($o['name']) ?></b></a><br><span class="muted" style="font-size:12px"><?= e($o['email']) ?></span></td>
                            <td><span class="pill gold">Pemilik grup</span></td>
                            <td><span class="pill">Sistem</span> <span class="pill">Owner Login</span></td>
                            <td class="muted" style="font-size:12px;text-align:right">diatur di <a href="/client.php?id=<?= (int)$biz['client_id'] ?>&tab=orang">grup</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($acc['members'] as $m): $ov = count(json_decode((string)($m['overrides'] ?? ''), true) ?: []); ?>
                        <tr>
                            <td><a href="/person.php?id=<?= (int)$m['person_id'] ?>" style="color:var(--ink)"><b><?= e($m['name']) ?></b></a><?= $m['status'] === 'invited' ? ' <span class="pill warn">diundang</span>' : '' ?><?= $m['status'] === 'disabled' ? ' <span class="pill">nonaktif</span>' : '' ?>
                                <br><span class="muted" style="font-size:12px"><?= $m['username'] ? e($m['username']) . ' · ' : '' ?><?= e($m['email']) ?></span></td>
                            <?php $mOff = ($r = $roles[(int)$m['role_id']] ?? null) ? $offFor($r['perms'], (bool)$r['is_owner'], json_decode((string)($m['overrides'] ?? ''), true) ?: []) : []; ?>
                            <td><span class="pill <?= $m['is_owner'] ? 'gold' : '' ?>"><?= e($m['role_name']) ?></span><?= $ov ? ' <span class="pill" title="Izin khusus orang ini yang berbeda dari perannya">+' . $ov . ' pengecualian</span>' : '' ?>
                                <?= $mOff ? '<br><span class="pill warn" style="margin-top:4px" title="Menu ini mati di Modul &amp; Fitur">⚠ belum tampil: ' . e(implode(', ', array_map($modName, $mOff))) . '</span>' : '' ?></td>
                            <td><span class="pill">Sistem</span><?= $m['portal_owner'] ? ' <span class="pill ok">Owner Login</span>' : '' ?></td>
                            <td style="text-align:right;white-space:nowrap">
                                <?php if ($isAdmin): ?><a class="btn ghost sm" href="/person.php?id=<?= (int)$m['person_id'] ?>&tab=akses">Edit</a><?php endif; ?>
                                <a class="btn ghost sm" href="/person.php?id=<?= (int)$m['person_id'] ?>&tab=menu&biz=<?= $id ?>">Menu</a>
                                <?php if ($isAdmin): ?>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Cabut akses <?= e($m['name']) ?> dari <?= e($biz['name']) ?>?<?= $linked ? ' Orang ini tidak bisa lagi membuka bisnis ini.' : '' ?>')"><?= csrf_field() ?><input type="hidden" name="action" value="member_remove"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="person_id" value="<?= (int)$m['person_id'] ?>"><button class="btn danger sm">Cabut</button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$acc['owners'] && !$acc['members']): ?><tr><td colspan="4" class="muted">Belum ada orang yang punya akses.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($show && ($p = array_values(array_filter($acc['members'], fn ($m) => (int)$m['person_id'] === $show))[0] ?? null)): $eff = Access::effective($biz, $show); ?>
            <div class="card" id="izin">
                <h2>Izin akhir · <?= e($p['name']) ?> (<?= e($p['role_name']) ?>)</h2>
                <?php $over = json_decode((string)($p['overrides'] ?? ''), true) ?: [];
                $pr = $roles[(int)$p['role_id']] ?? null;
                foreach ($pr ? $offFor($pr['perms'], (bool)$pr['is_owner'], $over) : [] as $code): ?>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 10px;color:var(--warn);font-size:13px">
                        ⚠ Perannya mengizinkan <b><?= e($modName($code)) ?></b>, tapi menu itu mati di bisnis ini — belum tampil. <?php $moduleOnButton($code); ?>
                    </div>
                <?php endforeach; ?>
                <div style="display:flex;flex-wrap:wrap;gap:6px">
                    <?php foreach ($enabledMods as $code): $lv = $eff[$code] ?? 0; ?>
                        <span class="pill <?= $lv >= Catalog::MAX_LEVEL ? 'ok' : ($lv >= 1 ? 'gold' : '') ?>"<?= isset($over[$code]) ? ' style="outline:1px dashed var(--gold2)" title="pengecualian khusus orang ini"' : '' ?>><?= e(Catalog::MODULES[$code][0]) ?>: <?= Catalog::LEVELS[$lv] ?><?= isset($over[$code]) ? ' ✱' : '' ?></span>
                    <?php endforeach; ?>
                </div>
                <?php if ($over): ?>
                    <p class="muted" style="margin:10px 0 0;font-size:12px">✱ = pengecualian khusus orang ini (<?= count($over) ?> menu) — izin lain mengikuti peran <?= e($p['role_name']) ?>.</p>
                    <?php if ($isAdmin): ?>
                        <form method="post" style="margin-top:8px" onsubmit="return confirm('Hapus semua pengecualian? Izin <?= e($p['name']) ?> akan persis mengikuti peran <?= e($p['role_name']) ?>.')">
                            <?= csrf_field() ?><input type="hidden" name="action" value="member_clear_overrides"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="person_id" value="<?= (int)$p['person_id'] ?>"><button class="btn ghost sm">Hapus pengecualian</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php elseif ($tab === 'portal'):
    $sdb = null;
    $err = null;
    try {
        $sdb = StaffPortal::db($biz);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    $shownStaff = ($_SESSION['shown_staff_pw']['biz'] ?? 0) === $id ? $_SESSION['shown_staff_pw'] : null;
    unset($_SESSION['shown_staff_pw']); ?>
    <p class="muted" style="margin:0 0 12px;font-size:12px">Akun <b>karyawan</b> untuk Staff Portal (absen GPS/wajah, slip gaji, cuti, lembur, chat, tugas kamar). Terpisah dari user sistem — satu akun per karyawan di menu Payroll.</p>
    <?php if (!$sdb): ?>
        <div class="soonbox"><?= $err ? e($err) : 'Bisnis ini belum terhubung ke sistem yang berjalan (database bisnis belum diatur).' ?></div>
    <?php else: $url = StaffPortal::url($biz); $emps = StaffPortal::employees($sdb); ?>
        <div class="card" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <span class="muted" style="font-size:12px">Link portal untuk staf:</span>
            <code id="portal-url" style="font-size:12px"><?= e($url) ?></code>
            <button class="btn ghost sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('portal-url').textContent);this.textContent='Tersalin ✓'">Salin</button>
            <a class="btn ghost sm" target="_blank" rel="noopener" href="<?= e($url) ?>">Buka</a>
        </div>
        <?php if ($shownStaff && isset($emps[$shownStaff['emp']])): $se = $emps[$shownStaff['emp']]; ?>
            <div class="card" style="border-color:var(--gold2)">
                <h2>Login portal · <?= e($se['full_name']) ?></h2>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <span class="muted" style="font-size:12px">username</span> <code><?= e($se['username']) ?></code>
                    <span class="muted" style="font-size:12px">password</span> <code id="spw" style="font-size:18px"><?= e($shownStaff['pw']) ?></code>
                    <button class="btn sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('spw').textContent);this.textContent='Tersalin ✓'">Salin</button>
                    <?php if ($se['phone']): $wa = preg_replace('/\D/', '', (string)$se['phone']); $wa = str_starts_with($wa, '0') ? '62' . substr($wa, 1) : $wa; ?>
                        <a class="btn ghost sm" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode("Staff Portal {$biz['name']}: {$url} — username {$se['username']}, password {$shownStaff['pw']}") ?>">Kirim WhatsApp</a>
                    <?php endif; ?>
                </div>
                <p class="muted" style="margin:8px 0 0;font-size:11px">Hanya tampil sekali. Bila lupa, buat password baru.</p>
            </div>
        <?php endif; ?>
        <div class="card">
            <h2>Karyawan aktif (<?= count($emps) ?>)</h2>
            <?php if (!$emps): ?>
                <p class="muted" style="margin:0;font-size:12px">Belum ada karyawan. Tambahkan karyawan di menu <b>Payroll</b> sistem <?= e($biz['name']) ?> (nyalakan Payroll di Modul & Fitur bila belum tampil), lalu buat akun portalnya di sini.</p>
            <?php else: ?>
                <table class="sm stack">
                    <thead><tr><th>Karyawan</th><th>Akun portal</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($emps as $eid => $em): ?>
                            <tr>
                                <td><b><?= e($em['full_name']) ?></b><br><span class="muted" style="font-size:11px"><?= e($em['employee_code']) ?><?= $em['position'] ? ' · ' . e($em['position']) : '' ?></span></td>
                                <td><?php if ($em['account_id']): ?><code><?= e($em['username']) ?></code><br><span class="muted" style="font-size:11px"><?= $em['last_login'] ? 'login terakhir ' . e(date('d M Y H:i', strtotime((string)$em['last_login']))) : 'belum pernah login' ?></span><?php else: ?><span class="pill">belum punya akun</span><?php endif; ?></td>
                                <td style="text-align:right">
                                    <?php if (!$isAdmin): ?>
                                    <?php elseif ($em['account_id']): ?>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Buat password portal baru untuk <?= e($em['full_name']) ?>?')"><?= csrf_field() ?><input type="hidden" name="action" value="staff_reset"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="employee_id" value="<?= $eid ?>"><input type="hidden" name="mode" value="auto"><button class="btn ghost sm">🔑 Password baru</button></form>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Hapus akun portal <?= e($em['full_name']) ?>?')"><?= csrf_field() ?><input type="hidden" name="action" value="staff_delete"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="employee_id" value="<?= $eid ?>"><button class="btn danger sm">Hapus</button></form>
                                    <?php else: ?>
                                        <form method="post" style="display:inline-flex;gap:6px" novalidate><?= csrf_field() ?><input type="hidden" name="action" value="staff_create"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="employee_id" value="<?= $eid ?>">
                                            <input name="username" class="sm" placeholder="username" value="<?= e(strtolower(preg_replace('/[^a-z0-9]+/i', '.', trim((string)$em['full_name'])))) ?>" style="width:140px">
                                            <button class="btn sm">Buat akun</button></form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="muted" style="margin:10px 0 0;font-size:11px">Password dibuat acak dan ditampilkan sekali. Password lama tidak bisa dilihat — buat yang baru bila karyawan lupa.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php else: $integs = Integration::all($biz); $feat = Biz::modules($biz)['feature']; ?>
    <p class="muted" style="margin:0 0 12px">Kunci layanan luar khusus <?= e($biz['name']) ?>. Kunci rahasia disimpan terenkripsi dan tidak pernah ditampilkan ulang — kosongkan kolomnya bila tidak ingin mengganti. Tes koneksi hanya membaca (tidak mengirim pesan / mengubah data).
        Pakasir diatur di tab <a href="/business.php?id=<?= $id ?>&tab=langganan">Langganan</a>.</p>
    <?php if (!Vault::ready()): ?>
        <div class="soonbox" style="border-color:var(--bad)"><b>Brankas kunci belum dibuat.</b> Jalankan sekali di server: <code>sudo -u adf php /srv/adf/app/cli/console.php vault-key</code> — kunci rahasia belum bisa disimpan sebelum itu.</div>
    <?php endif; ?>
    <?php if (!$integs): ?><div class="soonbox">Jenis bisnis ini tidak punya integrasi.</div><?php endif; ?>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(340px,1fr))">
        <?php foreach ($integs as $code => $it): $def = Integrations::DEFS[$code]; $row = $it['row']; ?>
            <div class="card" id="i-<?= e($code) ?>" style="margin:0">
                <h2 style="display:flex;justify-content:space-between;gap:8px;align-items:center">
                    <span><?= e($def['label']) ?></span>
                    <?= $it['enabled'] ? '<span class="pill ok">aktif</span>' : '<span class="pill">mati</span>' ?>
                </h2>
                <p class="muted" style="margin:-4px 0 10px;font-size:12px"><?= e($def['desc']) ?></p>
                <?php if ($def['feature'] && empty($feat[$def['feature']])): ?>
                    <p style="margin:0 0 10px;font-size:12px;color:var(--warn)">Fitur "<?= e(Catalog::FEATURES[$def['feature']][0]) ?>" mati di <a href="/business.php?id=<?= $id ?>&tab=modul">Modul & Fitur</a> — kunci tersimpan tapi belum dipakai.</p>
                <?php endif; ?>
                <?php if ($row && $row['last_test_at']): ?>
                    <p style="margin:0 0 10px;font-size:12px;color:<?= $row['last_test_ok'] ? 'var(--ok)' : 'var(--bad)' ?>"><?= $row['last_test_ok'] ? '● ' : '✕ ' ?><?= e($row['last_test_msg']) ?> <span class="muted">· tes <?= e(date('d M H:i', strtotime((string)$row['last_test_at']))) ?></span></p>
                <?php endif; ?>
                <form method="post" autocomplete="off">
                    <?= csrf_field() ?><input type="hidden" name="action" value="integ"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="provider" value="<?= e($code) ?>">
                    <?php foreach ($def['fields'] as $field => [$label, $kind, , $opt]): $name = 'v[' . e($field) . ']'; ?>
                        <div class="field">
                            <?php if ($kind === 'secret'): $hint = Integration::secretHint($biz, $code, $field, $it['secrets']); ?>
                                <label><?= e($label) ?> <?= $hint ? '<span class="pill ok">' . e($hint) . '</span>' : '' ?></label>
                                <input type="password" name="<?= $name ?>" placeholder="<?= $hint ? 'kosongkan = tetap' : e((string)$opt) ?>" autocomplete="new-password" <?= $dis ?>>
                                <?php if ($hint && $isAdmin): ?><label style="display:flex;gap:4px;align-items:center;margin:4px 0 0;font-size:12px"><input type="checkbox" name="clear[]" value="<?= e($field) ?>" style="width:auto"> hapus</label><?php endif; ?>
                            <?php else: $val = (string)($it['config'][$field] ?? ''); ?>
                                <label><?= e($label) ?></label>
                                <?php if ($kind === 'textarea'): ?>
                                    <textarea name="<?= $name ?>" rows="3" placeholder="<?= e((string)$opt) ?>" <?= $dis ?>><?= e($val) ?></textarea>
                                <?php elseif ($kind === 'select'): ?>
                                    <select name="<?= $name ?>" <?= $dis ?>><?php foreach ((array)$opt as $ov => $ol): ?><option value="<?= e($ov) ?>" <?= $val === (string)$ov ? 'selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?></select>
                                <?php else: ?>
                                    <input name="<?= $name ?>" value="<?= e($val) ?>" placeholder="<?= e((string)$opt) ?>" <?= $kind === 'number' ? 'inputmode="numeric"' : '' ?> <?= $dis ?>>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <label style="display:flex;gap:6px;align-items:center;margin:0 0 10px;color:var(--ink)"><input type="checkbox" name="enabled" value="1" style="width:auto" <?= $it['enabled'] ? 'checked' : '' ?> <?= $dis ?>> Aktifkan untuk bisnis ini</label>
                    <?php if ($isAdmin): ?>
                        <button class="btn sm" name="do" value="save">Simpan</button>
                        <button class="btn ghost sm" name="do" value="test">Simpan & tes koneksi</button>
                    <?php endif; ?>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php View::end();
