<?php

/**
 * FRONT DESK - PENGATURAN WHATSAPP (Fonnte)
 * Token gateway, tujuan laporan harian (nomor / grup), pesan otomatis check-in, tes kirim, log.
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/WhatsAppHelper.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('settings')) {
    header('Location: ' . BASE_URL . '/modules/frontdesk/dashboard.php');
    exit;
}

$db = Database::getInstance();
$wa = new WhatsAppHelper($db);

// ---- Aksi AJAX (JSON) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $act = $_POST['ajax'];
    if ($act === 'status') {
        $r = $wa->deviceStatus();
        $d = $r['data'] ?? [];
        echo json_encode([
            'ok' => $r['ok'],
            'detail' => $r['detail'],
            'device' => $d['device'] ?? '',
            'name' => $d['name'] ?? '',
            'connected' => ($d['device_status'] ?? '') === 'connect',
            'package' => $d['package'] ?? '',
            'quota' => $d['quota'] ?? '',
            'expired' => $d['expired'] ?? '',
        ]);
    } elseif ($act === 'groups') {
        echo json_encode($wa->groups(true));
    } elseif ($act === 'test') {
        $target = (string)($_POST['target'] ?? '');
        $msg = trim((string)($_POST['message'] ?? '')) ?: 'Tes pesan dari ADF System ✅';
        echo json_encode($wa->send($target, $msg, null, null, 'test', 'tes dari pengaturan'));
    } else {
        echo json_encode(['ok' => false, 'detail' => 'Aksi tidak dikenal']);
    }
    exit;
}

// ---- Simpan pengaturan (PRG) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim((string)($_POST['wa_token'] ?? ''));
    if ($token !== '') {
        $wa->saveSetting('wa_token', $token);
    } elseif (!empty($_POST['remove_token'])) {
        $wa->saveSetting('wa_token', '');
    }
    $targets = array_filter(array_map([WhatsAppHelper::class, 'normalizeTarget'], preg_split('/[\r\n,;]+/', (string)($_POST['wa_report_targets'] ?? ''))));
    $wa->saveSetting('wa_report_targets', implode("\n", array_unique($targets)));
    $wa->saveSetting('wa_checkin_enabled', !empty($_POST['wa_checkin_enabled']) ? '1' : '0');
    $tpl = trim((string)($_POST['wa_checkin_template'] ?? ''));
    $wa->saveSetting('wa_checkin_template', $tpl === trim(WhatsAppHelper::DEFAULT_CHECKIN_TEMPLATE) ? '' : $tpl);
    setFlash('success', 'Pengaturan WhatsApp tersimpan.');
    header('Location: whatsapp.php');
    exit;
}

$s = $wa->settings();
$template = trim($s['wa_checkin_template']) !== '' ? $s['wa_checkin_template'] : WhatsAppHelper::DEFAULT_CHECKIN_TEMPLATE;
$logRows = $wa->recentLog(15);
$typeLabel = ['report' => 'Laporan', 'checkin' => 'Check-in', 'test' => 'Tes'];
$pageTitle = 'Pengaturan WhatsApp';
include '../../includes/header.php';
?>

<style>
    body[data-theme] .main-content .wa-wrap { max-width: 1200px; margin: 0 auto; padding-bottom: 1.5rem; }
    body[data-theme] .main-content .wa-wrap .wa-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; margin-bottom: .75rem; }
    body[data-theme] .main-content .wa-wrap .wa-head h1 { margin: 0; font-size: .95rem !important; font-weight: 700; color: var(--fd-text) !important; }
    body[data-theme] .main-content .wa-wrap .wa-head h1 small { display: block; font-size: .68rem !important; font-weight: 500; color: var(--fd-muted) !important; }
    body[data-theme] .main-content .wa-wrap .wa-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: .75rem; align-items: start; }
    body[data-theme] .main-content .wa-wrap .wa-card { border-radius: 14px; background: var(--fd-card); border: 1px solid var(--fd-edge); box-shadow: var(--fd-shadow); padding: .85rem 1rem; margin-bottom: .75rem; }
    body[data-theme] .main-content .wa-wrap .wa-card h3 { margin: 0 0 .15rem; font-size: .82rem !important; font-weight: 700; color: var(--fd-text) !important; }
    body[data-theme] .main-content .wa-wrap .wa-card > p.wa-sub { margin: 0 0 .7rem; padding-bottom: .55rem; border-bottom: 1px solid var(--fd-line); font-size: .68rem !important; color: var(--fd-muted) !important; }
    body[data-theme] .main-content .wa-wrap label.wa-label { display: block; margin: 0 0 .3rem; font-size: .6rem !important; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--fd-muted) !important; }
    body[data-theme] .main-content .wa-wrap .wa-input, body[data-theme] .main-content .wa-wrap textarea.wa-input { width: 100%; padding: .45rem .65rem; border-radius: 9px; border: 1px solid var(--fd-input-border); background: var(--fd-input-bg) !important; color: var(--fd-text) !important; font-size: .76rem !important; font-family: inherit; }
    body[data-theme] .main-content .wa-wrap textarea.wa-input { min-height: 90px; resize: vertical; line-height: 1.5; }
    body[data-theme] .main-content .wa-wrap .wa-field { margin-bottom: .7rem; }
    body[data-theme] .main-content .wa-wrap .wa-hint { display: block; margin-top: .3rem; font-size: .64rem !important; color: var(--fd-muted) !important; line-height: 1.5; }
    body[data-theme] .main-content .wa-wrap .wa-hint code { padding: .02rem .3rem; border-radius: 5px; background: var(--fd-accent-soft); color: var(--fd-accent-text); font-size: .62rem; cursor: pointer; }
    body[data-theme] .main-content .wa-wrap .wa-row { display: flex; gap: .4rem; align-items: center; flex-wrap: wrap; }
    body[data-theme] .main-content .wa-wrap .wa-btn { display: inline-flex; align-items: center; gap: .35rem; height: 30px; padding: 0 .8rem; border-radius: 8px; border: 1px solid transparent; cursor: pointer; font-size: .7rem !important; font-weight: 600; text-decoration: none; color: #fff !important; -webkit-text-fill-color: #fff !important; background: linear-gradient(135deg, var(--fd-accent), var(--fd-accent-2)); }
    body[data-theme] .main-content .wa-wrap .wa-btn.ghost { background: var(--fd-tile); border-color: var(--fd-line); color: var(--fd-text) !important; -webkit-text-fill-color: var(--fd-text) !important; }
    body[data-theme] .main-content .wa-wrap .wa-btn.green { background: #25d366; }
    body[data-theme] .main-content .wa-wrap .wa-btn:disabled { opacity: .6; cursor: wait; }
    body[data-theme] .main-content .wa-wrap .wa-status { display: flex; align-items: center; gap: .6rem; padding: .6rem .7rem; border-radius: 10px; background: var(--fd-tile); border: 1px solid var(--fd-line); font-size: .74rem; color: var(--fd-text) !important; }
    body[data-theme] .main-content .wa-wrap .wa-dot { width: 10px; height: 10px; border-radius: 50%; background: #94a3b8; flex-shrink: 0; }
    body[data-theme] .main-content .wa-wrap .wa-dot.on { background: #22c55e; box-shadow: 0 0 0 4px rgba(34, 197, 94, .18); }
    body[data-theme] .main-content .wa-wrap .wa-dot.off { background: #ef4444; box-shadow: 0 0 0 4px rgba(239, 68, 68, .15); }
    body[data-theme] .main-content .wa-wrap .wa-status small { display: block; font-size: .64rem !important; color: var(--fd-muted) !important; }
    body[data-theme] .main-content .wa-wrap label.wa-switch { display: flex; gap: .55rem; align-items: flex-start; padding: .55rem .65rem; border-radius: 10px; background: var(--fd-tile); border: 1px solid var(--fd-line); cursor: pointer; font-size: .74rem !important; margin-bottom: .7rem; }
    body[data-theme] .main-content .wa-wrap .wa-switch input { width: 16px; height: 16px; margin-top: 1px; accent-color: #25d366; }
    body[data-theme] .main-content .wa-wrap .wa-switch b { display: block; font-size: .74rem; color: var(--fd-text) !important; }
    body[data-theme] .main-content .wa-wrap .wa-switch small { font-size: .64rem !important; color: var(--fd-muted) !important; }
    body[data-theme] .main-content .wa-wrap .wa-groups { display: none; margin-top: .5rem; max-height: 180px; overflow: auto; border-radius: 9px; border: 1px solid var(--fd-line); }
    body[data-theme] .main-content .wa-wrap .wa-groups button { display: flex; justify-content: space-between; gap: .5rem; width: 100%; padding: .45rem .65rem; border: 0; border-bottom: 1px solid var(--fd-line); background: transparent; cursor: pointer; text-align: left; font-size: .72rem !important; color: var(--fd-text) !important; }
    body[data-theme] .main-content .wa-wrap .wa-groups button:hover { background: var(--fd-accent-soft); }
    body[data-theme] .main-content .wa-wrap .wa-groups button span { color: var(--fd-accent-text) !important; font-size: .64rem; }
    body[data-theme] .main-content .wa-wrap .wa-preview { white-space: pre-wrap; padding: .6rem .75rem; border-radius: 10px 10px 10px 2px; background: #dcf8c6; color: #111b21 !important; font-size: .74rem; line-height: 1.5; max-width: 420px; box-shadow: 0 1px 1px rgba(0,0,0,.1); }
    body[data-theme] .main-content .wa-wrap table.wa-log { width: 100%; border-collapse: collapse; }
    body[data-theme] .main-content .wa-wrap .wa-log th { padding: .45rem .6rem; background: var(--fd-accent) !important; color: #fff !important; font-size: .56rem !important; letter-spacing: .07em; text-transform: uppercase; text-align: left; }
    body[data-theme] .main-content .wa-wrap .wa-log td { padding: .4rem .6rem; border-top: 1px solid var(--fd-line); font-size: .7rem !important; color: var(--fd-text-2) !important; vertical-align: top; }
    body[data-theme] .main-content .wa-wrap .wa-pill { display: inline-block; padding: .05rem .45rem; border-radius: 999px; font-size: .6rem; font-weight: 700; }
    body[data-theme] .main-content .wa-wrap .wa-pill.sent { background: rgba(5,150,105,.12); color: #047857 !important; }
    body[data-theme] .main-content .wa-wrap .wa-pill.failed { background: rgba(220,38,38,.1); color: #b91c1c !important; }
    body[data-theme] .main-content .wa-wrap .wa-pill.skipped { background: rgba(148,163,184,.18); color: var(--fd-muted) !important; }
    body[data-theme] .main-content .wa-wrap .wa-msg { margin-top: .45rem; font-size: .7rem; min-height: 1em; }
    body[data-theme] .main-content .wa-wrap .wa-msg.ok { color: #047857 !important; }
    body[data-theme] .main-content .wa-wrap .wa-msg.err { color: #b91c1c !important; }
    body[data-theme="dark"] .main-content .wa-wrap .wa-msg.ok, body[data-theme="dark"] .main-content .wa-wrap .wa-pill.sent { color: #6ee7b7 !important; }
    body[data-theme="dark"] .main-content .wa-wrap .wa-msg.err, body[data-theme="dark"] .main-content .wa-wrap .wa-pill.failed { color: #fca5a5 !important; }
    @media (max-width: 960px) { body[data-theme] .main-content .wa-wrap .wa-grid { grid-template-columns: 1fr; } }
</style>

<div class="wa-wrap">
    <div class="wa-head">
        <h1>Pengaturan WhatsApp <small>Laporan harian ke staf & pesan otomatis ke tamu saat check-in · Fonnte</small></h1>
        <a class="wa-btn ghost" href="settings.php">← Pengaturan Front Desk</a>
    </div>

    <form method="POST" id="waForm" autocomplete="off">
        <div class="wa-grid">
            <div>
                <!-- Koneksi -->
                <div class="wa-card">
                    <h3>Koneksi Gateway</h3>
                    <p class="wa-sub">Token perangkat dari dashboard Fonnte (menu Device → Token). Disimpan di server, tidak pernah ditampilkan utuh.</p>
                    <div class="wa-status" id="waStatus">
                        <span class="wa-dot" id="waDot"></span>
                        <div><b id="waStatusText"><?php echo $wa->isConfigured() ? 'Memeriksa koneksi…' : 'Token belum diisi'; ?></b><small id="waStatusSub"></small></div>
                    </div>
                    <div class="wa-field" style="margin-top:.7rem">
                        <label class="wa-label" for="waToken">Token Fonnte</label>
                        <input type="password" class="wa-input" id="waToken" name="wa_token" placeholder="<?php echo $wa->isConfigured() ? 'Tersimpan: ' . htmlspecialchars($wa->maskedToken()) . ' — isi hanya bila ingin mengganti' : 'Tempel token di sini'; ?>">
                        <?php if ($wa->isConfigured()): ?>
                            <span class="wa-hint"><label style="display:inline-flex;gap:.3rem;align-items:center;cursor:pointer"><input type="checkbox" name="remove_token" value="1"> Hapus token (putuskan integrasi)</label></span>
                        <?php endif; ?>
                    </div>
                    <div class="wa-row">
                        <button type="button" class="wa-btn ghost" id="waCheck">Cek koneksi</button>
                    </div>
                </div>

                <!-- Tujuan laporan -->
                <div class="wa-card">
                    <h3>Tujuan Laporan Harian</h3>
                    <p class="wa-sub">Tombol "Kirim ke WhatsApp" di menu Laporan langsung mengirim PDF ke semua tujuan ini.</p>
                    <div class="wa-field">
                        <label class="wa-label" for="waTargets">Nomor / grup (satu per baris)</label>
                        <textarea class="wa-input" id="waTargets" name="wa_report_targets" rows="4" placeholder="0812xxxxxxx&#10;120363xxxxxxxx@g.us"><?php echo htmlspecialchars($s['wa_report_targets']); ?></textarea>
                        <span class="wa-hint">Nomor boleh diawali 0, 62, atau +62. Untuk grup, klik "Ambil daftar grup" lalu pilih grupnya. Nomor WhatsApp yang terhubung harus menjadi anggota grup.</span>
                    </div>
                    <div class="wa-row">
                        <button type="button" class="wa-btn ghost" id="waGroupsBtn">Ambil daftar grup</button>
                    </div>
                    <div class="wa-groups" id="waGroups"></div>
                </div>

                <!-- Tes -->
                <div class="wa-card">
                    <h3>Tes Kirim</h3>
                    <p class="wa-sub">Kirim pesan percobaan untuk memastikan koneksi berjalan.</p>
                    <div class="wa-row">
                        <input type="text" class="wa-input" id="waTestTo" placeholder="Nomor tujuan, mis. 0812…" style="flex:1;min-width:180px">
                        <button type="button" class="wa-btn green" id="waTestBtn">Kirim tes</button>
                    </div>
                    <div class="wa-msg" id="waTestMsg"></div>
                </div>
            </div>

            <div>
                <!-- Pesan check-in -->
                <div class="wa-card">
                    <h3>Pesan Otomatis Saat Check-in</h3>
                    <p class="wa-sub">Dikirim ke nomor telepon tamu setelah check-in berhasil. Booking grup hanya menerima 1 pesan.</p>
                    <label class="wa-switch">
                        <input type="checkbox" name="wa_checkin_enabled" value="1" <?php echo $s['wa_checkin_enabled'] === '1' ? 'checked' : ''; ?>>
                        <span><b>Aktifkan pesan check-in</b><small>Matikan bila tidak ingin mengirim pesan otomatis ke tamu.</small></span>
                    </label>
                    <div class="wa-field">
                        <label class="wa-label" for="waTpl">Isi pesan</label>
                        <textarea class="wa-input" id="waTpl" name="wa_checkin_template" rows="10"><?php echo htmlspecialchars($template); ?></textarea>
                        <span class="wa-hint">Klik untuk menyisipkan:
                            <?php foreach (WhatsAppHelper::CHECKIN_PLACEHOLDERS as $ph): ?><code data-ph="<?php echo $ph; ?>"><?php echo $ph; ?></code> <?php endforeach; ?>
                        </span>
                    </div>
                    <label class="wa-label">Pratinjau</label>
                    <div class="wa-preview" id="waPreview"></div>
                </div>
            </div>
        </div>

        <div class="wa-row" style="justify-content:flex-end;margin-bottom:.75rem">
            <button type="submit" class="wa-btn">Simpan Pengaturan</button>
        </div>
    </form>

    <!-- Log -->
    <div class="wa-card">
        <h3>Riwayat Pengiriman</h3>
        <p class="wa-sub">15 pengiriman terakhir.</p>
        <?php if (!$logRows): ?>
            <div class="wa-hint">Belum ada pengiriman.</div>
        <?php else: ?>
            <div style="overflow-x:auto;border-radius:10px;border:1px solid var(--fd-line)">
                <table class="wa-log">
                    <thead><tr><th>Waktu</th><th>Jenis</th><th>Tujuan</th><th>Status</th><th>Keterangan</th></tr></thead>
                    <tbody>
                        <?php foreach ($logRows as $l): ?>
                            <tr>
                                <td><?php echo date('d M H:i', strtotime($l['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($typeLabel[$l['type']] ?? $l['type']); ?></td>
                                <td><?php echo htmlspecialchars($l['target'] ?: '-'); ?></td>
                                <td><span class="wa-pill <?php echo htmlspecialchars($l['status']); ?>"><?php echo ['sent' => 'Terkirim', 'failed' => 'Gagal', 'skipped' => 'Dilewati'][$l['status']] ?? htmlspecialchars($l['status']); ?></span></td>
                                <td><?php echo htmlspecialchars(trim($l['detail'] . ($l['ref'] ? ' · ' . $l['ref'] : ''))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    (function() {
        const post = (data) => {
            const fd = new FormData();
            Object.entries(data).forEach(([k, v]) => fd.append(k, v));
            return fetch('whatsapp.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json());
        };

        // Status koneksi
        const dot = document.getElementById('waDot');
        const st = document.getElementById('waStatusText');
        const sub = document.getElementById('waStatusSub');
        function check() {
            st.textContent = 'Memeriksa koneksi…';
            sub.textContent = '';
            dot.className = 'wa-dot';
            post({ ajax: 'status' }).then(r => {
                if (!r.ok) {
                    dot.className = 'wa-dot off';
                    st.textContent = 'Tidak terhubung';
                    sub.textContent = r.detail || '';
                    return;
                }
                dot.className = 'wa-dot ' + (r.connected ? 'on' : 'off');
                st.textContent = r.connected ? 'Terhubung · ' + (r.device || '') : 'Perangkat terputus — scan ulang QR di dashboard Fonnte';
                sub.textContent = [r.name, r.package && ('Paket ' + r.package), r.quota && ('Kuota ' + r.quota), r.expired && ('Aktif s/d ' + r.expired)].filter(Boolean).join(' · ');
            }).catch(() => { dot.className = 'wa-dot off'; st.textContent = 'Gagal memeriksa koneksi'; });
        }
        document.getElementById('waCheck').addEventListener('click', check);
        <?php if ($wa->isConfigured()): ?>check();<?php endif; ?>

        // Daftar grup
        const box = document.getElementById('waGroups');
        const targets = document.getElementById('waTargets');
        document.getElementById('waGroupsBtn').addEventListener('click', function() {
            const btn = this;
            btn.disabled = true;
            btn.textContent = 'Mengambil grup…';
            post({ ajax: 'groups' }).then(r => {
                box.innerHTML = '';
                box.style.display = 'block';
                if (!r.ok || !r.groups || !r.groups.length) {
                    box.innerHTML = '<div class="wa-hint" style="padding:.5rem .65rem">' + (r.ok ? 'Tidak ada grup. Pastikan nomor hotel sudah masuk grup.' : (r.detail || 'Gagal')) + '</div>';
                    return;
                }
                r.groups.forEach(g => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.innerHTML = '<b></b><span>+ Tambah</span>';
                    b.querySelector('b').textContent = g.name;
                    b.addEventListener('click', () => {
                        if (targets.value.indexOf(g.id) === -1) targets.value = (targets.value.trim() ? targets.value.trim() + '\n' : '') + g.id;
                        b.querySelector('span').textContent = '✓ Ditambahkan';
                    });
                    box.appendChild(b);
                });
            }).finally(() => { btn.disabled = false; btn.textContent = 'Ambil daftar grup'; });
        });

        // Tes kirim
        document.getElementById('waTestBtn').addEventListener('click', function() {
            const btn = this, msg = document.getElementById('waTestMsg');
            const to = document.getElementById('waTestTo').value.trim();
            if (!to) { msg.className = 'wa-msg err'; msg.textContent = 'Isi nomor tujuan dulu.'; return; }
            btn.disabled = true;
            msg.className = 'wa-msg';
            msg.textContent = 'Mengirim…';
            post({ ajax: 'test', target: to }).then(r => {
                msg.className = 'wa-msg ' + (r.ok ? 'ok' : 'err');
                msg.textContent = r.ok ? 'Terkirim ✓ — cek WhatsApp tujuan.' : 'Gagal: ' + (r.detail || '');
            }).catch(() => { msg.className = 'wa-msg err'; msg.textContent = 'Gagal menghubungi server.'; })
              .finally(() => { btn.disabled = false; });
        });

        // Template: sisip placeholder + pratinjau
        const tpl = document.getElementById('waTpl');
        const preview = document.getElementById('waPreview');
        const sample = {
            '{guest_name}': 'Mr. John Smith', '{hotel_name}': <?php echo json_encode((string)(($db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'")['setting_value'] ?? '') ?: (defined('BUSINESS_NAME') ? BUSINESS_NAME : 'Hotel'))); ?>,
            '{room}': '204', '{room_type}': 'Deluxe Queen', '{check_in}': '<?php echo date('d M Y'); ?>',
            '{check_out}': '<?php echo date('d M Y', strtotime('+2 day')); ?>', '{nights}': '2', '{booking_code}': 'BK-20261006-1234'
        };
        function render() {
            let t = tpl.value;
            Object.entries(sample).forEach(([k, v]) => { t = t.split(k).join(v); });
            preview.textContent = t;
        }
        tpl.addEventListener('input', render);
        document.querySelectorAll('[data-ph]').forEach(c => c.addEventListener('click', () => {
            const ph = c.dataset.ph, s = tpl.selectionStart, e = tpl.selectionEnd;
            tpl.value = tpl.value.slice(0, s) + ph + tpl.value.slice(e);
            tpl.focus();
            tpl.selectionStart = tpl.selectionEnd = s + ph.length;
            render();
        }));
        render();
    })();
</script>

<?php include '../../includes/footer.php'; ?>
