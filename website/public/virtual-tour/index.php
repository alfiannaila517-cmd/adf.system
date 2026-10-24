<?php

/**
 * Tour 360° Narayana. Daftar lokasi diatur dari ADF Console (Narayana Website → Tour 360°), tersimpan di settings
 * "web_tour360" (JSON) database hotel. Belum ada data → foto udara bawaan (img/aerial-*).
 * Tiap lokasi: id, name {id,en}, base (awalan gambar: <base>-4096.jpg / -8192.jpg / -thumb.jpg), view {yaw,pitch,fov},
 * links [{to, yaw, pitch}] (pitch positif = ke bawah).
 */
$_cfg = dirname(__DIR__) . '/config/config.php';
if (!file_exists($_cfg)) $_cfg = dirname(__DIR__, 2) . '/config/config.php';
require_once $_cfg;

$scenes = [];
try {
    $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'web_tour360'");
    $st->execute();
    $scenes = json_decode((string)$st->fetchColumn(), true) ?: [];
} catch (Throwable $e) {
    $scenes = [];
}
// Musik latar: file MP3 dari Console (settings web_tour360_music), kosong = musik bawaan yang dibuat di browser
$music = '';
try {
    $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'web_tour360_music'");
    $st->execute();
    $music = (string)$st->fetchColumn();
} catch (Throwable $e) {
    $music = '';
}
if (!$scenes) {
    $scenes = [['id' => 'aerial', 'name' => ['id' => 'Pemandangan Udara Karimunjawa', 'en' => 'Karimunjawa Aerial View'], 'base' => 'img/aerial', 'view' => ['yaw' => 25, 'pitch' => 12, 'fov' => 100], 'links' => []]];
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>Tour 360° · Narayana Hotel Karimunjawa</title>
    <meta name="description" content="Jelajahi Narayana Hotel Karimunjawa dalam 360° — lihat pulau, laut, dan resort dari udara.">
    <meta property="og:title" content="Tour 360° Narayana Hotel Karimunjawa">
    <meta property="og:image" content="img/aerial-4096.jpg">
    <link rel="icon" href="img/logo-round.png">
    <style>
        :root { --gold: #b4975a; --gold2: #ecc46b; --glass: rgba(42, 30, 14, .62); --ink: #fff; }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html, body { margin: 0; height: 100%; overflow: hidden; background: #0b0b0b; font-family: 'Lato', 'Segoe UI', system-ui, sans-serif; color: var(--ink); }
        #pano { position: absolute; inset: 0; }

        /* header */
        .hd { position: absolute; top: 0; left: 0; right: 0; display: flex; align-items: center; gap: 12px; padding: 14px 18px; background: linear-gradient(to bottom, rgba(0, 0, 0, .55), transparent); z-index: 5; pointer-events: none; }
        .hd > * { pointer-events: auto; }
        .brand { display: flex; align-items: center; gap: 10px; text-decoration: none; color: #fff; }
        .brand img { width: 46px; height: 46px; border-radius: 50%; box-shadow: 0 4px 14px rgba(0, 0, 0, .4); }
        .brand b { display: block; font-size: 14px; letter-spacing: .14em; text-transform: uppercase; }
        .brand small { display: block; font-size: 11px; color: var(--gold2); letter-spacing: .06em; }
        .sp { flex: 1; }
        .lang { display: flex; border: 1px solid rgba(255, 255, 255, .35); border-radius: 99px; overflow: hidden; }
        .lang button { border: 0; background: transparent; color: #fff; font-size: 11px; font-weight: 700; padding: 6px 11px; cursor: pointer; }
        .lang button.on { background: var(--gold); }
        .book { border: 1px solid var(--gold2); color: var(--gold2); text-decoration: none; font-size: 12px; font-weight: 700; letter-spacing: .14em; padding: 10px 18px; text-transform: uppercase; background: rgba(0, 0, 0, .25); }
        .book:hover { background: var(--gold); color: #fff; border-color: var(--gold); }

        /* judul titik */
        .title { position: absolute; left: 18px; bottom: 92px; z-index: 5; max-width: 70vw; text-shadow: 0 2px 10px rgba(0, 0, 0, .6); }
        .title small { font-size: 11px; letter-spacing: .2em; text-transform: uppercase; color: var(--gold2); }
        .title h1 { margin: 2px 0 0; font-size: 22px; font-weight: 700; letter-spacing: .02em; }

        /* bar tombol bawah */
        .bar { position: absolute; left: 50%; bottom: 20px; transform: translateX(-50%); z-index: 5; display: flex; gap: 4px; padding: 6px 8px; border-radius: 14px; background: var(--glass); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); box-shadow: 0 10px 30px rgba(0, 0, 0, .35); }
        .bar button { width: 42px; height: 38px; border: 0; border-radius: 10px; background: transparent; color: var(--gold2); cursor: pointer; display: grid; place-items: center; }
        .bar button:hover, .bar button.on { background: rgba(236, 196, 107, .16); }
        .bar svg { width: 21px; height: 21px; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .bar .sep { width: 1px; background: rgba(255, 255, 255, .15); margin: 6px 3px; }

        /* daftar titik (bila lebih dari satu) */
        .scenes { position: absolute; left: 50%; bottom: 74px; transform: translateX(-50%); z-index: 6; display: none; gap: 10px; padding: 10px; border-radius: 14px; background: var(--glass); backdrop-filter: blur(8px); max-width: 92vw; overflow-x: auto; }
        .scenes.show { display: flex; }
        .scenes a { flex: 0 0 auto; width: 118px; text-decoration: none; color: #fff; font-size: 11.5px; text-align: center; }
        .scenes a img { width: 118px; height: 66px; object-fit: cover; border-radius: 8px; border: 2px solid transparent; display: block; margin-bottom: 4px; }
        .scenes a.on img { border-color: var(--gold2); }

        /* hotspot pindah lokasi: "tiang bendera" — titik penghubung = ujung bawah tiang (titik di tanah), bola foto di atas,
           nama kecil agak transparan di atas bola / kiri (lp-l) / kanan (lp-r) — diatur di Console */
        .hs { width: 0; height: 0; cursor: pointer; }
        .hs .pin { position: absolute; left: -20px; bottom: 0; width: 40px; height: 96px; }
        .hs .ball { position: absolute; left: 0; top: 0; width: 40px; height: 40px; box-sizing: border-box; border-radius: 50%; border: 2px solid #fff; background: #fff center / cover no-repeat; box-shadow: 0 4px 14px rgba(0, 0, 0, .45); transition: transform .2s; }
        .hs:hover .ball { transform: scale(1.15); }
        .hs .pole { position: absolute; left: 19px; top: 40px; width: 2px; height: 52px; background: linear-gradient(rgba(255, 255, 255, .95), rgba(255, 255, 255, .35)); box-shadow: 0 0 4px rgba(0, 0, 0, .35); }
        .hs .base { position: absolute; left: 16px; bottom: -4px; width: 8px; height: 8px; border-radius: 50%; background: #fff; box-shadow: 0 0 0 3px rgba(255, 255, 255, .28); animation: hsPulse 2.2s ease-out infinite; }
        @keyframes hsPulse { 0% { box-shadow: 0 0 0 0 rgba(255, 255, 255, .5); } 100% { box-shadow: 0 0 0 9px rgba(255, 255, 255, 0); } }
        .hs .lb { position: absolute; left: 50%; bottom: 100%; transform: translateX(-50%); margin-bottom: 5px; padding: 1px 8px; border-radius: 99px; background: rgba(0, 0, 0, .32); color: rgba(255, 255, 255, .88); font-size: 10px; font-weight: 600; letter-spacing: .04em; white-space: nowrap; text-shadow: 0 1px 3px rgba(0, 0, 0, .5); }
        .hs.lp-l .lb { left: auto; right: 100%; bottom: auto; top: 20px; transform: translateY(-50%); margin: 0 6px 0 0; }
        .hs.lp-r .lb { left: 100%; bottom: auto; top: 20px; transform: translateY(-50%); margin: 0 0 0 6px; }

        /* volume musik (kanan atas, di bawah header) */
        .vol { position: absolute; right: 18px; top: 78px; z-index: 6; display: flex; align-items: center; gap: 8px; flex-direction: row-reverse; }
        .vol button { width: 42px; height: 42px; border-radius: 50%; border: 1px solid rgba(255, 255, 255, .25); background: var(--glass); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); color: var(--gold2); display: grid; place-items: center; cursor: pointer; box-shadow: 0 6px 18px rgba(0, 0, 0, .35); }
        .vol button.on { color: #fff; border-color: var(--gold2); }
        .vol button.on.playing { animation: volPulse 2.4s ease-in-out infinite; }
        @keyframes volPulse { 50% { box-shadow: 0 0 0 6px rgba(236, 196, 107, .18), 0 6px 18px rgba(0, 0, 0, .35); } }
        .vol svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .vol input { width: 0; opacity: 0; transition: width .25s, opacity .25s; accent-color: #ecc46b; }
        .vol:hover input, .vol.open input { width: 110px; opacity: 1; }
        .vtip { display: none; padding: 5px 10px; border-radius: 99px; background: var(--glass); font-size: 11px; white-space: nowrap; }
        .vol.wait .vtip { display: block; }
        .vol.open .vtip, .vol:hover .vtip { display: none; }
        @media (max-width: 640px) { .vol { top: 68px; right: 12px; } .vol button { width: 38px; height: 38px; } }

        /* efek pembuka: bola dunia (little planet) → tampilan biasa */
        #intro { position: absolute; inset: 0; width: 100%; height: 100%; z-index: 3; transition: opacity .5s; cursor: pointer; }
        #intro.off { opacity: 0; pointer-events: none; }

        /* logo penutup bawah (nadir) */
        .nadir { width: 0; height: 0; pointer-events: none; }
        .nadir > div { position: relative; left: -260px; top: -260px; width: 520px; height: 520px; border-radius: 50%; background: url(img/logo-round.png) center / cover no-repeat; }

        /* petunjuk */
        .hint { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); z-index: 4; padding: 12px 18px; border-radius: 12px; background: rgba(0, 0, 0, .5); font-size: 13px; text-align: center; transition: opacity .6s; pointer-events: none; }
        .hint.off { opacity: 0; }
        .credit { position: absolute; right: 14px; bottom: 10px; z-index: 5; font-size: 10px; color: rgba(255, 255, 255, .65); }

        @media (max-width: 640px) {
            .brand b { font-size: 12px; } .brand small { display: none; } .brand img { width: 40px; height: 40px; }
            .book { padding: 8px 11px; font-size: 10.5px; letter-spacing: .08em; }
            .title { bottom: 84px; } .title h1 { font-size: 18px; }
            .bar button { width: 38px; }
            .hs .pin { left: -17px; width: 34px; height: 82px; }
            .hs .ball { width: 34px; height: 34px; }
            .hs .pole { left: 16px; top: 34px; height: 44px; }
            .hs .base { left: 13px; }
            .hs.lp-l .lb, .hs.lp-r .lb { top: 17px; }
            .credit { display: none; }
        }
    </style>
</head>

<body>
    <div id="pano"></div>

    <div class="hd">
        <a class="brand" href="/" title="Narayana Hotel Karimunjawa"><img src="img/logo-round.png" alt="Narayana"><span><b>Narayana</b><small>Hotel Karimunjawa</small></span></a>
        <span class="sp"></span>
        <div class="lang"><button type="button" data-l="id" class="on">ID</button><button type="button" data-l="en">EN</button></div>
        <a class="book" href="/booking.php" data-t="book">Pesan Sekarang</a>
    </div>

    <!-- volume musik: tombol bulat kanan atas + slider -->
    <div class="vol" id="vol">
        <button type="button" id="bMusic" title="Musik" data-tt="music"><svg viewBox="0 0 24 24"><path d="M4 9h4l5-4v14l-5-4H4z" /><path class="w" d="M16.5 8.5a5 5 0 0 1 0 7M19 6a8.5 8.5 0 0 1 0 12" /><path class="x" d="M16 9l5 6M21 9l-5 6" style="display:none" /></svg></button>
        <input type="range" id="volRange" min="0" max="100" step="1" aria-label="Volume">
        <span class="vtip" id="volTip" data-t="tapmusic">Ketuk layar untuk musik</span>
    </div>

    <div class="title"><small data-t="tour">Tour 360°</small><h1 id="sceneName"></h1></div>

    <div class="scenes" id="sceneList"></div>

    <div class="bar">
        <button type="button" id="bPrev" title="Sebelumnya" data-tt="prev"><svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6" /></svg></button>
        <button type="button" id="bList" title="Daftar lokasi" data-tt="list"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="7" height="7" rx="1.5" /><rect x="14" y="4" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></svg></button>
        <button type="button" id="bNext" title="Berikutnya" data-tt="next"><svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6" /></svg></button>
        <span class="sep"></span>
        <button type="button" id="bRotate" class="on" title="Putar otomatis" data-tt="rotate"><svg viewBox="0 0 24 24"><path d="M20 12a8 8 0 1 1-2.3-5.7" /><path d="M20 4v5h-5" /></svg></button>
        <button type="button" id="bIn" title="Perbesar" data-tt="zin"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path d="M11 8v6M8 11h6M20 20l-4-4" /></svg></button>
        <button type="button" id="bOut" title="Perkecil" data-tt="zout"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path d="M8 11h6M20 20l-4-4" /></svg></button>
        <button type="button" id="bGyro" title="Gerakkan HP" data-tt="gyro" style="display:none"><svg viewBox="0 0 24 24"><rect x="7" y="2.5" width="10" height="19" rx="2" /><path d="M3 9a9 9 0 0 0 0 6M21 9a9 9 0 0 1 0 6" /></svg></button>
        <button type="button" id="bFull" title="Layar penuh" data-tt="full"><svg viewBox="0 0 24 24"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" /></svg></button>
    </div>

    <div class="hint" id="hint" data-t="hint">Geser untuk melihat sekeliling</div>
    <div class="credit">© Narayana Hotel Karimunjawa</div>

    <script src="https://cdn.jsdelivr.net/npm/marzipano@0.10.2/dist/marzipano.js"></script>
    <script>
        // Daftar lokasi dari Console (settings web_tour360); gambar = <base>-4096.jpg / -8192.jpg
        const SCENES = <?= json_encode(array_values($scenes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;

        const T = {
            id: { book: 'Pesan Sekarang', tour: 'Tour 360°', hint: 'Geser untuk melihat sekeliling', prev: 'Sebelumnya', next: 'Berikutnya', list: 'Daftar lokasi', rotate: 'Putar otomatis', zin: 'Perbesar', zout: 'Perkecil', gyro: 'Gerakkan HP', full: 'Layar penuh', music: 'Musik', tapmusic: 'Ketuk layar untuk musik' },
            en: { book: 'Book Now', tour: '360° Tour', hint: 'Drag to look around', prev: 'Previous', next: 'Next', list: 'Locations', rotate: 'Auto rotate', zin: 'Zoom in', zout: 'Zoom out', gyro: 'Move your phone', full: 'Fullscreen', music: 'Music', tapmusic: 'Tap the screen for music' },
        };
        let lang = (localStorage.getItem('nrLang') || navigator.language || 'id').startsWith('en') ? 'en' : 'id';

        const rad = d => d * Math.PI / 180;
        // gambar kecil untuk bola & daftar lokasi: <base>-thumb.jpg (unggahan Console) atau versi 4096 (foto bawaan)
        const thumb = s => s.thumb || (s.base + (s.base.startsWith('img/') ? '-4096.jpg' : '-thumb.jpg'));
        const viewer = new Marzipano.Viewer(document.getElementById('pano'), { controls: { mouseViewMode: 'drag' } });
        const limiter = Marzipano.RectilinearView.limit.traditional(8192, rad(120));
        const built = {};
        let current = null;

        const isFlat = s => s.type === 'flat';
        // posisi bola kembali otomatis di b menuju a (sama dengan Console Tour360::backPos)
        function backPos(a, l, b) {
            if (isFlat(b)) return { x: .5, y: .85 };
            if (isFlat(a)) return { yaw: b.view.yaw, pitch: Math.min(40, b.view.pitch + 15) };
            return { yaw: ((l.yaw + 360) % 360) - 180, pitch: Math.max(-40, Math.min(40, -l.pitch)) };
        }

        function build(s) {
            if (built[s.id]) return built[s.id];
            let source, geometry, view;
            if (isFlat(s)) {
                // foto biasa: satu gambar utuh, muat penuh di layar, bisa di-zoom sampai resolusi asli
                source = new Marzipano.ImageUrlSource(() => ({ url: s.base + '-full.jpg' }));
                geometry = new Marzipano.FlatGeometry([{ width: s.w, height: s.h, tileWidth: s.w, tileHeight: s.h }]);
                view = new Marzipano.FlatView({ x: .5, y: .5, zoom: 99, mediaAspectRatio: s.w / s.h },
                    Marzipano.util.compose(Marzipano.FlatView.limit.resolution(s.w), Marzipano.FlatView.limit.letterbox()));
            } else {
                // dua tingkat: 4096 (HP) & 8192 (komputer) — Marzipano memilih sesuai kemampuan perangkat
                source = new Marzipano.ImageUrlSource(t => ({ url: s.base + '-' + (t.z === 0 ? 4096 : 8192) + '.jpg' }));
                geometry = new Marzipano.EquirectGeometry([{ width: 4096 }, { width: 8192 }]);
                view = new Marzipano.RectilinearView({ yaw: rad(s.view.yaw), pitch: rad(s.view.pitch), fov: rad(s.view.fov) }, limiter);
            }
            const scene = viewer.createScene({ source, geometry, view, pinFirstLevel: true });
            const hc = scene.hotspotContainer();
            if (!isFlat(s)) {
                // logo bulat menempel di bawah (nadir)
                const nadir = document.createElement('div');
                nadir.className = 'nadir';
                nadir.innerHTML = '<div></div>'; // Marzipano menimpa transform elemen luar → isi di elemen dalam
                hc.createHotspot(nadir, { yaw: 0, pitch: Math.PI / 2 }, { perspective: { radius: 1100 } }); // Marzipano: pitch positif = ke bawah
            }
            // titik pindah ke lokasi lain + bola kembali otomatis (lokasi lain menuju sini tapi sini belum punya penghubung ke sana)
            const links = s.links.slice();
            const hidden = s.hide || []; // bola kembali otomatis yang dihapus admin di Console
            SCENES.forEach(o => (o.links || []).forEach(l => {
                if (l.to === s.id && o.id !== s.id && !hidden.includes(o.id) && !links.some(x => x.to === o.id)) links.push(Object.assign({ to: o.id }, backPos(o, l, s)));
            }));
            links.forEach(l => {
                const to = SCENES.find(x => x.id === l.to);
                if (!to) return;
                const el = document.createElement('div');
                el.className = 'hs' + (l.lp === 'l' || l.lp === 'r' ? ' lp-' + l.lp : '');
                // gambar bola: gambar sendiri dari Console (mis. logo) atau potongan foto lokasi
                el.innerHTML = '<div class="pin"><span class="lb"></span><div class="ball"></div><i class="pole"></i><i class="base"></i></div>';
                el.querySelector('.ball').style.backgroundImage = 'url("' + (to.ball || thumb(to)) + '")';
                el.querySelector('.lb').dataset.scene = to.id;
                // klik → zoom ke arah bola, lalu pindah lokasi (foto biasa: langsung pindah)
                el.addEventListener('click', () => go(to.id, isFlat(s) ? null : l));
                hc.createHotspot(el, isFlat(s) ? { x: +l.x, y: +l.y } : { yaw: rad(l.yaw), pitch: rad(l.pitch) });
            });
            return (built[s.id] = { s, scene, view });
        }

        const autorotate = Marzipano.autorotate({ yawSpeed: 0.03, targetPitch: 0, targetFov: Math.PI / 2 });
        let rotating = true;
        let introOn = false; // selama efek pembuka, putar otomatis ditunda
        function startRotate() {
            if (current && isFlat(current)) { viewer.stopMovement(); viewer.setIdleMovement(Infinity); return; } // foto biasa tidak diputar
            if (rotating && !introOn) { viewer.setIdleMovement(3000, autorotate); viewer.startMovement(autorotate); } }

        /*
         * Pindah lokasi. Dari bola penghubung (from = {yaw, pitch}): kamera ZOOM MASUK ke arah bola (0,65 dtk), lalu lokasi
         * baru tampil sedikit diperbesar dan pelan-pelan melebar ke tampilan awalnya — terasa seperti "masuk" ke tempat itu.
         */
        let moving = false;
        function go(id, from) {
            const s = SCENES.find(x => x.id === id) || SCENES[0];
            if (from && current && !isFlat(current) && built[current.id]) {
                if (moving) return;
                moving = true;
                viewer.stopMovement();
                viewer.setIdleMovement(Infinity);
                built[current.id].scene.lookTo({ yaw: rad(from.yaw), pitch: rad(from.pitch), fov: rad(28) }, { transitionDuration: 650 }, () => {
                    moving = false;
                    show(s, true);
                });
                return;
            }
            show(s, false);
        }

        function show(s, zoomed) {
            const b = build(s);
            if (!isFlat(s)) {
                // selalu mulai dari tampilan awal lokasi (kunjungan ulang tidak melanjutkan sudut terakhir)
                const target = { yaw: rad(s.view.yaw), pitch: rad(s.view.pitch), fov: rad(s.view.fov) };
                b.view.setParameters(zoomed ? { yaw: target.yaw, pitch: target.pitch, fov: Math.max(rad(30), target.fov * 0.55) } : target);
                b.scene.switchTo({ transitionDuration: zoomed ? 700 : 900 });
                if (zoomed) b.scene.lookTo(target, { transitionDuration: 1300 });
            } else {
                b.scene.switchTo({ transitionDuration: 700 });
            }
            current = s;
            history.replaceState(null, '', '#' + s.id);
            label();
            startRotate();
        }

        function label() {
            document.documentElement.lang = lang;
            document.getElementById('sceneName').textContent = current ? current.name[lang] : '';
            document.querySelectorAll('[data-t]').forEach(el => { el.textContent = T[lang][el.dataset.t]; });
            document.querySelectorAll('[data-tt]').forEach(el => { el.title = T[lang][el.dataset.tt]; });
            document.querySelectorAll('.hs .lb').forEach(el => { const s = SCENES.find(x => x.id === el.dataset.scene); el.textContent = s ? s.name[lang] : ''; });
            document.querySelectorAll('.lang button').forEach(b => b.classList.toggle('on', b.dataset.l === lang));
            const list = document.getElementById('sceneList');
            list.innerHTML = SCENES.map(s => '<a href="#' + s.id + '" data-id="' + s.id + '" class="' + (current && s.id === current.id ? 'on' : '') + '"><img src="' + thumb(s) + '" alt="">' + s.name[lang] + '</a>').join('');
        }

        // tombol
        const multi = SCENES.length > 1;
        ['bPrev', 'bNext', 'bList'].forEach(id => { if (!multi) document.getElementById(id).style.display = 'none'; });
        if (!multi) document.querySelector('.bar .sep').style.display = 'none';
        const step = d => { const i = SCENES.findIndex(x => x.id === current.id); go(SCENES[(i + d + SCENES.length) % SCENES.length].id); };
        document.getElementById('bPrev').onclick = () => step(-1);
        document.getElementById('bNext').onclick = () => step(1);
        document.getElementById('bList').onclick = () => document.getElementById('sceneList').classList.toggle('show');
        document.getElementById('sceneList').addEventListener('click', e => { const a = e.target.closest('a'); if (a) { e.preventDefault(); go(a.dataset.id); document.getElementById('sceneList').classList.remove('show'); } });
        document.getElementById('bRotate').onclick = function () {
            rotating = !rotating;
            this.classList.toggle('on', rotating);
            if (rotating) startRotate(); else { viewer.stopMovement(); viewer.setIdleMovement(Infinity); }
        };
        const zoom = f => {
            const v = built[current.id].view;
            if (isFlat(current)) v.setZoom(v.zoom() * f); // foto biasa: batas zoom diatur limiter (muat layar … resolusi asli)
            else v.setFov(Math.min(rad(120), Math.max(rad(30), v.fov() * f)));
        };
        document.getElementById('bIn').onclick = () => zoom(0.8);
        document.getElementById('bOut').onclick = () => zoom(1.25);
        /*
         * Musik latar. File MP3 dari Console bila ada; selain itu musik "pulau" bawaan yang DIBUAT di browser (Web Audio,
         * tanpa file / tanpa hak cipta): ombak pantai (noise disaring, mengalun 7–11 dtk) + pad akor hangat
         * (Fmaj7 → Em7 → Dm7 → Cmaj7, 8 dtk per akor) + petikan pentatonik mirip kalimba dengan gema lembut.
         * Browser melarang suara sebelum disentuh → mulai pelan pada sentuhan/geser pertama. Pilihan mati/nyala diingat.
         */
        const MUSIC_URL = <?= json_encode($music, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
        const musicBtn = document.getElementById('bMusic');
        let musicWanted = (() => { try { return localStorage.getItem('nrTourMusic') !== '0'; } catch (e) { return true; } })();
        let musicVol = (() => { try { const v = parseInt(localStorage.getItem('nrTourVol') ?? '', 10); return isNaN(v) ? 60 : v; } catch (e) { return 60; } })() / 100;
        let player = null;

        function islandMusic() {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const master = ctx.createGain();
            master.gain.value = 0;
            master.connect(ctx.destination);
            // gema lembut untuk petikan & pad
            const delay = ctx.createDelay(2), fb = ctx.createGain(), wet = ctx.createGain();
            delay.delayTime.value = 0.42; fb.gain.value = 0.33; wet.gain.value = 0.3;
            delay.connect(fb); fb.connect(delay); delay.connect(wet); wet.connect(master);

            // ombak: noise "coklat" stereo 8 dtk berulang → lowpass → gain yang mengalun
            const len = ctx.sampleRate * 8, buf = ctx.createBuffer(2, len, ctx.sampleRate);
            for (let c = 0; c < 2; c++) {
                const d = buf.getChannelData(c);
                let last = 0;
                for (let i = 0; i < len; i++) { last = (last + 0.02 * (Math.random() * 2 - 1)) / 1.02; d[i] = last * 3.2; }
            }
            const noise = ctx.createBufferSource(), wlp = ctx.createBiquadFilter(), wg = ctx.createGain();
            noise.buffer = buf; noise.loop = true;
            wlp.type = 'lowpass'; wlp.frequency.value = 500;
            wg.gain.value = 0.06;
            noise.connect(wlp); wlp.connect(wg); wg.connect(master);
            noise.start();

            // pad akor (MIDI): Fmaj7, Em7, Dm7, Cmaj7
            const CHORDS = [[53, 57, 60, 64], [52, 55, 59, 62], [50, 53, 57, 60], [48, 52, 55, 59]];
            const SCALE = [72, 74, 76, 79, 81, 84, 86, 88]; // C mayor pentatonik
            const hz = m => 440 * Math.pow(2, (m - 69) / 12);
            const padLp = ctx.createBiquadFilter(), padG = ctx.createGain();
            padLp.type = 'lowpass'; padLp.frequency.value = 1100; padG.gain.value = 0.045;
            padLp.connect(padG); padG.connect(master); padG.connect(delay);

            function chord(t, notes) {
                notes.forEach(m => [['sine', 0], ['triangle', 5]].forEach(([type, cents]) => {
                    const o = ctx.createOscillator(), g = ctx.createGain();
                    o.type = type; o.frequency.value = hz(m); o.detune.value = cents;
                    g.gain.setValueAtTime(0, t);
                    g.gain.linearRampToValueAtTime(0.5, t + 2.5);
                    g.gain.setValueAtTime(0.5, t + 6.5);
                    g.gain.linearRampToValueAtTime(0, t + 10);
                    o.connect(g); g.connect(padLp);
                    o.start(t); o.stop(t + 10.2);
                }));
            }
            function pluck(t, m) {
                const g = ctx.createGain();
                g.gain.setValueAtTime(0, t);
                g.gain.linearRampToValueAtTime(0.07, t + 0.006);
                g.gain.exponentialRampToValueAtTime(0.0008, t + 1.6);
                [[1, 'triangle'], [2, 'sine']].forEach(([mul, type]) => {
                    const o = ctx.createOscillator();
                    o.type = type; o.frequency.value = hz(m) * mul;
                    o.connect(g); o.start(t); o.stop(t + 1.7);
                });
                g.connect(master); g.connect(delay);
            }
            function wave(t) {
                const dur = 7 + Math.random() * 4;
                wg.gain.setValueAtTime(0.05, t);
                wg.gain.linearRampToValueAtTime(0.22 + Math.random() * 0.1, t + dur * 0.45);
                wg.gain.linearRampToValueAtTime(0.05, t + dur);
                wlp.frequency.setValueAtTime(420, t);
                wlp.frequency.linearRampToValueAtTime(1200, t + dur * 0.45);
                wlp.frequency.linearRampToValueAtTime(450, t + dur);
                return dur;
            }

            // penjadwal: melihat 1 dtk ke depan tiap 250 ms (72 bpm, petikan acak di ketukan)
            let nChord = ctx.currentTime + 0.1, nBeat = nChord + 1.5, nWave = nChord, ci = 0, timer = null;
            function tick() {
                const ahead = ctx.currentTime + 1;
                while (nChord < ahead) { chord(nChord, CHORDS[ci % 4]); ci++; nChord += 8; }
                while (nWave < ahead) nWave += wave(nWave);
                while (nBeat < ahead) {
                    if (Math.random() < 0.42) pluck(nBeat, SCALE[Math.floor(Math.random() * SCALE.length)]);
                    nBeat += 60 / 72 * (Math.random() < 0.2 ? 0.5 : 1);
                }
            }
            const level = () => 1.0 * musicVol; // volume slider 0…1
            return {
                running: () => ctx.state === 'running',
                play() {
                    const p = ctx.resume(); // harus di dalam ketukan pengguna (aturan browser)
                    if (!timer) { tick(); timer = setInterval(tick, 250); }
                    master.gain.cancelScheduledValues(ctx.currentTime);
                    master.gain.setValueAtTime(master.gain.value, ctx.currentTime);
                    master.gain.linearRampToValueAtTime(level(), ctx.currentTime + 3);
                    return p;
                },
                volume() {
                    master.gain.cancelScheduledValues(ctx.currentTime);
                    master.gain.setTargetAtTime(level(), ctx.currentTime, 0.08);
                },
                pause() {
                    master.gain.cancelScheduledValues(ctx.currentTime);
                    master.gain.setValueAtTime(master.gain.value, ctx.currentTime);
                    master.gain.linearRampToValueAtTime(0, ctx.currentTime + 0.8);
                    setTimeout(() => { if (!musicWanted || document.hidden) { clearInterval(timer); timer = null; ctx.suspend(); } }, 900);
                },
            };
        }

        function filePlayer(url) {
            const a = new Audio(url);
            a.loop = true; a.volume = 0;
            let fade = null;
            const to = (v, ms, done) => {
                clearInterval(fade);
                const step = (v - a.volume) / (ms / 50);
                fade = setInterval(() => {
                    const nv = Math.min(1, Math.max(0, a.volume + step));
                    a.volume = nv;
                    if ((step >= 0 && nv >= v) || (step < 0 && nv <= v)) { clearInterval(fade); if (done) done(); }
                }, 50);
            };
            return {
                running: () => !a.paused,
                play() { const p = a.play().catch(() => {}); to(musicVol, 2500); return p; },
                volume() { to(musicVol, 200); },
                pause() { to(0, 800, () => a.pause()); },
            };
        }

        const volBox = document.getElementById('vol'), volRange = document.getElementById('volRange');
        volRange.value = Math.round(musicVol * 100);
        function setMusicUi() {
            const playing = !!(player && player.running() && musicWanted);
            musicBtn.classList.toggle('on', musicWanted);
            musicBtn.classList.toggle('playing', playing);
            musicBtn.querySelector('.w').style.display = musicWanted && musicVol > 0 ? '' : 'none';
            musicBtn.querySelector('.x').style.display = musicWanted && musicVol > 0 ? 'none' : '';
            volBox.classList.toggle('wait', musicWanted && !playing); // "Ketuk layar untuk musik"
        }
        function musicStart() {
            if (!musicWanted || document.hidden) return;
            try {
                player = player || (MUSIC_URL ? filePlayer(MUSIC_URL) : islandMusic());
                Promise.resolve(player.play()).then(setMusicUi, setMusicUi);
            } catch (e) { /* tanpa dukungan audio */ }
            setTimeout(setMusicUi, 300);
        }
        musicBtn.onclick = e => {
            e.stopPropagation();
            // ketuk pertama di tombol saat musik belum jalan = nyalakan (bukan matikan)
            if (musicWanted && !(player && player.running())) { musicStart(); volBox.classList.add('open'); return; }
            musicWanted = !musicWanted;
            try { localStorage.setItem('nrTourMusic', musicWanted ? '1' : '0'); } catch (err) {}
            if (musicWanted) { if (musicVol === 0) { musicVol = 0.6; volRange.value = 60; } musicStart(); } else if (player) player.pause();
            volBox.classList.toggle('open', musicWanted);
            setTimeout(setMusicUi, 50);
        };
        volRange.addEventListener('input', () => {
            musicVol = volRange.value / 100;
            try { localStorage.setItem('nrTourVol', String(volRange.value)); } catch (err) {}
            if (musicVol > 0 && !musicWanted) { musicWanted = true; try { localStorage.setItem('nrTourMusic', '1'); } catch (err) {} musicStart(); }
            if (player) player.volume();
            setMusicUi();
        });
        volRange.addEventListener('pointerdown', e => e.stopPropagation());
        setMusicUi();
        // Izin bunyi dari browser hanya pada ketukan yang SELESAI (pointerup/touchend/click/keydown — bukan touchstart).
        // Coba di setiap ketukan sampai musik benar-benar berbunyi, lalu berhenti mendengarkan.
        const GESTURES = ['pointerup', 'touchend', 'click', 'keydown'];
        const unlock = () => {
            if (player && player.running()) {
                GESTURES.forEach(ev => document.removeEventListener(ev, unlock, true));
                return;
            }
            musicStart();
        };
        GESTURES.forEach(ev => document.addEventListener(ev, unlock, true));
        // tab disembunyikan → pelan-pelan diam; kembali → lanjut
        document.addEventListener('visibilitychange', () => { if (!player) return; if (document.hidden) player.pause(); else if (musicWanted) player.play(); setTimeout(setMusicUi, 400); });

        document.getElementById('bFull').onclick = () => {
            const d = document;
            if (!d.fullscreenElement) (d.documentElement.requestFullscreen || d.documentElement.webkitRequestFullscreen || (() => {})).call(d.documentElement);
            else (d.exitFullscreen || d.webkitExitFullscreen).call(d);
        };
        // gerak HP (giroskop) — hanya di perangkat yang mendukung
        if (window.DeviceOrientationEvent && 'ontouchstart' in window) {
            const g = document.getElementById('bGyro');
            g.style.display = '';
            let on = false, last = null;
            const onOri = e => {
                if (e.alpha == null || isFlat(current)) return;
                const cur = { a: e.alpha, b: e.beta };
                if (last) {
                    const v = built[current.id].view;
                    let da = cur.a - last.a; if (da > 180) da -= 360; if (da < -180) da += 360;
                    v.setYaw(v.yaw() - rad(da));
                    v.setPitch(Math.max(rad(-89), Math.min(rad(89), v.pitch() + rad(cur.b - last.b))));
                }
                last = cur;
            };
            g.onclick = async () => {
                if (!on && typeof DeviceOrientationEvent.requestPermission === 'function') {
                    try { if (await DeviceOrientationEvent.requestPermission() !== 'granted') return; } catch (e) { return; }
                }
                on = !on;
                g.classList.toggle('on', on);
                last = null;
                if (on) { viewer.stopMovement(); window.addEventListener('deviceorientation', onOri); } else window.removeEventListener('deviceorientation', onOri);
            };
        }
        document.querySelectorAll('.lang button').forEach(b => b.onclick = () => { lang = b.dataset.l; try { localStorage.setItem('nrLang', lang); } catch (e) {} label(); });
        // petunjuk hilang saat mulai digeser
        const hint = document.getElementById('hint');
        const hideHint = () => hint.classList.add('off');
        document.getElementById('pano').addEventListener('pointerdown', hideHint, { once: true });
        setTimeout(hideHint, 5000);
        // ?debug=1: klik untuk melihat yaw/pitch (memasang titik pindah)
        if (location.search.includes('debug=1')) {
            document.getElementById('pano').addEventListener('click', e => {
                const c = built[current.id].view.screenToCoordinates({ x: e.clientX, y: e.clientY });
                if (isFlat(current)) { hint.textContent = 'x ' + c.x.toFixed(3) + ' · y ' + c.y.toFixed(3); hint.classList.remove('off'); return; }
                console.log('yaw', (c.yaw * 180 / Math.PI).toFixed(1), 'pitch', (c.pitch * 180 / Math.PI).toFixed(1));
                hint.textContent = 'yaw ' + (c.yaw * 180 / Math.PI).toFixed(1) + ' · pitch ' + (c.pitch * 180 / Math.PI).toFixed(1);
                hint.classList.remove('off');
            });
        }

        // ?yaw=&pitch=&fov= (derajat) membuka sudut pandang tertentu — untuk membagikan tampilan
        const q = new URLSearchParams(location.search);
        if (q.has('yaw') || q.has('pitch') || q.has('fov')) {
            const s0 = SCENES.find(x => x.id === location.hash.slice(1)) || SCENES[0];
            s0.view = { yaw: +(q.get('yaw') ?? s0.view.yaw), pitch: +(q.get('pitch') ?? s0.view.pitch), fov: +(q.get('fov') ?? s0.view.fov) };
            rotating = false;
            document.getElementById('bRotate').classList.remove('on');
        }
        /*
         * Efek pembuka: kanvas WebGL di atas Marzipano menggambar foto 360° dengan proyeksi "kamera mundur" —
         * jarak kamera d dari pusat bola: d = 1 → stereografis (bola dunia / little planet), d = 0 → rectilinear (sama
         * dengan Marzipano). d, arah (yaw/pitch) & zoom dianimasikan ke tampilan awal, lalu kanvas memudar.
         * Lewati bila: link dengan ?yaw/pitch/fov, pengguna memilih kurangi animasi, atau WebGL tidak ada. Klik = lewati.
         */
        function intro(s) {
            return new Promise(done => {
                const cv = document.createElement('canvas');
                cv.id = 'intro';
                const gl = cv.getContext('webgl');
                if (!gl) return done();
                const dpr = Math.min(window.devicePixelRatio || 1, 1.5);
                let W = cv.width = Math.round(innerWidth * dpr), H = cv.height = Math.round(innerHeight * dpr);
                const sh = (type, src) => { const o = gl.createShader(type); gl.shaderSource(o, src); gl.compileShader(o); return o; };
                const pg = gl.createProgram();
                gl.attachShader(pg, sh(gl.VERTEX_SHADER, 'attribute vec2 a;void main(){gl_Position=vec4(a,0.,1.);}'));
                gl.attachShader(pg, sh(gl.FRAGMENT_SHADER, `precision highp float;
                    uniform sampler2D tex; uniform vec2 res; uniform float d, S, yaw, pitch, unit;
                    void main() {
                        vec2 p = (gl_FragCoord.xy - res * .5) / (unit * .5) * S;
                        vec3 D = normalize(vec3(p, 1.));
                        float b = -d * D.z, t = -b + sqrt(max(b * b - d * d + 1., 0.));
                        vec3 P = vec3(0., 0., -d) + t * D;                       // titik di bola satuan
                        float cp = cos(pitch), sp = sin(pitch), cy = cos(yaw), sy = sin(yaw);
                        vec3 v = vec3(P.x, P.y * cp - P.z * sp, P.y * sp + P.z * cp); // pitch positif = ke bawah
                        v = vec3(v.x * cy + v.z * sy, v.y, -v.x * sy + v.z * cy);    // yaw positif = ke kanan
                        gl_FragColor = texture2D(tex, vec2(.5 + atan(v.x, v.z) / 6.2831853, .5 - asin(clamp(v.y, -1., 1.)) / 3.1415927));
                    }`));
                gl.linkProgram(pg);
                if (!gl.getProgramParameter(pg, gl.LINK_STATUS)) return done();
                gl.useProgram(pg);
                gl.bindBuffer(gl.ARRAY_BUFFER, gl.createBuffer());
                gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);
                gl.enableVertexAttribArray(0);
                gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0);
                const U = n => gl.getUniformLocation(pg, n);
                const img = new Image();
                const giveUp = setTimeout(() => { img.onload = null; done(); }, 6000); // foto lambat → langsung tampilan biasa
                img.onerror = () => { clearTimeout(giveUp); done(); };
                img.onload = () => {
                    clearTimeout(giveUp);
                    gl.bindTexture(gl.TEXTURE_2D, gl.createTexture());
                    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGB, gl.RGB, gl.UNSIGNED_BYTE, img);
                    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
                    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
                    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT);
                    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
                    gl.viewport(0, 0, W, H);
                    gl.uniform2f(U('res'), W, H);
                    document.getElementById('pano').after(cv);
                    const end = { yaw: rad(s.view.yaw), pitch: rad(s.view.pitch), S: Math.tan(rad(s.view.fov) / 2) };
                    const DUR = 4200, t0 = performance.now();
                    const ease = x => x < .5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2;
                    let stop = false;
                    const finish = () => {
                        if (stop) return;
                        stop = true;
                        cv.classList.add('off');
                        setTimeout(() => cv.remove(), 600);
                        done();
                    };
                    cv.addEventListener('pointerdown', finish);
                    const frame = now => {
                        if (stop) return;
                        if (cv.clientWidth && (Math.round(cv.clientWidth * dpr) !== W || Math.round(cv.clientHeight * dpr) !== H)) {
                            // ukuran layar berubah (HP diputar / jendela diubah) → ikuti, seperti Marzipano
                            W = cv.width = Math.round(cv.clientWidth * dpr);
                            H = cv.height = Math.round(cv.clientHeight * dpr);
                            gl.viewport(0, 0, W, H);
                            gl.uniform2f(U('res'), W, H);
                        }
                        const t = Math.min(1, (now - t0) / DUR);
                        const e = ease(Math.max(0, (t - .2) / .8)); // 20% pertama: bola dunia berputar
                        gl.uniform1f(U('d'), 1 - e);
                        gl.uniform1f(U('S'), Math.exp(Math.log(2.4) * (1 - e) + Math.log(end.S) * e));
                        gl.uniform1f(U('yaw'), end.yaw - rad(200) * (1 - ease(t)));
                        gl.uniform1f(U('pitch'), Math.PI / 2 * (1 - e) + end.pitch * e);
                        gl.uniform1f(U('unit'), Math.min(W, H) * (1 - e) + H * e); // akhir: fov vertikal seperti Marzipano
                        gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
                        if (t < 1) requestAnimationFrame(frame); else finish();
                    };
                    requestAnimationFrame(frame);
                };
                img.src = s.base + '-4096.jpg';
            });
        }

        const first = SCENES.find(x => x.id === location.hash.slice(1)) || SCENES[0];
        const wantIntro = !isFlat(first) && !(q.has('yaw') || q.has('pitch') || q.has('fov')) && !matchMedia('(prefers-reduced-motion: reduce)').matches;
        introOn = wantIntro;
        go(first.id);
        if (wantIntro) {
            hint.classList.add('off');
            intro(first).then(() => {
                built[first.id].view.setParameters({ yaw: rad(first.view.yaw), pitch: rad(first.view.pitch), fov: rad(first.view.fov) });
                introOn = false;
                startRotate();
            });
        }
    </script>
</body>

</html>
