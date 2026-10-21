<?php

/** Bisnis: semua bisnis dikelompokkan per grup (pemilik), dan tambah grup baru. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();
$db = Biz::db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    if (!csrf_ok()) {
        flash('Sesi form kedaluwarsa, silakan coba lagi.', 'err');
    } elseif ($me['role'] !== 'admin') {
        flash('Hanya admin yang bisa menambah grup.', 'err');
    } elseif (mb_strlen($name) < 2) {
        flash('Nama grup wajib diisi.', 'err');
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('Email tidak valid.', 'err');
    } else {
        $db->prepare('INSERT INTO clients (name, contact_name, email, phone) VALUES (?, ?, ?, ?)')
            ->execute([mb_substr($name, 0, 150), trim((string)($_POST['contact_name'] ?? '')) ?: null, $email ?: null, trim((string)($_POST['phone'] ?? '')) ?: null]);
        $id = (int)$db->lastInsertId();
        Auth::audit('client.add', $name);
        flash("Grup {$name} dibuat. Tambahkan bisnis pertamanya di bawah.");
        redirect("/client.php?id={$id}");
    }
    redirect('/clients.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$clients = $db->query('SELECT * FROM clients ORDER BY is_demo, name')->fetchAll();
$groups = [];
foreach ($clients as $c) {
    $groups[(int)$c['id']] = ['client' => $c, 'biz' => Biz::businessesOf((int)$c['id'])];
}
if ($q !== '') {
    foreach ($groups as $cid => $g) {
        $hitGroup = stripos($g['client']['name'], $q) !== false;
        $groups[$cid]['biz'] = $hitGroup ? $g['biz'] : array_values(array_filter($g['biz'], fn ($b) => stripos($b['name'] . ' ' . $b['domain'], $q) !== false));
        if (!$hitGroup && !$groups[$cid]['biz']) {
            unset($groups[$cid]);
        }
    }
}
$total = array_sum(array_map(fn ($g) => count($g['biz']), $groups));

View::start('Bisnis', 'clients', $me);
?>
<div class="pagehead">
    <div><h1>Bisnis</h1><p><?= $total ?> bisnis dalam <?= count($groups) ?> grup. Grup = pemilik / perusahaan; klik bisnis untuk mengatur menu, akses, staff portal, dan integrasinya.</p></div>
    <form method="get" style="display:flex;gap:8px;min-width:260px">
        <input name="q" value="<?= e($q) ?>" placeholder="Cari grup atau bisnis…">
        <?php if ($q !== ''): ?><a class="btn ghost" href="/clients.php">Reset</a><?php endif; ?>
    </form>
</div>

<?php foreach ($groups as $cid => $g): $c = $g['client']; ?>
    <section style="margin-bottom:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 10px">
            <div>
                <span style="font-size:16px;font-weight:650"><?= e($c['name']) ?></span>
                <?= $c['is_demo'] ? '<span class="pill gold">DEMO</span>' : '' ?>
                <span class="muted" style="font-size:12.5px">· <?= count($g['biz']) ?> bisnis<?= $c['contact_name'] ? ' · ' . e($c['contact_name']) : '' ?></span>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <a class="btn ghost sm" href="/client.php?id=<?= $cid ?>&tab=login">Login & Domain</a>
                <a class="btn ghost sm" href="/client.php?id=<?= $cid ?>&tab=orang">Akses</a>
                <a class="btn ghost sm" href="/client.php?id=<?= $cid ?>">Pengaturan grup</a>
            </div>
        </div>
        <div class="grid">
            <?php foreach ($g['biz'] as $b): [$tLabel, $tIcon] = Biz::type((string)$b['type']); ?>
                <a class="tile" href="/business.php?id=<?= (int)$b['id'] ?>">
                    <div class="ic"><?= $tIcon ?></div>
                    <b><?= e($b['name']) ?></b>
                    <small><?= e($tLabel) ?><?= $b['domain'] ? ' · ' . e($b['domain']) : '' ?></small>
                    <div class="meta">
                        <?php if ($b['status'] !== 'active'): ?><span class="pill"><?= e(Biz::STATUS[$b['status']] ?? $b['status']) ?></span>
                        <?php elseif ($b['locked']): ?><span class="pill bad">terkunci</span>
                        <?php else: ?><span class="pill ok">aktif</span><?php endif; ?>
                        <?php if ((int)$b['unpaid_count'] > 0): ?><span class="pill warn"><?= (int)$b['unpaid_count'] ?> tagihan belum lunas</span><?php endif; ?>
                        <?php if (!$b['legacy_business_id'] && $b['type'] !== 'website'): ?><span class="pill">belum dibuat di sistem</span><?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
            <?php if ($me['role'] === 'admin'): ?>
                <a class="tile" href="/client.php?id=<?= $cid ?>&add=1#tambah" style="border-style:dashed;box-shadow:none;display:grid;place-items:center;color:var(--mu);min-height:116px">
                    <span style="text-align:center"><span style="font-size:22px">＋</span><br><small>Tambah bisnis ke <?= e($c['name']) ?></small></span>
                </a>
            <?php endif; ?>
        </div>
    </section>
<?php endforeach; ?>

<?php if (!$groups): ?>
    <div class="soonbox"><?= $q !== '' ? 'Tidak ada grup atau bisnis yang cocok.' : 'Belum ada grup. Buat grup pertama di bawah.' ?></div>
<?php endif; ?>

<?php if ($me['role'] === 'admin'): ?>
    <details class="card" style="margin-top:6px" <?= $clients ? '' : 'open' ?>>
        <summary style="cursor:pointer;font-weight:600">Buat grup baru</summary>
        <p class="muted" style="margin:8px 0 12px;font-size:12.5px">Grup = pemilik atau perusahaan (mis. Nasita Group). Satu grup bisa punya banyak bisnis dan ditagih bersama.</p>
        <form method="post" class="row2" style="align-items:end">
            <?= csrf_field() ?>
            <div><label>Nama grup</label><input name="name" required placeholder="mis. Nasita Group"></div>
            <div><label>Nama kontak</label><input name="contact_name"></div>
            <div><label>Email</label><input name="email" type="email"></div>
            <div><label>WhatsApp / telepon</label><input name="phone"></div>
            <div><button class="btn" style="width:100%">Buat grup</button></div>
        </form>
    </details>
<?php endif; ?>
<?php View::end();
