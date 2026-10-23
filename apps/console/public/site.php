<?php

/**
 * Website jualan adfsystem.store — edit isi halaman (pengganti menu "Edit …" di adfsystem.store/admin).
 * Data tetap di content.json milik website (StoreSite), jadi website langsung berubah tanpa deploy.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireAdmin();

/*
 * Daftar yang bisa ditambah/diubah/dihapus: tab => [judul, path di content.json, field, petunjuk]
 * field: nama => [label, jenis text|textarea|number|check|lines|color|image, opsi (subfolder upload untuk image)]
 */
$LISTS = [
    'layanan' => ['Layanan', ['layanan', 'items'], [
        'icon' => ['Ikon (emoji)', 'text'], 'color' => ['Warna', 'color'], 'title' => ['Judul', 'text'], 'desc' => ['Deskripsi', 'textarea'],
    ], 'Kartu di halaman Layanan.'],
    'modul' => ['Modul', ['modules'], [
        'icon' => ['Ikon (emoji)', 'text'], 'color' => ['Warna', 'color'], 'title' => ['Judul', 'text'], 'desc' => ['Deskripsi', 'textarea'],
    ], 'Kartu modul di halaman depan.'],
    'harga' => ['Harga', ['products'], [
        'title' => ['Nama paket', 'text'], 'price' => ['Harga (Rp, 0 = custom/konsultasi)', 'number'], 'featured' => ['Paling populer', 'check'],
        'tagline' => ['Tagline', 'text'], 'features' => ['Fitur (satu per baris)', 'lines'], 'notes' => ['Catatan peringatan (opsional, satu per baris)', 'lines'],
    ], 'Kartu di halaman Harga. Harga > 0 → tombol "Langganan" ke pembayaran Pakasir; 0 → tombol ke Kontak.'],
    'portofolio' => ['Portofolio', ['portfolio'], [
        'icon' => ['Ikon (emoji)', 'text'], 'color' => ['Warna', 'color'], 'tag' => ['Label kecil', 'text'], 'title' => ['Judul', 'text'],
        'desc' => ['Deskripsi', 'textarea'], 'link' => ['Link (opsional)', 'text'], 'image' => ['Gambar (opsional)', 'image', 'portfolio'],
    ], 'Contoh hasil kerja / produk.'],
    'klien' => ['Klien', ['clients'], [
        'name' => ['Nama perusahaan', 'text'], 'link' => ['Link (opsional)', 'text'], 'logo' => ['Logo (opsional)', 'image', 'clients'],
    ], 'Logo klien yang tampil di website.'],
];
$TABS = ['beranda' => 'Beranda'] + array_map(fn ($l) => $l[0], $LISTS) + ['bayar' => 'Pembayaran'];
$tab = (string)($_GET['tab'] ?? 'beranda');
if (!isset($TABS[$tab])) {
    $tab = 'beranda';
}
$problem = StoreSite::problem();

/** Ambil / taruh nilai di array bertingkat */
$get = function (array $a, array $path) {
    foreach ($path as $k) {
        $a = $a[$k] ?? [];
    }
    return $a;
};
$set = function (array &$a, array $path, $value): void {
    $ref = &$a;
    foreach ($path as $k) {
        $ref = &$ref[$k];
    }
    $ref = $value;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$problem) {
    $back = '/site.php?tab=' . $tab;
    if (!csrf_ok()) {
        flash('Sesi form kedaluwarsa, silakan coba lagi.', 'err');
        redirect($back);
    }
    $c = StoreSite::load();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'hero') {
            $c['hero']['title'] = trim((string)($_POST['title'] ?? ''));
            $c['hero']['subtitle'] = trim((string)($_POST['subtitle'] ?? ''));
            if ($c['hero']['title'] === '' || $c['hero']['subtitle'] === '') {
                throw new RuntimeException('Judul dan deskripsi tidak boleh kosong.');
            }
            if (!empty($_POST['remove_background'])) {
                StoreSite::deleteUpload($c['hero']['background']);
                $c['hero']['background'] = '';
            }
            if ($up = StoreSite::upload('background', 'hero')) {
                StoreSite::deleteUpload($c['hero']['background']);
                $c['hero']['background'] = $up;
            }
        } elseif ($action === 'logo') {
            if (!empty($_POST['remove_logo'])) {
                StoreSite::deleteUpload($c['branding']['logo']);
                $c['branding']['logo'] = '';
            }
            if ($up = StoreSite::upload('logo', 'branding')) {
                StoreSite::deleteUpload($c['branding']['logo']);
                $c['branding']['logo'] = $up;
            }
        } elseif ($action === 'contact') {
            $email = trim((string)($_POST['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Email tidak valid.');
            }
            $c['contact'] = ['email' => $email, 'whatsapp' => trim((string)($_POST['whatsapp'] ?? '')), 'address' => trim((string)($_POST['address'] ?? ''))];
        } elseif ($action === 'layanan_hero') {
            $c['layanan']['hero'] = ['title' => trim((string)($_POST['title'] ?? '')), 'subtitle' => trim((string)($_POST['subtitle'] ?? ''))];
        } elseif ($action === 'payment') {
            // Kunci rahasia: kosong = tetap
            $p = $c['payment'];
            $p['provider'] = 'pakasir';
            $p['project_slug'] = trim((string)($_POST['project_slug'] ?? ''));
            foreach (['api_key', 'webhook_secret'] as $k) {
                $v = trim((string)($_POST[$k] ?? ''));
                if ($v !== '') {
                    $p[$k] = $v;
                }
            }
            $p['sandbox'] = false;
            $c['payment'] = $p;
        } elseif (($action === 'item_save' || $action === 'item_delete') && isset($LISTS[$tab])) {
            [, $path, $fields] = $LISTS[$tab];
            $items = array_values((array)$get($c, $path));
            $i = (int)($_POST['index'] ?? -1);
            $old = $items[$i] ?? null;
            if ($action === 'item_delete') {
                if ($old !== null) {
                    foreach ($fields as $f => [, $kind]) {
                        if ($kind === 'image') {
                            StoreSite::deleteUpload((string)($old[$f] ?? ''));
                        }
                    }
                    array_splice($items, $i, 1);
                }
            } else {
                $row = [];
                foreach ($fields as $f => [, $kind]) {
                    $raw = (string)($_POST[$f] ?? '');
                    $row[$f] = match ($kind) {
                        'number' => max(0, (int)preg_replace('/\D/', '', $raw)),
                        'check' => !empty($_POST[$f]),
                        'lines' => StoreSite::lines($raw),
                        'color' => isset(StoreSite::COLORS[$raw]) ? $raw : 'c-orange',
                        'image' => (string)($old[$f] ?? ''),
                        default => trim($raw),
                    };
                    if ($kind === 'image') {
                        if (!empty($_POST['remove_' . $f])) {
                            StoreSite::deleteUpload($row[$f]);
                            $row[$f] = '';
                        }
                        if ($up = StoreSite::upload('file_' . $f, $fields[$f][2])) {
                            StoreSite::deleteUpload($row[$f]);
                            $row[$f] = $up;
                        }
                    }
                }
                if (in_array($tab, ['layanan', 'modul'], true) && $row['icon'] === '') {
                    $row['icon'] = '📦';
                }
                // wajib diisi: judul (atau nama untuk Klien)
                $first = isset($fields['title']) ? 'title' : 'name';
                if (($row[$first] ?? '') === '') {
                    throw new RuntimeException($fields[$first][0] . ' tidak boleh kosong.');
                }
                if ($old !== null) {
                    $items[$i] = $row;
                } else {
                    $items[] = $row;
                }
            }
            $set($c, $path, $items);
        } else {
            throw new RuntimeException('Aksi tidak dikenal.');
        }
        StoreSite::save($c);
        Auth::audit('site.update', "{$tab}: {$action}");
        flash($action === 'item_delete' ? 'Dihapus.' : 'Tersimpan — website langsung berubah.');
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect($back);
}

$c = $problem ? [] : StoreSite::load();
$img = fn (string $rel) => $rel !== '' ? '<img src="' . e(StoreSite::url($rel)) . '" alt="" style="max-height:70px;max-width:180px;border-radius:8px;display:block;margin:4px 0;background:#2a3140;padding:4px">' : '';

/** Satu field form untuk item daftar */
$fieldHtml = function (string $f, array $def, $val) use ($img): string {
    [$label, $kind] = $def;
    $n = e($f);
    return match ($kind) {
        'textarea' => "<div class=\"field\"><label>{$label}</label><textarea name=\"{$n}\" rows=\"3\">" . e((string)$val) . '</textarea></div>',
        'lines' => "<div class=\"field\"><label>{$label}</label><textarea name=\"{$n}\" rows=\"5\">" . e(implode("\n", (array)$val)) . '</textarea></div>',
        'number' => "<div class=\"field\"><label>{$label}</label><input name=\"{$n}\" inputmode=\"numeric\" value=\"" . (int)$val . '"></div>',
        'check' => "<label style=\"display:flex;gap:6px;align-items:center;margin:0 0 9px\"><input type=\"checkbox\" name=\"{$n}\" value=\"1\" style=\"width:auto\" " . ($val ? 'checked' : '') . "> {$label}</label>",
        'color' => "<div class=\"field\"><label>{$label}</label><select name=\"{$n}\">" . implode('', array_map(fn ($k, $l) => '<option value="' . $k . '"' . ($val === $k ? ' selected' : '') . ">{$l}</option>", array_keys(StoreSite::COLORS), StoreSite::COLORS)) . '</select></div>',
        'image' => "<div class=\"field\"><label>{$label}</label>" . $img((string)$val)
            . ($val ? "<label style=\"display:flex;gap:4px;align-items:center;margin:0 0 4px;font-size:12px\"><input type=\"checkbox\" name=\"remove_{$n}\" value=\"1\" style=\"width:auto\"> hapus gambar</label>" : '')
            . "<input type=\"file\" name=\"file_{$n}\" accept=\"image/png,image/jpeg,image/webp,image/gif\"></div>",
        default => "<div class=\"field\"><label>{$label}</label><input name=\"{$n}\" value=\"" . e((string)$val) . '"></div>',
    };
};

View::start('Website', 'site', $me);
?>
<div class="pagehead">
    <div><h1>Website jualan</h1><p>Isi halaman <a href="<?= e(StoreSite::url()) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://#', '', StoreSite::url())) ?></a> — perubahan langsung tampil.</p></div>
    <a class="btn ghost sm" href="<?= e(StoreSite::url()) ?>" target="_blank" rel="noopener">Lihat website</a>
</div>
<nav class="tabs">
    <?php foreach ($TABS as $k => $label): ?>
        <a class="<?= $tab === $k ? 'on' : '' ?>" href="/site.php?tab=<?= e($k) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($problem): ?>
    <div class="soonbox" style="border-color:var(--bad)"><?= e($problem) ?></div>

<?php elseif ($tab === 'beranda'): ?>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(320px,1fr))">
        <form class="card" method="post" enctype="multipart/form-data" style="margin:0">
            <?= csrf_field() ?><input type="hidden" name="action" value="hero">
            <h2>Judul utama (hero)</h2>
            <div class="field"><label>Judul</label><input name="title" value="<?= e($c['hero']['title']) ?>" required></div>
            <div class="field"><label>Deskripsi</label><textarea name="subtitle" rows="4" required><?= e($c['hero']['subtitle']) ?></textarea></div>
            <div class="field"><label>Gambar latar (opsional)</label><?= $img($c['hero']['background']) ?>
                <?php if ($c['hero']['background']): ?><label style="display:flex;gap:4px;align-items:center;margin:0 0 4px;font-size:12px"><input type="checkbox" name="remove_background" value="1" style="width:auto"> hapus gambar</label><?php endif; ?>
                <input type="file" name="background" accept="image/png,image/jpeg,image/webp,image/gif"></div>
            <button class="btn sm">Simpan</button>
        </form>
        <div>
            <form class="card" method="post" enctype="multipart/form-data">
                <?= csrf_field() ?><input type="hidden" name="action" value="logo">
                <h2>Logo</h2>
                <?= $img($c['branding']['logo']) ?: '<p class="muted" style="font-size:12px">Belum ada logo — website memakai ikon huruf bawaan.</p>' ?>
                <?php if ($c['branding']['logo']): ?><label style="display:flex;gap:4px;align-items:center;margin:0 0 6px;font-size:12px"><input type="checkbox" name="remove_logo" value="1" style="width:auto"> hapus logo</label><?php endif; ?>
                <div class="field"><input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif"></div>
                <button class="btn sm">Simpan</button>
            </form>
            <form class="card" method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="contact">
                <h2>Kontak</h2>
                <div class="row2">
                    <div class="field"><label>Email</label><input name="email" value="<?= e($c['contact']['email']) ?>" required></div>
                    <div class="field"><label>WhatsApp</label><input name="whatsapp" value="<?= e($c['contact']['whatsapp']) ?>" placeholder="62812…"></div>
                </div>
                <div class="field"><label>Alamat</label><textarea name="address" rows="2"><?= e($c['contact']['address']) ?></textarea></div>
                <button class="btn sm">Simpan</button>
            </form>
        </div>
    </div>

<?php elseif ($tab === 'bayar'): $p = $c['payment']; ?>
    <form class="card" method="post" autocomplete="off" style="max-width:560px">
        <?= csrf_field() ?><input type="hidden" name="action" value="payment">
        <h2>Pakasir</h2>
        <p class="muted" style="margin:-4px 0 10px;font-size:12px">Dipakai tombol "Langganan" di halaman Harga. Webhook Pakasir: <code><?= e(StoreSite::url('api/pakasir-webhook.php')) ?></code></p>
        <div class="field"><label>Project slug</label><input name="project_slug" value="<?= e($p['project_slug']) ?>"></div>
        <?php foreach (['api_key' => 'API key', 'webhook_secret' => 'Webhook secret'] as $k => $label): $v = (string)$p[$k]; ?>
            <div class="field"><label><?= e($label) ?> <?= $v !== '' ? '<span class="pill ok">tersimpan ••••' . e(mb_substr($v, -4)) . '</span>' : '<span class="pill warn">kosong</span>' ?></label>
                <input type="password" name="<?= e($k) ?>" placeholder="<?= $v !== '' ? 'kosongkan = tetap' : '' ?>" autocomplete="new-password"></div>
        <?php endforeach; ?>
        <button class="btn sm">Simpan</button>
    </form>

<?php else: [$title, $path, $fields, $hint] = $LISTS[$tab]; $items = array_values((array)$get($c, $path)); ?>
    <p class="muted" style="margin:0 0 12px;font-size:12px"><?= e($hint) ?></p>
    <?php if ($tab === 'layanan'): ?>
        <form class="card" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="layanan_hero">
            <h2>Judul halaman Layanan</h2>
            <div class="row2">
                <div class="field"><label>Judul</label><input name="title" value="<?= e($c['layanan']['hero']['title']) ?>"></div>
                <div class="field"><label>Deskripsi</label><input name="subtitle" value="<?= e($c['layanan']['hero']['subtitle']) ?>"></div>
            </div>
            <button class="btn sm">Simpan</button>
        </form>
    <?php endif; ?>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(300px,1fr))">
        <?php foreach ($items as $i => $it): ?>
            <form class="card" method="post" enctype="multipart/form-data" style="margin:0">
                <?= csrf_field() ?><input type="hidden" name="index" value="<?= (int)$i ?>">
                <h2 style="display:flex;justify-content:space-between;gap:6px"><span><?= e(($it['icon'] ?? '') . ' ' . ($it['title'] ?? $it['name'] ?? '')) ?></span><span class="muted">#<?= $i + 1 ?></span></h2>
                <?php foreach ($fields as $f => $def): ?><?= $fieldHtml($f, $def, $it[$f] ?? '') ?><?php endforeach; ?>
                <div style="display:flex;gap:6px">
                    <button class="btn sm" name="action" value="item_save">Simpan</button>
                    <button class="btn ghost sm" name="action" value="item_delete" onclick="return confirm('Hapus item ini dari website?')">Hapus</button>
                </div>
            </form>
        <?php endforeach; ?>
        <form class="card" method="post" enctype="multipart/form-data" style="margin:0;border-style:dashed">
            <?= csrf_field() ?><input type="hidden" name="index" value="-1">
            <h2>+ Tambah <?= e(mb_strtolower($title)) ?></h2>
            <?php foreach ($fields as $f => $def): ?><?= $fieldHtml($f, $def, $def[1] === 'color' ? 'c-orange' : '') ?><?php endforeach; ?>
            <button class="btn sm" name="action" value="item_save">Tambah</button>
        </form>
    </div>
<?php endif; ?>
<?php View::end();
