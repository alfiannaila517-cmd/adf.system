<?php

/** Satu client (grup usaha): tab Ringkasan · Bisnis · Tagihan · Orang & Akses. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();
$db = Biz::db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$client = Biz::client($id);
if (!$client) {
    flash('Client tidak ditemukan.', 'err');
    redirect('/clients.php');
}
$tab = (string)($_GET['tab'] ?? 'bisnis');
$isAdmin = $me['role'] === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!csrf_ok() || !$isAdmin) {
        flash(!$isAdmin ? 'Hanya admin yang bisa mengubah data client.' : 'Sesi form kedaluwarsa, silakan coba lagi.', 'err');
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
            flash('Data client disimpan.');
        }
    } elseif ($action === 'add_business') {
        $name = trim((string)($_POST['name'] ?? ''));
        $type = array_key_exists($_POST['type'] ?? '', Biz::TYPES) ? $_POST['type'] : 'other';
        $slug = Biz::slugify((string)($_POST['slug'] ?? '') ?: $name);
        if (mb_strlen($name) < 2 || strlen($slug) < 2) {
            flash('Nama bisnis wajib diisi.', 'err');
        } elseif ($db->query('SELECT 1 FROM businesses WHERE slug = ' . $db->quote($slug))->fetchColumn()) {
            flash("Kode bisnis \"{$slug}\" sudah dipakai, pakai kode lain.", 'err');
        } else {
            $db->prepare('INSERT INTO businesses (client_id, slug, name, type, domain) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $slug, mb_substr($name, 0, 150), $type, trim((string)($_POST['domain'] ?? '')) ?: null]);
            $bid = (int)$db->lastInsertId();
            Auth::audit('business.add', "{$name} → {$client['name']}");
            flash("Bisnis {$name} ditambahkan.");
            redirect("/business.php?id={$bid}");
        }
    }
    redirect("/client.php?id={$id}&tab={$tab}");
}

$businesses = Biz::businessesOf($id);
$unpaid = Biz::unpaidChargesOfClient($id);
$unpaidTotal = array_sum(array_map([Biz::class, 'chargeTotal'], $unpaid));
$monthly = array_sum(array_map(fn ($b) => (float)$b['base_fee'], array_filter($businesses, fn ($b) => $b['sub_id'] && $b['status'] === 'active')));

View::start($client['name'], 'clients', $me);
?>
<div class="crumb"><a href="/clients.php">Client & Bisnis</a> ›</div>
<h1><?= e($client['name']) ?> <?php if ($client['is_demo']): ?><span class="pill gold">DEMO</span><?php endif; ?></h1>
<p class="muted" style="margin:0"><?= e(implode(' · ', array_filter([$client['contact_name'], $client['email'], $client['phone']])) ?: 'Kontak belum diisi') ?></p>

<nav class="tabs">
    <?php foreach (['bisnis' => 'Bisnis (' . count($businesses) . ')', 'tagihan' => 'Tagihan', 'orang' => 'Orang & Akses', 'ringkasan' => 'Data client'] as $k => $label): ?>
        <a class="<?= $tab === $k ? 'on' : '' ?>" href="/client.php?id=<?= $id ?>&tab=<?= $k ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'bisnis'): ?>
    <div class="grid">
        <?php foreach ($businesses as $b): [$tLabel, $tIcon] = Biz::type((string)$b['type']); ?>
            <a class="tile" href="/business.php?id=<?= (int)$b['id'] ?>">
                <div class="ic"><?= $tIcon ?></div>
                <b><?= e($b['name']) ?></b>
                <small><?= e($tLabel) ?><?= $b['domain'] ? ' · ' . e($b['domain']) : '' ?></small>
                <div class="meta">
                    <?php if ($b['status'] !== 'active'): ?>
                        <span class="pill"><?= e(Biz::STATUS[$b['status']] ?? $b['status']) ?></span>
                    <?php elseif ($b['locked']): ?>
                        <span class="pill bad">🔒 terkunci</span>
                    <?php else: ?>
                        <span class="pill ok">● aktif</span>
                    <?php endif; ?>
                    <?php if (!$b['sub_id']): ?>
                        <span class="pill">belum ada langganan</span>
                    <?php elseif ((int)$b['unpaid_count'] > 0): ?>
                        <span class="pill warn"><?= (int)$b['unpaid_count'] ?> belum lunas · jatuh tempo <?= e(date('d M', strtotime((string)$b['next_due']))) ?></span>
                    <?php else: ?>
                        <span class="pill ok">lunas</span>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
        <?php if (!$businesses): ?><div class="soonbox">Belum ada bisnis di client ini.</div><?php endif; ?>
    </div>

    <?php if ($isAdmin): ?>
        <div class="card" style="margin-top:18px">
            <h2>Tambah bisnis ke <?= e($client['name']) ?></h2>
            <form method="post" class="row2" style="align-items:end">
                <?= csrf_field() ?><input type="hidden" name="action" value="add_business"><input type="hidden" name="id" value="<?= $id ?>">
                <div><label>Nama bisnis</label><input name="name" required placeholder="mis. Eatmeet"></div>
                <div><label>Jenis</label><select name="type"><?php foreach (Biz::TYPES as $code => [$l, $i]): ?><option value="<?= $code ?>"><?= $i ?> <?= e($l) ?></option><?php endforeach; ?></select></div>
                <div><label>Kode (opsional)</label><input name="slug" placeholder="otomatis dari nama"></div>
                <div><label>Domain (opsional)</label><input name="domain" placeholder="mis. narayanakarimunjawa.com"></div>
                <div><button class="btn" style="width:100%">Tambah bisnis</button></div>
            </form>
        </div>
    <?php endif; ?>

<?php elseif ($tab === 'tagihan'): ?>
    <div class="row2" style="margin-bottom:14px">
        <div class="card"><h2>Belum lunas</h2><div style="font-size:22px;font-weight:600;color:<?= $unpaid ? 'var(--warn)' : 'var(--ok)' ?>"><?= Biz::rp($unpaidTotal) ?></div><p class="muted" style="margin:4px 0 0;font-size:13px"><?= count($unpaid) ?> tagihan</p></div>
        <div class="card"><h2>Biaya dasar per bulan</h2><div style="font-size:22px;font-weight:600"><?= Biz::rp($monthly) ?></div><p class="muted" style="margin:4px 0 0;font-size:13px">belum termasuk tarif per tamu</p></div>
    </div>
    <div class="card">
        <h2>Tagihan langganan belum lunas</h2>
        <?php if (!$unpaid): ?>
            <p class="muted" style="margin:0">Semua tagihan sudah lunas.</p>
        <?php else: ?>
            <table class="stack">
                <thead><tr><th>Bisnis</th><th>Periode</th><th>Rincian</th><th>Jatuh tempo</th><th style="text-align:right">Total</th></tr></thead>
                <tbody>
                    <?php foreach ($unpaid as $ch): ?>
                        <tr>
                            <td><?= e($ch['business_name']) ?></td>
                            <td><?= e($ch['period']) ?><?= $ch['kind'] === 'topup' ? ' <span class="pill">susulan</span>' : '' ?></td>
                            <td class="muted" style="font-size:13px"><?= Biz::rp($ch['base_fee']) ?><?= (int)$ch['guest_count'] + (int)$ch['carry_guests'] > 0 ? ' + ' . ((int)$ch['guest_count'] + (int)$ch['carry_guests']) . ' tamu × ' . Biz::rp($ch['per_guest_fee']) : '' ?><?= (float)$ch['adjustment'] != 0 ? ' ' . ((float)$ch['adjustment'] > 0 ? '+' : '−') . ' ' . Biz::rp(abs((float)$ch['adjustment'])) : '' ?></td>
                            <td><?= $ch['due_date'] ? e(date('d M Y', strtotime($ch['due_date']))) : '-' ?></td>
                            <td style="text-align:right;font-weight:600"><?= Biz::rp(Biz::chargeTotal($ch)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <div class="soonbox"><b>Segera:</b> draf tagihan bulanan per client yang bisa diedit bebas (satu baris per bisnis, termasuk website), kirim invoice, dan catat pembayaran.</div>

<?php elseif ($tab === 'orang'): ?>
    <div class="soonbox">
        <b>Orang & Akses</b> — dibangun pada langkah berikutnya. Di sini nanti terlihat semua orang di grup ini dan bisnis mana saja yang bisa mereka buka:
        <ul style="margin:10px 0 0;padding-left:18px;line-height:1.8">
            <li><b>Owner grup</b>: otomatis bisa masuk ke semua bisnis grup ini</li>
            <li>Orang lain: dipilih bisnisnya + perannya per bisnis (mis. Front Desk di hotel, Kasir di kafe)</li>
            <li>Tambah orang = 3 langkah: email → pilih bisnis & peran → kirim undangan</li>
        </ul>
    </div>

<?php else: ?>
    <div class="card" style="max-width:720px">
        <h2>Data client</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $id ?>">
            <div class="row2">
                <div class="field"><label>Nama client / grup</label><input name="name" value="<?= e($client['name']) ?>" required <?= $isAdmin ? '' : 'disabled' ?>></div>
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
