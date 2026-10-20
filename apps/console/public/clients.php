<?php

/** Client & Bisnis: daftar client (grup usaha) beserta bisnisnya, dan tambah client baru. */

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
        flash('Hanya admin yang bisa menambah client.', 'err');
    } elseif (mb_strlen($name) < 2) {
        flash('Nama client wajib diisi.', 'err');
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('Email tidak valid.', 'err');
    } else {
        $db->prepare('INSERT INTO clients (name, contact_name, email, phone) VALUES (?, ?, ?, ?)')
            ->execute([mb_substr($name, 0, 150), trim((string)($_POST['contact_name'] ?? '')) ?: null, $email ?: null, trim((string)($_POST['phone'] ?? '')) ?: null]);
        $id = (int)$db->lastInsertId();
        Auth::audit('client.add', $name);
        flash("Client {$name} ditambahkan.");
        redirect("/client.php?id={$id}");
    }
    redirect('/clients.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$sql = 'SELECT c.*, COUNT(b.id) AS biz_count,
        SUM(b.status = \'active\') AS active_count,
        (SELECT COUNT(*) FROM subscription_charges ch JOIN businesses b2 ON b2.id = ch.business_id WHERE b2.client_id = c.id AND ch.status = \'unpaid\') AS unpaid_count,
        GROUP_CONCAT(b.type ORDER BY b.name) AS types, GROUP_CONCAT(b.name ORDER BY b.name SEPARATOR \' · \') AS names
    FROM clients c LEFT JOIN businesses b ON b.client_id = c.id'
    . ($q !== '' ? ' WHERE c.name LIKE ' . $db->quote('%' . $q . '%') . ' OR b.name LIKE ' . $db->quote('%' . $q . '%') : '')
    . ' GROUP BY c.id ORDER BY c.is_demo, c.name';
$clients = $db->query($sql)->fetchAll();

View::start('Client & Bisnis', 'clients', $me);
?>
<h1>Client & Bisnis</h1>
<p class="muted" style="margin:0 0 18px">Client = pemilik / grup usaha. Setiap client punya satu atau beberapa bisnis yang masing-masing punya sistem, langganan, dan aksesnya sendiri.</p>

<form method="get" style="display:flex;gap:8px;max-width:420px;margin-bottom:16px">
    <input name="q" value="<?= e($q) ?>" placeholder="Cari client atau bisnis…">
    <button class="btn ghost">Cari</button>
</form>

<?php if (!$clients): ?>
    <div class="soonbox">Belum ada client. Tambahkan client pertama di bawah, atau isi data contoh dengan <code>php cli/console.php demo seed</code>.</div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($clients as $c): ?>
            <a class="tile" href="/client.php?id=<?= (int)$c['id'] ?>">
                <div class="ic"><?= e(implode(' ', array_unique(array_map(fn ($t) => Biz::type($t)[1], array_filter(explode(',', (string)$c['types'])))))) ?: '▦' ?></div>
                <b><?= e($c['name']) ?></b>
                <small><?= e($c['names'] ?: 'Belum ada bisnis') ?></small>
                <div class="meta">
                    <span class="pill"><?= (int)$c['biz_count'] ?> bisnis</span>
                    <?php if ((int)$c['unpaid_count'] > 0): ?><span class="pill warn"><?= (int)$c['unpaid_count'] ?> tagihan belum lunas</span><?php endif; ?>
                    <?php if ($c['is_demo']): ?><span class="pill gold">DEMO</span><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($me['role'] === 'admin'): ?>
    <div class="card" style="margin-top:18px">
        <h2>Tambah client</h2>
        <form method="post" class="row2" style="align-items:end">
            <?= csrf_field() ?>
            <div><label>Nama client / grup</label><input name="name" required placeholder="mis. Grup Narayana"></div>
            <div><label>Nama kontak</label><input name="contact_name"></div>
            <div><label>Email</label><input name="email" type="email"></div>
            <div><label>WhatsApp / telepon</label><input name="phone"></div>
            <div><button class="btn" style="width:100%">Tambah client</button></div>
        </form>
    </div>
<?php endif; ?>
<?php View::end();
