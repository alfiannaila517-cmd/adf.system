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
$unpaid = array_sum(array_map(fn ($g) => count(array_filter($g['biz'], fn ($b) => (int)$b['unpaid_count'] > 0)), $groups));

View::start('Bisnis', 'clients', $me);
?>
<style>
    /* Daftar bisnis: satu kartu per grup, satu baris tipis per bisnis */
    .grp { background: var(--card); border: 1px solid var(--line); border-radius: 12px; margin-bottom: 12px; overflow: hidden; }
    .grp-head { display: flex; justify-content: space-between; align-items: center; gap: 8px 14px; flex-wrap: wrap; padding: 10px 14px; border-bottom: 1px solid var(--line2); background: var(--card2); }
    .grp-head b { font-size: 13.5px; font-weight: 650; }
    .grp-head .links { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px; }
    .grp-head .links a { color: var(--ink2); }
    .grp-head .links a:hover { color: var(--brand); }
    .grp-head .links a.add { color: var(--brand); font-weight: 600; }
    .biz { display: grid; grid-template-columns: 30px minmax(0, 1fr) auto 12px; align-items: center; gap: 10px; padding: 8px 14px; color: var(--ink); border-top: 1px solid var(--line2); }
    .biz:first-of-type { border-top: 0; }
    .biz:hover { background: rgba(255, 255, 255, .025); color: var(--ink); }
    .biz .ic { width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center; background: #1a1f2b; font-size: 15px; }
    .biz .nm { font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .biz .sub { color: var(--mu); font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .biz .st { display: flex; gap: 5px; flex-wrap: wrap; justify-content: flex-end; }
    .biz .go { color: var(--mu); font-size: 15px; }
    .biz:hover .go { color: var(--brand); }
    .grp-empty { padding: 10px 14px; color: var(--mu); font-size: 12px; }
    @media (max-width: 640px) { .biz { grid-template-columns: 30px minmax(0, 1fr) 12px; } .biz .st { grid-column: 2; justify-content: flex-start; } .biz .go { grid-row: 1; grid-column: 3; } }
</style>

<div class="pagehead">
    <div><h1>Bisnis</h1><p><?= $total ?> bisnis · <?= count($groups) ?> grup<?= $unpaid ? " · <span style=\"color:var(--warn)\">{$unpaid} punya tagihan belum lunas</span>" : '' ?></p></div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <form method="get" style="display:flex;gap:6px">
            <input name="q" value="<?= e($q) ?>" placeholder="Cari grup atau bisnis…" style="width:220px">
            <?php if ($q !== ''): ?><a class="btn ghost" href="/clients.php">Reset</a><?php endif; ?>
        </form>
        <?php if ($me['role'] === 'admin'): ?><a class="btn" href="#grup-baru" onclick="document.getElementById('grup-baru').open=true">＋ Grup baru</a><?php endif; ?>
    </div>
</div>

<?php foreach ($groups as $cid => $g): $c = $g['client']; ?>
    <section class="grp">
        <div class="grp-head">
            <div>
                <b><?= e($c['name']) ?></b>
                <?= $c['is_demo'] ? ' <span class="pill gold">DEMO</span>' : '' ?>
                <span class="muted" style="font-size:12px"> · <?= count($g['biz']) ?> bisnis</span>
            </div>
            <div class="links">
                <a href="/client.php?id=<?= $cid ?>&tab=login">Login & domain</a>
                <a href="/client.php?id=<?= $cid ?>&tab=orang">Akses</a>
                <a href="/client.php?id=<?= $cid ?>">Pengaturan</a>
                <?php if ($me['role'] === 'admin'): ?><a class="add" href="/client.php?id=<?= $cid ?>&add=1#tambah">＋ Bisnis</a><?php endif; ?>
            </div>
        </div>
        <?php foreach ($g['biz'] as $b): [$tLabel, $tIcon] = Biz::type((string)$b['type']); ?>
            <a class="biz" href="/business.php?id=<?= (int)$b['id'] ?>">
                <span class="ic"><?= $tIcon ?></span>
                <span style="min-width:0">
                    <div class="nm"><?= e($b['name']) ?></div>
                    <div class="sub"><?= e($tLabel) ?><?= $b['domain'] ? ' · ' . e($b['domain']) : '' ?></div>
                </span>
                <span class="st">
                    <?php if ((int)$b['unpaid_count'] > 0): ?><span class="pill warn"><?= (int)$b['unpaid_count'] ?> tagihan belum lunas</span><?php endif; ?>
                    <?php if (!empty($b['hosted_elsewhere'])): ?><span class="pill">server lain</span><?php elseif (!$b['legacy_business_id'] && $b['type'] !== 'website'): ?><span class="pill">belum dibuat di sistem</span><?php endif; ?>
                    <?php if ($b['status'] !== 'active'): ?><span class="pill"><?= e(Biz::STATUS[$b['status']] ?? $b['status']) ?></span>
                    <?php elseif ($b['locked']): ?><span class="pill bad">terkunci</span>
                    <?php else: ?><span class="pill ok">aktif</span><?php endif; ?>
                </span>
                <span class="go">›</span>
            </a>
        <?php endforeach; ?>
        <?php if (!$g['biz']): ?><div class="grp-empty">Belum ada bisnis.</div><?php endif; ?>
    </section>
<?php endforeach; ?>

<?php if (!$groups): ?>
    <div class="soonbox"><?= $q !== '' ? 'Tidak ada grup atau bisnis yang cocok.' : 'Belum ada grup. Buat grup pertama di bawah.' ?></div>
<?php endif; ?>

<?php if ($me['role'] === 'admin'): ?>
    <details class="card" id="grup-baru" style="margin-top:6px" <?= $clients ? '' : 'open' ?>>
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
