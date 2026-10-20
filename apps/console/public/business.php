<?php

/** Satu bisnis: tab Ringkasan · Langganan · Database & Storage · Modul & Fitur · Orang & Akses · Integrasi. */

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
            flash('Nama bisnis wajib diisi dan client harus valid.', 'err');
        } else {
            $db->prepare('UPDATE businesses SET name = ?, type = ?, status = ?, client_id = ?, domain = ?, notes = ? WHERE id = ?')->execute([
                mb_substr($name, 0, 150), $type, $status, $clientId, trim((string)($_POST['domain'] ?? '')) ?: null, trim((string)($_POST['notes'] ?? '')) ?: null, $id,
            ]);
            Auth::audit('business.update', $name . ($clientId !== (int)$biz['client_id'] ? ' (pindah client)' : ''));
            flash('Bisnis disimpan.');
        }
    } elseif ($action === 'database') {
        $dbName = trim((string)($_POST['db_name'] ?? ''));
        if ($dbName !== '' && !preg_match('/^[a-z0-9_]{3,64}$/', $dbName)) {
            flash('Nama database hanya huruf kecil, angka, garis bawah.', 'err');
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
    } elseif ($action === 'regenerate_token') {
        $db->prepare('UPDATE subscriptions SET client_token = ? WHERE business_id = ?')->execute([bin2hex(random_bytes(24)), $id]);
        Auth::audit('subscription.token', $biz['name']);
        flash('Token baru dibuat. Ganti juga token di pengaturan langganan sistem bisnis ini, kalau tidak sinkron akan gagal.', 'err');
    }

    // Perubahan akses langsung dikirim ke tabel sistem lama (bila bisnis ini terhubung) supaya sistem lama tetap berjalan sama
    $accessActions = ['modules', 'modules_reset', 'member_add', 'member_update', 'member_clear_overrides', 'member_remove', 'roles_save'];
    if (in_array($action, $accessActions, true) && ($_SESSION['flash']['type'] ?? '') === 'ok' && Legacy::linked($biz)) {
        try {
            $sync = Legacy::export(Biz::business($id), $newPasswords);
            Auth::audit('legacy.export', "{$biz['name']}: " . count($sync) . ' perubahan');
            $_SESSION['flash']['msg'] .= ' Sistem lama diperbarui' . ($sync ? ': ' . implode(' · ', $sync) : ' (tidak ada yang berubah).');
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['msg' => $_SESSION['flash']['msg'] . ' TAPI gagal menulis ke sistem lama: ' . $e->getMessage(), 'type' => 'err'];
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
<div class="crumb"><a href="/clients.php">Client & Bisnis</a> › <a href="/client.php?id=<?= (int)$biz['client_id'] ?>"><?= e($biz['client_name']) ?></a> ›</div>
<h1><?= $typeIcon ?> <?= e($biz['name']) ?></h1>
<p class="muted" style="margin:0"><?= e($typeLabel) ?> · kode <code><?= e($biz['slug']) ?></code><?= $biz['domain'] ? ' · ' . e($biz['domain']) : '' ?></p>

<nav class="tabs">
    <?php foreach (['ringkasan' => 'Ringkasan', 'langganan' => 'Langganan', 'database' => 'Database & Storage', 'modul' => 'Modul & Fitur', 'orang' => 'Orang & Akses', 'integrasi' => 'Integrasi'] as $k => $label): ?>
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
                <div class="field"><label>Milik client</label><select name="client_id" <?= $dis ?>><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === (int)$biz['client_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="field"><label>Domain / website</label><input name="domain" value="<?= e($biz['domain']) ?>" <?= $dis ?>></div>
            <div class="field"><label>Catatan</label><input name="notes" value="<?= e($biz['notes']) ?>" <?= $dis ?>></div>
            <?php if ($isAdmin): ?><button class="btn">Simpan</button><?php endif; ?>
        </form>
    </div>

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
                <h2 style="margin-top:8px">Pakasir (pembayaran online client ini)</h2>
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
    <div class="card" style="max-width:760px">
        <h2>Storage</h2>
        <dl class="kv"><dt>Folder file bisnis</dt><dd><code>/srv/adf/storage/<?= e($biz['slug']) ?>/</code></dd></dl>
    </div>
    <div class="soonbox"><b>Segera:</b> ukuran database & folder, backup terakhir, dan tombol backup/unduh per bisnis.</div>

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
    // Ringkasan izin peran: "8 menu · Kelola 5 · Input 3"
    $roleSummary = function (array $r) use ($enabledMods): string {
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
        return $open ? "{$open} menu · " . implode(' · ', $parts) : 'tidak ada menu';
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
        <div class="soonbox" style="margin-bottom:12px;padding:10px 14px;font-size:13px">🔗 Terhubung ke sistem <?= e($biz['name']) ?> yang sedang berjalan — setiap perubahan di sini <b>langsung berlaku</b> di sana.</div>
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
            <form method="post" autocomplete="off" <?= $ownerConfirm ?>>
                <?= csrf_field() ?><input type="hidden" name="action" value="member_add"><input type="hidden" name="id" value="<?= $id ?>">
                <p style="margin:4px 0 8px;font-weight:600">1. Data orang</p>
                <div class="row2">
                    <div class="field"><label>Nama lengkap</label><input name="name" value="<?= e($old['name'] ?? '') ?>" required></div>
                    <div class="field"><label>Email</label><input name="email" type="email" value="<?= e($old['email'] ?? '') ?>" required></div>
                </div>
                <?php if ($linked): ?>
                    <p style="margin:4px 0 8px;font-weight:600">2. Akun login sistem</p>
                    <div class="row2">
                        <div class="field"><label>Username *</label><input name="username" value="<?= e($old['username'] ?? '') ?>" pattern="[a-z0-9_.]{3,50}" placeholder="huruf kecil, mis. rina.fo"></div>
                        <div class="field"><label>Password awal *</label><input name="password" type="password" minlength="8" placeholder="min. 8 karakter" autocomplete="new-password"></div>
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

    <?php else: ?>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:6px">
                <h2 style="margin:0">Orang yang punya akses (<?= count($acc['owners']) + count($acc['members']) ?>)</h2>
                <?php if ($isAdmin): ?><a class="btn sm" href="<?= $base ?>&view=tambah">+ Tambah orang</a><?php endif; ?>
            </div>
            <table class="stack">
                <thead><tr><th>Orang</th><th>Peran</th><th>Bisa masuk lewat</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($acc['owners'] as $o): ?>
                        <tr>
                            <td><b><?= e($o['name']) ?></b><br><span class="muted" style="font-size:12px"><?= e($o['email']) ?></span></td>
                            <td><span class="pill gold">👑 Owner grup</span></td>
                            <td><span class="pill">Sistem</span> <span class="pill">Owner Login</span></td>
                            <td class="muted" style="font-size:12px;text-align:right">diatur di <a href="/client.php?id=<?= (int)$biz['client_id'] ?>&tab=orang">grup</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($acc['members'] as $m): $ov = count(json_decode((string)($m['overrides'] ?? ''), true) ?: []); ?>
                        <tr>
                            <td><b><?= e($m['name']) ?></b><?= $m['status'] === 'invited' ? ' <span class="pill warn">diundang</span>' : '' ?><?= $m['status'] === 'disabled' ? ' <span class="pill">nonaktif</span>' : '' ?>
                                <br><span class="muted" style="font-size:12px"><?= $m['username'] ? e($m['username']) . ' · ' : '' ?><?= e($m['email']) ?></span></td>
                            <td><span class="pill <?= $m['is_owner'] ? 'gold' : '' ?>"><?= e($m['role_name']) ?></span><?= $ov ? ' <span class="pill" title="Izin khusus orang ini yang berbeda dari perannya">+' . $ov . ' pengecualian</span>' : '' ?></td>
                            <td><span class="pill">Sistem</span><?= $m['portal_owner'] ? ' <span class="pill ok">Owner Login</span>' : '' ?></td>
                            <td style="text-align:right;white-space:nowrap">
                                <?php if ($isAdmin): ?><a class="btn ghost sm" href="<?= $base ?>&edit=<?= (int)$m['person_id'] ?>">Ubah</a><?php endif; ?>
                                <a class="btn ghost sm" href="<?= $base ?>&person=<?= (int)$m['person_id'] ?>#izin">Izin</a>
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
                <?php $over = json_decode((string)($p['overrides'] ?? ''), true) ?: []; ?>
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
