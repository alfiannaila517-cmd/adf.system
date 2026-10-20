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
    } elseif ($action === 'regenerate_token') {
        $db->prepare('UPDATE subscriptions SET client_token = ? WHERE business_id = ?')->execute([bin2hex(random_bytes(24)), $id]);
        Auth::audit('subscription.token', $biz['name']);
        flash('Token baru dibuat. Ganti juga token di pengaturan langganan sistem bisnis ini, kalau tidak sinkron akan gagal.', 'err');
    }
    redirect("/business.php?id={$id}&tab={$tab}");
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
        <a class="<?= $tab === $k ? 'on' : '' ?><?= in_array($k, ['modul', 'orang', 'integrasi'], true) ? ' soon' : '' ?>" href="/business.php?id=<?= $id ?>&tab=<?= $k ?>"><?= e($label) ?></a>
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
                <div style="font-size:18px;font-weight:600;color:<?= $sub['locked'] ? 'var(--bad)' : 'var(--ok)' ?>"><?= $sub['locked'] ? '🔒 Terkunci sejak ' . e(date('d M Y H:i', strtotime((string)$sub['locked_at']))) : '● Aktif' ?></div>
                <?php if ($isAdmin): ?>
                    <form method="post" style="margin-top:10px" onsubmit="return confirm('<?= $sub['locked'] ? 'Buka kunci sistem bisnis ini?' : 'Kunci sistem bisnis ini? Staf tidak bisa memakai sistem sampai dibuka.' ?>')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="<?= $sub['locked'] ? 'unlock' : 'lock' ?>"><input type="hidden" name="id" value="<?= $id ?>">
                        <button class="btn <?= $sub['locked'] ? '' : 'danger' ?> sm"><?= $sub['locked'] ? '🔓 Buka kunci' : '🔒 Kunci sistem' ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card"><h2>Tarif</h2>
                <div style="font-size:18px;font-weight:600"><?= Biz::rp($sub['base_fee']) ?> <span class="muted" style="font-size:13px;font-weight:400">/ bulan</span></div>
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

<?php elseif ($tab === 'modul'): ?>
    <div class="soonbox"><b>Modul & Fitur</b> — satu tempat untuk menyalakan/mematikan menu (Buku kas, Reservasi, Gaji, Gudang, …) dan fitur (Cloudbeds, hold kamar 24 jam, …) khusus bisnis ini. Menggantikan pengaturan modul yang sekarang tersebar di dua tempat.</div>
<?php elseif ($tab === 'orang'): ?>
    <div class="soonbox"><b>Orang & Akses</b> — siapa saja yang bisa masuk ke <?= e($biz['name']) ?>, perannya (Owner, Manager, Front Desk, Kasir, Staff…), dan pintu masuknya (sistem, portal owner, portal staff). Tambah orang cukup: email → peran → kirim undangan.</div>
<?php else: ?>
    <div class="soonbox"><b>Integrasi</b> — kunci WhatsApp (Fonnte), Cloudbeds, mesin absensi, dan Pakasir khusus bisnis ini, lengkap dengan tombol "Tes koneksi" dan status terakhir.</div>
<?php endif; ?>
<?php View::end();
