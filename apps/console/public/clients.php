<?php

/** Bisnis: semua bisnis dikelompokkan per grup (pemilik), dan tambah grup baru. */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();
$db = Biz::db();

// ＋ Bisnis dari atas: ke grup yang ada, atau sekalian buat grup / pelanggan baru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_business') {
    try {
        if (!csrf_ok() || $me['role'] !== 'admin') {
            throw new RuntimeException($me['role'] !== 'admin' ? 'Hanya admin yang bisa menambah bisnis.' : 'Sesi form kedaluwarsa, silakan coba lagi.');
        }
        $pick = (string)($_POST['client_id'] ?? '');
        if ($pick === 'new') {
            $gname = trim((string)($_POST['group_name'] ?? ''));
            if (mb_strlen($gname) < 2) {
                throw new RuntimeException('Isi nama grup / pelanggan baru.');
            }
            $db->prepare('INSERT INTO clients (name, contact_name, phone) VALUES (?, ?, ?)')
                ->execute([mb_substr($gname, 0, 150), trim((string)($_POST['contact_name'] ?? '')) ?: null, trim((string)($_POST['phone'] ?? '')) ?: null]);
            $pick = (string)$db->lastInsertId();
            Auth::audit('client.add', $gname);
        }
        $st = $db->prepare('SELECT * FROM clients WHERE id = ?');
        $st->execute([(int)$pick]);
        $client = $st->fetch() ?: throw new RuntimeException('Pilih grup.');
        [$bid, $msg, $ok] = Biz::add($client, $_POST);
        flash($msg, $ok ? 'ok' : 'err');
        redirect("/business.php?id={$bid}" . ($ok ? '' : '&tab=database'));
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'err');
        redirect('/clients.php?add=1');
    }
}

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
// Logo grup = logo/favicon login perusahaan (Login & Domain), dibaca sekali untuk semua grup
$logos = [];
try {
    if (Branding::available()) {
        foreach (Branding::all() as $br) {
            $logos[(int)($br['client_id'] ?? 0)] = Branding::publicUrl($br, 'favicon') ?? Branding::publicUrl($br, 'logo');
        }
    }
} catch (Throwable) {
    // tanpa logo: pakai inisial
}

View::start('Bisnis', 'clients', $me);
?>
<div class="pagehead">
    <div><h1>Bisnis</h1><p><?= $total ?> bisnis · <?= count($groups) ?> grup<?= $unpaid ? " · <span style=\"color:var(--warn)\">{$unpaid} punya tagihan belum lunas</span>" : '' ?></p></div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <form method="get" style="display:flex;gap:6px">
            <input name="q" value="<?= e($q) ?>" placeholder="Cari grup atau bisnis…" style="width:220px">
            <?php if ($q !== ''): ?><a class="btn ghost" href="/clients.php">Reset</a><?php endif; ?>
        </form>
        <?php if ($me['role'] === 'admin'): ?><a class="btn" href="?add=1#bisnis-baru" onclick="var d=document.getElementById('bisnis-baru');d.open=!d.open;return false">＋ Bisnis</a><?php endif; ?>
    </div>
</div>

<?php if ($me['role'] === 'admin'): ?>
    <details class="card" id="bisnis-baru" <?= isset($_GET['add']) ? 'open' : '' ?>>
        <summary style="cursor:pointer;font-weight:600">Bisnis baru</summary>
        <form method="post" style="margin-top:10px" novalidate>
            <?= csrf_field() ?><input type="hidden" name="action" value="add_business">
            <div class="row2">
                <div><label>Grup / pelanggan</label>
                    <select name="client_id" onchange="document.getElementById('grp-new').style.display=this.value==='new'?'':'none'">
                        <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?><?= $c['is_demo'] ? ' (demo)' : '' ?></option><?php endforeach; ?>
                        <option value="new" <?= $clients ? '' : 'selected' ?>>＋ Grup / pelanggan baru…</option>
                    </select></div>
                <div><label>Nama bisnis</label><input name="name" placeholder="mis. Warung Makan Sari"></div>
                <div><label>Jenis</label><select name="type"><?php foreach (Biz::TYPES as $code => [$l, $i]): ?><option value="<?= $code ?>"><?= $i ?> <?= e($l) ?></option><?php endforeach; ?></select></div>
                <div><label>Domain sendiri (opsional)</label><input name="domain" placeholder="mis. warungsari.com"></div>
            </div>
            <div class="row2" id="grp-new" style="margin-top:9px;<?= $clients ? 'display:none' : '' ?>">
                <div><label>Nama grup / pelanggan baru</label><input name="group_name" placeholder="mis. Sari Group"></div>
                <div><label>Nama kontak</label><input name="contact_name"></div>
                <div><label>WhatsApp</label><input name="phone" placeholder="0812…"></div>
            </div>
            <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-top:10px">
                <button class="btn">Buat bisnis</button>
                <label style="display:flex;gap:6px;align-items:center;margin:0;color:var(--ink)"><input type="checkbox" name="hosted_elsewhere" value="1" style="width:auto"> Sistemnya di server lain</label>
                <span class="muted" style="font-size:11px">Bisnis langsung dibuat di sistem (database, menu, akses). Domain sendiri: arahkan DNS-nya dulu, lalu pasang SSL — petunjuk muncul di halaman bisnis.</span>
            </div>
        </form>
    </details>
<?php endif; ?>

<?php if ($q !== ''): // Hasil cari: daftar bisnis yang cocok, dengan nama grupnya ?>
    <?php $hits = []; foreach ($groups as $g) { foreach ($g['biz'] as $b) { $hits[] = View::bizRow($b, $g['client']['name']); } } ?>
    <?php if ($hits): ?><div class="blist"><?= implode('', $hits) ?></div><?php endif; ?>
<?php else: ?>
    <div class="gtiles">
        <?php foreach ($groups as $cid => $g): $c = $g['client'];
            $hasUnpaid = (bool)array_filter($g['biz'], fn ($b) => (int)$b['unpaid_count'] > 0);
            $types = implode('', array_map(fn ($b) => Biz::type((string)$b['type'])[1], array_slice($g['biz'], 0, 6))); ?>
            <a class="gtile" href="/client.php?id=<?= $cid ?>">
                <?php if ($hasUnpaid): ?><span class="pill warn flag">belum lunas</span><?php elseif ($c['is_demo']): ?><span class="pill gold flag">DEMO</span><?php endif; ?>
                <?= View::groupAvatar((string)$c['name'], $logos[$cid] ?? null) ?>
                <b><?= e($c['name']) ?></b>
                <span class="sub"><?= count($g['biz']) ?> bisnis</span>
                <span class="types"><?= $types ?: '<span class="sub">—</span>' ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$groups): ?>
    <div class="soonbox"><?= $q !== '' ? 'Tidak ada grup atau bisnis yang cocok.' : 'Belum ada grup. Buat grup pertama di bawah.' ?></div>
<?php endif; ?>

<?php if ($me['role'] === 'admin'): ?>
    <details class="card" id="grup-baru" style="margin-top:12px" <?= $clients ? '' : 'open' ?>>
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
