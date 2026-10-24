<?php

/**
 * Tour 360° website (bisnis jenis Website → Pengaturan website → Tour 360°): tambah lokasi dari foto 360°, ganti nama,
 * atur tampilan awal, dan pasang penghubung (bola foto) antar lokasi dengan klik di foto. Data di Tour360.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$me = Auth::requireLogin();
$isAdmin = $me['role'] === 'admin';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$biz = Biz::business($id);
if (!$biz || !Website::available($biz)) {
    flash('Tour 360° ada di bisnis jenis Website yang sudah memilih sumber data.', 'err');
    redirect($biz ? '/business.php?id=' . $id : '/clients.php');
}
$pdo = Website::db($biz);
$cur = (string)($_GET['s'] ?? $_POST['s'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // melebihi post_max_size PHP → $_POST kosong (bukan sesi kedaluwarsa)
        flash('Foto terlalu besar untuk batas unggah server (' . ini_get('post_max_size') . ').', 'err');
    } elseif (!csrf_ok() || !$isAdmin) {
        flash(!$isAdmin ? 'Hanya admin yang bisa mengubah tour.' : 'Sesi form kedaluwarsa, silakan coba lagi.', 'err');
    } else {
        try {
            if ($action === 'add') {
                $cur = Tour360::add($pdo, $_FILES['photo'] ?? [], (string)($_POST['name_id'] ?? ''), (string)($_POST['name_en'] ?? ''));
                flash('Lokasi ditambahkan. Sekarang klik di foto untuk memasang penghubung ke lokasi lain.');
            } elseif ($action === 'rename') {
                Tour360::update($pdo, $cur, ['name_id' => $_POST['name_id'] ?? '', 'name_en' => $_POST['name_en'] ?? '']);
                flash('Nama disimpan.');
            } elseif ($action === 'view') {
                Tour360::update($pdo, $cur, ['view' => ['yaw' => $_POST['yaw'] ?? 0, 'pitch' => $_POST['pitch'] ?? 0, 'fov' => $_POST['fov'] ?? 100]]);
                flash('Tampilan awal disimpan.');
            } elseif ($action === 'first') {
                Tour360::makeFirst($pdo, $cur);
                flash('Lokasi ini sekarang dibuka pertama kali.');
            } elseif ($action === 'delete') {
                Tour360::delete($pdo, $cur);
                $cur = '';
                flash('Lokasi dihapus.');
            } elseif ($action === 'link_add') {
                $to = (string)($_POST['to'] ?? '');
                $pos = array_intersect_key($_POST, ['yaw' => 1, 'pitch' => 1, 'x' => 1, 'y' => 1]);
                $back = Tour360::addLink($pdo, $cur, $to, $pos, !empty($_POST['back']));
                $toName = array_column(array_map(fn ($s) => ['id' => $s['id'], 'n' => $s['name']['id']], Tour360::load($pdo)), 'n', 'id')[$to] ?? $to;
                flash('Penghubung dipasang.' . ($back ? " Bola kembali juga dipasang di \"{$toName}\" — buka lokasi itu untuk memindah posisinya bila kurang pas." : ''));
            } elseif ($action === 'link_del') {
                Tour360::removeLink($pdo, $cur, (string)($_POST['to'] ?? ''));
                flash('Penghubung dihapus.');
            }
            Auth::audit('website.tour360.' . $action, $biz['name'] . ($cur !== '' ? ': ' . $cur : ''));
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    }
    $back = '/tour360.php?id=' . $id . ($cur !== '' ? '&s=' . rawurlencode($cur) : '');
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        // unggah dengan persentase (XHR): kirim alamat tujuan, pesan flash tetap tersimpan untuk halaman berikutnya
        header('Content-Type: application/json');
        echo json_encode(['url' => $back]);
        exit;
    }
    redirect($back);
}

$scenes = Tour360::load($pdo);
$names = array_column(array_map(fn ($s) => ['id' => $s['id'], 'n' => $s['name']['id']], $scenes), 'n', 'id');
if (!isset($names[$cur])) {
    $cur = $scenes[0]['id'];
}
$scene = array_values(array_filter($scenes, fn ($s) => $s['id'] === $cur))[0];
// Bola kembali otomatis di lokasi ini (dihitung juga oleh website)
$autoBack = Tour360::implied($scenes, $cur);
$flat = Tour360::isFlat($scene);
$img = fn (string $s, string $z) => '/tour360-img.php?id=' . $id . '&s=' . rawurlencode($s) . '&z=' . $z;
$problem = Tour360::problem();
$limit = ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size');
// batas unggah PHP dalam byte (mis. "64M") — dicek di browser sebelum mengunggah
$limitBytes = (function (string $v): int {
    $n = (int)$v;
    return match (strtoupper(substr(trim($v), -1))) { 'G' => $n << 30, 'M' => $n << 20, 'K' => $n << 10, default => $n };
})((string)ini_get('upload_max_filesize'));
$site = $biz['domain'] ? 'https://' . $biz['domain'] . '/virtual-tour/' : null;

View::start('Tour 360° · ' . $biz['name'], 'clients', $me);
?>
<div class="crumb"><a href="/clients.php">Bisnis</a> › <a href="/business.php?id=<?= $id ?>"><?= e($biz['name']) ?></a> › <a href="/business.php?id=<?= $id ?>&tab=website">Pengaturan website</a> ›</div>
<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h1 style="margin:0">Tour 360°</h1>
    <?php if ($site): ?><a class="btn ghost sm" href="<?= e($site) ?>" target="_blank" rel="noopener">Lihat di website ↗</a><?php endif; ?>
</div>
<p class="muted" style="margin:4px 0 12px;font-size:12px">Halaman <b>/virtual-tour/</b> website (menu Explore → 360° Virtual Tour). Tiap lokasi = satu foto 360° atau foto biasa; pengunjung pindah lokasi lewat bola foto yang Anda pasang.</p>
<?php if ($problem): ?><div class="soonbox" style="margin-bottom:12px"><?= e($problem) ?></div><?php endif; ?>

<style>
    .t3 { display: grid; grid-template-columns: 280px 1fr; gap: 14px; align-items: start; }
    @media (max-width: 860px) { .t3 { grid-template-columns: 1fr; } }
    .t3-list a { display: flex; gap: 10px; align-items: center; padding: 7px; border-radius: 9px; color: inherit; text-decoration: none; border: 1px solid transparent; }
    .t3-list a:hover { background: var(--bg2, rgba(0,0,0,.03)); }
    .t3-list a.on { border-color: var(--brand); background: var(--bg2, rgba(0,0,0,.03)); }
    .t3-list img { width: 64px; height: 40px; object-fit: cover; border-radius: 6px; flex: none; background: #ccc; }
    .t3-list small { display: block; color: var(--ink2); font-size: 11px; }
    #pano { position: relative; width: 100%; height: min(62vh, 560px); border-radius: 10px; overflow: hidden; background: #111; cursor: crosshair; }
    .t3-hs > div { transform: translate(-50%, -50%); text-align: center; cursor: default; }
    .t3-hs .ball { width: 46px; height: 46px; margin: 0 auto; border-radius: 50%; border: 3px solid #fff; background: #555 center/cover; box-shadow: 0 2px 8px rgba(0,0,0,.5); }
    .t3-hs .lb { display: inline-block; margin-top: 4px; padding: 2px 8px; border-radius: 99px; background: rgba(0,0,0,.6); color: #fff; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .t3-new > div { transform: translate(-50%, -50%); width: 22px; height: 22px; border-radius: 50%; border: 3px solid #fff; background: var(--brand); box-shadow: 0 0 0 4px rgba(255,255,255,.35); }
    #linkBox { display: none; position: absolute; left: 50%; bottom: 12px; transform: translateX(-50%); z-index: 5; background: var(--card, #fff); color: var(--ink); padding: 10px; border-radius: 10px; box-shadow: 0 6px 24px rgba(0,0,0,.35); width: min(420px, calc(100% - 24px)); }
    .t3-hint { position: absolute; left: 10px; top: 10px; z-index: 4; padding: 4px 10px; border-radius: 99px; background: rgba(0,0,0,.6); color: #fff; font-size: 11.5px; pointer-events: none; }
</style>

<div class="t3">
    <div>
        <div class="card t3-list" style="padding:8px">
            <?php foreach ($scenes as $i => $s): ?>
                <a class="<?= $s['id'] === $cur ? 'on' : '' ?>" href="/tour360.php?id=<?= $id ?>&s=<?= rawurlencode($s['id']) ?>">
                    <img src="<?= e($img($s['id'], 'thumb')) ?>" alt="" loading="lazy">
                    <span style="min-width:0"><b><?= e($s['name']['id']) ?></b><small><?= $i === 0 ? 'dibuka pertama · ' : '' ?><?= Tour360::isFlat($s) ? 'foto biasa · ' : '' ?><?= count($s['links'] ?? []) ?> penghubung</small></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($isAdmin): ?>
            <form method="post" enctype="multipart/form-data" class="card" id="addForm">
                <h2>Tambah lokasi</h2>
                <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="id" value="<?= $id ?>">
                <div class="field"><label>Nama (Indonesia)</label><input name="name_id" placeholder="mis. Bens Cafe" required></div>
                <div class="field"><label>Nama (English)</label><input name="name_en" placeholder="opsional, mis. Bens Cafe"></div>
                <div class="field"><label>Foto 360° atau foto biasa</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
                    <div class="muted" style="font-size:11px;margin-top:2px">Foto 360° (JPG, lebar = 2 × tinggi, mis. 8192×4096 dari app DJI / kamera 360) otomatis jadi panorama; foto lain (JPG/PNG/WEBP dari HP/kamera) jadi foto biasa yang bisa di-zoom. Batas unggah server: <?= e($limit) ?>.</div></div>
                <button class="btn" <?= $problem ? 'disabled' : '' ?>>Unggah & tambah</button>
                <div id="addProg" style="display:none;margin-top:8px">
                    <div style="height:8px;border-radius:99px;background:var(--line);overflow:hidden"><div id="addBar" style="height:100%;width:0;background:var(--brand);transition:width .2s"></div></div>
                    <div class="muted" id="addTxt" style="font-size:11.5px;margin-top:4px">Mengunggah… 0%</div>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <div>
        <div class="card" style="padding:10px">
            <div id="pano">
                <span class="t3-hint"><?= $isAdmin ? 'Klik di foto untuk memasang penghubung · ' : '' ?><?= $flat ? 'foto biasa: gulir untuk zoom, seret untuk menggeser' : 'seret untuk melihat sekeliling' ?></span>
                <form method="post" id="linkBox">
                    <?= csrf_field() ?><input type="hidden" name="action" value="link_add"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="s" value="<?= e($cur) ?>">
                    <input type="hidden" name="yaw"><input type="hidden" name="pitch"><input type="hidden" name="x"><input type="hidden" name="y">
                    <b style="font-size:13px">Penghubung di titik ini menuju:</b>
                    <div style="display:flex;gap:6px;margin-top:6px">
                        <select name="to" style="flex:1" required>
                            <option value="">— pilih lokasi —</option>
                            <?php foreach ($scenes as $s): if ($s['id'] !== $cur): ?><option value="<?= e($s['id']) ?>"><?= e($s['name']['id']) ?></option><?php endif; endforeach; ?>
                        </select>
                        <button class="btn sm">Pasang</button>
                        <button type="button" class="btn ghost sm" id="linkCancel">Batal</button>
                    </div>
                    <label style="display:flex;gap:6px;align-items:center;margin:6px 0 0;font-size:11.5px;color:var(--ink)"><input type="checkbox" name="back" value="1" checked style="width:auto"> Pasang juga bola kembali di lokasi tujuan (bila belum ada)</label>
                    <?php if (count($scenes) < 2): ?><div class="muted" style="font-size:11px;margin-top:4px">Tambah lokasi lain dulu (form kiri).</div><?php endif; ?>
                </form>
            </div>
            <?php if ($isAdmin): ?>
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
                    <form method="post" id="viewForm" style="margin:0;<?= $flat ? 'display:none' : '' ?>">
                        <?= csrf_field() ?><input type="hidden" name="action" value="view"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="s" value="<?= e($cur) ?>">
                        <input type="hidden" name="yaw"><input type="hidden" name="pitch"><input type="hidden" name="fov">
                        <button class="btn ghost sm" title="Arah & zoom yang terlihat sekarang dipakai saat lokasi ini dibuka">Simpan tampilan sekarang sebagai awal</button>
                    </form>
                    <?php if ($scenes[0]['id'] !== $cur): ?>
                        <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="first"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="s" value="<?= e($cur) ?>"><button class="btn ghost sm">Jadikan lokasi pertama</button></form>
                    <?php endif; ?>
                    <?php if (count($scenes) > 1): ?>
                        <form method="post" style="margin:0 0 0 auto" onsubmit="return confirm('Hapus lokasi ini beserta fotonya dan semua penghubung ke sini?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="s" value="<?= e($cur) ?>"><button class="btn ghost sm" style="color:var(--bad)">Hapus lokasi</button></form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Penghubung dari lokasi ini <span class="muted" style="text-transform:none;letter-spacing:0">· <?= count($scene['links'] ?? []) ?></span></h2>
            <?php if (empty($scene['links'])): ?>
                <p class="muted" style="margin:0;font-size:12px">Belum ada. Klik di foto (mis. di arah kolam atau resto) lalu pilih lokasi tujuannya. Bola kembali di lokasi tujuan dibuat otomatis; untuk memindahnya, buka lokasi itu, klik di titik yang pas, lalu pilih lokasi ini lagi.</p>
            <?php else: ?>
                <table class="sm">
                    <?php foreach ($scene['links'] as $l): ?>
                        <tr>
                            <td><b><?= e($names[$l['to']] ?? $l['to']) ?></b> <span class="muted" style="font-size:11px"><?= $flat ? 'posisi ' . round((float)($l['x'] ?? 0) * 100) . '% dari kiri, ' . round((float)($l['y'] ?? 0) * 100) . '% dari atas' : 'arah ' . (int)($l['yaw'] ?? 0) . '°, ' . (int)($l['pitch'] ?? 0) . '°' ?></span></td>
                            <td style="text-align:right;white-space:nowrap">
                                <?php if (!$flat): ?><button type="button" class="btn ghost sm" data-look="<?= (float)$l['yaw'] ?>,<?= (float)$l['pitch'] ?>">Lihat</button><?php endif; ?>
                                <?php if ($isAdmin): ?><form method="post" style="display:inline;margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="link_del"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="s" value="<?= e($cur) ?>"><input type="hidden" name="to" value="<?= e($l['to']) ?>"><button class="btn ghost sm" style="color:var(--bad)">Hapus</button></form><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <?php if ($isAdmin): ?>
            <form method="post" class="card">
                <h2>Nama lokasi</h2>
                <?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="s" value="<?= e($cur) ?>">
                <div class="row2">
                    <div class="field"><label>Indonesia</label><input name="name_id" value="<?= e($scene['name']['id']) ?>" required></div>
                    <div class="field"><label>English</label><input name="name_en" value="<?= e($scene['name']['en']) ?>"></div>
                </div>
                <button class="btn sm">Simpan nama</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script src="/vendor/marzipano-0.10.2.js"></script>
<script>
(function () {
    const SCENE = <?= json_encode($scene, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
    const NAMES = <?= json_encode($names, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const IMG = <?= json_encode($img($cur, 'ZZ'), JSON_UNESCAPED_SLASHES) ?>;
    const THUMB = id => <?= json_encode('/tour360-img.php?id=' . $id . '&z=thumb&s=', JSON_UNESCAPED_SLASHES) ?> + encodeURIComponent(id);
    const ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
    const BACK = <?= json_encode($autoBack, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const rad = d => d * Math.PI / 180, deg = r => r * 180 / Math.PI;
    const pano = document.getElementById('pano');

    const FLAT = SCENE.type === 'flat';
    // posisi hotspot: foto biasa {x,y} (0…1), 360° {yaw,pitch} (radian)
    const at = l => FLAT ? { x: +l.x, y: +l.y } : { yaw: rad(l.yaw), pitch: rad(l.pitch) };

    const viewer = new Marzipano.Viewer(pano, { controls: { mouseViewMode: 'drag' } });
    let source, geometry, view;
    if (FLAT) {
        // foto biasa: satu gambar utuh, muat penuh di layar (letterbox), zoom sampai resolusi asli
        source = new Marzipano.ImageUrlSource(() => ({ url: IMG.replace('ZZ', 'full') }));
        geometry = new Marzipano.FlatGeometry([{ width: SCENE.w, height: SCENE.h, tileWidth: SCENE.w, tileHeight: SCENE.h }]);
        view = new Marzipano.FlatView({ x: .5, y: .5, zoom: 99, mediaAspectRatio: SCENE.w / SCENE.h },
            Marzipano.util.compose(Marzipano.FlatView.limit.resolution(SCENE.w), Marzipano.FlatView.limit.letterbox()));
    } else {
        source = new Marzipano.ImageUrlSource(t => ({ url: IMG.replace('ZZ', t.z === 0 ? '4096' : '8192') }));
        geometry = new Marzipano.EquirectGeometry([{ width: 4096 }, { width: 8192 }]);
        view = new Marzipano.RectilinearView({ yaw: rad(SCENE.view.yaw), pitch: rad(SCENE.view.pitch), fov: rad(SCENE.view.fov) },
            Marzipano.RectilinearView.limit.traditional(8192, rad(120)));
    }
    const scene = viewer.createScene({ source, geometry, view, pinFirstLevel: true });
    scene.switchTo();
    const hc = scene.hotspotContainer();

    // Penghubung yang sudah ada (Marzipano menimpa transform elemen hotspot → isi dibungkus div dalam)
    // + bola kembali otomatis (sama dengan website): lokasi lain menuju sini, sini belum punya penghubung ke sana
    const links = (SCENE.links || []).slice();
    BACK.forEach(l => { if (!links.some(x => x.to === l.to)) links.push(Object.assign({ auto: true }, l)); });
    links.forEach(l => {
        const el = document.createElement('div');
        el.className = 't3-hs';
        if (l.auto) { el.style.opacity = '.65'; el.title = 'Bola kembali otomatis — klik di titik yang pas lalu pilih lokasi ini untuk memindahnya'; }
        el.innerHTML = '<div><div class="ball"></div><span class="lb"></span></div>';
        el.querySelector('.ball').style.backgroundImage = 'url("' + THUMB(l.to) + '")';
        el.querySelector('.lb').textContent = (NAMES[l.to] || l.to) + (l.auto ? ' (otomatis)' : '');
        el.addEventListener('mousedown', e => e.stopPropagation());
        hc.createHotspot(el, at(l));
    });

    // Klik (bukan seret) di foto → tanda titik baru + kotak pilih tujuan
    const box = document.getElementById('linkBox');
    let marker = null, down = null;
    pano.addEventListener('pointerdown', e => { down = { x: e.clientX, y: e.clientY }; });
    pano.addEventListener('pointerup', e => {
        if (!ADMIN || !down || box.contains(e.target) || Math.hypot(e.clientX - down.x, e.clientY - down.y) > 5) return;
        const r = pano.getBoundingClientRect();
        const c = view.screenToCoordinates({ x: e.clientX - r.left, y: e.clientY - r.top });
        if (!c || (FLAT && (c.x < 0 || c.x > 1 || c.y < 0 || c.y > 1))) return; // foto biasa: klik di luar foto diabaikan
        if (marker) hc.destroyHotspot(marker);
        const el = document.createElement('div');
        el.className = 't3-new';
        el.innerHTML = '<div></div>';
        if (FLAT) {
            marker = hc.createHotspot(el, { x: c.x, y: c.y });
            box.x.value = c.x.toFixed(4);
            box.y.value = c.y.toFixed(4);
        } else {
            marker = hc.createHotspot(el, { yaw: c.yaw, pitch: c.pitch });
            box.yaw.value = deg(c.yaw).toFixed(1);
            box.pitch.value = deg(c.pitch).toFixed(1);
        }
        box.style.display = 'block';
    });
    document.getElementById('linkCancel').onclick = () => { box.style.display = 'none'; if (marker) { hc.destroyHotspot(marker); marker = null; } };

    // Simpan tampilan awal = arah & zoom sekarang
    const vf = document.getElementById('viewForm');
    if (vf) vf.addEventListener('submit', () => {
        let y = deg(view.yaw()) % 360;
        if (y > 180) y -= 360; else if (y < -180) y += 360;
        vf.yaw.value = y.toFixed(1);
        vf.pitch.value = deg(view.pitch()).toFixed(1);
        vf.fov.value = deg(view.fov()).toFixed(0);
    });

    // Tombol "Lihat" → putar ke arah penghubung
    document.querySelectorAll('[data-look]').forEach(b => b.onclick = () => {
        const [y, p] = b.dataset.look.split(',').map(Number);
        scene.lookTo({ yaw: rad(y), pitch: rad(p), fov: view.fov() }, { transitionDuration: 700 });
        pano.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    // Unggah foto dengan persentase (XHR); server membalas JSON {url} lalu halaman pindah ke lokasi baru
    const af = document.getElementById('addForm');
    const LIMIT = <?= $limitBytes ?>;
    if (af) af.addEventListener('submit', e => {
        e.preventDefault();
        const file = af.photo.files[0];
        const btn = af.querySelector('button'), bar = document.getElementById('addBar'), txt = document.getElementById('addTxt');
        const mb = n => (n / 1048576).toFixed(1) + ' MB';
        if (file && LIMIT && file.size > LIMIT) { alert('Foto ' + mb(file.size) + ' lebih besar dari batas unggah server (' + mb(LIMIT) + ').'); return; }
        const fail = msg => { txt.textContent = msg; txt.style.color = 'var(--bad)'; bar.style.background = 'var(--bad)'; btn.disabled = false; };
        btn.disabled = true;
        txt.style.color = ''; bar.style.background = ''; bar.style.width = '0';
        document.getElementById('addProg').style.display = 'block';
        const xhr = new XMLHttpRequest();
        xhr.open('POST', location.pathname + location.search);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.onprogress = ev => {
            if (!ev.lengthComputable) return;
            const p = Math.round(ev.loaded / ev.total * 100);
            bar.style.width = p + '%';
            txt.textContent = 'Mengunggah… ' + p + '% (' + mb(ev.loaded) + ' / ' + mb(ev.total) + ')';
        };
        xhr.upload.onload = () => { bar.style.width = '100%'; txt.textContent = 'Terunggah 100% — memproses foto (mengecilkan & membuat gambar kecil)… bisa 1–2 menit.'; };
        xhr.onload = () => {
            if (xhr.status === 413) return fail('Ditolak server: foto terlalu besar untuk batas unggah Nginx (client_max_body_size).');
            let r = null;
            try { r = JSON.parse(xhr.responseText); } catch (_) {}
            if (xhr.status !== 200 || !r || !r.url) return fail('Gagal (HTTP ' + xhr.status + '). Coba lagi.');
            location.href = r.url;
        };
        xhr.onerror = () => fail('Koneksi terputus saat mengunggah. Coba lagi.');
        xhr.send(new FormData(af));
    });
})();
</script>
<?php View::end(); ?>
